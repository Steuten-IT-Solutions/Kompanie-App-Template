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
Eine Änderung an `kompanie.json` oder am Logo startet den Build. Im Pull Request hängen die Bildschirmfotos
als Ergebnis an; nach dem Zusammenführen erscheint eine neue Version, die die Installationen selbst holen.

## Versionen

- Neue Versionen des Kerns laufen zuerst auf unserer Demo-Installation (Kanal `early`) und gehen danach an alle (`stable`).
- Ändert sich nur das Aussehen, geht die neue Version direkt an alle.
- **Zurückziehen:** *Actions → Versionen steuern → Run workflow* mit `withdraw` und der Version.
  Installationen auf dieser Version spielen automatisch die vorige ein.

## Einmalig einrichten (macht der Workflow „Neue Kompanie“)

- *Settings → Pages:* Quelle „Deploy from a branch“, Branch `gh-pages`.
- Topic `kompanie-app` setzen (damit „Alle Kompanien neu bauen“ das Repo findet).
- Das Secret `KOMPANIE_SIGNING_KEY` kommt aus der Organisation.
