<?php
/**
 * Tests des WordPress-Plugins gegen eine Test-WordPress-Instanz:
 *   php .wp-test/wp-cli.phar --allow-root --path=.wp-test/wp eval-file tests/wordpress/run-tests.php
 *
 * 1. Das gemeinsame API-Szenario (tests/contract), wie für alle Adapter.
 * 2. WordPress-spezifisches: Rollen, News aus Beiträgen, Shortcodes, Galerie-Auslieferung,
 *    Nonce, App-Seite, Datenbank-Präfix.
 * Exit-Code 1, wenn ein Test fehlschlägt.
 */

if (!defined('ABSPATH')) {
    exit(1);
}
require_once ABSPATH . 'wp-admin/includes/user.php';
$root = dirname(__DIR__, 2);
require_once $root . '/tests/contract/ApiDriver.php';
require_once $root . '/tests/contract/Checks.php';
require_once $root . '/tests/contract/ApiScenario.php';
require_once __DIR__ . '/WpDriver.php';

use KompanieApp\Contract\ApiScenario;
use KompanieApp\Contract\Checks;
use KompanieApp\Contract\WpDriver;
use KompanieApp\WordPress\Plugin;
use KompanieApp\WordPress\Roles;

do_action('rest_api_init', rest_get_server());
$c = new Checks(true);
$d = new WpDriver();
$app = Plugin::app();

echo "== Gemeinsames API-Szenario ==\n";
(new ApiScenario($d, $c))->run();

echo "\n== WordPress ==\n";
$c->section('Rollen und Rechte');
$member = $d->createUser('kpwp_mitglied', 'member');
$manager = $d->createUser('kpwp_verwalter', 'manager');
$admin = $d->createUser('kpwp_admin', 'admin');
$c->ok(user_can($member, Roles::CAP_MEMBER) && !user_can($member, Roles::CAP_MANAGE), 'Mitglied: Recht kompanie_member');
$c->ok(user_can($manager, Roles::CAP_MANAGE) && user_can($manager, Roles::CAP_MEMBER), 'Verwalter: Verwalten und Mitglied');
$c->ok(user_can($admin, Roles::CAP_EVENTS) && user_can($admin, Roles::CAP_MANAGE), 'Administrator: alle App-Rechte');
$app->users->setLevel($member, KompanieApp\Core\Auth\Level::Guest);
clean_user_cache($member);
$c->ok(['subscriber'] === array_values(get_userdata($member)->roles), 'Zurück zum Gast: Konto behält Rolle Abonnent');
$editor = wp_insert_user(['user_login' => 'kpwp_redakteur', 'user_pass' => wp_generate_password(), 'role' => 'editor']);
$app->users->setLevel($editor, KompanieApp\Core\Auth\Level::Member);
clean_user_cache($editor);
$c->ok(in_array('editor', get_userdata($editor)->roles, true) && in_array(Roles::ROLE_MEMBER, get_userdata($editor)->roles, true), 'Mitgliedsrolle zusätzlich zur Webseiten-Rolle');
wp_delete_user($editor);
$app->users->setLevel($member, KompanieApp\Core\Auth\Level::Member);

$c->section('Benutzer anlegen');
$d->actAs($admin);
[$s, $u] = $d->request('POST', '/users', ['name' => 'Kpwp Neu', 'email' => 'kpwp-neu@example.com']);
$c->ok($s === 201 && array_values(get_userdata($u['id'])->roles) === [Roles::ROLE_MEMBER], 'Neues Konto bekommt die Rolle Mitglied');
$c->ok($d->request('POST', '/users', ['name' => 'X', 'email' => 'kpwp-neu@example.com'])[0] === 400, 'Vergebene E-Mail → 400');
$c->ok(wp_authenticate($u['username'], $u['password']) instanceof WP_User, 'Anmeldung mit Startpasswort bei WordPress');
wp_delete_user($u['id']);

$c->section('Neuigkeiten aus Beiträgen');
$pub = wp_insert_post(['post_title' => 'Kpwp Beitrag', 'post_content' => '<p>Hallo <strong>Verein</strong></p>', 'post_status' => 'publish']);
$draft = wp_insert_post(['post_title' => 'Kpwp Entwurf', 'post_content' => 'geheim', 'post_status' => 'draft']);
$pw = wp_insert_post(['post_title' => 'Kpwp Kennwort', 'post_content' => 'geheim', 'post_status' => 'publish', 'post_password' => 'x']);
$d->actAs(null);
[$s, $news, $headers] = $d->request('GET', '/news');
$titles = array_column($news, 'title');
$c->ok($s === 200 && in_array('Kpwp Beitrag', $titles, true) && !in_array('Kpwp Entwurf', $titles, true) && !in_array('Kpwp Kennwort', $titles, true), 'Nur veröffentlichte Beiträge ohne Kennwort');
$c->ok(isset($headers['x-total-pages']) && (int) $headers['x-total-pages'] >= 1, 'Header X-Total-Pages');
[$s, $item] = $d->request('GET', '/news/' . $pub);
$c->ok($s === 200 && str_contains($item['content'], '<strong>Verein</strong>') && $item['id'] === (string) $pub, 'Beitrag mit Inhalt');
$c->ok($d->request('GET', '/news/' . $draft)[0] === 404, 'Entwurf → 404');
$c->ok($d->request('GET', '/news/' . $pw)[0] === 404, 'Kennwortgeschützter Beitrag → 404');
foreach ([$pub, $draft, $pw] as $pid) {
    wp_delete_post($pid, true);
}

