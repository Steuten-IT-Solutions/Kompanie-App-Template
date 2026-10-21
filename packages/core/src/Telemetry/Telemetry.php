<?php

declare(strict_types=1);

namespace KompanieApp\Core\Telemetry;

use KompanieApp\Core\Port\Scheduler;
use KompanieApp\Core\Port\Settings;
use KompanieApp\Core\Domain\Time;

/**
 * Fehlerzähler und sofortige Meldungen für die Statusmeldungen (Issue #20), nur mit Zustimmung, dazu das
 * Fehlerprotokoll der Installation.
 *
 * - Zähler je Code und Stunde (Einstellung ERRORS_SETTING), ältere als 24 Stunden werden verworfen.
 *   Daneben je Code die Ursachen (DETAILS_SETTING, siehe Detail), höchstens DETAILS_MAX verschiedene je
 *   Code und Stunde, weitere zählen als „other“.
 * - Schwere Fehler (Code::severe) kommen in eine Warteschlange (PENDING_SETTING, mit der Ursache) und
 *   werden von der Aufgabe status_report gesendet, die dafür vorgezogen wird: So wartet keine Anfrage auf
 *   den Versand. Je Code höchstens einmal pro Stunde (EVENTS_SETTING).
 * - Ohne Zustimmung wird nichts gezählt und nichts gemerkt.
 * - Fehlerprotokoll (LOG_SETTING): die letzten Fehler mit Text, auch ohne Zustimmung. Es bleibt auf dem
 *   Server; Admins sehen es unter Einstellungen → Zustand und Updates und können es bei Bedarf an die
 *   Betreuer weitergeben. Adressen werden darin gekürzt (geheime Kalender-Links, Schlüssel in Parametern).
 *
 * record() wirft nie: Ein Fehler beim Zählen darf keinen Aufruf scheitern lassen.
 */
final class Telemetry
{
    public const ERRORS_SETTING = 'telemetry_errors';
    public const PENDING_SETTING = 'telemetry_pending';
    public const EVENTS_SETTING = 'telemetry_events';
    public const DETAILS_SETTING = 'telemetry_details';
    /** Fehlerprotokoll der Installation (nicht in Sicherungen, bleibt beim Widerruf der Zustimmung) */
    public const LOG_SETTING = 'error_log';
    /** Höchstens so viele Ereignisse warten auf den Versand (mehr verschiedene Codes gibt es ohnehin kaum). */
    private const PENDING_MAX = 10;
    private const WINDOW = 86400;
    private const THROTTLE = 3600;
    /** Höchstens so viele je Code und Stunde (danach wird nicht mehr gezählt) */
    private const COUNT_MAX = 1000;
    /** Höchstens so viele verschiedene Ursachen je Code und Stunde */
    private const DETAILS_MAX = 10;
    /** Fehlerprotokoll: höchstens so viele Einträge, höchstens so alt, Text höchstens so lang */
    private const LOG_MAX = 50;
    private const LOG_DAYS = 14;
    private const LOG_TEXT_MAX = 1000;

    /**
     * @param \Closure(): bool $enabled Zustimmung und Empfänger vorhanden (StatusReporter::enabled)
     * @param string $task Aufgabe, die die Meldungen sendet (wird bei schweren Fehlern vorgezogen)
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly Scheduler $scheduler,
        private readonly Time $time,
        private readonly \Closure $enabled,
        private readonly string $task,
    ) {
    }

    /**
     * @param string $detail Ursache (wird mit Detail::of vereinheitlicht), nie aus freiem Text
     * @param string $message Fehlertext, nur für das Fehlerprotokoll der Installation
     */
    public function record(Code $code, string $detail = '', string $message = ''): void
    {
        $detail = $detail === '' ? '' : Detail::of($detail);
        $now = $this->time->timestamp();
        // Protokoll und Zählen getrennt: Scheitert das eine, soll das andere trotzdem klappen.
        try {
            $this->append($code, $detail, $message, $now);
        } catch (\Throwable $e) {
            error_log('Kompanie-App: Fehler ' . $code->value . ' nicht protokolliert: ' . $e->getMessage());
        }
        try {
            if (!($this->enabled)()) {
                return;
            }
            $hour = (string) ($now - $now % 3600);
            $errors = $this->prune($this->settings->get(self::ERRORS_SETTING), $now);
            $count = (int) ($errors[$hour][$code->value] ?? 0);
            // Ab einer Grenze nicht mehr schreiben: Ein Dauerfehler soll nicht jede Anfrage zum Schreiben bringen.
            if ($count < self::COUNT_MAX) {
                $errors[$hour][$code->value] = $count + 1;
                $this->settings->set(self::ERRORS_SETTING, $errors);
                if ($detail !== '') {
                    $this->countDetail($code, $detail, $hour, $now);
                }
            }
            if ($code->severe()) {
                $this->queue($code, $detail, $now);
            }
        } catch (\Throwable $e) {
            error_log('Kompanie-App: Fehlercode ' . $code->value . ' nicht gezählt: ' . $e->getMessage());
        }
    }

