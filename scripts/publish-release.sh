#!/usr/bin/env bash
# Baut die Pakete einer Kompanie, signiert und veröffentlicht sie (aus .github/workflows/publish-kompanie.yml,
# Job „veröffentlichen“): GitHub-Release im Kompanie-Repo, signierte Update-Liste und Download-Seite
# auf dessen gh-pages.
#
# Alles, was signiert wird (PHP-Code, update.json, install.php, release.json), entsteht hier aus dem
# eigenen Checkout. Aus dem Build-Job kommen nur die gebaute Oberfläche und Bilder, vorher mit
# scripts/import-build.php auf erlaubte Dateitypen geprüft.
#
# Umgebung: REPO (Organisation/Name), KOMPANIE_SHA (Commit des Kompanie-Repos), CONFIG (dessen kompanie.json),
#   DIST (gebaute Oberfläche), BRAND (Ordner mit brand.json, theme.css, logo.png, favicon.png),
#   SCREENSHOTS (Ordner mit PNGs, optional), GH_TOKEN (Schreibrecht auf das Kompanie-Repo), KOMPANIE_SIGNING_KEY.
#   Für Tests: GIT_REMOTE (statt GitHub), GH (Befehl statt gh), STABLE_CORE (statt der Datei), TRUST_SIGNING_KEY=1.
# Läuft es für denselben Stand noch einmal (Re-run, Abgleich), passiert nichts; nach einem Abbruch
# mittendrin wird dieselbe Versionsnummer fertig veröffentlicht.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GH="${GH:-gh}"
: "${REPO:?}" "${KOMPANIE_SHA:?}" "${CONFIG:?}" "${DIST:?}" "${BRAND:?}" "${KOMPANIE_SIGNING_KEY:?}"
[[ "$KOMPANIE_SHA" =~ ^[0-9a-f]{40}$ ]] || { echo "Ungültiger Commit: $KOMPANIE_SHA" >&2; exit 1; }
STABLE_CORE="${STABLE_CORE-$(cat "$ROOT/STABLE_CORE" 2>/dev/null || true)}"
CORE="$(tr -d '[:space:]' < "$ROOT/VERSION")"
REMOTE="${GIT_REMOTE:-https://x-access-token:${GH_TOKEN}@github.com/${REPO}.git}"
TRUST=()
[ "${TRUST_SIGNING_KEY:-}" = 1 ] && TRUST=(--trust-signing-key)
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# gh-pages holen; neu anlegen nur, wenn es den Branch sicher nicht gibt (Exit-Code 2), nicht bei Netzfehlern
fetch_pages() {
  rm -rf "$WORK/site"
  local code=0
  git ls-remote --exit-code --heads "$REMOTE" gh-pages > /dev/null || code=$?
  if [ "$code" = 0 ]; then
    git clone --quiet --depth 1 --branch gh-pages "$REMOTE" "$WORK/site"
  elif [ "$code" = 2 ]; then
    git init --quiet -b gh-pages "$WORK/site"
    git -C "$WORK/site" remote add origin "$REMOTE"
  else
    echo "gh-pages von $REPO nicht erreichbar." >&2
    exit 1
  fi
}
updates() { php "$ROOT/scripts/updates.php" --list="$WORK/site/updates.json" --kompanie="$REPO" "$@"; }

fetch_pages
CODE="$(php "$ROOT/scripts/package.php" --code-hash)"
KNOWN="$(updates --find="$KOMPANIE_SHA" --core-code="$CODE")"
if [ -n "$KNOWN" ]; then
  echo "Dieser Stand ist schon als Version $KNOWN veröffentlicht."
  exit 0
fi
VERSION="$(updates --next-version="$CORE")"
BASE_URL="https://github.com/${REPO}/releases/download/v${VERSION}/"

php "$ROOT/scripts/package.php" --config="$CONFIG" --dist="$DIST" --brand="$BRAND/brand.json" --version="$VERSION" \
  --out="$WORK/release" --kompanie="$REPO" --kompanie-commit="$KOMPANIE_SHA" --require-signature "${TRUST[@]}"
FILES=("$WORK/release/kompanie-app-wordpress.zip" "$WORK/release/kompanie-app-standalone.zip" "$WORK/release/release.json" "$WORK/release/install.php")

CHANNEL="$(updates --add="$WORK/release/release.json" --base-url="$BASE_URL" --stable-core="$STABLE_CORE" --dry-run | sed -n 's/^Kanal: //p')"
PRE=()
[ "$CHANNEL" = early ] && PRE=(--prerelease)

# Release: Ein vorhandenes (Abbruch nach dem Anlegen) gehört zu einer Version, die noch nicht in der Liste
# steht; seine Dateien werden ersetzt, gelöscht wird nie.
if $GH release view "v$VERSION" --repo "$REPO" > /dev/null 2>&1; then
  $GH release upload "v$VERSION" "${FILES[@]}" --repo "$REPO" --clobber
else
  $GH release create "v$VERSION" "${FILES[@]}" --repo "$REPO" --target "$KOMPANIE_SHA" "${PRE[@]}" \
    --title "Version $VERSION" --notes "Kern $CORE. Pakete und Prüfsummen: release.json (signiert)."
fi

# Update-Liste und Download-Seite; bei gleichzeitiger Änderung (Push abgelehnt) neu holen und wiederholen
for attempt in 1 2 3 4 5; do
  [ "$attempt" -gt 1 ] && fetch_pages
  ADDED="$(updates --add="$WORK/release/release.json" --base-url="$BASE_URL" --stable-core="$STABLE_CORE")"
  echo "$ADDED"
  CHANNEL="$(sed -n 's/.*(Kanal \(early\|stable\)).*/\1/p' <<< "$ADDED")"
  php "$ROOT/scripts/download-page.php" --updates="$WORK/site/updates.json" --out="$WORK/site" \
    ${SCREENSHOTS:+--screenshots="$SCREENSHOTS"} --brand="$BRAND"
  touch "$WORK/site/.nojekyll"
  git -C "$WORK/site" add -A
  git -C "$WORK/site" -c user.name="github-actions[bot]" -c user.email="41898282+github-actions[bot]@users.noreply.github.com" \
    commit --quiet -m "Version $VERSION ($CHANNEL)"
  if git -C "$WORK/site" push --quiet origin HEAD:refs/heads/gh-pages; then
    # Der Kanal kann sich beim Wiederholen geändert haben (z. B. gleichzeitig zurückgezogen): Release angleichen.
    $GH release edit "v$VERSION" --repo "$REPO" --prerelease="$([ "$CHANNEL" = early ] && echo true || echo false)" > /dev/null
    echo "Veröffentlicht: Version $VERSION, Kanal $CHANNEL."
    exit 0
  fi
  sleep $((attempt * 3))
done
echo "gh-pages ließ sich nicht aktualisieren (immer wieder gleichzeitige Änderungen)." >&2
exit 1
