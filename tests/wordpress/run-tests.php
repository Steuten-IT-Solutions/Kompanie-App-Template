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
use KompanieApp\WordPress\WpUsers;

do_action('rest_api_init', rest_get_server());
$c = new Checks(true);
$d = new WpDriver();
$app = Plugin::app();

echo "== Gemeinsames API-Szenario ==\n";
(new ApiScenario($d, $c))->run();

echo "\n== WordPress ==\n";
$c->section('Rollen und Rechte');
$member = $d->createUser('kpwp_mitglied', 'member');
$organizer = $d->createUser('kpwp_verwalter', 'events'); // Standardrolle „Termine verwalten“
$manager = $d->createUser('kpwp_leitung', 'points');      // Standardrolle „Punkte verwalten“
$admin = $d->createUser('kpwp_admin', 'admin');
$caps = static fn (int $id): array => array_map(static fn (KompanieApp\Core\Auth\Capability $x): string => $x->value, $app->users->find($id)?->capabilities ?? []);
$c->ok($caps($member) === ['member', 'calendar'], 'Mitglied: nur Mitgliederbereich (mit Kalender)');
$c->ok($caps($organizer) === ['member', 'manage_events', 'calendar'], 'Termine verwalten: Termine und Mitglied, keine Punkte/Strafen');
$c->ok($app->users->find($organizer)?->role === 'events' && get_user_meta($organizer, KompanieApp\WordPress\WpUsers::metaKey(), true) === 'events', 'Rolle steht in der Benutzer-Option der Website');
$c->ok($caps($manager) === ['member', 'manage_points', 'calendar'], 'Punkte verwalten: Punkte und Mitglied');
$c->ok(in_array(Roles::ROLE_MEMBER, get_userdata($member)->roles, true), 'Mitglied behält die frühere WP-Rolle (Zurückrollen)');
$c->ok(count($caps($admin)) === count(KompanieApp\Core\Auth\Capability::cases()) && $app->users->find($admin)?->role === 'admin', 'Administrator: alle App-Rechte');

// Eigene Rolle aus der App wirkt auch in WordPress.
$app->roles()->create(['label' => 'Kpwp Kasse', 'capabilities' => ['member', 'manage_penalties']]);
$kasseKey = (string) array_search('Kpwp Kasse', array_column($app->roles()->all(), 'label', 'key'), true);
$app->users->setRole($member, $kasseKey);
clean_user_cache($member);
$c->ok($app->users->find($member)?->role === $kasseKey && in_array('manage_penalties', $caps($member), true) && !in_array('manage_points', $caps($member), true), 'Eigene Rolle: Rechte aus der App');
$app->users->setRole($member, KompanieApp\Core\Auth\Roles::MEMBER);
$app->roles()->delete($kasseKey, null);

// Anwärter (Issue #23) als eigene Rolle: Abonnent mit der Rolle in der Benutzer-Option; aufnehmen darf, wer Konten anlegt –
// nur diesen Schritt und nur aus Rollen, die das erlauben.
$c->section('Anwärter');
$candKey = (string) (array_search('Kpwp Anwärter', array_column($app->roles()->all(), 'label', 'key'), true)
    ?: $app->roles()->create(['label' => 'Kpwp Anwärter', 'capabilities' => ['calendar'], 'new_accounts' => true, 'promotable' => true])['key']);
$app->roles()->create(['label' => 'Kpwp Aufnahme', 'capabilities' => ['member', 'create_users']]);
$aufnahmeKey = (string) array_search('Kpwp Aufnahme', array_column($app->roles()->all(), 'label', 'key'), true);
$holder = $d->createUser('kpwp_aufnahme', $aufnahmeKey);
$existingCand = get_user_by('login', 'kpwp.anwaerter');
if ($existingCand) {
    wp_delete_user($existingCand->ID);
}
wp_set_current_user($holder);
$created = $app->members()->create(['name' => 'Kpwp Anwärter', 'username' => 'kpwp.anwaerter', 'level' => $candKey], $app->users->find($holder));
$cand = (int) $created['id'];
clean_user_cache($cand);
$c->ok(get_userdata($cand)->roles === ['subscriber'] && get_user_meta($cand, KompanieApp\WordPress\WpUsers::metaKey(), true) === $candKey, 'Anwärter: WP-Abonnent, Rolle in der Benutzer-Option');
$c->ok($caps($cand) === ['calendar'], 'Anwärter: nur interner Kalender');
try {
    $app->users->setRole($cand, 'events');
    $c->ok(false, 'Wer nur Konten anlegt, vergibt keine anderen Rollen');
} catch (\DomainException) {
    $c->ok(true, 'Wer nur Konten anlegt, vergibt keine anderen Rollen');
}
$app->members()->promote($app->users->find($holder), $cand);
clean_user_cache($cand);
$c->ok($app->users->find($cand)?->role === 'member' && in_array(Roles::ROLE_MEMBER, get_userdata($cand)->roles, true), 'Aufgenommen: Mitglied, mit früherer WP-Rolle');
try {
    $app->users->setRole($cand, $candKey);
    $c->ok(false, 'Zurück zum Anwärter nur mit „Rollen vergeben“');
} catch (\DomainException) {
    $c->ok(true, 'Zurück zum Anwärter nur mit „Rollen vergeben“');
}
wp_set_current_user(0);
wp_delete_user($cand);
wp_delete_user($holder);
$app->roles()->delete($aufnahmeKey, null);
$app->roles()->delete($candKey, null);

// Update einer bestehenden Installation (vor #28): Rolle nur als WP-Rolle, ohne Meta.
// Die früheren WP-Rollen gibt es hier nur, wenn die Installation sie hatte; für den Test anlegen.
Roles::install(['member' => 'Mitglied', 'organizer' => 'Verwalter', 'manager' => 'Leitung']);
$known = static fn (string $role): bool => $app->roles()->exists($role);
$legacy = wp_insert_user(['user_login' => 'kpwp_alt', 'user_pass' => wp_generate_password(), 'role' => Roles::ROLE_ORGANIZER]);
clean_user_cache($legacy);
// Diese Installation ist nach #28 eingerichtet (keine App-Rolle „organizer“): gilt als Mitglied.
$c->ok($app->users->find($legacy)?->role === 'member', 'Frühere WP-Rolle ohne App-Rolle (neue Installation): gilt als Mitglied');
$listed = array_map(static fn (KompanieApp\Core\Auth\User $u): int => $u->id, $app->users->list(false));
$c->ok(in_array($legacy, $listed, true), 'Konto mit früherer WP-Rolle steht in der Mitgliederliste');
$c->ok(Roles::migrateUsers($known) >= 1 && get_user_meta($legacy, KompanieApp\WordPress\WpUsers::metaKey(), true) === 'member', 'Übernahme: unbekannte frühere Rolle → Mitglied');
$c->ok(Roles::migrateUsers($known) === 0, 'Übernahme nur einmal');
wp_delete_user($legacy);
// Ältere Installation, deren App die Rolle noch hat: die Rolle bleibt.
$legacy = wp_insert_user(['user_login' => 'kpwp_alt2', 'user_pass' => wp_generate_password(), 'role' => Roles::ROLE_ORGANIZER]);
clean_user_cache($legacy);
$c->ok(Roles::migrateUsers(static fn (string $role): bool => true) >= 1 && get_user_meta($legacy, KompanieApp\WordPress\WpUsers::metaKey(), true) === 'organizer', 'Übernahme bei älterer Installation: frühere WP-Rolle → Meta');
wp_delete_user($legacy);

// Frühere WP-Rechte über eine eigene WP-Rolle (z. B. mit einem Rollen-Plugin vergeben) zählen wie damals.
add_role('kpwp_vorstand', 'Vorstand', ['read' => true, Roles::CAP_MEMBER => true, Roles::CAP_MANAGE => true]);
$vorstand = wp_insert_user(['user_login' => 'kpwp_vorstand', 'user_pass' => wp_generate_password(), 'role' => 'kpwp_vorstand']);
clean_user_cache($vorstand);
$c->ok(WpUsers::legacyRole(get_userdata($vorstand)) === 'manager', 'Früheres Recht kompanie_manage über eigene WP-Rolle → Leitung');
$listed = array_map(static fn (KompanieApp\Core\Auth\User $u): int => $u->id, $app->users->list(false));
$c->ok(in_array($vorstand, $listed, true), 'Konto mit früherem WP-Recht steht in der Mitgliederliste');
$c->ok(Roles::migrateUsers(static fn (string $role): bool => true) >= 1 && get_user_meta($vorstand, WpUsers::metaKey(), true) === 'manager', 'Übernahme: früheres WP-Recht → Meta');
$c->ok(get_user_meta($vorstand, $GLOBALS['wpdb']->get_blog_prefix() . WpUsers::ACCOUNT_META, true) === '1', 'Übernahme markiert das Konto als Konto der Kompanie');
wp_delete_user($vorstand);
remove_role('kpwp_vorstand');

// Wer in der App Konten anlegen darf, setzt keine Passwörter von Benutzerverwaltern der Webseite zurück.
add_role('kpwp_userpflege', 'Benutzerpflege', ['read' => true, 'list_users' => true, 'edit_users' => true]);
$pflegeRole = $app->roles()->create(['label' => 'Kpwp Kontenpflege', 'capabilities' => ['member', 'create_users']]);
$pflegeKey = $pflegeRole['key'];
$pfleger = $d->createUser('kpwp_pfleger', 'member');
$app->users->setRole($pfleger, $pflegeKey);
$webUser = $d->createUser('kpwp_webuser', 'member');
get_userdata($webUser)->add_role('kpwp_userpflege');
$plain = $d->createUser('kpwp_schlicht', 'member');
clean_user_cache($pfleger);
wp_set_current_user($pfleger);
$refused = static function (int $id) use ($app): bool {
    try {
        $app->users->setPassword($id, wp_generate_password());
        return false;
    } catch (\DomainException) {
        return true;
    }
};
$c->ok($refused($webUser), 'Passwort eines Benutzers mit WP-Benutzerrechten nur mit WP-Recht');
$c->ok(!$refused($plain), 'Passwort eines Mitglieds mit dem App-Recht „Mitglieder anlegen“');
$marked = static fn (int $id): bool => get_user_meta($id, $GLOBALS['wpdb']->get_blog_prefix() . WpUsers::ACCOUNT_META, true) === '1';
$c->ok($marked($plain), 'Konto, dem eine Rolle mit WP-Recht gegeben wurde, ist als Konto der Kompanie markiert');
$neu = $app->users->create('Kpwp', 'Neu', 'kpwp_neu', 'kpwp_neu@example.com', wp_generate_password());
$c->ok($marked($neu), 'Von der App angelegtes Konto ist markiert');
wp_delete_user($neu);

