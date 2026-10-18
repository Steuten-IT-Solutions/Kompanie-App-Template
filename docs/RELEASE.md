# Veröffentlichen: Kern, Kompanien, Updates

Wie aus einer Änderung signierte Pakete für jede Kompanie werden und wie Updates verteilt werden.
Hintergrund: [Architektur, Abschnitt 11](ARCHITEKTUR.md#11-updates-und-zurückrollen) und
[Offene Punkte, Abschnitt 1](OFFENE-PUNKTE.md#1-update-verteilung-und-gestaffelte-releases).

## Sicherheitsprinzip

Wer den Signaturschlüssel hat, kann Code auf **allen** Installationen ausführen. Deshalb:

- Der Schlüssel liegt **nur im Kern-Repo**, als Secret der GitHub-Environment `veroeffentlichen`
  (Zugriff nur für den Branch `main`, auf Wunsch mit Freigabe durch Prüfer).
- Signiert wird in einem eigenen Job **ohne npm** und ohne fremden Build-Code: Er prüft die Pakete gegen
  `release.json`, signiert und veröffentlicht (`publish-kompanie.yml`, `scripts/sign-release.php`).
- Die **Kompanie-Repos haben keinen Zugriff** auf den Schlüssel. Sie bauen nur Vorschauen und stoßen die
  Veröffentlichung im Kern-Repo an (Secret `KOMPANIE_DISPATCH_TOKEN`, darf nur Workflows starten).
- Alle Actions sind auf Commits festgeschrieben; Checkouts behalten keine Zugangsdaten.
- Installationen prüfen die Update-Liste: Signatur, Kompanie (keine Liste einer anderen Kompanie) und
  laufende Nummer (keine ältere Liste), die Pakete per SHA-256 aus der signierten Liste.

## Einmalig einrichten

1. **Signaturschlüssel erzeugen** (lokal, nicht in der CI):
   ```sh
   php scripts/keygen.php
   ```
   - Den **geheimen Schlüssel** als Secret `KOMPANIE_SIGNING_KEY` der Environment `veroeffentlichen` im
     Kern-Repo speichern (*Settings → Environments → New environment*; unter *Deployment branches* nur
     `main`). Eine Kopie gehört in den Passwort-Tresor der Organisation, sonst nirgends hin.
   - Den **öffentlichen Schlüssel** (Zeile `'…' => '…'`) in `packages/core/src/Update/TrustedKeys.php`
     eintragen. Ohne diesen Eintrag verweigert `sign-release.php` das Signieren.
2. **GitHub App** der Organisation mit Schreibrecht auf *Contents* der Kompanie-Repos: App-ID als Variable
   `KOMPANIE_APP_ID`, privaten Schlüssel als Secret `KOMPANIE_APP_KEY` derselben Environment.
3. **`KOMPANIE_DISPATCH_TOKEN`** als Organisations-Secret für die Kompanie-Repos: ein Fine-grained Token
   (oder App-Token) nur mit *Actions: write* auf das Kern-Repo.
4. **Repo `Kompanie-App-Vorlage`** mit dem Inhalt von [`vorlage/`](../vorlage) anlegen und als
   *Template repository* markieren.
5. Nach dem ersten Zusammenführen in `main`: Tag **`v1`** setzen (siehe unten).

## Versionen

| | Beispiel | Woher |
|---|---|---|
| Kern | `1.4.0` | Datei [`VERSION`](../VERSION), Tag `v1.4.0` |
| Kompanie | `1.4.0.27` | Kern-Version plus laufende Nummer des Workflows „Kompanie veröffentlichen“ |
| geprüfter Kern | `1.4.0` | Datei [`STABLE_CORE`](../STABLE_CORE): hat den Test der Demo-Installation bestanden |

Innerhalb einer Hauptversion (`1.x`) ändern Migrationen nur additiv, damit Zurückrollen ohne Datenverlust geht.
Veröffentlichte Versionen werden nie ersetzt; neue müssen neuer sein als alle bisherigen.

## Einen Kern veröffentlichen

1. `VERSION` erhöhen, Pull Request, CI grün, zusammenführen.
2. Tags setzen: `git tag v1.4.0 && git tag -f v1 && git push origin v1.4.0 && git push -f origin v1`.
3. Je Kompanie *Actions → Kompanie veröffentlichen → Run workflow* (Phase 6: „Alle Kompanien neu bauen“).
4. Die neuen Versionen landen im Kanal `early`. Nach dem Test der Demo-Installation die Kern-Version in
   `STABLE_CORE` eintragen (Pull Request) und je Kompanie freigeben: *Actions → Versionen steuern*, `promote`.

## Was beim Veröffentlichen passiert

[`.github/workflows/publish-kompanie.yml`](../.github/workflows/publish-kompanie.yml):

1. **bauen** (ohne Geheimnisse): Branding aus `kompanie.json` und Logo, App, Bildschirmfotos mit
   Beispieldaten (`scripts/screenshots.sh`), Pakete und `release.json` (`scripts/package.php`)
2. **veröffentlichen** (Environment `veroeffentlichen`): `scripts/sign-release.php`, dann
   `scripts/publish-release.sh`: GitHub-Release `v<Version>` im Kompanie-Repo (Plugin-ZIP, ZIP der
   eigenständigen Version, `install.php`, signierte `release.json`), signierte Update-Liste `updates.json`
   und Download-Seite auf dessen Branch `gh-pages`.

Kanal: `stable`, wenn der Kern schon geprüft ist (`STABLE_CORE`) oder sich nur das Aussehen ändert (gleiche
Kern-Version und gleicher Kern-Commit wie die freigegebene Version); sonst `early`, als Vorab-Release.
Kern-Versionen mit einem zurückgezogenen Release gehen nie mehr direkt nach `stable`.
Ein erneuter Lauf (Re-run) veröffentlicht nichts doppelt.

Pull Requests in einem Kompanie-Repo bauen nur eine Vorschau (`preview-kompanie.yml`): Pakete
(unsigniert) und Bildschirmfotos hängen als Ergebnis am Lauf.

## Zurückziehen

Im Kern-Repo *Actions → Versionen steuern → Run workflow*: Kompanie-Repo, Aktion `withdraw`, Version.
`stable` fällt auf die letzte schon einmal freigegebene Version zurück; Installationen auf der
zurückgezogenen Version spielen sie automatisch ein.

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
