<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

/**
 * Entfernt Metadaten aus fertig erzeugten JPEGs: EXIF (u. a. GPS-Ort, Kamera, Seriennummer), XMP, IPTC und Kommentare.
 *
 * Der Bildeditor von WordPress (Imagick) schreibt die Metadaten des Originals mit, wenn er nicht verkleinern muss
 * (Bild schon klein genug, z. B. die Fassung in Originalauflösung); GD schreibt nie welche. Damit kein öffentliches
 * Bild den Aufnahmeort verrät, geht jede gespeicherte Fassung hier durch. Behalten werden JFIF (APP0), das
 * Farbprofil (APP2 „ICC_PROFILE“), das Adobe-Segment (APP14) und alle Segmente, die das Bild selbst beschreiben.
 * Die Ausrichtung ist zu diesem Zeitpunkt schon in die Pixel übernommen (ImageResizer dreht nach EXIF).
 */
final class JpegMetadata
{
    /** Bytes ohne Metadaten; was kein lesbares JPEG ist, bleibt unverändert (der Aufrufer hat es selbst erzeugt). */
    public static function strip(string $jpeg): string
    {
        if (!str_starts_with($jpeg, "\xFF\xD8")) {
            return $jpeg;
        }
        $out = "\xFF\xD8";
        $pos = 2;
        $len = strlen($jpeg);
        while ($pos + 4 <= $len) {
            if ($jpeg[$pos] !== "\xFF") {
                return $jpeg; // unerwarteter Aufbau: lieber nichts verändern
            }
            $marker = ord($jpeg[$pos + 1]);
            if ($marker === 0xFF) { // Füllbytes
                $pos++;
                continue;
            }
            if ($marker === 0xDA) { // Start of Scan: ab hier Bilddaten bis zum Ende
                return $out . substr($jpeg, $pos);
            }
            if ($marker === 0xD8 || $marker === 0xD9 || ($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0x01) {
                $out .= substr($jpeg, $pos, 2); // Marker ohne Länge
                $pos += 2;
                continue;
            }
            $segment = unpack('n', substr($jpeg, $pos + 2, 2));
            $size = is_array($segment) ? (int) $segment[1] : 0;
            if ($size < 2 || $pos + 2 + $size > $len) {
                return $jpeg;
            }
            if (!self::isMetadata($marker, substr($jpeg, $pos + 4, min(14, $size - 2)))) {
                $out .= substr($jpeg, $pos, 2 + $size);
            }
            $pos += 2 + $size;
        }
        return $jpeg;
    }

    /** Datei an Ort und Stelle bereinigen. */
    public static function stripFile(string $file): void
    {
        $data = @file_get_contents($file);
        if ($data === false || $data === '') {
            return;
        }
        $clean = self::strip($data);
        if ($clean !== $data && @file_put_contents($file, $clean) === false) {
            throw new \RuntimeException('Das Bild kann nicht gespeichert werden.');
        }
    }

    private static function isMetadata(int $marker, string $head): bool
    {
        if ($marker === 0xFE) { // Kommentar
            return true;
        }
        if ($marker < 0xE1 || $marker > 0xEF) { // APP0 (JFIF) und alle Nicht-APP-Segmente bleiben
            return false;
        }
        // Farbprofil und Adobe-Segment (Farbumrechnung bei CMYK) bleiben: keine persönlichen Angaben, aber nötig für die Farben.
        return !(($marker === 0xE2 && str_starts_with($head, "ICC_PROFILE\0")) || ($marker === 0xEE && str_starts_with($head, 'Adobe')));
    }
}