// Vor- und Nachname: im WordPress-Profil (first_name, last_name), Anzeigename „Vorname Nachname“.
wp_set_current_user(0);
$named = $app->users->create('Hans Peter', 'von Kpwp', 'kpwp_named', '', wp_generate_password());
clean_user_cache($named);
$wpUser = get_userdata($named);
$u = $app->users->find($named);
$c->ok(
    [$wpUser->first_name, $wpUser->last_name, $wpUser->display_name] === ['Hans Peter', 'von Kpwp', 'Hans Peter von Kpwp']
        && [$u?->firstName, $u?->lastName] === ['Hans Peter', 'von Kpwp'],
    'Vor- und Nachname stehen im WordPress-Profil',
);
$app->users->updateProfile($named, 'Maria', '', '', null);
clean_user_cache($named);
$p = $app->users->profile($named);
$c->ok([$p['name'] ?? null, $p['first_name'] ?? null, $p['last_name'] ?? null] === ['Maria', 'Maria', ''], 'Nur Vorname: Anzeigename ist der Vorname');
// Konto ohne Vor- und Nachname (z. B. im Backend angelegt): aus dem Anzeigenamen geteilt.
wp_update_user(['ID' => $named, 'display_name' => 'Kpwp Ohne Namen', 'first_name' => '', 'last_name' => '']);
clean_user_cache($named);
$u = $app->users->find($named);
$c->ok([$u?->firstName, $u?->lastName] === ['Kpwp Ohne', 'Namen'], 'Ohne Vor- und Nachname im Profil: letztes Wort des Anzeigenamens ist der Nachname');
// Früher teilte die App am ersten Leerzeichen; einmalig umstellen, von Hand gepflegte Namen bleiben.
wp_update_user(['ID' => $named, 'display_name' => 'Kpwp Peter Schulz', 'first_name' => 'Kpwp', 'last_name' => 'Peter Schulz']);
$manual = $app->users->create('Kpwp Maria', 'von Berg', 'kpwp_manual', '', wp_generate_password());
WpUsers::splitLegacyNames();
clean_user_cache($named);
clean_user_cache($manual);
$c->ok(
    [get_userdata($named)->first_name, get_userdata($named)->last_name] === ['Kpwp Peter', 'Schulz']
        && [get_userdata($manual)->first_name, get_userdata($manual)->last_name] === ['Kpwp Maria', 'von Berg'],
    'Update: frühere Teilung am ersten Leerzeichen wird umgestellt, gepflegte Namen bleiben',
);
wp_delete_user($named);
wp_delete_user($manual);
wp_set_current_user($pfleger);
// Konto der Webseite (z. B. Kunde, Forum), das erst über die App zum Mitglied gemacht wird: nicht übernehmbar.
wp_set_current_user(0);
$app->roles()->update($pflegeKey, ['capabilities' => ['member', 'create_users', 'promote_users']]);
$fremd = wp_insert_user(['user_login' => 'kpwp_fremd', 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
wp_set_current_user($pfleger);
$app->users->setRole($fremd, 'member');
clean_user_cache($fremd);
$c->ok($app->users->find($fremd)?->role === 'member' && $refused($fremd), 'Fremdes Konto erst zum Mitglied gemacht: Passwort nur mit WP-Recht');
wp_set_current_user(0);
foreach ([$pfleger, $webUser, $plain, $fremd] as $id) {
    wp_delete_user($id);
}
$app->roles()->delete($pflegeKey, 'member');
remove_role('kpwp_userpflege');

// Bezeichnungen der früheren WP-Rollen folgen kompanie.json.
$wpRoles = wp_roles();
$wpRoles->roles[Roles::ROLE_MANAGER]['name'] = 'Verwalter';
update_option($wpRoles->role_key, $wpRoles->roles);
Roles::install(['member' => 'Mitglied', 'organizer' => 'Verwalter', 'manager' => 'Spieß']);
$c->ok(wp_roles()->role_names[Roles::ROLE_MANAGER] === 'Spieß' && get_option($wpRoles->role_key)[Roles::ROLE_MANAGER]['name'] === 'Spieß', 'Update: Bezeichnungen der WP-Rollen aus kompanie.json');

$app->users->setRole($member, KompanieApp\Core\Auth\Roles::GUEST);
clean_user_cache($member);
$c->ok(['subscriber'] === array_values(get_userdata($member)->roles) && $app->users->find($member)?->role === 'guest', 'Zurück zum Gast: Konto behält Rolle Abonnent');
// Die Rolle gilt je Website (Multisite): ein Meta ohne Präfix der Website zählt nicht.
update_user_meta($member, KompanieApp\WordPress\WpUsers::ROLE_META, 'points');
clean_user_cache($member);
$c->ok($app->users->find($member)?->role === 'guest', 'Rolle einer anderen Website (ohne Präfix) gilt nicht');
delete_user_meta($member, KompanieApp\WordPress\WpUsers::ROLE_META);
update_user_option($member, KompanieApp\WordPress\WpUsers::ROLE_META, 'admin', false);
clean_user_cache($member);
$c->ok($app->users->find($member)?->role === 'guest', 'Admin nie über die Benutzer-Option');
$editor = wp_insert_user(['user_login' => 'kpwp_redakteur', 'user_pass' => wp_generate_password(), 'role' => 'editor']);
$app->users->setRole($editor, KompanieApp\Core\Auth\Roles::MEMBER);
clean_user_cache($editor);
$c->ok(in_array('editor', get_userdata($editor)->roles, true) && $app->users->find($editor)?->role === 'member', 'Mitgliedsrolle zusätzlich zur Webseiten-Rolle');
wp_delete_user($editor);
$app->users->setRole($member, KompanieApp\Core\Auth\Roles::MEMBER);

$c->section('Benutzer anlegen');
$d->actAs($admin);
[$s, $u] = $d->request('POST', '/users', ['name' => 'Kpwp Neu', 'email' => 'kpwp-neu@example.com']);
$c->ok($s === 201 && get_user_meta($u['id'], KompanieApp\WordPress\WpUsers::metaKey(), true) === 'member' && array_values(get_userdata($u['id'])->roles) === [Roles::ROLE_MEMBER], 'Neues Konto bekommt die Rolle Mitglied');
$c->ok($d->request('POST', '/users', ['name' => 'X', 'email' => 'kpwp-neu@example.com'])[0] === 400, 'Vergebene E-Mail → 400');
$c->ok(wp_authenticate($u['username'], $u['password']) instanceof WP_User, 'Anmeldung mit Startpasswort bei WordPress');
wp_delete_user($u['id']);

$c->section('Benutzername ändern');
// WordPress schreibt user_login bei wp_update_user() nicht; die App ändert ihn direkt, ID und Daten bleiben.
$d->actAs($admin);
[, $alt] = $d->request('POST', '/users', ['name' => 'Kpwp Umbenennen', 'username' => 'kpwp.alt']);
$rid = (int) $alt['id'];
$d->request('POST', '/points', ['user_ids' => [$rid], 'points' => 5, 'reason' => 'Kpwp vor dem Umbenennen']);
$sessions = WP_Session_Tokens::get_instance($rid);
$sessions->create(time() + HOUR_IN_SECONDS);
[$s, $p] = $d->request('GET', "/members/$rid/account");
$c->ok($s === 200 && $p['can_change_login'] === true, 'WordPress: Benutzername änderbar');
[$s, $p] = $d->request('PATCH', "/members/$rid/account", ['username' => 'kpwp.neu']);
clean_user_cache($rid);
$renamed = get_userdata($rid);
$c->ok($s === 200 && $p['login'] === 'kpwp.neu' && $renamed->user_login === 'kpwp.neu', 'Neuer Benutzername gespeichert, gleiche ID');
$c->ok($renamed->user_nicename === 'kpwp-neu', 'Aus dem alten Benutzernamen gebildeter Name in Adressen folgt dem neuen');
$c->ok(get_user_by('login', 'kpwp.alt') === false && (int) username_exists('kpwp.neu') === $rid, 'Alter Benutzername frei, neuer gehört zum Konto (Cache geleert)');
$c->ok(wp_authenticate('kpwp.neu', $alt['password']) instanceof WP_User && is_wp_error(wp_authenticate('kpwp.alt', $alt['password'])), 'Anmeldung nur mit dem neuen Benutzernamen');
$c->ok((int) $app->db->scalar('SELECT COALESCE(SUM(points), 0) FROM {points} WHERE user_id = ?', [$rid]) === 5, 'Daten in der App bleiben erhalten');
$c->ok(WP_Session_Tokens::get_instance($rid)->get_all() === [], 'Neuer Benutzername meldet auf allen Geräten ab');
$c->ok($d->request('PATCH', "/members/$rid/account", ['username' => 'admin'])[0] === 400, 'Vergebener Benutzername → 400');
$c->ok($d->request('PATCH', "/members/$rid/account", ['username' => 'mit leerzeichen'])[0] === 400, 'Ungültiger Benutzername → 400');
$illegal = static fn (): array => ['kpwp.verboten'];
add_filter('illegal_user_logins', $illegal);
$c->ok($d->request('PATCH', "/members/$rid/account", ['username' => 'kpwp.verboten'])[0] === 403 && get_userdata($rid)->user_login === 'kpwp.neu', 'Von WordPress verbotener Benutzername wird abgelehnt');
remove_filter('illegal_user_logins', $illegal);
// Eigener Name in Adressen (nicht aus dem Benutzernamen gebildet) bleibt.
$GLOBALS['wpdb']->update($GLOBALS['wpdb']->users, ['user_nicename' => 'kpwp-eigen'], ['ID' => $rid]);
clean_user_cache($rid);
$d->request('PATCH', "/members/$rid/account", ['username' => 'kpwp.dritter']);
clean_user_cache($rid);
$c->ok(get_userdata($rid)->user_login === 'kpwp.dritter' && get_userdata($rid)->user_nicename === 'kpwp-eigen', 'Eigener Name in Adressen bleibt');
// Benutzer der Webseite (z. B. Redakteur): nur mit dem Recht aus WordPress, nicht über „Mitglieder anlegen“ der App.
$umbenenner = $app->roles()->create(['label' => 'Kpwp Umbenenner', 'capabilities' => ['member', 'create_users']]);
$kontenpfleger = $d->createUser('kpwp_kontenpfleger', 'member');
$app->users->setRole($kontenpfleger, $umbenenner['key']);
$redakteur = wp_insert_user(['user_login' => 'kpwp_red', 'user_pass' => wp_generate_password(), 'role' => 'editor']);
$app->users->setRole($redakteur, 'member');
clean_user_cache($kontenpfleger);
clean_user_cache($redakteur);
$d->actAs($kontenpfleger);
$c->ok($d->request('PATCH', "/members/$redakteur/account", ['username' => 'kpwp_red_neu'])[0] === 403 && get_userdata($redakteur)->user_login === 'kpwp_red', 'Redakteur umbenennen nur mit WP-Recht');
$c->ok($d->request('PATCH', "/members/$rid/account", ['username' => 'kpwp.vierter'])[0] === 200, 'Konto der Kompanie umbenennen mit „Mitglieder anlegen“');
$d->actAs($admin);
$c->ok($d->request('PATCH', "/members/$redakteur/account", ['username' => 'kpwp_red_neu'])[0] === 200 && get_userdata($redakteur)->user_login === 'kpwp_red_neu', 'WordPress-Admin benennt Redakteur um');
$d->actAs(null);
foreach ([$rid, $kontenpfleger, $redakteur] as $id) {
    wp_delete_user($id);
}
$app->roles()->delete($umbenenner['key'], 'member');

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

$c->section('Bilder ohne Metadaten');
// Der Bildeditor von WordPress (Imagick) behält EXIF, wenn er nicht verkleinern muss: auch dann kein GPS-Ort.
$im = imagecreatetruecolor(60, 40);
ob_start();
imagejpeg($im, null, 90);
$jpeg = (string) ob_get_clean();
$exif = "Exif\0\0GPSLatitude 51.29";
$jpeg = substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif . substr($jpeg, 2);
$src = wp_tempnam('kpwp') . '.jpg';
$dst = $src . '-out.jpg';
file_put_contents($src, $jpeg);
$size = $app->images->resizeToJpeg($src, $dst, 8192, 90);
$c->ok($size === ['width' => 60, 'height' => 40] && !str_contains((string) file_get_contents($dst), 'GPSLatitude'), 'Ohne Verkleinerung: JPEG ohne EXIF (' . get_class(wp_get_image_editor($src)) . ')');
@unlink($src);
@unlink($dst);

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
$db = $app->db;
$db->execute('UPDATE {photos} SET status = ? WHERE id = ?', ['pending', $intern['id'] ?? 0]);
$pending = $gallery->photoForDelivery($intern['id'] ?? 0, 'thumb', true, true);
$c->ok($pending['status'] === 200 && "\xFF\xD8" === substr((string) file_get_contents((string) ($pending['file'] ?? '')), 0, 2) && $db->scalar('SELECT status FROM {photos} WHERE id = ?', [$intern['id'] ?? 0]) === 'ready', 'Noch nicht aufbereitetes Bild: während der Anfrage erzeugt (Download über WpHttpClient::download)');
$slots = [];
for ($i = 0; $i < KompanieApp\Core\Domain\GalleryService::REQUEST_SLOTS; $i++) {
    $slots[$i] = fopen($app->files->path('.erzeugen-' . $i . '.lock', false), 'c');
    flock($slots[$i], LOCK_EX);
}
$db->execute('UPDATE {photos} SET status = ? WHERE id = ?', ['pending', $intern['id'] ?? 0]);
$pending = $gallery->photoForDelivery($intern['id'] ?? 0, 'thumb', true, true);
$c->ok($pending['status'] === 202 && str_starts_with($pending['placeholder'] ?? '', '<svg') && ($pending['retryAfter'] ?? 0) > 0, 'Alle Plätze zum Erzeugen belegt: 202 mit Platzhalter');
foreach ($slots as $slot) {
    flock($slot, LOCK_UN);
    fclose($slot);
}
$db->execute('UPDATE {photos} SET status = ? WHERE id = ?', ['ready', $intern['id'] ?? 0]);
// ETag und 304 (Issue #18): Rechte vor dem Vergleich, Konto nur bei internen Bildern.
wp_set_current_user(0);
$c->ok(KompanieApp\WordPress\AppPage::photoResponse($app, $intern['id'] ?? 0, 'thumb')['status'] === 401, 'Auslieferung: Gast bekommt kein internes Bild');
wp_set_current_user($member);
$photoRes = KompanieApp\WordPress\AppPage::photoResponse($app, $intern['id'] ?? 0, 'thumb');
$etag = (string) ($photoRes['etag'] ?? '');
$c->ok($photoRes['status'] === 200 && preg_match('/^"[a-f0-9]{32}"$/', $etag) === 1, 'Internes Bild mit ETag');
$_SERVER['HTTP_IF_NONE_MATCH'] = $etag;
$c->ok(KompanieApp\Core\Http\FileCache::matches($etag, KompanieApp\WordPress\AppPage::ifNoneMatch()), 'If-None-Match mit gleichem ETag → 304');
$_SERVER['HTTP_IF_NONE_MATCH'] = '"anders"';
$c->ok(!KompanieApp\Core\Http\FileCache::matches($etag, KompanieApp\WordPress\AppPage::ifNoneMatch()), 'Anderer ETag → Bild neu senden');
unset($_SERVER['HTTP_IF_NONE_MATCH']);
$c->ok(KompanieApp\WordPress\AppPage::photoResponse($app, $public['id'] ?? 0, 'thumb')['status'] === 200, 'Öffentliches Bild über /app/foto/');
wp_set_current_user(0);

$c->section('Konten ohne Abfrage je Konto (Issue #18)');
$extra = [];
$queries = static function (callable $fn) use (&$extra): int {
    global $wpdb;
    foreach ($extra as $id) {
        clean_user_cache($id); // wie in einer neuen Anfrage
    }
    $before = $wpdb->num_queries;
    $fn();
    return $wpdb->num_queries - $before;
};
$measure = static function () use ($app, $queries, &$extra): array {
    return [
        'list' => $queries(static fn () => $app->users->list(false)),
        'findMany' => $queries(static fn () => $app->users->findMany($extra)),
        'members' => $queries(static fn () => $app->members()->list(false)),
        'titles' => $queries(static fn () => $app->titles()->list()),
    ];
};
for ($i = 0; $i < 5; $i++) {
    $extra[] = $d->createUser("kpwp_viele$i", 'member');
}
$few = $measure();
for ($i = 5; $i < 20; $i++) {
    $extra[] = $d->createUser("kpwp_viele$i", 'member');
}
$many = $measure();
$c->ok(count($app->users->findMany($extra)) === 20, 'Mehrere Konten auf einmal gefunden');
foreach ($few as $what => $n) {
    $c->ok($many[$what] === $n, sprintf('%s: gleich viele Abfragen mit 5 und 20 weiteren Konten (%d / %d)', $what, $n, $many[$what]));
}
foreach ($extra as $id) {
    wp_delete_user($id);
}

$c->section('Einstellungen ohne unnötige Abfragen');
$wpSettings = new KompanieApp\WordPress\WpSettings();
$wpdb = $GLOBALS['wpdb'];
$before = $wpdb->num_queries;
$c->ok($wpSettings->get('kp_gibt_es_nicht', 'standard') === 'standard' && $wpSettings->get('kp_auch_nicht') === null, 'Fehlende Einstellung: Standardwert');
$c->ok($wpdb->num_queries === $before, 'Fehlende Einstellungen ohne Datenbankabfrage (Autoload)');
foreach (KompanieApp\WordPress\WpSettings::NO_AUTOLOAD as $k) {
    wp_cache_delete(KompanieApp\WordPress\WpSettings::PREFIX . $k, 'options');
}
wp_cache_delete('notoptions', 'options');
$before = $wpdb->num_queries;
$hasDrive = $app->drive()->hasCredentials();
$wpSettings->get('gallery_status');
$c->ok($hasDrive && $wpdb->num_queries - $before <= 1, 'Zugang zu Google und Galerie-Status mit höchstens einer Abfrage (' . ($wpdb->num_queries - $before) . ')');
// Einstellung einer Vorversion ohne Autoload: nach dem Update wieder sichtbar (vor dem ersten Lesen umgestellt).
add_option(KompanieApp\WordPress\WpSettings::PREFIX . 'kp_alt', 'aus der Vorversion', '', false);
$rolesVersion = get_option(KompanieApp\WordPress\WpSettings::PREFIX . 'roles_version');
update_option(KompanieApp\WordPress\WpSettings::PREFIX . 'roles_version', '0.0.0-alt', true);
Plugin::upgrade();
$c->ok($wpSettings->get('kp_alt') === 'aus der Vorversion', 'Einstellung ohne Autoload aus einer Vorversion nach dem Update lesbar');
$c->ok(get_option(KompanieApp\WordPress\WpSettings::PREFIX . 'roles_version') === $rolesVersion, 'Update-Schritt einmal je Version');
delete_option(KompanieApp\WordPress\WpSettings::PREFIX . 'kp_alt');

$c->section('Aufgaben');
$task = [\KompanieApp\Core\Domain\GalleryService::TASK];
$hook = \KompanieApp\WordPress\WpScheduler::HOOK;
$c->ok((bool) wp_next_scheduled($hook, $task) && get_option('kompanie_app_' . \KompanieApp\Core\App::PLAN_SETTING) === KOMPANIE_APP_VERSION, 'Galerie-Abgleich eingeplant, Planung für diese Version vermerkt');
wp_clear_scheduled_hook($hook, $task);
Plugin::upgrade(); // läuft bei jedem Seitenaufruf (init)
$c->ok(!wp_next_scheduled($hook, $task), 'Normaler Seitenaufruf plant keine Aufgaben ein');
delete_option('kompanie_app_' . \KompanieApp\Core\App::PLAN_SETTING);
Plugin::upgrade(); // nach einem Update passt die vermerkte Version nicht mehr
$c->ok((bool) wp_next_scheduled($hook, $task), 'Nach einem Update werden die Aufgaben neu eingeplant');
// Schwarzes Brett (Issue #5, #172): stündlich über WP-Cron; der Hook löscht Nachrichten 30 Tage nach ihrem Ende.
$boardTask = \KompanieApp\Core\Domain\BoardService::TASK;
$c->ok(wp_get_schedule($hook, [$boardTask]) === 'hourly', 'Schwarzes Brett: Aufräumen stündlich über WP-Cron');
$past = static fn (int $s): string => gmdate('Y-m-d H:i:s', time() - $s);
$expiredBody = 'Kpwp abgelaufen ' . bin2hex(random_bytes(4));
$db->insert('board_messages', ['title' => 'Kpwp', 'body' => $expiredBody, 'author_id' => 1, 'created_at' => $past(32 * 86400), 'updated_at' => $past(32 * 86400), 'expires_at' => $past(31 * 86400)]);
do_action($hook, $boardTask);
$c->ok((int) $db->scalar('SELECT COUNT(*) FROM {board_messages} WHERE body = ?', [$expiredBody]) === 0, 'Aufgabe über den Hook löscht Nachrichten 30 Tage nach ihrem Ende');
// Umfragen (Issue #4): Erinnerung stündlich über WP-Cron; der Hook vermerkt fällige Umfragen als erinnert (einmal).
$pollTask = \KompanieApp\Core\Domain\PollService::TASK;
$c->ok(wp_get_schedule($hook, [$pollTask]) === 'hourly', 'Umfragen: Erinnerung stündlich über WP-Cron');
$future = static fn (int $s): string => gmdate('Y-m-d H:i:s', time() + $s);
$pollId = $db->insert('polls', ['title' => 'Kpwp Umfrage', 'description' => '', 'type' => 'text', 'anonymous' => 0, 'results' => 'after_vote', 'ends_at' => $future(3600), 'closed_at' => null, 'reminded_at' => null, 'created_by' => 1, 'created_at' => $past(60), 'updated_at' => $past(60)]);
do_action($hook, $pollTask);
$c->ok($db->scalar('SELECT reminded_at FROM {polls} WHERE id = ?', [$pollId]) !== null, 'Aufgabe über den Hook erinnert an bald endende Umfragen');
$db->execute('DELETE FROM {polls} WHERE id = ?', [$pollId]);
// Geburtstage (Issue #1): stündlich über WP-Cron; der Hook beansprucht einen heutigen Geburtstag ab 7 Uhr Ortszeit.
$birthdayTask = \KompanieApp\Core\Domain\BirthdayService::TASK;
$c->ok(wp_get_schedule($hook, [$birthdayTask]) === 'hourly', 'Geburtstage: Benachrichtigung stündlich über WP-Cron');
$localNow = (new DateTimeImmutable('now'))->setTimezone($app->time()->timezone());
$db->execute('DELETE FROM {birthdays} WHERE user_id = ?', [$member]);
$db->insert('birthdays', ['user_id' => $member, 'month' => (int) $localNow->format('n'), 'day' => (int) $localNow->format('j'), 'year' => null, 'visible' => 1, 'show_age' => 0, 'last_notified' => null, 'updated_by' => $member, 'updated_at' => $past(60)]);
do_action($hook, $birthdayTask);
$claimed = $db->scalar('SELECT last_notified FROM {birthdays} WHERE user_id = ?', [$member]);
$c->ok((int) $localNow->format('G') >= 7 ? $claimed === $localNow->format('Y-m-d') : $claimed === null, 'Aufgabe über den Hook benachrichtigt über heutige Geburtstage erst ab 7 Uhr (Ortszeit ' . $localNow->format('H:i') . ')');
do_action($hook, $birthdayTask);
$c->ok($db->scalar('SELECT last_notified FROM {birthdays} WHERE user_id = ?', [$member]) === $claimed, 'Zweiter Lauf ändert nichts');
$db->execute('DELETE FROM {birthdays} WHERE user_id = ?', [$member]);

$_GET['album'] = (string) ($album['id'] ?? 0);
wp_set_current_user(0);
$html = do_shortcode('[kompanie_galerie]');
unset($_GET['album']);
// Interne Bilder kämen nur über die App (…/foto/<id>/thumb|large); die Originalauflösung öffentlicher Bilder läuft
// über die App, bis sie erzeugt ist.
$c->ok(substr_count($html, 'class="ka-foto"') === 2 && !preg_match('#/app/foto/\d+/(thumb|large)#', $html), '[kompanie_galerie] zeigt Gästen keine internen Bilder');
$c->ok(substr_count($html, 'data-ka-original="') === 2, '[kompanie_galerie] lädt in der Großansicht die Originalauflösung nach');

// Videos (Issue #182): im Album mit ▶, abgespielt in Ausschnitten aus Drive (WpHttpClient mit Range).
$videoAlbum = null;
foreach ($overviewGuest[0]['albums'] ?? [] as $a) {
    if ($a['title'] === 'Umzug') {
        $videoAlbum = $a;
    }
}
$c->ok(($videoAlbum['count'] ?? 0) === 2 && ($videoAlbum['video_count'] ?? 0) === 1, 'Album mit Bild und Video gezählt');
$videoAlbum = $videoAlbum ? $gallery->album($videoAlbum['id'], false) : null;
$video = $videoAlbum ? array_values(array_filter($videoAlbum['photos'], static fn ($p) => $p['type'] === 'video'))[0] ?? null : null;
$c->ok($video !== null && str_contains($video['video'], '/app/foto/' . $video['id'] . '/video') && $video['mime'] === 'video/webm' && $video['duration'] === 2, 'Video im Album mit Adresse, Dateityp und Dauer');
$videoFile = (string) file_get_contents(dirname(__DIR__) . '/fixtures/galerie-video.webm');
$_SERVER['HTTP_RANGE'] = 'bytes=100-199';
$videoRes = KompanieApp\WordPress\AppPage::videoResponse($app, (int) ($video['id'] ?? 0));
$c->ok($videoRes['status'] === 206 && $videoRes['contentRange'] === 'bytes 100-199/' . strlen($videoFile) && ($videoRes['body'] ?? '') === substr($videoFile, 100, 100), 'Ausschnitt des Videos aus Drive (206, Content-Range)');
unset($_SERVER['HTTP_RANGE']);
$videoRes = KompanieApp\WordPress\AppPage::videoResponse($app, (int) ($video['id'] ?? 0));
$c->ok($videoRes['status'] === 206 && ($videoRes['body'] ?? '') === $videoFile, 'Ohne Range ab dem Anfang (kleines Video ganz)');
$_GET['album'] = (string) ($videoAlbum['id'] ?? 0);
$html = do_shortcode('[kompanie_galerie]');
unset($_GET['album']);
$c->ok(substr_count($html, 'class="ka-foto ka-foto-video"') === 1 && str_contains($html, '/app/foto/' . ($video['id'] ?? 0) . '/video') && str_contains($html, 'class="ka-foto-dauer">0:02<'), '[kompanie_galerie] zeigt das Video mit ▶ und Dauer');
$html = do_shortcode('[kompanie_galerie]');
$c->ok(str_contains($html, '1 Bild · 1 Video'), '[kompanie_galerie] zählt Bilder und Videos');

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

// Kalender-IDs über den Google-Zugang der Galerie (Dienstkonto der Test-Instanz): Bearer-Token, kein API-Schlüssel.
$calendarRequests = [];
$googleCalFilter = static function ($pre, $args, $url) use (&$calendarRequests) {
    if (!str_starts_with((string) $url, 'https://www.googleapis.com/calendar/v3/')) {
        return $pre;
    }
    $calendarRequests[] = ['url' => (string) $url, 'auth' => (string) ($args['headers']['Authorization'] ?? '')];
    $item = ['id' => 'g1', 'summary' => 'Probe über Google', 'start' => ['dateTime' => gmdate('Y-m-d', time() + 5 * 86400) . 'T18:00:00Z'], 'end' => ['dateTime' => gmdate('Y-m-d', time() + 5 * 86400) . 'T20:00:00Z']];
    return ['headers' => [], 'body' => (string) wp_json_encode(['items' => [$item]]), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
};
add_filter('pre_http_request', $googleCalFilter, 10, 3);
$app->settings->set('calendar_ids', 'kompanie@group.calendar.google.com');
$app->calendar()->clearCache();
[$s, $cal] = $d->request('GET', '/calendar');
$c->ok($app->calendar()->source() === 'google_connection' && $s === 200 && in_array('Probe über Google', array_column($cal, 'title'), true), 'Kalender-IDs über den Google-Zugang der Galerie');
$c->ok(count($calendarRequests) === 1 && $calendarRequests[0]['auth'] === 'Bearer TEST' && !str_contains($calendarRequests[0]['url'], 'key='), 'Abruf mit Bearer-Token, ohne API-Schlüssel');
if (!function_exists('submit_button')) {
    require_once ABSPATH . 'wp-admin/includes/template.php';
}
$renderSettings = static function () use ($app, $admin, $member): string {
    wp_set_current_user($admin);
    ob_start();
    (new ReflectionMethod(KompanieApp\WordPress\SettingsPage::class, 'render'))->invoke(null, $app);
    wp_set_current_user($member);
    return (string) ob_get_clean();
};
$page = $renderSettings();
$c->ok(str_contains($page, 'Kalender-IDs über den Google-Zugang'), 'Einstellungsseite nennt die Quelle');
$app->settings->set('drive_oauth', ['client_id' => 'CID', 'client_secret' => 'SECRET', 'refresh_token' => 'R', 'scope' => 'https://www.googleapis.com/auth/drive.readonly']);
$app->calendar()->clearCache();
$page = $renderSettings();
$c->ok($app->calendar()->source() === 'ics' && str_contains($page, 'neu verbinden, dabei auch das Häkchen für den Kalender'), 'Verbindung ohne Kalender-Berechtigung: iCal-Link und Hinweis „neu verbinden“');
remove_filter('pre_http_request', $googleCalFilter, 10);
$app->settings->delete('drive_oauth');
$app->settings->set('calendar_ids', '');
$app->calendar()->clearCache();

// Zwischenspeicher-Dauer (Issue #177) auch im WP-Admin: geprüft wie in der App, ungültig ändert nichts.
$calendarBefore = ['calendar_ics_url' => (string) $app->settings->get('calendar_ics_url', ''), 'calendar_ids' => '', 'google_api_key' => (string) $app->settings->get('google_api_key', '')];
$c->ok(str_contains($renderSettings(), 'id="calendar_cache_minutes" name="calendar_cache_minutes" value="15"'), 'Einstellungsseite: Feld „Aktualisieren alle … Minuten“ mit 15');
$c->ok(KompanieApp\WordPress\SettingsPage::applyCalendar($app, $calendarBefore + ['calendar_cache_minutes' => '1441', 'calendar_ics_url' => 'https://anders.example/k.ics'])
    === ['Der Zwischenspeicher muss zwischen 1 und 1440 Minuten liegen.', true]
    && $app->settings->get('calendar_ics_url') === $calendarBefore['calendar_ics_url'], '1441 Minuten abgelehnt, nichts geändert');
$c->ok(KompanieApp\WordPress\SettingsPage::applyCalendar($app, ['calendar_cache_minutes' => 'x'] + $calendarBefore)[1], 'Keine Zahl abgelehnt');
$c->ok(KompanieApp\WordPress\SettingsPage::applyCalendar($app, ['calendar_cache_minutes' => '30'] + $calendarBefore) === ['Gespeichert.', false]
    && $app->settingsService()->get()['calendar']['cache_minutes'] === 30 && str_contains($renderSettings(), 'name="calendar_cache_minutes" value="30"'), '30 Minuten gespeichert');
KompanieApp\WordPress\SettingsPage::applyCalendar($app, ['calendar_cache_minutes' => '15'] + $calendarBefore);

$c->section('Mit Google verbinden');
$page = $renderSettings();
$redirect = admin_url('admin-post.php');
$c->ok(str_contains($page, 'id="google"') && str_contains($page, esc_attr($redirect)) && strpos($page, 'id="google"') < strpos($page, 'id="kalender"') && str_contains($page, 'Speichern und mit Google verbinden'), 'Einstellungsseite: Abschnitt Google-Zugang über dem Kalender, mit Weiterleitungs-URI');
$c->ok(str_contains($page, 'Alternative: Dienstkonto') && strpos($page, 'id="drive_sa"') < strpos($page, 'id="kalender"'), 'Dienstkonto im Abschnitt Google-Zugang');
$d->actAs($admin);
[$s, $set] = $d->request('GET', '/admin/settings');
$c->ok($s === 200 && ($set['google']['redirect_uri'] ?? '') === $redirect, 'Weiterleitungs-URI: wp-admin/admin-post.php');
[$s, $start] = $d->request('POST', '/admin/google/connect', ['client_id' => '1-wp.apps.googleusercontent.com', 'client_secret' => 'GOCSPX-wp']);
parse_str((string) parse_url((string) ($start['url'] ?? ''), PHP_URL_QUERY), $q);
$c->ok($s === 200 && ($q['redirect_uri'] ?? '') === $redirect && str_starts_with((string) ($q['state'] ?? ''), 'kompanie_app_app_'), 'REST: Admin beginnt die Verbindung (Rückkehr in die App)');
$d->actAs($member);
$c->ok($d->request('POST', '/admin/google/connect', ['client_id' => '1-wp.apps.googleusercontent.com', 'client_secret' => 'x'])[0] === 403, 'REST: Mitglied → 403');
wp_set_current_user($member);
$c->ok(KompanieApp\WordPress\SettingsPage::googleCallbackTarget($app, ['state' => (string) $q['state'], 'code' => 'WPCODE']) === null, 'Rückkehr als Mitglied: keine Verbindung (403-Seite)');
wp_set_current_user(0);
$c->ok(KompanieApp\WordPress\SettingsPage::googleCallbackTarget($app, ['state' => (string) $q['state'], 'code' => 'WPCODE']) === null, 'Rückkehr ohne Anmeldung: keine Verbindung');
$googleFilter = static function ($pre, $args, $url) {
    $res = static fn (int $code, array $body): array => ['headers' => [], 'body' => (string) wp_json_encode($body), 'response' => ['code' => $code, 'message' => ''], 'cookies' => [], 'filename' => null];
    if (str_starts_with((string) $url, 'https://oauth2.googleapis.com/token')) {
        parse_str((string) ($args['body'] ?? ''), $form);
        if (($form['grant_type'] ?? '') === 'authorization_code') {
            return ($form['code'] ?? '') === 'WPCODE' && ($form['redirect_uri'] ?? '') === admin_url('admin-post.php')
                ? $res(200, ['access_token' => 'TEST', 'expires_in' => 3600, 'refresh_token' => 'WPREFRESH', 'scope' => 'https://www.googleapis.com/auth/drive.readonly'])
                : $res(400, ['error' => 'invalid_grant']);
        }
    }
    if (str_starts_with((string) $url, 'https://www.googleapis.com/drive/v3/about')) {
        return $res(200, ['user' => ['emailAddress' => 'wp@example.org']]);
    }
    if (str_starts_with((string) $url, 'https://oauth2.googleapis.com/revoke')) {
        $GLOBALS['kompanie_test_revoked'] = (string) ($args['body'] ?? '');
        return $res(200, []);
    }
    return $pre;
};
add_filter('pre_http_request', $googleFilter, 20, 3);
wp_set_current_user($admin);
$target = KompanieApp\WordPress\SettingsPage::googleCallbackTarget($app, ['state' => (string) $q['state'], 'code' => 'WPCODE']);
$c->ok($target === $app->env->appUrl() . (str_contains($app->env->appUrl(), '?') ? '&' : '?') . 'google=1#/einstellungen', 'Rückkehr: Weiterleitung in die Einstellungen der App');
$c->ok($app->drive()->oauthEmail() === 'wp@example.org' && $app->drive()->calendarAccess() === false && ($app->settings->get('drive_oauth')['refresh_token'] ?? '') === 'WPREFRESH', 'Verbunden: Konto und erlaubte Berechtigungen gespeichert (nur Drive)');
$page = $renderSettings();
$c->ok(str_contains($page, 'wp@example.org') && str_contains($page, 'Den Kalender darf es noch nicht lesen') && str_contains($page, 'Verbindung trennen'), 'Einstellungsseite: verbunden, Hinweis zum Kalender');
// Aus dem WP-Admin: Rückkehr auf die Einstellungsseite mit Meldung
wp_set_current_user($admin);
$url = KompanieApp\WordPress\SettingsPage::googleConnectUrl($app, ['client_id' => '1-wp.apps.googleusercontent.com', 'client_secret' => '']);
parse_str((string) parse_url($url, PHP_URL_QUERY), $q2);
$c->ok(str_starts_with((string) ($q2['state'] ?? ''), 'kompanie_app_admin_'), 'WP-Admin: Verbinden mit gespeichertem Secret');
$target = (string) KompanieApp\WordPress\SettingsPage::googleCallbackTarget($app, ['state' => (string) $q2['state'], 'error' => 'access_denied']);
$c->ok(str_contains($target, 'options-general.php?page=kompanie-app') && str_contains($target, 'kompanie_error=1') && str_ends_with($target, '#google'), 'WP-Admin: Abbruch zurück auf die Einstellungsseite mit Fehlermeldung');
$c->ok(KompanieApp\WordPress\SettingsPage::googleCallbackTarget($app, ['state' => 'kompanie_app_admin_' . str_repeat('0', 48), 'code' => 'WPCODE']) !== null && str_contains((string) ($app->googleConnect()->lastResult()['message'] ?? ''), 'abgelaufen oder ungültig'), 'Falsches state abgelehnt');
$app->googleConnect()->disconnect();
$c->ok($app->drive()->oauth() === null && str_contains((string) ($GLOBALS['kompanie_test_revoked'] ?? ''), 'WPREFRESH'), 'Trennen widerruft bei Google');
wp_set_current_user($member);
remove_filter('pre_http_request', $googleFilter, 20);
$app->settings->delete('drive_oauth');
$app->settings->delete(KompanieApp\Core\Domain\GoogleConnect::RESULT_SETTING);
$d->actAs(null);

$c->section('HTTP: mehrere Anfragen gleichzeitig, Download in eine Datei');
$http = new KompanieApp\WordPress\WpHttpClient();
$echoDir = sys_get_temp_dir() . '/kompanie-http-' . bin2hex(random_bytes(4));
mkdir($echoDir);
file_put_contents($echoDir . '/index.php', '<?php usleep(700000); header("X-Echo: " . ($_SERVER["HTTP_X_TEST"] ?? "")); echo $_SERVER["REQUEST_METHOD"] . " " . $_SERVER["REQUEST_URI"];');
// Zwei Server, damit sie die Anfragen sicher gleichzeitig beantworten (php -S arbeitet sonst ggf. nacheinander).
$port = 18000 + random_int(0, 998);
$servers = [];
foreach ([$port, $port + 1] as $p) {
    $servers[] = proc_open(['php', '-S', '127.0.0.1:' . $p, '-t', $echoDir], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $p); $i++) {
        usleep(100000);
    }
}
$base = 'http://127.0.0.1:' . $port;
$base2 = 'http://127.0.0.1:' . ($port + 1);
$start = microtime(true);
$all = $http->requestAll([
    'a' => ['method' => 'GET', 'url' => $base . '/a', 'headers' => ['X-Test' => 'eins']],
    'b' => ['method' => 'POST', 'url' => $base2 . '/b', 'body' => 'x=1'],
    'c' => ['method' => 'GET', 'url' => 'ftp://example.org/c'],
], 10);
$elapsed = microtime(true) - $start;
$c->ok(array_keys($all) === ['a', 'b', 'c'] && is_array($all['a']) && $all['a']['status'] === 200 && $all['a']['body'] === 'GET /a' && is_array($all['b']) && $all['b']['body'] === 'POST /b', 'requestAll: Antworten je Schlüssel, in der übergebenen Reihenfolge');
$c->ok(is_array($all['a']) && ($all['a']['headers']['x-echo'] ?? '') === 'eins', 'Kopfzeilen der Antwort (klein geschrieben)');
$c->ok($all['c'] instanceof \RuntimeException, 'Ungültige Adresse: Fehler nur für diese Anfrage');
$c->ok($elapsed < 1.3, sprintf('Gleichzeitig abgerufen (%.2f s für zwei Anfragen à 0,7 s)', $elapsed));
$single = $http->request('GET', $base . '/einzeln', ['X-Test' => 'zwei']);
$c->ok($single['body'] === 'GET /einzeln' && ($single['headers']['x-echo'] ?? '') === 'zwei', 'request: Kopfzeilen der Antwort');
$file = $echoDir . '/download.bin';
$dl = $http->download($base . '/datei', $file);
$c->ok($dl['status'] === 200 && file_get_contents($file) === 'GET /datei', 'download: direkt in die Datei');
$mock = static fn ($pre, $args, $url) => str_starts_with((string) $url, 'https://mock.invalid/')
    ? ['headers' => ['X-Mock' => '1'], 'body' => 'gefiltert ' . $args['method'], 'response' => ['code' => 201, 'message' => 'Created'], 'cookies' => [], 'filename' => null]
    : $pre;
add_filter('pre_http_request', $mock, 10, 3);
$all = $http->requestAll(['x' => ['method' => 'GET', 'url' => 'https://mock.invalid/x'], 'y' => ['method' => 'GET', 'url' => $base . '/y']], 10);
$c->ok(is_array($all['x']) && $all['x']['status'] === 201 && $all['x']['body'] === 'gefiltert GET' && is_array($all['y']) && $all['y']['body'] === 'GET /y', 'requestAll beachtet pre_http_request (Filter antwortet, der Rest geht übers Netz)');
$dl = $http->download('https://mock.invalid/datei', $file . '2');
$c->ok($dl['status'] === 201 && file_get_contents($file . '2') === 'gefiltert GET', 'download: Antwort eines Filters landet in der Datei');
remove_filter('pre_http_request', $mock, 10);
foreach ($servers as $server) {
    proc_terminate($server);
    proc_close($server);
}
exec('rm -rf ' . escapeshellarg($echoDir));

$c->section('Befunde aus dem Review');
$wpSettings = new KompanieApp\WordPress\WpSettings();
$wpSettings->set('kptest_frisch', ['a']);
$wpSettings->get('kptest_frisch');
$GLOBALS['wpdb']->update($GLOBALS['wpdb']->options, ['option_value' => maybe_serialize(['a', 'b'])], ['option_name' => 'kompanie_app_kptest_frisch']); // wie eine andere Anfrage
$c->ok($wpSettings->get('kptest_frisch') === ['a'] && $wpSettings->fresh('kptest_frisch') === ['a', 'b'] && $wpSettings->get('kptest_frisch') === ['a', 'b'], 'fresh() liest am Options-Cache vorbei');
$wpSettings->delete('kptest_frisch');
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
$c->ok((bool) preg_match('#kompanie_app=dokument(/|%2F)7&v=abc$#', $env->documentUrl(7, 'abc')), 'Einfache Permalinks: Dokument-Adresse mit &v=');
$c->ok((bool) preg_match('#kompanie_app=abzeichen(/|%2F)7&v=abc&s=64$#', $env->badgeUrl(7, 'abc', 64)), 'Einfache Permalinks: Abzeichen-Adresse mit &v= und &s=');
$c->ok((bool) preg_match('#kompanie_app=mitgliedsfoto(/|%2F)7&v=abc&s=128$#', $env->memberPhotoUrl(7, 'abc', 128)), 'Einfache Permalinks: Foto-Adresse mit &v= und &s=');
$c->ok((bool) preg_match('#kompanie_app=bild(/|%2F)0123456789abcdef0123456789abcdef$#', $env->richImageUrl('0123456789abcdef0123456789abcdef')), 'Einfache Permalinks: Bild in formatiertem Inhalt');
update_option('permalink_structure', $structure);
$c->ok(str_ends_with($env->appUrl('csrf'), '/app/csrf'), 'Schöne Permalinks: /app/csrf');
$c->ok(str_ends_with($env->documentUrl(7, 'abc'), '/app/dokument/7?v=abc'), 'Schöne Permalinks: /app/dokument/7?v=…');
$c->ok(str_ends_with($env->badgeUrl(7, 'abc', 128), '/app/abzeichen/7?v=abc&s=128'), 'Schöne Permalinks: /app/abzeichen/7?v=…&s=…');
$c->ok(str_ends_with($env->memberPhotoUrl(7, 'abc', 480), '/app/mitgliedsfoto/7?v=abc&s=480'), 'Schöne Permalinks: /app/mitgliedsfoto/7?v=…&s=…');
$c->ok(str_ends_with($env->richImageUrl('0123456789abcdef0123456789abcdef'), '/app/bild/0123456789abcdef0123456789abcdef'), 'Schöne Permalinks: /app/bild/<Kennung>');
// Mailversand (Issue #172): PHPMailer von WordPress, keine eigene Kopie im Plugin
$c->ok(KompanieApp\Core\Mail\PhpMailerMailer::unavailable() === null, 'PHPMailer verfügbar');
$c->ok(str_starts_with((string) (new ReflectionClass(PHPMailer\PHPMailer\PHPMailer::class))->getFileName(), ABSPATH . WPINC . '/PHPMailer/'), 'PHPMailer kommt aus wp-includes (die Kopie von WordPress)');
$c->ok(!is_dir(KOMPANIE_APP_DIR . 'src/lib/PHPMailer') && !is_dir(KOMPANIE_APP_DIR . 'vendor'), 'Das Plugin bringt keine eigene PHPMailer-Kopie mit');
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
update_option($opt . 'gallery_status', ['albums' => 0], true); // wie von der Vorversion gespeichert
$wpdb->update($wpdb->options, ['autoload' => 'yes'], ['option_name' => $opt . 'gallery_status']);
delete_option($opt . 'roles_version');
wp_cache_flush();
KompanieApp\WordPress\Plugin::upgrade();
$autoload = static fn (string $k): string => (string) $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $opt . $k));
$c->ok(in_array($autoload('schema_version'), ['yes', 'on', 'auto-on'], true) && in_array($autoload('roles_version'), ['yes', 'on', 'auto-on'], true), 'Einstellungen nach dem Update mit Autoload');
$c->ok(!in_array($autoload('gallery_status'), ['yes', 'on', 'auto-on', 'auto'], true), 'Großer, selten gebrauchter Wert ohne Autoload');

