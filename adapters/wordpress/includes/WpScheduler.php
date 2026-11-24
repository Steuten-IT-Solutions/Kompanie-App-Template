<?php

declare(strict_types=1);

namespace KompanieApp\WordPress;

use KompanieApp\Core\Domain\BoardMailService;
use KompanieApp\Core\Port\Scheduler;
use KompanieApp\Core\Push\PushService;

/** Wiederkehrende Aufgaben über WP-Cron; alle laufen über den Hook kompanie_app_task. */
final class WpScheduler implements Scheduler
{
    public const HOOK = 'kompanie_app_task';
    /** Eigener Zeitplan für Aufgaben unter einer Stunde (z. B. Abgleich für Push alle 15 Minuten). */
    public const QUARTER_HOUR = 'kompanie_app_15min';

    /**
     * Aufgaben, die nach der Antwort der auslösenden Anfrage gleich laufen (Benachrichtigungen, Mails vom Brett und
     * die übrigen Mails), statt auf den nächsten Lauf von WP-Cron zu warten. Ist WP-Cron abgeschaltet und ruft ein
     * Cronjob ihn z. B. alle 15 Minuten auf, kämen sie sonst bis zu 15 Minuten später.
     */
    public const AFTER_RESPONSE = [PushService::TASK_SEND, BoardMailService::TASK];

    /** @var array<string, true> Aufgaben, die nach der Antwort dieser Anfrage laufen sollen */
    private static array $afterResponse = [];

    /** @var array<string, true> Aufgaben, deren Sperre diese Anfrage gerade hält */
    private static array $holding = [];

    /** Läuft runAfterResponse gerade? Was dabei noch fällig wird (z. B. die nächste Portion), übernimmt WP-Cron. */
    private static bool $draining = false;

    /** Filter cron_schedules: den eigenen Zeitplan anmelden. */
    public static function schedules(array $schedules): array
    {
        $schedules[self::QUARTER_HOUR] = ['interval' => 900, 'display' => 'Alle 15 Minuten (Kompanie-App)'];
        return $schedules;
    }

    public function schedule(string $task, int $intervalSeconds): void
    {
        $recurrence = match (true) {
            $intervalSeconds >= DAY_IN_SECONDS => 'daily',
            $intervalSeconds >= 12 * HOUR_IN_SECONDS => 'twicedaily',
            $intervalSeconds >= HOUR_IN_SECONDS => 'hourly',
            default => self::QUARTER_HOUR,
        };
        // Hat sich der Abstand geändert (z. B. Statusmeldung früher täglich, jetzt stündlich), neu einplanen.
        $current = self::recurrence($task);
        if ($current !== null && $current !== $recurrence) {
            wp_clear_scheduled_hook(self::HOOK, [$task]);
            $current = null;
        }
        if ($current === null) {
            wp_schedule_event(time() + 60, $recurrence, self::HOOK, [$task]);
        }
    }

    /**
     * Wiederholung des eingeplanten Laufs oder null. Nicht wp_get_schedule(): Das sieht nur den nächsten
     * Lauf, und der kann ein einmaliger aus runSoon() sein.
     */
    private static function recurrence(string $task): ?string
    {
        $key = md5(serialize([$task]));
        foreach (_get_cron_array() ?: [] as $cron) {
            $event = $cron[self::HOOK][$key] ?? null;
            if (is_array($event) && !empty($event['schedule'])) {
                return (string) $event['schedule'];
            }
        }
        return null;
    }

    public function unschedule(string $task): void
    {
        wp_clear_scheduled_hook(self::HOOK, [$task]);
    }

    /** real, wenn WP-Cron abgeschaltet ist (dann ruft ihn ein Cronjob des Hosters auf), sonst wp. */
    public function kind(): string
    {
        return defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? 'real' : 'wp';
    }

    /** Zusätzlicher einmaliger Lauf; WordPress legt innerhalb von zehn Minuten keinen zweiten an. */
    public function runSoon(string $task): void
    {
        if (!wp_next_scheduled(self::HOOK, [$task])) {
            return;
        }
        wp_schedule_single_event(time(), self::HOOK, [$task]);
        // Im Cron-Lauf selbst erledigt das WP-Cron; sonst nach der Antwort (der eingeplante Lauf bleibt der Rückfall).
        if (in_array($task, self::AFTER_RESPONSE, true) && !wp_doing_cron() && !self::$draining) {
            if (self::$afterResponse === []) {
                add_action('shutdown', static fn () => self::runAfterResponse(), PHP_INT_MAX, 0);
            }
            self::$afterResponse[$task] = true;
        }
    }

