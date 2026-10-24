<?php

declare(strict_types=1);

namespace KompanieApp\Core\Infra;

use KompanieApp\Core\Domain\JpegMetadata;
use KompanieApp\Core\Port\ImageResizer;

/** Bildverkleinerung mit der PHP-Erweiterung GD. */
final class GdImageResizer implements ImageResizer
{
    public function resizeToJpeg(string $sourceFile, string $targetFile, int $maxEdge, int $quality): array
    {
        if (!function_exists('imagecreatefromstring')) {
            throw new \RuntimeException('Auf dem Server fehlt die PHP-Erweiterung GD für Bilder.');
        }
        $data = @file_get_contents($sourceFile);
        $src = $data === false ? false : @imagecreatefromstring($data);
        if ($src === false) {
            throw new \RuntimeException('Das Bild kann nicht gelesen werden.');
        }
        $src = $this->orient($src, $sourceFile);
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1.0, $maxEdge / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        if ($scale >= 1.0 && str_starts_with($data, "\xFF\xD8")) {
            // Originalauflösung aus einem JPEG (keine Transparenz): ohne zweite Kopie im Speicher (bei 4096 Pixeln rund 50 MB).
            $dst = $src;
        } else {
            $dst = imagecreatetruecolor($nw, $nh);
            // Transparente Flächen (PNG) weiß statt schwarz.
            imagefill($dst, 0, 0, (int) imagecolorallocate($dst, 255, 255, 255));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($src);
        }
        unset($data);
        imageinterlace($dst, true);
        $ok = imagejpeg($dst, $targetFile, $quality);
        imagedestroy($dst);
        if (!$ok) {
            throw new \RuntimeException('Das Bild kann nicht gespeichert werden.');
        }
        JpegMetadata::stripFile($targetFile); // GD schreibt keine Metadaten; zur Sicherheit wie im WordPress-Adapter
        return ['width' => $nw, 'height' => $nh];
    }

    public function resizeToPng(string $sourceFile, string $targetFile, int $maxEdge): array
    {
        if (!function_exists('imagecreatefromstring')) {
            throw new \RuntimeException('Auf dem Server fehlt die PHP-Erweiterung GD für Bilder.');
        }
        $data = @file_get_contents($sourceFile);
        $src = $data === false ? false : @imagecreatefromstring($data);
        if ($src === false) {
            throw new \RuntimeException('Das Bild kann nicht gelesen werden.');
        }
        // Palettenbilder (PNG-8) erst in Echtfarben umwandeln, sonst geht beim Verkleinern die Transparenz verloren.
        if (!imageistruecolor($src)) {
            imagepalettetotruecolor($src);
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1.0, $maxEdge / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        // Alphakanal behalten: nicht mischen, mit voller Transparenz füllen, Alpha mitspeichern.
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, (int) imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $ok = imagepng($dst, $targetFile, 9);
        imagedestroy($src);
        imagedestroy($dst);
        if (!$ok) {
            throw new \RuntimeException('Das Bild kann nicht gespeichert werden.');
        }
        return ['width' => $nw, 'height' => $nh];
    }

    /** Dreht nach der EXIF-Ausrichtung von Handyfotos. */
    private function orient(\GdImage $img, string $file): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data($file);
        $angle = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        if ($angle === 0) {
            return $img;
        }
        $rotated = imagerotate($img, $angle, 0);
        return $rotated === false ? $img : $rotated;
    }
}
