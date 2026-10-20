<?php

declare(strict_types=1);

namespace KompanieApp\Core\Update;

use KompanieApp\Core\App;
use KompanieApp\Core\Telemetry\Code;

/**
 * Statusmeldungen an uns (Format 2, Issue #20) – nur mit Zustimmung der Kompanie (Einstellung
 * status_reports). Stündlich ein Lebenszeichen, schwere Fehler sofort (siehe Telemetry\Telemetry).
 *
 * Inhalt abschließend (docs/OFFENE-PUNKTE.md, Abschnitt 4): Installations-ID, Kompanie, Version,
 * Variante, PHP-Version, Datenbank, Ampel je Bereich (nur Schlüssel und Stufe), Fehlercodes der
 * letzten 24 Stunden mit Anzahl, Art und letzter Lauf der Hintergrundaufgaben, eingeschaltete
 * Bereiche, Ergebnis des letzten Updates und Galerie-Abgleichs. Keine Mitgliederdaten, keine
 * Adressen, keine Fehlertexte.
 *
 * Signiert mit HMAC-SHA256 und einem Geheimnis je Installation (Header X-Kompanie-Signature). Das
 * Cockpit lernt das Geheimnis bei der ersten Meldung einer neuen Installation (Feld key, nur bis
 * es die Installation bestätigt hat); danach lehnt es Meldungen mit falscher Signatur ab.
 */
final class StatusReporter
{
    public const TASK = 'status_report';
    public const SETTING = 'status_reports';
    public const INTERVAL = 3600;

    /** Diese Einstellungen gehören zur Installation und kommen nicht in Sicherungen (Backup::LOCAL_SETTINGS). */
    public const ID_SETTING = 'installation_id';
    public const SECRET_SETTING = 'installation_secret';
    /** Installations-ID, die das Cockpit bestätigt hat (danach wird das Geheimnis nicht mehr mitgesendet). */
    public const REGISTERED_SETTING = 'telemetry_registered';
    /** Letzte Meldung: ['at' => Zeit, 'ok' => bool, 'kind' => heartbeat|event] */
    public const LAST_SETTING = 'telemetry_last';
    /** Zeitpunkt des letzten angenommenen Lebenszeichens */
    public const HEARTBEAT_SETTING = 'telemetry_heartbeat';
    /** Ampel beim letzten Lebenszeichen (um den Sprung auf Rot zu erkennen) */
    public const CHECKS_SETTING = 'telemetry_checks';

    public const HEADER = 'X-Kompanie-Signature';
    private const TIMEOUT = 5;
    /**
     * Lebenszeichen höchstens so oft: Ein vorgezogener Lauf (wegen eines Ereignisses) kurz nach einem
     * Lebenszeichen sendet keins, sonst gehört es zu jedem Lauf (sonst entstünden Lücken von fast zwei Stunden).
     */
    private const HEARTBEAT_MIN_GAP = 600;
    private const LEVELS = ['ok', 'warn', 'error', 'off'];
    /** Untere Grenze für freien Speicherplatz (wie die Statusseite) */
    private const DISK_LOW = 200 * 1024 * 1024;

    public function __construct(private readonly App $app)
    {
    }

    public function enabled(): bool
    {
        return (bool) $this->app->settings->get(self::SETTING, false) && $this->url() !== '';
    }

