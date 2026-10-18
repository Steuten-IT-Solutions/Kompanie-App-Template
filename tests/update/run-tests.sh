#!/usr/bin/env bash
# Ende-zu-Ende-Test von Installer und Updates der eigenständigen Version mit einem lokalen Release-Server
# und einem Wegwerf-Schlüssel:
#   install.php (neu) → Update → Zurückrollen (wird danach übersprungen) → Zurückziehen (automatischer
#   Wechsel) → manipuliertes Paket → alte Liste → install.php im Reparaturmodus → Liste einer anderen Kompanie.
# Vorher: scripts/build-frontend.sh (Beispielkonfiguration).
set -euo pipefail
# Ausgaben erst vollständig lesen, dann prüfen: „… | grep -q“ bricht die Pipe ab, und pipefail meldet das als Fehler.
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
T="$(mktemp -d "${TMPDIR:-/tmp}/kompanie-update.XXXXXX")"
PORT_R="${KOMPANIE_UPDATE_PORT:-8897}"
PORT_A=$((PORT_R + 1))
SERVERS=()
cleanup() { for p in "${SERVERS[@]}"; do pkill -P "$p" 2>/dev/null || true; kill "$p" 2>/dev/null || true; done; rm -rf "$T"; }
trap cleanup EXIT
cd "$ROOT"
pass=0
ok() { echo "  ok   $1"; pass=$((pass + 1)); }
fail() { echo "  FAIL $1"; exit 1; }

export KOMPANIE_SIGNING_KEY="$(php scripts/keygen.php | sed -n 2p)"
CORE="$(tr -d '[:space:]' < VERSION)"
SRV="$T/srv"
R="http://127.0.0.1:$PORT_R"
APPURL="http://127.0.0.1:$PORT_A/"
mkdir -p "$SRV" "$T/www"

