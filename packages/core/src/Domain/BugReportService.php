<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Push\PushService;
use KompanieApp\Core\Update\StatusReporter;

/**
 * Fehlermeldungen aus der App (Issue #64, Wire-Format in docs/FEHLERMELDUNGEN.md).
 *
 * Wer etwas meldet (auch ohne Anmeldung), stimmt im Formular zu, dass die Meldung mit den angezeigten Angaben an die
 * Betreuer der App geht. Unabhängig von der Zustimmung zu den Statusmeldungen: Jede Meldung hat ihre eigene Einwilligung.
 *
 * Ablauf: Formular öffnen (GET /reports/form: Zeitmarke und die Angaben, die der Server beifügt) → absenden
 * (POST /reports) → Warteschlange in der Datenbank (Tabelle bug_reports, nicht in Sicherungen) → Aufgabe bug_reports
 * (stündlich, nach dem Einreihen vorgezogen) sendet sie signiert an das Cockpit, das daraus ein Issue in einem privaten
 * Repository macht. Ohne Adresse des Empfängers (reportUrl in update.json) gibt es keine Meldungen.
 *
 * Gegen Spam ohne Captcha: verstecktes Feld (Honeypot), Zeitfalle (signierte Zeitmarke beim Öffnen, frühestens nach
 * MIN_SECONDS absendbar), Begrenzung je Anfragendem (IP als Hash, IPv6 je /64) und je Installation, volle Warteschlange.
 */
final class BugReportService
{
    public const TASK = 'bug_reports';
    public const INTERVAL = 3600;
    /** Installations-ID, die der Empfänger der Fehlermeldungen bestätigt hat (danach kein Geheimnis mehr im Feld key). */
    public const REGISTERED_SETTING = 'bug_reports_registered';

    public const MAX_DESCRIPTION = 2000;
    public const MAX_EXPECTED = 2000;
    public const MAX_SCREENSHOT_BYTES = 2 * 1024 * 1024;
    /** Längste Kante und Fläche eines Bildschirmfotos (Pixel): große Bildschirme ja, Bildbomben nein. */
    public const MAX_EDGE = 10000;
    public const MAX_PIXELS = 40_000_000;
    /** Zeitfalle: frühestens so viele Sekunden nach dem Öffnen des Formulars, spätestens nach MAX_FORM_AGE. */
    public const MIN_SECONDS = 3;
    public const MAX_FORM_AGE = 6 * 3600;
    /** Je Anfragendem (IP-Hash) und Stunde bzw. je Installation und Tag */
    public const CLIENT_RATE = 5;
    public const DAILY_RATE = 30;
    /** Höchstens so viele Meldungen warten auf den Versand. */
    public const MAX_QUEUED = 50;
    /** Nicht zugestellte Meldungen verfallen nach so vielen Tagen. */
    public const KEEP_DAYS = 7;
    /** Je Lauf höchstens so viele Meldungen (jede bis 2 MB) */
    private const PER_RUN = 10;
    private const TIMEOUT = 20;
    private const MAX_PAGE = 200;
    private const MAX_AGENT = 400;
    private const MAX_ERROR = 500;
    /** Erkennung am Dateianfang: Typ => [Signatur, Name für die Meldung] */
    private const TYPES = [
        'image/png' => "\x89PNG\r\n\x1a\n",
        'image/jpeg' => "\xFF\xD8\xFF",
        'image/webp' => 'RIFF',
    ];

    public function __construct(private readonly App $app)
    {
    }

    /** Ob die App Fehlermeldungen annimmt (der Knopf „Fehler melden“ erscheint nur dann). */
    public function available(): bool
    {
        return $this->url() !== '';
    }

    /**
     * Zum Öffnen des Formulars: Zeitmarke für die Zeitfalle und die Angaben, die der Server beifügt (zeigt die App vor
     * dem Absenden an).
     *
     * @return array{token: string, minSeconds: int, maxBytes: int, details: array{version: string, role: string}}
     */
    public function form(?User $user): array
    {
        $this->requireAvailable();
        $now = $this->app->time()->timestamp();
        return [
            'token' => $now . '.' . $this->formSignature($now),
            'minSeconds' => self::MIN_SECONDS,
            'maxBytes' => self::MAX_SCREENSHOT_BYTES,
            'details' => ['version' => $this->app->env->version(), 'role' => $this->roleLabel($user)],
        ];
    }

