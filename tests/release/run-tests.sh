#!/usr/bin/env bash
# Test des Release-Ablaufs mit einem Wegwerf-Schlüssel:
# Schlüssel → Pakete (signiert) → Update-Liste (befördern, zurückziehen) → Download-Seite →
# eigenständiges Paket entpacken, einrichten und starten.
# Vorher: scripts/build-frontend.sh (Beispielkonfiguration).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
T="$(mktemp -d "${TMPDIR:-/tmp}/kompanie-release.XXXXXX")"
PORT="${KOMPANIE_RELEASE_PORT:-8899}"
SERVER=""
trap '[ -n "$SERVER" ] && kill $SERVER 2>/dev/null; rm -rf "$T"' EXIT
cd "$ROOT"
pass=0
ok() { echo "  ok   $1"; pass=$((pass + 1)); }
fail() { echo "  FAIL $1"; exit 1; }

export KOMPANIE_SIGNING_KEY="$(php scripts/keygen.php | sed -n 2p)"
CORE="$(tr -d '[:space:]' < VERSION)"
BASE=https://github.com/org/kompanie-test/releases/download

php scripts/package.php --config=config/kompanie.example.json --version="$CORE.1" --out="$T/r1" --kompanie=org/kompanie-test --require-signature --trust-signing-key > /dev/null
[ -f "$T/r1/kompanie-app-wordpress.zip" ] && [ -f "$T/r1/kompanie-app-standalone.zip" ] && ok "Pakete gebaut" || fail "Pakete fehlen"
grep -q '"format": "kompanie-app-signed"' "$T/r1/release.json" && ok "release.json signiert" || fail "release.json nicht signiert"
if KOMPANIE_SIGNING_KEY= php scripts/package.php --config=config/kompanie.example.json --version="$CORE.9" --out="$T/x" --require-signature 2> /dev/null; then
  fail "Release ohne Schlüssel darf nicht entstehen"
else
  ok "Ohne Schlüssel kein Release"
fi