// Atomarer Zähler (Passwortwechsel-Grenze) in der Options-Tabelle
$cache = new KompanieApp\WordPress\WpCache();
$key = 'test_' . wp_generate_password(6, false);
$c->ok($cache->increment($key, 900) === 1 && $cache->increment($key, 900) === 2 && $cache->increment($key . 'x', 900) === 1, 'Zähler im Cache zählt atomar je Schlüssel');

$c->section('Abzeichen der Dienstgrade');
// Transparenz bleibt beim Verkleinern mit dem Bildeditor von WordPress, auch mit einem Plugin, das nach WebP umleiten will.
add_filter('image_editor_output_format', $toWebp);
$src = get_temp_dir() . 'ka-badge-' . wp_generate_password(6, false) . '.png';
$im = imagecreatetruecolor(200, 100);
imagealphablending($im, false);
imagesavealpha($im, true);
imagefill($im, 0, 0, (int) imagecolorallocatealpha($im, 0, 0, 0, 127));
imagefilledrectangle($im, 50, 25, 150, 75, (int) imagecolorallocate($im, 200, 0, 0));
imagepng($im, $src);
$target = get_temp_dir() . 'ka-badge-out-' . wp_generate_password(6, false) . '.png';
$size = (new KompanieApp\WordPress\WpImageResizer())->resizeToPng($src, $target, 64);
remove_filter('image_editor_output_format', $toWebp);
$out = is_file($target) ? imagecreatefrompng($target) : false;
$c->ok($out !== false && getimagesize($target)['mime'] === 'image/png' && $size === ['width' => 64, 'height' => 32], 'Abzeichen: PNG in 64 Pixeln, auch mit WebP-Plugin');
$c->ok($out !== false && imagecolorsforindex($out, imagecolorat($out, 1, 1))['alpha'] === 127 && imagecolorsforindex($out, imagecolorat($out, 32, 16))['alpha'] === 0, 'Abzeichen: transparenter Hintergrund bleibt (WordPress-Bildeditor)');
@unlink($src);
@unlink($target);
wp_set_current_user($admin);
$rank = $app->ranks()->create(['title' => 'Kpwp Abzeichen ' . wp_generate_password(4, false)]);
$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><script>alert(1)</script><circle cx="5" cy="5" r="4"/></svg>';
$badge = $app->ranks()->uploadBadge($rank['id'], ['name' => 'abzeichen.svg', 'data' => base64_encode($svg)]);
parse_str((string) wp_parse_url((string) $badge['badge_url'], PHP_URL_QUERY), $q);
$_GET['v'] = (string) ($q['v'] ?? '');
$_GET['s'] = '128';
wp_set_current_user(0);
// Mit der öffentlichen Mitgliederliste (Issue #112) sieht jeder die Abzeichen, sonst nur mit Anmeldung.
$res = KompanieApp\WordPress\AppPage::badgeResponse($app, $rank['id']);
$c->ok($res['status'] === 200 && str_starts_with($res['headers']['Cache-Control'], 'public'), 'Mit Mitgliederliste: Abzeichen ohne Anmeldung, öffentlich zu speichern');
$app->features()->update(['directory' => false]);
$res = KompanieApp\WordPress\AppPage::badgeResponse($app, $rank['id']);
$c->ok($res['status'] === 401 && ($res['headers']['X-Content-Type-Options'] ?? '') === 'nosniff' && !isset($res['path']), 'Abzeichen ohne Anmeldung → 401');
wp_set_current_user($member);
$res = KompanieApp\WordPress\AppPage::badgeResponse($app, $rank['id']);
$c->ok($res['status'] === 200 && $res['headers']['Content-Type'] === 'image/svg+xml' && $res['headers']['X-Content-Type-Options'] === 'nosniff'
    && ($res['headers']['Content-Security-Policy'] ?? '') === "default-src 'none'; style-src 'unsafe-inline'; sandbox", 'Mitglied: SVG mit strenger CSP und nosniff');
