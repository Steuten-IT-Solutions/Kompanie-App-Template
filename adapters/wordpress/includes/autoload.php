<?php
/**
 * Autoloader ohne Composer. Der Kern liegt im fertigen Plugin unter core/,
 * während der Entwicklung unter packages/core/src im Repo. PHPMailer (Mailversand, Issue #172) kommt von WordPress selbst
 * (wp-includes/PHPMailer, Namensraum PHPMailer\PHPMailer): keine zweite Kopie, kein Namenskonflikt. Lädt WordPress die
 * Klassen selbst (require_once), bleibt es bei einer Kopie.
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( string $class ): void {
		static $roots = null;
		if ( null === $roots ) {
			$core  = is_dir( KOMPANIE_APP_DIR . 'core' ) ? KOMPANIE_APP_DIR . 'core/' : dirname( KOMPANIE_APP_DIR, 2 ) . '/packages/core/src/';
			$roots = array(
				'KompanieApp\\Core\\'      => $core,
				'KompanieApp\\WordPress\\' => KOMPANIE_APP_DIR . 'includes/',
				'PHPMailer\\PHPMailer\\'    => ABSPATH . WPINC . '/PHPMailer/',
			);
		}
		foreach ( $roots as $prefix => $dir ) {
			if ( 0 === strpos( $class, $prefix ) ) {
				$file = $dir . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
				if ( is_file( $file ) ) {
					require_once $file;
				}
				return;
			}
		}
	}
);
