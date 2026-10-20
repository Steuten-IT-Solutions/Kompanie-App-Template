<?php
/**
 * Installer der Kompanie-App (eigenständige Version).
 *
 * Diese eine Datei in den Ordner hochladen, in dem die App laufen soll (z. B. /app/), und im
 * Browser öffnen. Sie prüft den Server, lädt die App (signiert geprüft), legt die Datenbank an,
 * richtet den ersten Admin ein und löscht sich danach selbst. Erneut hochgeladen erkennt sie eine
 * bestehende Installation und bietet „Aktualisieren / Reparieren“ an (nur für Admins).
 *
 * Bewusst in einfacher PHP-Syntax geschrieben, damit auch ein zu alter Server noch eine
 * verständliche Meldung zeigt statt eines Syntaxfehlers.
 */

// Wird beim Bauen je Kompanie eingesetzt (scripts/package.php).
$KONFIG = /*KOMPANIE_INSTALLER_CONFIG*/array(
    'name' => 'Kompanie-App',
    'updatesUrl' => '',
    'kompanie' => '',
    'trustedKeys' => array(),
    'primary' => '#2e6b3f',
    'downloadPage' => '',
    'support' => '',
)/*KOMPANIE_INSTALLER_CONFIG_ENDE*/;

ini_set('display_errors', '0');
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

define('KI_DIR', __DIR__);
define('KI_MIN_PHP', '8.1.0');

function ki_h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function ki_page($title, $body)
{
    global $KONFIG;
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', $KONFIG['primary']) ? $KONFIG['primary'] : '#2e6b3f';
    // Hilfe-Kontakt: E-Mail-Adresse oder https-Adresse als Link, sonst nur als Text
    $support = isset($KONFIG['support']) ? trim((string) $KONFIG['support']) : '';
    $href = filter_var($support, FILTER_VALIDATE_EMAIL) ? 'mailto:' . $support : (preg_match('#^https://#i', $support) ? $support : '');
    $help = $support === '' ? '' : '<p class="hint">Hilfe: ' . ($href !== '' ? '<a href="' . ki_h($href) . '">' . ki_h($support) . '</a>' : ki_h($support)) . '</p>';
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . ki_h($title . ' – ' . $KONFIG['name']) . '</title><style>'
        . 'body{margin:0;font:16px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;background:#f4f5f3;color:#1b1d21}'
        . 'header{background:' . $color . ';color:#fff;padding:22px 16px}header h1{margin:0 auto;max-width:720px;font-size:1.4rem}'
        . 'main{max-width:720px;margin:0 auto;padding:20px 16px 40px}.card{background:#fff;border:1px solid #dcdfd9;border-radius:12px;padding:18px 20px;margin:0 0 16px}'
        . 'h2{font-size:1.15rem;margin:0 0 10px}label{display:block;margin:12px 0 4px;font-weight:600}input,select{width:100%;box-sizing:border-box;padding:10px;border:1px solid #b9beb6;border-radius:8px;font:inherit}'
        . '.btn{display:inline-block;margin-top:16px;background:' . $color . ';color:#fff;border:0;border-radius:9px;padding:12px 20px;font:inherit;font-weight:700;cursor:pointer;text-decoration:none}'
        . '.ok{color:#1f6b3a}.bad{color:#a3301a;font-weight:600}.hint{color:#5d6168;font-size:.92rem}ul.checks{list-style:none;padding:0;margin:0}ul.checks li{padding:6px 0;border-bottom:1px solid #eceee9}'
        . '.error{background:#fbe9e4;border:1px solid #e9b7a8;color:#7a2412;border-radius:10px;padding:12px 14px;margin:0 0 16px}details{margin-top:12px}code{background:#eceee9;padding:1px 5px;border-radius:4px}'
        . '</style></head><body><header><h1>' . ki_h($KONFIG['name']) . ' – ' . ki_h($title) . '</h1></header><main>' . $body . $help . '</main></body></html>';
    exit;
}

// ---------------------------------------------------------------- Voraussetzungen

if (version_compare(PHP_VERSION, KI_MIN_PHP, '<')) {
    ki_page('Server prüfen', '<div class="card"><h2>Die PHP-Version ist zu alt</h2><p>Die App braucht PHP 8.1 oder neuer. Auf diesem Server läuft PHP ' . ki_h(PHP_VERSION) . '.</p>'
        . '<p>Die PHP-Version lässt sich bei fast allen Hostern im Kundenmenü umstellen (oft unter „PHP-Einstellungen“ oder „PHP-Version“). Danach diese Seite neu laden.</p></div>');
}

