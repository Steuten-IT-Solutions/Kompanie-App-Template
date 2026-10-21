---
titel: Installation ohne WordPress
reihenfolge: 100
rechte: [manage_settings]
variante: standalone
---
Ihr braucht nur Webspace mit PHP (ab Version 8.1). Eine Datenbank müsst ihr nicht anlegen; der Installer nimmt eine SQLite-Datei, wenn ihr keine MySQL-Datenbank angebt.

1. Auf der Download-Seite der Kompanie `install.php` herunterladen.
2. Im Dateimanager des Hosters (oder per FTP) einen Ordner `app` anlegen und die Datei hineinladen.
3. `eure-webseite.de/app/install.php` im Browser öffnen. Der Installer prüft zuerst den Server und sagt, was fehlt.
4. Unter **Einrichten** die **Adresse der App** prüfen und unter **Erster Admin** Name, Benutzername, E-Mail (optional) und ein Passwort mit mindestens 10 Zeichen eintragen. Unter **Datenbank** steht „Automatisch (SQLite-Datei, empfohlen)“, falls der Server das kann; nur wenn ihr eine MySQL- oder MariaDB-Datenbank nutzen wollt, tragt ihr dort deren Zugangsdaten ein.
5. **App installieren** klicken. Der Installer lädt die App, richtet sie ein und löscht sich danach selbst. Klappt das nicht, sagt er es; dann `install.php` im Dateimanager löschen.
6. Mit dem eben angelegten Admin-Konto anmelden. Die App führt dich durch die [Ersteinrichtung](seite:admin-einrichtung).

Läuft auf dem Webspace schon WordPress, richtet der Installer nichts ein und empfiehlt das Plugin. Ladet ihr `install.php` später erneut hoch, bietet er nach Anmeldung eines {rolle.admin} **Aktualisieren / Reparieren** an: Die aktuelle Version wird neu eingespielt, die Daten bleiben erhalten.

## Cronjob (empfohlen)
Die App erledigt Hintergrundaufgaben (z. B. {bereich.gallery}, Kalender, Updates) auch ohne Cronjob. Zuverlässiger geht es mit einem Cronjob im Kundenmenü des Hosters, der alle 15 Minuten die Cron-Adresse aufruft. Die Adresse zeigt der Installer am Ende an; sie steht auch unter **Verwaltung → Einstellungen → Zustand und Updates** beim Punkt **Hintergrundaufgaben**.

Ohne Cronjob erledigt die App fällige Aufgaben nach einem Besuch, ohne den Besucher warten zu lassen. Klappt das auf eurem Webspace nicht, zeigt die Ampel bei **Hintergrundaufgaben** das gelb oder rot an und nennt die Adresse für den Cronjob (siehe [Zustand und Statusmeldungen](seite:admin-zustand)). Läuft ein Cronjob, steht dort, wann er zuletzt lief.

## Server mit nginx
Die meisten Hoster nutzen den Webserver Apache oder LiteSpeed; dann richtet die App alles selbst ein (über die Dateien `.htaccess`). Läuft euer Webspace mit **nginx**, wertet der Server diese Dateien nicht aus. Die App läuft trotzdem, aber bittet den Hoster, Folgendes einzustellen:

- **Schutz der Daten:** Die Ordner `data`, `src`, `core` und `bin` im App-Ordner dürfen von außen nicht abrufbar sein. Der Installer prüft das und legt die Daten sonst außerhalb des Web-Verzeichnisses ab.
- **Schneller laden:** Dateien unter `app/assets/` ein Jahr im Browser zwischenspeichern (`Cache-Control: public, max-age=31536000, immutable`; ihr Name ändert sich mit jeder neuen Version), Symbole und Manifest unter `app/` einen Tag. Text-Dateien (HTML, CSS, JavaScript, JSON, SVG, Manifest) komprimiert ausliefern (gzip).

Ohne diese Einstellungen funktioniert die App, lädt beim ersten Öffnen aber langsamer.
