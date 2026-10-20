<?php
/**
 * Testserver für E2E-Tests der eigenständigen Version, installiert im Unterordner /app/:
 *   KOMPANIE_DATA_DIR=… php -S localhost:8891 -t <ordner mit Link app → adapters/standalone> tests/standalone/e2e-router.php
 * Verhält sich wie die .htaccess (URL-Umschreibung) und nutzt das simulierte Google Drive.
 */

declare(strict_types=1);

$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (!str_starts_with($path, '/app/') && $path !== '/app') {
    header('Location: /app/');
    http_response_code(302);
    return true;
}
$rel = substr($path, strlen('/app'));
if (preg_match('#^/(data|src|core|bin)(/|$)#', $rel)) {
    http_response_code(403);
    return true;
}
$root = dirname(__DIR__, 2) . '/adapters/standalone';
if ($rel !== '/' && $rel !== '' && is_file($root . $rel)) {
    return false;
}
// Wie manche Hoster (z. B. mit Jetpack Boost): Vary auf allen Antworten. Der Cache des Service Workers muss trotzdem passen.
header('Vary: accept,content-type');
$_SERVER['SCRIPT_NAME'] = '/app/index.php';
require $root . '/autoload.php';
require __DIR__ . '/MockHttp.php';
(new KompanieApp\Standalone\Kernel($root, null, new KompanieApp\Contract\MockHttp()))->handleHttp();