release() { # release <build> [kompanie]: Pakete bauen und in die Liste aufnehmen (immer early, wie ein neuer Kern)
  php scripts/package.php --config=config/kompanie.example.json --version="$CORE.$1" --out="$SRV/r$1" \
    --kompanie="${2:-org/test}" --updates-url="$R/updates.json" --require-signature --trust-signing-key > /dev/null
  [ -n "${2:-}" ] && return
  php scripts/updates.php --list="$SRV/updates.json" --add="$SRV/r$1/release.json" --base-url="$R/r$1/" --kompanie=org/test --channel=early > /dev/null
}
promote() { php scripts/updates.php --list="$SRV/updates.json" --promote="$CORE.$1" > /dev/null; }
withdraw() { php scripts/updates.php --list="$SRV/updates.json" --withdraw="$CORE.$1" > /dev/null; }
start() { # start <port> <docroot>
  PHP_CLI_SERVER_WORKERS=4 php -S "127.0.0.1:$1" -t "$2" > "$T/server-$1.log" 2>&1 &
  SERVERS+=($!)
  for _ in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$1/" && return; sleep 0.3; done
  fail "Server auf Port $1 startet nicht"
}
version() { curl -s "${APPURL}index.php?r=/api/v1/config" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["version"] ?? "";'; }
cli() { php "$T/www/bin/kompanie.php" "$@"; }
task() { # tägliche Update-Aufgabe sofort ausführen
  php -r 'require $argv[1] . "/autoload.php";
    $k = new KompanieApp\Standalone\Kernel($argv[1], new KompanieApp\Standalone\HttpContext([], ["REQUEST_METHOD" => "GET", "HTTP_HOST" => "127.0.0.1"], "/"));
    $k->upgrade(); $k->app()->updates()->runTask();' "$T/www"
}
installer() { # installer <ausgabe> [Felder …]: Formular laden (CSRF) und absenden
  local out="$1"; shift
  rm -f "$T/jar"
  local token
  token="$(curl -s -c "$T/jar" "${APPURL}install.php" | sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p' | head -1)"
  curl -s -b "$T/jar" --data-urlencode "csrf=$token" "$@" "${APPURL}install.php" > "$out"
}

release 1 && promote 1
start "$PORT_R" "$SRV"

# 1. Neu installieren mit install.php (aus dem Release, mit Update-Adresse und Schlüssel)
cp "$SRV/r1/install.php" "$T/www/install.php"
start "$PORT_A" "$T/www"
installer "$T/i1.html" --data-urlencode "url=$APPURL" --data-urlencode "name=Erste Admin" --data-urlencode "login=admin" \
  --data-urlencode "password=geheim-12345" --data-urlencode "password2=geheim-12345" --data-urlencode "db=sqlite"
grep -q "Die App ist eingerichtet" "$T/i1.html" || { sed 's/<[^>]*>/ /g' "$T/i1.html" | tr -s ' ' | head -c 600; fail "Installer"; }
[ ! -f "$T/www/install.php" ] && ok "install.php richtet die App ein und löscht sich selbst" || fail "install.php noch da"
[ "$(version)" = "$CORE.1" ] && ok "Installierte Version $CORE.1 läuft" || fail "Version: $(version)"
if [ -f "$T/www/data-dir.php" ]; then
  ok "Datenverzeichnis wäre erreichbar gewesen (kein .htaccess): nach außerhalb verlegt"
  [ "$(curl -s -o /dev/null -w '%{http_code}' "${APPURL}data/config.php")" = 404 ] && ok "Keine Daten mehr im Web-Verzeichnis" || fail "Daten im Web-Verzeichnis"
fi

# Daten, die alle Updates, das Zurückrollen und die Reparatur überstehen müssen
php -r 'require $argv[1] . "/autoload.php";
  $k = new KompanieApp\Standalone\Kernel($argv[1], new KompanieApp\Standalone\HttpContext([], ["REQUEST_METHOD" => "GET", "HTTP_HOST" => "127.0.0.1"], "/"));
  $k->upgrade(); $k->db()->insert("events", ["title" => "Bleibt erhalten", "start_date" => "2030-01-01", "description" => "", "created_by" => 1, "created_at" => "2026-01-01 00:00:00", "updated_at" => "2026-01-01 00:00:00"]);' "$T/www"

# 2. Neue Version: erst early (keine Wirkung auf stable), nach der Freigabe Update
release 2
grep -q "Keine neuere Version" <<< "$(cli update-check)" && ok "Version im Kanal early wird nicht angeboten" || fail "early angeboten"
promote 2
grep -q "Neue Version: $CORE.2" <<< "$(cli update-check)" && ok "Freigegebene Version wird angeboten" || fail "update-check"
task
[ "$(version)" = "$CORE.2" ] && ok "Automatisches Update auf $CORE.2" || fail "Version nach Update: $(version)"

# 3. Zurückrollen: danach wird $CORE.2 beim automatischen Update übersprungen
cli rollback --version="$CORE.1" > /dev/null
[ "$(version)" = "$CORE.1" ] && ok "Zurückrollen auf $CORE.1" || fail "Version nach Zurückrollen: $(version)"
task
[ "$(version)" = "$CORE.1" ] && ok "Zurückgerollte Version wird nicht automatisch wieder eingespielt" || fail "Übersprungen: $(version)"

# 4. Neuere Version: wieder automatisch; dann zurückgezogen → automatisch auf die zuletzt freigegebene
release 3 && promote 3
task
[ "$(version)" = "$CORE.3" ] && ok "Neuere Version wird wieder automatisch eingespielt" || fail "Version: $(version)"
withdraw 3
task
[ "$(version)" = "$CORE.2" ] && ok "Zurückgezogene Version: automatisch zurück auf $CORE.2" || fail "Version nach Zurückziehen: $(version)"

# 5. Manipuliertes Paket wird abgelehnt, die bisherige Version läuft weiter
cp "$SRV/updates.json" "$T/alte-liste.json"
release 4 && promote 4
echo "manipuliert" >> "$SRV/r4/kompanie-app-standalone.zip"
if cli update > "$T/u4.log" 2>&1; then fail "Manipuliertes Paket eingespielt"; fi
grep -q "stimmt nicht mit der signierten Liste" "$T/u4.log" && [ "$(version)" = "$CORE.2" ] && ok "Manipuliertes Paket abgelehnt, $CORE.2 läuft weiter" || fail "Manipulation: $(cat "$T/u4.log")"

# 6. Eine ältere (ebenfalls signierte) Liste wird nicht angenommen
cp "$T/alte-liste.json" "$SRV/updates.json"
if cli update-check > "$T/u5.log" 2>&1; then fail "Ältere Liste angenommen"; fi
grep -q "älter als die zuletzt geladene" "$T/u5.log" && ok "Ältere Liste abgelehnt" || fail "Alte Liste: $(cat "$T/u5.log")"

# 7. install.php im Reparaturmodus: nur mit Admin-Anmeldung, spielt die freigegebene Version neu ein
release 5 && promote 5
cp "$SRV/r5/install.php" "$T/www/install.php"
installer "$T/i2.html" --data-urlencode "login=admin" --data-urlencode "password=falsch-falsch"
grep -q "Passwort ist falsch" "$T/i2.html" && ok "Reparaturmodus verlangt einen Admin" || fail "Reparatur ohne Anmeldung"
installer "$T/i3.html" --data-urlencode "login=admin" --data-urlencode "password=geheim-12345"
grep -q "Version $CORE.5 ist eingespielt" "$T/i3.html" && [ "$(version)" = "$CORE.5" ] && ok "Reparaturmodus spielt $CORE.5 ein, Daten bleiben" || fail "Reparatur: $(sed 's/<[^>]*>/ /g' "$T/i3.html" | tr -s ' ' | head -c 400)"
grep -q '"auth"' <<< "$(curl -s "${APPURL}index.php?r=/api/v1/config")" && ok "App läuft nach der Reparatur" || fail "App nach Reparatur"
EV="$(curl -s "${APPURL}index.php?r=/api/v1/events")"; ST="$(cli status)"
grep -q "Bleibt erhalten" <<< "$EV" && grep -qx "Benutzer: 1" <<< "$ST" && ok "Termine und Konten sind nach allen Updates und der Reparatur noch da" || fail "Daten verloren: $EV / $ST"

# 8. Installer einer anderen Kompanie nimmt diese Liste nicht an
release 6 org/andere
rm -rf "$T/www2" && mkdir "$T/www2" && cp "$SRV/r6/install.php" "$T/www2/install.php"
start $((PORT_R + 2)) "$T/www2"
APPURL="http://127.0.0.1:$((PORT_R + 2))/" installer "$T/i4.html" --data-urlencode "url=http://127.0.0.1:$((PORT_R + 2))/" --data-urlencode "name=X" \
  --data-urlencode "login=admin" --data-urlencode "password=geheim-12345" --data-urlencode "password2=geheim-12345"
grep -q "gehört nicht zu dieser Kompanie" "$T/i4.html" && [ ! -d "$T/www2/core" ] && ok "Liste einer anderen Kompanie abgelehnt, nichts installiert" || fail "Fremde Liste: $(sed 's/<[^>]*>/ /g' "$T/i4.html" | tr -s ' ' | head -c 400)"

echo; echo "$pass bestanden, 0 fehlgeschlagen"
