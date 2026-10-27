<?php
/**
 * Kommandozeile der eigenständigen Version (für Hoster mit SSH oder Cronjob).
 *
 *   php bin/kompanie.php install --url=https://example.org/app/ [--rewrite]
 *                                [--mysql --db-host=… --db-name=… --db-user=… --db-pass=… --db-prefix=…]
 *   php bin/kompanie.php create-admin --name="Vorname Nachname" --login=name [--email=…] [--password=…]
 *   php bin/kompanie.php reset-password --login=name
 *   php bin/kompanie.php cron           fällige Aufgaben ausführen (z. B. stündlich als Cronjob)
 *   php bin/kompanie.php export [--out=datei.json]
 *   php bin/kompanie.php import datei.json
 *   php bin/kompanie.php status
 *   php bin/kompanie.php update-check   Update-Liste prüfen und anzeigen
 *   php bin/kompanie.php update [--version=…] [--major] [--without-drive]   neueste (oder angegebene) Version einspielen
 *   php bin/kompanie.php rollback --version=… [--without-drive]   auf eine ältere Version zurück
 *
 * Vor jedem Update wird gesichert, mit eingeschalteten Sicherungen in Google Drive auch dort. Scheitert nur Google
 * Drive, bricht das Update ab; --without-drive spielt es trotzdem ein (die Sicherung auf dem Server ist da).
 *
 * Updates bitte als derselbe Benutzer ausführen, unter dem PHP auf dem Webspace läuft;
 * sonst gehören die neuen Dateien einem anderen Benutzer.
 */

declare(strict_types=1);

use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Update\UpdateService;
use KompanieApp\Standalone\HttpContext;
use KompanieApp\Standalone\Kernel;
use KompanieApp\Standalone\Setup;
use KompanieApp\Standalone\StandaloneBackup;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require dirname(__DIR__) . '/autoload.php';

/** @return array{0: string, 1: array<string, string|true>, 2: string[]} */
function kompanie_args(array $argv): array
{
    $cmd = $argv[1] ?? 'help';
    $opts = [];
    $rest = [];
    foreach (array_slice($argv, 2) as $a) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $a, $m)) {
            $opts[$m[1]] = $m[2] ?? true;
        } else {
            $rest[] = $a;
        }
    }
    return [$cmd, $opts, $rest];
}

[$cmd, $opt, $rest] = kompanie_args($argv);
$str = static fn (string $k, string $d = ''): string => is_string($opt[$k] ?? null) ? $opt[$k] : $d;

$url = $str('url');
$path = $url !== '' ? (string) (parse_url($url, PHP_URL_PATH) ?: '/') : '/';
$server = ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => $url !== '' ? (string) parse_url($url, PHP_URL_HOST) : 'localhost', 'HTTPS' => str_starts_with($url, 'https') ? 'on' : ''];
$kernel = new Kernel(dirname(__DIR__), new HttpContext([], $server, rtrim($path, '/') . '/'));