    /** @return array<string, int> Code => Anzahl in den letzten 24 Stunden */
    public function errors(): array
    {
        $out = [];
        foreach ($this->prune($this->settings->get(self::ERRORS_SETTING), $this->time->timestamp()) as $counts) {
            foreach ($counts as $code => $n) {
                $out[$code] = ($out[$code] ?? 0) + $n;
            }
        }
        ksort($out);
        return $out;
    }

    /** @return array<string, array<string, int>> Code => Ursache => Anzahl in den letzten 24 Stunden */
    public function details(): array
    {
        $out = [];
        foreach ($this->pruneDetails($this->settings->get(self::DETAILS_SETTING), $this->time->timestamp()) as $codes) {
            foreach ($codes as $code => $details) {
                foreach ($details as $detail => $n) {
                    $out[$code][$detail] = ($out[$code][$detail] ?? 0) + $n;
                }
            }
        }
        ksort($out);
        foreach ($out as &$details) {
            arsort($details);
        }
        return $out;
    }

    /**
     * @param bool $fresh am Zwischenspeicher der Anfrage vorbei lesen (andere Anfragen reihen gleichzeitig ein)
     * @return Code[] wartende Ereignisse, ohne sie zu entfernen
     */
    public function pending(bool $fresh = false): array
    {
        return array_map(static fn (string $c): Code => Code::from($c), array_keys($this->pendingDetails($fresh)));
    }

    /**
     * @param bool $fresh wie bei pending()
     * @return array<string, string> wartende Ereignisse: Code => Ursache ('' ohne)
     */
    public function pendingDetails(bool $fresh = false): array
    {
        $raw = $fresh ? $this->settings->fresh(self::PENDING_SETTING) : $this->settings->get(self::PENDING_SETTING);
        $out = [];
        // Gespeichert als „CODE“ oder „CODE:ursache“ (ältere Versionen: nur der Code).
        foreach (is_array($raw) ? $raw : [] as $value) {
            [$value, $detail] = is_string($value) ? explode(':', $value, 2) + [1 => ''] : ['', ''];
            $code = Code::tryFrom($value);
            if ($code !== null && !isset($out[$code->value])) {
                $out[$code->value] = preg_match(Detail::PATTERN, $detail) ? $detail : '';
            }
        }
        return $out;
    }

    /**
     * Nach dem Versand: Von den gesendeten Ereignissen nur die behalten, die beim nächsten Lauf noch einmal
     * versucht werden. Was eine andere Anfrage in der Zwischenzeit eingereiht hat, bleibt erhalten.
     *
     * @param Code[] $sent Ereignisse, die dieser Lauf zu senden versucht hat
     * @param Code[] $keep davon erneut zu versuchen
     */
    public function finishPending(array $sent, array $keep): void
    {
        $current = $this->pendingDetails(true);
        $values = [];
        foreach ($current as $code => $detail) {
            $c = Code::from($code);
            if (!in_array($c, $sent, true) || in_array($c, $keep, true)) {
                $values[] = self::pendingValue($code, $detail);
            }
        }
        // Erneut zu versuchen, aber in der Zwischenzeit verschwunden (sollte nicht vorkommen): ohne Ursache.
        foreach ($keep as $c) {
            if (!isset($current[$c->value])) {
                $values[] = $c->value;
            }
        }
        if ($values !== []) {
            $this->settings->set(self::PENDING_SETTING, $values);
        } elseif ($this->settings->get(self::PENDING_SETTING) !== null) {
            $this->settings->delete(self::PENDING_SETTING);
        }
    }

    /** Beim Widerruf der Zustimmung: Zähler, Warteschlange und Drosselung löschen. */
    public function forget(): void
    {
        foreach ([self::ERRORS_SETTING, self::DETAILS_SETTING, self::PENDING_SETTING, self::EVENTS_SETTING] as $key) {
            if ($this->settings->get($key) !== null) {
                $this->settings->delete($key);
            }
        }
    }

    /**
     * Fehlerprotokoll: neueste zuerst.
     *
     * @return list<array{at: int, first: int, count: int, code: string, detail: string, message: string}>
     */
    public function log(): array
    {
        return $this->pruneLog($this->settings->get(self::LOG_SETTING), $this->time->timestamp());
    }

    public function clearLog(): void
    {
        if ($this->settings->get(self::LOG_SETTING) !== null) {
            $this->settings->delete(self::LOG_SETTING);
        }
    }

    /**
     * Ins Fehlerprotokoll schreiben. Derselbe Fehler wie zuletzt wird nur gezählt (höchstens COUNT_MAX),
     * damit ein Dauerfehler nicht jede Anfrage schreiben lässt.
     */
    private function append(Code $code, string $detail, string $message, int $now): void
    {
        $message = self::redact($message);
        $log = $this->pruneLog($this->settings->get(self::LOG_SETTING), $now);
        $first = $log[0] ?? null;
        if ($first !== null && $first['code'] === $code->value && $first['detail'] === $detail && $first['message'] === $message) {
            if ($first['count'] >= self::COUNT_MAX) {
                return;
            }
            $log[0] = ['at' => $now, 'count' => $first['count'] + 1] + $first;
        } else {
            array_unshift($log, ['at' => $now, 'first' => $now, 'count' => 1, 'code' => $code->value, 'detail' => $detail, 'message' => $message]);
        }
        $this->settings->set(self::LOG_SETTING, array_slice($log, 0, self::LOG_MAX));
    }