$c->ok(str_starts_with($res['headers']['Cache-Control'], 'private') && !str_contains((string) file_get_contents((string) ($res['path'] ?? '')), 'script'), 'Privat gespeichert, ausgeliefert wird das bereinigte SVG');
$app->features()->update(['directory' => true]);
wp_set_current_user($admin);
$im = imagecreatetruecolor(300, 300);
ob_start();
imagepng($im);
$app->ranks()->uploadBadge($rank['id'], ['name' => 'abzeichen.png', 'data' => base64_encode((string) ob_get_clean())]);
$_GET['s'] = '64';
wp_set_current_user($member);
$res = KompanieApp\WordPress\AppPage::badgeResponse($app, $rank['id']);
$c->ok($res['status'] === 200 && $res['headers']['Content-Type'] === 'image/png' && !isset($res['headers']['Content-Security-Policy']) && getimagesize((string) ($res['path'] ?? ''))[0] === 64, 'PNG-Abzeichen über den Bildeditor von WordPress, s=64');
unset($_GET['v'], $_GET['s']);
wp_set_current_user($admin);
$app->ranks()->removeBadge($rank['id']);
$app->ranks()->delete($rank['id']);

$c->section('Fotos der Mitglieder');
wp_set_current_user($admin);
$im = imagecreatetruecolor(1200, 800);
ob_start();
imagepng($im);
$photo = $app->memberPhotos()->upload($app->users->find($admin), $member, ['name' => 'foto.png', 'data' => base64_encode((string) ob_get_clean())]);
parse_str((string) wp_parse_url((string) $photo['photo_url'], PHP_URL_QUERY), $q);
$_GET['v'] = (string) ($q['v'] ?? '');
$_GET['s'] = '480';
wp_set_current_user(0);
// Mit der öffentlichen Mitgliederliste (Issue #112) auch ohne Anmeldung; ohne sie nur mit Anmeldung.
$res = KompanieApp\WordPress\AppPage::memberPhotoResponse($app, $member);
$c->ok($res['status'] === 200 && $res['headers']['Cache-Control'] === 'public, max-age=86400', 'Mit Mitgliederliste: Foto ohne Anmeldung, öffentlich');
$app->features()->update(['directory' => false]);
$res = KompanieApp\WordPress\AppPage::memberPhotoResponse($app, $member);
$c->ok($res['status'] === 401 && !isset($res['body']), 'Foto ohne Anmeldung → 401');
$app->features()->update(['directory' => true]);
wp_set_current_user($member);
$res = KompanieApp\WordPress\AppPage::memberPhotoResponse($app, $member);
$size = getimagesizefromstring((string) ($res['body'] ?? ''));
$c->ok($res['status'] === 200 && $res['headers']['Content-Type'] === 'image/jpeg' && $size !== false && [$size[0], $size[1], $size['mime']] === [480, 320, 'image/jpeg'], 'Eigenes Foto: JPEG in 480 Pixeln über den Bildeditor von WordPress');
$_GET['s'] = '1024';
$size = getimagesizefromstring((string) (KompanieApp\WordPress\AppPage::memberPhotoResponse($app, $member)['body'] ?? ''));
$c->ok($size !== false && $size[0] === 1024 && abs($size[1] - 683) <= 1, 'Eigenes Foto: 1024 Pixel für die vergrößerte Ansicht');
unset($_GET['v'], $_GET['s']);
$c->ok(($app->api()->config()['user']['photo_url'] ?? null) === $photo['photo_url'], '/config: eigenes Foto');

