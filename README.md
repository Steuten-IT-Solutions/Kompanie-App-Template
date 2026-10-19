# Kompanie-App

Gemeinsamer Kern der Kompanie-App: eine installierbare Web-App (PWA) für Kompanien und Vereine mit
- Terminen (öffentlich und Kalender für Mitglieder),
- News,
- Galerie aus Google Drive,
- Punkten und Strafen der Mitglieder.

Die App ist neutral gestaltet: Jede Kompanie bekommt ihr eigenes Logo, ihre Farben, ihren Namen und ihre Bezeichnungen.

Sie läuft auf normalem Webspace mit PHP, wahlweise
- als **WordPress-Plugin** oder
- als **eigenständige Version** ohne WordPress, mit SQLite (legt der Installer selbst an) oder MySQL/MariaDB.

Jede Kompanie hat in unserer Organisation ein eigenes, öffentliches Repo. Darin stehen nur Name, Logo, Farben und ein paar Texte. Build, Icons, Prüfungen, Releases und Updates auf den Servern laufen automatisch. Die Kompanien brauchen dafür kein technisches Wissen.

> **Stand:** Phasen 1–5 umgesetzt (Kern, WordPress-Plugin, neutrale App, eigenständige Version, Branding,
> signierte Releases, Installer, automatische Updates, Wartung in der App). Offen: Phase 6 (Formulare und
> Statusübersicht für uns) und die Punkte in [OFFENE-PUNKTE.md](docs/OFFENE-PUNKTE.md).

## Dokumentation

- [Architektur, Abläufe und Fahrplan](docs/ARCHITEKTUR.md)
- [Planung der offenen Punkte](docs/OFFENE-PUNKTE.md)
- [Veröffentlichen und Updates](docs/RELEASE.md)
- [Handbuch](docs/handbuch/) für Mitglieder und Admins: in der App hinter „?“ und je Kompanie auf GitHub Pages unter `/handbuch/`
- [Statusmeldungen an das Cockpit (Format 2)](docs/STATUSMELDUNGEN.md)
- [API-Spezifikation](api/openapi.yaml)
- [Kompanie-Konfiguration: Schema](config/kompanie.schema.json) und [Beispiel](config/kompanie.example.json)

## Aufbau

```
api/                  API-Vertrag (OpenAPI)
config/               Schema und Beispiel für kompanie.json
packages/core/        PHP-Kern ohne WordPress
packages/frontend/    React-PWA
packages/branding/    Farben, Icons und Logo aus kompanie.json
adapters/wordpress/   WordPress-Plugin
adapters/standalone/  eigenständige Version
installer/            Web-Installer install.php
scripts/              Prüf-, Build- und Veröffentlichungs-Skripte
vorlage/              Inhalt eines Kompanie-Repos (Vorlage)
tests/                Tests der Adapter, des Release-Ablaufs, des Installers und der Updates
```

## Prüfungen

```bash
scripts/check-core-boundary.sh   # Kern enthält keine WordPress-Aufrufe
scripts/check-neutral.sh         # keine Namen oder Motive einer bestimmten Kompanie
npm run format:check             # Formatierung von TypeScript, JavaScript und CSS (Prettier; npm run format korrigiert)
```

```bash
vendor/bin/phpunit                         # Kern
php tests/standalone/run-tests.php         # eigenständige Version (HTTP)
scripts/e2e-standalone.sh                  # E2E (Playwright), eigenständige Version
tests/release/run-tests.sh                 # Pakete, Signatur, Update-Liste, Veröffentlichen
tests/update/run-tests.sh                  # install.php, Update, Zurückrollen, Zurückziehen, Reparatur
tests/handbuch/run-tests.sh                # Handbuch: Aufbau, Platzhalter, Ausgabe für App und Webseite
```

GitHub Actions führt bei jedem Push alle Prüfungen aus, auch die WordPress-Tests, beide Datenbanken und die
OpenAPI-Spezifikation ([`.github/workflows/ci.yml`](.github/workflows/ci.yml)).