    /**
     * Genau das, was als Lebenszeichen gesendet wird (auch zum Anzeigen in der App), ohne das
     * Geheimnis für die erste Meldung.
     *
     * @return array<string, mixed>
     */
    public function payload(string $kind = 'heartbeat', ?Code $event = null): array
    {
        $a = $this->app;
        $last = $a->settings->get('update_last');
        $gallery = $a->gallery()->status();
        $data = [
            'format' => 2,
            'installation' => $this->installationId(),
            'kompanie' => (string) ($a->packageConfig['kompanie'] ?? ''),
            'version' => $a->env->version(),
            'variant' => $a->env->variant(),
            'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'database' => $a->db->driver(),
            'kind' => $kind,
            'checks' => $this->checks(),
            'errors' => (object) $a->telemetry()->errors(),
            'cron' => ['kind' => $a->scheduler->kind(), 'last' => $this->lastTaskRun()],
            'features' => array_keys(array_filter($a->features()->all())),
            'update' => is_array($last) ? ['ok' => (bool) ($last['ok'] ?? false), 'at' => (int) ($last['at'] ?? 0), 'to' => (string) ($last['to'] ?? '')] : null,
            'updateCheckOk' => (string) (((array) $a->settings->get('update_state', []))['error'] ?? '') === '',
            'gallery' => $a->gallery()->isConfigured() ? ['ok' => ($gallery['error'] ?? '') === '', 'at' => (int) ($gallery['last_sync'] ?? 0)] : null,
            // Wartungsmodus (Issue #42): nur ob, nie die Begründung (Freitext gehört nicht in Statusmeldungen).
            'maintenance' => $a->maintenance()->active(),
            'sent' => $a->time()->timestamp(),
            'nonce' => bin2hex(random_bytes(8)),
        ];
        if ($event !== null) {
            $data['event'] = $event->value;
        }
        return $data;
    }

    /** Zeitpunkt der letzten Meldung und ob sie angenommen wurde (für Einstellungen → Updates). */
    public function last(): ?array
    {
        $last = $this->app->settings->get(self::LAST_SETTING);
        return is_array($last) && isset($last['at']) ? ['at' => (int) $last['at'], 'ok' => (bool) ($last['ok'] ?? false), 'kind' => (string) ($last['kind'] ?? 'heartbeat')] : null;
    }

    /**
     * Stündliche Aufgabe, bei schweren Fehlern vorgezogen: erst wartende Ereignisse, dann das Lebenszeichen.
     * Jede Meldung wird einzeln versucht und nicht sofort wiederholt. Ereignisse, die das Cockpit nicht
     * erreicht haben oder später annehmen kann, bleiben bis zum nächsten Lauf liegen; endgültig abgelehnte
     * (z. B. 400) werden verworfen, damit sie nichts blockieren.
     */
    public function runTask(): void
    {
        if (!$this->enabled()) {
            return;
        }
        $t = $this->app->telemetry();
        try {
            $this->watchChecks();
        } catch (\Throwable $e) {
            error_log('Kompanie-App: Ampel für die Statusmeldung nicht geprüft: ' . $e->getMessage());
        }
        $failure = null;
        $keep = [];
        $pending = $t->pending(true);
        foreach ($pending as $code) {
            [$ok, $retry, $error] = $this->send($this->payload('event', $code));
            if (!$ok) {
                $failure ??= $error;
                if ($retry) {
                    $keep[] = $code;
                }
            }
        }
        $t->finishPending($pending, $keep);
        $now = $this->app->time()->timestamp();
        if ($now - (int) $this->app->settings->get(self::HEARTBEAT_SETTING, 0) >= self::HEARTBEAT_MIN_GAP) {
            [$ok, , $error] = $this->send($this->payload());
            if ($ok) {
                $this->app->settings->set(self::HEARTBEAT_SETTING, $now);
            } else {
                $failure ??= $error;
            }
        }
        if ($failure !== null) {
            throw new \RuntimeException($failure);
        }
    }

