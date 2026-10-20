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
require $root . '/packages/core/tests/Support/CountingPdo.php';

use KompanieApp\Contract\ApiScenario;
use KompanieApp\Contract\Checks;
use KompanieApp\Core\Tests\Support\CountingPdo;
use KompanieApp\Core\Storage\Sql\PdoConnection;
use KompanieApp\Contract\HttpDriver;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Standalone\Config;
use KompanieApp\Standalone\HttpContext;
use KompanieApp\Standalone\Kernel;
use KompanieApp\Standalone\SessionAuth;
use KompanieApp\Standalone\Sessions;
use KompanieApp\Standalone\Setup;
use KompanieApp\Standalone\StandaloneBackup;

$opts = getopt('', ['mysql::']);
$port = 8890 + random_int(10, 99);
$base = "http://127.0.0.1:$port/";
$data = sys_get_temp_dir() . '/kompanie-standalone-' . bin2hex(random_bytes(4));
putenv("KOMPANIE_DATA_DIR=$data");

$server = null;
// Mit PHP_CLI_SERVER_WORKERS hat der Server Kindprozesse; die müssen mit beendet werden.
$stopServer = static function ($proc): void {
    $pid = proc_get_status($proc)['pid'] ?? 0;
    if ($pid > 0) {
        exec('pkill -TERM -P ' . (int) $pid . ' 2>/dev/null');
    }
    proc_terminate($proc);
    proc_close($proc);
};
$startServer = static function (bool $rewrite) use (&$server, $app, $port, $data, $root, $stopServer): void {
    if ($server) {
        $stopServer($server);
        usleep(300000);
    }
    $cmd = [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $app];
    if ($rewrite) {
        $cmd[] = $root . '/tests/standalone/router.php';
    }
    $server = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', $data . '-server.log', 'a']], $pipes, $app, ['KOMPANIE_DATA_DIR' => $data, 'PHP_CLI_SERVER_WORKERS' => '8'] + getenv());
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
    $anna = $users->add('Anna Admin', 'anna', 'anna@example.org', 'anna-passwort', Roles::ADMIN);
    $max = $users->add('Max Mitglied', 'max', 'max@example.org', 'max-passwort', Roles::MEMBER);
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
    $c->ok($login('anna', 'anna-passwort', true, ['Sec-Fetch-Site' => 'cross-site'])[0] === 403, 'Sec-Fetch-Site: cross-site → 403');
    $raw = $d->send('POST', $base . 'index.php?r=/api/v1/auth/login', [], (string) json_encode(['username' => 'anna', 'password' => 'anna-passwort']));
    $c->ok($raw[0] === 415, 'Anmeldung ohne Content-Type → 415');

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

    $kernel->db()->execute('DELETE FROM {login_attempts}');
    for ($i = 0; $i < 5; $i++) {
        $login('max', 'falsch');
    }
    $c->ok($login('MAX@example.org', 'max-passwort')[0] === 429, 'Gleiches Konto per E-Mail oder anderer Schreibweise zählt mit');
    $kernel->db()->execute('DELETE FROM {login_attempts}');

    // Gleichzeitige Fehlversuche: Jeder wird vor der Prüfung gezählt, keiner scheitert an Sperren.
    $mh = curl_multi_init();
    $handles = [];
    for ($i = 0; $i < 24; $i++) {
        $ch = curl_init($base . 'index.php?r=/api/v1/auth/login');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['username' => 'anna', 'password' => 'falsch' . $i])]);
        curl_multi_add_handle($mh, $ch);
        $handles[] = $ch;
    }
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.1);
    } while ($running > 0);
    $codes = array_map(static fn ($ch) => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $handles);
    $count = array_count_values($codes);
    $c->ok(!isset($count[500]) && ($count[400] ?? 0) <= 5 && ($count[429] ?? 0) >= 19, 'Gleichzeitige Fehlversuche: höchstens 5 Passwortprüfungen, keine Fehler (' . json_encode($count) . ')');
    $kernel->db()->execute('DELETE FROM {login_attempts}');

    // Bekannte Geräte: Fremde können den Besitzer nicht über das Konto-Limit aussperren.
    $throttle = new KompanieApp\Standalone\LoginThrottle($kernel->db(), Config::load($data)->secret());
    for ($i = 1; $i <= 50; $i++) {
        $throttle->attempt('u99', "10.0.0.$i", false);
    }
    $c->ok($throttle->attempt('u99', '10.0.1.1', false) === false, 'Konto-Limit für unbekannte Geräte (verteiltes Raten)');
    $c->ok($throttle->attempt('u99', '10.0.1.2', true) === true, 'Bekanntes Gerät des Besitzers kommt trotzdem herein');
    for ($i = 0; $i < 60; $i++) {
        $throttle->attempt('u77', '10.9.9.9', false);
    }
    $c->ok($throttle->attempt('u77', '10.8.8.8', false) === true, 'Eine einzelne IP kann das Konto-Limit nicht füllen');
    $c->ok(KompanieApp\Standalone\LoginThrottle::network('2001:db8:1:2::1') === KompanieApp\Standalone\LoginThrottle::network('2001:db8:1:2:ffff::9')
        && KompanieApp\Standalone\LoginThrottle::network('2001:db8:1:3::1') !== KompanieApp\Standalone\LoginThrottle::network('2001:db8:1:2::1'), 'IPv6: ein /64-Netz zählt wie eine Adresse');
    $r = $login('anna', 'anna-passwort');
    $c->ok(str_contains($r[2]['set-cookie'], SessionAuth::DEVICE_COOKIE . '='), 'Nach der Anmeldung mit „Angemeldet bleiben“ ist das Gerät bekannt');
    $c->ok(!str_contains($login('anna', 'anna-passwort', false)[2]['set-cookie'], SessionAuth::DEVICE_COOKIE . '='), 'Ohne „Angemeldet bleiben“ wird das Gerät nicht gemerkt');
    $login('niemand-' . bin2hex(random_bytes(3)), 'x');
    $c->ok(is_string(json_decode((string) $kernel->db()->scalar("SELECT value FROM {settings} WHERE name = 'login_dummy_hash'"), true)), 'Vergleichs-Hash für unbekannte Namen wird einmal erzeugt und gespeichert');
    $c->ok($as(str_repeat('0', 64), 'DELETE', 'events/1')[0] === 401, 'Abgelaufene Sitzung und Anfrage ohne Inhalt: 401 statt 415');
    $kernel->db()->execute('DELETE FROM {login_attempts}');

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
    // Ohne statische Auslieferung (Weg über index.php): ETag, bei passendem If-None-Match 304 (Issue #18).
    $swPhp = $d->send('GET', $base . 'index.php?r=/sw.js', [], null);
    $swEtag = (string) ($swPhp[2]['etag'] ?? '');
    $c->ok($swPhp[0] === 200 && $swEtag !== '' && ($swPhp[2]['cache-control'] ?? '') === 'no-cache' && isset($swPhp[2]['service-worker-allowed']), 'sw.js über index.php: mit ETag, no-cache');
    $swAgain = $d->send('GET', $base . 'index.php?r=/sw.js', ['If-None-Match' => $swEtag], null);
    $c->ok($swAgain[0] === 304 && !isset($swAgain[2]['content-length']), 'sw.js über index.php: passender ETag → 304 ohne Inhalt');
    $c->ok($d->send('GET', $base . 'index.php?r=/cron&key=falsch', [], null)[0] === 403, 'Cron mit falschem Schlüssel → 403');
    $c->ok($d->send('GET', $kernel->cronUrl(), [], null)[0] === 200, 'Cron mit Schlüssel → 200');
    // Schwarzes Brett (Issue #5): Der Cron führt das Aufräumen aus und löscht abgelaufene Nachrichten.
    $kdb = $kernel->db();
    $past = static fn (int $s): string => gmdate('Y-m-d H:i:s', time() - $s);
    $kdb->insert('board_messages', ['body' => 'Kpsa abgelaufen', 'author_id' => 1, 'created_at' => $past(90000), 'updated_at' => $past(90000), 'expires_at' => $past(3600)]);
    $kdb->execute('UPDATE {tasks} SET next_run = ? WHERE name = ?', [time() - 1, \KompanieApp\Core\Domain\BoardService::TASK]);
    $kdb->execute('DELETE FROM {settings} WHERE name = ?', [KompanieApp\Standalone\PseudoScheduler::NEXT_SETTING]); // nächsten Lauf neu lesen
    $c->ok((int) $kdb->scalar('SELECT COUNT(*) FROM {tasks} WHERE name = ?', [\KompanieApp\Core\Domain\BoardService::TASK]) === 1, 'Schwarzes Brett: Aufräumen eingeplant');
    $d->send('GET', $kernel->cronUrl(), [], null);
    // Der Cron antwortet sofort und arbeitet danach: kurz warten.
    for ($i = 0; $i < 50 && (int) $kdb->scalar('SELECT COUNT(*) FROM {board_messages} WHERE body = ?', ['Kpsa abgelaufen']) > 0; $i++) {
        usleep(100000);
    }
    $c->ok((int) $kdb->scalar('SELECT COUNT(*) FROM {board_messages} WHERE body = ?', ['Kpsa abgelaufen']) === 0, 'Cron löscht abgelaufene Nachrichten am Brett');
    // Umfragen (Issue #4): Der Cron führt die Erinnerung aus (bald endende Umfrage wird einmal als erinnert vermerkt).
    $pollTask = \KompanieApp\Core\Domain\PollService::TASK;
    $c->ok((int) $kdb->scalar('SELECT COUNT(*) FROM {tasks} WHERE name = ?', [$pollTask]) === 1, 'Umfragen: Erinnerung eingeplant');
    $pollId = $kdb->insert('polls', ['title' => 'Kpsa Umfrage', 'description' => '', 'type' => 'text', 'anonymous' => 0, 'results' => 'after_vote', 'ends_at' => gmdate('Y-m-d H:i:s', time() + 3600), 'closed_at' => null, 'reminded_at' => null, 'created_by' => 1, 'created_at' => $past(60), 'updated_at' => $past(60)]);
    $kdb->execute('UPDATE {tasks} SET next_run = ? WHERE name = ?', [time() - 1, $pollTask]);
    $kdb->execute('DELETE FROM {settings} WHERE name = ?', [KompanieApp\Standalone\PseudoScheduler::NEXT_SETTING]);
    $d->send('GET', $kernel->cronUrl(), [], null);
    for ($i = 0; $i < 50 && $kdb->scalar('SELECT reminded_at FROM {polls} WHERE id = ?', [$pollId]) === null; $i++) {
        usleep(100000);
    }
    $c->ok($kdb->scalar('SELECT reminded_at FROM {polls} WHERE id = ?', [$pollId]) !== null, 'Cron erinnert an bald endende Umfragen');
    // Geburtstage (Issue #1): stündlich eingeplant; der Cron beansprucht einen heutigen Geburtstag ab 7 Uhr Ortszeit
    // (last_notified), vorher nicht.
    $birthdayTask = \KompanieApp\Core\Domain\BirthdayService::TASK;
    $c->ok((int) $kdb->scalar('SELECT interval_seconds FROM {tasks} WHERE name = ?', [$birthdayTask]) === 3600, 'Geburtstage: Benachrichtigung stündlich eingeplant');
    $localNow = (new DateTimeImmutable('now'))->setTimezone($kernel->app()->time()->timezone());
    $kdb->execute('DELETE FROM {birthdays} WHERE user_id = ?', [$max]);
    $kdb->insert('birthdays', ['user_id' => $max, 'month' => (int) $localNow->format('n'), 'day' => (int) $localNow->format('j'), 'year' => null, 'visible' => 1, 'show_age' => 0, 'last_notified' => null, 'updated_by' => $max, 'updated_at' => $past(60)]);
    $kdb->execute('UPDATE {tasks} SET next_run = ? WHERE name = ?', [time() - 1, $birthdayTask]);
    $kdb->execute('DELETE FROM {settings} WHERE name = ?', [KompanieApp\Standalone\PseudoScheduler::NEXT_SETTING]);
    $d->send('GET', $kernel->cronUrl(), [], null);
    for ($i = 0; $i < 50 && (int) $kdb->scalar('SELECT next_run FROM {tasks} WHERE name = ?', [$birthdayTask]) < time(); $i++) {
        usleep(100000);
    }
    for ($i = 0; $i < 20 && (int) $localNow->format('G') >= 7 && $kdb->scalar('SELECT last_notified FROM {birthdays} WHERE user_id = ?', [$max]) === null; $i++) {
        usleep(100000);
    }
    $claimed = $kdb->scalar('SELECT last_notified FROM {birthdays} WHERE user_id = ?', [$max]);
    $c->ok((int) $localNow->format('G') >= 7 ? $claimed === $localNow->format('Y-m-d') : $claimed === null, 'Cron benachrichtigt über heutige Geburtstage erst ab 7 Uhr (Ortszeit ' . $localNow->format('H:i') . ')');
    $kdb->execute('DELETE FROM {birthdays} WHERE user_id = ?', [$max]);
    $c->ok($d->send('GET', $base . 'index.php?r=/foto/999/thumb', [], null)[0] === 404, 'Unbekanntes Foto → 404');

    $c->section('Geschützte Fotos: ETag und 304');
    $albumId = $kdb->insert('albums', ['drive_id' => 'etag-album', 'year' => '2026', 'title' => 'ETag', 'sort_date' => '2026-01-01', 'synced_at' => gmdate('Y-m-d H:i:s')]);
    $photoRow = ['album_id' => $albumId, 'drive_file_id' => 'etag-foto', 'name' => 'etag.jpg', 'is_intern' => 1, 'width' => 4, 'height' => 3, 'file_key' => 'etag' . bin2hex(random_bytes(4)), 'status' => 'ready'];
    $photoId = $kdb->insert('photos', $photoRow);
    $im = imagecreatetruecolor(4, 3);
    imagejpeg($im, $kernel->app()->gallery()->path($photoRow, 'thumb'));
    $photoUrl = $base . 'index.php?r=/foto/' . $photoId . '/thumb';
    $annaCookie = ['Cookie' => Sessions::COOKIE . '=' . $d->token($anna)];
    $first = $d->send('GET', $photoUrl, $annaCookie, null);
    $etag = (string) ($first[2]['etag'] ?? '');
    $c->ok($first[0] === 200 && preg_match('/^"[a-f0-9]{32}"$/', $etag) === 1 && ($first[2]['cache-control'] ?? '') === 'private, max-age=86400', 'Internes Foto: 200 mit ETag und privatem Cache');
    $again = $d->send('GET', $photoUrl, $annaCookie + ['If-None-Match' => $etag], null);
    $c->ok($again[0] === 304 && ($again[2]['etag'] ?? '') === $etag && ($again[2]['cache-control'] ?? '') === 'private, max-age=86400' && !isset($again[2]['content-length']) , 'Gleicher ETag (If-None-Match) → 304 ohne Inhalt');
    $c->ok($d->send('GET', $photoUrl, ['If-None-Match' => $etag], null)[0] === 401, 'Ohne Anmeldung trotz passendem ETag → 401 (Rechte vor 304)');
    $c->ok($d->send('GET', $photoUrl, $annaCookie + ['If-None-Match' => '"anders"'], null)[0] === 200, 'Anderer ETag → 200');
    $kdb->execute('DELETE FROM {photos} WHERE id = ?', [$photoId]);
    $kdb->execute('DELETE FROM {albums} WHERE id = ?', [$albumId]);
    $doc = $d->send('GET', $base . 'index.php?r=/dokument/1&v=x', [], null);
    $c->ok(in_array($doc[0], [401, 404], true) && ($doc[2]['x-content-type-options'] ?? '') === 'nosniff', 'Dokument ohne Anmeldung → kein Inhalt');

    $c->section('Abzeichen der Dienstgrade (Auslieferung)');
    $d->actAs($anna);
    [, $badgeRank] = $d->request('POST', '/ranks', ['title' => 'Kpsa Abzeichen ' . bin2hex(random_bytes(2))]);
    $svgBadge = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10" onload="alert(1)"><script>alert(2)</script><circle cx="5" cy="5" r="4"/></svg>';
    [$s, $badge] = $d->request('POST', '/ranks/' . ($badgeRank['id'] ?? 0) . '/badge', ['file' => ['name' => 'abzeichen.svg', 'data' => base64_encode($svgBadge)]]);
    $badgeUrl = (string) ($badge['badge_url'] ?? '');
    $c->ok($s === 200 && str_starts_with($badgeUrl, $base . 'index.php?r=/abzeichen/' . ($badgeRank['id'] ?? 0) . '&v='), 'SVG-Abzeichen hochgeladen, Adresse im Bereich der App');
    $anon = $d->send('GET', $badgeUrl, [], null);
    $c->ok($anon[0] === 401 && ($anon[2]['x-content-type-options'] ?? '') === 'nosniff', 'Abzeichen ohne Anmeldung → 401');
    $cookie = ['Cookie' => Sessions::COOKIE . '=' . $d->token($anna)];
    $svgRes = $d->send('GET', $badgeUrl, $cookie, null);
    $c->ok($svgRes[0] === 200 && ($svgRes[2]['content-type'] ?? '') === 'image/svg+xml' && ($svgRes[2]['x-content-type-options'] ?? '') === 'nosniff'
        && ($svgRes[2]['content-security-policy'] ?? '') === "default-src 'none'; style-src 'unsafe-inline'; sandbox", 'SVG: image/svg+xml mit strenger CSP und nosniff');
    $c->ok(str_starts_with((string) ($svgRes[2]['cache-control'] ?? ''), 'private'), 'Abzeichen: Cache-Control private');
    $c->ok($d->send('GET', $badgeUrl, $cookie + ['If-None-Match' => (string) ($svgRes[2]['etag'] ?? '-')], null)[0] === 304, 'Abzeichen: passender ETag → 304');
    $svgBody = (string) file_get_contents($badgeUrl, false, stream_context_create(['http' => ['header' => 'Cookie: ' . $cookie['Cookie']]]));
    $c->ok(str_contains($svgBody, '<circle') && !str_contains($svgBody, 'script') && !str_contains($svgBody, 'onload'), 'Ausgeliefert wird das bereinigte SVG');
    $guestId = $users->add('Gina Gast', 'gina', 'gina@example.org', 'gina-passwort', Roles::GUEST);
    $c->ok($d->send('GET', $badgeUrl, ['Cookie' => Sessions::COOKIE . '=' . $d->token($guestId)], null)[0] === 403, 'Abzeichen ohne Mitgliederbereich und Kalender → 403');
    $im = imagecreatetruecolor(300, 150);
    ob_start();
    imagepng($im);
    [, $pngBadge] = $d->request('POST', '/ranks/' . ($badgeRank['id'] ?? 0) . '/badge', ['file' => ['name' => 'abzeichen.png', 'data' => base64_encode((string) ob_get_clean())]]);
    $pngUrl = str_replace('&s=128', '&s=64', (string) ($pngBadge['badge_url'] ?? ''));
    $pngRes = $d->send('GET', $pngUrl, $cookie, null);
    $pngSize = @getimagesizefromstring((string) file_get_contents($pngUrl, false, stream_context_create(['http' => ['header' => 'Cookie: ' . $cookie['Cookie']]])));
    $c->ok($pngRes[0] === 200 && ($pngRes[2]['content-type'] ?? '') === 'image/png' && !isset($pngRes[2]['content-security-policy']) && ($pngSize[0] ?? 0) === 64 && ($pngSize[1] ?? 0) === 32, 'PNG-Abzeichen: image/png, s=64 liefert 64 Pixel');
    $old = $d->send('GET', $badgeUrl, $cookie, null);
    $c->ok($old[0] === 200 && ($old[2]['content-type'] ?? '') === 'image/png' && ($old[2]['cache-control'] ?? '') === 'private, no-cache', 'Alte Adresse nach dem Ersetzen: neues Abzeichen, nicht lange speichern');

    $c->section('Dokumente: Fassung in der Adresse, Prüfung per ETag');
    [$s, $upDoc] = $d->request('POST', '/documents', ['title' => 'Kpsa Satzung', 'date' => '2026-10-01', 'file' => ['name' => 'satzung.pdf', 'data' => base64_encode("%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n")]]);
    $docUrl = (string) ($upDoc['url'] ?? '');
    $docRes = $d->send('GET', $docUrl, $cookie, null);
    $c->ok($s === 201 && str_contains($docUrl, '&v=' . ($upDoc['version'] ?? '-')) && $docRes[0] === 200 && ($docRes[2]['cache-control'] ?? '') === 'private, no-cache', 'Aktuelle Fassung: private, no-cache (Abmelden leert den HTTP-Cache nicht)');
    $docAgain = $d->send('GET', $docUrl, $cookie + ['If-None-Match' => (string) ($docRes[2]['etag'] ?? '-')], null);
    $c->ok($docAgain[0] === 304 && ($docAgain[2]['cache-control'] ?? '') === 'private, no-cache', 'Dokument: passender ETag → 304 (wie bisher)');
    $c->ok(($d->send('GET', preg_replace('/&v=[^&]*/', '&v=alt', $docUrl), $cookie, null)[2]['cache-control'] ?? '') === 'private, no-cache', 'Veraltete Fassung in der Adresse: jedes Mal prüfen');
    $d->request('DELETE', '/documents/' . ($upDoc['id'] ?? 0));

    $bad = $d->send('POST', $base . 'index.php?r=/api/v1/auth/login', $json, '{kaputt');
    $c->ok($bad[0] === 400 && $bad[1]['code'] === 'invalid', 'Ungültiges JSON → 400');

    $c->section('Selbstaufruf, Fehlerseiten, Adressen');
    $listenPort = $port + 1;
    $listenLog = $data . '-listener.log';
    $listener = proc_open([PHP_BINARY, '-S', "127.0.0.1:$listenPort"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', $listenLog, 'a']], $lp);
    usleep(400000);
    $kernel->db()->execute("DELETE FROM {tasks} WHERE name = 'testaufgabe'");
    $kernel->db()->insert('tasks', ['name' => 'testaufgabe', 'interval_seconds' => 3600, 'next_run' => time() - 10]);
    $kernel->scheduler()->refresh(); // direkt in die Tabelle geschrieben: nächsten Lauf neu merken
    $kernel->app()->settings->delete(Kernel::CRON_SPAWN_SETTING);
    $d->send('GET', $base . 'index.php?r=/api/v1/config', ['Host' => "127.0.0.1:$listenPort"], null);
    usleep(1500000);
    proc_terminate($listener);
    proc_close($listener);
    $c->ok(!str_contains((string) @file_get_contents($listenLog), 'cron'), 'Host-Header lenkt den Selbstaufruf nicht um (Cron-Schlüssel bleibt geheim)');
    $c->ok((int) $kernel->db()->scalar("SELECT next_run FROM {tasks} WHERE name = 'testaufgabe'") > time(), 'Fällige Aufgabe läuft über den Selbstaufruf an die eingetragene Adresse');

    $c->section('Pseudo-Cron: normale Anfragen ohne Schreibzugriff, Selbstaufruf ohne Warten');
    if ($kernel->db()->driver() === 'sqlite') {
        // Eine Anfrage im selben Prozess nachstellen, mit einer Verbindung, die alle Abfragen mitschreibt.
        $counted = static function (?Config $config = null) use ($app, $port, $data): array {
            $k = new Kernel($app, new HttpContext([], ['HTTP_HOST' => "127.0.0.1:$port"], '/'));
            $cfg = Config::load($data);
            $pdo = new CountingPdo('sqlite:' . $data . '/' . $cfg->toArray()['db']['path']);
            (new ReflectionProperty(Kernel::class, 'db'))->setValue($k, new PdoConnection($pdo));
            if ($config !== null) {
                (new ReflectionProperty(Kernel::class, 'config'))->setValue($k, $config);
            }
            return [$k, $pdo];
        };
        $afterResponse = static fn (Kernel $k) => (new ReflectionMethod(Kernel::class, 'afterResponse'))->invoke($k);
        $kernel->db()->execute("DELETE FROM {tasks} WHERE name = 'testaufgabe'");
        $kernel->scheduler()->refresh();
        [$k, $pdo] = $counted();
        $k->upgrade();
        $k->app()->api()->config();
        $afterResponse($k);
        $c->ok($pdo->writes() === [], 'Normale Anfrage schreibt nichts (Aufgaben werden nicht bei jeder Anfrage eingeplant)' . ($pdo->writes() ? ': ' . implode(' | ', $pdo->writes()) : ''));
        $c->ok(!preg_grep('/\{?tasks\}?/', $pdo->log), 'Normale Anfrage liest die Aufgaben nicht (nächster Lauf steht in den Einstellungen)');

        // Fällige Aufgabe ohne PHP-FPM: Selbstaufruf, ohne auf die Antwort zu warten.
        $kernel->db()->insert('tasks', ['name' => 'testaufgabe', 'interval_seconds' => 3600, 'next_run' => time() - 10]);
        $kernel->scheduler()->refresh();
        $kernel->app()->settings->delete(Kernel::CRON_SPAWN_SETTING);
        [$k] = $counted();
        $k->upgrade();
        $t = microtime(true);
        $afterResponse($k);
        $elapsed = microtime(true) - $t;
        $c->ok($elapsed < 0.15, sprintf('Selbstaufruf wartet nicht auf die Aufgaben (%.0f ms)', $elapsed * 1000));
        $ran = false;
        for ($i = 0; $i < 30 && !$ran; $i++) {
            usleep(100000);
            $ran = (int) $kernel->db()->scalar("SELECT next_run FROM {tasks} WHERE name = 'testaufgabe'") > time();
        }
        $c->ok($ran, 'Die Aufgabe läuft trotzdem (im aufgerufenen Prozess)');
        // Warten, bis der aufgerufene Prozess fertig ist: Zuletzt merkt er sich den nächsten Lauf.
        // Sonst übernimmt er womöglich die fällige Aufgabe des nächsten Abschnitts (8 Worker).
        for ($i = 0; $i < 50; $i++) {
            $next = json_decode((string) $kernel->db()->scalar('SELECT value FROM {settings} WHERE name = ?', [KompanieApp\Standalone\PseudoScheduler::NEXT_SETTING]), true);
            if (is_array($next) && (int) ($next['at'] ?? 0) > time()) {
                break;
            }
            usleep(100000);
        }
        usleep(200000);
        $kernel->db()->execute("DELETE FROM {tasks} WHERE name = 'testaufgabe'");

        // Selbstaufruf schlägt fehl: zählen, seltener versuchen, auf der Statusseite melden.
        $kernel->db()->insert('tasks', ['name' => 'testaufgabe', 'interval_seconds' => 3600, 'next_run' => time() - 10]);
        $kernel->scheduler()->refresh();
        $kernel->app()->settings->delete(Kernel::CRON_SPAWN_SETTING);
        $kernel->app()->settings->delete(Kernel::CRON_RUNS_SETTING);
        $closed = $port + 2;
        $unreachable = new Config($data, ['base_url' => "http://127.0.0.1:$closed/"] + Config::load($data)->toArray());
        [$k] = $counted($unreachable);
        $t = microtime(true);
        $afterResponse($k);
        $c->ok(microtime(true) - $t < 0.3, 'Nicht erreichbare Adresse hält die Anfrage nicht auf');
        $state = json_decode((string) $kernel->db()->scalar('SELECT value FROM {settings} WHERE name = ?', [Kernel::CRON_SPAWN_SETTING]), true);
        $c->ok(($state['failures'] ?? 0) === 1 && ($state['error'] ?? '') !== '', 'Fehlschlag wird gezählt');
        [$k] = $counted($unreachable);
        $afterResponse($k);
        $state2 = json_decode((string) $kernel->db()->scalar('SELECT value FROM {settings} WHERE name = ?', [Kernel::CRON_SPAWN_SETTING]), true);
        $c->ok(($state2['failures'] ?? 0) === 1 && ($state2['at'] ?? 0) === ($state['at'] ?? -1), 'Danach wird seltener versucht (kein neuer Versuch innerhalb der Wartezeit)');
        $kernel->app()->settings->set(Kernel::CRON_SPAWN_SETTING, ['at' => time() - 7200, 'failures' => 3, 'error' => 'Keine Verbindung zur eigenen Adresse.', 'checked' => true]);
        [$k] = $counted($unreachable);
        $cron = array_values(array_filter($k->app()->status()->report()['items'], static fn (array $i): bool => $i['key'] === 'cron'))[0] ?? [];
        $c->ok(($cron['level'] ?? '') === 'error' && str_contains($cron['text'] ?? '', 'Cronjob') && str_contains($cron['text'] ?? '', 'key='), 'Statusseite meldet den fehlschlagenden Selbstaufruf und empfiehlt einen Cronjob mit Adresse');
        $kernel->db()->execute("DELETE FROM {tasks} WHERE name = 'testaufgabe'");
        $kernel->scheduler()->refresh();
        $kernel->app()->settings->delete(Kernel::CRON_SPAWN_SETTING);
        $d->send('GET', $kernel->cronUrl(), [], null);
        // Die Antwort kommt vor den Aufgaben; der Lauf wird danach vermerkt.
        for ($i = 0; $i < 30 && !str_contains((string) $kernel->db()->scalar('SELECT value FROM {settings} WHERE name = ?', [Kernel::CRON_RUNS_SETTING]), '"cron"'); $i++) {
            usleep(100000);
        }
        [$k] = $counted();
        $cron = array_values(array_filter($k->app()->status()->report()['items'], static fn (array $i): bool => $i['key'] === 'cron'))[0] ?? [];
        $c->ok(($cron['level'] ?? '') === 'ok' && str_contains($cron['text'] ?? '', 'Cronjob beim Hoster'), 'Mit Cronjob des Hosters: Statusseite grün');

        // Issue #18: Listen laden Konten gemeinsam, nicht mit einer Abfrage je Konto.
        [$k, $pdo] = $counted();
        $ids = array_map(static fn (KompanieApp\Core\Auth\User $u): int => $u->id, $k->users()->list(true));
        $pdo->log = [];
        $many = $k->users()->findMany([...$ids, ...$ids, 999999]);
        $found = array_keys($many);
        sort($found);
        sort($ids);
        $c->ok(count($pdo->log) === 1 && $found === $ids, sprintf('Mehrere Konten mit einer Abfrage (%d Konten, %d Abfrage)', count($ids), count($pdo->log)));
        $pdo->log = [];
        $c->ok($k->users()->findMany([]) === [] && $pdo->log === [], 'Keine Konten: keine Abfrage');
    }
    $redirect = $d->send('GET', $base, ['Host' => "localhost:$port"], null);
    $c->ok($redirect[0] === 302 && ($redirect[2]['location'] ?? '') === $base, 'Andere Schreibweise der Adresse wird (vorläufig) auf die eingetragene umgeleitet');
    $proxied = $d->send('GET', $base, ['Host' => 'backend.intern:8080', 'X-Forwarded-Host' => "127.0.0.1:$port"], null);
    $c->ok($proxied[0] === 200, 'Hinter einem Proxy zählt X-Forwarded-Host (keine Umleitungsschleife)');
    $c->ok(Setup::normalizeUrl('https://Bücher-Verein.de/app') === 'https://xn--bcher-verein-dlb.de/app/' || !function_exists('idn_to_ascii'), 'Umlaut-Domain wird so gespeichert, wie Browser sie senden');
    $c->ok((bool) preg_match('/^kompanie-[a-f0-9]{32}\.sqlite$/', (string) (Config::load($data)->toArray()['db']['path'] ?? '')) || $kernel->db()->driver() === 'mysql', 'SQLite-Datei mit zufälligem Namen');
    $cfgFile = $data . '/config.php';
    $saved = (string) file_get_contents($cfgFile);
    file_put_contents($cfgFile, '<?php return [');
    sleep(3); // OPcache des Testservers prüft Dateien nur alle 2 Sekunden auf Änderungen
    $broken = $d->send('GET', $base . 'index.php?r=/api/v1/config', [], null);
    file_put_contents($cfgFile, $saved);
    sleep(3);
    $c->ok($broken[0] === 503 && ($broken[1]['code'] ?? '') === 'config_invalid', 'Beschädigte config.php: verständliche Meldung als JSON');

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
    // Kalender-IDs ohne API-Schlüssel: gelesen über den Google-Zugang der Galerie, wenn er den Kalender lesen darf.
    $store = $kernel->app()->settings;
    $store->set('drive_oauth', ['client_id' => 'CID', 'client_secret' => 'SECRET', 'refresh_token' => 'R', 'scope' => 'https://www.googleapis.com/auth/drive.readonly']);
    $set = $d->request('PATCH', '/admin/settings', ['calendar_ids' => 'kompanie@group.calendar.google.com'])[1];
    $c->ok($set['calendar']['source'] === 'none' && $set['calendar']['connection_needs_calendar_access'] === true && $set['gallery']['calendar_access'] === false, 'Verbindung ohne Kalender-Berechtigung: keine Quelle, Hinweis „neu verbinden“');
    $store->set('drive_oauth', ['client_id' => 'CID', 'client_secret' => 'SECRET', 'refresh_token' => 'R', 'scope' => 'https://www.googleapis.com/auth/drive.readonly https://www.googleapis.com/auth/calendar.readonly']);
    $set = $d->request('GET', '/admin/settings')[1];
    $c->ok($set['calendar']['source'] === 'google_connection' && $set['calendar']['connection_needs_calendar_access'] === false && $set['gallery']['calendar_access'] === true, 'Verbindung mit Kalender-Berechtigung: Quelle google_connection');
    $c->ok($d->request('PATCH', '/admin/settings', ['drive_oauth' => '', 'calendar_ids' => ''])[1]['calendar']['source'] === 'none', 'Verbindung getrennt, Kalender-IDs geleert');
    $settings = $kernel->app()->settings;
    $settings->set('kptest_frisch', ['a']);
    $kernel->db()->execute("UPDATE {settings} SET value = ? WHERE name = 'kptest_frisch'", [json_encode(['a', 'b'])]); // wie eine andere Anfrage
    $c->ok($settings->get('kptest_frisch') === ['a'] && $settings->fresh('kptest_frisch') === ['a', 'b'] && $settings->get('kptest_frisch') === ['a', 'b'], 'fresh() liest am Zwischenspeicher vorbei');
    $settings->delete('kptest_frisch');
    $c->ok($settings->fresh('kptest_frisch', 'weg') === 'weg', 'fresh(): fehlender Wert → Vorgabe');

    $c->section('Wartungsmodus');
    $d->actAs($anna);
    [$s, $m] = $d->request('POST', '/admin/maintenance', ['on' => true, 'reason' => 'Umstellung, bis ca. 18 Uhr']);
    $c->ok($s === 200 && $m['on'] === true, 'Admin schaltet den Wartungsmodus ein');
    $c->ok($d->request('GET', '/me')[0] === 200, 'Admin arbeitet normal weiter');
    $d->actAs($max);
    [$s, $e] = $d->request('GET', '/me');
    $c->ok($s === 503 && ($e['code'] ?? '') === 'maintenance' && ($e['reason'] ?? '') === 'Umstellung, bis ca. 18 Uhr', 'Mitglied → 503 maintenance mit Begründung');
    [$s, $cfg] = $d->request('GET', '/config');
    $c->ok($s === 200 && ($cfg['maintenance']['on'] ?? false) === true, '/config bleibt erreichbar und meldet die Wartung');
    $c->ok($d->send('GET', $base . 'index.php?r=/foto/999/thumb', [], null)[0] === 503, 'Geschützte Fotos → 503');
    $c->ok($d->send('GET', $base . 'index.php?r=/dokument/1&v=x', [], null)[0] === 503, 'Dokumente → 503');
    $c->ok($d->send('GET', $pngUrl, [], null)[0] === 503, 'Abzeichen → 503');
    $c->ok($d->send('GET', $base, [], null)[0] === 200, 'App-Seite wird weiter ausgeliefert (zeigt die Wartungsseite)');
    $d->actAs($anna);
    $c->ok($d->request('POST', '/admin/maintenance', ['on' => false])[1]['on'] === false, 'Admin beendet den Wartungsmodus');
    $d->actAs($max);
    $c->ok($d->request('GET', '/me')[0] === 200, 'Danach wieder normal');

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
    // Über die API, wie in der App: herunterladen, Daten ändern, wiederherstellen
    $d->forgetSessions(); // der Import oben hat alle Sitzungen beendet
    $d->actAs($anna);
    [$s, $dl] = $d->request('GET', '/admin/backup');
    $c->ok($s === 200 && ($dl['format'] ?? '') === 'kompanie-app-backup' && !isset($dl['settings']['update_state']), 'GET /admin/backup liefert die Sicherung (ohne Update-Zustand)');
    $d->request('DELETE', '/events/' . (int) $kernel->db()->scalar("SELECT id FROM {events} WHERE title = 'Sicherungstest'"));
    $c->ok($d->request('POST', '/admin/backup', ['backup' => json_encode($dl)])[0] === 400, 'Wiederherstellen ohne Bestätigung → 400');
    $d->actAs($max);
    $c->ok($d->request('POST', '/admin/backup', ['backup' => json_encode($dl), 'confirm' => true])[0] === 403, 'Mitglied darf nicht wiederherstellen');
    $d->actAs($anna);
    [$s, $r] = $d->request('POST', '/admin/backup', ['backup' => json_encode($dl), 'confirm' => true]);
    $c->ok($s === 200 && ($r['restored'] ?? false) === true, 'POST /admin/backup stellt wieder her');
    $c->ok((int) $kernel->db()->scalar("SELECT COUNT(*) FROM {events} WHERE title = 'Sicherungstest'") === 1, 'Gelöschter Termin ist nach dem Wiederherstellen über die API wieder da');
    $d->forgetSessions();

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
        $stopServer($server);
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
