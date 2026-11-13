<?php

declare(strict_types=1);

namespace KompanieApp\Standalone;

use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Port\WebsiteHost;

/**
 * Entry file of the website (Issue #273): index.php in the folder above the program (the program usually lives in
 * /app/, the website then at /). It only defines KOMPANIE_WEBSITE and includes the program's index.php, so it never
 * has to change with an update. The App creates it only where there is no start page yet; another index.php,
 * index.html … (e.g. an older website) is never replaced. Its own entry file it recognises by MARKER.
 */
final class StandaloneWebsiteHost implements WebsiteHost
{
    public const MARKER = 'kompanie-app-website';
    /** Other start pages a web server may serve for the folder. */
    private const OTHER_INDEX = ['index.html', 'index.htm', 'index.shtml', 'index.cgi', 'index.pl', 'default.htm', 'default.html'];

    public function __construct(
        private readonly string $root,
        private readonly StandaloneEnvironment $env,
    ) {
    }

    public function entryStatus(): array
    {
        $folder = basename($this->root);
        $content = self::content($folder);
        $url = $this->websiteUrl();
        $status = static fn (string $state, ?string $file = null) => ['state' => $state, 'url' => $url, 'file' => $file, 'folder' => $folder, 'content' => $content];
        if ($url === null) {
            return $status('root');
        }
        $dir = dirname($this->root);
        $index = $dir . '/index.php';
        if (is_file($index)) {
            $current = (string) @file_get_contents($index);
            if (!str_contains($current, self::MARKER)) {
                return $status('occupied', 'index.php');
            }
            // Ours, but for another folder name (program folder renamed): may be written again.
            if ($current !== $content) {
                return is_writable($index) ? $status('outdated') : $status('not_writable');
            }
            return $status('installed');
        }
        foreach (self::OTHER_INDEX as $name) {
            if (file_exists($dir . '/' . $name)) {
                return $status('occupied', $name);
            }
        }
        return is_writable($dir) ? $status('missing') : $status('not_writable');
    }

    public function installEntry(): void
    {
        $status = $this->entryStatus();
        if (!in_array($status['state'], ['missing', 'outdated'], true)) {
            throw new ApiError(409, 'conflict', match ($status['state']) {
                'installed' => 'Die Einstiegsdatei ist schon da.',
                'occupied' => 'Dort liegt schon eine andere Startseite (' . $status['file'] . '). Die App ersetzt sie nicht.',
                'not_writable' => 'PHP darf dort keine Dateien anlegen. Bitte die Einstiegsdatei von Hand hochladen.',
                default => 'Für die Webseite muss die App in einem eigenen Ordner liegen.',
            });
        }
        $dir = dirname($this->root);
        $tmp = $dir . '/.index.php.' . bin2hex(random_bytes(4));
        // Written next to it and renamed: the web server never serves a half-written file.
        if (@file_put_contents($tmp, $status['content']) === false || !@rename($tmp, $dir . '/index.php')) {
            @unlink($tmp);
            throw new ApiError(409, 'conflict', 'Die Einstiegsdatei konnte nicht angelegt werden. Bitte sie von Hand als index.php hochladen.');
        }
        @chmod($dir . '/index.php', 0644);
    }

    public function stylesheetUrl(string $version): string
    {
        $url = $this->env->route('webseite.css');
        return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . rawurlencode($version);
    }

    public function iconUrl(string $file): string
    {
        return $this->env->appUrl() . 'app/icons/' . rawurlencode($file);
    }

    /** Content of the entry file for the program folder $folder. */
    public static function content(string $folder): string
    {
        return "<?php\n"
            . '// ' . self::MARKER . ": Einstieg der Webseite, angelegt von der Kompanie-App.\n"
            . "// Bindet nur die App im Ordner „" . $folder . "“ ein und ändert sich mit Updates nicht.\n"
            . "define('KOMPANIE_WEBSITE', true);\n"
            . 'require __DIR__ . ' . var_export('/' . $folder . '/index.php', true) . ";\n";
    }

    /** Address of the folder above the program, e.g. https://example.org/ for https://example.org/app/; null at the top. */
    private function websiteUrl(): ?string
    {
        $app = $this->env->appUrl();
        $parts = parse_url($app);
        $path = is_array($parts) ? (string) ($parts['path'] ?? '/') : '/';
        $path = rtrim($path, '/');
        if ($path === '' || !is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $parent = rtrim(str_replace('\\', '/', dirname($path)), '/') . '/';
        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . $parent;
    }
}
