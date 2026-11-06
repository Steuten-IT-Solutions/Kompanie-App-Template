<?php

declare(strict_types=1);

namespace KompanieApp\Core\Port;

/**
 * Ablage für Originale, die die Galerie selbst hält (Issue #284, gebraucht ab „eigener Server“, Issue #285): Datei
 * ablegen, lesen, löschen und einem Album zuordnen. Schlüssel sind relativ und von der Ablage vergeben.
 */
interface MediaStore
{
    /**
     * Kopiert die Datei $source in die Ablage, zugeordnet zum Album $albumId.
     *
     * @param string $extension Dateiendung ohne Punkt (jpg, png, webp, mp4, webm)
     * @return string Schlüssel
     */
    public function put(int $albumId, string $source, string $extension): string;

    /** Absoluter Pfad zum Lesen; null, wenn es die Datei nicht gibt. */
    public function path(string $key): ?string;

    public function delete(string $key): void;

    /** Ordnet die Datei einem anderen Album zu; liefert den neuen Schlüssel. */
    public function move(string $key, int $albumId): string;

    /** Belegter Platz in Bytes. */
    public function bytes(): int;

    /**
     * Alle Schlüssel mit der Zeit der letzten Änderung (Unix-Zeit), z. B. zum Aufräumen von Dateien ohne Verweis.
     *
     * @return array<string, int>
     */
    public function keys(): array;
}
