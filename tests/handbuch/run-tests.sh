#!/usr/bin/env bash
# Tests des Handbuchs (Issue #12): Prüfung der Seiten, Ausgabe für die App und für die Webseite.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
T="$(mktemp -d "${TMPDIR:-/tmp}/kompanie-handbuch.XXXXXX")"
trap 'rm -rf "$T"' EXIT
cd "$ROOT"
pass=0
ok() { echo "  ok   $1"; pass=$((pass + 1)); }
fail() { echo "  FAIL $1"; exit 1; }

php scripts/handbuch.php --check > /dev/null && ok "Alle Seiten in docs/handbuch sind gültig" || fail "Prüfung: $(php scripts/handbuch.php --check 2>&1)"

# Fehler werden erkannt
cp -R docs/handbuch "$T/src"
printf -- '---\ntitel: Kaputt\nreihenfolge: 1\nrechte: [darf_alles]\nbereiche: [inventar]\n---\nText {bereich.unbekannt} und [weiter](seite:gibt-es-nicht).\n' > "$T/src/kaputt.md"
printf -- '---\ntitel: Offen\nreihenfolge: 2\nrechte: manage_settings\nbereiche: events\n---\nText.\n' > "$T/src/offen.md"
printf -- '---\ntitel: Für alle\nreihenfolge: 3\nrechte: []\n---\nSiehe [Installation](seite:admin-installation-eigenstaendig) und [x](seite:javascript:alert).\n' > "$T/src/verweis.md"
rm "$T/src/strafen-verwalten.md" "$T/src/rollen-vergeben.md"
OUT="$(php scripts/handbuch.php --check --src="$T/src" 2>&1 || true)"
for want in 'unbekanntes Recht „darf_alles“' 'unbekannter Bereich „inventar“' 'unbekannter Platzhalter {bereich.unbekannt}' 'Verweis auf fehlende Seite „gibt-es-nicht“' 'Bereich „penalties“ hat eigene Rechte' 'offen.md: „rechte“ muss eine Liste in [ ] sein' 'offen.md: „bereiche“ muss eine Liste in [ ] sein' 'verweis.md: Verweis auf „admin-installation-eigenstaendig“, die nicht jeder sieht' 'Für das Recht „promote_users“ fehlt eine eigene Handbuchseite' 'verweis.md: ungültiger Verweis seite:javascript:alert'; do
  grep -qF "$want" <<< "$OUT" || fail "Fehler nicht erkannt: $want – $OUT"
done
ok "Unbekannte Rechte, Bereiche, Platzhalter, Listen ohne [ ], tote oder nicht sichtbare Verweise und Rechte ohne Seite werden gemeldet"
# Vorausgesetzte Bereiche: Wer Umfragen sieht, hat auch das Schwarze Brett (Umfragen brauchen es), umgekehrt nicht.
cp -R docs/handbuch "$T/req"
printf -- '---\ntitel: Umfrage\nreihenfolge: 4\nrechte: [member]\nbereiche: [polls]\n---\nSiehe [Brett](seite:schwarzes-brett).\n' > "$T/req/u.md"
php scripts/handbuch.php --check --src="$T/req" > /dev/null || fail "Verweis von Umfragen aufs Brett abgelehnt: $(php scripts/handbuch.php --check --src="$T/req" 2>&1)"
printf -- '---\ntitel: Brett\nreihenfolge: 5\nrechte: [member]\nbereiche: [board]\n---\nSiehe [Umfragen](seite:umfragen).\n' > "$T/req/b.md"
OUT="$(php scripts/handbuch.php --check --src="$T/req" 2>&1 || true)"
grep -qF 'b.md: Verweis auf „umfragen“, die nicht jeder sieht' <<< "$OUT" || fail "Verweis vom Brett auf Umfragen angenommen: $OUT"
ok "Vorausgesetzte Bereiche gelten beim Verweis als eingeschaltet, nur in diese Richtung"
if php scripts/handbuch.php --app="$T/x.json" --src="$T/src" 2> /dev/null; then fail "Fehlerhaftes Handbuch ausgegeben"; fi
ok "Fehlerhaftes Handbuch wird nicht ausgegeben"

