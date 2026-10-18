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

# Veröffentlichen wie im Workflow: unsigniert bauen, getrennt signieren, Release + gh-pages
# (mit einem Bare-Repo statt GitHub und einer Attrappe der GitHub-CLI).
KOMPANIE_SIGNING_KEY= php scripts/package.php --config=config/kompanie.example.json --version="$CORE.7" --out="$T/p7/release" --kompanie=org/kompanie-test > /dev/null 2>&1
grep -q '"type": "release"' "$T/p7/release/release.json" && ! grep -q kompanie-app-signed "$T/p7/release/release.json" && ok "Build-Job erzeugt unsignierte release.json" || fail "release.json im Build-Job"
cp "$T/p7/release/kompanie-app-standalone.zip" "$T/orig.zip"
echo "manipuliert" >> "$T/p7/release/kompanie-app-standalone.zip"
if php scripts/sign-release.php --dir="$T/p7/release" --trust-signing-key > /dev/null 2>&1; then fail "Manipuliertes Paket signiert"; else ok "Signier-Schritt prüft die Pakete gegen release.json"; fi
cp "$T/orig.zip" "$T/p7/release/kompanie-app-standalone.zip"
if php scripts/sign-release.php --dir="$T/p7/release" > /dev/null 2>&1; then fail "Schlüssel außerhalb von TrustedKeys akzeptiert"; else ok "Nur Schlüssel aus TrustedKeys signieren"; fi
php scripts/sign-release.php --dir="$T/p7/release" --trust-signing-key > /dev/null && ok "Signiert" || fail "Signieren"
mkdir -p "$T/p7/screenshots" "$T/p7/brand"
cp packages/frontend/src/generated/{brand.json,theme.css,logo.png} "$T/p7/brand/" && cp packages/frontend/public/icons/favicon-32.png "$T/p7/brand/favicon.png"
git init --quiet --bare "$T/remote.git"
cat > "$T/gh" <<'GH'
#!/usr/bin/env bash
# Attrappe der GitHub-CLI: merkt sich Releases als Ordner
D="$(dirname "$0")/releases"; mkdir -p "$D"
case "$1 $2" in
  "release view") [ -d "$D/$3" ] ;;
  "release delete") rm -rf "${D:?}/$3" ;;
  "release create") TAG="$3"; shift 3; mkdir -p "$D/$TAG"; for a in "$@"; do [ -f "$a" ] && cp "$a" "$D/$TAG/"; [ "$a" = --prerelease ] && touch "$D/$TAG/PRERELEASE"; done; true ;;
esac
GH
chmod +x "$T/gh"
PUB=(env REPO=org/kompanie-test VERSION="$CORE.7" KOMPANIE_SHA=abc OUT="$T/p7" STABLE_CORE= GIT_REMOTE="file://$T/remote.git" GH="$T/gh" GH_TOKEN=x)
"${PUB[@]}" scripts/publish-release.sh > /dev/null && [ -f "$T/releases/v$CORE.7/install.php" ] && [ -f "$T/releases/v$CORE.7/PRERELEASE" ] && ok "Erstes Veröffentlichen legt gh-pages an; neuer Kern als Vorab-Release" || fail "Veröffentlichen"
git clone --quiet --branch gh-pages "file://$T/remote.git" "$T/pages1"
[ -f "$T/pages1/updates.json" ] && [ -f "$T/pages1/index.html" ] && [ -f "$T/pages1/brand/theme.css" ] && ok "gh-pages: Update-Liste, Download-Seite, Branding" || fail "gh-pages-Inhalt"
OUT2="$("${PUB[@]}" scripts/publish-release.sh)"
grep -q "schon in der Liste" <<< "$OUT2" && ok "Re-run veröffentlicht nichts doppelt" || fail "Re-run"
env REPO=org/kompanie-test ACTION=promote VERSION="$CORE.7" GIT_REMOTE="file://$T/remote.git" GH_TOKEN=x scripts/control-release.sh > /dev/null && ok "Freigeben über control-release.sh" || fail "Freigeben"
git clone --quiet --branch gh-pages "file://$T/remote.git" "$T/pages2"
grep -q "releases/download/v$CORE.7/kompanie-app-wordpress.zip" "$T/pages2/index.html" && ok "Download-Seite nach der Freigabe" || fail "Download-Seite nach Freigabe"
if env REPO=org/kompanie-test ACTION=boese VERSION="$CORE.7" GIT_REMOTE="file://$T/remote.git" GH_TOKEN=x scripts/control-release.sh > /dev/null 2>&1; then fail "Unbekannte Aktion angenommen"; else ok "Unbekannte Aktion abgelehnt"; fi

echo; echo "$pass bestanden, 0 fehlgeschlagen"
