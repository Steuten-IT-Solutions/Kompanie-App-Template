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
foreach (['verwalter' => 'manager', 'mitglied1' => 'member', 'mitglied2' => 'member'] as $login => $level) {
    $u = get_user_by('login', $login);
    if ($u) {
        wp_set_password('test1234', $u->ID);
        KompanieApp\WordPress\Plugin::app()->users->setLevel($u->ID, KompanieApp\Core\Auth\Level::from($level));
    }
}
// Zähler (z. B. Passwortänderungen je 15 Minuten) und den Einrichtungsassistenten zurücksetzen.
global $wpdb;
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like(KompanieApp\WordPress\WpSettings::PREFIX . KompanieApp\WordPress\WpCache::COUNTER) . '%'));
delete_option(KompanieApp\WordPress\WpSettings::PREFIX . 'setup_done');
echo "Testdaten zurückgesetzt.\n";
