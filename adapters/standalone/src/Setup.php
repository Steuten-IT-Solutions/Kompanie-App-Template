<?php

declare(strict_types=1);

namespace KompanieApp\Standalone;

use KompanieApp\Core\Auth\Level;
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
    public static function install(Kernel $kernel, array $db, string $baseUrl = '', string $urlMode = 'query'): void
    {
        $data = $kernel->dataDir();
        if (Config::exists($data)) {
            throw new \RuntimeException('Die App ist hier schon eingerichtet (config.php vorhanden).');
        }
        self::prepareDataDir($data);
        if (($db['driver'] ?? 'sqlite') === 'sqlite') {
            if (!extension_loaded('pdo_sqlite')) {
                throw new \RuntimeException('PHP kann keine SQLite-Datenbank anlegen (Erweiterung pdo_sqlite fehlt). Sie lässt sich meist im Kundenmenü des Hosters einschalten; alternativ eine MySQL-Datenbank nutzen.');
            }
            $db = ['driver' => 'sqlite', 'path' => 'kompanie.sqlite'];
        } elseif (!extension_loaded('pdo_mysql')) {
            throw new \RuntimeException('PHP kann keine MySQL-Datenbank ansprechen (Erweiterung pdo_mysql fehlt).');
        }
        $settings = Config::defaults($db);
        $settings['base_url'] = $baseUrl === '' ? '' : rtrim($baseUrl, '/') . '/';
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
        $id = $users->add($name, $login, $email, $password, Level::Admin);
        return ['id' => $id, 'login' => $login, 'password' => $password];
    }
}
