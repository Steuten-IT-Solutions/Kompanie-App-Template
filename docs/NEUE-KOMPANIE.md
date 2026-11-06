# Neue Kompanie anlegen

Schritt für Schritt: von den Angaben einer neuen Kompanie (oder eines Vereins) bis zur laufenden App.
Hintergrund und technische Einzelheiten: [Veröffentlichen, Abschnitt „Neue Kompanie, Kompanie ändern“](RELEASE.md#neue-kompanie-kompanie-ändern).

## 1. Angaben sammeln

| Angabe | Pflicht | Regeln |
|---|---|---|
| Name | ja | So erscheint die Kompanie in der App, höchstens 80 Zeichen. |
| Name des Repos | ja | Nur Kleinbuchstaben a–z, Ziffern und einzelne Bindestriche, höchstens 60 Zeichen, z. B. `musterkompanie-beispielstadt`. Wird Teil der Adresse der Download-Seite. **Darf nie umbenannt werden** (Name und Adresse der Update-Liste stehen in jeder Installation). |
| Hauptfarbe | ja | Farbcode `#rrggbb`, z. B. `#2e6b3f`. |
| Logo | ja | PNG, JPG, SVG oder WebP, beliebiges Seitenverhältnis, möglichst mindestens 512 Pixel, höchstens 5 MB. |
| Kurzname | nein | Unter dem App-Symbol auf dem Handy, höchstens 30 Zeichen, besser unter 12. Leer = Name. |
| Akzentfarbe | nein | `#rrggbb`. Leer = passend zur Hauptfarbe. |
| Begrüßung | nein | Muss `{name}` enthalten. Leer = „Hallo, {name}“. |
| Willkommenstext für Gäste | nein | Höchstens 300 Zeichen. |
| Bezeichnung für Mitglieder | nein | Leer = „Mitglied“. Weitere Rollen legt der Admin der Kompanie in der App an. |
| Bezeichnung für Strafen | nein | Leer = „Strafen“. |

Welche Bereiche die App hat (Termine, Galerie, Punkte …), gehört nicht dazu: Das wählt der Admin der Kompanie
in der App, bei der Ersteinrichtung und später unter *Einstellungen → Bereiche*.

Außerdem klären: Hat die Kompanie eine **WordPress-Webseite** (dann Plugin) oder nur **Webspace mit PHP ab 8.1**
(dann eigenständige Version)?

Keine Zugangsdaten in das Formular oder in das Repo der Kompanie schreiben.

## 2. Formular „Neue Kompanie“ ausfüllen

1. Im Kern-Repo [*Issues → New issue*](https://github.com/Steuten-IT-Solutions/Kompanie-App-Template/issues/new/choose)
   öffnen und **„Neue Kompanie“** wählen.
2. Die Angaben aus Schritt 1 eintragen. Das Logo **in das Feld „Logo“ ziehen** (ein Link auf eine andere
   Webseite reicht nicht; der Workflow lädt nur Bilder, die GitHub als Anhang gespeichert hat).
3. Absenden.

Auslösen kann das Formular nur, wer Schreibrecht auf das Kern-Repo hat (sowohl wer das Issue anlegt als auch wer
es zuletzt bearbeitet).

## 3. Ergebnis abwarten

Der Workflow [`kompanie-formular.yml`](../.github/workflows/kompanie-formular.yml) prüft die Angaben, baut eine
Vorschau mit Bildschirmfotos, legt das Repo aus der Vorlage `Kompanie-App-Vorlage` an (öffentlich, Topic
`kompanie-app`), schreibt `kompanie.json` und das Logo hinein und veröffentlicht die erste Version.

- **Erfolg:** Im Issue steht ein Kommentar „Fertig.“ mit dem Link zum Repo und zur Download-Seite
  `https://steuten-it-solutions.github.io/<repo>/` (erscheint nach wenigen Minuten). Das Issue wird geschlossen.
- **Fehler:** Der Kommentar nennt, was nicht passt. Das Issue bearbeiten und speichern; die Prüfung läuft erneut.
  Brach das Anlegen mittendrin ab, setzt das erneute Speichern des offenen Issues fort.
- **Kanal:** Die erste Version liegt direkt in `stable`, wenn der Kern schon geprüft ist (`STABLE_CORE`), sonst
  zuerst in `early`.

Die Vorschau (Bildschirmfotos) liegt als Ergebnis „vorschau“ am Workflow-Lauf, der im Kommentar verlinkt ist.

## 4. Installieren (macht die Kompanie)

Den Link zur Download-Seite bekommt die Kompanie. Die Seite verlinkt die `stable`-Version.

- **Mit WordPress** (WordPress ab 6.2, PHP ab 8.1, Konto als WordPress-Administrator): Plugin-Datei herunterladen
  (nicht entpacken), in WordPress unter *Plugins → Neues Plugin hinzufügen → Plugin hochladen* installieren und
  aktivieren. Die App liegt dann unter `eure-webseite.de/app/`. Handbuch:
  [Installation mit WordPress](handbuch/admin-installation-wordpress.md).
- **Ohne WordPress** (Webspace mit PHP ab 8.1, Dateimanager oder FTP): `install.php` herunterladen, im
  Dateimanager einen Ordner `app` anlegen, die Datei hineinladen und `eure-webseite.de/app/install.php` im
  Browser öffnen. Der Installer prüft den Server, legt den ersten Admin an und wählt die Datenbank selbst
  (SQLite, optional MySQL/MariaDB). Handbuch: [Installation ohne WordPress](handbuch/admin-installation-eigenstaendig.md).

Danach führt der [Einrichtungsassistent](handbuch/admin-einrichtung.md) durch Bereiche, Kalender und Galerie.
Empfohlen: beim Hoster einen Cronjob alle 15 Minuten einrichten (Einzelheiten in den beiden Handbuchseiten).

## 5. Später ändern

Logo, Farben oder Texte ändert das Formular **„Kompanie ändern“** im Kern-Repo: nur die zu ändernden Felder
ausfüllen (`-` entfernt einen Wert). Es öffnet im Repo der Kompanie einen Pull Request mit Vorschau; nach dem
Zusammenführen wird die neue Version veröffentlicht (innerhalb von 30 Minuten). Neue Kern-Versionen bekommt
jede Kompanie automatisch über „Kompanien abgleichen“.

## Noch offen

Die rechtliche Prüfung von Impressum und Datenschutzerklärung war vor der ersten neuen Kompanie geplant
([Offene Punkte, Abschnitt 3](OFFENE-PUNKTE.md#3-rechtstexte-und-datenschutz)).
