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
- **Restrisiko:** Wird der Build-Job kompromittiert (z. B. über ein npm-Paket oder einen Fehler in der
  Bildverarbeitung des Logos), kann manipuliertes JavaScript in die Oberfläche **dieser einen** Kompanie
  gelangen. Server-Code und Schlüssel bleiben unberührt.
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
2. **GitHub App** der Organisation mit Schreibrecht auf *Contents* der Kompanie-Repos: App-ID als Variable
   `KOMPANIE_APP_ID`, privaten Schlüssel als Secret `KOMPANIE_APP_KEY` derselben Environment.
3. **Repo `Kompanie-App-Vorlage`** mit dem Inhalt von [`vorlage/`](../vorlage) anlegen und als
   *Template repository* markieren.
4. Nach dem ersten Zusammenführen in `main`: Tag **`v1`** setzen (die Kompanie-Repos rufen die Vorschau
   über `preview-kompanie.yml@v1` auf).

## Versionen

| | Beispiel | Woher |
|---|---|---|
| Kern | `1.4.0` | Datei [`VERSION`](../VERSION), Tag `v1.4.0` |
| Kompanie | `1.4.0.27` | Kern-Version plus fortlaufende Nummer je Kompanie (aus deren Update-Liste) |
| geprüfter Kern | `1.4.0 3f2a…` | Datei [`STABLE_CORE`](../STABLE_CORE): Kern-Version und `coreCode` aus der `release.json` der auf der Demo geprüften Version |

`coreCode` ist eine Prüfsumme über alle Programmdateien (Kern, Adapter, Installer), ohne Oberfläche und
Doku (`php scripts/package.php --code-hash`). `STABLE_CORE` gilt nur, wenn beides passt: Ein Commit nach
dem Test, der den Code ändert, landet also wieder zuerst in `early`.

Innerhalb einer Hauptversion (`1.x`) ändern Migrationen nur additiv, damit Zurückrollen ohne Datenverlust geht.
Veröffentlichte Versionen werden nie ersetzt; neue müssen neuer sein als alle bisherigen.

## Einen Kern veröffentlichen

1. `VERSION` erhöhen, Pull Request, CI grün, zusammenführen.
2. Tags setzen: `git tag v1.4.0 && git tag -f v1 && git push origin v1.4.0 && git push -f origin v1`.
3. Innerhalb von 30 Minuten baut „Kompanien abgleichen“ für jede Kompanie eine neue Version (oder sofort:
   *Actions → Kompanien abgleichen → Run workflow*). Jede Änderung am Programmcode auf `main` führt so zu
   neuen Versionen für alle Kompanien, zuerst im Kanal `early`.
4. Nach dem Test der Demo-Installation Kern-Version und `coreCode` in `STABLE_CORE` eintragen (Pull Request)
   und je Kompanie freigeben: *Actions → Versionen steuern*, `promote`.

## Was beim Veröffentlichen passiert

[`kompanien-abgleichen.yml`](../.github/workflows/kompanien-abgleichen.yml) findet alle öffentlichen Repos
der Organisation mit dem Topic `kompanie-app`, deren Stand (Commit auf `main` und `coreCode`) noch nicht in
ihrer Update-Liste steht (`scripts/find-pending.sh`), und ruft für jedes
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
Ein erneuter Lauf veröffentlicht nichts doppelt; bricht ein Lauf nach dem Anlegen des Releases ab, ergänzt
der nächste dessen Dateien unter derselben Nummer (gelöscht wird nie).

Pull Requests in einem Kompanie-Repo bauen nur eine Vorschau (`preview-kompanie.yml`): Pakete
(unsigniert) und Bildschirmfotos hängen als Ergebnis am Lauf.

## Zurückziehen

Im Kern-Repo *Actions → Versionen steuern → Run workflow*: Kompanie-Repo, Aktion `withdraw`, Version.
`stable` fällt auf die letzte schon einmal freigegebene Version derselben Hauptversion zurück;
Installationen auf der zurückgezogenen Version spielen sie automatisch ein. **Gibt es keine solche Version**
(z. B. die erste Version einer neuen Hauptversion), bleiben die Installationen auf der zurückgezogenen
Version; ihre Statusseite zeigt das rot an. Dann eine korrigierte Version veröffentlichen und freigeben.

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
