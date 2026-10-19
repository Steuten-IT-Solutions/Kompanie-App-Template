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
        if (!wp_next_scheduled(self::HOOK, [$task])) {
            $recurrence = $intervalSeconds >= DAY_IN_SECONDS ? 'daily' : ($intervalSeconds >= 12 * HOUR_IN_SECONDS ? 'twicedaily' : 'hourly');
            wp_schedule_event(time() + 60, $recurrence, self::HOOK, [$task]);
        }
    }

    public function unschedule(string $task): void
    {
        wp_clear_scheduled_hook(self::HOOK, [$task]);
    }

    /** Zusätzlicher einmaliger Lauf; WordPress legt innerhalb von zehn Minuten keinen zweiten an. */
    public function runSoon(string $task): void
    {
        if (wp_next_scheduled(self::HOOK, [$task])) {
            wp_schedule_single_event(time(), self::HOOK, [$task]);
        }
    }
}
