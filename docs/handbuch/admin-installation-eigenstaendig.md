---
titel: Installation ohne WordPress
reihenfolge: 100
rechte: [manage_settings]
variante: standalone
---
Ihr braucht nur Webspace mit PHP. Eine Datenbank müsst ihr nicht anlegen; der Installer nimmt SQLite, wenn ihr keine MySQL-Datenbank angebt.

1. Auf der Download-Seite der Kompanie `install.php` herunterladen.
2. Im Dateimanager des Hosters (oder per FTP) einen Ordner `app` anlegen und die Datei hineinladen.
3. `eure-webseite.de/app/install.php` öffnen und den Schritten folgen. Am Ende legt ihr das erste Admin-Konto an; der Installer löscht sich danach selbst.

## Cronjob (empfohlen)
Die App erledigt Hintergrundaufgaben (z. B. Galerie, Kalender, Updates) auch ohne Cronjob. Zuverlässiger geht es mit einem Cronjob beim Hoster, der alle 15 Minuten die Cron-Adresse aufruft. Die Adresse steht unter **Einstellungen → Zustand der App**.

Ohne Cronjob erledigt die App fällige Aufgaben nach einem Besuch, ohne den Besucher warten zu lassen. Klappt das auf eurem Webspace nicht, zeigt **Zustand der App** das rot an und nennt die Adresse für den Cronjob.

## Server mit nginx
Die meisten Hoster nutzen den Webserver Apache oder LiteSpeed; dann richtet die App alles selbst ein (über die Dateien `.htaccess`). Läuft euer Webspace mit **nginx**, wertet der Server diese Dateien nicht aus. Die App läuft trotzdem, aber bittet den Hoster, Folgendes einzustellen:

- **Schutz der Daten:** Die Ordner `data`, `src`, `core` und `bin` im App-Ordner dürfen von außen nicht abrufbar sein. Der Installer prüft das und legt die Daten sonst außerhalb des Web-Verzeichnisses ab.
- **Schneller laden:** Dateien unter `app/assets/` ein Jahr im Browser zwischenspeichern (`Cache-Control: public, max-age=31536000, immutable`; ihr Name ändert sich mit jeder neuen Version), Symbole und Manifest unter `app/` einen Tag. Text-Dateien (HTML, CSS, JavaScript, JSON, SVG, Manifest) komprimiert ausliefern (gzip).

Ohne diese Einstellungen funktioniert die App, lädt beim ersten Öffnen aber langsamer.
