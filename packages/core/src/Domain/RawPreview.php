<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

/**
 * Eingebettetes Vorschau-JPEG aus Kamera-RAW-Dateien (NEF, CR2, ARW, DNG, PEF u. a.).
 *
 * Diese Formate sind wie TIFF aufgebaut und tragen ein fertiges JPEG der Kamera mit, bei
 * Spiegelreflexkameras meist in voller oder nahezu voller Größe. Viele Hoster haben für
 * ImageMagick keinen RAW-Decoder (NoDecodeDelegateForThisImageFormat), GD kann RAW nie lesen;
 * das eingebettete JPEG können beide verarbeiten. Gelesen werden nur die Verzeichnisse (IFDs)
 * der Datei, nicht die Rohdaten.
 */
final class RawPreview
{
    /** Höchstens so viele IFDs durchsuchen (Schutz vor Schleifen in kaputten Dateien). */
    private const MAX_IFDS = 32;

    /**
     * Größtes eingebettetes JPEG als neue Datei $target, mit der Ausrichtung der RAW-Datei als
     * EXIF (die Kamera vermerkt sie meist nur in der RAW-Datei, nicht im Vorschau-JPEG).
     *
     * @param int $minEdge kürzere Vorschauen (z. B. 160 Pixel breite Daumennagelbilder) nicht nehmen
     * @return bool false, wenn die Datei kein TIFF-Aufbau ist oder kein passendes JPEG enthält
     */
    public static function extract(string $source, string $target, int $minEdge = 0): bool
    {
        $fh = @fopen($source, 'rb');
        if ($fh === false) {
            return false;
        }
        try {
            $head = (string) fread($fh, 8);
            if (strlen($head) < 8 || !in_array(substr($head, 0, 2), ['II', 'MM'], true)) {
                return false;
            }
            $le = $head[0] === 'I';
            $fileSize = (int) fstat($fh)['size'];
            [$best, $orientation] = self::scan($fh, $le, self::u32(substr($head, 4, 4), $le), $fileSize);
            if ($best === null) {
                return false;
            }
            fseek($fh, $best[0]);
            $jpeg = (string) fread($fh, $best[1]);
        } finally {
            fclose($fh);
        }
        $info = strlen($jpeg) === $best[1] && str_starts_with($jpeg, "\xFF\xD8") ? @getimagesizefromstring($jpeg) : false;
        if ($info === false || max($info[0], $info[1]) < $minEdge) {
            return false;
        }
        if ($orientation > 1 && $orientation <= 8) {
            $jpeg = self::withOrientation($jpeg, $orientation);
        }
        return file_put_contents($target, $jpeg) !== false;
    }

