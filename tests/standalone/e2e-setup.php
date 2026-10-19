<?php
/**
 * Legt die Testinstallation für die E2E-Tests frisch an (Daten wie in der WordPress-Testinstanz):
 *   KOMPANIE_DATA_DIR=/tmp/… KOMPANIE_BASE_URL=http://localhost:8891/app/ php tests/standalone/e2e-setup.php
 * Benutzer: admin/admin, verwalter, mitglied1, mitglied2 (Passwort test1234).
 * Mit KOMPANIE_DEMO=1 zusätzlich Beispieldaten für Bildschirmfotos (Termine, Punkte, Strafen).
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2) . '/adapters/standalone';
require $root . '/autoload.php';
require __DIR__ . '/MockHttp.php';

use KompanieApp\Contract\MockHttp;
use KompanieApp\Core\Auth\Roles;
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
// Öffentliche Galeriebilder der vorigen Testinstallation entfernen. Achtung: media/ liegt im
// Programmverzeichnis; eine lokale Entwicklungsinstallation dort verliert damit ihre Bilder.
exec('rm -rf ' . escapeshellarg($root . '/media'));

$u = parse_url($base);
$kernel = new Kernel($root, new HttpContext([], ['HTTP_HOST' => $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '')], (string) $u['path']), new MockHttp());
Setup::install($kernel, ['driver' => 'sqlite'], $base, 'rewrite');
$users = $kernel->users();
$demo = getenv('KOMPANIE_DEMO') === '1';
$admin = $users->add($demo ? 'Alex Admin' : 'Admin', 'admin', 'admin@example.org', 'admin', Roles::ADMIN);
$manager = $users->add($demo ? 'Vera Verwalter' : 'Verwalter', 'verwalter', '', 'test1234', Roles::MEMBER);
$m1 = $users->add($demo ? 'Max Muster' : 'Mitglied1', 'mitglied1', '', 'test1234', Roles::MEMBER);
$m2 = $users->add($demo ? 'Erika Beispiel' : 'Mitglied2', 'mitglied2', '', 'test1234', Roles::MEMBER);

$app = $kernel->app();
// Alle Bereiche an (eine neue Installation hat keine; sonst wählt sie der Admin in der Einrichtung).
$app->features()->update(array_fill_keys(KompanieApp\Core\Domain\Features::keys(), true));
// Eigene Rolle wie die Leitung einer Kompanie (Punkte, Kasse, Termine), z. B. „Spieß“.
$spiess = $app->roles()->create(['label' => 'Spieß', 'capabilities' => ['member', 'manage_points', 'manage_penalties', 'manage_events']]);
$users->setRole($manager, $spiess['key']);
$app->settings->set('calendar_ics_url', 'https://kalender.test/test.ics');
$app->settings->set('news_source', 'rss');
$app->settings->set('news_url', 'https://news.test/feed');
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($key, $pem);
$app->drive()->saveServiceAccount((string) json_encode(['client_email' => 'test@example.iam.gserviceaccount.com', 'private_key' => $pem]));
$app->gallery()->setRoot('TESTROOT');
$app->gallery()->sync();
$app->gallery()->processPending(60);
// Wie nach dem Speichern in den Einstellungen: Aufgaben einplanen (Galerie, Kalender).
$app->scheduleTasks();

if ($demo) {
    $day = static fn (int $n): string => date('Y-m-d', time() + $n * 86400);
    $events = $app->events();
    $events->create(KompanieApp\Core\Domain\EventService::input(['title' => 'Tag der offenen Tür', 'start_date' => $day(12), 'start_time' => '11:00', 'end_time' => '17:00', 'location' => 'Vereinsheim']), $admin);
    $events->create(KompanieApp\Core\Domain\EventService::input(['title' => 'Jahresfest', 'start_date' => $day(40), 'end_date' => $day(42), 'location' => 'Festplatz']), $admin);
    $scores = $app->scores();
    $fest = $scores->createCatalogItem(['title' => 'Teilnahme Sommerfest', 'points' => 5]);
    $scores->createCatalogItem(['title' => 'Aufbauhilfe', 'points' => 3]);
    $scores->givePoints(['user_ids' => [$m1, $m2], 'catalog_id' => $fest['id'], 'date' => $day(-20)], $manager);
    $scores->givePoints(['user_ids' => [$m1], 'points' => 3, 'reason' => 'Aufbauhilfe', 'date' => $day(-6)], $manager);
    $scores->givePenalty(['user_ids' => [$m1], 'amount_cents' => 250, 'reason' => 'Zu spät gekommen', 'date' => $day(-3)], $manager);
}
echo "Testinstallation angelegt: $base\n";