try {
    switch ($cmd) {
        case 'install':
            $db = isset($opt['mysql'])
                ? ['driver' => 'mysql', 'host' => $str('db-host', 'localhost'), 'port' => (int) $str('db-port', '3306'), 'name' => $str('db-name'), 'user' => $str('db-user'), 'password' => $str('db-pass'), 'prefix' => $str('db-prefix', 'kompanie_')]
                : ['driver' => 'sqlite'];
            Setup::install($kernel, $db, $url, isset($opt['rewrite']) ? 'rewrite' : 'query');
            echo "Eingerichtet. Als Nächstes einen Admin anlegen:\n  php bin/kompanie.php create-admin --name=\"…\" --login=…\n";
            break;

        case 'create-admin':
            $kernel->upgrade();
            $r = Setup::createAdmin($kernel, $str('name'), $str('login'), $str('email'), isset($opt['password']) ? $str('password') : null);
            echo "Admin angelegt.\n  Benutzername: {$r['login']}\n";
            if (!isset($opt['password'])) {
                echo "  Startpasswort: {$r['password']}\n  (Bitte nach der ersten Anmeldung unter Konto ändern.)\n";
            }
            break;

        case 'reset-password':
            $kernel->upgrade();
            $users = $kernel->users();
            $row = $users->findForLogin($str('login'));
            if (!$row) {
                throw new RuntimeException('Diesen Benutzer gibt es nicht.');
            }
            $password = KompanieApp\Core\Domain\Passwords::readable(12);
            $users->setPassword((int) $row['id'], $password);
            echo "Neues Startpasswort für {$row['login']}: $password\n";
            break;

        case 'cron':
            $kernel->upgrade();
            $kernel->runDueTasks();
            break;

        case 'export':
            $kernel->upgrade();
            $json = json_encode((new StandaloneBackup($kernel))->export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $out = $str('out');
            if ($out === '') {
                echo $json, "\n";
            } else {
                file_put_contents($out, $json);
                @chmod($out, 0600);
                fwrite(STDERR, "Gesichert in $out (enthält Passwort-Hashes und Zugangsschlüssel – sicher aufbewahren).\n");
            }
            break;

        case 'import':
            $file = $rest[0] ?? '';
            if ($file === '' || !is_readable($file)) {
                throw new RuntimeException('Bitte die Datei der Sicherung angeben.');
            }
            $kernel->upgrade();
            $backup = new StandaloneBackup($kernel);
            // Vorher den jetzigen Stand sichern, damit ein falscher Import rückgängig zu machen ist.
            $before = $kernel->dataDir() . '/vor-import-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.json';
            $out = fopen($before, 'wb');
            @chmod($before, 0600);
            try {
                $backup->exportTo($out); // Stück für Stück, auch bei großen Sicherungen
            } finally {
                fclose($out);
            }
            echo "Bisheriger Stand gesichert: $before\n";
            $backup->import((string) file_get_contents($file));
            echo "Sicherung wiederhergestellt. Alle Geräte müssen sich neu anmelden; die Galeriebilder werden neu aufbereitet.\n";
            break;

        case 'status':
            echo 'Version: ', $kernel->version(), "\n";
            echo 'Eingerichtet: ', $kernel->installed() ? 'ja' : 'nein', "\n";
            if ($kernel->installed()) {
                $kernel->upgrade();
                echo 'Datenbank: ', $kernel->db()->driver(), "\n";
                echo 'Benutzer: ', $kernel->users()->count(), "\n";
                foreach ($kernel->scheduler()->overview() as $task => $next) {
                    echo "Aufgabe $task: nächster Lauf ", date('Y-m-d H:i', $next), "\n";
                }
                echo 'Cron-Adresse: ', $kernel->cronUrl(), "\n";
            }
            break;

        case 'update-check':
            $kernel->upgrade();
            $updates = $kernel->app()->updates();
            $updates->check();
            $st = $updates->status();
            echo 'Installiert: ', $st['current'], " (Kanal {$st['channel']}, automatisch: ", $st['auto'] ? 'ja' : 'nein', ")\n";
            if ($st['withdrawn']) {
                echo $st['fallback'] !== null
                    ? "Diese Version wurde zurückgezogen; Wechsel auf {$st['fallback']} mit: update (oder beim nächsten Cron-Lauf).\n"
                    : "Diese Version wurde zurückgezogen; es gibt noch keine freigegebene Version zum Wechseln.\n";
            }
            echo $st['available'] ? "Neue Version: {$st['latest']}" . ($st['skipped'] ? ' (nach dem Zurückrollen nur von Hand)' : '') . "\n" : "Keine neuere Version derselben Hauptversion.\n";
            if ($st['major']) {
                echo "Neue Hauptversion verfügbar: {$st['majorVersion']} (nur mit: update --version={$st['majorVersion']} --major)\n";
            }
            foreach ($st['problems'] as $p) {
                echo "Hinweis: $p\n";
            }
            if ($st['versions']) {
                echo 'Verfügbare Versionen: ', implode(', ', array_column($st['versions'], 'version')), "\n";
            }
            break;

        case 'update':
        case 'rollback':
            $kernel->upgrade();
            $updates = $kernel->app()->updates();
            $updates->check();
            $version = $str('version');
            if ($cmd === 'rollback') {
                if ($version === '') {
                    throw new RuntimeException('Bitte die Version angeben: rollback --version=…');
                }
                $updates->rollback($version, isset($opt['without-drive']));
            } else {
                $st = $updates->status();
                $version = $version !== '' ? $version : (string) ($st['available'] ? $st['latest'] : ($st['withdrawn'] ? $st['fallback'] : ''));
                if ($version === '' && $st['withdrawn']) {
                    throw new RuntimeException('Diese Version wurde zurückgezogen, aber es gibt noch keine freigegebene Version zum Wechseln.');
                }
                if ($version === '') {
                    echo "Schon aktuell.\n";
                    break;
                }
                $updates->install($version, 'manual', isset($opt['major']), isset($opt['without-drive']));
            }
            echo "Version $version eingespielt.\n";
            break;

        default:
            $doc = (string) file_get_contents(__FILE__);
            preg_match('#/\*\*(.*?)\*/#s', $doc, $m);
            echo preg_replace('/^\s*\* ?/m', '', trim($m[1] ?? '')), "\n";
            exit($cmd === 'help' ? 0 : 1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Fehler: ' . $e->getMessage() . "\n");
    if ($e instanceof ApiError && $e->errorCode === UpdateService::DRIVE_FAILED) {
        fwrite(STDERR, "Trotzdem einspielen: denselben Befehl mit --without-drive.\n");
    }
    exit(1);
}
