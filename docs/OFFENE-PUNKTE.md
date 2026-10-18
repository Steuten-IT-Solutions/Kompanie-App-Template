# Planung der offenen Punkte

Ergänzt die [Architektur](ARCHITEKTUR.md). Jeder Punkt hat einen Vorschlag, die Schritte zur Umsetzung und was wir dafür entscheiden oder bereitstellen müssen.

| Nr. | Punkt | Wann | Braucht von uns |
|---|---|---|---|
| 1 | [Update-Verteilung und gestaffelte Releases](#1-update-verteilung-und-gestaffelte-releases) | Phase 4/5 | Haltezeit, Testkompanien |
| 2 | [Hilfe beim Hochladen von `install.php`](#2-hilfe-beim-hochladen-von-installphp) | Phase 5 | Entscheidung: Installationsdienst ja/nein |
| 3 | [Rechtstexte und Datenschutz](#3-rechtstexte-und-datenschutz) | vor der ersten neuen Kompanie | rechtliche Prüfung beauftragen |
| 4 | [Statusmeldungen](#4-statusmeldungen) | Phase 5/6 | Webspace/Subdomain für den Empfang |
| 5 | [Galerie-Test](#5-galerie-test-mit-google-verbinden) | sofort, parallel zu Phase 1 | Google-Cloud-Projekt, Test-Konto |
| 6 | [Branches und PHP-Version](#6-branches-und-php-version) | sofort | Standard-Branch umstellen, PHP-Version von Lank nachsehen |

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

**Lösung: zwei Ringe.**

| Ring | Wer | Bekommt ein Release |
|---|---|---|
| `early` | Demo-Installation, Testkompanien (z. B. Lank und eine Kompanie ohne WordPress) | sofort |
| `stable` | alle anderen | nach der **Haltezeit** (Vorschlag: 7 Tage), wenn nichts gestoppt wurde |

Ablauf:

1. Kern-Release → „Alle Kompanien neu bauen“ baut **alle** Kompanie-Repos sofort.
2. Jedes Kompanie-Repo trägt die neue Version in `updates.json` unter `early` ein. Kompanien im Ring `early` (Feld `rollout` in `kompanie.json`) bekommen sie sofort, alle anderen sehen sie noch nicht.
3. Die **Demo-Installation** wird sofort aktualisiert. Ein täglicher Workflow testet sie automatisch (Playwright: Anmeldung, Termine, Punkte, Galerie, Update, Zurückrollen).
4. Ein täglicher Workflow **befördert** Versionen nach der Haltezeit nach `stable`, wenn
   - der Demo-Test grün ist,
   - kein Issue mit dem Label `release-stopp` offen ist,
   - keine Testkompanie mit Zustimmung zu Statusmeldungen (Punkt 4) Fehler nach dem Update meldet.
5. **Sicherheitskorrekturen** (Release mit Label `security`) gehen sofort nach `stable`.
6. **Stoppen:** Wer einen Fehler bemerkt, legt ein Issue mit `release-stopp` an. Die Beförderung hält an, bis es geschlossen ist. Ist eine Version schon in `stable`, setzt der Workflow „Version zurückziehen“ `stable` auf die vorige Version. Server, die schon aktualisiert haben, **rollen automatisch zurück**, wenn ihre Version zurückgezogen wurde. Das klappt, weil Migrationen innerhalb einer Hauptversion nur hinzufügen.

**Schritte:**
- `updates.json` und ihre Signatur im Build-Workflow erzeugen (Phase 4)
- Updater liest `updates.json`, Ringe, Zurückziehen (Phase 5)
- Demo-Installation mit Beispieldaten auf unserem Webspace, täglicher Test (Phase 5)
- Workflows „Befördern“ und „Version zurückziehen“ (Phase 6)

---

## 2. Hilfe beim Hochladen von `install.php`

Ohne WordPress bleibt ein technischer Schritt: eine Datei auf den Webspace laden.

**Immer:**
- **Anleitungen je Hoster** mit Bildschirmfotos für den Dateimanager im Kundenmenü (kein FTP-Programm nötig). Zuerst für die Hoster der ersten Kompanien, dann nach Bedarf. Vorschlag zum Start: IONOS, Strato, all-inkl, netcup, Hetzner.
- Dieselben Anleitungen erklären: HTTPS einschalten, PHP-Version umstellen, Subdomain oder Unterordner anlegen.
- **Kurzvideo** (1–2 Minuten) je Hoster, auf der Download-Seite verlinkt.

**Optional: Installationsdienst durch uns.**
1. Die Kompanie legt im Kundenmenü ihres Hosters einen **eigenen, zeitlich begrenzten FTP-Zugang** für uns an (das erklärt eine Anleitung). Sie gibt nicht ihr Hauptpasswort weiter.
2. Wir laden `install.php` hoch, rufen ihn auf und richten alles bis zum ersten Admin ein.
3. Die Kompanie löscht den Zugang wieder. Wir bestätigen schriftlich, dass wir keine Zugangsdaten aufbewahren.
4. Weil wir dabei Zugriff auf den Webspace und damit möglicherweise auf personenbezogene Daten haben, braucht es einen **Vertrag zur Auftragsverarbeitung** (siehe Punkt 3).

*Entscheidung offen:* ob wir den Dienst anbieten und ob kostenlos oder gegen Gebühr.

---

## 3. Rechtstexte und Datenschutz

*Hinweis:* Das ist keine Rechtsberatung. Die folgenden Punkte sind die Fragen, die eine Fachanwältin oder ein Datenschutzberater klären sollte, und der Plan, wie die App die Antworten umsetzt.

### Zu klären (einmalig, für alle Kompanien)

1. **Impressum:** Die Impressumspflicht nach § 5 DDG gilt für Telemedien; Sekundärquellen zählen Apps ausdrücklich dazu und nennen für Vereine u. a. Name mit Rechtsform, Anschrift, zwei Kontaktwege und den vertretungsberechtigten Vorstand ([ARAG](https://www.arag.de/vereinsversicherung/sicheres-impressum-fuer-vereine/), [forms-legal](https://forms-legal.com/de/deutschland/business/policies/website-impressum-vollstaendig-deutschland)). Zu klären: Braucht die App ein eigenes Impressum, oder reicht ein Link auf das der Webseite? Was gilt für Kompanien ohne Webseite?
2. **Datenschutzerklärung:** Welche Angaben braucht sie? Die App verarbeitet: Konten der Mitglieder, Punkte und Strafen, Anmelde-Cookies, Bilder aus Google Drive (Kopien auf dem Server), Kalenderdaten, Server-Logs beim Hoster.
3. **Rollen:** Die Kompanie ist Verantwortliche. Sind wir **Auftragsverarbeiter**, wenn wir Statusmeldungen empfangen (Punkt 4) oder installieren (Punkt 2)? Dann brauchen wir einen Mustervertrag (AVV).
4. **Strafen** sind Geldbeträge je Person. Gibt es dafür besondere Anforderungen (z. B. Löschfristen, Information der Mitglieder)?
5. **Verzeichnis von Verarbeitungstätigkeiten:** Sollen wir den Kompanien eine Vorlage mitgeben?

### Umsetzung in der App

- **Vorlagen** für Impressum und Datenschutzerklärung nach der Prüfung, mit Platzhaltern. Die App füllt aus, was sie weiß: Name, eingeschaltete Bereiche, Google ja/nein, Statusmeldungen ja/nein.
- Den Rest trägt die Kompanie im Einrichtungsassistenten ein: Anschrift, Vorstand, Kontakt. Wer schon eine Webseite hat, gibt stattdessen die Links an.
- **Sichtbar** auf der Anmeldeseite, im Konto-Bereich und auf der Download-Seite.
- **Statusseite:** gelb, solange Impressum oder Datenschutz fehlen.
- **Lösch- und Exportfunktion** je Mitglied (Auskunft nach Art. 15 DSGVO, Löschung), falls die Prüfung das verlangt.
- Die Vorlagen liegen **im Kern**, damit eine Änderung über ein Update alle Kompanien erreicht.

**Schritte:** Prüfung beauftragen (vor der ersten neuen Kompanie) → Vorlagen schreiben → in Phase 5 einbauen.

---

## 4. Statusmeldungen

### Vorschlag: kleiner Empfänger auf unserem eigenen Webspace

- Adresse z. B. `https://status.<unsere-domain>/` auf normalem PHP-Webspace. Kein eigener Server, kein Cloud-Dienst.
- Gebaut **mit dem Kern selbst** (SQLite, Benutzerverwaltung, Updates). Er wird also wie eine Kompanie installiert und aktualisiert.
- Die Server **senden** einmal täglich, nur wenn die Kompanie zugestimmt hat. „Holen“ durch uns ginge nicht ohne zusätzliche Zugangsdaten auf jedem Server.

### Inhalt einer Meldung (abschließend)

| Feld | Beispiel |
|---|---|
| Installations-ID (zufällig, bei der Installation erzeugt) | `9f3c…` |
| Kompanie (Repo-Name) | `kompanie-musterstadt` |
| Variante, Datenbank | `standalone`, `sqlite` |
| Version, Ring | `1.4.0.3`, `stable` |
| PHP-Version, Webserver | `8.3`, `nginx` |
| letztes Update: Zeit, Ergebnis | `2026-11-02`, `ok` |
| letzter Galerie-Abgleich, Kalenderabruf: Ergebnis | `ok` / Fehlercode |
| Fehlercodes der letzten 24 h (ohne Texte, ohne Daten) | `GALLERY_403` |

**Keine** Namen, keine Mitgliederzahlen, keine Inhalte. IP-Adressen speichert der Empfänger nicht; der Hoster protokolliert sie trotzdem in seinen Server-Logs, das gehört in die Datenschutzerklärung.

- **Aufbewahrung:** 90 Tage, danach automatisch gelöscht.
- **Signiert** mit einem Schlüssel je Installation, damit niemand Meldungen fälschen kann.
- **Übersicht** für uns: Ampel je Kompanie, Version, Ring.
- **Warnung per E-Mail an uns**, wenn eine Kompanie länger als 2 Tage rot ist oder ein Release in `early` Fehler häuft. Das füttert die Beförderung aus Punkt 1.
- Die Zustimmung wird im Einrichtungsassistenten erfragt und mit Datum gespeichert; abschaltbar unter *Einstellungen*.

**Schritte:** Meldung im Kern (Phase 5) → Empfänger und Übersicht (Phase 6). *Braucht:* Subdomain auf unserem Webspace.

---

## 5. Galerie-Test: „Mit Google verbinden“

Ziel: klären, ob die App mit der Berechtigung `drive.file` und einer Ordnerauswahl über den Google Picker dauerhaft an alle Bilder eines Ordners kommt. Nur dann gibt es „Mit Google verbinden“ ohne Googles Sicherheitsbewertung.

### Was wir bereitstellen

1. Google-Cloud-Projekt (unseres, nicht das einer Kompanie)
2. dort einschalten: **Google Drive API** und **Google Picker API**
3. OAuth-Zustimmungsbildschirm: Typ „Extern“, Status „Testing“, Test-Google-Konto als Testnutzer eintragen
4. OAuth-Client vom Typ **Webanwendung**, Rücksprungadresse auf die Testseite
5. API-Schlüssel, beschränkt auf die Picker API
6. Ein **Test-Google-Konto** mit dieser Ordnerstruktur:
   ```
   Galerie/
     2025/Sommerfest/   3 Bilder
     2025/Sommerfest/intern/   2 Bilder
     2026/Winterball/   2 Bilder
   ```

Client-ID, Client-Secret und API-Schlüssel gebt ihr mir **nicht im Chat**, sondern tragt sie als Umgebungsvariablen in die Umgebung dieser Sitzung ein (Anleitung bei Bedarf).

### Was ich baue

Eine kleine Testseite (PHP + Picker) auf einem Testserver:
1. „Mit Google verbinden“ → Ordner `Galerie` auswählen
2. Der Server liest danach **ohne** Browser, nur mit dem gespeicherten Token, rekursiv alle Ordner und Bilder.
3. Ergebnis als Tabelle.

### Testfälle

| Nr. | Test | Bestanden, wenn |
|---|---|---|
| T1 | Bilder direkt in `Galerie/…` | alle gelistet und herunterladbar |
| T2 | Unterordner Jahr → Album | rekursiv gelistet |
| T3 | Unterordner `intern` | gelistet |
| T4 | **Nach** dem Verbinden ein neues Bild in `2026/Winterball` hochladen (im Drive im Browser, nicht über die App) | beim nächsten Abgleich gelistet |
| T5 | **Nach** dem Verbinden einen neuen Album-Ordner anlegen | gelistet |
| T6 | Bild löschen | verschwindet aus der Liste |
| T7 | Token nach einigen Tagen ohne Browser erneuern | funktioniert weiterhin |
| T8 | Anmeldung über eine **feste Rücksprungseite** (GitHub Pages), die an einen anderen Server weiterleitet | funktioniert, `state` wird geprüft |

### Entscheidung danach

- **T1–T7 bestanden:** „Mit Google verbinden“. Danach Googles vereinfachte Prüfung beantragen. Dafür braucht es: Startseite und Datenschutzerklärung auf einer **bestätigten Domain** von uns, App-Name, Logo, Support-Adresse.
- **T4 oder T5 nicht bestanden:** Ersatzlösung „Ordner freigeben“ an ein Dienstkonto je Kompanie (siehe [Architektur, Abschnitt 9](ARCHITEKTUR.md#9-google-galerie-und-kalender)). Dafür testen wir dann: Dienstkonto anlegen, öffentlichen Schlüssel hochladen (ob die Organisationsrichtlinien das erlauben), Freigabe prüfen.
- **T7 nicht bestanden:** klären, ob das nur am Status „Testing“ liegt, bevor wir verwerfen.

---

## 6. Branches und PHP-Version

### Standard-Branch

Weil das Repo leer war, ist mein Arbeits-Branch zum Standard-Branch geworden. Plan:

1. Branch `main` aus dem aktuellen Stand anlegen.
2. In GitHub unter *Settings → General → Default branch* auf `main` umstellen (braucht Admin-Rechte im Repo).
3. **Regeln für `main`** (*Settings → Rules*): nur über Pull Requests, CI muss grün sein.
4. Arbeits-Branches danach immer gegen `main`, jede Phase als eigener Pull Request.

Schritt 1 kann ich übernehmen, wenn ihr zustimmt. Die Schritte 2 und 3 macht ihr in den Einstellungen.

### PHP-Version

- **Lank:** In WordPress unter *Werkzeuge → Website-Zustand → Bericht → Server* steht die PHP-Version. Bitte nachsehen.
- **Neue Kompanien:** Der Installer prüft die Version ohnehin. Bis zur ersten neuen Kompanie bleibt PHP ≥ 8.1 die Mindestversion.
- **Laufend:** Die Statusmeldungen (Punkt 4) zeigen, welche PHP-Versionen im Einsatz sind. Eine Mindestversion heben wir erst an, wenn keine zustimmende Kompanie mehr darunter liegt, und nur mit einer neuen Hauptversion.
