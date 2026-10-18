<?php

declare(strict_types=1);

namespace KompanieApp\Core\Infra;

use KompanieApp\Core\Port\FileStorage;

/**
 * Dateiablage in zwei Verzeichnissen: eines, das der Webserver ausliefert,
 * und eines, das von außen nicht erreichbar sein darf.
 */
final class LocalFileStorage implements FileStorage
{
    public function __construct(
        private readonly string $publicDir,
        private readonly string $publicUrl,
        private readonly string $privateDir,
        private readonly string $tempDir = '',
    ) {
    }

    public function path(string $relative, bool $public): string
    {
        $relative = ltrim(str_replace(['\\', '..'], ['/', ''], $relative), '/');
        $base = rtrim($public ? $this->publicDir : $this->privateDir, '/');
        $path = $base . '/' . $relative;
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Verzeichnis kann nicht angelegt werden: ' . $dir);
        }
        $this->protect($base, $public);
        return $path;
    }

    public function publicUrl(string $relative): string
    {
        return rtrim($this->publicUrl, '/') . '/' . ltrim($relative, '/');
    }

    public function delete(string $relative, bool $public): void
    {
        $relative = ltrim(str_replace(['\\', '..'], ['/', ''], $relative), '/');
        $file = rtrim($public ? $this->publicDir : $this->privateDir, '/') . '/' . $relative;
        if (is_file($file)) {
            @unlink($file);
        }
    }

    public function tempFile(): string
    {
        $dir = $this->tempDir !== '' ? $this->tempDir : sys_get_temp_dir();
        $file = tempnam($dir, 'kompanie-');
        if ($file === false) {
            throw new \RuntimeException('Es kann keine temporäre Datei angelegt werden.');
        }
        return $file;
    }

    /** Verzeichnisauflistung verhindern; das geschützte Verzeichnis zusätzlich sperren (Apache). */
    private function protect(string $base, bool $public): void
    {
        if (!is_file($base . '/index.php')) {
            @file_put_contents($base . '/index.php', "<?php // Kein Zugriff.\n");
        }
        if (!$public && !is_file($base . '/.htaccess')) {
            @file_put_contents(
                $base . '/.htaccess',
                "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
            );
        }
    }
}
