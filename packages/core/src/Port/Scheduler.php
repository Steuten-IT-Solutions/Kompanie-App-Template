<?php

declare(strict_types=1);

namespace KompanieApp\Core\Port;

/**
 * Wiederkehrende Aufgaben, z. B. der stündliche Galerie-Abgleich.
 * WordPress: WP-Cron. Standalone: Pseudo-Cron bei Seitenaufrufen, optional Cronjob des Hosters.
 * Fällige Aufgaben führt der Adapter über App::runTask() aus.
 */
interface Scheduler
{
    public function schedule(string $task, int $intervalSeconds): void;

    public function unschedule(string $task): void;
}
