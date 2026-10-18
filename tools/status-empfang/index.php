<?php
/**
 * Empfang der Statusmeldungen der Kompanie-Apps (nur von Installationen, deren Admin zugestimmt hat)
 * und Übersicht für uns. Läuft auf einer eigenen Subdomain, z. B. https://status.example.org/.
 *
 *   POST /           Meldung (JSON, Aufbau siehe packages/core/src/Update/StatusReporter.php)
 *   GET  /?uebersicht   Übersicht (HTTP-Anmeldung mit dem Passwort aus config.php)
 *
 * Die Meldungen sind nicht signiert: Jeder könnte eine Meldung schicken. Die Übersicht ist deshalb ein
 * Hinweis, kein Beweis. Gespeichert wird je Kompanie und Variante nur die letzte Meldung (keine IP-Adressen).
 * Einrichtung: README.md.
 */

declare(strict_types=1);

$config = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
$dataDir = (string) ($config['data_dir'] ?? __DIR__ . '/data');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function se_db(string $dataDir): PDO
{
    if (!is_dir($dataDir) && !mkdir($dataDir, 0700, true) && !is_dir($dataDir)) {
        throw new RuntimeException('Datenverzeichnis fehlt.');
    }
    $pdo = new PDO('sqlite:' . $dataDir . '/status.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA busy_timeout = 3000');
    $pdo->exec('CREATE TABLE IF NOT EXISTS meldungen (kompanie TEXT NOT NULL, variante TEXT NOT NULL, daten TEXT NOT NULL, empfangen INTEGER NOT NULL, PRIMARY KEY (kompanie, variante))');
    $pdo->exec('CREATE TABLE IF NOT EXISTS zaehler (fenster TEXT PRIMARY KEY, anzahl INTEGER NOT NULL)');
    return $pdo;
}

function se_json(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body);
    exit;
}

/** Prüft eine Meldung und gibt nur die bekannten Felder zurück (alles andere wird verworfen). */
function se_clean(mixed $in): ?array
{
    if (!is_array($in) || ($in['format'] ?? null) !== 1) {
        return null;
    }
    $kompanie = (string) ($in['kompanie'] ?? '');
    $version = (string) ($in['version'] ?? '');
    $variant = (string) ($in['variant'] ?? '');
    if (!preg_match('~^[A-Za-z0-9_.-]{1,60}/[A-Za-z0-9_.-]{1,100}$~', $kompanie) || !preg_match('/^\d{1,4}(\.\d{1,6}){2,3}$/', $version)
        || !in_array($variant, ['wordpress', 'standalone'], true)) {
        return null;
    }
    $flag = static fn ($v): ?array => is_array($v) ? ['ok' => (bool) ($v['ok'] ?? false), 'at' => max(0, (int) ($v['at'] ?? 0))] : null;
    $update = is_array($in['update'] ?? null) ? $flag($in['update']) + ['to' => preg_match('/^[0-9.]{1,30}$/', (string) ($in['update']['to'] ?? '')) ? (string) $in['update']['to'] : ''] : null;
    return [
        'kompanie' => $kompanie,
        'version' => $version,
        'variant' => $variant,
        'php' => preg_match('/^\d{1,2}\.\d{1,2}$/', (string) ($in['php'] ?? '')) ? (string) $in['php'] : '?',
        'database' => in_array($in['database'] ?? '', ['mysql', 'sqlite'], true) ? $in['database'] : '?',
        'update' => $update,
        'updateCheckOk' => (bool) ($in['updateCheckOk'] ?? false),
        'gallery' => $flag($in['gallery'] ?? null),
        'sent' => max(0, (int) ($in['sent'] ?? 0)),
    ];
}

