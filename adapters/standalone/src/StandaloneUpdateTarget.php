<?php

declare(strict_types=1);

namespace KompanieApp\Standalone;

use KompanieApp\Core\Port\UpdateTarget;

/**
 * Spielt ein Paket der eigenständigen Version ein, indem die Programmdateien getauscht werden.
 * Daten (data/), Galeriebilder (media/) und data-dir.php bleiben unberührt.
 *
 * Ablauf: Paket in einen Arbeitsordner im Programmverzeichnis entpacken (dasselbe Dateisystem,
 * auch wenn data/ woanders liegt) und prüfen → jede Programmdatei bzw. jeden Ordner erst
 * beiseite legen, dann den neuen an seine Stelle verschieben (Umbenennen ist auf demselben
 * Dateisystem atomar) → bei einem Fehler alles zurück. Die Datenbank wird beim nächsten Aufruf
 * migriert (nur additiv, ältere Versionen laufen weiter).
 */
final class StandaloneUpdateTarget implements UpdateTarget
{
    /** Was zum Programm gehört und getauscht wird. */
    public const PROGRAM = ['core', 'src', 'app', 'bin', 'index.php', 'autoload.php', '.htaccess', 'sw.js', 'kompanie.json', 'update.json', 'VERSION', 'README.md'];

    public function __construct(private readonly string $root, private readonly string $dataDir)
    {
    }

    public function variant(): string
    {
        return 'standalone';
    }

    public function autoUpdateAllowed(): bool
    {
        return true;
    }

    public function problems(array $entry): array
    {
        $problems = [];
        foreach (array_merge([$this->root], array_map(fn (string $p): string => $this->root . '/' . $p, ['core', 'src', 'app'])) as $path) {
            if (file_exists($path) && !is_writable($path)) {
                $problems[] = 'PHP darf die Programmdateien nicht ändern (' . basename($path) . '). Beim Hoster die Dateirechte prüfen oder install.php erneut hochladen und „Reparieren“ wählen.';
                break;
            }
        }
        $free = @disk_free_space($this->dataDir);
        if (is_float($free) && $free < 3 * (int) ($entry['files']['standalone']['size'] ?? 0) + 20 * 1048576) {
            $problems[] = 'Auf dem Webspace ist zu wenig Platz für das Update.';
        }
        return $problems;
    }

    public function install(string $zipFile, string $version): void
    {
        $work = $this->root . '/.neu-' . bin2hex(random_bytes(6));
        $aside = $this->root . '/.alt-' . bin2hex(random_bytes(6));
        try {
            self::extract($zipFile, $work);
            $new = $work . '/kompanie-app';
            if (!is_file($new . '/index.php') || trim((string) @file_get_contents($new . '/VERSION')) !== $version) {
                throw new \RuntimeException('Das Paket ist unvollständig oder hat eine andere Version.');
            }
            self::swap($this->root, $new, $aside, self::PROGRAM);
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
        } finally {
            self::remove($work);
            self::remove($aside);
        }
    }

    /**
     * Tauscht die Einträge $names in $root gegen die aus $new. Bei einem Fehler wird der
     * vorige Stand wiederhergestellt.
     *
     * @param string[] $names
     */
    public static function swap(string $root, string $new, string $aside, array $names): void
    {
        if (!@mkdir($aside, 0755) && !is_dir($aside)) {
            throw new \RuntimeException('PHP darf im Programmverzeichnis nichts anlegen (Dateirechte).');
        }
        $moved = [];
        $placed = [];
        try {
            foreach ($names as $name) {
                if (!file_exists($new . '/' . $name)) {
                    continue; // im neuen Paket nicht enthalten: alte Datei bleibt
                }
                if (file_exists($root . '/' . $name)) {
                    if (!@rename($root . '/' . $name, $aside . '/' . $name)) {
                        throw new \RuntimeException("Die Datei bzw. der Ordner „{$name}“ lässt sich nicht ersetzen (Dateirechte).");
                    }
                    $moved[] = $name;
                }
                if (!@rename($new . '/' . $name, $root . '/' . $name)) {
                    throw new \RuntimeException("„{$name}“ lässt sich nicht einspielen (Dateirechte oder Speicherplatz).");
                }
                $placed[] = $name;
            }
        } catch (\RuntimeException $e) {
            foreach (array_reverse($placed) as $name) {
                self::remove($root . '/' . $name);
            }
            foreach (array_reverse($moved) as $name) {
                @rename($aside . '/' . $name, $root . '/' . $name);
            }
            throw $e;
        }
    }

    /** Entpackt ein ZIP sicher (keine Pfade außerhalb des Ziels, keine absoluten Pfade). */
    public static function extract(string $zipFile, string $target): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('PHP kann keine ZIP-Dateien entpacken (Erweiterung zip fehlt).');
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new \RuntimeException('Das Paket lässt sich nicht öffnen.');
        }
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if ($name === '' || str_starts_with($name, '/') || str_contains($name, '\\') || preg_match('#(^|/)\.\.(/|$)#', $name)) {
                    throw new \RuntimeException('Das Paket enthält ungültige Pfade.');
                }
            }
            if (!@mkdir($target, 0755, true) && !is_dir($target)) {
                throw new \RuntimeException('Arbeitsordner für das Update kann nicht angelegt werden.');
            }
            if (!$zip->extractTo($target)) {
                throw new \RuntimeException('Das Paket lässt sich nicht entpacken (Speicherplatz?).');
            }
        } finally {
            $zip->close();
        }
    }

    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
