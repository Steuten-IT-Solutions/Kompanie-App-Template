# Statusmeldungen (Format 2)

Die App meldet dem **Steuten IT Solutions Cockpit** (Repo `Steuten-IT-Solutions/project-cockpit`), ob eine
Installation läuft, aber nur, wenn ein Admin zugestimmt hat (Einrichtungsassistent bzw.
*Einstellungen → Zustand und Updates*). Ohne Zustimmung wird nichts gesendet und nichts gezählt. Issue #20, Gegenseite
im Cockpit: project-cockpit#12.

Der Empfänger steht in der Repo-Variable `KOMPANIE_STATUS_URL` (nur `https://`) und kommt als
`statusUrl` in die `update.json` des Pakets.

## Wann

| Art | Wann |
|---|---|
| `heartbeat` (Lebenszeichen) | stündlich (Aufgabe `status_report`); auch bei einem vorgezogenen Lauf, außer das letzte ist keine 10 Minuten her |
| `event` (Ereignis) | bei schweren Fehlern sofort, je Code höchstens einmal pro Stunde; die Aufgabe wird dafür vorgezogen, keine Anfrage wartet auf den Versand |

Ohne echten Cronjob laufen Aufgaben nur bei Seitenaufrufen (eigenständige Version: `cron.kind = pseudo`;
WordPress ohne Cronjob des Hosters: `wp`); nachts kann das Lebenszeichen dann ausbleiben, ohne dass etwas
kaputt ist.

Jede Meldung wird einzeln versucht (Zeitlimit 5 s), nicht in einer Schleife. Ereignisse, die nicht ankommen
oder die das Cockpit später annehmen kann, bleiben bis zum nächsten Lauf liegen; endgültig abgelehnte
werden verworfen. Ein gescheitertes Ereignis hält das Lebenszeichen nicht auf.

Fehlercodes werden in den Einstellungen der Installation gezählt (höchstens 1000 je Code und Stunde). Ist die
Datenbank ganz ausgefallen, lässt sich deshalb auch `DB_ERROR` nicht vermerken; dann fällt die Installation
durch das ausbleibende Lebenszeichen auf.

## Inhalt (abschließend)

```json
{
  "format": 2,
  "installation": "9f3c0a…(32 Hex-Zeichen)",
  "kompanie": "steuten-it-solutions/kompanie-musterstadt",
  "version": "1.4.0.3",
  "variant": "standalone",
  "php": "8.3",
  "database": "sqlite",
  "kind": "heartbeat",
  "checks": { "updates": "ok", "gallery": "error", "calendar": "off", "backup": "warn", "server": "ok", "cron": "ok" },
  "errors": { "CALENDAR_FETCH": 3, "UPDATE_FAILED": 1 },
  "cron": { "kind": "real", "last": 1790000000 },
  "features": ["events", "calendar", "points"],
  "update": { "ok": false, "at": 1790000000, "to": "1.4.0.4" },
  "updateCheckOk": true,
  "gallery": { "ok": false, "at": 1789990000 },
  "maintenance": false,
  "sent": 1790000300,
  "nonce": "a1b2c3d4e5f60718",
  "event": "UPDATE_FAILED",
  "key": "…(64 Hex-Zeichen, nur bis zur Bestätigung)"
}
```

- `checks`: Ampel der Statusseite je Bereich, nur Schlüssel (`^[a-z][a-z0-9_]{0,31}$`) und Stufe
  (`ok`, `warn`, `error`, `off`), **keine Texte**.
- `errors`: Fehlercodes der letzten 24 Stunden mit Anzahl. `event`: nur bei `kind = event`.
- Codes (`Telemetry\Code`, Muster `^[A-Z0-9_]{1,40}$`): `UPDATE_FAILED`*, `ROLLBACK`*, `UPDATE_CHECK`,
  `CALENDAR_FETCH`, `GALLERY_SYNC`, `TASK_FAILED`, `SERVER_ERROR`, `DB_ERROR`*, `PHP_FATAL`*, `DISK_LOW`*,
  `CHECK_ERROR`* (eine Ampel ist auf Rot gesprungen). * = schwer, wird sofort als Ereignis gesendet.
