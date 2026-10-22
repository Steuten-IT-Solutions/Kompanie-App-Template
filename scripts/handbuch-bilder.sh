#!/usr/bin/env bash
# Bildschirmfotos für die Anleitungen im Handbuch (docs/handbuch, Verweise ![…](bild:name)), mit Beispieldaten, in
# Farben, Logo und Bezeichnungen der gebauten App:
#   scripts/handbuch-bilder.sh ausgabe-ordner
# Vorher: scripts/build-frontend.sh mit KOMPANIE_CONFIG/KOMPANIE_ASSETS der Kompanie. Fehlt ein Foto, endet das Skript
# mit einem Fehler (packages/frontend/e2e/handbuch-bilder.spec.ts).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$(mkdir -p "${1:-handbuch-bilder}" && cd "${1:-handbuch-bilder}" && pwd)"
rm -f "$OUT"/*.jpg
KOMPANIE_DEMO=1 KOMPANIE_HANDBUCH_BILDER=1 KOMPANIE_SCREENSHOT_DIR="$OUT" "$ROOT/scripts/e2e-standalone.sh" --reporter=line
echo "Bilder für das Handbuch in $OUT ($(find "$OUT" -name '*.jpg' | wc -l) Fotos, $(du -sh "$OUT" | cut -f1))"
