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

> **Stand:** Phase 0. Architektur, API-Spezifikation, Konfigurationsschema und die Schnittstellen des Kerns sind festgelegt. Die eigentliche Logik wird ab Phase 1 aus der Lank-App übernommen.

## Dokumentation

- [Architektur, Abläufe und Fahrplan](docs/ARCHITEKTUR.md)
- [Planung der offenen Punkte](docs/OFFENE-PUNKTE.md)
- [API-Spezifikation](api/openapi.yaml)
- [Kompanie-Konfiguration: Schema](config/kompanie.schema.json) und [Beispiel](config/kompanie.example.json)

## Aufbau

```
api/                  API-Vertrag (OpenAPI)
config/               Schema und Beispiel für kompanie.json
packages/core/        PHP-Kern ohne WordPress
packages/frontend/    React-PWA (folgt in Phase 2)
adapters/wordpress/   WordPress-Plugin (folgt in Phase 1)
adapters/standalone/  eigenständige Version (folgt in Phase 3)
installer/            Web-Installer (folgt in Phase 5)
scripts/              Prüf- und Build-Skripte
```

## Prüfungen

```bash
scripts/check-core-boundary.sh   # Kern enthält keine WordPress-Aufrufe
scripts/check-neutral.sh         # keine Namen oder Motive einer bestimmten Kompanie
```

GitHub Actions prüft bei jedem Push zusätzlich die PHP-Syntax, die OpenAPI-Spezifikation und das Konfigurationsschema.
