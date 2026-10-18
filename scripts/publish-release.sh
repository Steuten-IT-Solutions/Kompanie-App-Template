#!/usr/bin/env bash
# Baut die Pakete einer Kompanie, signiert und veröffentlicht sie (aus .github/workflows/publish-kompanie.yml,
# Job „publish“): GitHub-Release im Kompanie-Repo, signierte Update-Liste und Download-Seite auf dessen gh-pages.
#
# Alles, was signiert wird (PHP-Code, update.json, install.php, release.json), entsteht hier aus dem
# eigenen Checkout. Aus dem Build-Job kommen nur die gebaute Oberfläche und Bilder, vorher mit
# scripts/import-build.php auf erlaubte Dateitypen geprüft (theme.css prüft download-page.php).
#
# Umgebung: REPO (Organisation/Name), KOMPANIE_SHA (Commit des Kompanie-Repos), CONFIG (dessen kompanie.json),
#   DIST (gebaute Oberfläche), BRAND (Ordner mit brand.json, theme.css, logo.png, favicon.png),
#   SCREENSHOTS (Ordner mit PNGs, optional), GH_TOKEN (Schreibrecht auf das Kompanie-Repo), KOMPANIE_SIGNING_KEY,
#   optional STATUS_URL (Empfang der Statusmeldungen, https://…) und SUPPORT (Kontakt für Hilfe).
#   Für Tests: GIT_REMOTE (statt GitHub), GH (Befehl statt gh), STABLE_CORE (statt der Datei), TRUST_SIGNING_KEY=1.
#
# Ablauf und Wiederholbarkeit: Das Release entsteht als Entwurf (unsichtbar) und wird erst veröffentlicht,
# wenn die Update-Liste es enthält. Ein Lauf für einen schon veröffentlichten Stand macht nur den Entwurf
# öffentlich, falls ein früherer Lauf davor abbrach. Entwürfe einer abgebrochenen Version werden ersetzt;
# veröffentlichte Releases werden nie geändert oder gelöscht.
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
  git ls-remote --exit-code "$REMOTE" refs/heads/gh-pages > /dev/null || code=$?
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
# Ist v$1 ein Entwurf? true/false; „none“ nur, wenn es das Release sicher nicht gibt. Bei anderen
# Fehlern (Netz, API) mehrmals versuchen, sonst abbrechen.
release_state() {
  local out err
  for attempt in 1 2 3; do
    if out="$($GH release view "v$1" --repo "$REPO" --json isDraft --jq .isDraft 2> "$WORK/gh-err")"; then
      echo "$out"
      return 0
    fi
    err="$(cat "$WORK/gh-err")"
    if grep -qx "release not found" <<< "$err"; then
      echo none
      return 0
    fi
    sleep $((attempt * 3))
  done
  echo "Release v$1 nicht abfragbar: $err" >&2
  exit 1
}
# Entwurf veröffentlichen (Prerelease, wenn noch nicht für alle freigegeben) und prüfen, dass er öffentlich ist
publish_release() {
  local state
  state="$(release_state "$1")"
  if [ "$state" = true ]; then
    $GH release edit "v$1" --repo "$REPO" --draft=false --prerelease="$([ "$2" = early ] && echo true || echo false)" > /dev/null
    state="$(release_state "$1")"
  fi
  if [ "$state" != false ]; then
    echo "Release v$1 ist nicht veröffentlicht (Stand: $state)." >&2
    exit 1
  fi
}
# GitHub Pages einschalten, falls noch nicht geschehen (braucht das Recht „Pages“ der App)
enable_pages() {
  $GH api "repos/$REPO/pages" > /dev/null 2>&1 \
    || $GH api -X POST "repos/$REPO/pages" -f build_type=legacy -f 'source[branch]=gh-pages' -f 'source[path]=/' > /dev/null 2>&1 \
    || echo "Hinweis: GitHub Pages ließ sich nicht einschalten; bitte in $REPO unter Settings → Pages den Branch gh-pages wählen." >&2
}

fetch_pages
# (Entwürfe zählen nicht: Ein abgebrochener erster Lauf hinterlässt einen, den der nächste ersetzt.)
PUBLISHED=""
if [ ! -f "$WORK/site/updates.json" ]; then
  PUBLISHED="$($GH release list --repo "$REPO" --exclude-drafts --limit 1)"   # eigene Zuweisung: Fehler brechen ab
