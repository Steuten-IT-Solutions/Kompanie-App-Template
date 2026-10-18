#!/usr/bin/env bash
# Baut das Frontend für eine Kompanie und legt es in die Adapter.
#   KOMPANIE_CONFIG=pfad/kompanie.json KOMPANIE_ASSETS=pfad/assets scripts/build-frontend.sh
# Ohne Angaben: Beispielkonfiguration aus config/.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
[ -d node_modules ] || npm ci --no-audit --no-fund
npm run build
for target in adapters/wordpress/app; do
  rm -rf "$target"
  mkdir -p "$target"
  cp -R packages/frontend/dist/. "$target/"
done
echo "Frontend gebaut und nach adapters/wordpress/app kopiert."
