<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\Domain\JpegMetadata;
use KompanieApp\Core\Infra\GdImageResizer;
use PHPUnit\Framework\TestCase;

/** Metadaten (EXIF mit GPS, XMP, Kommentare) verschwinden aus gespeicherten JPEGs, Farbprofil und Bild bleiben. */
final class JpegMetadataTest extends TestCase
{
    private static function segment(int $marker, string $payload): string
    {
        return "\xFF" . chr($marker) . pack('n', strlen($payload) + 2) . $payload;
    }

    /** JPEG von GD mit eingefügtem EXIF (GPS), XMP, Kommentar, Farbprofil und Adobe-Segment hinter SOI. */
    public static function jpegWithMetadata(int $w = 40, int $h = 30): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, (int) imagecolorallocate($im, 200, 40, 40));
        ob_start();
        imagejpeg($im, null, 90);
        $jpeg = (string) ob_get_clean();
        $meta = self::segment(0xE1, "Exif\0\0GPSLatitude 51.2954 GPSLongitude 6.6880")
            . self::segment(0xE1, "http://ns.adobe.com/xap/1.0/\0<x:xmpmeta>Kamera-Seriennummer</x:xmpmeta>")
            . self::segment(0xFE, 'Kommentar mit Namen')
            . self::segment(0xE2, "ICC_PROFILE\0\x01\x01profil")
            . self::segment(0xEE, "Adobe\0\x64\0\0\0\0\x01");
        return substr($jpeg, 0, 2) . $meta . substr($jpeg, 2);
    }

    public function testStripsPersonalMetadataAndKeepsImage(): void
    {
        $jpeg = self::jpegWithMetadata();
        $this->assertStringContainsString('GPSLatitude', $jpeg);
        $clean = JpegMetadata::strip($jpeg);
        foreach (['Exif', 'GPSLatitude', 'xmpmeta', 'Kommentar mit Namen'] as $gone) {
            $this->assertStringNotContainsString($gone, $clean, $gone);
        }
        $this->assertStringContainsString("ICC_PROFILE\0", $clean, 'Farbprofil bleibt');
        $this->assertStringContainsString('Adobe', $clean, 'Adobe-Segment (Farbumrechnung) bleibt');
        $this->assertSame([40, 30], array_slice((array) getimagesizefromstring($clean), 0, 2));
        $this->assertNotFalse(@imagecreatefromstring($clean), 'Bild bleibt lesbar');
        $this->assertSame($clean, JpegMetadata::strip($clean), 'zweimal ändert nichts');
    }

    public function testLeavesOtherDataUnchanged(): void
    {
        $this->assertSame('kein jpeg', JpegMetadata::strip('kein jpeg'));
        $broken = "\xFF\xD8\xFF\xE1\x00\x50Exif"; // Länge zeigt über das Ende hinaus
        $this->assertSame($broken, JpegMetadata::strip($broken));
    }

    public function testResizerWritesNoMetadataEvenWithoutResizing(): void
    {
        $dir = sys_get_temp_dir() . '/kompanie-jpeg-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            file_put_contents($dir . '/in.jpg', self::jpegWithMetadata());
            $size = (new GdImageResizer())->resizeToJpeg($dir . '/in.jpg', $dir . '/out.jpg', 8192, 90);
            $this->assertSame(['width' => 40, 'height' => 30], $size, 'nie vergrößert');
            $this->assertStringNotContainsString('GPSLatitude', (string) file_get_contents($dir . '/out.jpg'));
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }
}
