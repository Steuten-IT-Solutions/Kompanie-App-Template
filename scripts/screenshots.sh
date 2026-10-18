#!/usr/bin/env bash
# Bildschirmfotos der gebauten App mit Beispieldaten (hell und dunkel), z. B. für die Download-Seite:
#   scripts/screenshots.sh ausgabe-ordner
# Vorher: scripts/build-frontend.sh mit KOMPANIE_CONFIG/KOMPANIE_ASSETS der Kompanie.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$(mkdir -p "${1:-screenshots}" && cd "${1:-screenshots}" && pwd)"
KOMPANIE_DEMO=1 KOMPANIE_SCREENSHOTS=1 KOMPANIE_SCREENSHOT_DIR="$OUT" "$ROOT/scripts/e2e-standalone.sh" --reporter=line
echo "Bildschirmfotos in $OUT"