- `cron.kind`: `real` (Cronjob des Hosters: eigenständig in den letzten 2 Stunden gelaufen, bei WordPress
  mit `DISABLE_WP_CRON`), `pseudo` (eigenständig, nur bei Seitenaufrufen), `wp` (WP-Cron, läuft ebenfalls
  nur bei Seitenaufrufen). `cron.last`: letzter Lauf einer Hintergrundaufgabe.
- `update`, `updateCheckOk`, `gallery`, `sent`: wie in Format 1.
- `maintenance`: ob der Wartungsmodus an ist (Issue #42), ohne Begründung. Das Cockpit zeigt die Installation dann als „in Wartung“, nicht als Fehler.

**Keine** Namen, keine Mitgliederzahlen, keine Inhalte, keine Fehlertexte. Was genau gesendet wird, sehen
Admins unter *Einstellungen → Zustand und Updates* (Beispiel eines Lebenszeichens, ohne `key`), dazu den Zeitpunkt der
letzten Meldung.

## Signatur und erste Meldung

- Header `X-Kompanie-Signature: sha256=<hex>`: HMAC-SHA256 über den gesendeten Text (UTF-8, genau die Bytes
  des Bodys) mit dem Geheimnis der Installation.
- Installations-ID (16 Byte) und Geheimnis (32 Byte) erzeugt die App beim ersten Bedarf zufällig. Beide
  gehören zur Installation und kommen nicht in Sicherungen (`Backup::LOCAL_SETTINGS`).
- **Vertrauen beim ersten Kontakt:** Bis das Cockpit eine Meldung mit 2xx angenommen hat, sendet die App das
  Geheimnis im Feld `key` mit. Das Cockpit speichert es bei der ersten Meldung einer unbekannten
  Installation und prüft ab dann jede Meldung damit. Danach sendet die App `key` nicht mehr.
- Kommt bei einer **bekannten** Installation noch `key` mit (z. B. weil die App die Antwort auf die erste
  Meldung nicht erhalten hat), prüft das Cockpit nur die Signatur mit dem gespeicherten Geheimnis und
  ignoriert `key`; ein anderes Geheimnis überschreibt nie das gespeicherte.
- Gegen Wiederholung: `sent` muss nah an der Zeit des Empfängers liegen, `nonce` je Installation einmalig.

## Antworten des Empfängers

| Status | Bedeutung | Reaktion der App |
|---|---|---|
| 2xx | angenommen | mit `key`: Installation gilt als bestätigt |
| 400 | ungültig (Feld, Code) | Ereignis verwerfen |
| 401 `{"code":"bad_signature"}` | Signatur fehlt oder falsch | Ereignis beim nächsten Lauf erneut (`key` nur, solange nicht bestätigt) |
| 409 `{"code":"unknown_installation"}` | Installation unbekannt und kein `key` (z. B. Cockpit neu aufgebaut) | Ereignis beim nächsten Lauf erneut, wieder mit `key` |
| 409 `{"code":"replay"}` | `nonce` schon gesehen oder `sent` außerhalb des Zeitfensters | Ereignis verwerfen |
| 403 `{"code":"unknown_company"}` | Kompanie ist im Cockpit (noch) nicht als Projekt eingetragen | Ereignis verwerfen; Lebenszeichen kommen ohnehin stündlich, sobald sie eingetragen ist |
| 413, sonstige 4xx | zu groß bzw. nicht annehmbar | Ereignis verwerfen |
| 429, 5xx, keine Antwort | zu viele, Fehler beim Empfänger | Ereignis beim nächsten Lauf erneut |

Das Lebenszeichen wird in jedem Fall beim nächsten Lauf neu erzeugt.

Format 1 (täglich, ohne Signatur) nimmt das Cockpit übergangsweise weiter an, bis alle Installationen
aktualisiert sind.
