<?php

declare(strict_types=1);

namespace KompanieApp\Core\Port;

/**
 * Dateiablage der Galerie.
 *
 * Öffentliche Dateien liefert der Webserver direkt aus. Geschützte Dateien
 * (Bilder aus „intern“) liegen außerhalb des direkten Zugriffs und werden nur
 * nach Prüfung der Anmeldung über Environment::photoUrl() ausgeliefert.
 * Pfade sind relativ, z. B. „12/ab34…-thumb.jpg“.
 */
interface FileStorage
{
    /** Absoluter Pfad im Dateisystem; legt fehlende Verzeichnisse an. */
    public function path(string $relative, bool $public): string;

    /** Adresse einer öffentlichen Datei für den Browser. */
    public function publicUrl(string $relative): string;

    public function delete(string $relative, bool $public): void;

    /** Temporäre Datei, die der Aufrufer selbst löscht. */
    public function tempFile(): string;
}
