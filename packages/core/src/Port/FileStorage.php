<?php

declare(strict_types=1);

namespace KompanieApp\Core\Port;

/**
 * Dateiablage der Galerie.
 *
 * Öffentliche Dateien liefert der Webserver direkt aus. Geschützte Dateien
 * (Bilder aus „intern“) liegen außerhalb des direkten Zugriffs und werden
 * nur nach Prüfung der Anmeldung ausgeliefert.
 */
interface FileStorage
{
    public function put(string $path, string $sourceFile, bool $public): void;

    public function delete(string $path, bool $public): void;

    public function exists(string $path, bool $public): bool;

    /** Adresse für den Browser. Bei geschützten Dateien die Auslieferung über die App. */
    public function url(string $path, bool $public): string;

    /** Absoluter Pfad, z. B. zum Ausliefern geschützter Dateien. */
    public function localPath(string $path, bool $public): string;

    /** Temporäre Datei, die der Aufrufer selbst löscht. */
    public function tempFile(): string;
}
