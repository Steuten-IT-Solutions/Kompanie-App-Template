# Kompanie-App

Gemeinsamer Kern der Kompanie-App: eine installierbare Web-App (PWA) für Kompanien und Vereine mit
- Terminen (öffentlich und Kalender für Mitglieder),
- News,
- Galerie aus Google Drive,
- Punkten und Strafen der Mitglieder.

Entstanden aus der App der Marinekompanie 1973 Lank.

Die App läuft auf normalem Webspace mit PHP und MySQL, wahlweise
- als **WordPress-Plugin** oder
- als **eigenständige Version** ohne WordPress.

Jede Kompanie hat ein eigenes, kleines Repo mit Name, Farben, Logo und den gewünschten Bereichen. Daraus baut GitHub Actions die fertige App für genau diese Kompanie.

> **Stand:** Phase 0. Architektur, API-Spezifikation, Konfigurationsschema und die Schnittstellen des Kerns sind festgelegt. Die eigentliche Logik wird in Phase 1 aus der Lank-App übernommen.

## Dokumentation

- [Architektur und Fahrplan](docs/ARCHITEKTUR.md)
- [API-Spezifikation](api/openapi.yaml)
- [Kompanie-Konfiguration: Schema](config/kompanie.schema.json) und [Beispiel](config/kompanie.example.json)

## Aufbau

```
api/                 API-Vertrag (OpenAPI)
config/              Schema und Beispiel für kompanie.json
packages/core/       PHP-Kern ohne WordPress
packages/frontend/   React-PWA (folgt in Phase 3)
adapters/wordpress/  WordPress-Plugin (folgt in Phase 2)
adapters/standalone/ eigenständige Version (folgt in Phase 4)
kompanie-vorlage/    Gerüst für ein Kompanie-Repo (folgt in Phase 5)
scripts/             Prüf- und Build-Skripte
```

## Prüfungen

```bash
scripts/check-core-boundary.sh   # Kern enthält keine WordPress-Aufrufe
```

GitHub Actions prüft bei jedem Push zusätzlich die PHP-Syntax, die OpenAPI-Spezifikation und das Konfigurationsschema.
