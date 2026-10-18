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
| `assets/logo.png` | Logo (oder `.jpg`, `.svg`) |
| `legal/` | später: `impressum.md`, `datenschutz.md` (wartet auf die rechtliche Prüfung) |

## Ändern

Am einfachsten über das Formular **„Kompanie ändern“** im Kern-Repo. Wer es direkt hier ändert:
Eine Änderung an `kompanie.json` oder am Logo startet im Pull Request eine Vorschau: Bildschirmfotos und
Pakete liegen als Ergebnis „vorschau“ am Workflow-Lauf (*Checks → App bauen → Summary*). Nach dem Zusammenführen
veröffentlicht das Kern-Repo eine neue Version, die die Installationen selbst holen.

## Versionen

- Neue Versionen des Kerns laufen zuerst auf unserer Demo-Installation (Kanal `early`) und gehen danach an alle (`stable`).
- Ändert sich nur das Aussehen, geht die neue Version direkt an alle.
- Freigeben und Zurückziehen geschieht zentral im Kern-Repo (*Actions → Versionen steuern*).
  Installationen auf einer zurückgezogenen Version spielen automatisch die vorige ein.

## Sicherheit

Dieses Repo enthält keine Geheimnisse und hat keinen Zugriff auf den Signaturschlüssel. Nach einer
Änderung auf `main` stößt es nur die Veröffentlichung im Kern-Repo an; dort wird gebaut, geprüft und signiert.

## Einmalig einrichten (macht der Workflow „Neue Kompanie“)

- *Settings → Pages:* Quelle „Deploy from a branch“, Branch `gh-pages` (nach der ersten Veröffentlichung).
- Topic `kompanie-app` setzen (damit „Alle Kompanien neu bauen“ das Repo findet).
- Das Secret `KOMPANIE_DISPATCH_TOKEN` kommt aus der Organisation.
