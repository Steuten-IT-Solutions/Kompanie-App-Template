# Empfang der Statusmeldungen

Installationen, deren Admin zugestimmt hat, senden einmal täglich eine kurze Meldung
(Aufbau: `packages/core/src/Update/StatusReporter.php`): Kompanie, Version, Variante, PHP-Version,
Datenbank-Art, Ergebnis des letzten Updates, ob Update-Prüfung und Galerie-Abgleich klappen.
Keine Mitgliederdaten, keine Adressen; IP-Adressen speichert der Empfang nicht.

## Einrichten

1. Subdomain mit HTTPS anlegen, z. B. `status.example.org` (PHP ≥ 8.1 mit `pdo_sqlite`).
2. `index.php` und `.htaccess` hochladen, `config.example.php` als `config.php` kopieren und ein
   Passwort-Hash eintragen; das Datenverzeichnis möglichst außerhalb des Web-Verzeichnisses.
   Bei nginx `config.php`, `*.sqlite` und `data/` per `deny all` sperren.
3. Im Kern-Repo die Variable `KOMPANIE_STATUS_URL` (z. B. `https://status.example.org/`) bei der
   Environment `veroeffentlichen` setzen. Ab der nächsten Veröffentlichung steht die Adresse in den
   Paketen (`update.json`); Installationen senden erst, wenn ein Admin unter *Einstellungen → Updates*
   zustimmt.
4. Übersicht: `https://status.example.org/?uebersicht` (Benutzername beliebig, Passwort aus Schritt 2).

## Grenzen

- Meldungen sind **nicht signiert**; jeder kann eine schicken. Die Übersicht ist ein Hinweis, kein Beweis.
- Je Kompanie und Variante wird nur die letzte Meldung gespeichert (mehrere Installationen derselben
  Kompanie und Variante überschreiben sich).
- Die Erwähnung in der Datenschutzerklärung der Kompanien gehört zu den Rechtstexten
  ([OFFENE-PUNKTE.md, Abschnitt 3](../../docs/OFFENE-PUNKTE.md)).
