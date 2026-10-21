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

# Ausgabe für die App
php scripts/handbuch.php --app="$T/handbuch.json" > /dev/null
php -r '
$d = json_decode(file_get_contents($argv[1]), true);
$s = array_column($d["seiten"], null, "slug");
$ok = isset($s["punkte-vergeben"]) && $s["punkte-vergeben"]["rechte"] === ["manage_points"] && $s["punkte-vergeben"]["bereiche"] === ["points"]
  && $s["admin-installation-wordpress"]["variante"] === "wordpress" && $s["app-installieren"]["gruppe"] === "Für alle"
  && str_contains($s["punkte-vergeben"]["titel"], "{bereich.points}") && str_contains($s["admin-rollen-und-mitglieder"]["html"], "href=\"#/hilfe/mitglieder-anlegen\"");
exit($ok ? 0 : 1);' "$T/handbuch.json" && ok "App: Seiten mit Rechten, Bereichen, Variante, Gruppe; Platzhalter bleiben; Verweise auf #/hilfe/…" || fail "App-Ausgabe: $(head -c 400 "$T/handbuch.json")"

# Webseite mit Bezeichnungen der Kompanie, alles maskiert
mkdir -p "$T/brand"
printf '{"name":"TV <Beispiel> & Co","labels":{"sections":{"penalties":"Kasse"},"roles":{"member":"Kamerad"}}}' > "$T/brand/brand.json"
printf ':root {\n  --primary: #123456;\n}\n' > "$T/brand/theme.css"
php scripts/handbuch.php --site="$T/site" --brand="$T/brand" > /dev/null
[ "$(ls "$T/site"/*.html | wc -l)" -eq "$(( $(ls docs/handbuch/*.md | wc -l) + 1 ))" ] && ok "Webseite: eine Seite je Datei und eine Übersicht" || fail "Seiten: $(ls "$T/site")"
grep -q 'TV &lt;Beispiel&gt; &amp; Co' "$T/site/index.html" && ! grep -q '<Beispiel>' "$T/site/index.html" && ok "Name der Kompanie maskiert" || fail "Maskierung"
grep -q '<h1>Kasse verwalten</h1>' "$T/site/strafen-verwalten.html" && grep -q 'Kamerad' "$T/site/admin-rollen-und-mitglieder.html" && ! grep -rq '{bereich\.\|{rolle\.\|{kompanie\.' "$T/site" && ok "Bezeichnungen der Kompanie eingesetzt, keine Platzhalter übrig" || fail "Platzhalter"
grep -q 'Nur mit WordPress' "$T/site/admin-installation-wordpress.html" && grep -q 'Nur, wenn Kasse eingeschaltet ist' "$T/site/strafen-verwalten.html" && ok "Webseite markiert Variante und Bereiche" || fail "Markierung"
grep -q "Content-Security-Policy\" content=\"default-src 'none'" "$T/site/index.html" && ! grep -rq '<script' "$T/site" && ok "Keine Skripte, strenge CSP" || fail "CSP"
printf ':root {\n  --primary: red; } body { background: url(https://boese.example) }\n' > "$T/brand/theme.css"
if php scripts/handbuch.php --site="$T/site2" --brand="$T/brand" 2> /dev/null; then fail "Fremdes theme.css angenommen"; fi
ok "Fremdes theme.css abgelehnt"

echo; echo "$pass bestanden, 0 fehlgeschlagen"