# Kopffeld einrecht und Bilder: Fehler werden erkannt
cp -R docs/handbuch "$T/ein"
printf -- '---\ntitel: Einrecht\nreihenfolge: 6\nrechte: []\neinrecht: [manage_points, darf_alles]\n---\nText.\n' > "$T/ein/e1.md"
printf -- '---\ntitel: Einrecht ohne Liste\nreihenfolge: 7\nrechte: []\neinrecht: manage_points\n---\nText.\n' > "$T/ein/e2.md"
printf -- '---\ntitel: Bilder\nreihenfolge: 8\nrechte: []\n---\nText mit ![Foto](bild:mitten) im Satz.\n\n![](bild:ohne-text)\n\n![Foto](bild:Gross)\n' > "$T/ein/b1.md"
OUT="$(php scripts/handbuch.php --check --src="$T/ein" 2>&1 || true)"
for want in 'e1.md: unbekanntes Recht „darf_alles“' 'e2.md: „einrecht“ muss eine Liste in [ ] sein' 'b1.md: Bild nur als eigene Zeile'; do
  grep -qF "$want" <<< "$OUT" || fail "Fehler nicht erkannt: $want – $OUT"
done
ok "einrecht: unbekannte Rechte und Listen ohne [ ] werden gemeldet; Bilder nur als eigene Zeile mit Beschreibung und gültigem Namen"
# Wer eine Seite mit einrecht sieht, hat nur irgendeines dieser Rechte: Ziele brauchen dieselben (oder mehr) Rechte zur Auswahl.
cp -R docs/handbuch "$T/ein2"
printf -- '---\ntitel: Liste\nreihenfolge: 6\nrechte: []\neinrecht: [manage_points, create_users]\n---\nSiehe [a](seite:ziel-weiter) und [b](seite:ziel-eng).\n' > "$T/ein2/quelle.md"
printf -- '---\ntitel: Weiter\nreihenfolge: 7\nrechte: []\neinrecht: [manage_points, create_users, promote_users]\n---\nText.\n' > "$T/ein2/ziel-weiter.md"
printf -- '---\ntitel: Eng\nreihenfolge: 8\nrechte: [create_users]\n---\nText.\n' > "$T/ein2/ziel-eng.md"
printf -- '---\ntitel: Admin\nreihenfolge: 9\nrechte: [manage_settings]\n---\nSiehe [a](seite:quelle).\n' > "$T/ein2/admin.md"
OUT="$(php scripts/handbuch.php --check --src="$T/ein2" 2>&1 || true)"
grep -qF 'quelle.md: Verweis auf „ziel-eng“, die nicht jeder sieht' <<< "$OUT" || fail "Verweis auf engere Seite angenommen: $OUT"
! grep -qF 'ziel-weiter' <<< "$OUT" && ! grep -qF 'admin.md' <<< "$OUT" || fail "Verweis auf passende Seite abgelehnt: $OUT"
ok "einrecht: Verweise nur auf Seiten, die jeder mit einem dieser Rechte sieht (Admins sehen alles)"
# Pflichtseite für ein Recht auch über einrecht
cp -R docs/handbuch "$T/ein3"
rm -f "$T/ein3/rollen-vergeben.md"
printf -- '---\ntitel: Rollen\nreihenfolge: 6\nrechte: []\neinrecht: [promote_users, create_users]\n---\nText.\n' > "$T/ein3/rollen-vergeben.md"
php scripts/handbuch.php --check --src="$T/ein3" > /dev/null || fail "Pflichtseite über einrecht abgelehnt: $(php scripts/handbuch.php --check --src="$T/ein3" 2>&1)"
ok "Pflichtseite für ein Recht auch mit rechte: [] und dem Recht in einrecht"

# Ausgabe für die App und die Webseite: eigene Testseiten neben dem Handbuch (unabhängig von dessen Wortlaut)
cp -R docs/handbuch "$T/out"
printf -- '---\ntitel: "{bereich.penalties} Test"\nreihenfolge: 900\nrechte: [manage_penalties]\nbereiche: [penalties]\n---\nFür {rolle.member}. Siehe [Alle](seite:zz-alle).\n\n![Die {bereich.penalties} im Überblick](bild:zz-bild)\n\n![Ohne Foto](bild:zz-fehlt)\n' > "$T/out/zz-strafen.md"
printf -- '---\ntitel: Für alle Test\nreihenfolge: 901\nrechte: []\n---\nText.\n' > "$T/out/zz-alle.md"
printf -- '---\ntitel: WordPress Test\nreihenfolge: 902\nrechte: [manage_settings]\nvariante: wordpress\n---\nText.\n' > "$T/out/zz-wp.md"
printf -- '---\ntitel: Liste Test\nreihenfolge: 903\nrechte: []\neinrecht: [manage_points, create_users]\n---\nText.\n' > "$T/out/zz-liste.md"
php scripts/handbuch.php --app="$T/handbuch.json" --src="$T/out" > /dev/null
php -r '
$d = json_decode(file_get_contents($argv[1]), true);
$s = array_column($d["seiten"], null, "slug");
$p = $s["zz-strafen"];
$ok = $p["rechte"] === ["manage_penalties"] && $p["bereiche"] === ["penalties"] && $p["einrecht"] === [] && $p["gruppe"] === "Für Aufgaben in der Verwaltung"
  && $s["zz-wp"]["variante"] === "wordpress" && $s["zz-wp"]["gruppe"] === "Für Admins" && $s["zz-alle"]["gruppe"] === "Für alle"
  && $s["zz-liste"]["einrecht"] === ["manage_points", "create_users"] && $s["zz-liste"]["gruppe"] === "Für Aufgaben in der Verwaltung"
  && str_contains($p["titel"], "{bereich.penalties}") && str_contains($p["html"], "href=\"#/hilfe/zz-alle\"")
  && str_contains($p["html"], "<figure class=\"hilfe-bild\"><img src=\"bild:zz-bild\" alt=\"Die {bereich.penalties} im Überblick\" loading=\"lazy\">")
  && str_contains($p["html"], "src=\"bild:zz-fehlt\"");