    /**
     * Action shutdown: die Antwort abschließen (PHP-FPM, LiteSpeed) und danach die vorgemerkten Aufgaben erledigen.
     * Ohne solchen Server (z. B. Apache mit mod_php, WP-CLI) bleibt es beim eingeplanten Lauf über WP-Cron: Sonst
     * wartete die Person auf das Verschicken.
     *
     * @param (callable(): bool)|null $finish schließt die Antwort ab (Tests); ohne Angabe fastcgi_finish_request bzw.
     *                                         litespeed_finish_request
     * @return string[] die erledigten Aufgaben
     */
    public static function runAfterResponse(?callable $finish = null): array
    {
        $tasks = array_keys(self::$afterResponse);
        self::$afterResponse = [];
        $finish ??= match (true) {
            function_exists('fastcgi_finish_request') => 'fastcgi_finish_request',
            function_exists('litespeed_finish_request') => 'litespeed_finish_request',
            default => null,
        };
        if ($tasks === [] || $finish === null || !$finish()) {
            return [];
        }
        ignore_user_abort(true);
        self::$draining = true;
        $done = [];
        foreach ($tasks as $task) {
            // Läuft die Aufgabe gerade anderswo (Cron), bleibt der einmalige Lauf stehen: Sie kommt dann mit dem
            // nächsten Cron-Lauf. Sonst ihn übernehmen, damit WP-Cron ihn nicht noch einmal startet.
            $ran = self::exclusive($task, static function () use ($task): void {
                self::takeSingleRuns($task);
                do_action(self::HOOK, $task);
            });
            if ($ran) {
                $done[] = $task;
            }
        }
        self::$draining = false;
        return $done;
    }

    /**
     * Führt $run aus, wenn die Aufgabe nicht gerade in einer anderen Anfrage läuft (gleichzeitiger Cron-Lauf, zweite
     * Anfrage): Die Mails haben keine eigene Sperre und gingen sonst doppelt raus. Verschachtelt (Hook innerhalb von
     * runAfterResponse) gilt die Sperre schon.
     *
     * @param callable(): void $run
     * @return bool false, wenn die Aufgabe gerade anderswo läuft
     */
    public static function exclusive(string $task, callable $run): bool
    {
        if (isset(self::$holding[$task])) {
            $run();
            return true;
        }
        if (!self::lock($task)) {
            return false;
        }
        self::$holding[$task] = true;
        try {
            $run();
        } catch (\Throwable $e) {
            error_log('Kompanie-App: Aufgabe ' . $task . ': ' . $e->getMessage());
        } finally {
            unset(self::$holding[$task]);
            self::unlock($task);
        }
        return true;
    }

    /** Fällige einmalige Läufe der Aufgabe aus dem Plan von WP-Cron nehmen (der wiederkehrende bleibt). */
    private static function takeSingleRuns(string $task): void
    {
        $key = md5(serialize([$task]));
        foreach (_get_cron_array() ?: [] as $timestamp => $cron) {
            $event = $cron[self::HOOK][$key] ?? null;
            if ((int) $timestamp <= time() && is_array($event) && empty($event['schedule'])) {
                wp_unschedule_event((int) $timestamp, self::HOOK, [$task]);
            }
        }
    }

    /**
     * Sperre je Aufgabe als Zeile in der Tabelle options: INSERT IGNORE legt sie nur an, wenn es sie noch nicht gibt
     * (eindeutiger Schlüssel; add_option ginge nicht, es überschreibt eine vorhandene Zeile). Eine Sperre, die älter als
     * fünf Minuten ist, gilt als verwaist (abgebrochener Lauf).
     *
     * @return bool false, wenn die Aufgabe gerade läuft
     */
    public static function lock(string $task): bool
    {
        global $wpdb;
        $name = self::lockName($task);
        $insert = static fn (): bool => (int) $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $name,
            (string) time(),
        )) === 1;
        if ($insert()) {
            return true;
        }
        $since = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
        if ($since !== null && (int) $since > time() - 300) {
            return false;
        }
        // Nur die verwaiste Sperre löschen, nicht eine, die ein anderer Aufruf eben neu angelegt hat.
        if ($since !== null) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $since));
        }
        return $insert();
    }

    public static function unlock(string $task): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", self::lockName($task)));
    }

    private static function lockName(string $task): string
    {
        return 'kompanie_app_lock_' . $task;
    }
}
