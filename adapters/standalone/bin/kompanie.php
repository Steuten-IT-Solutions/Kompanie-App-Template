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
 */

declare(strict_types=1);

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
            $before = $kernel->dataDir() . '/vor-import-' . gmdate('Ymd-His') . '.json';
            file_put_contents($before, json_encode($backup->export(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            @chmod($before, 0600);
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

        default:
            $doc = (string) file_get_contents(__FILE__);
            preg_match('#/\*\*(.*?)\*/#s', $doc, $m);
            echo preg_replace('/^\s*\* ?/m', '', trim($m[1] ?? '')), "\n";
            exit($cmd === 'help' ? 0 : 1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Fehler: ' . $e->getMessage() . "\n");
    exit(1);
}
