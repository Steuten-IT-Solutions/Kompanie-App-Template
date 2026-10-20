<?php

declare(strict_types=1);

namespace KompanieApp\Core\Http;

/**
 * Bedingte Abrufe geschützter Dateien (Galeriebilder, Dokumente, Abzeichen): ETag aus Schlüssel,
 * Größe und Änderungszeit der Datei; passt If-None-Match, antworten die Adapter mit 304 ohne Inhalt.
 * Die Rechte prüft der Kern vorher wie bei jedem Abruf; ein 304 verrät also nichts zusätzlich.
 */
final class FileCache
{
    /** Starker ETag (mit Anführungszeichen) oder null, wenn die Datei fehlt. */
    public static function etag(string $key, string $path): ?string
    {
        $size = @filesize($path);
        $mtime = @filemtime($path);
        if ($size === false || $mtime === false) {
            return null;
        }
        return '"' . substr(hash('sha256', $key . '|' . $size . '|' . $mtime), 0, 32) . '"';
    }

    /** Passt der ETag zu If-None-Match (Liste, schwache Vergleiche mit W/, oder *)? */
    public static function matches(?string $etag, ?string $ifNoneMatch): bool
    {
        if ($etag === null || $ifNoneMatch === null || trim($ifNoneMatch) === '') {
            return false;
        }
        if (trim($ifNoneMatch) === '*') {
            return true;
        }
        $plain = self::opaque($etag);
        foreach (explode(',', $ifNoneMatch) as $candidate) {
            if (self::opaque($candidate) === $plain) {
                return true;
            }
        }
        return false;
    }

    private static function opaque(string $tag): string
    {
        $tag = trim($tag);
        return str_starts_with($tag, 'W/') ? substr($tag, 2) : $tag;
    }
}
