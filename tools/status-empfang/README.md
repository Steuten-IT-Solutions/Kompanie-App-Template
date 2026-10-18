# Empfang der Statusmeldungen

Installationen, deren Admin zugestimmt hat, senden einmal täglich eine kurze Meldung
(Aufbau: `packages/core/src/Update/StatusReporter.php`): Kompanie, Version, Variante, PHP-Version,
Datenbank-Art, Ergebnis des letzten Updates, ob Update-Prüfung und Galerie-Abgleich klappen.
Keine Mitgliederdaten, keine Adressen. Das Skript speichert keine IP-Adressen; die Zugriffsprotokolle
des Webservers tun es aber meist. Für die Subdomain deshalb das Protokoll beim Hoster abschalten oder
kurz halten.

## Einrichten

1. Subdomain mit HTTPS anlegen, z. B. `status.example.org` (PHP ≥ 8.1 mit `pdo_sqlite`).
2. `index.php` und `.htaccess` hochladen, `config.example.php` als `config.php` kopieren und ein
   Passwort-Hash eintragen; das Datenverzeichnis möglichst außerhalb des Web-Verzeichnisses.
   Bei nginx `config.php`, `*.sqlite` und `data/` per `deny all` sperren.
3. Im Kern-Repo die Repo-Variable `KOMPANIE_STATUS_URL` (z. B. `https://status.example.org/`) setzen.
   Beim nächsten Abgleich (spätestens nach 30 Minuten) bekommt jede Kompanie eine neue Version mit dieser
   Adresse in `update.json`; Installationen senden erst, wenn ein Admin unter *Einstellungen → Updates*
   zustimmt.
4. Übersicht: `https://status.example.org/?uebersicht` (Benutzername beliebig, Passwort aus Schritt 2).

## Grenzen

- Meldungen sind **nicht signiert**; jeder kann eine schicken. Die Übersicht ist ein Hinweis, kein Beweis.
- Angenommen werden nur Meldungen von Repos der Organisation (`org` in `config.php`), höchstens 2000
  Zeilen; Einträge ohne Meldung seit 60 Tagen werden entfernt. Wer die Tabelle mit erfundenen Meldungen
  füllt, kann neue Kompanien bis zu 60 Tage aus der Übersicht halten (dann die Datenbank leeren). Die Anmeldung zur Übersicht bremst nur
  leicht (1 Sekunde je Fehlversuch): ein langes Passwort verwenden.
- Je Kompanie und Variante wird nur die letzte Meldung gespeichert (mehrere Installationen derselben
  Kompanie und Variante überschreiben sich).
- Die Erwähnung in der Datenschutzerklärung der Kompanien gehört zu den Rechtstexten
  ([OFFENE-PUNKTE.md, Abschnitt 3](../../docs/OFFENE-PUNKTE.md)).