    /**
     * Nimmt eine Meldung an und reiht sie für den Versand ein. Antwortet erst, wenn sie gespeichert ist.
     *
     * @param array<string, mixed> $in
     * @return array{queued: bool}
     */
    public function submit(?User $user, array $in, string $client): array
    {
        $this->requireAvailable();
        // Honeypot: Menschen sehen das Feld nicht. Antwort wie bei Erfolg, damit Programme nichts lernen.
        if (trim(is_string($in['website'] ?? null) ? $in['website'] : '') !== '') {
            return ['queued' => true];
        }
        $this->checkForm($in['token'] ?? null);
        if (($in['consent'] ?? null) !== true) {
            throw ApiError::invalid('Bitte bestätige, dass die Meldung an die Betreuer der App gehen darf.');
        }
        $description = self::text($in['description'] ?? null, 0, true);
        if ($description === '') {
            throw ApiError::invalid('Bitte beschreibe kurz, was nicht klappt.');
        }
        if (mb_strlen($description) > self::MAX_DESCRIPTION) {
            throw ApiError::invalid(sprintf('Die Beschreibung darf höchstens %d Zeichen lang sein.', self::MAX_DESCRIPTION));
        }
        $expected = self::text($in['expected'] ?? null, 0, true);
        if (mb_strlen($expected) > self::MAX_EXPECTED) {
            throw ApiError::invalid(sprintf('„Was hast du erwartet?“ darf höchstens %d Zeichen lang sein.', self::MAX_EXPECTED));
        }
        $includeName = ($in['includeName'] ?? false) === true;
        if ($includeName && $user === null) {
            throw ApiError::invalid('Ohne Anmeldung kann kein Name mitgeschickt werden.');
        }
        [$screenshot, $type] = $this->screenshot($in['screenshot'] ?? null);

        $this->limit($client);
        $now = $this->app->time()->timestamp();
        $report = [
            'description' => $description,
            'expected' => $expected,
            'page' => self::text($in['page'] ?? null, self::MAX_PAGE, false),
            'version' => $this->app->env->version(),
            'variant' => $this->app->env->variant(),
            'userAgent' => self::text($in['userAgent'] ?? null, self::MAX_AGENT, false),
            'role' => $this->roleLabel($user),
            'name' => $includeName && $user !== null ? $user->name : null,
            'lastError' => self::text($in['lastError'] ?? null, self::MAX_ERROR, false) ?: null,
            'created' => $now,
        ];
        $this->app->db->insert('bug_reports', [
            'uuid' => bin2hex(random_bytes(16)),
            // Nur mit Namen: Dann gehört die Meldung zum Konto und wird mit ihm gelöscht (UserData).
            'user_id' => $includeName && $user !== null ? $user->id : 0,
            'report' => (string) json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'screenshot' => $screenshot,
            'screenshot_type' => $type,
            'attempts' => 0,
            'last_error' => '',
            'created_at' => $now,
        ]);
        try {
            $this->app->scheduler->runSoon(self::TASK);
        } catch (\Throwable $e) {
            error_log('Kompanie-App: Versand der Fehlermeldung nicht vorgezogen: ' . $e->getMessage());
        }
        return ['queued' => true];
    }

    /** Wartende Meldungen (für Tests und die Statusseite). */
    public function queued(): int
    {
        return (int) $this->app->db->scalar('SELECT COUNT(*) FROM {bug_reports}');
    }

    /**
     * Stündliche Aufgabe, nach dem Einreihen vorgezogen: abgelaufene Meldungen verwerfen, dann die ältesten senden.
     * Angenommene (2xx) und endgültig abgelehnte (4xx außer 401, 409 unknown_installation, 429) werden gelöscht, die
     * übrigen beim nächsten Lauf erneut versucht. Bei Netz- oder Serverfehlern hört der Lauf auf (das Cockpit ist
     * dann vermutlich nicht erreichbar).
     */
    public function runTask(): void
    {
        $db = $this->app->db;
        $now = $this->app->time()->timestamp();
        $db->execute('DELETE FROM {bug_reports} WHERE created_at < ?', [$now - self::KEEP_DAYS * 86400]);
        if (!$this->available()) {
            return;
        }
        $failure = null;
        foreach ($db->select('SELECT id FROM {bug_reports} ORDER BY id LIMIT ' . self::PER_RUN) as $row) {
            // Einzeln lesen: höchstens ein Bildschirmfoto im Speicher.
            $r = $db->selectOne('SELECT * FROM {bug_reports} WHERE id = ?', [(int) $row['id']]);
            if ($r === null) {
                continue;
            }
            [$done, $stop, $error] = $this->send($r);
            if ($done) {
                $db->execute('DELETE FROM {bug_reports} WHERE id = ?', [(int) $r['id']]);
                if ($error !== '') {
                    error_log('Kompanie-App: Fehlermeldung verworfen: ' . $error);
                }
                continue;
            }
            $db->update('bug_reports', ['attempts' => (int) $r['attempts'] + 1, 'last_error' => mb_substr($error, 0, 255)], ['id' => (int) $r['id']]);
            $failure ??= $error;
            if ($stop) {
                break;
            }
        }
        if ($failure !== null) {
            throw new \RuntimeException($failure);
        }
    }

