#!/usr/bin/env bash
# Tests des Wartungsabos (scripts/wartung.php, ohne Netz): wer neue Versionen bekommt, Aufbau der Variable.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
pass=0
ok() { echo "  ok   $1"; pass=$((pass + 1)); }
fail() { echo "  FAIL $1"; exit 1; }
REPOS=$'Org/kompanie-a\nOrg/kompanie-b\nOrg/kompanie-c\nOrg/kompanie-d'
LIST=$'# Kommentar\n\nkompanie-a 2026-12-31\nOrg/Kompanie-B unbefristet\nkompanie-c 2026-11-20\nkompanie-x 2027-01-01'
filter() { printf '%s\n' "$REPOS" | WARTUNG="$LIST" php scripts/wartung.php --filter --complete "$@"; }

out="$(filter --today=2026-11-21 2> /dev/null)"
[ "$out" = $'Org/kompanie-a\nOrg/kompanie-b' ] && ok "Nur laufende Wartung (Datum, unbefristet, Groß-/Kleinschreibung, mit Organisation)" || fail "Filter: $out"
[ "$(filter --today=2026-12-31 2> /dev/null)" = $'Org/kompanie-a\nOrg/kompanie-b' ] && ok "Letzter Tag zählt noch mit" || fail "Letzter Tag"
[ "$(filter --today=2027-01-01 2> /dev/null)" = 'Org/kompanie-b' ] && ok "Danach nur noch unbefristet" || fail "Nach Ablauf"
err="$(filter --today=2026-11-21 2>&1 > /dev/null)"
grep -q "Übersprungen (Wartung endete am 2026-11-20): Org/kompanie-c" <<< "$err" && grep -q "Übersprungen (kein Wartungsabo): Org/kompanie-d" <<< "$err" \
  && ok "Übersprungene Kompanien mit Grund" || fail "Meldungen: $err"
grep -q "::warning::.*kompanie-x" <<< "$err" && ok "Warnung für unbekannte Namen" || fail "Unbekannter Name: $err"
err="$(printf 'Org/kompanie-c\n' | WARTUNG="$LIST" php scripts/wartung.php --filter --today=2026-11-21 2>&1 > /dev/null)"
! grep -q "::warning::" <<< "$err" && ok "Einzelnes Repo: keine Warnung für die übrigen Namen" || fail "Einzeln: $err"

out="$(printf '%s\n' "$REPOS" | WARTUNG=$'# nur Kommentar\n' php scripts/wartung.php --filter 2> "${TMPDIR:-/tmp}/wartung-err.txt")"
[ "$out" = "$REPOS" ] && grep -q "::warning::Variable KOMPANIE_WARTUNG fehlt" "${TMPDIR:-/tmp}/wartung-err.txt" && ok "Leere Variable: alle Kompanien, mit Warnung" || fail "Leer: $out"
rm -f "${TMPDIR:-/tmp}/wartung-err.txt"

for bad in 'kompanie-a' 'kompanie-a 2026-02-30' 'kompanie-a morgen' 'kompanie a 2026-12-31' $'kompanie-a 2026-12-31\nKompanie-A unbefristet'; do
  if printf '%s\n' "$REPOS" | WARTUNG="$bad" php scripts/wartung.php --filter > /dev/null 2>&1; then fail "Angenommen: $bad"; fi
done
ok "Fehler im Aufbau brechen ab (fehlendes oder ungültiges Datum, Leerzeichen im Namen, doppelt)"

[ "$(WARTUNG="$LIST" php scripts/wartung.php --until=Org/kompanie-a)" = 2026-12-31 ] && [ "$(WARTUNG="$LIST" php scripts/wartung.php --until=kompanie-b)" = unbefristet ] \
  && [ -z "$(WARTUNG="$LIST" php scripts/wartung.php --until=Org/kompanie-d)" ] && ok "--until gibt das Datum aus" || fail "--until"
WARTUNG="$LIST" php scripts/wartung.php --check 2> /dev/null && ok "--check" || fail "--check"

[ "$(ONLY=Org/kompanie-a WARTUNG="$LIST" scripts/kompanien.sh 2> /dev/null)" = Org/kompanie-a ] \
  && [ -z "$(ONLY=Org/kompanie-d WARTUNG="$LIST" scripts/kompanien.sh 2> /dev/null)" ] && ok "kompanien.sh mit ONLY beachtet die Wartung" || fail "kompanien.sh ONLY"

echo "$pass Tests bestanden"
