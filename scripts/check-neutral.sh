#!/usr/bin/env bash
# Prüft, dass App, Kern, Adapter, Installer, API und Beispielkonfiguration keine
# Namen, Motive oder Begriffe einer bestimmten Kompanie enthalten. Jede Kompanie
# soll sich in ihrer App wiederfinden; ihr Branding kommt nur aus ihrem Kompanie-Repo.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PATTERN='\b(marine[a-zäöüß]*|maritim[a-zäöüß]*|lank|mk-lank|mkl_[a-z_]*|ahoi|anker|navy|brass|messing[a-z]*|steuerrad|wappen|antreten)\b|⚓'
PATHS=()
for p in packages adapters installer api config/kompanie.example.json; do
  [ -e "$ROOT/$p" ] && PATHS+=("$ROOT/$p")
done
HITS=$(grep -rniEI "$PATTERN" "${PATHS[@]}" --exclude-dir=node_modules --exclude-dir=vendor --exclude-dir=dist --exclude-dir=generated --exclude-dir=app --exclude-dir=test-results --exclude-dir=e2e --exclude='README.md' || true)
if [ -n "$HITS" ]; then
  echo "$HITS"
  echo "Fehler: Kompanie-spezifische Begriffe gefunden (siehe oben). Branding gehört ins Kompanie-Repo." >&2
  exit 1
fi
echo "OK: keine Kompanie-spezifischen Begriffe gefunden."