    /**
     * Alle IFDs ab $first (Kette und SubIFDs) nach JPEG-Vorschauen durchsuchen.
     *
     * @param resource $fh
     * @return array{0: array{0: int, 1: int}|null, 1: int} [[Position, Länge] des größten JPEGs, Ausrichtung aus IFD0]
     */
    private static function scan($fh, bool $le, int $first, int $fileSize): array
    {
        $queue = [$first];
        $seen = [];
        $best = null;
        $orientation = 1;
        while ($queue !== [] && count($seen) < self::MAX_IFDS) {
            $offset = array_shift($queue);
            if ($offset < 8 || $offset + 2 > $fileSize || isset($seen[$offset])) {
                continue;
            }
            $isFirst = $seen === [];
            $seen[$offset] = true;
            fseek($fh, $offset);
            $count = self::u16((string) fread($fh, 2), $le);
            $raw = (string) fread($fh, $count * 12 + 4);
            if (strlen($raw) < $count * 12 + 4) {
                continue;
            }
            $tags = [];
            for ($i = 0; $i < $count; $i++) {
                $e = substr($raw, $i * 12, 12);
                $tags[self::u16(substr($e, 0, 2), $le)] = [self::u16(substr($e, 2, 2), $le), self::u32(substr($e, 4, 4), $le), substr($e, 8, 4)];
            }
            if ($isFirst && isset($tags[0x0112])) {
                $orientation = self::u16(substr($tags[0x0112][2], 0, 2), $le);
            }
            // JPEGInterchangeFormat/-Length (NEF, CR2, ARW, PEF …)
            $candidates = [];
            if (isset($tags[0x0201], $tags[0x0202])) {
                $candidates[] = [self::value($tags[0x0201], $le), self::value($tags[0x0202], $le)];
            }
            // DNG: Vorschau als ein JPEG-Streifen (Compression 7 bzw. 6) in einem verkleinerten Bild (NewSubFileType Bit 0);
            // die Rohdaten selbst sind oft verlustfrei JPEG-komprimiert, die gehören nicht dazu.
            $compression = isset($tags[0x0103]) ? self::value($tags[0x0103], $le) : 0;
            $reduced = isset($tags[0x00FE]) && (self::value($tags[0x00FE], $le) & 1) === 1;
            if ($reduced && in_array($compression, [6, 7], true) && isset($tags[0x0111], $tags[0x0117])
                && $tags[0x0111][1] === 1 && $tags[0x0117][1] === 1) {
                $candidates[] = [self::value($tags[0x0111], $le), self::value($tags[0x0117], $le)];
            }
            foreach ($candidates as [$start, $length]) {
                if ($length > 2 && $start >= 8 && $start + $length <= $fileSize && ($best === null || $length > $best[1])) {
                    $best = [$start, $length];
                }
            }
            if (isset($tags[0x014A])) {
                array_push($queue, ...self::longs($fh, $tags[0x014A], $le));
            }
            $next = self::u32(substr($raw, $count * 12, 4), $le);
            if ($next !== 0) {
                $queue[] = $next;
            }
        }
        return [$best, $orientation];
    }

    /**
     * Wert eines Eintrags mit einem Element (SHORT oder LONG).
     *
     * @param array{0: int, 1: int, 2: string} $tag [Typ, Anzahl, Wertfeld]
     */
    private static function value(array $tag, bool $le): int
    {
        return $tag[0] === 3 ? self::u16(substr($tag[2], 0, 2), $le) : self::u32($tag[2], $le);
    }

    /**
     * Alle Werte eines LONG-/IFD-Eintrags (SubIFDs: Wert direkt im Eintrag oder ab einer Position).
     *
     * @param resource $fh
     * @param array{0: int, 1: int, 2: string} $tag
     * @return list<int>
     */
    private static function longs($fh, array $tag, bool $le): array
    {
        $n = min($tag[1], self::MAX_IFDS);
        if ($n <= 1) {
            return $n === 1 ? [self::u32($tag[2], $le)] : [];
        }
        fseek($fh, self::u32($tag[2], $le));
        $data = (string) fread($fh, $n * 4);
        $out = [];
        for ($i = 0; $i + 4 <= strlen($data); $i += 4) {
            $out[] = self::u32(substr($data, $i, 4), $le);
        }
        return $out;
    }

    /** Setzt vor alle anderen Segmente ein EXIF (APP1) mit nur der Ausrichtung. */
    private static function withOrientation(string $jpeg, int $orientation): string
    {
        $tiff = "MM\x00\x2A" . pack('N', 8) . pack('n', 1) . pack('nnN', 0x0112, 3, 1) . pack('nn', $orientation, 0) . pack('N', 0);
        $payload = "Exif\x00\x00" . $tiff;
        return "\xFF\xD8\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload . substr($jpeg, 2);
    }

    private static function u16(string $b, bool $le): int
    {
        return strlen($b) < 2 ? 0 : (int) unpack($le ? 'v' : 'n', $b)[1];
    }

    private static function u32(string $b, bool $le): int
    {
        return strlen($b) < 4 ? 0 : (int) unpack($le ? 'V' : 'N', $b)[1];
    }
}
