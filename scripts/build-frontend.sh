#!/usr/bin/env bash
# Baut das Frontend für eine Kompanie und legt es in die Adapter.
#   KOMPANIE_CONFIG=pfad/kompanie.json KOMPANIE_ASSETS=pfad/assets scripts/build-frontend.sh
# Ohne Angaben: Beispielkonfiguration aus config/.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
[ -d node_modules ] || npm ci --no-audit --no-fund
npm run build
for target in adapters/wordpress/app adapters/standalone/app; do
  rm -rf "$target"
  mkdir -p "$target"
  cp -R packages/frontend/dist/. "$target/"
  # Zwischenspeicher und Komprimierung (Apache/LiteSpeed); nicht Teil des Builds, damit aus dem
  # Build-Ergebnis nie eine .htaccess übernommen wird (siehe scripts/import-build.php).
  cp adapters/shared/app.htaccess "$target/.htaccess"
  mkdir -p "$target/assets"
  cp adapters/shared/app-assets.htaccess "$target/assets/.htaccess"
done
# Eigenständige Version: Service Worker neben index.php, damit sein Bereich die ganze App umfasst.
cp packages/frontend/dist/sw.js adapters/standalone/sw.js
echo "Frontend gebaut und nach adapters/wordpress/app und adapters/standalone/app kopiert."
