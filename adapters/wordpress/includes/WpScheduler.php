<?php

declare(strict_types=1);

namespace KompanieApp\WordPress;

use KompanieApp\Core\Port\Scheduler;

/** Wiederkehrende Aufgaben über WP-Cron; alle laufen über den Hook kompanie_app_task. */
final class WpScheduler implements Scheduler
{
    public const HOOK = 'kompanie_app_task';

    public function schedule(string $task, int $intervalSeconds): void
    {
        $recurrence = $intervalSeconds >= DAY_IN_SECONDS ? 'daily' : ($intervalSeconds >= 12 * HOUR_IN_SECONDS ? 'twicedaily' : 'hourly');
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
        if (wp_next_scheduled(self::HOOK, [$task])) {
            wp_schedule_single_event(time(), self::HOOK, [$task]);
        }
    }
}