function ki_checks()
{
    $c = array();
    $c[] = array('PHP-Version ' . PHP_VERSION . ' (mindestens 8.1)', version_compare(PHP_VERSION, '8.1', '>='), 'Die App braucht PHP 8.1 oder neuer. Die PHP-Version lässt sich meist im Kundenmenü des Hosters umstellen.');
    $db = extension_loaded('pdo_sqlite') || extension_loaded('pdo_mysql');
    $c[] = array('Datenbank (SQLite oder MySQL)', $db, 'Bitte beim Hoster die PHP-Erweiterung „pdo_sqlite“ einschalten (oder „pdo_mysql“ und eine MySQL-Datenbank anlegen).');
    foreach (array('sodium' => 'Signaturen prüfen', 'zip' => 'Pakete entpacken', 'openssl' => 'Verbindung zu Google', 'mbstring' => 'Umlaute', 'json' => 'Daten', 'gd' => 'Galeriebilder verkleinern', 'simplexml' => 'News-Feeds') as $ext => $why) {
        $c[] = array('PHP-Erweiterung ' . $ext . ' (' . $why . ')', extension_loaded($ext), 'Bitte beim Hoster die PHP-Erweiterung „' . $ext . '“ einschalten (meist im Kundenmenü unter PHP-Einstellungen).');
    }
    $c[] = array('Verbindungen nach außen (cURL)', function_exists('curl_init') || ini_get('allow_url_fopen'), 'Bitte beim Hoster „curl“ oder „allow_url_fopen“ einschalten.');
    $c[] = array('Schreibrechte in diesem Ordner', is_writable(KI_DIR), 'PHP darf in diesem Ordner keine Dateien anlegen. Bitte im Dateimanager des Hosters die Rechte des Ordners auf 755 setzen.');
    $free = @disk_free_space(KI_DIR);
    $c[] = array('Speicherplatz (mindestens 50 MB frei)', !is_float($free) || $free > 50 * 1048576, 'Auf dem Webspace ist zu wenig Platz frei.');
    return $c;
}

function ki_checks_ok($checks)
{
    foreach ($checks as $c) {
        if (!$c[1]) {
            return false;
        }
    }
    return true;
}

function ki_checks_html($checks)
{
    $out = '<ul class="checks">';
    foreach ($checks as $c) {
        $out .= '<li>' . ($c[1] ? '<span class="ok">✓</span> ' : '<span class="bad">✗</span> ') . ki_h($c[0]) . ($c[1] ? '' : '<br><span class="hint">' . ki_h($c[2]) . '</span>') . '</li>';
    }
    return $out . '</ul>';
}

// ---------------------------------------------------------------- Hilfen

function ki_base_url()
{
    $https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/install.php')), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $dir . '/';
}

function ki_http_get($url, $timeout)
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_USERAGENT => 'Kompanie-App-Installer', CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS));
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new RuntimeException('Keine Verbindung: ' . $error);
        }
        return array($status, $body);
    }
    $ctx = stream_context_create(array('http' => array('timeout' => $timeout, 'ignore_errors' => true, 'user_agent' => 'Kompanie-App-Installer')));
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        throw new RuntimeException('Keine Verbindung.');
    }
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
        $status = (int) $m[1];
    }
    return array($status, $body);
}

/**
 * Lädt die Update-Liste, prüft die Signatur und gibt den Eintrag der stabilen Version zurück.
 *
 * @param string|null $installed installierte Version (Reparaturmodus): dann die neueste freigegebene Version
 *                               derselben Hauptversion, nie ein Wechsel der Hauptversion. Leer = unbekannt
 *                               (Datei VERSION beschädigt): nur, wenn alle freigegebenen Versionen eine
 *                               einzige Hauptversion haben.
 */