exit($ok ? 0 : 1);' "$T/handbuch.json" && ok "App: Rechte, einrecht, Bereiche, Variante, Gruppe; Platzhalter bleiben; Verweise auf #/hilfe/…; Bilder als bild:name (die App setzt die Adresse ein)" || fail "App-Ausgabe: $(head -c 600 "$T/handbuch.json")"

# Webseite mit Bezeichnungen der Kompanie, alles maskiert
mkdir -p "$T/brand" "$T/bilder"
printf '{"name":"TV <Beispiel> & Co","labels":{"sections":{"penalties":"Kasse"},"roles":{"member":"Kamerad"}}}' > "$T/brand/brand.json"
printf ':root {\n  --primary: #123456;\n}\n' > "$T/brand/theme.css"
printf 'jpg' > "$T/bilder/zz-bild.jpg"
php scripts/handbuch.php --site="$T/site" --brand="$T/brand" --src="$T/out" --bilder="$T/bilder" > /dev/null
[ "$(ls "$T/site"/*.html | wc -l)" -eq "$(( $(ls "$T/out"/*.md | wc -l) + 1 ))" ] && ok "Webseite: eine Seite je Datei und eine Übersicht" || fail "Seiten: $(ls "$T/site")"
grep -q 'TV &lt;Beispiel&gt; &amp; Co' "$T/site/index.html" && ! grep -q '<Beispiel>' "$T/site/index.html" && ok "Name der Kompanie maskiert" || fail "Maskierung"
grep -q '<h1>Kasse Test</h1>' "$T/site/zz-strafen.html" && grep -q 'Kamerad' "$T/site/zz-strafen.html" && ! grep -rq '{bereich\.\|{rolle\.\|{kompanie\.' "$T/site" && ok "Bezeichnungen der Kompanie eingesetzt, keine Platzhalter übrig" || fail "Platzhalter"
grep -q 'Nur mit WordPress' "$T/site/zz-wp.html" && grep -q 'Nur, wenn Kasse eingeschaltet ist' "$T/site/zz-strafen.html" && ok "Webseite markiert Variante und Bereiche" || fail "Markierung"
grep -q '<img src="bilder/zz-bild.jpg" alt="Die Kasse im Überblick"' "$T/site/zz-strafen.html" && [ -f "$T/site/bilder/zz-bild.jpg" ] && ! grep -q 'zz-fehlt' "$T/site/zz-strafen.html" && ok "Webseite: Fotos nach bilder/, ohne Foto kein Bild" || fail "Bilder: $(grep -o '<figure.*figure>' "$T/site/zz-strafen.html")"
php scripts/handbuch.php --site="$T/site" --brand="$T/brand" --src="$T/out" > /dev/null
[ -f "$T/site/bilder/zz-bild.jpg" ] && grep -q 'bilder/zz-bild.jpg' "$T/site/zz-strafen.html" && ok "Ohne --bilder bleiben die vorhandenen Fotos (Freigabe einer Version)" || fail "Fotos verloren"
grep -q "Content-Security-Policy\" content=\"default-src 'none'" "$T/site/index.html" && ! grep -rq '<script' "$T/site" && ok "Keine Skripte, strenge CSP" || fail "CSP"
printf ':root {\n  --primary: red; } body { background: url(https://boese.example) }\n' > "$T/brand/theme.css"
if php scripts/handbuch.php --site="$T/site2" --brand="$T/brand" 2> /dev/null; then fail "Fremdes theme.css angenommen"; fi
ok "Fremdes theme.css abgelehnt"

echo; echo "$pass bestanden, 0 fehlgeschlagen"
