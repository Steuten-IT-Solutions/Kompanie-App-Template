<?php
/**
 * Plugin Name:       Kompanie-App
 * Description:       Mitglieder-App (PWA) mit Terminen, News, Galerie, Punkten und Strafen. Erreichbar unter /app/.
 * Version:           0.1.0
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * Author:            Kompanie-App
 * License:           GPL-2.0-or-later
 * Text Domain:       kompanie-app
 * Update URI:        false
 */

defined( 'ABSPATH' ) || exit;

define( 'KOMPANIE_APP_VERSION', '0.1.0' );
define( 'KOMPANIE_APP_FILE', __FILE__ );
define( 'KOMPANIE_APP_DIR', plugin_dir_path( __FILE__ ) );
define( 'KOMPANIE_APP_URL', plugin_dir_url( __FILE__ ) );

if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>Die Kompanie-App braucht PHP 8.1 oder neuer. Bitte die PHP-Version im Kundenmenü des Hosters umstellen.</p></div>';
		}
	);
	return;
}

require_once KOMPANIE_APP_DIR . 'includes/autoload.php';

register_activation_hook( __FILE__, array( 'KompanieApp\\WordPress\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'KompanieApp\\WordPress\\Plugin', 'deactivate' ) );

KompanieApp\WordPress\Plugin::boot();
