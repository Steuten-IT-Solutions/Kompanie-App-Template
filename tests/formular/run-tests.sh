#!/usr/bin/env bash
# Tests der Formulare „Neue Kompanie“ und „Kompanie ändern“ (ohne Netz):
# Issue-Text → kompanie.json (scripts/issue-to-kompanie.php), Ablehnungen von scripts/fetch-logo.php.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
T="$(mktemp -d "${TMPDIR:-/tmp}/kompanie-formular.XXXXXX")"
trap 'rm -rf "$T"' EXIT
cd "$ROOT"
pass=0
ok() { echo "  ok   $1"; pass=$((pass + 1)); }
fail() { echo "  FAIL $1"; exit 1; }
get() { php -r '$k = json_decode(file_get_contents($argv[1]), true); foreach (explode(".", $argv[2]) as $p) { $k = $k[$p] ?? null; } echo $k === null ? "" : (is_scalar($k) ? $k : json_encode($k, JSON_UNESCAPED_UNICODE));' "$1" "$2"; }

php scripts/issue-to-kompanie.php --mode=neu --body=tests/formular/neu.md --out="$T/n" > /dev/null
K="$T/n/kompanie.json"
[ "$(get "$K" name)" = "Schützenkompanie Musterdorf" ] && [ "$(get "$K" colors.primary)" = "#1a4d8f" ] && ok "Name und Farbe (klein geschrieben)" || fail "Name/Farbe"
[ "$(get "$K" labels.roles.manager)" = "Spieß" ] && [ "$(get "$K" labels.sections)" = "" ] && ok "Bezeichnungen; leere Gruppen entfallen" || fail "Bezeichnungen: $(cat "$K")"
[ "$(get "$K" features)" = '{"news":false,"points":false}' ] && ok "Angekreuzte Bereiche ausgeschaltet" || fail "Bereiche: $(get "$K" features)"
[ "$(cat "$T/n/repo.txt")" = "schuetzen-musterdorf" ] && grep -q "user-attachments/assets/0f0e5a2c" "$T/n/logo-url.txt" && ok "Repo-Name und Logo-Anhang" || fail "Repo/Logo"
(cd packages/branding && node -e "
  const Ajv = require('ajv/dist/2020').default; const fs = require('fs');
  const v = new Ajv({ strict: false, validateFormats: false }).compile(JSON.parse(fs.readFileSync('../../config/kompanie.schema.json')));
  process.exit(v(JSON.parse(fs.readFileSync(process.argv[1]))) ? 0 : 1);" "$K") && ok "Ergebnis passt zum Schema" || fail "Schema"

sed 's/#1A4D8f/blau/; s/schuetzen-musterdorf/Schützen Dorf/; s#https://github.com/user-attachments/assets/[0-9a-f-]*#https://example.org/logo.png#' tests/formular/neu.md > "$T/bad.md"
if php scripts/issue-to-kompanie.php --mode=neu --body="$T/bad.md" --out="$T/b" 2> /dev/null; then fail "Ungültige Angaben angenommen"; fi
F="$(cat "$T/b/fehler.txt")"
grep -q "Name des Repos" <<< "$F" && grep -q "Hauptfarbe" <<< "$F" && grep -q "Logo fehlt" <<< "$F" && ok "Fehler werden gesammelt gemeldet (Repo, Farbe, Logo von fremder Adresse)" || fail "Fehlermeldung: $F"

printf '### Name des Repos\n\nschuetzen-musterdorf\n\n### Akzentfarbe\n\n#00ff00\n\n### Kurzname\n\n-\n\n### Neues Logo\n\n_No response_\n' > "$T/aendern.md"
php scripts/issue-to-kompanie.php --mode=aendern --body="$T/aendern.md" --out="$T/a" --current="$K" > /dev/null
[ "$(get "$T/a/kompanie.json" colors.accent)" = "#00ff00" ] && [ "$(get "$T/a/kompanie.json" shortName)" = "" ] && [ "$(get "$T/a/kompanie.json" name)" = "Schützenkompanie Musterdorf" ] && [ ! -f "$T/a/logo-url.txt" ] \
  && ok "Ändern: nur angegebene Felder, „-“ entfernt, Rest bleibt" || fail "Ändern: $(cat "$T/a/kompanie.json")"
printf '### Name des Repos\n\nkompanie-app-template\n' > "$T/res.md"
if php scripts/issue-to-kompanie.php --mode=aendern --body="$T/res.md" --out="$T/r" --repo-only 2> /dev/null; then fail "Reservierter Name angenommen"; else ok "Reservierte Repo-Namen abgelehnt"; fi

# Überschriften im Freitext: eine fremde gehört zum Text, eine bekannte macht das Formular mehrdeutig
awk '{print} /^Herzlich willkommen!$/ {print "### Zwischentitel"}' tests/formular/neu.md > "$T/titel.md"
php scripts/issue-to-kompanie.php --mode=neu --body="$T/titel.md" --out="$T/t" > /dev/null
grep -q "### Zwischentitel" <<< "$(get "$T/t/kompanie.json" guestWelcome)" && [ "$(cat "$T/t/repo.txt")" = schuetzen-musterdorf ] && ok "Fremde Überschrift bleibt Teil des Textes" || fail "Fremde Überschrift"
awk '{print} /^Herzlich willkommen!$/ {print "### Name des Repos"; print ""; print "anderes-repo"}' tests/formular/neu.md > "$T/doppelt.md"
if php scripts/issue-to-kompanie.php --mode=neu --body="$T/doppelt.md" --out="$T/d" 2> /dev/null; then fail "Doppeltes Feld angenommen"; fi
grep -q "doppelt" "$T/d/fehler.txt" && ok "Doppeltes Feld (Überschrift im Text) wird abgelehnt" || fail "Doppelt: $(cat "$T/d/fehler.txt")"

for url in "https://example.org/logo.png" "http://github.com/user-attachments/assets/x" "https://github.com.example.org/user-attachments/x" \
  "https://github-production-user-asset-6210df.s3.amazonaws.com/x.png"; do   # S3 nur als Weiterleitungsziel von GitHub
  if php scripts/fetch-logo.php --url="$url" --out="$T/logo" 2> /dev/null; then fail "Logo von $url geladen"; fi
done
ok "Logo nur von GitHub-Anhängen (https)"

echo; echo "$pass bestanden, 0 fehlgeschlagen"
