# Lizenzen

Technische Festlegungen zu den Lizenzen der Kompanie-App (Issue #173). Keine Rechtsberatung: Geschäftsmodell (Verkauf
von Kopien, Dienstleistung, Doppel-Lizenzierung) und Urheberrechtslage vor dem Verkauf klären.

## Projektlizenz: GPL-2.0-or-later

- Der eigene Code steht unter **GPL-2.0-or-later**: `LICENSE` (Hinweis mit „or (at your option) any later version“ und
  dem vollständigen Text der GPL Version 2), `"license"` in allen `package.json` und in `composer.json`, Plugin-Kopf
  `adapters/wordpress/kompanie-app.php`, Kopf von `adapters/standalone/index.php` und `installer/install.php`. Ein Test
  (`packages/frontend/test/licenses.test.ts`) prüft, dass alle Stellen übereinstimmen.
- **„or later“ beibehalten:** Apache-2.0 (pdf.js, DOMPurify, Roboto Slab) ist mit GPLv2 allein nicht verträglich, mit
  GPLv3 schon. Nicht auf `GPL-2.0-only` umstellen.
- **Quellcode:** Die ausgelieferten Programmdateien sind verkleinert und damit nicht die bevorzugte Form zur Bearbeitung.
  Wo der vollständige Quellcode liegt, steht in `LICENSE`, in `THIRD-PARTY-NOTICES.txt` und in der App unter
  *Hilfe → Lizenzen* (Adresse aus `"repository"` in der `package.json` im Wurzelverzeichnis).

## Positivliste für ausgelieferte Bausteine

Der eigene Code soll später zusätzlich unter einer geschlossenen Lizenz verkauft werden können (Doppel-Lizenzierung).
Das geht nur, wenn kein fremder Code unter einer Lizenz ausgeliefert wird, die das ganze Werk erfasst. Deshalb sind
für Bausteine, die in den Paketen landen, nur erlaubt:

| Lizenz | Hinweis |
|---|---|
| MIT, ISC, 0BSD, BSD-2-Clause, BSD-3-Clause | freizügig |
| Apache-2.0 | freizügig; eine NOTICE-Datei des Pakets wird mitgeliefert |
| MPL-2.0 | Copyleft nur je Datei |
| LGPL-2.1-or-later, LGPL-3.0-or-later, LGPL-2.1-only | nur für austauschbare Bibliotheken; „-only“ schließt nur den Wechsel auf eine spätere LGPL-Version aus, die Pflichten sind dieselben (PHPMailer, Issue #172) |
| OFL-1.1 | Schriften; dürfen nicht allein verkauft werden |

**Nicht erlaubt:** GPL, AGPL, Lizenzen mit Ausnahmen (`WITH …`), proprietäre oder kostenpflichtige Erweiterungen
(z. B. TipTap Pro, Premium-Pakete von CKEditor oder TinyMCE) und Pakete ohne Lizenzangabe. Bei einer Wahl
(`MPL-2.0 OR Apache-2.0`, DOMPurify) gilt die erste erlaubte Lizenz in der Reihenfolge der Tabelle, also die
freizügigere.

Nur beim Bauen genutzte Werkzeuge (z. B. `sharp` mit `libvips` unter LGPL-3.0-or-later, `ajv`, TypeScript, Vite)
gehen nicht in die Pakete und fallen nicht unter die Liste. WordPress-eigene Bausteine (z. B. die PHPMailer-Kopie von
WordPress) nutzt das Plugin zur Laufzeit, liefert sie aber nicht mit.

## Drittanbieter-Hinweise beim Bauen

`packages/frontend/build/licenses.ts` (Vite-Plugin, läuft bei jedem `vite build`):

1. **Was ausgeliefert wird**, ergibt sich aus dem Build selbst: jedes Modul aus `node_modules`, das in die App eingeht,
   bringt sein Paket in die Liste. Darum stehen dort je Kompanie nur die gewählte Schrift und keine Build-Werkzeuge,
   und eine neue Abhängigkeit erscheint ohne weitere Pflege, sobald die App sie importiert. Dazu die Hilfsfunktionen,
   die Vite selbst in den Build schreibt (MIT), und die PHP-Bibliotheken aus `composer.lock` (`packages`, nicht
   `packages-dev`; derzeit PHPMailer, das nur die eigenständige Version mitliefert – die Hinweise sind für beide Pakete dieselben). Ein unbekanntes virtuelles Modul oder eine Datei aus `node_modules`, deren Paket
   nicht erfasst ist, bricht den Build ab.
2. **Prüfung:** Die Lizenzangabe des Pakets (`license` in `package.json` bzw. `composer.lock`) muss nach der
   Positivliste erlaubt sein, und eine Lizenzdatei des Pakets muss den Text genau dieser Lizenz enthalten. Sonst
   bricht der Build mit einer Meldung ab, die Paket und Lizenz nennt.
3. **Ergebnis:** `THIRD-PARTY-NOTICES.txt` in `dist/` mit Version, Lizenz, Herkunft, Copyright-Zeilen (aus der
   Lizenzdatei und den Köpfen der eingebundenen Dateien) und Lizenztext je Baustein; `scripts/package.php` legt sie
   zusammen mit `LICENSE` in den Hauptordner beider Pakete. Dieselben Daten zeigt die App unter *Hilfe → Lizenzen*
   (eigenes, nachgeladenes Teilstück). Lizenzkommentare im verkleinerten JavaScript sind dafür nicht nötig.

`tests/release/run-tests.sh` prüft, dass beide Pakete `LICENSE` und `THIRD-PARTY-NOTICES.txt` enthalten und keine
Build-Werkzeuge.

## Neue Abhängigkeit

- Vor dem Hinzufügen die Lizenz prüfen (Positivliste oben). Passt sie nicht, schlägt der Build fehl; dann einen
  anderen Baustein wählen, statt die Liste zu erweitern.
- Die Positivliste nur nach einer bewussten Entscheidung ändern, in `ALLOWED` in `packages/frontend/build/licenses.ts`
  und in dieser Datei.
- Eine PHP-Bibliothek, die ausgeliefert wird, gehört in `require` der `composer.json` (dann prüft der Build sie mit);
  für den Lizenztext muss beim Bauen `vendor/` installiert sein (`composer install --no-dev`).
- Code, der aus einem anderen Projekt übernommen wird (statt als Paket eingebunden), erfasst die Prüfung nicht: dessen
  Copyright- und Lizenzhinweis von Hand in der übernommenen Datei behalten und hier nennen.