// Shortcodes aus Ämtern und Mitgliederliste: dieselben öffentlichen Angaben wie die App, auch für Gäste.
$c->section('Shortcodes: Ämter und Mitglieder');
wp_set_current_user($admin);
$app->features()->update(['offices' => true, 'directory' => true]);
$guestHolder = $d->createUser('kpwp_gast_amt', 'guest');
$galleryCandKey = (string) (array_search('Kpwp Anwärter Galerie', array_column($app->roles()->all(), 'label', 'key'), true)
    ?: $app->roles()->create(['label' => 'Kpwp Anwärter Galerie', 'capabilities' => ['calendar']])['key']);
$galleryCand = $d->createUser('kpwp_anwaerter_galerie', $galleryCandKey);
$memberName = (string) $app->users->find($member)?->name;
$offices = $app->offices();
$groupA = $offices->createGroup('Kpwp Kompanie');
$groupB = $offices->createGroup('Kpwp Bruderschaft');
$officeA = $offices->createOffice(['title' => 'Kpwp Kapitän', 'group_id' => $groupA['id']]);
$officeB = $offices->createOffice(['title' => 'Kpwp Brudermeister', 'group_id' => $groupB['id']]);
$offices->createTerm($officeA['id'], ['start_year' => 1980, 'end_year' => 1990, 'holders' => [['name' => 'Kpwp Früher'], ['user_id' => $guestHolder]]]);
$offices->createTerm($officeA['id'], ['start_year' => 2020, 'holders' => [['user_id' => $member]], 'note' => 'Kpwp Zusatz']);
$offices->createTerm($officeB['id'], ['start_year' => 2018, 'holders' => [['name' => 'Kpwp Frei']]]);
wp_set_current_user(0);
$html = do_shortcode('[kompanie_aemter]');
$c->ok(str_contains($html, 'Kpwp Kapitän') && str_contains($html, 'Kpwp Brudermeister') && str_contains($html, 'class="ka-gruppe">Kpwp Kompanie<'), '[kompanie_aemter]: alle Gruppen mit Überschrift');
$c->ok(str_contains($html, esc_html($memberName)) && str_contains($html, 'seit 2020 · Kpwp Zusatz') && str_contains($html, esc_url((string) $app->memberPhotos()->urls(480)[$member])), 'Laufende Amtszeit mit Foto (480 Pixel) aus der Mitgliederliste und Zusatz');
$c->ok(str_contains($html, 'Chronik seit 1980') && str_contains($html, '1980–1990') && str_contains($html, 'Kpwp Früher'), 'Frühere Amtszeiten als Chronik');
$html = do_shortcode('[kompanie_aemter gruppe="kpwp bruderschaft"]');
$c->ok(str_contains($html, 'Kpwp Brudermeister') && !str_contains($html, 'Kpwp Kapitän') && !str_contains($html, 'ka-gruppe">'), 'gruppe="…" zeigt nur diese Gruppe (ohne eigene Überschrift)');
$html = do_shortcode('[kompanie_aemter gruppe=”Kpwp Bruderschaft”]');
$c->ok(str_contains($html, 'Kpwp Brudermeister') && !str_contains($html, 'Kpwp Kapitän'), 'gruppe mit typografischen Anführungszeichen');
foreach (['/kompanie-app/v1/offices', '/kompanie-app/v1/directory'] as $route) {
    $res = rest_do_request(new WP_REST_Request('GET', $route));
    $c->ok($res->get_status() === 200 && ($res->get_headers()['X-Robots-Tag'] ?? '') === 'noindex', "GET $route ohne Anmeldung: noindex");
}
$html = do_shortcode('[kompanie_aemter amt="kpwp kapitän"]');
$c->ok(str_contains($html, 'Kpwp Kapitän') && !str_contains($html, 'Kpwp Brudermeister') && !str_contains($html, 'ka-gruppe">'), 'amt="…" zeigt nur dieses Amt (ohne Überschrift)');
$html = do_shortcode('[kompanie_aemter gruppe=„Kpwp Kompanie“ amt=„Kpwp Kapitän“]');
$c->ok(str_contains($html, 'Kpwp Kapitän') && !str_contains($html, 'Kpwp Brudermeister'), 'gruppe und amt mit typografischen Anführungszeichen');
$html = do_shortcode('[kompanie_aemter gruppe="Kpwp Bruderschaft" amt="Kpwp Kapitän"]');
$c->ok(!str_contains($html, 'Kpwp Kapitän') && !str_contains($html, 'Kpwp Brudermeister') && str_contains($html, 'Noch keine Ämter eingetragen.'), 'amt in einer anderen Gruppe: nichts');
$html = do_shortcode('[kompanie_mitglieder]');
$c->ok(str_contains($html, 'class="ka ka-mitglieder"') && str_contains($html, '<span class="ka-mitglied-name">' . esc_html($memberName) . '</span>')
    && str_contains($html, esc_url((string) $app->memberPhotos()->urls(480)[$member])) && str_contains($html, 'href="' . esc_url((string) $app->memberPhotos()->urls(1024)[$member]) . '" data-ka-lightbox="mitglieder"'), '[kompanie_mitglieder]: Galerie mit Foto (480 Pixel), Name und Großansicht (1024 Pixel)');
