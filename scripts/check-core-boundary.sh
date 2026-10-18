#!/usr/bin/env bash
# Prüft, dass der Kern (packages/core/src) keine WordPress-Funktionen verwendet.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PATTERN='\$wpdb|\bwp_[a-z_]+\s*\(|\b(get|update|add|delete)_option\s*\(|\b(get|set|delete)_transient\s*\(|\bcurrent_user_can\s*\(|\bis_user_logged_in\s*\(|\b(add|do|remove)_(action|filter)\s*\(|\bapply_filters\s*\(|\bregister_rest_route\s*\(|\bWP_[A-Z][A-Za-z_]+|\bABSPATH\b'
# Kommentarzeilen (/** … */, //, #) dürfen WordPress erwähnen.
HITS=$(grep -rnE "$PATTERN" "$ROOT/packages/core/src" | grep -vE '^[^:]+:[0-9]+:[[:space:]]*(\*|/\*|//|#)' || true)
if [ -n "$HITS" ]; then
  echo "$HITS"
  echo "Fehler: Der Kern darf keine WordPress-Funktionen verwenden (siehe Treffer oben)." >&2
  exit 1
fi
echo "OK: Kern ist frei von WordPress-Aufrufen."
