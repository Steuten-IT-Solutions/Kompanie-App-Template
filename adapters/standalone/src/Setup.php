<?php

declare(strict_types=1);

namespace KompanieApp\Standalone;

use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\Passwords;
use KompanieApp\Core\Domain\Text;

/**
 * Einrichtung der eigenständigen Version: Datenverzeichnis schützen, config.php schreiben,
 * Tabellen anlegen, ersten Admin anlegen. Genutzt von bin/kompanie.php und vom Installer.
 */
final class Setup
{
    /**
     * @param array<string, mixed> $db ['driver' => 'sqlite'] oder ['driver' => 'mysql', 'host', 'port', 'name', 'user', 'password', 'prefix']
     */
    public static function install(Kernel $kernel, array $db, string $baseUrl, string $urlMode = 'query'): void
    {
        // Die Adresse wird fest eingetragen: Sie darf nie aus dem Host-Header einer Anfrage kommen.
        $baseUrl = self::normalizeUrl($baseUrl);
        $data = $kernel->dataDir();
        if (Config::exists($data)) {
            throw new \RuntimeException('Die App ist hier schon eingerichtet (config.php vorhanden).');
        }
        self::prepareDataDir($data);
        if (($db['driver'] ?? 'sqlite') === 'sqlite') {
            $version = extension_loaded('pdo_sqlite') ? (string) (new \PDO('sqlite::memory:'))->query('SELECT sqlite_version()')->fetchColumn() : '';
            if ($version !== '' && version_compare($version, '3.24.0', '<')) {
                throw new \RuntimeException("Die SQLite-Version des Servers ($version) ist zu alt (mindestens 3.24). Bitte eine MySQL-Datenbank nutzen oder beim Hoster nachfragen.");
            }
            if (!extension_loaded('pdo_sqlite')) {
                throw new \RuntimeException('PHP kann keine SQLite-Datenbank anlegen (Erweiterung pdo_sqlite fehlt). Sie lässt sich meist im Kundenmenü des Hosters einschalten; alternativ eine MySQL-Datenbank nutzen.');
            }
            // Zufälliger Name: Selbst wenn der Webserver das Datenverzeichnis nicht sperrt
            // (nginx ohne passende Regel), ist die Datei nicht zu erraten.
            $db = ['driver' => 'sqlite', 'path' => 'kompanie-' . bin2hex(random_bytes(16)) . '.sqlite'];
        } elseif (!extension_loaded('pdo_mysql')) {
            throw new \RuntimeException('PHP kann keine MySQL-Datenbank ansprechen (Erweiterung pdo_mysql fehlt).');
        }
        $settings = Config::defaults($db);
        $settings['base_url'] = $baseUrl;
        $settings['url_mode'] = $urlMode === 'rewrite' ? 'rewrite' : 'query';
        // Erst die Verbindung prüfen, dann config.php schreiben: Ein Tippfehler im Passwort soll
        // keine halbe Installation hinterlassen.
        (new Config($data, $settings))->connect();
        Config::write($data, $settings);
        $kernel->upgrade();
        if ($settings['db']['driver'] === 'sqlite') {
            @chmod($data . '/' . $settings['db']['path'], 0640); // nur für den PHP-Benutzer
        }
    }

    /**
     * Prüft die Adresse der App und schreibt Umlaut-Domains so, wie Browser sie senden (Punycode).
     */
    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if (!preg_match('~^(https?)://([^/\s?#:]+)(:\d+)?(/[^\s?#]*)?$~i', $url, $m)) {
            throw new \RuntimeException('Bitte die Adresse der App angeben, z. B. https://beispiel.de/app/');
        }
        $host = strtolower($m[2]);
        if (preg_match('/[^\x20-\x7e]/', $host)) {
            $ascii = function_exists('idn_to_ascii') ? idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : false;
            if (!is_string($ascii) || $ascii === '') {
                throw new \RuntimeException('Die Adresse enthält Umlaute. Bitte die Schreibweise mit „xn--“ verwenden (zeigt der Hoster an).');
            }
            $host = $ascii;
        }
        return strtolower($m[1]) . '://' . $host . ($m[3] ?? '') . rtrim($m[4] ?? '', '/') . '/';
    }

    /** Legt das Datenverzeichnis an und sperrt es für den Webserver (Apache; nginx siehe README). */
    public static function prepareDataDir(string $data): void
    {
        if (!is_dir($data) && !@mkdir($data, 0770, true) && !is_dir($data)) {
            throw new \RuntimeException('Das Datenverzeichnis kann nicht angelegt werden: ' . $data);
        }
        if (!is_writable($data)) {
            throw new \RuntimeException('PHP darf im Datenverzeichnis nicht schreiben: ' . $data);
        }
        $files = [
            '.htaccess' => "# Kein Zugriff von außen\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n",
            'index.html' => '',
            'web.config' => "<?xml version=\"1.0\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
        ];
        foreach ($files as $name => $content) {
            if (!is_file($data . '/' . $name)) {
                file_put_contents($data . '/' . $name, $content);
            }
        }
        foreach (['files', 'tmp'] as $sub) {
            if (!is_dir($data . '/' . $sub)) {
                @mkdir($data . '/' . $sub, 0770, true);
            }
        }
    }

    /**
     * Legt einen Admin an. Ohne Passwort wird ein lesbares Startpasswort erzeugt.
     *
     * @return array{id: int, login: string, password: string}
     */
    public static function createAdmin(Kernel $kernel, string $name, string $login, string $email = '', ?string $password = null): array
    {
        $users = $kernel->users();
        $name = Text::line($name, 100);
        $login = trim($login);
        if ($name === '' || !Passwords::validLogin($login)) {
            throw new \RuntimeException('Bitte einen Namen und einen Benutzernamen (a–z, Ziffern, . _ - @) angeben.');
        }
        if ($users->loginExists($login)) {
            throw new \RuntimeException('Dieser Benutzername ist schon vergeben.');
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException('Die E-Mail-Adresse ist ungültig.');
        }
        if ($password !== null && mb_strlen($password) < Passwords::MIN_LENGTH) {
            throw new \RuntimeException(sprintf('Das Passwort muss mindestens %d Zeichen lang sein.', Passwords::MIN_LENGTH));
        }
        $password ??= Passwords::readable(12);
        $id = $users->add($name, $login, $email, $password, Roles::ADMIN);
        return ['id' => $id, 'login' => $login, 'password' => $password];
    }
}
