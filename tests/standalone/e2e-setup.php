<?php
/**
 * Legt die Testinstallation für die E2E-Tests frisch an (Daten wie in der WordPress-Testinstanz):
 *   KOMPANIE_DATA_DIR=/tmp/… KOMPANIE_BASE_URL=http://localhost:8891/app/ php tests/standalone/e2e-setup.php
 * Benutzer: admin/admin, verwalter, mitglied1, mitglied2 (Passwort test1234).
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2) . '/adapters/standalone';
require $root . '/autoload.php';
require __DIR__ . '/MockHttp.php';

use KompanieApp\Contract\MockHttp;
use KompanieApp\Core\Auth\Level;
use KompanieApp\Standalone\HttpContext;
use KompanieApp\Standalone\Kernel;
use KompanieApp\Standalone\Setup;

$data = (string) getenv('KOMPANIE_DATA_DIR');
$base = (string) getenv('KOMPANIE_BASE_URL');
if ($data === '' || $base === '' || !str_starts_with($data, sys_get_temp_dir())) {
    fwrite(STDERR, "KOMPANIE_DATA_DIR (unter " . sys_get_temp_dir() . ") und KOMPANIE_BASE_URL angeben.\n");
    exit(1);
}
exec('rm -rf ' . escapeshellarg($data));
// Öffentliche Galeriebilder der vorigen Testinstallation entfernen.
exec('rm -rf ' . escapeshellarg($root . '/media'));

$u = parse_url($base);
$kernel = new Kernel($root, new HttpContext([], ['HTTP_HOST' => $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '')], (string) $u['path']), new MockHttp());
Setup::install($kernel, ['driver' => 'sqlite'], $base, 'rewrite');
$users = $kernel->users();
$users->add('Admin', 'admin', 'admin@example.org', 'admin', Level::Admin);
$users->add('Verwalter', 'verwalter', '', 'test1234', Level::Manager);
$users->add('Mitglied1', 'mitglied1', '', 'test1234', Level::Member);
$users->add('Mitglied2', 'mitglied2', '', 'test1234', Level::Member);

$app = $kernel->app();
$app->settings->set('calendar_ics_url', 'https://kalender.test/test.ics');
$app->settings->set('news_source', 'rss');
$app->settings->set('news_url', 'https://news.test/feed');
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($key, $pem);
$app->drive()->saveServiceAccount((string) json_encode(['client_email' => 'test@example.iam.gserviceaccount.com', 'private_key' => $pem]));
$app->gallery()->setRoot('TESTROOT');
$app->gallery()->sync();
$app->gallery()->processPending(60);
echo "Testinstallation angelegt: $base\n";
