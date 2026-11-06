<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Http\ApiError;

/**
 * Logos in der Datenbank (Sponsoren, Issue #279; Kompanien, Issue #280): hochladen und ausliefern, an einer Stelle.
 *
 * - PNG, JPG, WebP oder SVG, höchstens 2 MB. Rasterbilder werden zu PNG mit höchstens 512 Pixeln an der längeren Kante
 *   (mit Transparenz, nie vergrößert), SVGs bereinigt (SvgSanitizer) und mit strenger CSP ausgeliefert.
 * - Jede neue Fassung bekommt eine eigene Kennung (version) für die Adresse; so dürfen Zwischenspeicher lange behalten.
 */
final class LogoImage
{
    public const MAX_BYTES = 2 * 1024 * 1024;
    /** Längere Kante der PNG-Fassung: scharf auch in Kacheln auf Handys mit hoher Pixeldichte. */
    public const EDGE = 512;
    /** Größte Kantenlänge eines hochgeladenen Rasterbilds (schützt den Arbeitsspeicher beim Verkleinern). */
    public const MAX_EDGE = 4096;
    /** Endung => Signatur; bei WebP steht „WEBP“ zusätzlich ab Byte 8, SVG wird als XML geprüft. */
    public const TYPES = ['png' => "\x89PNG\r\n\x1A\n", 'jpg' => "\xFF\xD8\xFF", 'jpeg' => "\xFF\xD8\xFF", 'webp' => 'RIFF', 'svg' => ''];

    /**
     * Hochgeladene Datei prüfen und umwandeln.
     *
     * @param mixed $file {name, data (Base64, auch als data:-Adresse)}
     * @return array{type: 'png'|'svg', logo: string, version: string} logo: PNG-Bytes bzw. SVG-Text
     */
    public static function fromUpload(App $app, mixed $file): array
    {
        $name = is_array($file) && is_string($file['name'] ?? null) ? $file['name'] : '';
        $bytes = self::decode(is_array($file) ? ($file['data'] ?? null) : null);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$ext])) {
            throw ApiError::invalid('Erlaubt sind PNG, JPG, WebP und SVG.');
        }
        if ($ext === 'svg') {
            try {
                $logo = SvgSanitizer::sanitize($bytes);
            } catch (\InvalidArgumentException $e) {
                throw ApiError::invalid($e->getMessage());
            }
            $type = 'svg';
        } else {
            self::checkRaster($bytes, $ext);
            $logo = self::toPng($app, $bytes, $ext);
            $type = 'png';
        }
        return ['type' => $type, 'logo' => $logo, 'version' => substr(hash('sha256', $logo . random_bytes(8)), 0, 16)];
    }

    /**
     * Header für die Auslieferung eines vorhandenen Logos. $public: sehen es auch Besucher ohne Anmeldung (dann darf
     * jeder Zwischenspeicher es behalten); $current: passt die Fassung in der Adresse (dann lange speichern, sonst jedes
     * Mal neu prüfen lassen). In der Vorschau nie speichern.
     *
     * @return array<string, string>
     */
    public static function headers(App $app, string $type, string $version, bool $public, mixed $requested): array
    {
        $current = is_string($requested) && $version !== '' && hash_equals($version, $requested);
        $svg = $type === 'svg';
        $headers = [
            'Content-Type' => $svg ? 'image/svg+xml' : 'image/png',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => $app->preview()->cacheControl(($public ? 'public' : 'private') . ($current ? ', max-age=31536000, immutable' : ', no-cache')),
            'X-Robots-Tag' => 'noindex',
            'ETag' => '"' . $version . '"',
        ];
        if ($svg) {
            $headers['Content-Security-Policy'] = RankService::SVG_CSP;
        }
        return $headers;
    }

    /** @return array{status: int, headers: array<string, string>} */
    public static function deny(int $status): array
    {
        return ['status' => $status, 'headers' => ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']];
    }

    /** Für die Datenbank: PNG als Base64, SVG als Text; '' entfernt. */
    public static function encode(string $type, string $logo): ?string
    {
        return $type === '' ? null : ($type === 'png' ? base64_encode($logo) : $logo);
    }

    /** Aus der Datenbank: PNG-Bytes bzw. SVG-Text, oder null ohne Logo. */
    public static function decodeStored(mixed $type, mixed $stored): ?string
    {
        if (!is_string($stored) || $stored === '') {
            return null;
        }
        if ($type === 'png') {
            $bytes = base64_decode($stored, true);
            return $bytes === false || $bytes === '' ? null : $bytes;
        }
        return $type === 'svg' ? $stored : null;
    }

    /** Base64 (auch als data:-Adresse) dekodieren und die Größe prüfen. */
    private static function decode(mixed $data): string
    {
        $data = is_string($data) ? $data : '';
        if (str_starts_with($data, 'data:') && ($comma = strpos($data, ',')) !== false) {
            $data = substr($data, $comma + 1);
        }
        // Vor dem Dekodieren: Base64 ist ein Drittel größer als die Datei.
        if (strlen($data) > intdiv(self::MAX_BYTES * 4, 3) + 4) {
            throw ApiError::invalid('Das Logo darf höchstens 2 MB groß sein.');
        }
        $bytes = $data === '' ? false : base64_decode($data, true);
        if ($bytes === false || $bytes === '') {
            throw ApiError::invalid('Es ist keine Datei angekommen.');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw ApiError::invalid('Das Logo darf höchstens 2 MB groß sein.');
        }
        return $bytes;
    }

    /** Rasterbild: Signatur passend zur Endung und eine vernünftige Größe in Pixeln. */
    private static function checkRaster(string $bytes, string $ext): void
    {
        $signature = self::TYPES[$ext];
        $ok = strncmp($bytes, $signature, strlen($signature)) === 0 && ($ext !== 'webp' || substr($bytes, 8, 4) === 'WEBP');
        $info = $ok ? @getimagesizefromstring($bytes) : false;
        if ($info === false) {
            throw ApiError::invalid(sprintf('Die Datei ist keine gültige .%s-Datei.', $ext));
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] > self::MAX_EDGE || $info[1] > self::MAX_EDGE) {
            throw ApiError::invalid(sprintf('Das Bild darf höchstens %d × %d Pixel groß sein.', self::MAX_EDGE, self::MAX_EDGE));
        }
    }

    /** PNG-Fassung mit höchstens EDGE Pixeln (mit Transparenz, nie vergrößert). */
    private static function toPng(App $app, string $bytes, string $ext): string
    {
        $tmp = $app->files->tempFile();
        // Mit Endung: Der Bildeditor von WordPress erkennt das Format daran.
        $source = $tmp . '.' . $ext;
        $target = $tmp . '-logo.png';
        try {
            if (@file_put_contents($source, $bytes) === false) {
                throw new ApiError(500, 'storage_failed', 'Das Logo konnte nicht gespeichert werden.');
            }
            try {
                $app->images->resizeToPng($source, $target, self::EDGE);
            } catch (\RuntimeException $e) {
                throw ApiError::invalid('Das Bild kann nicht verarbeitet werden: ' . $e->getMessage());
            }
            $png = @file_get_contents($target);
            if ($png === false || $png === '') {
                throw new ApiError(500, 'storage_failed', 'Das Logo konnte nicht gespeichert werden.');
            }
            return $png;
        } finally {
            @unlink($source);
            @unlink($target);
            @unlink($tmp);
        }
    }
}