$c->section('Shortcodes');
$d->actAs($admin);
$tomorrow = wp_date('Y-m-d', time() + DAY_IN_SECONDS);
[, $ev] = $d->request('POST', '/events', ['title' => 'Kpwp Fest', 'start_date' => $tomorrow, 'start_time' => '19:00', 'end_time' => '23:00', 'url' => 'https://example.org/fest']);
$html = do_shortcode('[kompanie_termine anzahl="50"]');
$c->ok(str_contains($html, 'Kpwp Fest') && str_contains($html, 'href="https://example.org/fest"') && str_contains($html, '19:00–23:00 Uhr'), '[kompanie_termine] mit Link und Uhrzeit');
$c->ok(!str_contains($html, '⚓'), 'Keine Motive einer bestimmten Kompanie');
$d->request('DELETE', '/events/' . $ev['id']);

$c->section('Galerie');
$gallery = $app->gallery();
$c->ok($gallery->isConfigured(), 'Beispiel-Galerie der Test-Instanz eingerichtet');
$overviewMember = $gallery->overview(true);
$overviewGuest = $gallery->overview(false);
$titles = static fn (array $years): array => array_merge(...array_map(static fn ($y) => array_column($y['albums'], 'title'), $years ?: [['albums' => []]]));
$c->ok(in_array('Vereinsabend', $titles($overviewMember), true) && !in_array('Vereinsabend', $titles($overviewGuest), true), 'Rein interne Alben nur für Mitglieder');
$album = null;
foreach ($overviewMember[0]['albums'] ?? [] as $a) {
    if ($a['title'] === 'Sommerfest') {
        $album = $gallery->album($a['id'], true);
    }
}
$intern = $album ? array_values(array_filter($album['photos'], static fn ($p) => $p['intern']))[0] ?? null : null;
$public = $album ? array_values(array_filter($album['photos'], static fn ($p) => !$p['intern']))[0] ?? null : null;
$uploads = wp_upload_dir();
$c->ok($public && str_starts_with($public['thumb'], $uploads['baseurl'] . '/kompanie-galerie/'), 'Öffentliche Bilder als statische Datei');
$c->ok($intern && str_contains($intern['thumb'], '/app/foto/'), 'Interne Bilder nur über /app/foto/');
$c->ok(is_file($uploads['basedir'] . '/kompanie-galerie-intern/.htaccess'), 'Geschütztes Verzeichnis mit .htaccess');
$c->ok($gallery->photoForDelivery($intern['id'] ?? 0, 'thumb', false, false)['status'] === 401, 'Gast bekommt kein internes Bild');
$res = $gallery->photoForDelivery($intern['id'] ?? 0, 'thumb', true, true);
$c->ok($res['status'] === 200 && "\xFF\xD8" === substr((string) file_get_contents($res['file']), 0, 2), 'Mitglied bekommt das interne Bild (JPEG)');
$_GET['album'] = (string) ($album['id'] ?? 0);
wp_set_current_user(0);
$html = do_shortcode('[kompanie_galerie]');
unset($_GET['album']);
$c->ok(substr_count($html, 'class="ka-foto"') === 2 && !str_contains($html, '/app/foto/'), '[kompanie_galerie] zeigt Gästen keine internen Bilder');

$c->section('Laufzeit-Konfiguration und App-Seite');
wp_set_current_user($member);
$config = $app->api()->config();
$c->ok($config['csrf']['header'] === 'X-WP-Nonce' && wp_verify_nonce($config['csrf']['token'], 'wp_rest') !== false, 'CSRF-Token ist ein gültiger REST-Nonce');
$c->ok($config['auth']['mode'] === 'redirect' && str_contains((string) $config['auth']['loginUrl'], 'wp-login.php'), 'Anmeldung über wp-login.php');
$c->ok($config['features']['calendar'] === true && $config['features']['gallery'] === true && $config['features']['news'] === true, 'Eingerichtete Bereiche sind eingeschaltet');
$c->ok($config['apiBase'] === rest_url('kompanie-app/v1/'), 'API-Adresse der WordPress-REST-API');
$c->ok(str_starts_with($app->db->table('points'), $GLOBALS['wpdb']->prefix . 'kompanie_'), 'Tabellen mit WordPress-Präfix');
// Der Test-Server läuft hier nicht; die iCal-Datei der Test-Instanz direkt liefern.
$icsFilter = static fn ($pre, $args, $url) => str_ends_with((string) $url, '/test.ics')
    ? ['headers' => [], 'body' => (string) file_get_contents(ABSPATH . 'test.ics'), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null]
    : $pre;