function ki_stable_entry($installed = null)
{
    global $KONFIG;
    if ($KONFIG['updatesUrl'] === '' || !$KONFIG['trustedKeys']) {
        throw new RuntimeException('Dieser Installer ist nicht vollständig (keine Update-Adresse). Bitte die Datei neu von der Download-Seite laden.');
    }
    $r = ki_http_get($KONFIG['updatesUrl'], 20);
    if ($r[0] !== 200) {
        throw new RuntimeException('Die Liste der Versionen ist nicht erreichbar (HTTP ' . $r[0] . '). Darf der Server Verbindungen nach außen aufbauen?');
    }
    $env = json_decode($r[1], true);
    if (!is_array($env) || !isset($env['payload'], $env['signature'], $env['keyId']) || !isset($KONFIG['trustedKeys'][$env['keyId']])) {
        throw new RuntimeException('Die Liste der Versionen ist nicht mit unserem Schlüssel signiert.');
    }
    $bytes = base64_decode($env['payload'], true);
    $sig = base64_decode($env['signature'], true);
    $key = base64_decode($KONFIG['trustedKeys'][$env['keyId']], true);
    if ($bytes === false || $sig === false || $key === false || strlen($sig) !== 64 || strlen($key) !== 32 || !sodium_crypto_sign_verify_detached($sig, $bytes, $key)) {
        throw new RuntimeException('Die Signatur der Liste ist ungültig. Bitte später erneut versuchen und sonst bei uns melden.');
    }
    $list = json_decode($bytes, true);
    if (!is_array($list) || !isset($list['type']) || $list['type'] !== 'updates' || !isset($list['kompanie']) || $list['kompanie'] !== $KONFIG['kompanie']) {
        throw new RuntimeException('Die Liste der Versionen gehört nicht zu dieser Kompanie. Bitte den Installer neu von der Download-Seite laden.');
    }
    $stable = isset($list['channels']['stable']) ? $list['channels']['stable'] : null;
    $withdrawn = isset($list['withdrawn']) ? (array) $list['withdrawn'] : array();
    if ($stable !== null && in_array($stable, $withdrawn, true)) {
        $stable = null;
    }
    if ($installed !== null) {
        $history = isset($list['stableHistory']) ? (array) $list['stableHistory'] : array();
        if (!preg_match('/^(\d+)\.\d+\.\d+(\.\d+)?$/', $installed, $m)) {
            // Installierte Version unbekannt: nur weiter, wenn es keine Wahl der Hauptversion gibt. Gezählt wird
            // alles, was je veröffentlicht wurde (die Liste der Versionen ist gekürzt, die Historie nicht; auch auf
            // zurückgezogenen Versionen können noch Installationen laufen).
            $majors = array();
            $all = array_merge($history, $withdrawn);
            foreach (isset($list['versions']) ? $list['versions'] : array() as $e) {
                if (isset($e['version'])) {
                    $all[] = $e['version'];
                }
            }
            foreach ($all as $v) {
                if (is_string($v) && preg_match('/^\d+\./', $v)) {
                    $majors[(int) $v] = true;
                }
            }
            if (count($majors) === 0) {
                throw new RuntimeException('Es gibt noch keine freigegebene Version. Bitte später erneut versuchen.');
            }
            if (count($majors) !== 1) {
                throw new RuntimeException('Die installierte Version ist nicht zu erkennen (Datei VERSION fehlt oder ist beschädigt), und es gibt mehrere Hauptversionen. Bitte bei uns melden, damit nichts Falsches eingespielt wird.');
            }
            $m = array(1 => (string) key($majors));
        }
        // Neueste freigegebene (stableHistory), nicht zurückgezogene Version der installierten Hauptversion.
        $stable = null;
        foreach (isset($list['versions']) ? $list['versions'] : array() as $e) {
            if (isset($e['version']) && strpos($e['version'], $m[1] . '.') === 0 && in_array($e['version'], $history, true) && !in_array($e['version'], $withdrawn, true)) {
                $stable = $e['version'];
                break;
            }
        }
        if ($stable === null) {
            throw new RuntimeException('Für die installierte Hauptversion ' . $m[1] . ' gibt es gerade keine freigegebene Version zum Einspielen. Bitte bei uns melden; eine neue Hauptversion wird nur in der App unter Einstellungen → Updates eingespielt (dort wird vorher gesichert).');
        }
    }
    foreach (isset($list['versions']) ? $list['versions'] : array() as $e) {
        if ($stable !== null && $e['version'] === $stable && isset($e['files']['standalone']['url'], $e['files']['standalone']['sha256'])) {
            if (isset($e['minPhp']) && version_compare(PHP_VERSION, (string) $e['minPhp'], '<')) {
                throw new RuntimeException('Version ' . $e['version'] . ' braucht PHP ' . $e['minPhp'] . '; hier läuft PHP ' . PHP_VERSION . '. Die PHP-Version lässt sich meist im Kundenmenü des Hosters umstellen.');
            }
            return $e;
        }
    }
    throw new RuntimeException('Es gibt noch keine freigegebene Version. Bitte später erneut versuchen.');
}

