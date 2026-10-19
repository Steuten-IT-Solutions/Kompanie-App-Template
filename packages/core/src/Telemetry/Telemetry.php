<?php

declare(strict_types=1);

namespace KompanieApp\Core\Telemetry;

use KompanieApp\Core\Port\Scheduler;
use KompanieApp\Core\Port\Settings;
use KompanieApp\Core\Domain\Time;

/**
 * Fehlerzähler und sofortige Meldungen für die Statusmeldungen (Issue #20), nur mit Zustimmung.
 *
 * - Zähler je Code und Stunde (Einstellung ERRORS_SETTING), ältere als 24 Stunden werden verworfen.
 * - Schwere Fehler (Code::severe) kommen in eine Warteschlange (PENDING_SETTING) und werden von der
 *   Aufgabe status_report gesendet, die dafür vorgezogen wird: So wartet keine Anfrage auf den Versand.
 *   Je Code höchstens einmal pro Stunde (EVENTS_SETTING).
 * - Ohne Zustimmung wird nichts gezählt und nichts gemerkt.
 *
 * record() wirft nie: Ein Fehler beim Zählen darf keinen Aufruf scheitern lassen.
 */
final class Telemetry
{
    public const ERRORS_SETTING = 'telemetry_errors';
    public const PENDING_SETTING = 'telemetry_pending';
    public const EVENTS_SETTING = 'telemetry_events';
    /** Höchstens so viele Ereignisse warten auf den Versand (mehr verschiedene Codes gibt es ohnehin kaum). */
    private const PENDING_MAX = 10;
    private const WINDOW = 86400;
    private const THROTTLE = 3600;
    /** Höchstens so viele je Code und Stunde (danach wird nicht mehr gezählt) */
    private const COUNT_MAX = 1000;

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

    public function record(Code $code): void
    {
        try {
            if (!($this->enabled)()) {
                return;
            }
            $now = $this->time->timestamp();
            $hour = (string) ($now - $now % 3600);
            $errors = $this->prune($this->settings->get(self::ERRORS_SETTING), $now);
            $count = (int) ($errors[$hour][$code->value] ?? 0);
            // Ab einer Grenze nicht mehr schreiben: Ein Dauerfehler soll nicht jede Anfrage zum Schreiben bringen.
            if ($count < self::COUNT_MAX) {
                $errors[$hour][$code->value] = $count + 1;
                $this->settings->set(self::ERRORS_SETTING, $errors);
            }
            if ($code->severe()) {
                $this->queue($code, $now);
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

    /**
     * @param bool $fresh am Zwischenspeicher der Anfrage vorbei lesen (andere Anfragen reihen gleichzeitig ein)
     * @return Code[] wartende Ereignisse, ohne sie zu entfernen
     */
    public function pending(bool $fresh = false): array
    {
        $raw = $fresh ? $this->settings->fresh(self::PENDING_SETTING) : $this->settings->get(self::PENDING_SETTING);
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $value) {
            $code = is_string($value) ? Code::tryFrom($value) : null;
            if ($code !== null && !in_array($code, $out, true)) {
                $out[] = $code;
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
        $values = array_map(static fn (Code $c): string => $c->value, $keep);
        foreach ($this->pending(true) as $code) {
            if (!in_array($code, $sent, true)) {
                $values[] = $code->value;
            }
        }
        $values = array_values(array_unique($values));
        if ($values !== []) {
            $this->settings->set(self::PENDING_SETTING, $values);
        } elseif ($this->settings->get(self::PENDING_SETTING) !== null) {
            $this->settings->delete(self::PENDING_SETTING);
        }
    }

    /** Beim Widerruf der Zustimmung: Zähler, Warteschlange und Drosselung löschen. */
    public function forget(): void
    {
        foreach ([self::ERRORS_SETTING, self::PENDING_SETTING, self::EVENTS_SETTING] as $key) {
            if ($this->settings->get($key) !== null) {
                $this->settings->delete($key);
            }
        }
    }

    private function queue(Code $code, int $now): void
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
        $pending = array_map(static fn (Code $c): string => $c->value, $this->pending(true));
        if (!in_array($code->value, $pending, true) && count($pending) < self::PENDING_MAX) {
            $pending[] = $code->value;
            $this->settings->set(self::PENDING_SETTING, $pending);
        }
        $this->scheduler->runSoon($this->task);
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