add_filter('pre_http_request', $icsFilter, 10, 3);
$app->calendar()->clearCache();
[$s, $cal] = $d->request('GET', '/calendar');
remove_filter('pre_http_request', $icsFilter, 10);
$c->ok($s === 200 && in_array('Vereinsabend', array_column($cal, 'title'), true), 'Kalender aus iCal-Link der Test-Instanz');

$c->section('Befunde aus dem Review');
wp_set_current_user(0);
[$s, $body] = $d->request('POST', '/auth/login', ['username' => 'mitglied1', 'password' => 'test1234']);
$c->ok($s === 400 && $body['code'] === 'use_login_page' && get_current_user_id() === 0, 'Keine Anmeldung über die API (Login-CSRF, Login-Schutz-Plugins)');
wp_set_current_user($member);
$logout = (string) $app->api()->config()['auth']['logoutUrl'];
$c->ok(!str_contains($logout, '&amp;') && str_contains($logout, '_wpnonce='), 'Abmelde-Adresse ohne HTML-Maskierung');
// Plugins für WebP/AVIF dürfen die Galeriebilder nicht umleiten.
$toWebp = static fn () => ['image/jpeg' => 'image/webp'];
add_filter('image_editor_output_format', $toWebp);
$tmp = wp_tempnam('ka-src');
$im = imagecreatetruecolor(800, 600);
imagejpeg($im, $tmp);
$target = get_temp_dir() . 'ka-out-' . wp_generate_password(6, false) . '-thumb.jpg';
$size = (new KompanieApp\WordPress\WpImageResizer())->resizeToJpeg($tmp, $target, 400, 80);
remove_filter('image_editor_output_format', $toWebp);
$c->ok(is_file($target) && getimagesize($target)['mime'] === 'image/jpeg' && $size['width'] === 400, 'Galeriebilder bleiben JPEG, auch mit WebP-Plugin');
@unlink($tmp);
@unlink($target);
// Einfache Permalinks: Adressen über ?kompanie_app=…
$structure = get_option('permalink_structure');
update_option('permalink_structure', '');
$env = new KompanieApp\WordPress\WpEnvironment();
$c->ok(str_contains($env->appUrl('csrf'), 'kompanie_app=csrf') && str_contains($env->photoUrl(3, 'thumb'), 'kompanie_app=foto%2F3%2Fthumb') || str_contains($env->photoUrl(3, 'thumb'), 'kompanie_app=foto/3/thumb'), 'Einfache Permalinks: App-Adressen über Parameter');
$c->ok(str_contains($env->serviceWorkerUrl(), 'kompanie_app=sw.js'), 'Einfache Permalinks: Service Worker über Parameter (nicht /sw.js)');
update_option('permalink_structure', $structure);
$c->ok(str_ends_with($env->appUrl('csrf'), '/app/csrf'), 'Schöne Permalinks: /app/csrf');
// Deaktivieren entfernt die /app/-Regeln.
global $wp_rewrite;
KompanieApp\WordPress\AppPage::addRewriteRules();
KompanieApp\WordPress\AppPage::removeRewriteRules();
$c->ok(!isset($wp_rewrite->extra_rules_top['^app/?$']), 'Regeln für /app/ lassen sich entfernen');
KompanieApp\WordPress\AppPage::addRewriteRules();
// Ältere Installationen: Autoload war aus; das Update stellt es einmalig um.
$opt = KompanieApp\WordPress\WpSettings::PREFIX;
$wpdb = $GLOBALS['wpdb'];
$wpdb->update($wpdb->options, ['autoload' => 'no'], ['option_name' => $opt . 'schema_version']);
delete_option($opt . 'roles_version');
wp_cache_flush();
KompanieApp\WordPress\Plugin::upgrade();
$autoload = static fn (string $k): string => (string) $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $opt . $k));
$c->ok(in_array($autoload('schema_version'), ['yes', 'on', 'auto-on'], true) && in_array($autoload('roles_version'), ['yes', 'on', 'auto-on'], true), 'Einstellungen nach dem Update mit Autoload');
$c->ok($autoload('drive_service_account') === '' || !in_array($autoload('drive_service_account'), ['yes', 'on', 'auto-on'], true), 'Großer Schlüssel ohne Autoload');

foreach ([$member, $manager, $admin] as $id) {
    $d->deleteUser($id);
}
wp_set_current_user(0);

printf("\n%d bestanden, %d fehlgeschlagen\n", $c->passed, count($c->failed));
if ($c->failed) {
    echo "Fehlgeschlagen:\n  - " . implode("\n  - ", $c->failed) . "\n";
    exit(1);
}