php scripts/updates.php --list="$T/updates.json" --add="$T/r1/release.json" --base-url="$BASE/v$CORE.1/" > /dev/null
show() { php scripts/updates.php --list="$T/updates.json" --show | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo $d["channels"]["early"] ?? "-", " ", $d["channels"]["stable"] ?? "-", "\n";'; }
[ "$(show)" = "$CORE.1 -" ] && ok "Neue Version zuerst in early" || fail "Kanal: $(show)"
php scripts/updates.php --list="$T/updates.json" --promote="$CORE.1" > /dev/null
[ "$(show)" = "$CORE.1 $CORE.1" ] && ok "Befördern nach stable" || fail "Kanal: $(show)"

# Nur das Aussehen geändert (gleicher Kern): direkt stable
php scripts/package.php --config=config/kompanie.example.json --version="$CORE.2" --out="$T/r2" --kompanie=org/kompanie-test --require-signature --trust-signing-key > /dev/null
php scripts/updates.php --list="$T/updates.json" --add="$T/r2/release.json" --base-url="$BASE/v$CORE.2/" > /dev/null
[ "$(show)" = "$CORE.2 $CORE.2" ] && ok "Gleicher Kern: direkt stable" || fail "Kanal: $(show)"
php scripts/updates.php --list="$T/updates.json" --withdraw="$CORE.2" > /dev/null
[ "$(show)" = "$CORE.1 $CORE.1" ] && ok "Zurückziehen setzt beide Kanäle auf die vorige Version" || fail "Kanal: $(show)"

# Manipulierte Liste wird abgelehnt
php -r '$e = json_decode(file_get_contents($argv[1]), true); $e["payload"] = base64_encode(str_replace("kompanie-test", "boese", base64_decode($e["payload"]))); file_put_contents($argv[2], json_encode($e));' "$T/updates.json" "$T/bad.json"
if php scripts/updates.php --list="$T/bad.json" --show > /dev/null 2>&1; then fail "Manipulierte Liste angenommen"; else ok "Manipulierte Liste abgelehnt"; fi
if KOMPANIE_SIGNING_KEY="$(php scripts/keygen.php | sed -n 2p)" php scripts/updates.php --list="$T/updates.json" --show > /dev/null 2>&1; then
  fail "Liste mit fremdem Schlüssel angenommen"
else
  ok "Liste eines fremden Schlüssels abgelehnt"
fi

php scripts/download-page.php --updates="$T/updates.json" --out="$T/site" > /dev/null
[ -f "$T/site/handbuch/index.html" ] && grep -q 'href="handbuch/index.html"' "$T/site/index.html" && ok "Download-Seite mit Handbuch (handbuch/)" || fail "Handbuch fehlt auf der Seite"
grep -q "$BASE/v$CORE.1/kompanie-app-wordpress.zip" "$T/site/index.html" && ok "Download-Seite verlinkt die stable-Version" || fail "Download-Seite ohne Link"

# Eigenständiges Paket entpacken, einrichten, starten
mkdir "$T/www" && (cd "$T/www" && unzip -q "$T/r1/kompanie-app-standalone.zip")
APP="$T/www/kompanie-app"
php "$APP/bin/kompanie.php" install --url="http://127.0.0.1:$PORT/" > /dev/null
php -S "127.0.0.1:$PORT" -t "$APP" > "$T/server.log" 2>&1 &
SERVER=$!
for _ in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$PORT/" && break; sleep 0.3; done
VERSION_SEEN="$(curl -s "http://127.0.0.1:$PORT/index.php?r=/api/v1/config" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["version"] ?? "";')"
[ "$VERSION_SEEN" = "$CORE.1" ] && ok "Paket läuft und meldet seine Version" || fail "Version: $VERSION_SEEN"
[ "$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/")" = 200 ] && ok "App-Seite aus dem Paket" || fail "App-Seite"
# Inhaltsverzeichnisse erst vollständig lesen (grep -q würde unzip mitten in der Ausgabe abbrechen).
SA_LIST="$(unzip -l "$T/r1/kompanie-app-standalone.zip")"
WP_LIST="$(unzip -l "$T/r1/kompanie-app-wordpress.zip")"
grep -qE 'tests/|e2e/|node_modules' <<< "$SA_LIST" && fail "Paket enthält Entwicklungsdateien" || ok "Paket ohne Entwicklungsdateien"
grep -q 'kompanie-app/core/App.php' <<< "$WP_LIST" && ok "Plugin enthält den Kern" || fail "Kern fehlt im Plugin"
# PHPMailer (Issue #172): nur im eigenständigen Paket, mit Lizenz, unter src/ (wird auch von älteren Updatern getauscht)
for f in PHPMailer.php SMTP.php Exception.php LICENSE; do
  grep -q "kompanie-app/src/lib/PHPMailer/$f" <<< "$SA_LIST" || fail "PHPMailer: $f fehlt im eigenständigen Paket"
done
grep -qE 'PHPMailer/(PHPMailer|SMTP)\.php' <<< "$WP_LIST" && fail "Plugin bringt eine eigene PHPMailer-Kopie mit" || ok "PHPMailer mit Lizenz nur im eigenständigen Paket (WordPress nutzt die eigene Kopie)"
grep -q 'GNU LESSER GENERAL PUBLIC LICENSE' <<< "$(unzip -p "$T/r1/kompanie-app-standalone.zip" kompanie-app/src/lib/PHPMailer/LICENSE)" || fail "Lizenztext von PHPMailer fehlt"
MAILER="$(cd "$APP" && php -r 'require "autoload.php"; echo (new ReflectionClass(PHPMailer\PHPMailer\PHPMailer::class))->getFileName();')"
[ "$MAILER" = "$(cd "$APP" && pwd -P)/src/lib/PHPMailer/PHPMailer.php" ] && ok "Paket lädt PHPMailer aus src/lib/PHPMailer" || fail "PHPMailer geladen aus: $MAILER"
for zip in standalone wordpress; do
  unzip -p "$T/r1/kompanie-app-$zip.zip" kompanie-app/app/assets/.htaccess | grep -q 'max-age=31536000, immutable' \
    && unzip -p "$T/r1/kompanie-app-$zip.zip" kompanie-app/app/.htaccess | grep -q 'AddOutputFilterByType DEFLATE' \
    || fail "Cache-Header/Komprimierung fehlen im Paket ($zip)"
done
ok "Beide Pakete: App-Dateien ein Jahr im Browser-Cache, Komprimierung (app/.htaccess, app/assets/.htaccess)"
# Lizenzen (Issue #173): LICENSE und die beim Bauen erzeugten Drittanbieter-Hinweise in beiden Paketen; Build-Werkzeuge
# (sharp mit libvips, ajv, …) weder als Datei im Paket noch in den Hinweisen.
for zip in standalone wordpress; do
  LIST="$(unzip -l "$T/r1/kompanie-app-$zip.zip")"
  unzip -p "$T/r1/kompanie-app-$zip.zip" kompanie-app/LICENSE | grep -q 'or (at your option) any later' \
    && unzip -p "$T/r1/kompanie-app-$zip.zip" kompanie-app/THIRD-PARTY-NOTICES.txt | grep -q '^react-dom ' \
    || fail "LICENSE oder THIRD-PARTY-NOTICES.txt fehlt im Paket ($zip)"
  if grep -q 'kompanie-app/app/THIRD-PARTY-NOTICES.txt' <<< "$LIST"; then fail "THIRD-PARTY-NOTICES.txt doppelt (app/) im Paket ($zip)"; fi
  if grep -qiE 'sharp|libvips|@img/|/ajv|napi-rs|node_modules' <<< "$LIST"; then fail "Build-Werkzeuge im Paket ($zip)"; fi
  NOTICES="$(unzip -p "$T/r1/kompanie-app-$zip.zip" kompanie-app/THIRD-PARTY-NOTICES.txt)"
  if grep -qE '^(sharp|@img/|ajv|@napi-rs/|typescript|@vitejs/|prettier) ' <<< "$NOTICES"; then fail "Build-Werkzeug in THIRD-PARTY-NOTICES.txt ($zip)"; fi
done
ok "Beide Pakete: LICENSE und THIRD-PARTY-NOTICES.txt, keine Build-Werkzeuge"

# Veröffentlichen wie im Workflow: Build-Ergebnis gefiltert übernehmen, im Veröffentlichungs-Job selbst
# paketieren und signieren, Release + gh-pages (mit einem Bare-Repo statt GitHub und einer Attrappe der GitHub-CLI).
mkdir -p "$T/untrusted/brand" "$T/untrusted/screenshots"
cp -r packages/frontend/dist "$T/untrusted/dist"
cp packages/frontend/src/generated/{brand.json,theme.css,logo.png} "$T/untrusted/brand/" && cp packages/frontend/public/icons/favicon-32.png "$T/untrusted/brand/favicon.png"
cp packages/frontend/public/icons/favicon-32.png "$T/untrusted/screenshots/1-termine.png"
TYPES=js,css,html,png,jpg,jpeg,webp,svg,ico,json,webmanifest,woff2,txt
for bad in "x.php" "bild.php.png" ".htaccess" "assets/.user.ini"; do
  mkdir -p "$(dirname "$T/untrusted/dist/$bad")" && echo "<?php" > "$T/untrusted/dist/$bad"
  if php scripts/import-build.php --from="$T/untrusted/dist" --to="$T/checked/dist" --types="$TYPES" > /dev/null 2>&1; then fail "Build-Ergebnis mit $bad übernommen"; fi
  rm "$T/untrusted/dist/$bad"
done
ok "Build-Ergebnis mit PHP-, .htaccess- oder Punktdateien wird abgelehnt"
ln -s /etc/passwd "$T/untrusted/dist/link.txt"
if php scripts/import-build.php --from="$T/untrusted/dist" --to="$T/checked/dist" --types="$TYPES" > /dev/null 2>&1; then fail "Symbolischer Link übernommen"; else ok "Symbolische Links werden abgelehnt"; fi
rm "$T/untrusted/dist/link.txt"
php scripts/import-build.php --from="$T/untrusted/dist" --to="$T/checked/dist" --types="$TYPES" > /dev/null
php scripts/import-build.php --from="$T/untrusted/brand" --to="$T/checked/brand" --types=json,css,png > /dev/null
php scripts/import-build.php --from="$T/untrusted/screenshots" --to="$T/checked/screenshots" --types=png > /dev/null
ok "Gebaute Oberfläche übernommen"

git init --quiet --bare "$T/remote.git"
cat > "$T/gh" <<'GH'
#!/usr/bin/env bash
# Attrappe der GitHub-CLI: merkt sich Releases als Ordner (DRAFT/PRERELEASE als Markierungsdateien)
D="$(dirname "$0")/releases"; mkdir -p "$D"
case "$1 $2" in
  "release view") [ -d "$D/$3" ] || { echo "release not found" >&2; exit 1; }; [ -f "$D/$3/DRAFT" ] && echo true || echo false ;;
  "release list") for r in "$D"/*/; do [ -d "$r" ] || continue; case "$*" in *--exclude-drafts*) [ -f "$r/DRAFT" ] && continue ;; esac; basename "$r"; done ;;
  "release delete") [ -f "$D/$3/DRAFT" ] || { echo "veröffentlicht gelöscht: $3" > "$D/$3.FEHLER"; exit 1; }; rm -rf "${D:?}/$3" ;;
  "release create") TAG="$3"; shift 3; mkdir -p "$D/$TAG"; for a in "$@"; do [ -f "$a" ] && cp "$a" "$D/$TAG/"; [ "$a" = --draft ] && touch "$D/$TAG/DRAFT"; [ "$a" = --prerelease ] && touch "$D/$TAG/PRERELEASE"; done; true ;;
  "release edit") case "$*" in *--prerelease=true*) touch "$D/$3/PRERELEASE" ;; *--prerelease=false*) rm -f "$D/$3/PRERELEASE" ;; esac
    case "$*" in *--draft=false*) rm -f "$D/$3/DRAFT" ;; esac ;;
  "api "*) true ;;
esac
GH
chmod +x "$T/gh"
SHA1=1111111111111111111111111111111111111111
SHA2=2222222222222222222222222222222222222222
SHA3=3333333333333333333333333333333333333333
pub() { env REPO=org/kompanie-test KOMPANIE_SHA="$1" CONFIG=config/kompanie.example.json DIST="$T/checked/dist" BRAND="$T/checked/brand" \
  SCREENSHOTS="$T/checked/screenshots" STABLE_CORE="${2:-}" TRUST_SIGNING_KEY=1 GIT_REMOTE="file://$T/remote.git" GH="$T/gh" GH_TOKEN=x scripts/publish-release.sh; }
pages() { rm -rf "$T/pages" && git clone --quiet --branch gh-pages "file://$T/remote.git" "$T/pages"; }
list() { php scripts/updates.php --list="$T/pages/updates.json" --show; }

pub "$SHA1" > /dev/null
R="$T/releases/v$CORE.1"
[ "$(ls "$R" | sort | tr '\n' ' ')" = "PRERELEASE install.php kompanie-app-standalone.zip kompanie-app-wordpress.zip release.json " ] \
  && ok "Erstes Veröffentlichen: Version $CORE.1 mit genau vier Dateien, neuer Kern als Vorab-Release" || fail "Release-Dateien: $(ls "$R")"
pages
[ -f "$T/pages/updates.json" ] && [ -f "$T/pages/index.html" ] && [ -f "$T/pages/brand/theme.css" ] && ok "gh-pages: Update-Liste, Download-Seite, Branding" || fail "gh-pages-Inhalt"
INFO="$(php -r '$e = json_decode(file_get_contents($argv[1]), true); $r = json_decode(base64_decode($e["payload"]), true);
  echo $r["kompanie"], " ", $r["kompanieCommit"], " ", $r["installer"]["sha256"] === hash_file("sha256", $argv[2]) ? "ok" : "falsch", " ", strlen($r["coreCode"]);' "$R/release.json" "$R/install.php")"
[ "$INFO" = "org/kompanie-test $SHA1 ok 64" ] && ok "release.json: Kompanie, Commit, Prüfsumme von install.php, Code-Prüfsumme" || fail "release.json: $INFO"
grep -q "\"kompanieCommit\": \"$SHA1\"" <<< "$(list)" && ok "Update-Liste kennt den Stand des Kompanie-Repos" || fail "kompanieCommit fehlt in der Liste"

OUT2="$(pub "$SHA1")"
grep -q "schon als Version $CORE.1 veröffentlicht" <<< "$OUT2" && [ ! -d "$T/releases/v$CORE.2" ] && ok "Gleicher Stand: nichts doppelt" || fail "Re-run: $OUT2"

# Abbruch nach dem Anlegen des Entwurfs (Liste nicht aktualisiert): Entwurf wird ersetzt, dann veröffentlicht
mkdir -p "$T/releases/v$CORE.2" && echo alt > "$T/releases/v$CORE.2/release.json" && touch "$T/releases/v$CORE.2/DRAFT"
pub "$SHA2" > /dev/null
pages
grep -q kompanie-app-signed "$T/releases/v$CORE.2/release.json" && [ ! -f "$T/releases/v$CORE.2/DRAFT" ] && grep -q "\"$CORE.2\"" <<< "$(list)" \
  && ok "Entwurf eines abgebrochenen Laufs wird ersetzt und veröffentlicht" || fail "Abgebrochener Entwurf"

# Abbruch nach dem Push, vor dem Veröffentlichen: der nächste Lauf macht den Entwurf öffentlich
touch "$T/releases/v$CORE.2/DRAFT"
OUT3="$(pub "$SHA2")"
grep -q "schon als Version $CORE.2" <<< "$OUT3" && [ ! -f "$T/releases/v$CORE.2/DRAFT" ] && ok "Liegengebliebener Entwurf wird beim nächsten Lauf veröffentlicht" || fail "Entwurf nach Push"

# Ein veröffentlichtes Release, das nicht in der Liste steht, wird nie angefasst
mkdir -p "$T/releases/v$CORE.3" && echo fremd > "$T/releases/v$CORE.3/release.json"
if pub "$SHA3" > /dev/null 2>&1; then fail "Veröffentlichtes Release überschrieben"; fi
[ "$(cat "$T/releases/v$CORE.3/release.json")" = fremd ] && ! ls "$T/releases/"*.FEHLER > /dev/null 2>&1 && ok "Veröffentlichtes Release ohne Listeneintrag: Abbruch, nichts geändert" || fail "Release angefasst"
rm -rf "$T/releases/v$CORE.3"

# Releases vorhanden, aber gh-pages fehlt: keine neue Liste (die Nummerierung begänne von vorn)
git clone --quiet --bare "file://$T/remote.git" "$T/remote-sicherung.git"
git --git-dir="$T/remote.git" update-ref -d refs/heads/gh-pages
if pub "$SHA3" > "$T/nolist.log" 2>&1; then fail "Neue Liste trotz vorhandener Releases"; fi
grep -q "keine Update-Liste" "$T/nolist.log" && ok "Fehlende Liste bei vorhandenen Releases: Abbruch" || fail "Fehlende Liste: $(cat "$T/nolist.log")"
git --git-dir="$T/remote.git" fetch --quiet "$T/remote-sicherung.git" gh-pages:gh-pages

# Geprüfte Version freigeben (promote-checked): nur mit passendem STABLE_CORE, sonst ändert sich nichts
checked() { env REPO=org/kompanie-test ACTION=promote-checked VERSION= STABLE_CORE_FILE="$T/STABLE_CORE" GIT_REMOTE="file://$T/remote.git" GH="$T/gh" GH_TOKEN=x scripts/control-release.sh; }
: > "$T/STABLE_CORE"
if checked > "$T/checked.log" 2>&1; then fail "promote-checked ohne STABLE_CORE"; fi
grep -q "STABLE_CORE ist leer" "$T/checked.log" && ok "promote-checked ohne STABLE_CORE: Abbruch" || fail "Leeres STABLE_CORE: $(cat "$T/checked.log")"
echo "$CORE 0000000000000000000000000000000000000000000000000000000000000000" > "$T/STABLE_CORE"
grep -q "keine geprüfte Version" <<< "$(checked)" && [ -f "$T/releases/v$CORE.2/PRERELEASE" ] && ok "promote-checked mit anderem Programmcode: nichts freigegeben" || fail "Fremder Code freigegeben"
# „Kernversion freigeben“: geprüfter Code als STABLE_CORE_VALUE (Kern-Version und Prüfsumme wie beim Veröffentlichen),
# gilt vor der Datei (die hier noch einen anderen Code enthält)
CHECKED="$CORE $(php scripts/package.php --code-hash)"
# Die Kern-Version gehört zum Programmcode: Erhöhen ergibt einen neuen Stand (sonst baut der Abgleich nichts Neues)
cp VERSION "$T/VERSION.bak" && echo 9.9.9 > VERSION
OTHER="$(php scripts/package.php --code-hash || true)"
cp "$T/VERSION.bak" VERSION
[ -n "$OTHER" ] && [ "$OTHER" != "${CHECKED#* }" ] && ok "Andere Kern-Version, anderer coreCode" || fail "coreCode hängt nicht von VERSION ab"
grep -q "gebe $CORE.2 frei" <<< "$(STABLE_CORE_VALUE="$CHECKED" checked)" && pages && [ ! -f "$T/releases/v$CORE.2/PRERELEASE" ] && grep -q "\"stable\": \"$CORE.2\"" <<< "$(list)" \
  && ok "promote-checked gibt die neueste geprüfte Version frei" || fail "promote-checked: $(list | grep -A3 channels)"
echo "$CHECKED" > "$T/STABLE_CORE"
grep -q "keine geprüfte Version" <<< "$(checked)" && ok "promote-checked ein zweites Mal: nichts mehr zu tun" || fail "promote-checked doppelt"
env REPO=org/kompanie-test ACTION=promote VERSION="$CORE.2" GIT_REMOTE="file://$T/remote.git" GH="$T/gh" GH_TOKEN=x scripts/control-release.sh > /dev/null && [ ! -f "$T/releases/v$CORE.2/PRERELEASE" ] && ok "Freigeben über control-release.sh (Release kein Vorab-Release mehr)" || fail "Freigeben"
pages
grep -q "releases/download/v$CORE.2/kompanie-app-wordpress.zip" "$T/pages/index.html" && ok "Download-Seite nach der Freigabe" || fail "Download-Seite nach Freigabe"
mkdir -p "$T/releases/v$CORE.99" && touch "$T/releases/v$CORE.99/DRAFT"
if env REPO=org/kompanie-test ACTION=promote VERSION="$CORE.99" GIT_REMOTE="file://$T/remote.git" GH="$T/gh" GH_TOKEN=x scripts/control-release.sh > "$T/draft.log" 2>&1; then fail "Entwurf freigegeben"; fi
grep -q "nicht veröffentlicht" "$T/draft.log" && ok "Ein Entwurf lässt sich nicht freigeben" || fail "Entwurf: $(cat "$T/draft.log")"
rm -rf "$T/releases/v$CORE.99"
if env REPO=org/kompanie-test ACTION=boese VERSION="$CORE.2" GIT_REMOTE="file://$T/remote.git" GH_TOKEN=x scripts/control-release.sh > /dev/null 2>&1; then fail "Unbekannte Aktion angenommen"; else ok "Unbekannte Aktion abgelehnt"; fi

# Neuer Stand der Kompanie bei gleichem Programmcode wie stable: direkt stable, kein Vorab-Release
pub "$SHA3" > /dev/null
[ -d "$T/releases/v$CORE.3" ] && [ ! -f "$T/releases/v$CORE.3/PRERELEASE" ] && ok "Nur Aussehen geändert: direkt stable" || fail "Kanal bei gleichem Code"

# Revert des Kompanie-Repos auf einen früheren Stand: neuer Stand, neue Version (nicht „schon veröffentlicht“)
pub "$SHA2" > /dev/null
[ -d "$T/releases/v$CORE.4" ] && ok "Revert auf einen früheren Stand wird neu veröffentlicht" || fail "Revert nicht veröffentlicht"

# Manipuliertes theme.css (z. B. aus einem kompromittierten Build) kommt nicht in die Download-Seite
cp -r "$T/checked/brand" "$T/brand-boese"
printf '</style><script>alert(1)</script>\n' >> "$T/brand-boese/theme.css"
if php scripts/download-page.php --out="$T/site-boese" --brand="$T/brand-boese" > /dev/null 2>&1; then fail "theme.css mit Skript angenommen"; else ok "theme.css mit fremdem Inhalt abgelehnt"; fi

# Nach dem Zurückziehen ist die Kern-Version gesperrt: auch mit passendem STABLE_CORE nur early
# (dass STABLE_CORE nur mit passender Code-Prüfsumme gilt, prüft UpdateTest)
CODE="$(php scripts/package.php --code-hash)"
SHA4=4444444444444444444444444444444444444444
env REPO=org/kompanie-test ACTION=withdraw VERSION="$CORE.4" GIT_REMOTE="file://$T/remote.git" GH="$T/gh" GH_TOKEN=x scripts/control-release.sh > /dev/null
pub "$SHA4" "$CORE $CODE" > /dev/null
[ -f "$T/releases/v$CORE.5/PRERELEASE" ] && ok "Nach dem Zurückziehen: Kern gesperrt, nur noch early" || fail "Gesperrter Kern"
pages
grep -q '"serial": 1[0-9]\{9\}' <<< "$(list)" && ok "Serien-Nummer mindestens die aktuelle Zeit" || fail "Serien-Nummer"

# Neue Adresse für Statusmeldungen: neue Version (die Paket-Konfiguration gehört zum Stand)
STATUS_URL=https://status.example.org/ pub "$SHA4" > /dev/null
grep -q '"statusUrl": "https://status.example.org/"' <<< "$(unzip -p "$T/releases/v$CORE.6/kompanie-app-standalone.zip" kompanie-app/update.json)" \
  && ok "Geänderte Adresse für Statusmeldungen ergibt eine neue Version" || fail "Statusadresse: $(ls "$T/releases")"
if STATUS_URL=http://unsicher.example.org/ pub "$SHA4" > /dev/null 2>&1; then fail "http-Adresse für Statusmeldungen angenommen"; else ok "Adresse für Statusmeldungen nur mit https"; fi

# Empfänger der Fehlermeldungen (Issue #64): nur https; ohne Angabe bleibt die Prüfsumme der Paket-Konfiguration gleich
H0="$(php scripts/package.php --config-hash --kompanie=org/kompanie-test --status-url=https://status.example.org/)"
H1="$(php scripts/package.php --config-hash --kompanie=org/kompanie-test --status-url=https://status.example.org/ --report-url=)"
H2="$(php scripts/package.php --config-hash --kompanie=org/kompanie-test --status-url=https://status.example.org/ --report-url=https://status.example.org/reports)"
[ "$H0" = "$H1" ] && [ "$H0" != "$H2" ] && ok "Ohne Adresse für Fehlermeldungen gleiche Prüfsumme, mit Adresse eine neue" || fail "Prüfsumme mit reportUrl: $H0 $H1 $H2"
if php scripts/package.php --config-hash --kompanie=org/kompanie-test --report-url=http://unsicher.example.org/ > /dev/null 2>&1; then fail "http-Adresse für Fehlermeldungen angenommen"; else ok "Adresse für Fehlermeldungen nur mit https"; fi

# Zurückgezogener Stand kommt auch mit geänderter Paket-Konfiguration nicht wieder
env REPO=org/kompanie-test ACTION=withdraw VERSION="$CORE.6" GIT_REMOTE="file://$T/remote.git" GH="$T/gh" GH_TOKEN=x scripts/control-release.sh > /dev/null
OUT4="$(STATUS_URL=https://status.example.org/ SUPPORT=hilfe@example.org pub "$SHA4")"
grep -q "zurückgezogen und wird nicht neu veröffentlicht" <<< "$OUT4" && [ ! -d "$T/releases/v$CORE.7" ] && ok "Zurückgezogener Stand wird nicht neu veröffentlicht (auch mit neuer Konfiguration)" || fail "Zurückgezogen: $OUT4"

echo; echo "$pass bestanden, 0 fehlgeschlagen"
