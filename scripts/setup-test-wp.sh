#!/usr/bin/env bash
# Richtet eine lokale Test-WordPress-Instanz mit dem Plugin ein.
#
#   scripts/setup-test-wp.sh [ZIELVERZEICHNIS]   (Standard: .wp-test)
#   PHP_CLI_SERVER_WORKERS=4 php -S localhost:8888 -t .wp-test/wp .wp-test/router.php
#
# Datenbank: MariaDB/MySQL, wenn KOMPANIE_WP_DB_HOST gesetzt ist
# (dazu KOMPANIE_WP_DB_NAME, KOMPANIE_WP_DB_USER, KOMPANIE_WP_DB_PASS), sonst SQLite.
#
# Danach: http://localhost:8888/app/ – Benutzer verwalter / mitglied1 / mitglied2, Passwort test1234
#         http://localhost:8888/wp-admin/ – admin / admin
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIR="$(mkdir -p "${1:-$ROOT/.wp-test}" && cd "${1:-$ROOT/.wp-test}" && pwd)"
URL="${KOMPANIE_TEST_URL:-http://localhost:8888}"
WP="php $DIR/wp-cli.phar --allow-root --path=$DIR/wp"

if [ ! -f "$DIR/wp/wp-load.php" ]; then
  mkdir -p "$DIR/wp"
  curl -sSL https://wordpress.org/latest.tar.gz | tar xz -C "$DIR/wp" --strip-components=1
  curl -sSLo "$DIR/wp-cli.phar" https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
  if [ -z "${KOMPANIE_WP_DB_HOST:-}" ]; then
    curl -sSLo "$DIR/sqlite.zip" https://downloads.wordpress.org/plugin/sqlite-database-integration.zip
    unzip -qo "$DIR/sqlite.zip" -d "$DIR/wp/wp-content/plugins/"
    SQLITE="$DIR/wp/wp-content/plugins/sqlite-database-integration"
    sed "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$SQLITE#; s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
      "$SQLITE/db.copy" > "$DIR/wp/wp-content/db.php"
  fi
fi

if ! $WP core is-installed 2>/dev/null; then
  if [ -n "${KOMPANIE_WP_DB_HOST:-}" ]; then
    $WP config create --dbname="$KOMPANIE_WP_DB_NAME" --dbuser="$KOMPANIE_WP_DB_USER" --dbpass="$KOMPANIE_WP_DB_PASS" \
      --dbhost="$KOMPANIE_WP_DB_HOST" --skip-check --force
    $WP db create >/dev/null 2>&1 || true
  else
    $WP config create --dbname=wp --dbuser=x --dbpass=x --skip-check --force
  fi
  $WP core install --url="$URL" --title="Musterkompanie Test" --admin_user=admin --admin_password=admin \
    --admin_email=admin@example.com --skip-email
  # WP-CLI leitet siteurl sonst aus dem Installationspfad ab (…/wp).
  $WP option update home "$URL"
  $WP option update siteurl "$URL"
  $WP option update timezone_string Europe/Berlin
  $WP rewrite structure '/%postname%/'
fi

ln -sfn "$ROOT/adapters/wordpress" "$DIR/wp/wp-content/plugins/kompanie-app"
$WP plugin activate kompanie-app

for u in Verwalter Mitglied1 Mitglied2; do
  login=$(echo "$u" | tr '[:upper:]' '[:lower:]')
  $WP user get "$login" >/dev/null 2>&1 || \
    $WP user create "$login" "$login@example.com" --user_pass=test1234 --role=kompanie_member --display_name="$u" >/dev/null
done
$WP user add-role verwalter kompanie_manager >/dev/null

# Beispieltermine als iCal-Feed (ersetzt im Test den Google Kalender).
Y=$(date +%Y); N=$((Y + 1))
IN7=$(php -r 'echo date("Ymd", time() + 7 * 86400);')
cat > "$DIR/wp/test.ics" <<ICS
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:e1
DTSTART;VALUE=DATE:${N}0508
DTEND;VALUE=DATE:${N}0509
SUMMARY:Frühlingsfest
LOCATION:Marktplatz
END:VEVENT
BEGIN:VEVENT
UID:e2
DTSTART;TZID=Europe/Berlin:${IN7}T190000
DTEND;TZID=Europe/Berlin:${IN7}T230000
SUMMARY:Vereinsabend
LOCATION:Vereinslokal
DESCRIPTION:Bitte pünktlich sein!
END:VEVENT
BEGIN:VEVENT
UID:e3
DTSTART;VALUE=DATE:${N}0625
DTEND;VALUE=DATE:${N}0629
SUMMARY:Sommerfest
LOCATION:Festplatz
END:VEVENT
END:VCALENDAR
ICS
$WP option update kompanie_app_calendar_ics_url "$URL/test.ics" >/dev/null

# Beispiel-Galerie über simuliertes Google Drive (nur Test-Instanz).
mkdir -p "$DIR/wp/wp-content/mu-plugins"
ln -sfn "$ROOT/tests/wordpress/drive-mock.php" "$DIR/wp/wp-content/mu-plugins/kompanie-drive-mock.php"
$WP eval '
$app = KompanieApp\WordPress\Plugin::app();
// Alle Bereiche an (eine neue Installation hat keine; sonst wählt sie der Admin in der Einrichtung).
$app->features()->update( array_fill_keys( KompanieApp\Core\Domain\Features::keys(), true ) );
$key = openssl_pkey_new( array( "private_key_bits" => 2048, "private_key_type" => OPENSSL_KEYTYPE_RSA ) );
openssl_pkey_export( $key, $pem );
$app->drive()->saveServiceAccount( wp_json_encode( array( "client_email" => "test@example.iam.gserviceaccount.com", "private_key" => $pem ) ) );
$app->gallery()->setRoot( "TESTROOT" );
$app->gallery()->sync();
$app->gallery()->processPending( 60 );
' >/dev/null

cat > "$DIR/router.php" <<'PHP'
<?php
// Router für den PHP-Entwicklungsserver: vorhandene Dateien direkt, sonst WordPress.
$root = __DIR__ . '/wp';
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$file = realpath( $root . $path );
if ( '/' !== $path && $file && is_file( $file ) ) {
	if ( '.php' !== substr( $file, -4 ) ) {
		return false;
	}
	chdir( dirname( $file ) );
	$_SERVER['SCRIPT_NAME']     = $path;
	$_SERVER['SCRIPT_FILENAME'] = $file;
	require $file;
	return true;
}
if ( $file && is_dir( $file ) && is_file( $file . '/index.php' ) ) {
	chdir( $file );
	require $file . '/index.php';
	return true;
}
chdir( $root );
$_SERVER['SCRIPT_NAME'] = '/index.php';
require $root . '/index.php';
PHP

echo "Test-WordPress bereit in $DIR"
echo "Start: PHP_CLI_SERVER_WORKERS=4 php -S ${URL#*://} -t $DIR/wp $DIR/router.php"
