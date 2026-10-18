<?php

declare(strict_types=1);

namespace KompanieApp\Core\Port;

/**
 * Wiederkehrende Aufgaben, z. B. der stündliche Galerie-Abgleich.
 * WordPress: WP-Cron. Standalone: Cronjob des Hosters, sonst Pseudo-Cron bei Seitenaufrufen.
 */
interface Scheduler
{
    /** @param callable(): void $task */
    public function every(string $name, int $intervalSeconds, callable $task): void;

    public function cancel(string $name): void;
}
