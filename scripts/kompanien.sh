#!/usr/bin/env bash
# Gibt die Kompanie-Repos aus, die neue Versionen bekommen (eins je Zeile, Organisation/Name): öffentliche,
# nicht archivierte Repos der Organisation mit dem Topic kompanie-app, deren Wartungsabo läuft
# (scripts/wartung.php, Variable KOMPANIE_WARTUNG). Für „Kompanien abgleichen“ (find-pending.sh),
# „Kernversion freigeben“ und „Versionen steuern“ (promote-checked für alle).
#
# Umgebung: GH_TOKEN (nur Lesen), WARTUNG (Inhalt von KOMPANIE_WARTUNG), optional ORG (Standard
#   Steuten-IT-Solutions) und ONLY (nur dieses Repo statt der Liste der Organisation, Wartung gilt trotzdem).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ORG="${ORG:-Steuten-IT-Solutions}"

if [ -n "${ONLY:-}" ]; then
  printf '%s\n' "$ONLY" | php "$ROOT/scripts/wartung.php" --filter
else
  # Über die Liste der Repos der Organisation, nicht über die Suche: Der Such-Index von GitHub lässt ein Repo
  # nach Änderungen (z. B. direkt nach einer Veröffentlichung) zeitweise weg; dann wurde still nichts gebaut.
  REPOS="$(gh api --paginate "orgs/$ORG/repos?type=public&per_page=100" \
    --jq '.[] | select(.archived | not) | select((.topics // []) | index("kompanie-app")) | .full_name')"
  if [ -z "$REPOS" ]; then
    echo "::error::Keine Kompanie-Repos gefunden (öffentlich, Topic kompanie-app, in $ORG)." >&2
    exit 1
  fi
  printf '%s\n' "$REPOS" | php "$ROOT/scripts/wartung.php" --filter --complete
fi
