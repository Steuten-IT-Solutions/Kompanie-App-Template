<?php
// Router für den PHP-Testserver: verhält sich wie die .htaccess (URL-Umschreibung).
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/(data|src|core|bin)(/|$)#', $path)) {
    http_response_code(403);
    return true;
}
if ($path !== '/' && is_file($_SERVER['DOCUMENT_ROOT'] . $path)) {
    return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
