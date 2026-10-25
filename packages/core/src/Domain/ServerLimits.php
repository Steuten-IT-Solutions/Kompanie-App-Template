<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Technische Grenzen der Umgebung (Issue #172): Die App setzt für formatierte Inhalte und ihre Bilder keine eigenen
 * Grenzen, liest aber die des Servers aus, zeigt sie an und meldet Überschreitungen verständlich.
 *
 * - post_max_size: größte Anfrage. Bilder gehen als Base64 im JSON (rund ein Drittel größer als die Datei).
 * - upload_max_filesize: gilt nur für Datei-Uploads als Formular (multipart); wird zur Information mitgeliefert.
 * - memory_limit: Bildverarbeitung (GD) braucht das Bild unkomprimiert im Speicher.
 * - max_allowed_packet (nur MySQL/MariaDB): größter Wert, den die Datenbank in einer Anweisung annimmt.
 */
final class ServerLimits
{
    /** Bytes aus einer PHP-Angabe wie „8M“; null = unbegrenzt bzw. nicht gesetzt. */
    public static function bytes(string|false $value): ?int
    {
        if ($value === false) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || $value === '-1' || $value === '0') {
            return null;
        }
        if (preg_match('/^(\d+)\s*([kmgKMG]?)/', $value, $m) !== 1) {
            return null;
        }
        $n = (int) $m[1];
        return match (strtolower($m[2])) {
            'k' => $n * 1024,
            'm' => $n * 1024 * 1024,
            'g' => $n * 1024 * 1024 * 1024,
            default => $n,
        };
    }

    public static function postMaxSize(): ?int
    {
        return self::bytes(ini_get('post_max_size'));
    }

    public static function uploadMaxFilesize(): ?int
    {
        return self::bytes(ini_get('upload_max_filesize'));
    }

    public static function memoryLimit(): ?int
    {
        return self::bytes(ini_get('memory_limit'));
    }

    /** Noch verfügbarer Arbeitsspeicher; null = unbegrenzt. */
    public static function memoryAvailable(): ?int
    {
        $limit = self::memoryLimit();
        return $limit === null ? null : max(0, $limit - memory_get_usage(true));
    }

    public static function maxAllowedPacket(Connection $db): ?int
    {
        if ($db->driver() !== 'mysql') {
            return null;
        }
        try {
            $v = $db->scalar('SELECT @@max_allowed_packet');
        } catch (\Throwable) {
            return null;
        }
        return is_numeric($v) && (int) $v > 0 ? (int) $v : null;
    }

    /** Für Meldungen: „8 MB“, „512 KB“. */
    public static function format(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            $mb = $bytes / (1024 * 1024);
            return str_replace('.', ',', (string) round($mb, $mb >= 10 ? 0 : 1)) . ' MB';
        }
        return max(1, (int) round($bytes / 1024)) . ' KB';
    }
}
