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
			parse_str( is_string( $args['body'] ) ? $args['body'] : http_build_query( (array) $args['body'] ), $form );
			// „Mit Google verbinden“: Der Code TESTCODE gilt (E2E-Test mit simulierter Anmeldung bei Google).
			if ( isset( $form['grant_type'] ) && 'authorization_code' === $form['grant_type'] ) {
				if ( isset( $form['code'] ) && 'TESTCODE' === $form['code'] ) {
					return $ok( array( 'access_token' => 'TEST', 'expires_in' => 3600, 'refresh_token' => 'TESTREFRESH', 'scope' => 'https://www.googleapis.com/auth/drive.readonly https://www.googleapis.com/auth/calendar.readonly' ) );
				}
				return array( 'headers' => array(), 'body' => '{"error":"invalid_grant"}', 'response' => array( 'code' => 400, 'message' => 'Bad Request' ), 'cookies' => array(), 'filename' => null );
			}
			return $ok( array( 'access_token' => 'TEST', 'expires_in' => 3600 ) );
		}
		if ( 0 === strpos( $url, 'https://oauth2.googleapis.com/revoke' ) ) {
			return $ok( '' );
		}
		if ( 0 === strpos( $url, 'https://www.googleapis.com/drive/v3/about' ) ) {
			return $ok( array( 'user' => array( 'emailAddress' => 'kompanie@example.org' ) ) );
		}
		if ( 0 === strpos( $url, 'https://lh3.googleusercontent.com/drive-storage/' ) ) {
			// Vorschaubild eines Videos (thumbnailLink)
			$im = imagecreatetruecolor( 1600, 900 );
			imagefill( $im, 0, 0, imagecolorallocate( $im, 160, 60, 40 ) );
			ob_start();
			imagejpeg( $im, null, 80 );
			return $ok( ob_get_clean() );
		}
		if ( 0 !== strpos( $url, 'https://www.googleapis.com/drive/v3/files' ) ) {
			return $pre;
		}
		if ( ! empty( $GLOBALS['kompanie_drive_mock_off'] ) ) {
			return $pre;
		}
		// Kleines Video (WebM, 2 Sekunden) für die Galerie (Issue #182); die Datei liegt im Repository (Link als MU-Plugin).
		$video_file = dirname( (string) realpath( __FILE__ ), 2 ) . '/fixtures/galerie-video.webm';
		if ( false !== strpos( $url, '/files/VID1?' ) ) {
			if ( false !== strpos( $url, 'fields=thumbnailLink' ) ) {
				return $ok( array( 'thumbnailLink' => 'https://lh3.googleusercontent.com/drive-storage/VID1=s220' ) );
			}
			$video = (string) file_get_contents( $video_file );
			$range = isset( $args['headers']['Range'] ) ? (string) $args['headers']['Range'] : '';
			if ( preg_match( '/^bytes=(\d+)-(\d+)$/', $range, $r ) ) {
				$end = min( (int) $r[2], strlen( $video ) - 1 );
				return array(
					'headers'  => array( 'content-type' => 'video/webm', 'content-range' => 'bytes ' . $r[1] . '-' . $end . '/' . strlen( $video ) ),
					'body'     => substr( $video, (int) $r[1], $end - (int) $r[1] + 1 ),
					'response' => array( 'code' => 206, 'message' => 'Partial Content' ),
					'cookies'  => array(),
					'filename' => null,
				);
			}
			return $ok( $video );
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
				array( 'id' => 'A3', 'name' => 'Umzug', 'mimeType' => $folder, 'createdTime' => gmdate( 'Y' ) . '-05-01T00:00:00Z' ),
			),
			'A3'       => array(
				array( 'id' => 'IMG5', 'name' => '5.jpg', 'mimeType' => 'image/jpeg', 'md5Checksum' => '5' ),
				array( 'id' => 'VID1', 'name' => 'umzug.webm', 'mimeType' => 'video/webm', 'md5Checksum' => 'v1', 'size' => (string) filesize( $video_file ), 'videoMediaMetadata' => array( 'width' => 160, 'height' => 90, 'durationMillis' => '2000' ) ),
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