/** Lädt das Paket, prüft SHA-256 und Größe, entpackt es in einen Arbeitsordner; gibt dessen Programmordner zurück. */
function ki_fetch_package($entry)
{
    $f = $entry['files']['standalone'];
    if (strpos($f['url'], 'https://') !== 0 && !preg_match('#^http://(127\.0\.0\.1|localhost)[:/]#', $f['url'])) {
        throw new RuntimeException('Ungültige Download-Adresse.');
    }
    $r = ki_http_get($f['url'], 180);
    if ($r[0] !== 200) {
        throw new RuntimeException('Das Paket ließ sich nicht laden (HTTP ' . $r[0] . ').');
    }
    if (!hash_equals($f['sha256'], hash('sha256', $r[1])) || (isset($f['size']) && (int) $f['size'] !== strlen($r[1]))) {
        throw new RuntimeException('Das geladene Paket stimmt nicht mit der signierten Liste überein und wird nicht verwendet.');
    }
    $zipFile = KI_DIR . '/.paket-' . bin2hex(random_bytes(6)) . '.zip';
    file_put_contents($zipFile, $r[1]);
    $work = KI_DIR . '/.neu-' . bin2hex(random_bytes(6));
    $zip = new ZipArchive();
    try {
        if ($zip->open($zipFile) !== true) {
            throw new RuntimeException('Das Paket lässt sich nicht öffnen.');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if ($name === '' || $name[0] === '/' || strpos($name, '\\') !== false || preg_match('#(^|/)\.\.(/|$)#', $name) || strpos($name, 'kompanie-app/') !== 0) {
                throw new RuntimeException('Das Paket enthält ungültige Pfade.');
            }
        }
        @mkdir($work, 0755, true);
        if (!$zip->extractTo($work)) {
            throw new RuntimeException('Das Paket lässt sich nicht entpacken (Speicherplatz?).');
        }
        $zip->close();
    } catch (RuntimeException $e) {
        ki_remove($work);
        throw $e;
    } finally {
        @unlink($zipFile);
    }
    if (trim((string) @file_get_contents($work . '/kompanie-app/VERSION')) !== $entry['version']) {
        ki_remove($work);
        throw new RuntimeException('Das Paket ist unvollständig.');
    }
    return $work;
}

/** Verschiebt die Programmdateien aus dem Arbeitsordner an ihren Platz (vorhandene werden ersetzt). */
function ki_place($work)
{
    $src = $work . '/kompanie-app';
    $aside = KI_DIR . '/.alt-' . bin2hex(random_bytes(6));
    @mkdir($aside, 0755);
    $moved = array();
    $placed = array();
    try {
        foreach (scandir($src) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (file_exists(KI_DIR . '/' . $name)) {
                if (!@rename(KI_DIR . '/' . $name, $aside . '/' . $name)) {
                    throw new RuntimeException('„' . $name . '“ lässt sich nicht ersetzen (Dateirechte).');
                }
                $moved[] = $name;
            }
            if (!@rename($src . '/' . $name, KI_DIR . '/' . $name)) {
                throw new RuntimeException('„' . $name . '“ lässt sich nicht anlegen (Dateirechte oder Speicherplatz).');
            }
            $placed[] = $name;
        }
    } catch (RuntimeException $e) {
        foreach (array_reverse($placed) as $name) {
            ki_remove(KI_DIR . '/' . $name);
        }
        foreach (array_reverse($moved) as $name) {
            @rename($aside . '/' . $name, KI_DIR . '/' . $name);
        }
        ki_remove($aside);
        throw $e;
    }
    ki_remove($aside);
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
}

function ki_remove($path)
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) as $e) {
        if ($e !== '.' && $e !== '..') {
            ki_remove($path . '/' . $e);
        }
    }
    @rmdir($path);
}

function ki_data_dir()
{
    $data = KI_DIR . '/data';
    if (is_file(KI_DIR . '/data-dir.php')) {
        $d = include KI_DIR . '/data-dir.php';
        $data = is_string($d) && $d !== '' ? rtrim($d, '/') : $data;
    }
    return $data;
}

function ki_installed()
{
    return is_file(ki_data_dir() . '/config.php');
}

/** Verbindung zur Datenbank der Installation: array(PDO, Tabellen-Präfix). */
function ki_db()
{
    $config = include ki_data_dir() . '/config.php';
    if (!is_array($config) || !isset($config['db'])) {
        throw new RuntimeException('data/config.php ist beschädigt. Bitte bei uns melden.');
    }
    $db = $config['db'];
    if (isset($db['driver']) && $db['driver'] === 'mysql') {
        $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], isset($db['port']) ? (int) $db['port'] : 3306, $db['name']), $db['user'], $db['password']);
        $prefix = isset($db['prefix']) && preg_match('/^[A-Za-z0-9_]*$/', $db['prefix']) ? $db['prefix'] : 'kompanie_';
    } else {
        $file = isset($db['path']) ? $db['path'] : 'kompanie.sqlite';
        $pdo = new PDO('sqlite:' . ($file[0] === '/' ? $file : ki_data_dir() . '/' . $file));
        $prefix = '';
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return array($pdo, $prefix);
}