    /** Signatur über den gesendeten Text (für Tests und das Cockpit nachvollziehbar). */
    public static function sign(string $body, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{0: bool, 1: bool, 2: string} angenommen, später erneut versuchen, Fehlermeldung (fürs Protokoll)
     */
    private function send(array $data): array
    {
        $s = $this->app->settings;
        $id = (string) $data['installation'];
        if ($s->get(self::REGISTERED_SETTING) !== $id) {
            $data['key'] = $this->secret();
        }
        $body = (string) json_encode($data, JSON_UNESCAPED_SLASHES);
        $kind = (string) $data['kind'];
        try {
            $res = $this->app->http->request('POST', $this->url(), [
                'Content-Type' => 'application/json',
                self::HEADER => self::sign($body, $this->secret()),
            ], $body, self::TIMEOUT);
        } catch (\Throwable $e) {
            $s->set(self::LAST_SETTING, ['at' => $data['sent'], 'ok' => false, 'kind' => $kind]);
            return [false, true, 'Statusmeldung nicht gesendet: ' . $e->getMessage()];
        }
        $status = (int) $res['status'];
        $ok = $status >= 200 && $status < 300;
        $s->set(self::LAST_SETTING, ['at' => $data['sent'], 'ok' => $ok, 'kind' => $kind]);
        if ($ok) {
            if (isset($data['key'])) {
                $s->set(self::REGISTERED_SETTING, $id);
            }
            return [true, false, ''];
        }
        // Das Cockpit kennt die Installation nicht (mehr), z. B. nach einem Neuaufbau: beim nächsten
        // Mal das Geheimnis wieder mitsenden. Eine bekannte Installation mit anderem Geheimnis lehnt es ab.
        $body = json_decode((string) $res['body'], true);
        $code = is_array($body) ? (string) ($body['code'] ?? '') : '';
        if ($status === 409 && $code === 'unknown_installation') {
            $s->delete(self::REGISTERED_SETTING);
        }
        // Später erneut: Server- und Netzfehler, zu viele Meldungen, unbekannte Installation, Signatur.
        $retry = $status >= 500 || $status === 429 || $status === 401 || ($status === 409 && $code === 'unknown_installation');
        return [false, $retry, 'Statusmeldung nicht angenommen (HTTP ' . $status . ($code !== '' ? ', ' . $code : '') . ').'];
    }

    /** @return array<string, string> Bereich => Stufe, nur bekannte Stufen und einfache Schlüssel */
    private function checks(): array
    {
        $out = [];
        foreach ($this->app->status()->report()['items'] as $item) {
            $key = (string) ($item['key'] ?? '');
            $level = (string) ($item['level'] ?? '');
            if (preg_match('/^[a-z][a-z0-9_]{0,31}$/', $key) && in_array($level, self::LEVELS, true)) {
                $out[$key] = $level;
            }
        }
        return $out;
    }

    /** Sprung einer Ampel auf Rot und wenig Speicherplatz als Fehler zählen (schwer: sofort melden). */
    private function watchChecks(): void
    {
        $s = $this->app->settings;
        $before = $s->get(self::CHECKS_SETTING);
        $before = is_array($before) ? $before : [];
        $now = $this->checks();
        foreach ($now as $key => $level) {
            if ($level === 'error' && ($before[$key] ?? '') !== 'error') {
                $this->app->telemetry()->record(Code::CheckError);
                break;
            }
        }
        if ($now !== $before) {
            $s->set(self::CHECKS_SETTING, $now);
        }
        $free = @disk_free_space($this->app->files->path('.', false));
        if (is_float($free) && $free < self::DISK_LOW) {
            $this->app->telemetry()->record(Code::DiskLow);
        }
    }

    private function lastTaskRun(): int
    {
        $v = $this->app->settings->get(App::TASK_RUN_SETTING);
        return is_numeric($v) ? (int) $v : 0;
    }

    private function installationId(): string
    {
        $id = $this->app->settings->get(self::ID_SETTING);
        if (!is_string($id) || !preg_match('/^[0-9a-f]{32}$/', $id)) {
            $id = bin2hex(random_bytes(16));
            $this->app->settings->set(self::ID_SETTING, $id);
        }
        return $id;
    }

    private function secret(): string
    {
        $secret = $this->app->settings->get(self::SECRET_SETTING);
        if (!is_string($secret) || !preg_match('/^[0-9a-f]{64}$/', $secret)) {
            $secret = bin2hex(random_bytes(32));
            $this->app->settings->set(self::SECRET_SETTING, $secret);
        }
        return $secret;
    }

    private function url(): string
    {
        $url = trim((string) ($this->app->packageConfig['statusUrl'] ?? ''));
        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || ($parts['host'] ?? '') === '') {
            return '';
        }
        // Nur https (für Tests auch http auf dem eigenen Rechner, wie bei den Update-Paketen).
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        return $scheme === 'https' || ($scheme === 'http' && in_array($parts['host'], ['127.0.0.1', 'localhost'], true)) ? $url : '';
    }
}
