<?php

declare(strict_types=1);

namespace KompanieApp\Standalone;

use KompanieApp\Core\Storage\Sql\PdoConnection;

/**
 * Einstellungen der Installation aus data/config.php (schreibt der Installer).
 * Enthält Zugangsdaten und den geheimen Schlüssel; liegt deshalb im geschützten Datenverzeichnis.
 */
final class Config
{
    public const FILE = 'config.php';

    /** @param array<string, mixed> $data */
    public function __construct(public readonly string $dataDir, private readonly array $data)
    {
    }

    public static function exists(string $dataDir): bool
    {
        return is_file($dataDir . '/' . self::FILE);
    }

    public static function load(string $dataDir): self
    {
        $data = require $dataDir . '/' . self::FILE;
        if (!is_array($data)) {
            throw new \RuntimeException('Die Datei config.php ist beschädigt.');
        }
        return new self($dataDir, $data);
    }

    /**
     * Schreibt config.php atomar (erst temporäre Datei, dann umbenennen).
     *
     * @param array<string, mixed> $data
     */
    public static function write(string $dataDir, array $data): self
    {
        $php = "<?php\n// Von der Kompanie-App angelegt. Enthält Zugangsdaten – nicht weitergeben.\nreturn " . var_export($data, true) . ";\n";
        $file = $dataDir . '/' . self::FILE;
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $php, LOCK_EX) === false || !rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException('config.php kann nicht geschrieben werden. Hat PHP Schreibrechte im Datenverzeichnis?');
        }
        @chmod($file, 0640);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
        return new self($dataDir, $data);
    }

    /** Neue Grundeinstellungen mit frischem geheimem Schlüssel. */
    public static function defaults(array $db): array
    {
        return [
            'db' => $db,
            'secret' => bin2hex(random_bytes(32)),
            'url_mode' => 'query',
            'base_url' => '',
            'timezone' => 'Europe/Berlin',
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    public function secret(): string
    {
        $s = (string) ($this->data['secret'] ?? '');
        if (strlen($s) < 32) {
            throw new \RuntimeException('In config.php fehlt der geheime Schlüssel (secret).');
        }
        return $s;
    }

    /** 'rewrite' (schöne Adressen per .htaccess) oder 'query' (index.php?r=…, läuft überall). */
    public function urlMode(): string
    {
        return ($this->data['url_mode'] ?? '') === 'rewrite' ? 'rewrite' : 'query';
    }

    /** Fest eingetragene Adresse der Installation, z. B. https://example.org/app/ (leer = aus der Anfrage). */
    public function baseUrl(): string
    {
        $url = trim((string) ($this->data['base_url'] ?? ''));
        return $url === '' ? '' : rtrim($url, '/') . '/';
    }

    /** HSTS-Header über HTTPS senden (Vorgabe); 'hsts' => false in config.php schaltet ihn ab. */
    public function hsts(): bool
    {
        return ($this->data['hsts'] ?? true) !== false;
    }

    public function timezone(): string
    {
        $tz = (string) ($this->data['timezone'] ?? 'Europe/Berlin');
        return in_array($tz, \DateTimeZone::listIdentifiers(), true) ? $tz : 'Europe/Berlin';
    }

    public function connect(): PdoConnection
    {
        $db = $this->data['db'] ?? [];
        $driver = $db['driver'] ?? 'sqlite';
        if ($driver === 'mysql') {
            if (!preg_match('/^[A-Za-z0-9_]{0,32}$/', (string) ($db['prefix'] ?? 'kompanie_'))) {
                throw new \RuntimeException('Das Tabellen-Präfix darf nur Buchstaben, Ziffern und _ enthalten.');
            }
            return PdoConnection::mysql(
                (string) ($db['host'] ?? 'localhost'),
                (string) ($db['name'] ?? ''),
                (string) ($db['user'] ?? ''),
                (string) ($db['password'] ?? ''),
                (string) ($db['prefix'] ?? 'kompanie_'),
                (int) ($db['port'] ?? 3306),
            );
        }
        $file = (string) ($db['path'] ?? 'kompanie.sqlite');
        if (!str_starts_with($file, '/') && !preg_match('#^[A-Za-z]:[\\\\/]#', $file)) {
            $file = $this->dataDir . '/' . $file;
        }
        return PdoConnection::sqlite($file);
    }
}
