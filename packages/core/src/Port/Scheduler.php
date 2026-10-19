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

    /**
     * Eine geplante Aufgabe möglichst bald ausführen (z. B. weitere Galeriebilder aufbereiten).
     * Ist sie nicht geplant, passiert nichts. Der nächste reguläre Lauf verschiebt sich dadurch.
     */
    public function runSoon(string $task): void;

    /**
     * Wie die Aufgaben angestoßen werden (für die Statusmeldungen): real (Cronjob des Hosters),
     * pseudo (nur bei Seitenaufrufen, nachts können Läufe ausbleiben) oder wp (WP-Cron).
     */
    public function kind(): string;
}