// Foto von vor Datenbankversion 18 ohne 1024er (Issue #109): Großansicht mit der 480er statt einer Adresse mit 404, ohne srcset.
$app->db->execute('UPDATE {member_photos} SET xlarge = NULL WHERE user_id = ?', [$member]);
$medium480 = esc_url((string) $app->memberPhotos()->urls(480)[$member]);
$oldHtml = do_shortcode('[kompanie_mitglieder]');
$c->ok(str_contains($oldHtml, 'href="' . $medium480 . '" data-ka-lightbox="mitglieder"') && str_contains($oldHtml, '<img src="' . $medium480 . '" alt=')
    && !str_contains($oldHtml, 's=1024'), 'Altes Foto ohne 1024er: Großansicht mit der 480er, ohne srcset');
$_GET['v'] = (string) ($q['v'] ?? '');
parse_str((string) wp_parse_url(html_entity_decode($medium480), PHP_URL_QUERY), $oldQuery);
$_GET['s'] = (string) ($oldQuery['s'] ?? '');
$c->ok(KompanieApp\WordPress\AppPage::memberPhotoResponse($app, $member)['status'] === 200, 'Adresse der Großansicht liefert das Foto');
unset($_GET['v'], $_GET['s']);
$body = (string) preg_replace('#<(style|script)\b.*?</\1>#s', '', $html); // CSS und Skript der Großansicht nennen Klassen und „role“
$c->ok(!str_contains($body, 'ka-klein') && str_contains($body, 'class="ka-mitglied-leer"'), 'Ohne Dienstgrad; ohne Foto die Anfangsbuchstaben');
$c->ok(!str_contains($body, 'Kpwp_gast_amt') && !str_contains($body, 'punkte') && !str_contains($body, 'role'), 'Ohne Gäste, Punkte oder Rollen');
$c->ok(in_array($galleryCand, array_column($app->api()->directory(), 'id'), true) && !str_contains($body, 'Kpwp_anwaerter_galerie'), 'Ohne Anwärter (in der App-Liste stehen sie)');
wp_delete_user($galleryCand);
$app->roles()->delete($galleryCandKey, null);