    /**
     * Genau das, was an das Cockpit geht (ohne key).
     *
     * @param array<string, mixed> $row Zeile aus bug_reports
     * @return array<string, mixed>
     */
    public function payload(array $row): array
    {
        $report = json_decode((string) $row['report'], true);
        $report = is_array($report) ? $report : [];
        $shot = is_string($row['screenshot'] ?? null) && $row['screenshot'] !== '' ? ['type' => (string) $row['screenshot_type'], 'data' => $row['screenshot']] : null;
        return [
            'format' => 1,
            'installation' => $this->app->statusReporter()->installationId(),
            'kompanie' => (string) ($this->app->packageConfig['kompanie'] ?? ''),
            'sent' => $this->app->time()->timestamp(),
            'nonce' => bin2hex(random_bytes(8)),
            'report' => ['id' => (string) $row['uuid']] + $report,
            'screenshot' => $shot,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{0: bool, 1: bool, 2: string} erledigt (löschen), Lauf beenden, Fehlermeldung
     */
    private function send(array $row): array
    {
        $s = $this->app->settings;
        $reporter = $this->app->statusReporter();
        $data = $this->payload($row);
        $id = (string) $data['installation'];
        // Vertrauen beim ersten Kontakt wie bei den Statusmeldungen, aber eigener Stand: Das Cockpit lernt das
        // Geheimnis auch, wenn diese Installation keine Statusmeldungen sendet.
        $withKey = $s->get(self::REGISTERED_SETTING) !== $id;
        if ($withKey) {
            $data['key'] = $reporter->secret();
        }
        $body = (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        unset($data);
        try {
            $res = $this->app->http->request('POST', $this->url(), [
                'Content-Type' => 'application/json',
                StatusReporter::HEADER => StatusReporter::sign($body, $reporter->secret()),
            ], $body, self::TIMEOUT);
        } catch (\Throwable $e) {
            return [false, true, 'Fehlermeldung nicht gesendet: ' . $e->getMessage()];
        }
        $status = (int) $res['status'];
        if ($status >= 200 && $status < 300) {
            if ($withKey) {
                $s->set(self::REGISTERED_SETTING, $id);
            }
            return [true, false, ''];
        }
        $answer = json_decode((string) $res['body'], true);
        $code = is_array($answer) ? (string) ($answer['code'] ?? '') : '';
        $error = 'Fehlermeldung nicht angenommen (HTTP ' . $status . ($code !== '' ? ', ' . $code : '') . ').';
        if ($status === 409 && $code === 'unknown_installation') {
            $s->delete(self::REGISTERED_SETTING);
            return [false, false, $error];
        }
        if ($status === 401 || ($status === 409 && $code === 'replay')) {
            return [false, false, $error];
        }
        if ($status === 429 || $status >= 500) {
            return [false, true, $error];
        }
        // Sonstige 4xx: so nie annehmbar (ungültig, zu groß, Kompanie unbekannt) – verwerfen.
        return [true, false, $error];
    }

    /** Begrenzung je Anfragendem, je Installation und Größe der Warteschlange. */
    private function limit(string $client): void
    {
        $cache = $this->app->cache;
        if ($cache->increment('bug_report_c_' . hash('sha256', PushService::clientKey($client)), 3600) > self::CLIENT_RATE
            || $cache->increment('bug_report_day', 86400) > self::DAILY_RATE) {
            throw new ApiError(429, 'too_many_attempts', 'Zu viele Meldungen in kurzer Zeit. Bitte versuche es später erneut.');
        }
        if ($this->queued() >= self::MAX_QUEUED) {
            throw new ApiError(503, 'reports_full', 'Zurzeit können keine weiteren Meldungen angenommen werden. Bitte versuche es später erneut.');
        }
    }

    /** Zeitfalle: Zeitmarke aus form(), nicht zu frisch und nicht zu alt. */
    private function checkForm(mixed $token): void
    {
        $now = $this->app->time()->timestamp();
        if (!is_string($token) || !preg_match('/^(\d{1,12})\.([0-9a-f]{64})$/D', $token, $m) || !hash_equals($this->formSignature((int) $m[1]), $m[2])
            || $now - (int) $m[1] > self::MAX_FORM_AGE || (int) $m[1] > $now + 60) {
            throw new ApiError(400, 'form_expired', 'Das Formular ist abgelaufen. Bitte öffne es neu.');
        }
        if ($now - (int) $m[1] < self::MIN_SECONDS) {
            throw new ApiError(429, 'too_fast', 'Das ging sehr schnell. Bitte warte einen Moment und sende dann erneut.');
        }
    }

    private function formSignature(int $at): string
    {
        return hash_hmac('sha256', 'bug-report-form|' . $at, $this->app->statusReporter()->secret());
    }

    /**
     * Bildschirmfoto (optional): Base64 (auch als data:-Adresse), PNG, JPEG oder WebP, echtes Bild mit sinnvollen Maßen.
     *
     * @return array{0: string|null, 1: string} Base64 und Typ, oder null und ''
     */
    private function screenshot(mixed $in): array
    {
        if ($in === null || $in === '') {
            return [null, ''];
        }
        $data = is_array($in) ? ($in['data'] ?? null) : $in;
        $data = is_string($data) ? $data : '';
        if (str_starts_with($data, 'data:') && ($comma = strpos($data, ',')) !== false) {
            $data = substr($data, $comma + 1);
        }
        // Grobe Grenze vor dem Dekodieren (Base64 ist ein Drittel größer).
        if (strlen($data) > intdiv(self::MAX_SCREENSHOT_BYTES * 4, 3) + 8) {
            throw ApiError::invalid('Das Bildschirmfoto darf höchstens 2 MB groß sein.');
        }
        $bytes = $data === '' ? false : base64_decode($data, true);
        if ($bytes === false || $bytes === '') {
            throw ApiError::invalid('Das Bildschirmfoto konnte nicht gelesen werden.');
        }
        if (strlen($bytes) > self::MAX_SCREENSHOT_BYTES) {
            throw ApiError::invalid('Das Bildschirmfoto darf höchstens 2 MB groß sein.');
        }
        $type = null;
        foreach (self::TYPES as $mime => $signature) {
            if (str_starts_with($bytes, $signature) && ($mime !== 'image/webp' || substr($bytes, 8, 4) === 'WEBP')) {
                $type = $mime;
            }
        }
        $info = $type !== null ? @getimagesizefromstring($bytes) : false;
        if ($type === null || $info === false || ($info['mime'] ?? '') !== $type) {
            throw ApiError::invalid('Als Bildschirmfoto gehen nur PNG-, JPEG- oder WebP-Bilder.');
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] > self::MAX_EDGE || $info[1] > self::MAX_EDGE || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw ApiError::invalid('Das Bildschirmfoto hat ungewöhnliche Maße. Bitte ein normales Bildschirmfoto wählen.');
        }
        // Neu kodiert statt der Eingabe: sicher gültiges Base64 ohne Zeilenumbrüche.
        return [base64_encode($bytes), $type];
    }

    private function roleLabel(?User $user): string
    {
        return $user === null ? 'ohne Anmeldung' : $this->app->roles()->label($user->role);
    }

    /** Text ohne Steuerzeichen (Zeilenumbrüche nur mehrzeilig), Zeilenenden vereinheitlicht, außen ohne Leerraum. */
    private static function text(mixed $value, int $max, bool $multiline): string
    {
        if (!is_string($value)) {
            return '';
        }
        $s = mb_scrub(str_replace(["\r\n", "\r"], "\n", $value), 'UTF-8');
        $s = (string) preg_replace($multiline ? '/[\x00-\x08\x0B-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]+/u', $multiline ? '' : ' ', $s);
        $s = trim($s);
        return $max > 0 ? mb_substr($s, 0, $max) : $s;
    }

    private function requireAvailable(): void
    {
        if (!$this->available()) {
            throw new ApiError(404, 'reports_unavailable', 'Fehlermeldungen sind in dieser App nicht eingerichtet.');
        }
    }

    private function url(): string
    {
        return StatusReporter::safeUrl($this->app->packageConfig['reportUrl'] ?? '');
    }
}
