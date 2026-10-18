# Veröffentlichen: Kern, Kompanien, Updates

Wie aus einer Änderung am Kern signierte Pakete für jede Kompanie werden und wie Updates verteilt werden.
Hintergrund: [Architektur, Abschnitt 11](ARCHITEKTUR.md#11-updates-und-zurückrollen) und
[Offene Punkte, Abschnitt 1](OFFENE-PUNKTE.md#1-update-verteilung-und-gestaffelte-releases).

## Einmalig einrichten

1. **Signaturschlüssel erzeugen** (lokal, nicht in der CI):
   ```sh
   php scripts/keygen.php
   ```
   - Den **geheimen Schlüssel** als Organisations-Secret `KOMPANIE_SIGNING_KEY` speichern
     (*Organisation → Settings → Secrets and variables → Actions*, Zugriff für alle Kompanie-Repos).
     Nirgends sonst speichern; eine Kopie gehört in den Passwort-Tresor der Organisation.
   - Den **öffentlichen Schlüssel** (Zeile `'…' => '…'`) in `packages/core/src/Update/TrustedKeys.php` eintragen.
     Installer und Updater nehmen nur Pakete an, die mit einem dort eingetragenen Schlüssel signiert sind.
2. **Repo `Kompanie-App-Vorlage`** mit dem Inhalt von [`vorlage/`](../vorlage) anlegen und in den
   Repo-Einstellungen als *Template repository* markieren.
3. Nach dem ersten Zusammenführen in `main`: Tag **`v1`** setzen (siehe unten). Die Kompanie-Repos rufen
   die Workflows des Kerns über `@v1` auf.

## Versionen

| | Beispiel | Woher |
|---|---|---|
| Kern | `1.4.0` | Datei [`VERSION`](../VERSION), Tag `v1.4.0` |
| Kompanie | `1.4.0.27` | Kern-Version plus laufende Build-Nummer des Kompanie-Repos |
| geprüfter Kern | `1.4.0` | Datei [`STABLE_CORE`](../STABLE_CORE): hat den Test der Demo-Installation bestanden |

Innerhalb einer Hauptversion (`1.x`) ändern Migrationen nur additiv, damit Zurückrollen ohne Datenverlust geht.

## Einen Kern veröffentlichen

1. `VERSION` erhöhen, Pull Request, CI grün, zusammenführen.
2. Tags setzen: `git tag v1.4.0 && git tag -f v1 && git push origin v1.4.0 && git push -f origin v1`.
3. „Alle Kompanien neu bauen“ (Phase 6) löst in jedem Kompanie-Repo den Build aus
   (`repository_dispatch`, Typ `kern-release`). Bis dahin: in jedem Kompanie-Repo *Actions → App bauen → Run workflow*.
4. Die neuen Versionen landen im Kanal `early`. Nach dem Test der Demo-Installation die Kern-Version in
   `STABLE_CORE` eintragen und je Kompanie freigeben (*Actions → Versionen steuern*, `promote`).

## Was der Build einer Kompanie macht

[`.github/workflows/build-kompanie.yml`](../.github/workflows/build-kompanie.yml):

1. Branding aus `kompanie.json` und Logo, App bauen (`scripts/build-frontend.sh`)
2. Bildschirmfotos mit Beispieldaten (`scripts/screenshots.sh`)
3. Pakete und signierte `release.json` (`scripts/package.php`):
   `kompanie-app-wordpress.zip` (Plugin), `kompanie-app-standalone.zip` (lädt der Installer)
4. GitHub-Release `v<Version>` mit diesen Dateien
5. Branch `gh-pages`: signierte Update-Liste `updates.json` (`scripts/updates.php`) und Download-Seite
   (`scripts/download-page.php`). Kanal: `stable`, wenn der Kern schon geprüft ist (`STABLE_CORE`) oder sich
   nur das Aussehen ändert; sonst `early`.

In Pull Requests eines Kompanie-Repos wird nur gebaut, nichts veröffentlicht; Pakete und Bildschirmfotos
hängen als Ergebnis am Lauf.

## Zurückziehen

*Actions → Versionen steuern → Run workflow* im Kompanie-Repo, Aktion `withdraw`, Version eintragen.
`stable` und `early` zeigen danach auf die vorige Version; Installationen auf der zurückgezogenen Version
spielen die vorige automatisch ein (Phase 5).

## Schlüsselwechsel

Neuen Schlüssel erzeugen, öffentlichen Schlüssel **zusätzlich** in `TrustedKeys` eintragen, als Kern-Version
ausliefern und warten, bis alle Installationen sie haben (Statusseite/Statusmeldungen). Erst dann das Secret
austauschen. Den alten Eintrag entfernen, wenn keine Installation mehr eine ältere Version hat.

## Von Hand (lokal)

```sh
KOMPANIE_CONFIG=pfad/kompanie.json KOMPANIE_ASSETS=pfad/assets scripts/build-frontend.sh
php scripts/package.php --config=pfad/kompanie.json --version=0.1.0.1 --out=dist   # ohne Schlüssel: unsigniert, nur zum Testen
scripts/screenshots.sh dist/screenshots
php scripts/download-page.php --out=dist/site --screenshots=dist/screenshots       # Vorschau ohne Downloads
```
