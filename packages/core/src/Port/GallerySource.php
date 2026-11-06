<?php

declare(strict_types=1);

namespace KompanieApp\Core\Port;

/**
 * Herkunft der Galerie (Issue #284): Ordner und Dateien auflisten und Dateien lesen. Erste Umsetzung ist Google Drive
 * (DriveClient); die Galerie spricht nur mit dieser Schnittstelle.
 *
 * Einträge sind Arrays mit mindestens id, name und mimeType (Ordner: FOLDER_MIME bzw. isFolder), dazu je nach Quelle
 * size, modifiedTime, createdTime, imageMediaMetadata und videoMediaMetadata (Felder wie bei Google Drive).
 */
interface GallerySource
{
    /** Zugang eingerichtet (Anmeldung bzw. Schlüssel vorhanden)? */
    public function hasCredentials(): bool;

    /** @return array<int, array<string, mixed>> alle Einträge eines Ordners */
    public function listChildren(string $folderId): array;

    /** Lädt eine Datei in eine temporäre Datei; der Aufrufer löscht sie. */
    public function download(string $fileId): string;

    /** Vorschaubild der Quelle (z. B. für Videos) als temporäre Datei mit höchstens $maxEdge Pixeln Kantenlänge. */
    public function thumbnail(string $fileId, int $maxEdge): string;

    /**
     * Ausschnitt einer Datei weiterreichen (Videos): $onStart(start, end, total) vor den Daten, dann $onData je Stück.
     *
     * @param \Closure(int, int, int): void $onStart
     * @param \Closure(string): bool $onData
     * @return bool true, wenn der ganze Bereich weitergegeben wurde
     */
    public function streamRange(string $fileId, int $start, int $end, \Closure $onStart, \Closure $onData): bool;

    /** Adresse der Datei in der Quelle (für Admins, z. B. bei fehlgeschlagenen Bildern). */
    public function fileUrl(string $fileId): string;

    /** Ordner-Kennung aus einem Link oder direkt; leer, wenn nicht erkannt. */
    public static function parseFolderId(string $input): string;

    /** @param array<string, mixed> $f */
    public static function isFolder(array $f): bool;

    /** @param array<string, mixed> $f */
    public static function isImage(array $f): bool;

    /** @param array<string, mixed> $f */
    public static function isVideo(array $f): bool;

    /** Videoformat, das die Galerie abspielt. */
    public static function isPlayableVideo(string $mime): bool;
}
