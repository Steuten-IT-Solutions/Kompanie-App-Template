# Fehlermeldungen (Format 1)

Über **? → Fehler melden** kann jede Person, auch ohne Anmeldung, einen Fehler an die Betreuer der App melden
(Issue #64). Die Installation schickt die Meldung signiert an das **Steuten IT Solutions Cockpit**
(Repo `Steuten-IT-Solutions/project-cockpit`); das Cockpit legt daraus mit seiner GitHub App ein Issue in **einem
privaten Repository für Meldungen** an (Vorschlag `Steuten-IT-Solutions/kompanie-meldungen`, Label
`kompanie:<repo>` je Kompanie). Die Installationen haben keinen GitHub-Zugang.

Der Empfänger steht in der Repo-Variable `KOMPANIE_REPORT_URL` (nur `https://`, z. B.
`https://status.steuten-it-solutions.de/reports`) und kommt als `reportUrl` in die `update.json` des Pakets
(`scripts/package.php --report-url`). Ohne sie gibt es in der App keinen Knopf „Fehler melden“ (`/config` →
`reports: false`), und die Routen antworten 404 `reports_unavailable`.

Fehlermeldungen brauchen **keine** Zustimmung zu den [Statusmeldungen](STATUSMELDUNGEN.md): Jede Meldung hat
ihre eigene Einwilligung im Formular.

## In der App

| Schritt | Was passiert |
|---|---|
| `GET /reports/form` | Zeitmarke (`token`, HMAC mit dem Geheimnis der Installation), `minSeconds`, `maxBytes` und die Angaben, die der Server beifügt (`version`, `role`). |
| Formular | Beschreibung (Pflicht, ≤ 2000 Zeichen), „Was hast du erwartet?“ (≤ 2000), Bildschirmfoto (PNG/JPEG/WebP, ≤ 2 MB), „Meinen Namen mitschicken“ (nur angemeldet, Standard aus), Einwilligung (Pflicht). Darunter steht, was automatisch mitgeht: Seite in der App, App-Version, Gerät/Browser, Rolle bzw. „ohne Anmeldung“, Uhrzeit, letzte Fehlermeldung der App (nur im Speicher der Sitzung, `lastError.ts`). |
| `POST /reports` | JSON, CSRF wie bei allen POSTs (eigenständig: Herkunft und `application/json`, angemeldet zusätzlich das Token; WordPress: REST-Nonce). Prüft Einwilligung, Längen, Bild (Signatur, `getimagesize`, Typ passend, Kante ≤ 10000 px, ≤ 40 Megapixel), Zeitfalle, Begrenzung; antwortet `201 {"queued": true}` erst, wenn die Meldung in `bug_reports` steht. |
| Aufgabe `bug_reports` | stündlich, nach dem Einreihen vorgezogen (`Scheduler::runSoon`); läuft auch im Wartungsmodus. Je Lauf höchstens 10 Meldungen, jede einzeln (Zeitlimit 20 s). |

**Gegen Spam ohne Captcha:** verstecktes Feld `website` (gefüllt → scheinbar `201`, nichts gespeichert),
Zeitfalle (frühestens 3 s, spätestens 6 h nach dem Öffnen; sonst `429 too_fast` bzw. `400 form_expired`),
höchstens 5 Meldungen je Anfragendem und Stunde (IP nur als SHA-256 im Zwischenspeicher, IPv6 je /64), höchstens
30 je Installation und Tag (`429 too_many_attempts`), höchstens 50 wartende Meldungen (`503 reports_full`).

**Speicher:** Tabelle `bug_reports` (Migration 14): Angaben als JSON, Bildschirmfoto als Base64 in derselben Zeile
(eine Zeile = eine Meldung; Löschen entfernt alles auf einmal, keine verwaisten Dateien, nichts im öffentlichen
Verzeichnis). Nicht in Sicherungen (keine Kerntabelle der Sicherung). `user_id` steht nur, wenn der Name
mitgeht; dann wird die Meldung beim Löschen des Kontos mit gelöscht (`UserData`). Zugestellte Meldungen werden
gelöscht, nicht zugestellte nach 7 Tagen.

## Inhalt (abschließend)

```json
{
  "format": 1,
  "installation": "9f3c0a…(32 Hex-Zeichen, wie bei den Statusmeldungen)",
  "kompanie": "steuten-it-solutions/kompanie-musterstadt",
  "sent": 1790000300,
  "nonce": "a1b2c3d4e5f60718",
  "report": {
    "id": "5b0e…(32 Hex-Zeichen, je Meldung; gegen doppelte Issues)",
    "description": "Der Knopf „Speichern“ reagiert nicht.",
    "expected": "Dass der Termin gespeichert wird.",
    "page": "#/termine/verwalten",
    "version": "1.4.0.3",
    "variant": "standalone",
    "userAgent": "Mozilla/5.0 …",
    "role": "Mitglied",
    "name": null,
    "lastError": "Dafür fehlen dir die Rechte. (HTTP 403, forbidden)",
    "created": 1790000000
  },
  "screenshot": { "type": "image/png", "data": "iVBORw0…(Base64, ≤ 2 MB dekodiert)" },
  "key": "…(64 Hex-Zeichen, nur bis zur Bestätigung)"
}
```

- `report.role`: Bezeichnung der Rolle (wie in der App) oder „ohne Anmeldung“. `report.name`: nur mit Haken,
  sonst `null`. `report.lastError`: einzeilig, ≤ 500 Zeichen, oder `null`. `report.page` ≤ 200, `userAgent` ≤ 400.
- `version`: Version beim Absenden des Formulars. `created`: Zeitpunkt des Absendens, `sent`: des Versands.
- `screenshot`: `null` ohne Bild; `type` ist `image/png`, `image/jpeg` oder `image/webp`.

## Signatur und erste Meldung

Wie bei den Statusmeldungen: Header `X-Kompanie-Signature: sha256=<hex>` (HMAC-SHA256 über genau die Bytes
des Bodys) mit dem Geheimnis der Installation (`installation_secret`, nicht in Sicherungen).

Vertrauen beim ersten Kontakt mit **eigenem Stand** (`bug_reports_registered`): Bis der Empfänger eine
Fehlermeldung mit 2xx angenommen hat, geht das Geheimnis im Feld `key` mit. So lernt das Cockpit das Geheimnis
auch von Installationen, die keine Statusmeldungen senden. Kennt das Cockpit die Installation schon (aus Status-
oder Fehlermeldungen), prüft es nur mit dem gespeicherten Geheimnis; ein `key` ersetzt es nie.

## Antworten des Empfängers

| Status | Bedeutung | Reaktion der App |
|---|---|---|
| 2xx | gespeichert (auch: `report.id` schon bekannt) | Meldung löschen; mit `key`: Installation gilt als bestätigt |
| 401 `bad_signature` | Signatur fehlt oder falsch | beim nächsten Lauf erneut |
| 409 `unknown_installation` | Installation unbekannt und kein `key` | beim nächsten Lauf erneut, wieder mit `key` |
| 409 `replay` | `nonce` schon gesehen oder `sent` außerhalb von 10 Minuten | beim nächsten Lauf erneut (neue `nonce`) |
| 400, 403 `unknown_company`, 413, sonstige 4xx | so nie annehmbar | Meldung verwerfen |
| 429, 5xx, keine Antwort | zu viele, Fehler beim Empfänger | erneut beim nächsten Lauf; dieser Lauf hört auf |

Das Cockpit antwortet 2xx erst, wenn es die Meldung gespeichert hat; das Issue legt es danach an (sofort bzw. im
Cron, bei Ausfall von GitHub später erneut).

## Datenschutz

Beschreibung, Erwartung, Bildschirmfoto und ggf. Name sind personenbezogen bzw. können es sein. Deshalb:
Einwilligung je Meldung mit Hinweis auf die Betreuer und das private Repository, Name nur auf Wunsch, alles vor
dem Absenden sichtbar, IP-Adressen nur gehasht und nur für die Begrenzung (im Zwischenspeicher, höchstens eine
Stunde), keine Sicherungskopien, Löschen nach Zustellung bzw. 7 Tagen bzw. mit dem Konto. Hinweis für die
Datenschutzerklärung: [Offene Punkte, Abschnitt 3](OFFENE-PUNKTE.md#3-rechtstexte-und-datenschutz).