fi
if [ -n "$PUBLISHED" ]; then
  # Ohne Liste begänne die Nummerierung von vorn, unter den installierten Versionen.
  echo "In $REPO gibt es Releases, aber keine Update-Liste auf gh-pages. Bitte gh-pages wiederherstellen (siehe docs/RELEASE.md)." >&2
  exit 1
fi
CODE="$(php "$ROOT/scripts/package.php" --code-hash)"
KNOWN="$(updates --find="$KOMPANIE_SHA" --core-code="$CODE")"
if [ -n "$KNOWN" ]; then
  read -r KNOWN_VERSION KNOWN_CHANNEL <<< "$KNOWN"
  publish_release "$KNOWN_VERSION" "$KNOWN_CHANNEL"
  echo "Dieser Stand ist schon als Version $KNOWN_VERSION veröffentlicht."
  exit 0
fi
VERSION="$(updates --next-version="$CORE")"
BASE_URL="https://github.com/${REPO}/releases/download/v${VERSION}/"

php "$ROOT/scripts/package.php" --config="$CONFIG" --dist="$DIST" --brand="$BRAND/brand.json" --version="$VERSION" \
  --out="$WORK/release" --kompanie="$REPO" --kompanie-commit="$KOMPANIE_SHA" --require-signature "${TRUST[@]}" \
  --status-url="${STATUS_URL:-}" --support="${SUPPORT:-}"
# Branding vor dem Anlegen des Releases prüfen (download-page.php lehnt fremdes theme.css ab).
php "$ROOT/scripts/download-page.php" --out="$WORK/brand-check" --brand="$BRAND" > /dev/null
FILES=("$WORK/release/kompanie-app-wordpress.zip" "$WORK/release/kompanie-app-standalone.zip" "$WORK/release/release.json" "$WORK/release/install.php")

# Release als Entwurf. Ein vorhandener Entwurf dieser Nummer stammt von einem abgebrochenen Lauf (nie
# veröffentlicht, nie in der Liste): ersetzen. Ein veröffentlichtes Release dieser Nummer darf es nicht geben.
EXISTING="$(release_state "$VERSION")"
if [ "$EXISTING" = true ]; then
  $GH release delete "v$VERSION" --repo "$REPO" --yes   # Entwürfe haben noch kein Tag
elif [ "$EXISTING" != none ]; then
  echo "Release v$VERSION ist schon veröffentlicht, steht aber nicht in der Update-Liste. Bitte prüfen (siehe docs/RELEASE.md)." >&2
  exit 1
fi
$GH release create "v$VERSION" "${FILES[@]}" --repo "$REPO" --target "$KOMPANIE_SHA" --draft \
  --title "Version $VERSION" --notes "Kern $CORE. Pakete und Prüfsummen: release.json (signiert)."

# Update-Liste und Download-Seite; bei gleichzeitiger Änderung (Push abgelehnt) neu holen und wiederholen
for attempt in 1 2 3 4 5; do
  [ "$attempt" -gt 1 ] && fetch_pages
  ADDED="$(updates --add="$WORK/release/release.json" --base-url="$BASE_URL" --stable-core="$STABLE_CORE")"
  echo "$ADDED"
  if grep -q "schon in der Liste" <<< "$ADDED"; then
    # Der vorige Push kam an, nur die Rückmeldung nicht.
    CHANNEL="$(updates --find="$KOMPANIE_SHA" --core-code="$CODE" | cut -d' ' -f2)"
    break
  fi
  CHANNEL="$(sed -n 's/.*(Kanal \(early\|stable\)).*/\1/p' <<< "$ADDED")"
  php "$ROOT/scripts/download-page.php" --updates="$WORK/site/updates.json" --out="$WORK/site" \
    ${SCREENSHOTS:+--screenshots="$SCREENSHOTS"} --brand="$BRAND"
  touch "$WORK/site/.nojekyll"
  git -C "$WORK/site" add -A
  git -C "$WORK/site" -c user.name="github-actions[bot]" -c user.email="41898282+github-actions[bot]@users.noreply.github.com" \
    commit --quiet -m "Version $VERSION ($CHANNEL)"
  if git -C "$WORK/site" push --quiet origin HEAD:refs/heads/gh-pages; then
    break
  fi
  [ "$attempt" = 5 ] && { echo "gh-pages ließ sich nicht aktualisieren (immer wieder gleichzeitige Änderungen)." >&2; exit 1; }
  sleep $((attempt * 3))
done
publish_release "$VERSION" "${CHANNEL:-early}"
enable_pages
echo "Veröffentlicht: Version $VERSION, Kanal ${CHANNEL:-early}."
