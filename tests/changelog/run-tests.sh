#!/usr/bin/env bash
# Tests des Changelogs: Prüfung von CHANGELOG.md, Prüfung zum Release und Ausgabe für die App.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
T="$(mktemp -d "${TMPDIR:-/tmp}/kompanie-changelog.XXXXXX")"
trap 'rm -rf "$T"' EXIT
cd "$ROOT"
pass=0
ok() { echo "  ok   $1"; pass=$((pass + 1)); }
fail() { echo "  FAIL $1"; exit 1; }
check() { php scripts/changelog.php --check --src="$T/$1" "${@:2}" 2>&1; }

php scripts/changelog.php --check > /dev/null && ok "CHANGELOG.md ist gültig" || fail "Prüfung: $(php scripts/changelog.php --check 2>&1)"

# Gültiges Beispiel
cat > "$T/gut.md" <<'MD'
# Changelog
Einleitung mit [Link](https://example.org).

## [Unveröffentlicht]

## [1.2.0] – 2026-11-03
### Neu
- **Fett** und `Code` <script>alert(1)</script>
  mit Fortsetzung
- [Verweis](https://example.org) und [kein Verweis](javascript:alert)
### Behoben
- Etwas

## [1.1.0] - 2026-11-01
### Geändert
- Anders
MD
OUT="$(check gut.md --version=1.2.0)" || fail "Gültiges Beispiel abgelehnt: $OUT"
OUT="$(check gut.md --version=1.2.0 --require-version)" || fail "Release mit passender Version abgelehnt: $OUT"
ok "Gültiges Changelog wird angenommen, auch zum Release"

# Fehler werden erkannt
cat > "$T/kaputt.md" <<'MD'
# Changelog

## [1.0.0] – 2026-10-01
### Neu
- Älter oben

## [Unveröffentlicht]

## [1.1.0] – 2026-02-30
### Verbessert
- Unbekannter Unterabschnitt
### Behoben

## [1.1.0] – 2026-10-02
Text ohne Unterabschnitt
#### Zu tief
### Neu
Kein Listenpunkt
- [Hilfe](seite:kalender)

## [2.0] – 2026-10-03
MD
OUT="$(check kaputt.md --version=1.1.0 || true)"
for want in '[Unveröffentlicht] muss der erste Abschnitt sein' 'Abschnitt [Unveröffentlicht] fehlt' 'ungültiges Datum 2026-02-30' 'unbekannter Unterabschnitt „Verbessert“' '„### Behoben“ in [1.1.0] hat keine Einträge' 'Eintrag ohne Unterabschnitt' 'nur Überschriften mit ## und ###' 'Einträge als Liste mit „- “' 'Verweise auf Handbuchseiten (seite:)' 'muss „## [Unveröffentlicht]“ oder „## [x.y.z] – JJJJ-MM-TT“ sein' '[1.1.0] muss älter sein als [1.1.0] darüber' '[1.1.0] muss älter sein als [1.0.0] darüber'; do
  grep -qF "$want" <<< "$OUT" || fail "Fehler nicht erkannt: $want – $OUT"
done
ok "Reihenfolge, doppelte Versionen, Datum, Unterabschnitte, leere Abschnitte und Verweise werden gemeldet"

printf '# Changelog\n\n## [Unveröffentlicht]\n\n## [1.3.0] – 2026-11-05\n### Neu\n- Zu früh\n\n## [1.2.0] – 2026-11-06\n### Neu\n- Datum später\n' > "$T/zukunft.md"
OUT="$(check zukunft.md --version=1.2.0 || true)"
grep -qF '[1.3.0] ist neuer als die Kern-Version 1.2.0' <<< "$OUT" || fail "Version über VERSION nicht erkannt: $OUT"
grep -qF 'Datum von [1.2.0] liegt nach dem der neueren Version [1.3.0]' <<< "$OUT" || fail "Datum nach der neueren Version nicht erkannt: $OUT"
ok "Version über der Kern-Version und Datum nach einer neueren Version werden gemeldet"

# Zum Release: Abschnitt zur Version aus VERSION, „Unveröffentlicht“ leer
OUT="$(check gut.md --version=1.3.0 --require-version || true)"
grep -qF 'Für die Kern-Version 1.3.0 (Datei VERSION) fehlt der Abschnitt' <<< "$OUT" || fail "Fehlende Version nicht erkannt: $OUT"
check gut.md --version=1.3.0 > /dev/null || fail "Ohne --require-version darf die neue Version noch fehlen"
sed 's/^## \[Unveröffentlicht\]$/## [Unveröffentlicht]\n### Neu\n- Noch offen/' "$T/gut.md" > "$T/offen.md"
check offen.md --version=1.2.0 > /dev/null || fail "Einträge unter Unveröffentlicht ohne --require-version abgelehnt"
OUT="$(check offen.md --version=1.2.0 --require-version || true)"
grep -qF '[Unveröffentlicht] ist nicht leer' <<< "$OUT" || fail "Nicht leeres Unveröffentlicht zum Release nicht erkannt: $OUT"
ok "Zum Release: fehlender Abschnitt zur Kern-Version und nicht leeres „Unveröffentlicht“ werden gemeldet"

if php scripts/changelog.php --app="$T/x.json" --src="$T/kaputt.md" --version=1.1.0 2> /dev/null; then fail "Fehlerhaftes Changelog ausgegeben"; fi
[ ! -e "$T/x.json" ] || fail "Datei trotz Fehler geschrieben"
ok "Fehlerhaftes Changelog wird nicht ausgegeben"

# Ausgabe für die App
php scripts/changelog.php --app="$T/gut.json" --src="$T/gut.md" --version=1.2.0 > /dev/null
php scripts/changelog.php --app="$T/offen.json" --src="$T/offen.md" --version=1.2.0 > /dev/null
php -r '
$d = json_decode(file_get_contents($argv[1]), true);
$o = json_decode(file_get_contents($argv[2]), true);
$fail = static function (string $m) { fwrite(STDERR, $m . "\n"); exit(1); };
if (array_column($d["versionen"], "version") !== ["1.2.0", "1.1.0"]) $fail("Versionen: " . json_encode($d["versionen"]));
if ($d["versionen"][1]["datum"] !== "2026-11-01") $fail("Datum fehlt");
if ($d["unveroeffentlicht"] !== null) $fail("Leeres Unveröffentlicht muss null sein");
$h = $d["versionen"][0]["html"];
foreach (["<h4>Neu</h4>", "<h4>Behoben</h4>", "<strong>Fett</strong>", "<code>Code</code>", "&lt;script&gt;", "mit Fortsetzung</li>", "<a href=\"https://example.org\"", "kein Verweis"] as $w) {
    if (!str_contains($h, $w)) $fail("HTML ohne $w: $h");
}
if (str_contains($h, "<script") || str_contains($h, "javascript:") || str_contains($h, "<h3>")) $fail("HTML nicht sauber: $h");
if (!str_contains((string) $o["unveroeffentlicht"], "Noch offen")) $fail("Unveröffentlicht fehlt");
' "$T/gut.json" "$T/offen.json" || fail "Ausgabe für die App"
ok "Ausgabe für die App: Versionen, Datum, maskiertes HTML, nur https-Verweise, Unveröffentlicht"

echo
echo "$pass bestanden, 0 fehlgeschlagen"
