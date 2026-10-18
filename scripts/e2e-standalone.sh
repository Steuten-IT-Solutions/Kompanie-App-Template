#!/usr/bin/env bash
# E2E-Tests (Playwright) gegen die eigenständige Version, installiert im Unterordner /app/.
#   scripts/e2e-standalone.sh [weitere Playwright-Argumente]
# Baut nichts: vorher scripts/build-frontend.sh ausführen.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PORT="${KOMPANIE_E2E_PORT:-8891}"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/kompanie-e2e.XXXXXX")"
mkdir -p "$WORK/www"
ln -s "$ROOT/adapters/standalone" "$WORK/www/app"
export KOMPANIE_DATA_DIR="$WORK/data"
php -S "localhost:$PORT" -t "$WORK/www" "$ROOT/tests/standalone/e2e-router.php" > "$WORK/server.log" 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null || true; rm -rf "$WORK"' EXIT
for _ in $(seq 1 30); do curl -s -o /dev/null "http://localhost:$PORT/" && break; sleep 0.5; done
cd "$ROOT/packages/frontend"
KOMPANIE_VARIANT=standalone KOMPANIE_BASE_URL="http://localhost:$PORT" npx playwright test "$@"
