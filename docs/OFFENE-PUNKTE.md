# Planung der offenen Punkte

Ergänzt die [Architektur](ARCHITEKTUR.md). Jeder Punkt hat einen Vorschlag, die Schritte zur Umsetzung und was wir dafür entscheiden oder bereitstellen müssen.

| Nr. | Punkt | Wann | Braucht von uns |
|---|---|---|---|
| 1 | [Update-Verteilung und gestaffelte Releases](#1-update-verteilung-und-gestaffelte-releases) | Phase 4/5 | Demo-Installation auf unserem Webspace |
| 2 | [Hilfe beim Hochladen von `install.php`](#2-hilfe-beim-hochladen-von-installphp) | Phase 5 | Anleitungen je Hoster |
| 3 | [Rechtstexte und Datenschutz](#3-rechtstexte-und-datenschutz) | vor der ersten neuen Kompanie | rechtliche Prüfung beauftragen |
| 4 | [Statusmeldungen](#4-statusmeldungen) | Phase 5/6 | Webspace/Subdomain für den Empfang |
| 5 | [Google: „Mit Google verbinden“](#5-google-mit-google-verbinden-und-optional-drivefile) | umgesetzt; Test `drive.file` optional (Issue #40) | für den Test: Google-Cloud-Projekt, Test-Konto |
| 6 | [Branches und PHP-Version](#6-branches-und-php-version) | sofort | Standard-Branch auf `main` umstellen, PHP-Version von Lank nachsehen |

---

## 1. Update-Verteilung und gestaffelte Releases

### Problem 1: Rate-Limit der GitHub-API

Fragt jeder Server direkt die GitHub-API nach neuen Releases, gilt ohne Anmeldung ein Limit von **60 Anfragen pro Stunde je IP-Adresse** ([GitHub Docs](https://docs.github.com/en/rest/using-the-rest-api/rate-limits-for-the-rest-api)). Auf gemietetem Webspace teilen sich oft hunderte Webseiten eine IP-Adresse; die Abfragen würden zufällig scheitern.

**Lösung: eine Update-Liste als statische Datei.** Jedes Kompanie-Repo veröffentlicht über GitHub Pages (dort liegt auch die Download-Seite) eine Datei `updates.json`:

```json
{
  "kompanie": "kompanie-musterstadt",
  "channels": {
    "stable": "1.4.0.3",
    "early":  "1.5.0.1"
  },
  "versions": [
    { "version": "1.5.0.1", "major": 1, "minPhp": "8.1", "migrations": true,
      "security": false, "url": "https://github.com/…/releases/download/v1.5.0.1/kompanie.zip",
      "sha256": "…", "published": "2026-11-02" },
    { "version": "1.4.0.3", "…": "…" }
  ],
  "signature": "…"
}
```

- Statische Dateien unterliegen nicht dem API-Limit. Die ZIPs werden über normale Download-Adressen geladen.
- Die **ganze Liste ist signiert** (Ed25519). Ein Server übernimmt sie nur mit gültiger Signatur. So kann niemand eine ältere, fehlerhafte Version als „neu“ unterschieben.
- Die Liste nennt **alle Versionen** der aktuellen Hauptversion. Das braucht das Zurückrollen.
- Server prüfen **einmal täglich** zu einer zufälligen Uhrzeit, damit nicht alle gleichzeitig laden.

### Problem 2: Ein fehlerhaftes Release trifft alle gleichzeitig

**Entscheidung: keine Testkompanien.** Jede neue Version läuft zuerst auf **unserer eigenen Demo-Installation** und wird dort automatisch getestet. Erst danach bekommen sie alle Kompanien.

| Kanal | Wer | Bekommt ein Release |
|---|---|---|
| `early` | nur unsere Demo-Installation | sofort |
| `stable` | alle Kompanien | sobald der Demo-Test grün ist und nichts gestoppt wurde |

Ablauf:

1. Kern-Release → „Kompanien abgleichen“ baut **alle** Kompanie-Repos (spätestens nach 30 Minuten) und trägt die Version in `updates.json` unter `early` ein.
2. Die **Demo-Installation** (Standalone mit SQLite **und** WordPress-Plugin, mit Beispieldaten) aktualisiert sich sofort.
3. Ein Workflow testet sie automatisch (Playwright): Anmeldung, Termine, Punkte, Strafen, Galerie, Update, Zurückrollen.
4. Ist der Test grün und kein Issue mit dem Label `release-stopp` offen, **befördert** der Workflow die Version nach `stable`. Ab dann holen sie sich alle Kompanien.
5. **Stoppen:** Wer einen Fehler bemerkt, legt ein Issue mit `release-stopp` an; die Beförderung hält an.
6. **Zurückziehen:** Ist eine Version schon in `stable`, setzt der Workflow „Version zurückziehen“ `stable` auf die vorige Version. Server, die schon aktualisiert haben, **rollen automatisch zurück**. Das klappt, weil Migrationen innerhalb einer Hauptversion nur hinzufügen.

*Einschätzung:* Der Demo-Test findet Fehler in den getesteten Abläufen, aber nicht alles, was nur bei echten Daten oder einem bestimmten Hoster auftritt. Dafür sind Zurückziehen, automatisches Zurückrollen und die Statusmeldungen (Punkt 4) da. Eine Haltezeit lässt sich später ergänzen, falls nötig.

**Schritte:**
- ✓ `updates.json` und ihre Signatur beim Veröffentlichen erzeugen (Phase 4)
- ✓ Updater liest `updates.json`, Kanäle, Zurückziehen (Phase 5)
- ✓ Befördern und Zurückziehen von Hand: *Actions → Versionen steuern* (Phase 4/5)
- offen: Demo-Installation mit Beispieldaten auf unserem Webspace, automatischer Test und automatisches
  Befördern mit `release-stopp` (Phase 6; braucht den Webspace)

---

## 2. Hilfe beim Hochladen von `install.php`

Ohne WordPress bleibt ein technischer Schritt: eine Datei auf den Webspace laden. **Entscheidung: Wir installieren nicht selbst, sondern stellen Anleitungen bereit.**

- **Anleitungen je Hoster** mit Bildschirmfotos für den Dateimanager im Kundenmenü, damit kein FTP-Programm nötig ist. Zuerst für die Hoster der ersten Kompanien, dann nach Bedarf. Vorschlag zum Start: IONOS, Strato, all-inkl, netcup, Hetzner.
- Dieselben Anleitungen erklären: HTTPS einschalten, PHP-Version umstellen, Unterordner anlegen.
- **Kurzvideo** (1–2 Minuten) je Hoster, auf der Download-Seite verlinkt.
- Eine **allgemeine Anleitung** für alle anderen Hoster („So findest du den Dateimanager“).
- Der Installer erkennt den Hoster, wo möglich, und verlinkt die passende Anleitung direkt bei jedem Problem.
- Die Anleitungen liegen **im Kern-Repo** und erscheinen auf allen Download-Seiten. Eine Korrektur erreicht so alle Kompanien.

**Schritte:** ✓ Kurze allgemeine Anleitung auf jeder Download-Seite (Phase 5). Offen: ausführliche Anleitungen
und Videos je Hoster, sobald eine Kompanie dort installiert; Erkennen des Hosters im Installer.

---

## 3. Rechtstexte und Datenschutz

*Hinweis:* Das ist keine Rechtsberatung. Die folgenden Punkte sind die Fragen, die eine Fachanwältin oder ein Datenschutzberater klären sollte, und der Plan, wie die App die Antworten umsetzt.

### Zu klären (einmalig, für alle Kompanien)

1. **Impressum:** Die Impressumspflicht nach § 5 DDG gilt für Telemedien; Sekundärquellen zählen Apps ausdrücklich dazu und nennen für Vereine u. a. Name mit Rechtsform, Anschrift, zwei Kontaktwege und den vertretungsberechtigten Vorstand ([ARAG](https://www.arag.de/vereinsversicherung/sicheres-impressum-fuer-vereine/), [forms-legal](https://forms-legal.com/de/deutschland/business/policies/website-impressum-vollstaendig-deutschland)). Zu klären: Braucht die App ein eigenes Impressum, oder reicht ein Link auf das der Webseite? Was gilt für Kompanien ohne Webseite?
2. **Datenschutzerklärung:** Welche Angaben braucht sie? Die App verarbeitet: Konten der Mitglieder, Punkte und Strafen, Anmelde-Cookies, Bilder aus Google Drive (Kopien auf dem Server), Kalenderdaten, Server-Logs beim Hoster.
3. **Rollen:** Die Kompanie ist Verantwortliche. Sind wir **Auftragsverarbeiter**, wenn wir Statusmeldungen empfangen (Punkt 4)? Dann brauchen wir einen Mustervertrag (AVV).
4. **Strafen** sind Geldbeträge je Person. Gibt es dafür besondere Anforderungen (z. B. Löschfristen, Information der Mitglieder)?
5. **Verzeichnis von Verarbeitungstätigkeiten:** Sollen wir den Kompanien eine Vorlage mitgeben?
6. **Umfragen (Issue #4):** Antworten in **namentlichen** Umfragen sind personenbezogene Daten (wer was meint, z. B. zu Terminen oder Vereinsfragen); sie gehören in die Datenschutzerklärung und das Verzeichnis. Bei **anonymen** Umfragen speichert die App, *wer* teilgenommen hat (`poll_votes`, für „schon beantwortet“ und die Erinnerung), und die Antworten getrennt davon unter einem Pseudonym (HMAC der Benutzer-ID mit dem Geheimnis `polls_secret`, das mit in die Sicherung geht). Die App zeigt niemandem, wer was geantwortet hat; wer aber Zugriff auf Datenbank **und** Einstellungen hat (Hoster, Admin mit Datenbankzugang), kann die Zuordnung herstellen. Das ist **Pseudonymisierung, keine Anonymisierung** im Sinne der DSGVO – zu klären, wie die Datenschutzerklärung das beschreibt (der Hinweis in der App lautet bewusst nur „Deine Antwort wird niemandem angezeigt“). Bei sehr wenigen Teilnehmenden lassen sich anonyme Antworten außerdem aus den Ergebnissen erschließen. Löschfristen für beendete Umfragen gibt es noch nicht (sie bleiben, bis jemand mit `manage_polls` sie löscht); beim Löschen eines Kontos verschwinden Teilnahme und Antworten der Person (auch die anonymen, über das Pseudonym).
7. **Geburtstage (Issue #1):** Das Geburtsdatum ist ein personenbezogenes Datum. Die App zeigt es anderen Mitgliedern standardmäßig (**Opt-out**: Schalter „Anderen Mitgliedern zeigen“ ist bei neuen Einträgen an, auch wenn die Verwaltung das Datum einträgt); das Alter nur auf Wunsch (Opt-in, „Alter anzeigen“), das Geburtsjahr nie. Zu klären: Reicht Opt-out mit Hinweis, oder braucht es eine Einwilligung (Opt-in), zumal wer Mitglieder anlegen darf Daten anderer eintragen kann? Wer Mitgliedsdetails sieht, sieht auch das volle Datum mit Jahr – angemessen? Die Geburtstage gehören in die Datenschutzerklärung und das Verzeichnis und in einen künftigen **Export** der eigenen Daten (siehe unten); beim Löschen eines Kontos wird der Geburtstag mit gelöscht.

### Umsetzung in der App

- **Vorlagen** für Impressum und Datenschutzerklärung nach der Prüfung, mit Platzhaltern. Die App füllt aus, was sie weiß: Name, eingeschaltete Bereiche, Google ja/nein, Statusmeldungen ja/nein.
- Den Rest trägt die Kompanie im Einrichtungsassistenten ein: Anschrift, Vorstand, Kontakt. Wer schon eine Webseite hat, gibt stattdessen die Links an.
- **Sichtbar** auf der Anmeldeseite, im Konto-Bereich und auf der Download-Seite.
- **Statusseite:** gelb, solange Impressum oder Datenschutz fehlen.
- **Löschung** je Mitglied gibt es seit Issue #24 (*Verwaltung → Mitglied → Konto löschen*, entfernt alle eigenen Daten). Offen ist noch ein **Export** der eigenen Daten (Auskunft nach Art. 15 DSGVO), falls die Prüfung das verlangt.
- Die Vorlagen liegen **im Kern**, damit eine Änderung über ein Update alle Kompanien erreicht.

**Schritte:** Prüfung beauftragen (vor der ersten neuen Kompanie) → Vorlagen schreiben → in Phase 5 einbauen.

---

## 4. Statusmeldungen

Empfang, Verlauf, Ampel und Warnungen baut das **Steuten IT Solutions Cockpit** (Repo `project-cockpit`,
Subdomain auf unserem Webspace). Das frühere `tools/status-empfang` ist dadurch ersetzt und entfernt.

- Die Server **senden** nur, wenn die Kompanie zugestimmt hat: stündlich ein Lebenszeichen, schwere Fehler
  sofort (je Code höchstens einmal pro Stunde). „Holen“ durch uns ginge nicht ohne zusätzliche Zugangsdaten.
- **Inhalt (abschließend)** und Protokoll: [STATUSMELDUNGEN.md](STATUSMELDUNGEN.md) (Format 2). Kurz:
  Installations-ID, Kompanie, Version, Variante, PHP, Datenbank, Ampel je Bereich (nur Stufe), Fehlercodes
  der letzten 24 Stunden mit Anzahl und Ursache (z. B. `ics_http_404`), Art und letzter Lauf der
  Hintergrundaufgaben, eingeschaltete Bereiche, Ergebnis des letzten Updates und Galerie-Abgleichs.
- **Keine** Namen, keine Mitgliederzahlen, keine Inhalte, keine Fehlertexte. Die Texte stehen nur im
  Fehlerprotokoll der Installation; Admins können es kopieren und uns bei Bedarf schicken. IP-Adressen speichert der
  Empfänger nicht; der Hoster protokolliert sie trotzdem in seinen Server-Logs, das gehört in die
  Datenschutzerklärung.
- **Signiert** (HMAC-SHA256) mit einem Geheimnis je Installation; das Cockpit lernt es bei der ersten
  Meldung (Vertrauen beim ersten Kontakt).
- **Aufbewahrung:** 90 Tage, danach automatisch gelöscht.
- **Still** gilt eine Installation nach 2 Stunden ohne Lebenszeichen (echter Cronjob oder WP-Cron) bzw.
  24 Stunden (Pseudo-Cron).
- **Warnungen per E-Mail und Push** an uns bei neuen Ereignissen, roter Ampel, ausbleibendem Lebenszeichen
  und gehäuften Fehlern nach einem Release. Dann prüfen wir, ob die Version zurückgezogen werden muss
  (Punkt 1).
- Die Zustimmung wird im Einrichtungsassistenten erfragt (Haken vorausgewählt, lässt sich entfernen); abschaltbar unter *Einstellungen → Updates*. Beim
  Abschalten werden auch die Fehlerzähler gelöscht.

**Datenschutz:** Die Erweiterung (stündlich, Fehlercodes) gehört in die Prüfung aus Abschnitt 3 und in die
Datenschutzerklärung der Kompanien; ob ein AVV nötig ist, klären wir dort.

**Schritte:** ✓ Meldung im Kern, nur mit Zustimmung, Inhalt vorher einsehbar (Phase 5) → ✓ Format 2 in der
App (#20) → Empfang, Verlauf und Warnungen im Cockpit (project-cockpit#12).

---

## 5. Google: „Mit Google verbinden“ und optional `drive.file`

**Umgesetzt:** „Mit Google verbinden“ mit dem **eigenen OAuth-Client jeder Installation** (Client-ID und Client-Secret trägt der Admin ein), Berechtigungen `drive.readonly` und `calendar.readonly`, wie in der früheren App von Lank und dort im Betrieb erprobt: externe App „In Produktion“, nichts unter Google Auth Platform → Datenzugriff (sonst verlangt Google eine Prüfung mit Demo-Video), der Admin bestätigt die Warnung „Google hat diese App nicht überprüft“ über „Erweitert → Weiter“ und setzt beide Häkchen. Technik: [Architektur, Abschnitt 9](ARCHITEKTUR.md#galerie); Anleitung für Admins: [Handbuch, Google einrichten](handbuch/admin-google.md). Das Dienstkonto bleibt als Alternative.

Weil jede Kompanie ihre eigene App bei Google hat, gilt die Grenze von 100 Nutzern für nicht geprüfte Apps praktisch nicht (es verbindet sich nur ein Admin), und eine Sicherheitsbewertung ist nicht nötig. Der Preis ist der Warnbildschirm beim Verbinden und etwa 20 Minuten Einrichtung in der Google Cloud Console.

### Optional: `drive.file` mit Google Picker (Issue #40)

Ziel wäre, den Warnbildschirm zu vermeiden: `drive.file` gilt bei Google als nicht sensibel, die App sähe nur den im Google Picker gewählten Ordner. Offen ist, ob die App damit an Dateien **in** einem gewählten Ordner kommt, in Unterordnern (Jahr → Album → `intern`) und vor allem an **später hochgeladene Bilder**. Die Berichte dazu widersprechen sich, eine offizielle Aussage von Google fehlt. Für den Kalender bliebe `calendar.readonly` („sensitive“) mit Warnbildschirm oder Prüfung; der Kalender ginge dann eher über die iCal-Adresse.

Für einen Test bräuchte es: ein Google-Cloud-Projekt mit **Google Drive API** und **Google Picker API**, OAuth-Zustimmungsbildschirm „Extern“ im Status „Testing“ mit einem Test-Google-Konto als Testnutzer, einen OAuth-Client vom Typ **Webanwendung**, einen auf die Picker API beschränkten API-Schlüssel und im Test-Konto diese Ordnerstruktur:

```
Galerie/
  2025/Sommerfest/   3 Bilder
  2025/Sommerfest/intern/   2 Bilder
  2026/Winterball/   2 Bilder
```

Client-ID, Client-Secret und API-Schlüssel gehören nicht in den Chat, sondern als Umgebungsvariablen in die Umgebung der Sitzung.

| Nr. | Test | Bestanden, wenn |
|---|---|---|
| T1 | Bilder direkt in `Galerie/…` | alle gelistet und herunterladbar |
| T2 | Unterordner Jahr → Album | rekursiv gelistet |
| T3 | Unterordner `intern` | gelistet |
| T4 | **Nach** dem Verbinden ein neues Bild in `2026/Winterball` hochladen (im Drive im Browser, nicht über die App) | beim nächsten Abgleich gelistet |
| T5 | **Nach** dem Verbinden einen neuen Album-Ordner anlegen | gelistet |
| T6 | Bild löschen | verschwindet aus der Liste |
| T7 | Token nach einigen Tagen ohne Browser erneuern | funktioniert weiterhin |

**Danach:** Bestehen T1–T7, kann `drive.file` mit Picker als zusätzlicher Weg (ohne Warnbildschirm) neben dem heutigen dazukommen. Scheitern T4 oder T5, bleibt es beim heutigen Weg.

---

## 6. Branches und PHP-Version

### Standard-Branch

Weil das Repo leer war, ist mein Arbeits-Branch zum Standard-Branch geworden. Plan:

1. Branch `main` aus dem aktuellen Stand anlegen. *Erledigt.*
2. In GitHub unter *Settings → General → Default branch* auf `main` umstellen (braucht Admin-Rechte im Repo).
3. **Regeln für `main`** (*Settings → Rules*): nur über Pull Requests, CI muss grün sein.
4. Arbeits-Branches danach immer gegen `main`, jede Phase als eigener Pull Request.

Die Schritte 2 und 3 macht ihr in den Einstellungen.

### PHP-Version

- **Lank:** In WordPress unter *Werkzeuge → Website-Zustand → Bericht → Server* steht die PHP-Version. Bitte nachsehen.
- **Neue Kompanien:** Der Installer prüft die Version ohnehin. Bis zur ersten neuen Kompanie bleibt PHP ≥ 8.1 die Mindestversion.
- **Laufend:** Die Statusmeldungen (Punkt 4) zeigen, welche PHP-Versionen im Einsatz sind. Eine Mindestversion heben wir erst an, wenn keine zustimmende Kompanie mehr darunter liegt, und nur mit einer neuen Hauptversion.