    /**
     * Text fürs Protokoll: Adressen nur bis zum Server (geheime Kalender-Links und Schlüssel stehen im
     * Pfad oder in Parametern), Dateien ohne Verzeichnis, eine Zeile, gekürzt.
     */
    private static function redact(string $message): string
    {
        $message = (string) preg_replace('~\b(https?://)(?:[^/\s@]*@)?([^/\s?#]+)[^\s]*~i', '$1$2/…', $message);
        // Verzeichnisse auf dem Server weglassen (Dateiname und Zeile reichen).
        $message = (string) preg_replace('~(?<![:/\w])(?:/[^/\s:]+)+/([^/\s:]+)~', '$1', $message);
        $message = trim((string) preg_replace('/\s+/', ' ', $message));
        return mb_substr($message, 0, self::LOG_TEXT_MAX);
    }

    /** @return list<array{at: int, first: int, count: int, code: string, detail: string, message: string}> */
    private function pruneLog(mixed $raw, int $now): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $e) {
            if (!is_array($e) || !is_numeric($e['at'] ?? null) || (int) $e['at'] <= $now - self::LOG_DAYS * 86400
                || !is_string($e['code'] ?? null) || Code::tryFrom($e['code']) === null) {
                continue;
            }
            $out[] = [
                'at' => (int) $e['at'],
                'first' => (int) ($e['first'] ?? $e['at']),
                'count' => max(1, (int) ($e['count'] ?? 1)),
                'code' => $e['code'],
                'detail' => (string) ($e['detail'] ?? ''),
                'message' => (string) ($e['message'] ?? ''),
            ];
        }
        return array_slice($out, 0, self::LOG_MAX);
    }

    private function countDetail(Code $code, string $detail, string $hour, int $now): void
    {
        $details = $this->pruneDetails($this->settings->get(self::DETAILS_SETTING), $now);
        $known = $details[$hour][$code->value] ?? [];
        if (!isset($known[$detail]) && count($known) >= self::DETAILS_MAX) {
            $detail = 'other';
        }
        $details[$hour][$code->value][$detail] = ($known[$detail] ?? 0) + 1;
        $this->settings->set(self::DETAILS_SETTING, $details);
    }

    private function queue(Code $code, string $detail, int $now): void
    {
        $sent = $this->settings->fresh(self::EVENTS_SETTING);
        $sent = is_array($sent) ? $sent : [];
        if ($now - (int) ($sent[$code->value] ?? 0) < self::THROTTLE) {
            return;
        }
        $sent[$code->value] = $now;
        // Alte Einträge entfernen, damit die Einstellung klein bleibt.
        $sent = array_filter($sent, static fn (mixed $at): bool => $now - (int) $at < self::THROTTLE);
        $this->settings->set(self::EVENTS_SETTING, $sent);
        $pending = $this->pendingDetails(true);
        if (!isset($pending[$code->value]) && count($pending) < self::PENDING_MAX) {
            $pending[$code->value] = $detail;
            $values = [];
            foreach ($pending as $c => $d) {
                $values[] = self::pendingValue($c, $d);
            }
            $this->settings->set(self::PENDING_SETTING, $values);
        }
        $this->scheduler->runSoon($this->task);
    }

    private static function pendingValue(string $code, string $detail): string
    {
        return $detail === '' ? $code : $code . ':' . $detail;
    }

    /** @return array<string, array<string, array<string, int>>> Stunde => Code => Ursache => Anzahl, nur die letzten 24 Stunden */
    private function pruneDetails(mixed $raw, int $now): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $hour => $codes) {
            if (!is_numeric($hour) || (int) $hour <= $now - self::WINDOW || !is_array($codes)) {
                continue;
            }
            foreach ($codes as $code => $details) {
                if (!is_string($code) || Code::tryFrom($code) === null || !is_array($details)) {
                    continue;
                }
                foreach ($details as $detail => $n) {
                    if (is_string($detail) && preg_match(Detail::PATTERN, $detail) && is_numeric($n) && (int) $n > 0) {
                        $out[(string) (int) $hour][$code][$detail] = (int) $n;
                    }
                }
            }
        }
        return $out;
    }

    /** @return array<string, array<string, int>> Stunde => Code => Anzahl, nur die letzten 24 Stunden und bekannte Codes */
    private function prune(mixed $raw, int $now): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $hour => $counts) {
            if (!is_numeric($hour) || (int) $hour <= $now - self::WINDOW || !is_array($counts)) {
                continue;
            }
            foreach ($counts as $code => $n) {
                if (is_string($code) && Code::tryFrom($code) !== null && is_numeric($n) && (int) $n > 0) {
                    $out[(string) (int) $hour][$code] = (int) $n;
                }
            }
        }
        return $out;
    }
}
