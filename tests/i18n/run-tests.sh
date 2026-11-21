#!/usr/bin/env bash
# Tests von scripts/check-i18n.php: Aufrufe finden, fehlende und verwaiste Einträge, Platzhalter, Mehrzahl.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
T="$(mktemp -d "${TMPDIR:-/tmp}/kompanie-i18n.XXXXXX")"
trap 'rm -rf "$T"' EXIT
cd "$ROOT"
pass=0
ok() { echo "  ok   $1"; pass=$((pass + 1)); }
fail() { echo "  FAIL $1"; exit 1; }
check() { php scripts/check-i18n.php --frontend="$T/fe" --php="$T/php" --catalogs="$T/cat" "$@" 2>&1; }

OUT="$(php scripts/check-i18n.php 2>&1)" && ok "Kataloge des Repos sind vollständig" || fail "Repo: $OUT"

mkdir -p "$T/fe/views" "$T/fe/generated" "$T/php" "$T/cat"
cat > "$T/fe/views/A.tsx" <<'TS'
// t('im Kommentar') zählt nicht
const a = 'auch t("in einer Zeichenkette") nicht';
const b = `Vorlage ${t('In der Vorlage')} mit ${x}`;
export const C = () => <p title={t('Titel')}>{t("Hallo, {name}", { name })} {tn('{count} Termin', '{count} Termine', n)}</p>;
obj.t('Methode zählt nicht');
const s = t('Zeile mit \'Zitat\'');
TS
cat > "$T/fe/generated/G.ts" <<'TS'
t('Erzeugt, zählt nicht');
TS
cat > "$T/php/A.php" <<'PHP'
<?php
use KompanieApp\Core\I18n\I18n;
$x = I18n::t('Bitte melde dich an.');
$y = \KompanieApp\Core\I18n\I18n::tn('{count} Mitglied', '{count} Mitglieder', $n, ['x' => 1]);
$z = I18n::t("Mit \"Anführung\"");
PHP
cat > "$T/cat/en.json" <<'JSON'
{
  "In der Vorlage": "In the template",
  "Titel": "Title",
  "Hallo, {name}": "Hello, {name}",
  "{count} Termine": { "one": "one event", "other": "{count} events" },
  "Zeile mit 'Zitat'": "Line with 'quote'",
  "Bitte melde dich an.": "Please sign in.",
  "{count} Mitglieder": { "one": "{count} member", "other": "{count} members" },
  "Mit \"Anführung\"": "With \"quotes\""
}
JSON
OUT="$(check)" || fail "Vollständiges Beispiel abgelehnt: $OUT"
[[ "$OUT" == "8 Texte, 1 Kataloge vollständig." ]] || fail "Falsche Zählung: $OUT"
ok "Aufrufe in TS und PHP werden gefunden, Kommentare, Zeichenketten, Methoden und generated/ nicht"

cp "$T/cat/en.json" "$T/good.json"
python3 - "$T/cat/en.json" <<'PY'
import json, sys
p = sys.argv[1]; d = json.load(open(p))
del d['Titel']; d['Alt'] = 'Old'; d['Hallo, {name}'] = 'Hello, {nam}'; d['{count} Termine'] = 'events'
json.dump(d, open(p, 'w'), ensure_ascii=False)
PY
OUT="$(check)" && fail "Fehler nicht erkannt: $OUT"
for want in 'fehlt „Titel“' '„Alt“ wird nicht mehr genutzt' '„Hallo, {name}“: Platzhalter' '„{count} Termine“ braucht die Formen one und other'; do
  [[ "$OUT" == *"$want"* ]] || fail "Meldung fehlt: $want — $OUT"
done
ok "Fehlende, verwaiste, falsche Platzhalter und fehlende Mehrzahl werden gemeldet"

OUT="$(check --missing=en)"
[[ "$OUT" == *'"Titel": ""'* ]] || fail "--missing listet Titel nicht: $OUT"
ok "--missing listet fehlende Einträge"

cp "$T/good.json" "$T/cat/en.json"
echo "const d = t(name); const e = t('a' + b); const f = tn('x', y, n);" > "$T/fe/B.ts"
OUT="$(check)" && fail "Ausdruck statt Zeichenkette nicht erkannt: $OUT"
[[ "$(grep -c 'braucht den Text als einfache Zeichenkette' <<< "$OUT")" == 3 ]] || fail "Nicht alle Ausdrücke gemeldet: $OUT"
ok "Ausdrücke statt Zeichenketten werden gemeldet"

echo "$pass Tests bestanden."
