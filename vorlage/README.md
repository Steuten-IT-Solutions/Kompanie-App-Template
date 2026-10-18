# App der Kompanie

Dieses Repo beschreibt **eine** Kompanie. Daraus entstehen automatisch:

- das **WordPress-Plugin** und die **eigenständige Version** (signiert),
- die **Download-Seite** mit Anleitung (GitHub Pages dieses Repos),
- **Bildschirmfotos** der App in hell und dunkel.

Der Programmcode liegt im gemeinsamen Kern ([Kompanie-App-Template](https://github.com/Steuten-IT-Solutions/Kompanie-App-Template)).
Hier wird nur eingetragen, was die Kompanie ausmacht.

## Was hier steht

| Datei | Inhalt |
|---|---|
| `kompanie.json` | Name, Farben, Begrüßung, Bezeichnungen, Bereiche – Beschreibung aller Felder im [Schema](https://github.com/Steuten-IT-Solutions/Kompanie-App-Template/blob/main/config/kompanie.schema.json). Pflicht sind nur `name` und `colors.primary`. |
| `assets/logo.png` | Logo (oder `.svg`, `.jpg`, `.jpeg`, `.webp`) |
| `legal/` | geplant: `impressum.md`, `datenschutz.md` (wartet auf die rechtliche Prüfung; wird noch nicht verwendet) |

## Ändern

Direkt hier im Repo (ein Formular „Kompanie ändern“ im Kern-Repo ist geplant):
Eine Änderung an `kompanie.json` oder am Logo startet im Pull Request eine Vorschau: Bildschirmfotos und
Pakete liegen als Ergebnis „vorschau“ am Workflow-Lauf (*Checks → App bauen → Summary*). Nach dem Zusammenführen
veröffentlicht das Kern-Repo innerhalb von 30 Minuten eine neue Version, die die Installationen selbst holen.

## Versionen

- Neue Versionen des Kerns laufen zuerst auf unserer Demo-Installation (Kanal `early`) und gehen danach an alle (`stable`).
- Ändert sich nur das Aussehen, geht die neue Version direkt an alle.
- Freigeben und Zurückziehen geschieht zentral im Kern-Repo (*Actions → Versionen steuern*).
  Installationen auf einer zurückgezogenen Version spielen automatisch die zuletzt freigegebene ein
  (sofern es eine gibt; sonst zeigt ihre Statusseite das an).

## Sicherheit

Dieses Repo enthält keine Geheimnisse und hat keinen Zugriff auf das Kern-Repo oder den Signaturschlüssel.
Das Kern-Repo prüft selbst regelmäßig, ob sich `main` hier geändert hat, und baut, prüft und signiert dann.
Das Repo muss öffentlich sein und das Topic `kompanie-app` tragen.

## Einmalig einrichten (später automatisch über den geplanten Workflow „Neue Kompanie“)

- *Settings → Pages:* Quelle „Deploy from a branch“, Branch `gh-pages` (nach der ersten Veröffentlichung).
- Topic `kompanie-app` setzen (damit „Kompanien abgleichen“ im Kern-Repo das Repo findet).
