#!/usr/bin/env bash
# Gibt eine Version einer Kompanie für alle frei (promote) oder zieht sie zurück (withdraw)
# (aus .github/workflows/release-control.yml). Ändert die signierte Update-Liste und die
# Download-Seite auf gh-pages des Kompanie-Repos.
#
# Umgebung: REPO, ACTION (promote|withdraw), VERSION, GH_TOKEN, KOMPANIE_SIGNING_KEY,
#   optional GIT_REMOTE und GH (für Tests).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
: "${REPO:?}" "${ACTION:?}" "${VERSION:?}" "${KOMPANIE_SIGNING_KEY:?}"
[[ "$ACTION" == promote || "$ACTION" == withdraw ]] || { echo "Unbekannte Aktion: $ACTION" >&2; exit 1; }
[[ "$VERSION" =~ ^[0-9]{1,4}\.[0-9]{1,4}\.[0-9]{1,4}(\.[0-9]{1,6})?$ ]] || { echo "Ungültige Version: $VERSION" >&2; exit 1; }
REMOTE="${GIT_REMOTE:-https://x-access-token:${GH_TOKEN}@github.com/${REPO}.git}"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
GH="${GH:-gh}"
# Freigeben nur, was öffentlich ist (kein Entwurf eines abgebrochenen Laufs)
if [ "$ACTION" = promote ] && [ "$($GH release view "v$VERSION" --repo "$REPO" --json isDraft --jq .isDraft)" != false ]; then
  echo "Release v$VERSION ist nicht veröffentlicht (Entwurf?). Erst „Kompanie veröffentlichen“ erneut laufen lassen." >&2
  exit 1
fi
mark_release() {
  # Freigegeben: Das Release ist kein Vorab-Release mehr (Kennzeichnung auf GitHub; maßgeblich ist die Liste).
  if [ "$ACTION" = promote ]; then
    $GH release edit "v$VERSION" --repo "$REPO" --prerelease=false > /dev/null 2>&1 || echo "Hinweis: Kennzeichnung des Releases nicht geändert." >&2
  fi
}
for attempt in 1 2 3 4 5; do
  rm -rf "$WORK/site"
  git clone --quiet --depth 1 --branch gh-pages "$REMOTE" "$WORK/site"
  php "$ROOT/scripts/updates.php" --list="$WORK/site/updates.json" --kompanie="$REPO" --"$ACTION"="$VERSION"
  php "$ROOT/scripts/download-page.php" --updates="$WORK/site/updates.json" --out="$WORK/site"
  git -C "$WORK/site" add -A
  if git -C "$WORK/site" diff --cached --quiet; then
    echo "Keine Änderung nötig."
    mark_release
    exit 0
  fi
  git -C "$WORK/site" -c user.name="github-actions[bot]" -c user.email="41898282+github-actions[bot]@users.noreply.github.com" \
    commit --quiet -m "$ACTION $VERSION"
  if git -C "$WORK/site" push --quiet origin HEAD:refs/heads/gh-pages; then
    mark_release
    exit 0
  fi
  sleep $((attempt * 3))
done
echo "gh-pages ließ sich nicht aktualisieren." >&2
exit 1
