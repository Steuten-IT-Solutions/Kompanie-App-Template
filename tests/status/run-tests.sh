#!/usr/bin/env bash
# Test des Empfangs der Statusmeldungen (tools/status-empfang) mit dem PHP-Testserver.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
T="$(mktemp -d "${TMPDIR:-/tmp}/kompanie-status.XXXXXX")"
PORT="${KOMPANIE_STATUS_PORT:-8873}"
SERVER=""
trap '[ -n "$SERVER" ] && kill "$SERVER" 2>/dev/null; rm -rf "$T"' EXIT
pass=0
ok() { echo "  ok   $1"; pass=$((pass + 1)); }
fail() { echo "  FAIL $1"; exit 1; }
cp "$ROOT/tools/status-empfang/index.php" "$T/"
php -r 'file_put_contents($argv[1] . "/config.php", "<?php return " . var_export(["password_hash" => password_hash("ein-langes-passwort", PASSWORD_DEFAULT), "data_dir" => $argv[1] . "/daten", "org" => "Test-Org"], true) . ";");' "$T"
php -S "127.0.0.1:$PORT" -t "$T" > "$T/server.log" 2>&1 &
SERVER=$!
U="http://127.0.0.1:$PORT/"
for _ in $(seq 1 30); do curl -s -o /dev/null "$U" && break; sleep 0.2; done
post() { curl -s -o /dev/null -w '%{http_code}' -X POST --data-binary "$1" "$U"; }
M='{"format":1,"kompanie":"Test-Org/kompanie-a","version":"1.4.0.3","variant":"standalone","php":"8.2","database":"sqlite","update":{"ok":false,"at":1,"to":"1.4.0.4"},"updateCheckOk":true,"gallery":null,"sent":5,"name":"<script>alert(1)</script>"}'
[ "$(post "$M")" = 200 ] && ok "Meldung angenommen" || fail "Meldung"
[ "$(post "${M/Test-Org/Fremde-Org}")" = 400 ] && ok "Kompanie einer anderen Organisation abgelehnt" || fail "Fremde Organisation"
[ "$(post '{"format":1,"kompanie":"Test-Org/x","version":"<b>"}')" = 400 ] && ok "Ungültige Meldung abgelehnt" || fail "Ungültig"
[ "$(post "$(head -c 5000 /dev/zero | tr '\0' 'x')")" = 413 ] && ok "Zu große Meldung abgelehnt" || fail "Größe"
[ "$(curl -s -o /dev/null -w '%{http_code}' "${U}?uebersicht")" = 401 ] && ok "Übersicht nur mit Anmeldung" || fail "Übersicht ohne Anmeldung"
[ "$(curl -s -o /dev/null -w '%{http_code}' -u x:falsch "${U}?uebersicht")" = 401 ] && ok "Falsches Passwort abgelehnt" || fail "Falsches Passwort"
PAGE="$(curl -s -u x:ein-langes-passwort "${U}?uebersicht")"
grep -q "Test-Org/kompanie-a" <<< "$PAGE" && grep -q "letztes Update auf 1.4.0.4 gescheitert" <<< "$PAGE" && ok "Übersicht zeigt Kompanie und Problem" || fail "Übersicht: $PAGE"
! grep -q "<script>" <<< "$PAGE" && ok "Unbekannte Felder werden verworfen, nichts Ungeprüftes in der Seite" || fail "Skript in der Seite"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$U")" = 404 ] && ok "Ohne Parameter nichts zu sehen" || fail "Startseite"
echo; echo "$pass bestanden, 0 fehlgeschlagen"