/**
 * Prüft die Anmeldung eines Admins direkt in der Datenbank – ohne den Programmcode, der bei
 * einer Reparatur beschädigt sein kann.
 */
function ki_admin_ok($login, $password)
{
    list($pdo, $prefix) = ki_db();
    $stmt = $pdo->prepare('SELECT password_hash, level FROM ' . $prefix . 'users WHERE LOWER(login) = LOWER(?) OR (email <> \'\' AND LOWER(email) = LOWER(?))');
    $stmt->execute(array($login, $login));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['level'] === 'admin' && password_verify($password, $row['password_hash'])) {
            return true;
        }
    }
    return false;
}

/**
 * Installierte Version laut Datenbank, falls die Datei VERSION fehlt oder beschädigt ist: die Version,
 * die die App zuletzt selbst als laufend eingetragen hat (App::VERSION_SETTING); sonst (ältere Versionen
 * ohne diesen Eintrag) das letzte Update: bei Erfolg die neue, sonst die alte Version. Leer, wenn unbekannt.
 */
function ki_db_version()
{
    try {
        list($pdo, $prefix) = ki_db();
        $stmt = $pdo->prepare('SELECT name, value FROM ' . $prefix . "settings WHERE name IN ('running_version', 'update_last')");
        $stmt->execute();
        $rows = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[$r['name']] = json_decode((string) $r['value'], true);
        }
    } catch (Exception $e) {
        return '';
    }
    if (isset($rows['running_version']) && is_string($rows['running_version'])) {
        return $rows['running_version'];
    }
    $last = isset($rows['update_last']) ? $rows['update_last'] : null;
    if (!is_array($last)) {
        return '';
    }
    $v = !empty($last['ok']) ? (isset($last['to']) ? $last['to'] : '') : (isset($last['from']) ? $last['from'] : '');
    return is_string($v) ? $v : '';
}

/**
 * Liegen hier schon fremde Dateien (z. B. eine bestehende Webseite)? Dann nicht installieren,
 * sondern um einen eigenen Ordner bitten. Reste eines abgebrochenen Versuchs (unsere Dateien) sind ok.
 */
function ki_foreign_files()
{
    $ours = is_file(KI_DIR . '/VERSION') && strpos((string) @file_get_contents(KI_DIR . '/autoload.php'), 'KompanieApp') !== false;
    if ($ours) {
        return array();
    }
    $found = array();
    foreach (scandir(KI_DIR) as $e) {
        if ($e !== '.' && $e !== '..' && $e !== basename(__FILE__) && strpos($e, '.neu-') !== 0 && strpos($e, '.alt-') !== 0 && $e !== '.well-known') {
            $found[] = $e;
        }
    }
    return $found;
}

function ki_wordpress()
{
    foreach (array(KI_DIR, dirname(KI_DIR)) as $dir) {
        if (is_file($dir . '/wp-config.php') || is_file($dir . '/wp-load.php')) {
            return true;
        }
    }
    return false;
}

function ki_csrf()
{
    if (empty($_COOKIE['ki_csrf']) || !preg_match('/^[a-f0-9]{32}$/', $_COOKIE['ki_csrf'])) {
        $t = bin2hex(random_bytes(16));
        setcookie('ki_csrf', $t, 0, '', '', !empty($_SERVER['HTTPS']), true);
        $_COOKIE['ki_csrf'] = $t;
    }
    return $_COOKIE['ki_csrf'];
}

function ki_csrf_ok()
{
    return !empty($_COOKIE['ki_csrf']) && isset($_POST['csrf']) && is_string($_POST['csrf']) && hash_equals($_COOKIE['ki_csrf'], $_POST['csrf']);
}

function ki_self_delete()
{
    return @unlink(__FILE__);
}

/** Ob eine Adresse von außen Inhalt liefert (für den Schutz-Test des Datenverzeichnisses). */
function ki_reachable($url)
{
    try {
        $r = ki_http_get($url, 8);
        return $r[0] === 200 && $r[1] !== '';
    } catch (RuntimeException $e) {
        return false;
    }
}

function ki_post($key, $default = '')
{
    return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : $default;
}

// ---------------------------------------------------------------- Ablauf

