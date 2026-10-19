<?php
/**
 * Setzt die App-Daten der Test-Instanz für einen neuen E2E-Lauf zurück:
 *   php .wp-test/wp-cli.phar --allow-root --path=.wp-test/wp eval-file tests/wordpress/reset-e2e.php
 * Löscht Punkte, Strafen, Katalog, öffentliche Termine und in Tests angelegte Konten;
 * setzt Rollen und Passwörter der Testbenutzer zurück. Galerie und Kalender bleiben.
 */

if (!defined('ABSPATH')) {
    exit(1);
}
require_once ABSPATH . 'wp-admin/includes/user.php';

$db = KompanieApp\WordPress\Plugin::app()->db;
foreach (['points', 'penalties', 'point_catalog', 'events'] as $table) {
    $db->execute('DELETE FROM {' . $table . '}');
}
foreach (get_users(['search' => 'neue.testperson*', 'search_columns' => ['user_login']]) as $u) {
    wp_delete_user($u->ID);
}
// Rolle „Spieß“ der Testperson verwalter (legt scripts/setup-test-wp.sh an; hier für den Fall, dass ein Test sie gelöscht hat).
$roles = KompanieApp\WordPress\Plugin::app()->roles();
$spiess = array_search('Spieß', array_column($roles->all(), 'label', 'key'), true);
if ($spiess === false) {
    $spiess = $roles->create(['label' => 'Spieß', 'capabilities' => ['member', 'manage_points', 'manage_penalties', 'manage_events']])['key'];
}
foreach (['verwalter' => (string) $spiess, 'mitglied1' => 'member', 'mitglied2' => 'member'] as $login => $level) {
    $u = get_user_by('login', $login);
    if ($u) {
        wp_set_password('test1234', $u->ID);
        KompanieApp\WordPress\Plugin::app()->users->setRole($u->ID, $level);
    }
}
// Zähler (z. B. Passwortänderungen je 15 Minuten) und den Einrichtungsassistenten zurücksetzen.
global $wpdb;
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like(KompanieApp\WordPress\WpSettings::PREFIX . KompanieApp\WordPress\WpCache::COUNTER) . '%'));
delete_option(KompanieApp\WordPress\WpSettings::PREFIX . 'setup_done');
// Alle Bereiche wieder an (der Test der Einrichtung schaltet einen aus und wieder ein).
KompanieApp\WordPress\Plugin::app()->features()->update(array_fill_keys(KompanieApp\Core\Domain\Features::keys(), true));
delete_option(KompanieApp\WordPress\WpSettings::PREFIX . 'backup_last'); // Ampel „Datensicherung“ wieder gelb
echo "Testdaten zurückgesetzt.\n";
