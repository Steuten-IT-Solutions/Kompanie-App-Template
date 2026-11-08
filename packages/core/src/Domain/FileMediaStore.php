<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\Port\FileStorage;
use KompanieApp\Core\Port\MediaStore;

/**
 * Originale der Galerie auf dem eigenen Server (Issue #284): im geschützten Bereich der Dateiablage, flach in einem
 * Ordner (DIR), Schlüssel „<DIR>/<Album>-<Zufall>.<Endung>“. Ausgeliefert wird nur über die App. Das Standbild eines
 * Videos (Issue #350) liegt daneben als „<DIR>/<Album>-<Zufall>.poster.jpg“; keys() und bytes() zählen nur die Dateien.
 */
final class FileMediaStore implements MediaStore
{
    public const DIR = 'einreichungen';
    public const EXTENSIONS = ['jpg', 'png', 'webp', 'mp4', 'webm'];
    private const KEY = '#^' . self::DIR . '/(\d+)-([a-f0-9]{24})\.([a-z0-9]{2,4})$#';

    public function __construct(private readonly FileStorage $files)
    {
    }

    public function put(int $albumId, string $source, string $extension): string
    {
        $key = $this->newKey($albumId, $source, $extension);
        if (!@copy($source, $this->files->path($key, false))) {
            throw new \RuntimeException('Die Datei kann nicht abgelegt werden.');
        }
        return $key;
    }

    public function adopt(int $albumId, string $source, string $extension): string
    {
        $key = $this->newKey($albumId, $source, $extension);
        $target = $this->files->path($key, false);
        // rename geht nicht über Dateisysteme hinweg: dann kopieren und die Quelle löschen.
        if (!@rename($source, $target)) {
            if (!@copy($source, $target)) {
                @unlink($target);
                throw new \RuntimeException('Die Datei kann nicht abgelegt werden.');
            }
            @unlink($source);
        }
        return $key;
    }

    public function putPoster(string $key, string $source): void
    {
        $poster = self::posterKey($key) ?? throw new \InvalidArgumentException('Ungültiger Schlüssel.');
        if (!@copy($source, $this->files->path($poster, false))) {
            throw new \RuntimeException('Das Standbild kann nicht abgelegt werden.');
        }
    }

    public function posterPath(string $key): ?string
    {
        $poster = self::posterKey($key);
        if ($poster === null) {
            return null;
        }
        $path = $this->files->path($poster, false);
        return is_file($path) ? $path : null;
    }

    private function newKey(int $albumId, string $source, string $extension): string
    {
        $extension = strtolower($extension);
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;
        if ($albumId < 1 || !in_array($extension, self::EXTENSIONS, true)) {
            throw new \InvalidArgumentException('Ungültiges Album oder Dateiformat.');
        }
        if (!is_file($source)) {
            throw new \RuntimeException('Die Datei ist nicht da.');
        }
        return sprintf('%s/%d-%s.%s', self::DIR, $albumId, bin2hex(random_bytes(12)), $extension);
    }

    /** Schlüssel des Standbilds zu $key; null bei ungültigem Schlüssel. */
    private static function posterKey(string $key): ?string
    {
        return preg_match(self::KEY, $key, $m) === 1 ? sprintf('%s/%d-%s.poster.jpg', self::DIR, $m[1], $m[2]) : null;
    }

    public function path(string $key): ?string
    {
        if (preg_match(self::KEY, $key) !== 1) {
            return null;
        }
        $path = $this->files->path($key, false);
        return is_file($path) ? $path : null;
    }

    public function delete(string $key): void
    {
        if (preg_match(self::KEY, $key) === 1) {
            $this->files->delete($key, false);
            $this->files->delete((string) self::posterKey($key), false);
        }
    }

    public function move(string $key, int $albumId): string
    {
        if (preg_match(self::KEY, $key, $m) !== 1 || $albumId < 1) {
            throw new \InvalidArgumentException('Ungültiger Schlüssel oder ungültiges Album.');
        }
        if ((int) $m[1] === $albumId) {
            return $key;
        }
        $from = $this->path($key) ?? throw new \RuntimeException('Die Datei ist nicht da.');
        $new = sprintf('%s/%d-%s.%s', self::DIR, $albumId, $m[2], $m[3]);
        $poster = $this->posterPath($key);
        if (!@rename($from, $this->files->path($new, false))) {
            throw new \RuntimeException('Die Datei kann nicht verschoben werden.');
        }
        if ($poster !== null) {
            @rename($poster, $this->files->path((string) self::posterKey($new), false));
        }
        return $new;
    }

    public function keys(): array
    {
        $dir = dirname($this->files->path(self::DIR . '/x', false));
        $out = [];
        foreach (glob($dir . '/*') ?: [] as $file) {
            $key = self::DIR . '/' . basename($file);
            if (is_file($file) && preg_match(self::KEY, $key) === 1) {
                $out[$key] = (int) filemtime($file);
            }
        }
        return $out;
    }

    public function bytes(): int
    {
        $dir = dirname($this->files->path(self::DIR . '/x', false));
        $sum = 0;
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file) && preg_match(self::KEY, self::DIR . '/' . basename($file)) === 1) {
                $sum += (int) filesize($file);
            }
        }
        return $sum;
    }
}