$checks = ki_checks();
$csrf = ki_csrf();
$err = '';

if (ki_wordpress()) {
    ki_page('WordPress gefunden', '<div class="card"><h2>Hier läuft WordPress</h2><p>Für Webseiten mit WordPress gibt es die App als Plugin. Das ist einfacher und nutzt die Benutzer von WordPress.</p>'
        . '<ol><li>Auf der Download-Seite das Plugin herunterladen.</li><li>In WordPress unter <em>Plugins → Neues Plugin hinzufügen → Plugin hochladen</em> installieren und aktivieren.</li><li>Diese Datei <code>install.php</code> wieder löschen.</li></ol>'
        . ($GLOBALS['KONFIG']['downloadPage'] !== '' ? '<a class="btn" href="' . ki_h($GLOBALS['KONFIG']['downloadPage']) . '">Zur Download-Seite</a>' : '') . '</div>');
}

// Bestehende Installation: nur Aktualisieren/Reparieren, nur für Admins.
if (ki_installed()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            if (!ki_csrf_ok()) {
                throw new RuntimeException('Die Seite war zu lange offen. Bitte neu laden und erneut versuchen.');
            }
            $lock = ki_data_dir() . '/installer-versuche.json';
            $tries = json_decode((string) @file_get_contents($lock), true);
            $tries = is_array($tries) && isset($tries['since']) && $tries['since'] > time() - 900 ? $tries : array('since' => time(), 'n' => 0);
            if ($tries['n'] >= 10) {
                throw new RuntimeException('Zu viele Fehlversuche. Bitte in 15 Minuten erneut versuchen.');
            }
            if (!ki_admin_ok(ki_post('login'), isset($_POST['password']) ? (string) $_POST['password'] : '')) {
                $tries['n']++;
                file_put_contents($lock, json_encode($tries));
                sleep(1);
                throw new RuntimeException('Benutzername oder Passwort ist falsch, oder das Konto ist kein Admin.');
            }
            @unlink($lock);
            $installedVersion = trim((string) @file_get_contents(KI_DIR . '/VERSION'));
            if (!preg_match('/^\d+\.\d+\.\d+(\.\d+)?$/', $installedVersion)) {
                $installedVersion = ki_db_version(); // Datei VERSION fehlt oder ist beschädigt
            }
            $entry = ki_stable_entry($installedVersion);
            $work = ki_fetch_package($entry);
            try {
                ki_place($work);
            } finally {
                ki_remove($work);
            }
            $deleted = ki_self_delete();
            ki_page('Fertig', '<div class="card"><h2>Version ' . ki_h($entry['version']) . ' ist eingespielt</h2><p>Die Daten sind unverändert. Die Datenbank wird beim nächsten Aufruf bei Bedarf ergänzt.</p>'
                . ($deleted ? '' : '<p class="bad">Bitte die Datei install.php jetzt im Dateimanager löschen.</p>') . '<a class="btn" href="./">Zur App</a></div>');
        } catch (Exception $e) {
            $err = $e->getMessage();
        } catch (Error $e) {
            $err = 'Unerwarteter Fehler: ' . $e->getMessage();
        }
    }
    ki_page('Aktualisieren oder reparieren', ($err !== '' ? '<div class="error">' . ki_h($err) . '</div>' : '')
        . '<div class="card"><h2>Die App ist hier schon installiert</h2><p>Hier lässt sich die aktuelle, für alle freigegebene Version neu einspielen, z. B. wenn ein automatisches Update nicht klappt oder Dateien beschädigt sind. <strong>Die Daten bleiben erhalten.</strong></p>'
        . '<form method="post"><input type="hidden" name="csrf" value="' . ki_h($csrf) . '"><label for="login">Benutzername eines Admins</label><input id="login" name="login" autocomplete="username" required>'
        . '<label for="password">Passwort</label><input id="password" name="password" type="password" autocomplete="current-password" required>'
        . '<button class="btn" type="submit">Aktualisieren / Reparieren</button></form></div>'
        . '<p class="hint">Wer das nicht vorhat: diese Datei install.php bitte wieder löschen.</p>');
}

