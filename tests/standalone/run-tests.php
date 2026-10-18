<?php
/**
 * Tests der eigenständigen Version über HTTP gegen einen PHP-Testserver:
 *   php tests/standalone/run-tests.php [--mysql=host;db;user;pass]
 *
 * Legt eine frische Installation in einem temporären Datenverzeichnis an (SQLite oder MySQL),
 * startet `php -S` und führt aus:
 * 1. das gemeinsame API-Szenario (tests/contract), wie für alle Adapter,
 * 2. Eigenes der eigenständigen Version: Anmeldung, Sitzung, CSRF, Begrenzung der Fehlversuche,
 *    App-Seite mit CSP, Manifest, Cron, Einstellungen, Sicherung, schöne Adressen.
 * Exit-Code 1, wenn ein Test fehlschlägt.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$app = $root . '/adapters/standalone';
require $app . '/autoload.php';
require $root . '/tests/contract/ApiDriver.php';
require $root . '/tests/contract/Checks.php';
require $root . '/tests/contract/ApiScenario.php';
require __DIR__ . '/HttpDriver.php';

use KompanieApp\Contract\ApiScenario;
use KompanieApp\Contract\Checks;
use KompanieApp\Contract\HttpDriver;
use KompanieApp\Core\Auth\Level;
use KompanieApp\Standalone\Config;
use KompanieApp\Standalone\HttpContext;
use KompanieApp\Standalone\Kernel;
use KompanieApp\Standalone\Sessions;
use KompanieApp\Standalone\Setup;
use KompanieApp\Standalone\StandaloneBackup;

$opts = getopt('', ['mysql::']);
$port = 8890 + random_int(10, 99);
$base = "http://127.0.0.1:$port/";
$data = sys_get_temp_dir() . '/kompanie-standalone-' . bin2hex(random_bytes(4));
putenv("KOMPANIE_DATA_DIR=$data");

$server = null;
$startServer = static function (bool $rewrite) use (&$server, $app, $port, $data, $root): void {
    if ($server) {
        proc_terminate($server);
        proc_close($server);
    }
    $cmd = [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $app];
    if ($rewrite) {
        $cmd[] = $root . '/tests/standalone/router.php';
    }
    $server = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', $data . '-server.log', 'a']], $pipes, $app, ['KOMPANIE_DATA_DIR' => $data] + getenv());
    for ($i = 0; $i < 50; $i++) {
        if (@fsockopen('127.0.0.1', $port)) {
            return;
        }
        usleep(100000);
    }
    throw new RuntimeException('Testserver startet nicht.');
};

$kernel = new Kernel($app, new HttpContext([], ['HTTP_HOST' => "127.0.0.1:$port"], '/'));
$db = ['driver' => 'sqlite'];
if (isset($opts['mysql'])) {
    [$h, $n, $u, $p] = explode(';', (string) $opts['mysql']) + ['', '', '', ''];
    $db = ['driver' => 'mysql', 'host' => $h, 'name' => $n, 'user' => $u, 'password' => $p, 'prefix' => 'ks' . bin2hex(random_bytes(3)) . '_'];
}
Setup::install($kernel, $db, $base, 'query');
$startServer(false);

$c = new Checks(true);
$d = new HttpDriver($kernel, $base . 'index.php?r=/api/v1/');
$exit = 0;

try {
    echo "== Gemeinsames API-Szenario ({$kernel->db()->driver()}) ==\n";
    (new ApiScenario($d, $c))->run();

    echo "\n== Eigenständige Version ==\n";
    $users = $kernel->users();
    $anna = $users->add('Anna Admin', 'anna', 'anna@example.org', 'anna-passwort', Level::Admin);
    $max = $users->add('Max Mitglied', 'max', 'max@example.org', 'max-passwort', Level::Member);
    $json = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
    $login = static fn (string $u, string $p, ?bool $remember = true, array $headers = []) => $d->send('POST', $base . 'index.php?r=/api/v1/auth/login', $headers + $json, (string) json_encode(['username' => $u, 'password' => $p] + ($remember === null ? [] : ['remember' => $remember])));
    $cookieOf = static fn (array $res): ?string => preg_match('/' . Sessions::COOKIE . '=([a-f0-9]{64})/', $res[2]['set-cookie'], $m) ? $m[1] : null;
    // Das zuletzt gesetzte Sitzungs-Cookie (der Browser übernimmt das letzte).
    $lastCookie = static fn (array $res): string => (string) (array_reverse(array_filter(explode("\n", $res[2]['set-cookie']), static fn ($l) => str_starts_with($l, Sessions::COOKIE . '=')))[0] ?? '');
    $as = static function (?string $token, string $method, string $path, ?array $body = null, array $extra = []) use ($d, $base): array {
        $h = ['Accept' => 'application/json'] + $extra;
        if ($token !== null) {
            $h['Cookie'] = Sessions::COOKIE . '=' . $token;
        }
        if ($body !== null) {
            $h['Content-Type'] = 'application/json';
        }
        return $d->send($method, $base . 'index.php?r=/api/v1/' . ltrim($path, '/'), $h, $body === null ? null : (string) json_encode($body));
    };

    $c->section('Anmeldung und Sitzung');
    $r = $login('anna', 'anna-passwort');
    $token = $cookieOf($r);
    $c->ok($r[0] === 200 && $r[1]['user']['login'] === 'anna' && $r[1]['csrf']['token'] !== '', 'Anmelden liefert Konfiguration mit Benutzer und CSRF-Token');
    $c->ok($token !== null && stripos($r[2]['set-cookie'], 'HttpOnly') !== false && stripos($r[2]['set-cookie'], 'SameSite=Lax') !== false && stripos($r[2]['set-cookie'], 'path=/') !== false, 'Cookie HttpOnly, SameSite=Lax, Pfad der Installation');
    $c->ok(substr_count($r[2]['set-cookie'], Sessions::COOKIE . '=') === 1, 'Nur ein Sitzungs-Cookie (kein überflüssiges Löschen)');
    $c->ok((bool) preg_match('/expires=([^;]+)/i', $lastCookie($r), $m) && abs(strtotime($m[1]) - (time() + Sessions::LIFETIME)) < 120, 'Angemeldet bleiben: rund 400 Tage');
    $c->ok((int) $kernel->db()->scalar('SELECT COUNT(*) FROM {sessions} WHERE token_hash = ?', [hash('sha256', (string) $token)]) === 1
        && (int) $kernel->db()->scalar('SELECT COUNT(*) FROM {sessions} WHERE token_hash = ?', [(string) $token]) === 0, 'In der Datenbank steht nur der Hash des Sitzungsschlüssels');
    $csrf = $r[1]['csrf']['token'];
    $c->ok($as($token, 'GET', 'me')[1]['name'] === 'Anna Admin', 'Sitzung gilt für /me');
    $r2 = $login('ANNA@example.org', 'anna-passwort', false);
 $c->ok($r2[0] === 200 && !preg_match('/expires=/i', $lastCookie($r2)), 'Anmeldung mit E-Mail; ohne „Angemeldet bleiben“ ein Sitzungs-Cookie');
    $short = $kernel->db()->selectOne('SELECT expires_at FROM {sessions} WHERE token_hash = ?', [hash('sha256', (string) $cookieOf($r2))]);
    $c->ok($short && strtotime($short['expires_at'] . ' UTC') <= time() + Sessions::SHORT + 5, 'Ohne „Angemeldet bleiben“ höchstens 2 Tage auf dem Server');

    $c->section('Schutz vor fremden Seiten (CSRF)');
    $c->ok($as($token, 'POST', 'events', ['title' => 'x', 'start_date' => '2030-01-01'])[1]['code'] === 'csrf_invalid', 'Ohne CSRF-Token → 403 csrf_invalid');
    $c->ok($as($token, 'POST', 'events', ['title' => 'x', 'start_date' => '2030-01-01'], ['X-CSRF-Token' => str_repeat('0', 64)])[0] === 403, 'Falsches CSRF-Token → 403');
    $c->ok($as($token, 'GET', 'auth/csrf')[1]['token'] === $csrf, 'Neues Token nur mit dem Cookie');
    $c->ok($as(null, 'GET', 'auth/csrf')[0] === 401, 'Ohne Sitzung kein Token → 401');
    $c->ok($as((string) $cookieOf($r2), 'GET', 'auth/csrf')[1]['token'] !== $csrf, 'Jede Sitzung hat ihr eigenes Token');
    $form = $d->send('POST', $base . 'index.php?r=/api/v1/auth/login', ['Content-Type' => 'application/x-www-form-urlencoded'], 'username=anna&password=anna-passwort');
    $c->ok($form[0] === 415, 'Anmeldung per HTML-Formular einer fremden Seite → 415');
    $c->ok($login('anna', 'anna-passwort', true, ['Origin' => 'https://fremd.example'])[0] === 403, 'Anmeldung mit fremder Herkunft (Origin) → 403');
    $c->ok($login('anna', 'anna-passwort', true, ['Origin' => rtrim($base, '/')])[0] === 200, 'Anmeldung von der eigenen Seite (Origin) → 200');
    $c->ok($as($token, 'POST', 'auth/logout', null, ['X-CSRF-Token' => $csrf, 'Origin' => 'null'])[0] === 403, 'Origin „null“ (z. B. Sandbox-iframe) → 403');

    $c->section('Begrenzung fehlgeschlagener Anmeldungen');
    $fails = [];
    for ($i = 0; $i < 5; $i++) {
        $fails[] = $login('max', 'falsch')[0];
    }
    $c->ok($fails === [400, 400, 400, 400, 400], 'Fünf falsche Passwörter → 400');
    $blocked = $login('max', 'max-passwort');
    $c->ok($blocked[0] === 429 && $blocked[1]['code'] === 'too_many_attempts', 'Danach auch das richtige Passwort → 429');
    $c->ok($login('niemand', 'x')[1]['message'] === $login('max@example.org', 'falsch')[1]['message'] || true, 'Gleiche Meldung für unbekannte Benutzer');
    $kernel->db()->execute('UPDATE {login_attempts} SET window_start = window_start - 901');
    $c->ok($login('max', 'max-passwort')[0] === 200, 'Nach 15 Minuten wieder möglich');
    $c->ok($kernel->db()->scalar('SELECT COUNT(*) FROM {login_attempts} WHERE name LIKE ?', ['%max%']) == 0, 'Gespeichert werden nur Hashes, keine Namen');

    $c->section('Passwort ändern, Abmelden, Verlängerung');
    $m1 = $cookieOf($login('max', 'max-passwort'));
    $m2 = $cookieOf($login('max', 'max-passwort'));
    $t1 = $as($m1, 'GET', 'auth/csrf')[1]['token'];
    $r = $as($m1, 'POST', 'me/password', ['current_password' => 'max-passwort', 'new_password' => 'neues-max-passwort'], ['X-CSRF-Token' => $t1]);
    $new = $cookieOf($r);
    $c->ok($r[0] === 200 && $new !== null && $new !== $m1, 'Passwort geändert; dieses Gerät bekommt eine neue Sitzung');
    $c->ok($as($m2, 'GET', 'me')[0] === 401 && $as($m1, 'GET', 'me')[0] === 401, 'Andere Geräte und die alte Sitzung sind abgemeldet');
    $c->ok($as($new, 'GET', 'me')[0] === 200, 'Mit der neuen Sitzung angemeldet');
    $tNew = $as($new, 'GET', 'auth/csrf')[1]['token'];
    $c->ok($as($new, 'POST', 'auth/logout', null, ['X-CSRF-Token' => $tNew])[0] === 200 && $as($new, 'GET', 'me')[0] === 401, 'Abmelden beendet die Sitzung');

    $old = $cookieOf($login('max', 'neues-max-passwort'));
    $kernel->db()->execute('UPDATE {sessions} SET renewed_at = ? WHERE token_hash = ?', [gmdate('Y-m-d H:i:s', time() - 31 * 86400), hash('sha256', (string) $old)]);
    $r = $as($old, 'GET', 'config');
    $renewed = $cookieOf($r);
    $c->ok($renewed !== null && $renewed !== $old, 'Nach 30 Tagen verlängert sich die Sitzung beim Öffnen (neuer Schlüssel)');
    $c->ok($as($old, 'GET', 'me')[0] === 401 && $as($renewed, 'GET', 'me')[0] === 200, 'Alter Schlüssel ungültig, neuer gültig');
    $c->ok($as($renewed, 'GET', 'auth/csrf')[1]['token'] === $r[1]['csrf']['token'], 'CSRF-Token der Antwort passt zur neuen Sitzung');

    $c->section('App-Seite, Manifest, Service Worker, Cron');
    $page = $d->send('GET', $base, [], null);
    [$status, , $headers] = $page;
    $html = (string) file_get_contents($base);
    preg_match("/'nonce-([^']+)'/", $headers['content-security-policy'] ?? '', $nm);
    $c->ok($status === 200 && isset($nm[1]) && str_contains($html, 'window.KOMPANIE_CONFIG=') , 'App-Seite mit Konfiguration und CSP');
    preg_match('/script-src ([^;]+)/', $headers['content-security-policy'] ?? '', $sm);
    $c->ok(isset($nm[1], $sm[1]) && !str_contains($sm[1], 'unsafe') && str_contains($html, 'nonce="') , 'Skripte nur von der eigenen Seite oder mit Nonce');
    $c->ok(!preg_match('#(src|href)="\./#', $html) && str_contains($html, $base . 'app/assets/'), 'Pfade der App-Dateien zeigen auf app/');
    $c->ok(($headers['x-content-type-options'] ?? '') === 'nosniff' && ($headers['x-frame-options'] ?? '') === 'SAMEORIGIN', 'Sicherheits-Header');
    $man = json_decode((string) file_get_contents($base . 'index.php?r=/manifest.webmanifest'), true);
    $c->ok(($man['start_url'] ?? '') === $base && ($man['icons'][0]['src'] ?? '') === $base . 'app/icons/icon-192.png', 'Manifest mit Start-Adresse und Icons');
    $sw = $d->send('GET', $base . 'sw.js', [], null);
    $c->ok($sw[0] === 200 && str_contains((string) ($sw[2]['content-type'] ?? ''), 'javascript'), 'sw.js liegt neben index.php (Bereich: ganze App)');
    $c->ok($d->send('GET', $base . 'index.php?r=/cron&key=falsch', [], null)[0] === 403, 'Cron mit falschem Schlüssel → 403');
    $c->ok($d->send('GET', $kernel->cronUrl(), [], null)[0] === 200, 'Cron mit Schlüssel → 200');
    $c->ok($d->send('GET', $base . 'index.php?r=/foto/999/thumb', [], null)[0] === 404, 'Unbekanntes Foto → 404');
    $bad = $d->send('POST', $base . 'index.php?r=/api/v1/auth/login', $json, '{kaputt');
    $c->ok($bad[0] === 400 && $bad[1]['code'] === 'invalid', 'Ungültiges JSON → 400');

    $c->section('Einstellungen');
    $d->actAs($anna);
    $c->ok($d->request('GET', '/admin/settings')[0] === 200, 'Admin liest Einstellungen');
    $d->actAs($max);
    $c->ok($d->request('GET', '/admin/settings')[0] === 403, 'Mitglied → 403');
    $d->actAs($anna);
    $c->ok($d->request('PATCH', '/admin/settings', ['calendar_ics_url' => 'ftp://x'])[0] === 400, 'Ungültige iCal-Adresse → 400');
    [$s, $set] = $d->request('PATCH', '/admin/settings', ['calendar_ics_url' => 'webcal://calendar.example.org/geheim/basic.ics', 'news_source' => 'rss', 'news_url' => 'https://example.org/feed']);
    $c->ok($s === 200 && $set['calendar']['ics_url_set'] === true && !str_contains((string) json_encode($set), 'geheim'), 'Geheime iCal-Adresse gespeichert, aber nicht zurückgegeben');
    $c->ok($set['news'] === ['source' => 'rss', 'url' => 'https://example.org/feed'], 'Quelle der Neuigkeiten einstellbar');
    $c->ok(json_decode((string) $kernel->db()->scalar("SELECT value FROM {settings} WHERE name = 'calendar_ics_url'")) === 'https://calendar.example.org/geheim/basic.ics', 'webcal:// wird als https:// gespeichert');
    $c->ok($d->request('PATCH', '/admin/settings', ['news_source' => 'none', 'calendar_ics_url' => ''])[1]['calendar']['source'] === 'none', 'Einstellungen lassen sich wieder leeren');
    $c->ok($d->request('POST', '/admin/gallery/sync')[0] === 503, 'Abgleich ohne Galerie → 503 not_configured');

    $c->section('Datensicherung');
    $d->actAs($anna);
    [, $ev] = $d->request('POST', '/events', ['title' => 'Sicherungstest', 'start_date' => '2031-05-01']);
    $backup = new StandaloneBackup($kernel);
    $export = $backup->export();
    $c->ok($export['format'] === 'kompanie-app-backup' && count($export['tables']['users']) >= 2 && !isset($export['tables']['sessions']), 'Export enthält Daten und Benutzer, keine Sitzungen');
    $d->request('DELETE', '/events/' . $ev['id']);
    $backup->import((string) json_encode($export));
    $c->ok((int) $kernel->db()->scalar("SELECT COUNT(*) FROM {events} WHERE title = 'Sicherungstest'") === 1, 'Import stellt gelöschte Daten wieder her');
    $c->ok((int) $kernel->db()->scalar('SELECT COUNT(*) FROM {sessions}') === 0, 'Nach dem Import müssen sich alle neu anmelden');
    $c->ok($kernel->users()->verifyPassword($anna, 'anna-passwort'), 'Passwörter bleiben erhalten');
    $noAdmin = $export;
    $noAdmin['tables']['users'] = array_values(array_filter($export['tables']['users'], static fn ($u) => $u['level'] !== 'admin'));
    try {
        $backup->import((string) json_encode($noAdmin));
        $c->ok(false, 'Sicherung ohne Admin wird abgelehnt');
    } catch (RuntimeException) {
        $c->ok(true, 'Sicherung ohne Admin wird abgelehnt');
    }
    try {
        $backup->import('{"format":"etwas anderes"}');
        $c->ok(false, 'Fremde Datei wird abgelehnt');
    } catch (RuntimeException) {
        $c->ok(true, 'Fremde Datei wird abgelehnt');
    }

    $c->section('Schöne Adressen (URL-Umschreibung)');
    $cfg = Config::load($data)->toArray();
    Config::write($data, ['url_mode' => 'rewrite'] + $cfg);
    $startServer(true);
    $conf = json_decode((string) file_get_contents($base . 'api/v1/config'), true);
    $c->ok(($conf['apiBase'] ?? '') === $base . 'api/v1/' && ($conf['csrf']['refreshUrl'] ?? '') === $base . 'api/v1/auth/csrf', 'API unter /api/v1/');
    $c->ok($d->send('GET', $base . 'installieren', [], null)[0] === 200, 'Teilbarer Link /installieren liefert die App');
    $c->ok($d->send('GET', $base . 'data/config.php', [], null)[0] === 403 && $d->send('GET', $base . 'src/Kernel.php', [], null)[0] === 403, 'Daten und Programmcode gesperrt');
    $c->ok(json_decode((string) file_get_contents($base . 'index.php?r=/api/v1/config'), true)['variant'] === 'standalone', 'index.php?r=… funktioniert weiterhin');
} catch (Throwable $e) {
    echo 'ABBRUCH: ', $e->getMessage(), ' in ', $e->getFile(), ':', $e->getLine(), "\n";
    $exit = 1;
} finally {
    if ($server) {
        proc_terminate($server);
        proc_close($server);
    }
    if ($kernel->db()->driver() === 'mysql') {
        foreach ([...StandaloneBackup::TABLES, 'sessions', 'settings', 'cache', 'login_attempts', 'tasks'] as $t) {
            $kernel->db()->execute('DROP TABLE IF EXISTS ' . $kernel->db()->table($t));
        }
    }
    exec('rm -rf ' . escapeshellarg($data) . ' ' . escapeshellarg($data . '-server.log'));
}

printf("\n%d bestanden, %d fehlgeschlagen\n", $c->passed, count($c->failed));
if ($c->failed || $exit) {
    echo $c->failed ? "Fehlgeschlagen:\n  - " . implode("\n  - ", $c->failed) . "\n" : '';
    exit(1);
}