// Platzhalterbild aus der Mediathek statt der Anfangsbuchstaben (Einstellungen der Webseite).
require_once ABSPATH . 'wp-admin/includes/image.php';
$upload = wp_upload_dir();
$file = $upload['path'] . '/kpwp-platzhalter.png';
$im = imagecreatetruecolor(400, 600);
imagepng($im, $file);
imagedestroy($im);
$attachment = wp_insert_attachment(['post_mime_type' => 'image/png', 'post_title' => 'Kpwp Platzhalter', 'post_status' => 'inherit'], $file);
wp_update_attachment_metadata($attachment, wp_generate_attachment_metadata($attachment, $file));
$c->ok(KompanieApp\WordPress\SettingsPage::applyMembersPlaceholder($app, ['placeholder_id' => 'x']) === ['Bitte ein Bild aus der Mediathek wählen.', true]
    && KompanieApp\WordPress\SettingsPage::applyMembersPlaceholder($app, ['placeholder_id' => (string) $guestHolder])[1], 'Platzhalterbild: nur Bilder aus der Mediathek');
$c->ok(KompanieApp\WordPress\SettingsPage::applyMembersPlaceholder($app, ['placeholder_id' => (string) $attachment]) === ['Platzhalterbild gespeichert.', false]
    && (int) $app->settings->get(KompanieApp\WordPress\Shortcodes::MEMBERS_PLACEHOLDER) === $attachment, 'Platzhalterbild gespeichert');
$c->ok(str_contains($renderSettings(), 'id="kompanie-placeholder-id" value="' . $attachment . '"'), 'Einstellungsseite zeigt das Platzhalterbild');
$html = do_shortcode('[kompanie_mitglieder]');
$body = (string) preg_replace('#<(style|script)\b.*?</\1>#s', '', $html);
$c->ok(!str_contains($body, 'class="ka-mitglied-leer"') && str_contains($body, 'class="ka-mitglied-platzhalter"') && str_contains($body, 'kpwp-platzhalter')
    && str_contains($body, esc_url((string) $app->memberPhotos()->urls(480)[$member])), 'Ohne Foto das Platzhalterbild statt der Anfangsbuchstaben, Fotos bleiben');
$c->ok(substr_count($body, 'data-ka-lightbox="mitglieder"') === count($app->memberPhotos()->urls(480)), 'Platzhalterbild ohne Großansicht');
wp_delete_attachment($attachment, true);
$body = (string) preg_replace('#<(style|script)\b.*?</\1>#s', '', do_shortcode('[kompanie_mitglieder]'));
$c->ok(str_contains($body, 'class="ka-mitglied-leer"') && !str_contains($body, 'ka-mitglied-platzhalter'), 'Bild aus der Mediathek gelöscht: wieder die Anfangsbuchstaben');
$c->ok(KompanieApp\WordPress\SettingsPage::applyMembersPlaceholder($app, ['remove' => '1'])[1] === false
    && $app->settings->get(KompanieApp\WordPress\Shortcodes::MEMBERS_PLACEHOLDER) === null, 'Platzhalterbild entfernt');
$app->features()->update(['offices' => false, 'directory' => false]);
$c->ok(do_shortcode('[kompanie_aemter]') === '' && do_shortcode('[kompanie_mitglieder]') === '', 'Bereich aus: keine Ausgabe');
$app->features()->update(['offices' => true, 'directory' => true]);
wp_set_current_user($admin);
foreach ([$officeA, $officeB] as $o) {
    $offices->deleteOffice($o['id']);
}
$offices->deleteGroup($groupA['id']);
$offices->deleteGroup($groupB['id']);
wp_delete_user($guestHolder);

wp_set_current_user($admin);
$app->memberPhotos()->remove($app->users->find($admin), $member);
$c->ok($app->memberPhotos()->url($member) === null, 'Foto entfernt');