// Neue Installation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ki_checks_ok($checks)) {
    $work = null;
    try {
        if (!ki_csrf_ok()) {
            throw new RuntimeException('Die Seite war zu lange offen. Bitte neu laden und erneut versuchen.');
        }
        $base = ki_post('url');
        if (!preg_match('~^https?://[^/\s?#]+(/[^\s?#]*)?$~', $base)) {
            throw new RuntimeException('Bitte die Adresse der App prüfen (z. B. https://beispiel.de/app/).');
        }
        $base = rtrim($base, '/') . '/';
        $name = ki_post('name');
        $login = ki_post('login');
        $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
        if ($name === '' || !preg_match('/^[A-Za-z0-9._@-]{2,60}$/', $login)) {
            throw new RuntimeException('Bitte Namen und Benutzernamen angeben (Benutzername: Buchstaben a–z, Ziffern sowie . _ - @).');
        }
        if (strlen($password) < 10 || $password !== (isset($_POST['password2']) ? (string) $_POST['password2'] : '')) {
            throw new RuntimeException('Das Passwort muss mindestens 10 Zeichen haben und in beiden Feldern gleich sein.');
        }
        $email = ki_post('email');
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Die E-Mail-Adresse ist ungültig.');
        }
        if ($foreign = ki_foreign_files()) {
            throw new RuntimeException('In diesem Ordner liegen schon andere Dateien (' . implode(', ', array_slice($foreign, 0, 5)) . '). Bitte einen eigenen, leeren Ordner für die App anlegen (z. B. „app“) und install.php dort hineinladen.');
        }
        $db = array('driver' => 'sqlite');
        if (ki_post('db') === 'mysql') {
            $db = array('driver' => 'mysql', 'host' => ki_post('db_host', 'localhost'), 'port' => (int) ki_post('db_port', '3306'), 'name' => ki_post('db_name'),
                'user' => ki_post('db_user'), 'password' => isset($_POST['db_pass']) ? (string) $_POST['db_pass'] : '', 'prefix' => 'kompanie_');
        }

        $entry = ki_stable_entry();
        $work = ki_fetch_package($entry);
        ki_place($work);
        ki_remove($work);
        $work = null;

        require KI_DIR . '/autoload.php';
        $http = new KompanieApp\Standalone\HttpContext(array(), $_SERVER, KompanieApp\Standalone\Kernel::basePath($_SERVER));
        $kernel = new KompanieApp\Standalone\Kernel(KI_DIR, $http);
        KompanieApp\Standalone\Setup::install($kernel, $db, $base, 'query');

        // Sind die Daten von außen erreichbar (Webserver ohne .htaccess)? Dann außerhalb ablegen oder abbrechen.
        $config = KompanieApp\Standalone\Config::load($kernel->dataDir());
        $dbFile = isset($config->toArray()['db']['path']) ? $config->toArray()['db']['path'] : '';
        if (ki_reachable($base . 'data/config.php') || ($db['driver'] === 'sqlite' && ki_reachable($base . 'data/' . rawurlencode($dbFile)))) {
            $outside = dirname(isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== '' ? rtrim($_SERVER['DOCUMENT_ROOT'], '/') : KI_DIR) . '/kompanie-daten-' . bin2hex(random_bytes(4));
            if (is_writable(dirname($outside)) && @rename($kernel->dataDir(), $outside)) {
                file_put_contents(KI_DIR . '/data-dir.php', '<?php return ' . var_export($outside, true) . ";\n");
                $kernel = new KompanieApp\Standalone\Kernel(KI_DIR, $http);
            } else {
                ki_remove($kernel->dataDir());
                throw new RuntimeException('Der Webserver schützt das Datenverzeichnis nicht (die Sperre per .htaccess wirkt hier nicht), und außerhalb des Web-Verzeichnisses darf PHP nichts anlegen. Bitte beim Hoster nachfragen, wie man einen Ordner sperrt, oder einen Ordner außerhalb des Web-Verzeichnisses beschreibbar machen. Es wurde nichts eingerichtet.');
            }
        }

        // Schöne Adressen, wenn die URL-Umschreibung (.htaccess) wirkt.
        try {
            $r = ki_http_get($base . 'api/v1/config', 8);
            if ($r[0] === 200 && is_array(json_decode($r[1], true))) {
                $c = KompanieApp\Standalone\Config::load($kernel->dataDir())->toArray();
                $c['url_mode'] = 'rewrite';
                KompanieApp\Standalone\Config::write($kernel->dataDir(), $c);
            }
        } catch (RuntimeException $e) {
            // dann eben index.php?r=…
        }

        $kernel = new KompanieApp\Standalone\Kernel(KI_DIR, $http);
        KompanieApp\Standalone\Setup::createAdmin($kernel, $name, $login, $email, $password);
        // Empfehlung: Cronjob beim Hoster (ohne läuft der Pseudo-Cron nach Besuchen, siehe Kernel::afterResponse).
        $cron = '';
        try {
            $cron = '<p class="hint">Empfohlen: Die App erledigt Hintergrundaufgaben (Galerie, Kalender, Updates) auch ohne weiteres Zutun. Zuverlässiger geht es mit einem Cronjob im Kundenmenü des Hosters, der alle 15 Minuten diese Adresse aufruft: <code>'
                . ki_h($kernel->cronUrl()) . '</code> (steht auch unter Einstellungen → Zustand der App).</p>';
        } catch (Exception $e) {
            // nur ein Hinweis
        } catch (Error $e) {
            // nur ein Hinweis
        }
        $deleted = ki_self_delete();
        ki_page('Fertig', '<div class="card"><h2>Die App ist eingerichtet</h2><p>Version ' . ki_h($entry['version']) . '. Jetzt mit dem eben angelegten Admin-Konto anmelden; danach führt die App durch die restliche Einrichtung (Bereiche wählen, dann z. B. Kalender, Galerie, Neuigkeiten).</p>'
            . $cron
            . ($deleted ? '<p class="hint">Der Installer hat sich selbst gelöscht.</p>' : '<p class="bad">Bitte die Datei install.php jetzt im Dateimanager löschen.</p>')
            . '<a class="btn" href="' . ki_h($base) . '">Zur App</a></div>');
    } catch (Exception $e) {
        if ($work !== null) {
            ki_remove($work);
        }
        $err = $e->getMessage();
    } catch (Error $e) {
        $err = 'Unerwarteter Fehler: ' . $e->getMessage();
    }
}

