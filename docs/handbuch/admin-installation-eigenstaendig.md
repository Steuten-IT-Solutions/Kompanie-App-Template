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