// ---------------------------------------------------------------- Meldung annehmen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = (string) file_get_contents('php://input', false, null, 0, 4097);
    if (strlen($body) > 4096) {
        se_json(413, ['error' => 'zu groß']);
    }
    $m = se_clean(json_decode($body, true));
    if ($m === null) {
        se_json(400, ['error' => 'ungültig']);
    }
    try {
        $db = se_db($dataDir);
        // Grobe Bremse gegen Überflutung: höchstens 2000 Meldungen je Stunde insgesamt.
        $fenster = gmdate('YmdH');
        $db->prepare('INSERT INTO zaehler (fenster, anzahl) VALUES (?, 1) ON CONFLICT(fenster) DO UPDATE SET anzahl = anzahl + 1')->execute([$fenster]);
        $q = $db->prepare('SELECT anzahl FROM zaehler WHERE fenster = ?');
        $q->execute([$fenster]);
        $n = (int) $q->fetchColumn();
        $db->prepare('DELETE FROM zaehler WHERE fenster < ?')->execute([gmdate('YmdH', time() - 86400)]);
        if ($n > 2000) {
            se_json(429, ['error' => 'zu viele Meldungen']);
        }
        $db->prepare('INSERT INTO meldungen (kompanie, variante, daten, empfangen) VALUES (?, ?, ?, ?) ON CONFLICT(kompanie, variante) DO UPDATE SET daten = excluded.daten, empfangen = excluded.empfangen')
            ->execute([$m['kompanie'], $m['variant'], json_encode($m), time()]);
    } catch (Throwable $e) {
        error_log('Statusmeldung: ' . $e->getMessage());
        se_json(500, ['error' => 'intern']);
    }
    se_json(200, ['ok' => true]);
}

// ---------------------------------------------------------------- Übersicht
if (!isset($_GET['uebersicht'])) {
    http_response_code(404);
    exit;
}
$hash = (string) ($config['password_hash'] ?? '');
$pw = (string) ($_SERVER['PHP_AUTH_PW'] ?? '');
// PHP als CGI/FPM: Anmeldung steht nur im weitergereichten Header (siehe .htaccess)
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($pw === '' && stripos($auth, 'basic ') === 0) {
    $pw = (string) (explode(':', (string) base64_decode(substr($auth, 6)), 2)[1] ?? '');
}
if ($hash === '' || !password_verify($pw, $hash)) {
    if ($pw !== '') {
        sleep(1);
    }
    header('WWW-Authenticate: Basic realm="Statusmeldungen", charset="UTF-8"');
    http_response_code(401);
    echo 'Anmeldung nötig.';
    exit;
}
header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'');
header('Cache-Control: no-store');
$rows = se_db($dataDir)->query('SELECT daten, empfangen FROM meldungen ORDER BY kompanie, variante')->fetchAll(PDO::FETCH_ASSOC);
$h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$date = static fn (int $t): string => $t > 0 ? gmdate('d.m.Y H:i', $t) . ' UTC' : '–';
$out = '';
foreach ($rows as $r) {
    $m = json_decode($r['daten'], true);
    $alt = (int) $r['empfangen'] < time() - 3 * 86400;
    $problems = [];
    if ($alt) {
        $problems[] = 'seit über 3 Tagen keine Meldung';
    }
    if (!$m['updateCheckOk']) {
        $problems[] = 'Update-Liste nicht erreichbar';
    }
    if ($m['update'] !== null && !$m['update']['ok']) {
        $problems[] = 'letztes Update auf ' . $m['update']['to'] . ' gescheitert';
    }
    if ($m['gallery'] !== null && !$m['gallery']['ok']) {
        $problems[] = 'Galerie-Abgleich mit Fehler';
    }
    $out .= '<tr class="' . ($problems ? 'bad' : 'ok') . '"><td>' . $h($m['kompanie']) . '</td><td>' . $h($m['variant']) . '</td><td>' . $h($m['version'])
        . '</td><td>' . $h($m['php']) . ' / ' . $h($m['database']) . '</td><td>' . $h($date((int) $r['empfangen'])) . '</td><td>'
        . ($problems ? $h(implode('; ', $problems)) : 'in Ordnung') . '</td></tr>';
}
?><!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Statusmeldungen</title>
<style>body{font:15px system-ui,sans-serif;margin:16px;color:#1d2321;background:#f6f7f5}table{border-collapse:collapse;width:100%;background:#fff}
th,td{text-align:left;padding:6px 8px;border-bottom:1px solid #dde3dd;vertical-align:top}tr.bad td{background:#fde8e4}
@media (prefers-color-scheme:dark){body{background:#151a18;color:#e6ebe7}table{background:#1e2522}th,td{border-color:#33403a}tr.bad td{background:#4a2620}}</style></head>
<body><h1>Statusmeldungen</h1><p>Je Kompanie und Variante die letzte Meldung. Meldungen sind nicht signiert, also nur ein Hinweis.</p>
<div style="overflow-x:auto"><table><thead><tr><th>Kompanie</th><th>Variante</th><th>Version</th><th>PHP / DB</th><th>Letzte Meldung</th><th>Zustand</th></tr></thead>
<tbody><?= $out !== '' ? $out : '<tr><td colspan="6">Noch keine Meldungen.</td></tr>' ?></tbody></table></div></body></html>
