#!/usr/bin/env bash
# Veröffentlicht ein signiertes Release einer Kompanie (aus .github/workflows/publish-kompanie.yml):
# GitHub-Release im Kompanie-Repo, signierte Update-Liste und Download-Seite auf dessen gh-pages.
#
# Umgebung: REPO (Organisation/Name), VERSION, KOMPANIE_SHA (Commit des Kompanie-Repos),
#   OUT (Ordner mit release/, screenshots/, brand/), STABLE_CORE (Inhalt von STABLE_CORE),
#   GH_TOKEN (Schreibrecht auf das Kompanie-Repo), KOMPANIE_SIGNING_KEY,
#   optional GIT_REMOTE (für Tests statt GitHub) und GH (Befehl für die GitHub-CLI, Standard gh).
# Läuft ein zweites Mal für dieselbe Version (Re-run), passiert nichts Doppeltes.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GH="${GH:-gh}"
: "${REPO:?}" "${VERSION:?}" "${KOMPANIE_SHA:?}" "${OUT:?}" "${KOMPANIE_SIGNING_KEY:?}"
REMOTE="${GIT_REMOTE:-https://x-access-token:${GH_TOKEN}@github.com/${REPO}.git}"
BASE_URL="https://github.com/${REPO}/releases/download/v${VERSION}/"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# gh-pages holen (oder neu anlegen, wenn es den Branch noch nicht gibt)
fetch_pages() {
  rm -rf "$WORK/site"
  if git ls-remote --exit-code --heads "$REMOTE" gh-pages > /dev/null 2>&1; then
    git clone --quiet --depth 1 --branch gh-pages "$REMOTE" "$WORK/site"
  else
    git init --quiet -b gh-pages "$WORK/site"
    git -C "$WORK/site" remote add origin "$REMOTE"
  fi
}

fetch_pages
# Kanal bestimmen; schon veröffentlichte Version (Re-run nach Erfolg) nicht noch einmal
CHANNEL_INFO="$(GITHUB_OUTPUT= php "$ROOT/scripts/updates.php" --list="$WORK/site/updates.json" --add="$OUT/release/release.json" \
  --base-url="$BASE_URL" --stable-core="${STABLE_CORE:-}" --dry-run)"
echo "$CHANNEL_INFO"
if grep -q "schon in der Liste" <<< "$CHANNEL_INFO"; then
  exit 0
fi
CHANNEL="$(sed -n 's/^Kanal: //p' <<< "$CHANNEL_INFO")"

# Release (bei einem Re-run nach einem Fehler: das halbfertige Release ersetzen)
if $GH release view "v$VERSION" --repo "$REPO" > /dev/null 2>&1; then
  $GH release delete "v$VERSION" --repo "$REPO" --yes --cleanup-tag
fi
PRE=()
[ "$CHANNEL" = early ] && PRE=(--prerelease)
$GH release create "v$VERSION" "$OUT"/release/* --repo "$REPO" --target "$KOMPANIE_SHA" "${PRE[@]}" \
  --title "Version $VERSION" --notes "Kern ${VERSION%.*}. Kanal: $CHANNEL. Pakete und Prüfsummen: release.json (signiert)."

# Update-Liste und Download-Seite; bei gleichzeitiger Änderung (Push abgelehnt) neu holen und wiederholen
for attempt in 1 2 3 4 5; do
  [ "$attempt" -gt 1 ] && fetch_pages
  php "$ROOT/scripts/updates.php" --list="$WORK/site/updates.json" --add="$OUT/release/release.json" \
    --base-url="$BASE_URL" --stable-core="${STABLE_CORE:-}"
  php "$ROOT/scripts/download-page.php" --updates="$WORK/site/updates.json" --out="$WORK/site" \
    --screenshots="$OUT/screenshots" --brand="$OUT/brand"
  touch "$WORK/site/.nojekyll"
  git -C "$WORK/site" add -A
  git -C "$WORK/site" -c user.name="github-actions[bot]" -c user.email="41898282+github-actions[bot]@users.noreply.github.com" \
    commit --quiet -m "Version $VERSION ($CHANNEL)"
  if git -C "$WORK/site" push --quiet origin HEAD:refs/heads/gh-pages; then
    echo "Veröffentlicht: Version $VERSION, Kanal $CHANNEL."
    exit 0
  fi
  sleep $((attempt * 3))
done
echo "gh-pages ließ sich nicht aktualisieren (immer wieder gleichzeitige Änderungen)." >&2
exit 1
