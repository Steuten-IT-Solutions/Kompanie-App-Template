<?php
/**
 * Nur für Test-Instanzen (von scripts/setup-test-wp.sh als MU-Plugin eingebunden):
 * simuliert Google Drive mit einer kleinen Beispiel-Galerie, damit E2E-Tests ohne
 * echten Google-Zugang laufen.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		$ok = function ( $body ) {
			return array(
				'headers'  => array(),
				'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		if ( 0 === strpos( $url, 'https://oauth2.googleapis.com/token' ) ) {
			return $ok( array( 'access_token' => 'TEST', 'expires_in' => 3600 ) );
		}
		if ( 0 !== strpos( $url, 'https://www.googleapis.com/drive/v3/files' ) ) {
			return $pre;
		}
		if ( ! empty( $GLOBALS['kompanie_drive_mock_off'] ) ) {
			return $pre;
		}
		if ( preg_match( '#/files/([^/?]+)\?alt=media#', $url, $m ) ) {
			// Je Bild eine andere Farbe, damit man sie unterscheiden kann.
			$colors = array( 'IMG1' => array( 47, 93, 138 ), 'IMG2' => array( 217, 164, 65 ), 'IMG3' => array( 46, 107, 63 ), 'IMG4' => array( 120, 60, 120 ) );
			$c      = isset( $colors[ $m[1] ] ) ? $colors[ $m[1] ] : array( 120, 120, 120 );
			$im     = imagecreatetruecolor( 1200, 800 );
			imagefill( $im, 0, 0, imagecolorallocate( $im, $c[0], $c[1], $c[2] ) );
			ob_start();
			imagejpeg( $im, null, 80 );
			return $ok( ob_get_clean() );
		}
		$folder = 'application/vnd.google-apps.folder';
		$tree   = array(
			'TESTROOT' => array( array( 'id' => 'Y', 'name' => gmdate( 'Y' ), 'mimeType' => $folder ) ),
			'Y'        => array(
				array( 'id' => 'A1', 'name' => 'Sommerfest', 'mimeType' => $folder, 'createdTime' => gmdate( 'Y' ) . '-06-28T00:00:00Z' ),
				array( 'id' => 'A2', 'name' => 'Vereinsabend', 'mimeType' => $folder, 'createdTime' => gmdate( 'Y' ) . '-03-01T00:00:00Z' ),
			),
			'A1'       => array(
				array( 'id' => 'IMG1', 'name' => '1.jpg', 'mimeType' => 'image/jpeg', 'md5Checksum' => '1' ),
				array( 'id' => 'IMG2', 'name' => '2.jpg', 'mimeType' => 'image/jpeg', 'md5Checksum' => '2' ),
				array( 'id' => 'I1', 'name' => 'intern', 'mimeType' => $folder ),
			),
			'I1'       => array( array( 'id' => 'IMG3', 'name' => '3.jpg', 'mimeType' => 'image/jpeg', 'md5Checksum' => '3' ) ),
			'A2'       => array( array( 'id' => 'I2', 'name' => 'intern', 'mimeType' => $folder ) ),
			'I2'       => array( array( 'id' => 'IMG4', 'name' => '4.jpg', 'mimeType' => 'image/jpeg', 'md5Checksum' => '4' ) ),
		);
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		preg_match( "/'([^']+)' in parents/", isset( $q['q'] ) ? $q['q'] : '', $m );
		return $ok( array( 'files' => isset( $m[1], $tree[ $m[1] ] ) ? $tree[ $m[1] ] : array() ) );
	},
	10,
	3
);