$c->section('Wartungsmodus');
wp_set_current_user($admin);
$app->maintenance()->set(true, 'Umstellung', $app->users->find($admin));
$c->ok(rest_do_request(new WP_REST_Request('GET', '/kompanie-app/v1/me'))->get_status() === 200, 'WP-Administrator arbeitet normal');
wp_set_current_user($member);
$res = rest_do_request(new WP_REST_Request('GET', '/kompanie-app/v1/me'));
$c->ok($res->get_status() === 503 && ($res->get_data()['code'] ?? '') === 'maintenance' && ($res->get_data()['reason'] ?? '') === 'Umstellung', 'Mitglied → 503 maintenance mit Begründung');
$c->ok(rest_do_request(new WP_REST_Request('GET', '/kompanie-app/v1/config'))->get_status() === 200, '/config bleibt erreichbar');
$c->ok(str_contains(do_shortcode('[kompanie_termine]'), 'class="ka'), 'Shortcodes der Webseite zeigen weiter an (Entscheidung im Issue)');
wp_set_current_user($admin);
$app->maintenance()->set(false, null, $app->users->find($admin));
wp_set_current_user($member);
$c->ok(rest_do_request(new WP_REST_Request('GET', '/kompanie-app/v1/me'))->get_status() === 200, 'Danach wieder normal');
wp_set_current_user(0);

$c->section('Alias-Namensraum (Übernahme einer früheren App)');
KompanieApp\WordPress\RestBridge::register($app, 'kpwp-alt/v1');
$res = rest_do_request(new WP_REST_Request('GET', '/kpwp-alt/v1/config'));
$c->ok($res->get_status() === 200 && isset($res->get_data()['features']), 'Routen des Kerns auch unter einem zusätzlichen Namensraum');
$res = rest_do_request(new WP_REST_Request('POST', '/kpwp-alt/v1/push/status'));
$c->ok($res->get_status() !== 404, 'Push-Routen im Alias erreichbar (Status ' . $res->get_status() . ')');

$c->section('Datensicherung und Update');
$backup = new KompanieApp\WordPress\WpBackup($app->db, KOMPANIE_APP_VERSION, $app->config->name(), $app->files);
update_option(KompanieApp\WordPress\WpSettings::PREFIX . 'kp_test_opt', 'vorher');
update_option(KompanieApp\WordPress\WpSettings::PREFIX . 'update_state', ['serial' => 5]);
$app->cache->increment('backup_test', 900);
$eventId = $app->db->insert('events', ['title' => 'Aus der Sicherung', 'start_date' => '2031-02-03', 'description' => '', 'created_by' => $admin, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
$photoStatus = $app->db->select('SELECT id, status FROM {photos}'); // Wiederherstellen setzt alle Bilder auf „aufbereiten“
$data = $backup->export();
$c->ok($data['variant'] === 'wordpress' && ($data['settings']['kp_test_opt'] ?? null) === 'vorher', 'Sicherung enthält Daten und Einstellungen');
$c->ok(!array_key_exists('update_state', $data['settings']) && preg_grep('/^ctr_/', array_keys($data['settings'])) === [], 'Sicherung ohne Update-Zustand und Zähler');
$app->db->execute('DELETE FROM {events} WHERE id = ?', [$eventId]);
update_option(KompanieApp\WordPress\WpSettings::PREFIX . 'kp_test_opt', 'nachher');
$backup->import((string) json_encode($data));
$c->ok((int) $app->db->scalar('SELECT COUNT(*) FROM {events} WHERE title = ?', ['Aus der Sicherung']) === 1, 'Wiederherstellen bringt die Termine zurück');
$c->ok(get_option(KompanieApp\WordPress\WpSettings::PREFIX . 'kp_test_opt') === 'vorher' && get_option(KompanieApp\WordPress\WpSettings::PREFIX . 'update_state') === ['serial' => 5], 'Einstellungen zurück, Update-Zustand bleibt');
$fremd = $data;
$fremd['site'] = 'andere-website.example';
try {
    $backup->import((string) json_encode($fremd));
    $c->ok(false, 'Sicherung einer anderen Website wird abgelehnt');
} catch (RuntimeException $e) {
    $c->ok(str_contains($e->getMessage(), 'anderen Website'), 'Sicherung einer anderen Website wird abgelehnt');
}

// Dateien der Dokumente: in der Sicherung, nach dem Verlust wieder da.
$docId = $app->db->insert('documents', ['title' => 'Satzung (Sicherung)', 'category_id' => null, 'doc_date' => '2026-01-01', 'visibility' => 'members', 'file' => 'dok-sicherungstest.pdf', 'ext' => 'pdf', 'size' => 9, 'version' => 'v1', 'uploaded_by' => $admin, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
$docPath = $app->files->path('dokumente/dok-sicherungstest.pdf', false);
file_put_contents($docPath, '%PDF-test');
$withFile = $backup->export();
$c->ok(base64_decode((string) ($withFile['files']['dokumente/dok-sicherungstest.pdf'] ?? '')) === '%PDF-test', 'Sicherung enthält die Dateien der Dokumente');
unlink($docPath);
$backup->import((string) json_encode($withFile));
$c->ok(is_file($docPath) && file_get_contents($docPath) === '%PDF-test', 'Wiederherstellen legt die Dateien der Dokumente wieder ab');
$app->db->execute('DELETE FROM {documents} WHERE id = ?', [$docId]);
unlink($docPath);

// Konten (WordPress-Benutzer) in der Sicherung: nach dem Verlust der Website mit derselben ID wieder angelegt.
$verloren = wp_insert_user(['user_login' => 'kpwp_verloren', 'user_pass' => 'Geheim-123', 'user_email' => 'verloren@example.com', 'display_name' => 'Vera Loren', 'first_name' => 'Vera', 'last_name' => 'Loren', 'role' => 'subscriber']);
wp_set_current_user(0);
$app->users->setRole($verloren, KompanieApp\Core\Auth\Roles::MEMBER);
update_user_option($verloren, WpUsers::ACCOUNT_META, '1', false);
$acc = $backup->export()['accounts'];
$row = array_values(array_filter($acc, static fn (array $a): bool => $a['id'] === $verloren))[0] ?? [];
$c->ok(($row['login'] ?? '') === 'kpwp_verloren' && ($row['app_role'] ?? '') === 'member' && ($row['app_account'] ?? false) === true
    && ($row['first_name'] ?? '') === 'Vera' && wp_check_password('Geheim-123', (string) ($row['pass'] ?? '')), 'Sicherung enthält die Konten mit Passwort-Hash, Namen und Rolle in der App');
$c->ok(in_array($admin, array_column($acc, 'id'), true), 'Sicherung enthält alle Benutzer der Website, auch Administratoren');
$member2 = $d->createUser('kpwp_rolle', 'member');
$withAccounts = $backup->export();
wp_delete_user($verloren);
$app->users->setRole($member2, 'points');
$backup->import((string) json_encode($withAccounts));
$back = get_userdata($verloren);
$c->ok($back !== false && $back->user_login === 'kpwp_verloren' && $back->user_email === 'verloren@example.com' && $back->first_name === 'Vera'
    && $back->display_name === 'Vera Loren', 'Fehlendes Konto mit derselben ID, Benutzername, E-Mail und Namen wieder angelegt');
$c->ok($back !== false && wp_check_password('Geheim-123', $back->user_pass, $verloren) && $app->users->find($verloren)?->role === 'member'
    && in_array(Roles::ROLE_MEMBER, $back->roles, true), 'Wiederhergestelltes Konto: altes Passwort und Rolle in der App gelten');
$c->ok($app->users->find($member2)?->role === 'member', 'Vorhandenes Konto bekommt die Rolle in der App aus der Sicherung zurück');
// Widerspruch: Die ID gehört hier einem anderen Benutzernamen → nichts ändern.
$falsch = $withAccounts;
foreach ($falsch['accounts'] as &$a) {
    if ($a['id'] === $member2) {
        $a['login'] = 'kpwp_jemand_anders';
    }
}
unset($a);
$app->users->setRole($member2, 'points');
try {
    $backup->import((string) json_encode($falsch));
    $c->ok(false, 'Konto-ID mit anderem Benutzernamen wird abgelehnt');
} catch (RuntimeException $e) {
    $c->ok(str_contains($e->getMessage(), 'kpwp_jemand_anders') && $app->users->find($member2)?->role === 'points', 'Konto-ID mit anderem Benutzernamen wird abgelehnt, ohne etwas zu ändern');
}
// Konten anlegen nur mit dem WP-Recht dazu.
wp_delete_user($verloren);
wp_set_current_user($member2);
try {
    $backup->import((string) json_encode($withAccounts));
    $c->ok(false, 'Konten anlegen ohne WP-Recht wird abgelehnt');
} catch (RuntimeException $e) {
    $c->ok(str_contains($e->getMessage(), 'Benutzer anlegen') && get_userdata($verloren) === false, 'Konten anlegen ohne WP-Recht wird abgelehnt');
}
wp_set_current_user(0);
// Ältere Sicherung ohne Konten: wie bisher.
$alt = $withAccounts;
unset($alt['accounts']);
$backup->import((string) json_encode($alt));
$c->ok(get_userdata($verloren) === false && (int) $app->db->scalar('SELECT COUNT(*) FROM {events} WHERE title = ?', ['Aus der Sicherung']) === 1, 'Ältere Sicherung ohne Konten lässt sich weiter einspielen');
wp_delete_user($member2);
$app->db->execute('DELETE FROM {events} WHERE title = ?', ['Aus der Sicherung']);
foreach ($photoStatus as $p) {
    $app->db->update('photos', ['status' => $p['status']], ['id' => $p['id']]);
}
delete_option(KompanieApp\WordPress\WpSettings::PREFIX . 'kp_test_opt');
delete_option(KompanieApp\WordPress\WpSettings::PREFIX . 'update_state');

// Update-Ziel: ein Paket mit falscher Version wird abgelehnt, bevor WordPress den alten Ordner anfasst.
// (Ein erfolgreiches Einspielen wird hier nicht getestet: Das Plugin ist in der Test-Instanz ein Link aufs Repo.)
$target = new KompanieApp\WordPress\WpUpdateTarget();
$c->ok($target->problems(['minWordPress' => '99.0']) !== [] && $target->autoUpdateAllowed(), 'Zu alte WordPress-Version wird gemeldet; automatische Updates erlaubt');
$zipFile = wp_tempnam('kompanie-test.zip');
$zip = new ZipArchive();
$zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('kompanie-app/kompanie-app.php', "<?php\n/**\n * Plugin Name: Kompanie-App\n * Version: 9.9.9.9\n */\n");
$zip->close();
try {
    $target->install($zipFile, '1.0.0.1');
    $c->ok(false, 'Paket mit anderer Version abgelehnt');
} catch (RuntimeException $e) {
    $c->ok(str_contains($e->getMessage(), 'andere Version'), 'Paket mit anderer Version abgelehnt, mit dem Grund in der Meldung: ' . $e->getMessage());
}
$c->ok(is_file(KOMPANIE_APP_FILE) && is_link(WP_PLUGIN_DIR . '/kompanie-app'), 'Plugin-Ordner unverändert');
@unlink($zipFile);

foreach ([$member, $organizer, $manager, $admin] as $id) {
    $d->deleteUser($id);
}
wp_set_current_user(0);

printf("\n%d bestanden, %d fehlgeschlagen\n", $c->passed, count($c->failed));
if ($c->failed) {
    echo "Fehlgeschlagen:\n  - " . implode("\n  - ", $c->failed) . "\n";
    exit(1);
}
