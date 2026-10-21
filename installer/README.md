# Web-Installer

Eine einzelne Datei `install.php` für die eigenständige Version (ohne WordPress). Die Kompanie lädt sie über den Dateimanager ihres Hosters oder per FTP in den Ordner der App (z. B. `/app/`) und öffnet sie im Browser. Der Installer

- prüft den Server (u. a. PHP ab 8.1) und zeigt verständliche Meldungen, auch auf zu alten Servern,
- erkennt WordPress und verweist dann auf das Plugin,
- lädt das signierte Release der Kompanie und prüft die Signatur,
- legt die Datenbank an (SQLite empfohlen, sonst MySQL) und richtet den ersten Admin ein,
- löscht sich danach selbst; danach führt die App den Admin durch den Einrichtungsassistenten.

Erneut hochgeladen erkennt er eine bestehende Installation und bietet nach Anmeldung eines Admins „Aktualisieren / Reparieren“ an; die Daten bleiben.

`scripts/package.php` setzt beim Bauen je Kompanie Name, Update-Adresse, vertrauenswürdige Schlüssel, Farbe, Download-Seite und Kontakt ein. Anleitung für Kompanien: Handbuch, „Installation ohne WordPress“ (`docs/handbuch/admin-installation-eigenstaendig.md`). Hintergrund: [Architektur, Abschnitt 12](../docs/ARCHITEKTUR.md#12-installation-und-einrichtung).