$sqlite = extension_loaded('pdo_sqlite');
ki_page('Installation', ($err !== '' ? '<div class="error">' . ki_h($err) . '</div>' : '')
    . '<div class="card"><h2>1. Server prüfen</h2>' . ki_checks_html($checks)
    . (ki_checks_ok($checks) ? '' : '<p class="bad">Bitte die markierten Punkte beim Hoster beheben und die Seite neu laden.</p>') . '</div>'
    . (ki_checks_ok($checks) ? '<form method="post" class="card"><h2>2. Einrichten</h2><input type="hidden" name="csrf" value="' . ki_h($csrf) . '">'
        . '<label for="url">Adresse der App</label><input id="url" name="url" value="' . ki_h(ki_post('url', ki_base_url())) . '" required>'
        . '<p class="hint">So erreichen die Mitglieder die App. Mit https:// ist sie sicher; ein Zertifikat (z. B. „Let’s Encrypt“) lässt sich bei fast allen Hostern kostenlos einschalten.</p>'
        . '<h2 style="margin-top:20px">Erster Admin</h2>'
        . '<label for="name">Name</label><input id="name" name="name" value="' . ki_h(ki_post('name')) . '" required autocomplete="name">'
        . '<label for="login">Benutzername</label><input id="login" name="login" value="' . ki_h(ki_post('login')) . '" required autocomplete="username" pattern="[A-Za-z0-9._@\-]{2,60}">'
        . '<label for="email">E-Mail (optional)</label><input id="email" name="email" type="email" value="' . ki_h(ki_post('email')) . '" autocomplete="email">'
        . '<label for="password">Passwort (mindestens 10 Zeichen)</label><input id="password" name="password" type="password" minlength="10" required autocomplete="new-password">'
        . '<label for="password2">Passwort wiederholen</label><input id="password2" name="password2" type="password" minlength="10" required autocomplete="new-password">'
        . '<details' . ($sqlite ? '' : ' open') . '><summary>Datenbank' . ($sqlite ? ' (wird automatisch angelegt)' : '') . '</summary>'
        . '<label for="db">Art</label><select id="db" name="db">' . ($sqlite ? '<option value="sqlite">Automatisch (SQLite-Datei, empfohlen)</option>' : '') . '<option value="mysql"' . (ki_post('db') === 'mysql' || !$sqlite ? ' selected' : '') . '>MySQL/MariaDB (im Kundenmenü des Hosters angelegt)</option></select>'
        . '<label for="db_host">Server</label><input id="db_host" name="db_host" value="' . ki_h(ki_post('db_host', 'localhost')) . '">'
        . '<label for="db_name">Datenbank</label><input id="db_name" name="db_name" value="' . ki_h(ki_post('db_name')) . '">'
        . '<label for="db_user">Benutzer</label><input id="db_user" name="db_user" value="' . ki_h(ki_post('db_user')) . '">'
        . '<label for="db_pass">Passwort</label><input id="db_pass" name="db_pass" type="password"></details>'
        . '<button class="btn" type="submit">App installieren</button></form>' : ''));
