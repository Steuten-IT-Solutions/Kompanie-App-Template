# Veröffentlichen: Kern, Kompanien, Updates

Wie aus einer Änderung signierte Pakete für jede Kompanie werden und wie Updates verteilt werden.
Hintergrund: [Architektur, Abschnitt 11](ARCHITEKTUR.md#11-updates-und-zurückrollen) und
[Offene Punkte, Abschnitt 1](OFFENE-PUNKTE.md#1-update-verteilung-und-gestaffelte-releases).

## Sicherheitsprinzip

Wer den Signaturschlüssel hat, kann Code auf **allen** Installationen ausführen. Deshalb:

- Der Schlüssel liegt **nur im Kern-Repo**, als Secret der GitHub-Environment `veroeffentlichen`
  (Zugriff nur für den Branch `main`). Workflows im Kern-Repo starten können nur Personen mit Schreibrecht darauf.
- Der Build-Job (npm, Branding aus den Daten der Kompanie, Bildschirmfotos) läuft **ohne Geheimnisse**;
  sein Ergebnis gilt als nicht vertrauenswürdig. Der signierende Job läuft **ohne npm**, übernimmt daraus nur
  die gebaute Oberfläche und Bilder (`scripts/import-build.php`: nur erlaubte Dateitypen, kein PHP, keine
  `.htaccess`, keine Punktdateien, keine Links) und erzeugt **alles, was signiert wird, selbst** aus dem eigenen
  Checkout: PHP-Code, `update.json`, `install.php` (dessen SHA-256 steht in der signierten `release.json`),
  `release.json`. `kompanie.json` holt er selbst aus dem Kompanie-Repo.
- `theme.css` aus dem Build kommt in die Download-Seite nur, wenn sie genau den Aufbau hat, den das
  Branding erzeugt (Farbwerte, Schriftliste); die Seite hat zusätzlich eine CSP ohne Skripte.
- **Restrisiko:** Wird der Build-Job kompromittiert (z. B. über ein npm-Paket oder einen Fehler in der
  Bildverarbeitung des Logos), kann manipuliertes JavaScript in die Oberfläche **dieser einen** Kompanie
  gelangen. Der Schlüssel und der PHP-Code der Pakete bleiben unberührt. **Aber:** Im WordPress-Plugin läuft
  die Oberfläche auf derselben Adresse wie `wp-admin`; öffnet ein WordPress-Administrator die App, könnte
  solches JavaScript in seinem Namen handeln (z. B. ein Plugin hochladen) und so doch Server-Code ausführen.
  Der Weg dahin setzt eine Lücke in npm-Paketen oder in der Bildverarbeitung voraus, dazu ein präpariertes Logo
  von jemandem mit Schreibrecht. Abhilfe für später: die allgemeine Oberfläche in einem vertrauenswürdigen Job
  bauen und das Branding nur als geprüfte Daten anwenden.
- **Schreibrecht auf ein Kompanie-Repo** heißt: bestimmen, was neue Installationen dieser Kompanie laden.
  Die Download-Seite (`gh-pages`) und die Release-Dateien liegen dort; beim ersten Herunterladen prüft niemand
  das Plugin-ZIP oder `install.php` (erst danach prüfen die Installationen alles selbst). Deshalb Schreibrecht
  nur für die Organisation und die GitHub App vergeben und per Ruleset `gh-pages` sowie Tags `v*` nur für die
  App freigeben.
- Die **Kompanie-Repos haben keine Geheimnisse** und keinen Zugriff auf das Kern-Repo. Sie bauen nur
  Vorschauen; das Kern-Repo prüft alle 30 Minuten selbst, ob es etwas zu veröffentlichen gibt
  (`kompanien-abgleichen.yml`).
- Alle Actions sind auf Commits festgeschrieben; Checkouts behalten keine Zugangsdaten.
- Installer und Installationen prüfen die Update-Liste: Signatur, Typ, Kompanie (keine Liste einer anderen
  Kompanie); Installationen zusätzlich die laufende Nummer (keine ältere Liste als die zuletzt gesehene).
  Pakete werden per SHA-256 und Größe aus der signierten Liste geprüft.
- **Bekannte Grenze:** Eine Liste hat kein Ablaufdatum. Wer die Auslieferung von GitHub Pages abfangen
  könnte, könnte eine Installation auf dem zuletzt gesehenen Stand festhalten (nicht zurücksetzen).

## Einmalig einrichten

1. **Signaturschlüssel erzeugen** (lokal, nicht in der CI):
   ```sh
   php scripts/keygen.php
   ```
   - Den **geheimen Schlüssel** als Secret `KOMPANIE_SIGNING_KEY` der Environment `veroeffentlichen` im
     Kern-Repo speichern (*Settings → Environments → New environment*; unter *Deployment branches* nur
     `main`). Eine Kopie gehört in den Passwort-Tresor der Organisation, sonst nirgends hin.
   - Den **öffentlichen Schlüssel** (Zeile `'…' => '…'`) in `packages/core/src/Update/TrustedKeys.php`
     eintragen. Ohne diesen Eintrag verweigert `package.php --require-signature` das Signieren.
2. **GitHub App** der Organisation, installiert für alle Repos, mit den Rechten *Contents*, *Pages*,
   *Pull requests*, *Administration* (Repos anlegen, Topics) und *Workflows* (die Vorlage enthält
   `.github/workflows/build.yml`; ob das Anlegen aus der Vorlage dieses Recht braucht, ist nicht geprüft)
   jeweils Lesen und Schreiben: App-ID als
   Variable `KOMPANIE_APP_ID`, privaten Schlüssel als Secret `KOMPANIE_APP_KEY` derselben Environment.
3. **Repo `Kompanie-App-Vorlage`** mit dem Inhalt von [`vorlage/`](../vorlage) anlegen und als
   *Template repository* markieren.
4. Nach dem ersten Zusammenführen in `main`: Tag **`v1`** setzen (die Kompanie-Repos rufen die Vorschau
   über `preview-kompanie.yml@v1` auf).
5. Im Kern-Repo die Labels `neue-kompanie` und `kompanie-aendern` anlegen (die Formulare setzen sie).
6. Optional als **Variablen des Kern-Repos** (nicht der Environment, sonst sieht sie der Abgleich nicht;
   gleichnamige Variablen der Environment hätten beim Veröffentlichen Vorrang, also dort keine anlegen):
   `KOMPANIE_STATUS_URL` (Empfang der Statusmeldungen im Cockpit, https, siehe [STATUSMELDUNGEN.md](STATUSMELDUNGEN.md))
   und `KOMPANIE_SUPPORT` (E-Mail-Adresse oder https-Adresse für Hilfe; erscheint in der App und im Installer).
   Eine Änderung daran (wie an `TrustedKeys`) ergibt beim nächsten Abgleich für jede Kompanie eine neue
   Version: Die Prüfsumme der Paket-Konfiguration (`configHash`) gehört zum veröffentlichten Stand.
   Ausnahme: Ist die neueste Version einer Kompanie zurückgezogen, wird derselbe Stand nicht neu
   veröffentlicht; dann braucht es eine Korrektur (neuer Commit im Kern- oder Kompanie-Repo).

## Neue Kompanie, Kompanie ändern

Im Kern-Repo *Issues → New issue*:

- **„Neue Kompanie“**: Name, Repo-Name, Farben, Texte, Logo (ins Feld ziehen). Welche Bereiche die App hat,
  wählt der Admin der Kompanie bei der Ersteinrichtung in der App. Der Workflow
  [`kompanie-formular.yml`](../.github/workflows/kompanie-formular.yml) prüft die Angaben (Schema, Kontrast,
  Logo nur von GitHub, höchstens 5 MB, PNG/JPEG/WebP/SVG), baut eine Vorschau, legt das Repo aus der Vorlage
  an (öffentlich, Topic `kompanie-app`) und veröffentlicht die erste Version. Ergebnis und Link zur
  Download-Seite stehen danach als Kommentar im Issue.
- **„Kompanie ändern“**: nur die zu ändernden Felder ausfüllen (`-` entfernt einen Wert). Der Workflow öffnet
  im Repo der Kompanie einen Pull Request mit Vorschau; nach dem Zusammenführen wird veröffentlicht.
  Bereiche schaltet der Admin der Kompanie in der App (*Einstellungen → Bereiche*), nicht `kompanie.json`.
- Fehler stehen als Kommentar im Issue; nach dem Bearbeiten des Issues läuft die Prüfung erneut.
- Nur wer Schreibrecht auf das Kern-Repo hat, kann die Formulare auslösen: Sowohl wer das Issue angelegt hat
  als auch wer es zuletzt bearbeitet hat, braucht Schreibrecht. Der Text des Issues wird in einem Job ohne
  Geheimnisse verarbeitet. Nach Erfolg wird das Issue geschlossen; für weitere Änderungen ein neues Formular.
- Bricht das Anlegen einer neuen Kompanie ab, setzt das erneute Bearbeiten des (offenen) Issues fort.

## Versionen

| | Beispiel | Woher |
|---|---|---|
| Kern | `1.4.0` | Datei [`VERSION`](../VERSION), Tag `v1.4.0` |
| Kompanie | `1.4.0.27` | Kern-Version plus fortlaufende Nummer je Kompanie (aus deren Update-Liste) |
| geprüfter Kern | `1.4.0 3f2a…` | Datei [`STABLE_CORE`](../STABLE_CORE): Kern-Version und `coreCode` aus der `release.json` der auf der Demo geprüften Version |

`coreCode` ist eine Prüfsumme über alle versionierten Dateien, die in die Pakete eingehen: Kern, Adapter,
Installer, Oberfläche, Branding, Build- und Paket-Skripte, `package.json`/`package-lock.json`; ohne Daten der
Kompanie, Tests und Doku (`php scripts/package.php --code-hash`, braucht einen Git-Arbeitsbaum).
`STABLE_CORE` gilt nur, wenn beides passt: Ein Commit nach dem Test, der den Code ändert, landet also
wieder zuerst in `early`.

Innerhalb einer Hauptversion (`1.x`) ändern Migrationen nur additiv, damit Zurückrollen ohne Datenverlust geht.
Veröffentlichte Versionen werden nie ersetzt; neue müssen neuer sein als alle bisherigen.

## Branches: develop und main

- **develop**: Alle Änderungen kommen per Pull Request nach `develop`. Jeder Merge startet „Kompanien
  abgleichen“ auf `develop`: Alle Kompanien bekommen eine neue Version mit dem Kern von `develop` im Kanal
  `early` (Vorabversion). Installationen mit dem Schalter **Vorabversionen erhalten** (Einstellungen → Zustand und
  Updates) spielen sie ein; dort wird getestet.
- **main**: Ein Release ist ein Pull Request `develop → main` (als Merge-Commit, nicht zusammengefasst). Jeder
  Push auf `main` startet „Kernversion freigeben“: Die getestete early-Version mit genau diesem Programmcode wird
  bei allen Kompanien `stable`, der Code kommt in `STABLE_CORE`, und Kompanien ohne Version mit diesem Code
  bekommen sie direkt als `stable` gebaut.
- Nach einem Release `main` zurück nach `develop` mergen (nimmt den `STABLE_CORE`-Eintrag mit).
- Der Zeitplan von „Kompanien abgleichen“ läuft auf `main`: Änderungen eines Kompanie-Repos (Logo, Farben) kommen
  mit dem freigegebenen Kern, also direkt als `stable`.
- „Kompanien abgleichen“ vergleicht je Programmcode mit dessen neuester Version (`find-pending.sh`,
  `updates.php --find`): Versionen von `develop` und `main` stoßen sich so nicht gegenseitig neu an.
- Die Environment `veroeffentlichen` muss Deployments von `main` **und** `develop` erlauben
  (Settings → Environments → veroeffentlichen → Deployment branches).

## Einen Kern veröffentlichen

1. `VERSION` erhöhen (Pull Request nach `develop`). Für das Release Tags setzen:
   `git tag v1.4.0 && git tag -f v1 && git push origin v1.4.0 && git push -f origin v1`.
2. Jeder Merge nach `develop` baut für jede Kompanie eine neue Version im Kanal `early`.
3. Auf einer Installation mit **Vorabversionen erhalten** testen.
4. Release: Pull Request `develop → main` zusammenführen. „Kernversion freigeben“ läuft dann von selbst (siehe oben).
   Als Notfall-Knopf: *Actions → Kernversion freigeben → Run workflow* (gibt den Stand von `main` frei). Lässt der
   Branch-Schutz das Eintragen von `STABLE_CORE` nicht zu, meldet die Action es als Warnung; dann per Pull Request.
   Einzelne Kompanie oder bestimmte Version: *Actions → Versionen steuern* (`promote`, `withdraw`,
   `promote-checked` mit der Datei `STABLE_CORE`).

## Was beim Veröffentlichen passiert

[`kompanien-abgleichen.yml`](../.github/workflows/kompanien-abgleichen.yml) findet alle öffentlichen Repos
der Organisation mit dem Topic `kompanie-app` (nicht archiviert), deren Stand (Commit auf `main` und
`coreCode`) nicht der neuesten Version ihrer Update-Liste entspricht (`scripts/find-pending.sh`; ein Revert
auf einen früheren Stand ist also ein neuer Stand), und ruft für jedes
[`publish-kompanie.yml`](../.github/workflows/publish-kompanie.yml) auf (auch einzeln von Hand startbar):

1. **build** (ohne Geheimnisse): Branding aus `kompanie.json` und Logo, App, Bildschirmfotos mit
   Beispieldaten (`scripts/screenshots.sh`).
2. **publish** (Environment `veroeffentlichen`, ohne npm): Build-Ergebnis prüfen und übernehmen
   (`scripts/import-build.php`), dann `scripts/publish-release.sh`: nächste Versionsnummer, Pakete,
   `install.php` und signierte `release.json` (`scripts/package.php`), GitHub-Release `v<Version>` im
   Kompanie-Repo mit genau diesen vier Dateien, signierte Update-Liste `updates.json` und Download-Seite auf
   dessen Branch `gh-pages`.

Kanal: `stable`, wenn der Programmcode schon geprüft ist (`STABLE_CORE`) oder sich nur das Aussehen ändert
(gleiche Kern-Version und gleicher `coreCode` wie die freigegebene Version); sonst `early`, als Vorab-Release.
Kern-Versionen mit einem zurückgezogenen Release gehen nie mehr direkt nach `stable`.
Das Release entsteht als **Entwurf** und wird erst öffentlich, wenn die Update-Liste es enthält. Ein erneuter
Lauf veröffentlicht nichts doppelt; bricht ein Lauf ab, ersetzt der nächste den Entwurf oder macht ihn
öffentlich. Veröffentlichte Releases werden nie geändert oder gelöscht: Gibt es eines, das nicht in der Liste
steht, oder fehlt die Liste, obwohl es Releases gibt, bricht der Lauf ab (siehe „Störungen“).

Pull Requests in einem Kompanie-Repo bauen nur eine Vorschau (`preview-kompanie.yml`): Pakete
(unsigniert) und Bildschirmfotos hängen als Ergebnis am Lauf.

## Zurückziehen

Im Kern-Repo *Actions → Versionen steuern → Run workflow*: Kompanie-Repo, Aktion `withdraw`, Version.
`stable` fällt auf die letzte schon einmal freigegebene Version derselben Hauptversion zurück;
Installationen auf der zurückgezogenen Version spielen sie automatisch ein (außer dort, wo automatische
Updates gesperrt sind, z. B. in WordPress per `AUTOMATIC_UPDATER_DISABLED`; dann zeigt die Statusseite den
Wechsel an). **Gibt es keine solche Version**
(z. B. die erste Version einer neuen Hauptversion), bleiben die Installationen auf der zurückgezogenen
Version; ihre Statusseite zeigt das rot an. Dann eine korrigierte Version veröffentlichen und freigeben.

## Störungen

- **`gh-pages` oder `updates.json` gelöscht:** Aus dem Git-Verlauf wiederherstellen (z. B. eine lokale Kopie
  zurückpushen). Eine neue Liste wird bewusst nicht angelegt, solange es Releases gibt: Sie begänne mit
  niedrigeren Nummern als die installierten Versionen.
- **Veröffentlichtes Release ohne Listeneintrag:** von Hand prüfen (Dateien gegen eine Sicherung), dann
  Release **und Tag** `v<Version>` löschen (das Tag braucht wegen des Rulesets Ausnahmerechte); der nächste
  Lauf veröffentlicht unter derselben Nummer neu.
- **Entwurf eines abgebrochenen Laufs:** nichts zu tun; der nächste Lauf ersetzt ihn oder veröffentlicht ihn
  (der Abgleich erkennt auch einen Listeneintrag, dessen Release noch nicht öffentlich ist). Freigeben lässt
  sich ein Entwurf nicht.
- **Formel von `coreCode` geändert:** Dann entspricht kein Listeneintrag mehr dem aktuellen Stand, und jede
  Kompanie wird einmal neu veröffentlicht (zuerst `early`). `STABLE_CORE` mit der neuen Prüfsumme eintragen.
- **Kompanie-Repo nie umbenennen:** Name und Adresse der Update-Liste stehen in jeder Installation.
- **Zeitplan steht still:** Bei öffentlichen Repos schaltet GitHub „Kompanien abgleichen“ nach 60 Tagen ohne
  Aktivität im Kern-Repo ab. Dann unter *Actions* wieder einschalten; spätestens bei jedem Kern-Release prüfen,
  ob der letzte Lauf aktuell ist.

## Schlüsselwechsel

Neuen Schlüssel erzeugen, öffentlichen Schlüssel **zusätzlich** in `TrustedKeys` eintragen, als Kern-Version
ausliefern und warten, bis alle Installationen sie haben (Statusseite/Statusmeldungen). Erst dann das Secret
austauschen. Den alten Eintrag entfernen, wenn keine Installation mehr eine ältere Version hat.

## Von Hand (lokal)

```sh
KOMPANIE_CONFIG=pfad/kompanie.json KOMPANIE_ASSETS=pfad/assets scripts/build-frontend.sh
php scripts/package.php --config=pfad/kompanie.json --version=0.1.0.1 --out=dist/release   # unsigniert
KOMPANIE_CONFIG=pfad/kompanie.json scripts/screenshots.sh dist/screenshots
php scripts/download-page.php --out=dist/site --screenshots=dist/screenshots              # Vorschau ohne Downloads
```
