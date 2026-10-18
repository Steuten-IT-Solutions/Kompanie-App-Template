# Architektur der Kompanie-App

Dieses Repo ist der **gemeinsame Kern** der Kompanie-App. Jede Kompanie bekommt in unserer GitHub-Organisation ein eigenes, kleines Repo. Darin stehen nur Name, Logo, Farben und ein paar Texte. Alles andere passiert automatisch: Build, Icons, Prüfungen, Releases und Updates auf den Servern der Kompanien.

Die erste Umsetzung war eine App für eine einzelne Kompanie (Repo `Marinekompanie-Lank-App`). Ihre Funktionen werden hier herausgelöst, **neutral gestaltet** und von WordPress getrennt. Die Umstellung dieser Kompanie wird getrennt geplant (siehe [Abschnitt 15](#15-umstellung-der-ersten-kompanie-lank)).

## 1. Leitlinien

1. **Einrichtung so einfach wie möglich.** Für eine neue Kompanie tragen wir nur Name, Logo, Farben und Texte ein. Was sich berechnen, prüfen oder automatisieren lässt, wird automatisiert.
2. **Jede Kompanie erkennt sich in ihrer App wieder.** Die App selbst ist neutral: keine Motive, Texte oder Farben einer bestimmten Kompanie. Erkennbar wird sie durch Logo, Farben, Name und Bezeichnungen der jeweiligen Kompanie.
3. **Läuft überall.** Normaler Webspace bei jedem gängigen Hoster, mit oder ohne WordPress, mit oder ohne eigene Datenbank, Apache oder nginx, Domain oder Unterordner.
4. **Updates sind in jedem Fall einfach.** Normalerweise automatisch; wenn das nicht geht, mit einem Klick; als letzter Ausweg mit derselben Datei wie bei der Installation. Jede Kompanie kann auf eine ältere Version zurückgehen.
5. **Kein technisches Wissen nötig.** Die App erklärt in Alltagssprache, was zu tun ist. Begriffe wie Cron, OAuth, PHP oder Datenbank tauchen in der Oberfläche nur auf, wenn es nicht anders geht, und dann mit Erklärung.

## 2. Entscheidungen

| Frage | Entscheidung |
|---|---|
| Wie kommen Updates zu den Kompanien? | Gemeinsamer Kern mit Versionen. Kern-Releases bauen alle Kompanie-Repos automatisch neu. |
| Kompanie-Repos | Ein Repo pro Kompanie in unserer Organisation, **öffentlich** (enthält keine Geheimnisse). |
| Wer hostet? | Jede Kompanie selbst, auf ihrem eigenen Webspace. |
| WordPress? | Optional. **WordPress-Plugin** und **eigenständige Version** auf demselben Kern, beide werden jetzt gebaut. |
| Datenspeicher | **SQLite** (legt der Installer selbst an, Standard ohne WordPress) und **MySQL/MariaDB** (WordPress oder auf Wunsch). Kein eigenes Dateiformat (siehe [Abschnitt 6](#6-datenspeicher-und-konnektoren)). |
| Installation | **Web-Installer** (`install.php`) für die Kompanien, **Formular „Neue Kompanie“** für uns. |
| Updates | **Automatisch**, signiert, abschaltbar. **Zurückrollen auf eine ältere Version** ist jederzeit möglich. |
| Mitglieder | Werden **nur vom Admin** angelegt (wie heute). |
| Statusmeldungen an uns | **Nur mit Zustimmung** der Kompanie. |
| Galerie (Google Drive) | Erst ein Test-Prototyp für „Mit Google verbinden“, sonst Ordnerfreigabe an ein Dienstkonto je Kompanie. |
| Erste Kompanie (Lank) | Wird umgestellt, aber in einem eigenen, später geplanten Schritt. |

Technische Rahmenbedingungen:

- **Server: nur PHP.** Keine Node.js, kein Docker, kein Shell-Zugang nötig. Node.js braucht es nur beim Build in GitHub Actions.
- **Keine Laufzeit-Abhängigkeiten über Composer.** Der Kern bringt alles selbst mit. Das fertige ZIP enthält alles.
- **PHP ≥ 8.1** als Mindestversion für die Kompatibilität. PHP 8.1 bekommt seit Ende 2025 keine Sicherheitsupdates mehr; die App empfiehlt deshalb eine aktuelle Version. *Offen:* prüfen, welche Versionen die Webspaces der ersten Kompanien anbieten.
- **PHP-Erweiterungen:** Pflicht sind nur `openssl`, `sodium` (Signaturprüfung), `zip` (Updates), `json`. Ohne WordPress `pdo_sqlite` (gehört zum Standardumfang von PHP) oder `pdo_mysql`; für Bilder `gd` **oder** `imagick`; für Abrufe `curl` **oder** `allow_url_fopen`. Der Installer prüft alles und wählt die Alternative selbst.

## 3. Die Abläufe

### Neue Kompanie (wir)

1. Im Kern-Repo das Formular **„Neue Kompanie“** ausfüllen: Name, Kurzname, Hauptfarbe, optional Akzentfarbe, Begrüßung, Bezeichnungen. Logo per Drag & Drop anhängen (PNG, JPG oder SVG, beliebiges Seitenverhältnis).
2. Ein Workflow prüft alles (Farbformat, Kontrast, Logo-Größe), legt das Repo aus der Vorlage an, schreibt `kompanie.json`, legt das Logo ab und setzt das Topic `kompanie-app`.
3. Der Build erzeugt Farben, Icons, das signierte Paket, Screenshots und eine **Download-Seite** der Kompanie (GitHub Pages).
4. Der Workflow antwortet im Formular mit Screenshots und dem Link zur Download-Seite. Diesen Link bekommt die Kompanie.

Spätere Änderungen (neues Logo, andere Farbe) laufen über das Formular **„Kompanie ändern“**. Die Screenshots hängen auch dort an.

### Installation (Kompanie)

Die Download-Seite der Kompanie zeigt zwei große Knöpfe mit bebilderter Anleitung:

- **„Wir haben eine WordPress-Webseite“:** Plugin-Datei herunterladen, in WordPress unter *Plugins → Installieren → Plugin hochladen* auswählen, aktivieren. Kein FTP nötig.
- **„Wir haben keine WordPress-Webseite“:** `install.php` herunterladen und auf den Webspace laden (über den Dateimanager des Hosters oder FTP), im Browser öffnen. Der Installer erledigt den Rest.

Danach führt der **Einrichtungsassistent** durch das, was nur die Kompanie wissen kann: erster Admin (nur ohne WordPress), Kalender-Link, Galerie. Jeder Schritt wird sofort geprüft. **Eine Datenbank muss die Kompanie nicht anlegen**: Der Installer wählt den Datenspeicher selbst.

Das Hochladen einer Datei auf den Webspace ist der einzige technische Schritt ohne WordPress. Dafür gibt es **Anleitungen für die gängigen Hoster** (z. B. IONOS, Strato, all-inkl, netcup, Hetzner) mit Bildern. *Offen:* Ob wir das für Kompanien übernehmen, die es nicht selbst können (siehe [Offene Punkte, Abschnitt 2](OFFENE-PUNKTE.md#2-hilfe-beim-hochladen-von-installphp)).

### Update (niemand)

1. Wir veröffentlichen ein Kern-Release.
2. Alle Kompanie-Repos bauen automatisch neu und veröffentlichen ein Release.
3. Die Server holen sich das Update selbst, prüfen Signatur und Voraussetzungen, sichern die Daten, spielen es ein und führen die Migrationen aus.

Was passiert, wenn etwas davon nicht geht, steht in [Abschnitt 11](#11-updates-und-zurückrollen).

## 4. Überblick

```
                ┌──────────────────────────────────────────┐
                │  Frontend (React-PWA, neutral)           │
                │  Branding aus kompanie.json beim Build   │
                └───────────────────┬──────────────────────┘
                                    │  HTTP: api/openapi.yaml
             ┌──────────────────────┴───────────────────────┐
┌────────────┴─────────────┐                ┌───────────────┴───────────┐
│ Adapter WordPress        │                │ Adapter Standalone         │
│ (Plugin)                 │                │ (index.php)                │
│ WP-Login, Options,       │                │ eigene Benutzer, Sitzung,  │
│ WP-Cron, Shortcodes      │                │ Pseudo-Cron, Installer     │
└────────────┬─────────────┘                └───────────────┬───────────┘
             └──────────────────────┬───────────────────────┘
                ┌───────────────────┴──────────────────────┐
                │  Kern (PHP, ohne WordPress)              │
                │  Punkte, Strafen, Katalog, Termine,      │
                │  Galerie, Kalender, News, Rechte,        │
                │  Routen, Updater, Sicherung, Status      │
                └───────────────────┬──────────────────────┘
                                    │  Repositories
        ┌──────────────────┬────────┴─────────┬──────────────────┐
   MySQL ($wpdb)      MySQL (PDO)        SQLite (PDO)
   (WordPress)        (Standalone)       (Standalone, Standard)
```

Grundregel: **Im Kern steht kein einziger WordPress-Aufruf.** Alles, was von der Umgebung abhängt, läuft über eine Schnittstelle in `packages/core/src/Port/`. `scripts/check-core-boundary.sh` sichert das in der CI ab.

## 5. Neutral und erkennbar

### Was aus der ersten Umsetzung entfernt wird

Das Frontend der ersten Umsetzung ist auf eine Marinekompanie zugeschnitten. Für den Kern wird es neutral gestaltet:

| Fundstelle | Heute | Künftig |
|---|---|---|
| `styles.css` | „Maritim-traditionelles Design“, Farbvariablen `--navy`, `--brass`, `--wood` (54 Verwendungen) | neutrale Variablen (`--primary`, `--accent`, …), berechnet aus `kompanie.json` |
| `styles.css` | Anker als Wasserzeichen in den Kennzahlen | das **Logo der Kompanie** als Wasserzeichen |
| `assets/logo.png`, Icons | Logo der Kompanie | aus `kompanie.json`/`assets/` |
| `App.tsx` | „Ahoi, …“, Name fest | `greeting` und `name` aus `kompanie.json` |
| `Installieren.tsx` | Bilder mit Name und Domain der Kompanie | Name aus `kompanie.json`, Adresse aus dem Browser |
| `ics.ts` | Kennung und Domain der Kompanie im Kalender-Export | aus Name und Adresse der Installation |
| `index.html`, Manifest | Titel fest | aus `kompanie.json` |
| `main.tsx` | Schrift Oswald fest eingebunden | Schrift aus der Auswahl in `kompanie.json` |
| Tests, Beispieldaten | Begriffe und Orte der Kompanie | neutrale Beispieldaten („Musterkompanie“) |

Eine **Neutralitätsprüfung** in der CI stellt sicher, dass in App, Kern, Adaptern und Installer keine Namen, Motive oder Begriffe einer bestimmten Kompanie auftauchen (`scripts/check-neutral.sh`).

### Womit sich eine Kompanie wiederfindet

- Logo (Kopfzeile, Icons, Wasserzeichen, Anmeldeseite)
- Farben (alle Flächen, Knöpfe, Dunkelmodus)
- Name und Kurzname (Kopfzeile, Fenstertitel, Home-Bildschirm, Installationsanleitung)
- Begrüßung, Willkommenstext für Gäste
- **Bezeichnungen:** Rollen (z. B. „Spieß“, „Kassierer“, „Hauptmann“) und Bereiche (z. B. „Punkte“ → „Orden“, „Strafen“ → „Kasse“)
- welche Bereiche es gibt

## 6. Datenspeicher und Konnektoren

Der Kern spricht **nicht direkt mit SQL**, sondern mit **Repositories** (z. B. `PointRepository`, `PenaltyRepository`, `EventRepository`, `GalleryRepository`, `UserRepository`, `SettingsStore`, `SessionStore`). Es gibt **eine** SQL-Umsetzung dieser Repositories mit einem kleinen Dialekt-Baustein (Tabellenanlage, Upsert, Datumsfunktionen). Sie läuft über drei Verbindungen:

| Verbindung | Für wen | Einrichtung durch die Kompanie |
|---|---|---|
| **MySQL über `$wpdb`** | WordPress-Plugin | keine (nutzt die WordPress-Datenbank) |
| **SQLite** | Standalone, **Standard** | keine: der Installer legt die Datenbank-Datei selbst an |
| **MySQL/MariaDB über PDO** | Standalone, auf Wunsch | Datenbank im Kundenmenü des Hosters anlegen, Zugangsdaten eintragen; die Tabellen legt der Installer an |

- **Auswahl automatisch:** Der Installer nimmt SQLite. MySQL nur auf ausdrücklichen Wunsch (z. B. weil der Hoster Sicherungen nur für MySQL anbietet).
- **Warum der Installer keine MySQL-Datenbank anlegt:** Bei gemieteten Webspaces geht das in der Regel nur über das Kundenmenü des Hosters; der Datenbank-Benutzer darf keine neuen Datenbanken erstellen.
- **Fehlt `pdo_sqlite`** (selten), erklärt der Installer, wie man es beim Hoster einschaltet oder eine MySQL-Datenbank nutzt. Ein eigenes Dateiformat (z. B. JSON-Dateien) gibt es bewusst nicht. SQLite ist bereits „eine Datenbank in einer Datei“, mit sicherem gleichzeitigem Schreiben und Schutz vor halb geschriebenen Daten. Durch die Repository-Schicht bliebe ein weiterer Speicher später trotzdem möglich.
- **SQLite und viele Nutzer:** Für die Zugriffe einer Kompanie (wenige gleichzeitige Schreibvorgänge) reicht SQLite. Die Verbindung nutzt den WAL-Modus und eine Wartezeit bei Sperren.
- **Ein gemeinsames Export-Format** (JSON) für beide Datenbanken. Es dient als Datensicherung und zum **Wechsel** (z. B. von SQLite zu MySQL oder von WordPress zu Standalone).
- **Schutz der Daten:** Die SQLite-Datei liegt außerhalb des Web-Verzeichnisses, wenn der Hoster das erlaubt. Sonst gibt es eine Sperre per `.htaccess` **und** einen Selbsttest, ob die Datei von außen wirklich nicht erreichbar ist (nginx wertet `.htaccess` nicht aus). Schlägt der Test fehl, installiert der Installer nicht und erklärt, was zu tun ist.
- **Contract-Tests je Repository** laufen gegen MySQL/MariaDB und SQLite.
- **Migrationen** für beide Dialekte. Für MySQL entspricht die erste Migration genau dem Schema der ersten Umsetzung in Version 3, damit Lank seine Daten behält.

## 7. Der Kern und seine Schnittstellen

Neben den Repositories bekommt der Kern diese Ports von außen:

| Port | Zweck | WordPress | Standalone |
|---|---|---|---|
| `AuthContext` | aktueller Benutzer mit Rolle und Rechten | WP-Benutzer + Capabilities | eigene Sitzung |
| `UserDirectory` | Benutzer anlegen, Rolle setzen, Passwort | WP-Benutzer | `UserRepository` |
| `Cache` | Zwischenspeicher mit Ablaufzeit | Transients | Repository |
| `HttpClient` | Google, iCal, News, Updates | `wp_remote_*` | cURL oder Streams |
| `FileStorage` | öffentliche und geschützte Dateien | Upload-Verzeichnis | `storage/` und `media/` |
| `ImageResizer` | Bildgrößen erzeugen | `wp_get_image_editor` | GD oder Imagick |
| `Scheduler` | wiederkehrende Aufgaben | WP-Cron | Pseudo-Cron, optional Cronjob |
| `NewsSource` | Neuigkeiten | eigene Beiträge | fremde WordPress-Seite, RSS oder keine |
| `Clock` | Zeit und Zeitzone | `wp_timezone` | Europe/Berlin |

**Lange Aufgaben in Häppchen.** Auf Webspace bricht PHP oft nach 30 Sekunden ab. Der Galerie-Abgleich arbeitet mit einem Zeitbudget pro Aufruf und setzt beim nächsten fort. Der Pseudo-Cron läuft nach dem Absenden der Antwort, wo der Server das unterstützt, damit Besucher nicht warten. **Ein Cronjob beim Hoster ist nicht nötig**, macht den Abgleich aber zuverlässiger; der Assistent bietet ihn nur als Zusatz an.

### Rechte und Rollen

| Recht | Bedeutung |
|---|---|
| `member` | eigene Punkte/Strafen, interner Kalender, interne Galerie |
| `manage` | Punkte und Strafen vergeben, Katalog pflegen |
| `manage_events` | öffentliche Termine pflegen |
| `create_users` | Mitglieder anlegen, Startpasswörter erzeugen |
| `promote_users` | Rollen vergeben |
| `manage_settings` | Einstellungen, Updates, Sicherung (bei WordPress im WP-Admin) |

Rollen: `guest`, `member`, `manager`, `admin`. Die Bezeichnungen kommen aus `kompanie.json`.

- **WordPress:** Admin ist, wer WP-Administrator ist. Admin-Rechte werden nur im WP-Backend vergeben.
- **Standalone:** Admins können andere zu Admins machen. Der **letzte Admin kann nicht herabgestuft** werden, niemand ändert seine eigene Rolle.
- **Mitglieder** legt nur ein Admin an (Startpasswort, nur einmal angezeigt). Vorschlag zur Erleichterung: mehrere Namen auf einmal eintragen und die Zugangsdaten als **Zettel zum Ausdrucken** ausgeben.

Die `/me`-Routen lesen die Benutzer-ID immer aus der Sitzung. Stornierte Einträge werden nur ausgeblendet; gespeichert wird, wer sie angelegt hat.

### Routen

Der Kern enthält **eine Routentabelle**. WordPress registriert sie unter `/wp-json/kompanie-app/v1/` (bei einfachen Permalinks über `?rest_route=`), Standalone unter `/api/v1/` und **ohne URL-Umschreibung** über `index.php/api/v1/…`. Ist ein Bereich ausgeschaltet, sind auch seine Routen gesperrt.

## 8. Frontend

| Heute (an WordPress gebunden) | Künftig |
|---|---|
| Konfiguration als `window.MKL_CONFIG` | dieselben Daten wie `GET /config`, direkt in die `index.html` geschrieben |
| `X-WP-Nonce`, Erneuerung über `admin-ajax.php` | Header-Name und Token aus `/config`, Erneuerung über `GET /auth/csrf` |
| Anmelden über `wp-login.php` | `auth.mode` aus `/config`: `form` oder `redirect` |
| `X-WP-TotalPages` | `X-Total-Pages` |
| Service Worker kennt den API-Pfad fest | API-Pfad beim Registrieren übergeben |
| Build im Plugin-Ordner | Build nach `dist/`, das Paket-Skript verteilt es |

Die App setzt **HTTPS** voraus (sonst funktioniert die Installation aufs Handy nicht). Der Installer prüft das und erklärt, wie man beim Hoster ein kostenloses Zertifikat einschaltet.

## 9. Google: Galerie und Kalender

### Kalender

Standard ist die **geheime iCal-Adresse** des Google Kalenders. Das ist ein Link zum Einfügen, ohne Google Cloud. Google-Kalender-ID mit API-Key bleibt als Option.

### Galerie

Gesucht ist ein Weg, bei dem die Kompanie kein Google-Cloud-Projekt braucht. Zwei Varianten, die Entscheidung fällt nach einem **Test-Prototyp** (Testfälle: [Offene Punkte, Abschnitt 5](OFFENE-PUNKTE.md#5-galerie-test-mit-google-verbinden)):

**Ziel: „Mit Google verbinden“** (OAuth, Berechtigung `drive.file`, Ordnerauswahl über den Google Picker)

- `drive.file` gilt bei Google als nicht sensibel. Die App braucht nur die vereinfachte Prüfung, keine Sicherheitsbewertung. Die App sieht nur den gewählten Ordner, nicht das ganze Drive.
- Ein gemeinsamer OAuth-Client in unserem Google-Cloud-Projekt. Die Anmeldung läuft über eine **feste Rücksprungseite** (statisch, z. B. GitHub Pages) mit signiertem `state`, die an den Server der Kompanie weiterleitet. Dadurch muss nicht jede Kompanie-Domain bei Google eingetragen werden.
- **Offen, Gegenstand des Tests:** Ob die App mit `drive.file` an Dateien **in** einem gewählten Ordner kommt, in Unterordnern (Jahr → Album → `intern`) und vor allem an **später hochgeladene Bilder**. Die Berichte dazu widersprechen sich, eine offizielle Aussage von Google fehlt.
- Verworfen: `drive.readonly`. Diese Berechtigung ist „restricted“ und braucht eine Sicherheitsbewertung; ohne Prüfung gibt es einen Warnbildschirm und eine Grenze von 100 Nutzern.

**Ersatz: Ordner freigeben** (falls der Test scheitert)

- Ein Workflow legt pro Kompanie ein Dienstkonto in unserem Google-Cloud-Projekt an.
- Der Server der Kompanie erzeugt sein Schlüsselpaar selbst. Nur der **öffentliche** Schlüssel wird beim Dienstkonto hinterlegt. Der private Schlüssel verlässt den Server nie.
- Die Kompanie gibt ihren Galerie-Ordner für die angezeigte E-Mail-Adresse frei. Der Assistent prüft live, ob die Freigabe da ist.
- *Zu prüfen:* Neuere Google-Cloud-Organisationen sperren das Hochladen von Schlüsseln teilweise per Voreinstellung. Ein Administrator kann diese Sperre aufheben.

Verworfen: ein **gemeinsames Dienstkonto für alle** (ein kompromittierter Server könnte die Ordner aller Kompanien lesen) und ein **zentraler Galerie-Dienst** bei uns (wir würden die internen Fotos aller Kompanien verarbeiten; das bedeutet Auftragsverarbeitung und eigenen Betrieb).

## 10. Kompanie-Konfiguration und automatisches Branding

`kompanie.json` (Schema: [`config/kompanie.schema.json`](../config/kompanie.schema.json), Beispiel: [`config/kompanie.example.json`](../config/kompanie.example.json)). **Pflicht sind nur `name` und `colors.primary`**, dazu ein Logo. Alles andere hat Standardwerte.

| Eingabe | Automatisch daraus |
|---|---|
| `colors.primary`, optional `colors.accent` | alle Farbabstufungen und der Dunkelmodus; Kontrastprüfung nach WCAG (mindestens 4,5 : 1 für Text), bei Bedarf automatische Aufhellung/Abdunklung der Textfarbe; Abbruch mit verständlicher Meldung nur, wenn das nicht reicht |
| `assets/logo.png`, `.jpg` oder `.svg` | Freistellung auf quadratisch mit passendem Rand, App-Icons 192/512 px, Android-Icon mit Sicherheitsabstand, Apple-Icon, Favicon, Wasserzeichen. Warnung bei zu kleinem Logo |
| `name`, `shortName` | Fenstertitel, Manifest, Name unter dem App-Symbol, Installationsanleitung |
| Repo-Name | technischer Name, Dateinamen, Update-Adresse, Download-Seite |

Schriften nur aus einer **mitgelieferten Auswahl** (Lizenzen geklärt, kein Abruf bei Google).

**Zugangsdaten** gehören nie in `kompanie.json` oder ins Repo; die Kompanie-Repos sind öffentlich.

**Rechtstexte:** Felder `legal.imprintUrl`/`legal.privacyUrl` oder Dateien `legal/impressum.md`/`legal/datenschutz.md`. *Ob die App ein eigenes Impressum braucht und was in die Datenschutzerklärung gehört, muss rechtlich geprüft werden.* Danach liegen Vorlagen im Kern, die die App mit ihren Daten füllt (siehe [Offene Punkte, Abschnitt 3](OFFENE-PUNKTE.md#3-rechtstexte-und-datenschutz)).

**Vorschau:** Die CI jedes Kompanie-Repos startet die App mit Beispieldaten und hängt Screenshots (hell/dunkel) an Pull Requests und Releases.

## 11. Updates und Zurückrollen

### Versionen

- Kern: SemVer, Tag `v1.2.3`, beweglicher Tag `v1`.
- Kompanie: Kern-Version plus laufende Build-Nummer, z. B. `1.4.0.3`. Auch eine Logo-Änderung wird so als Update ausgeliefert.
- Jedes Release enthält `release.json`: Version, Mindest-PHP-Version, nötige Erweiterungen, ob Migrationen dabei sind, Signatur.
- Ein Kern-Release startet **„Alle Kompanien neu bauen“** (über das Topic `kompanie-app`, mit einer GitHub App der Organisation).

### Signatur

Jedes Paket wird mit **Ed25519** signiert (privater Schlüssel als Organisations-Secret, öffentliche Schlüssel im Kern). Installer und Updater prüfen mit `sodium` vor dem Entpacken. Schlüsselwechsel über eine Liste erlaubter Schlüssel.

### Ein Update in jedem Fall einspielen

| Situation | Was passiert |
|---|---|
| Normalfall | automatisch, ohne Zutun |
| automatische Updates abgeschaltet (von der Kompanie, vom Hoster oder in WordPress gesperrt) | Hinweis für Admins in der App: **„Jetzt aktualisieren“**, ein Klick |
| WordPress verlangt FTP-Zugangsdaten zum Schreiben | Hinweis mit Erklärung; als Ausweg die Plugin-Datei über *Plugin hochladen* ersetzen |
| Server erfüllt die Voraussetzungen der neuen Version nicht (z. B. PHP zu alt) | Update wird **nicht** eingespielt, die App läuft weiter. Admins sehen in Alltagssprache, was beim Hoster umzustellen ist, mit Anleitung je Hoster |
| Update scheitert mittendrin | automatische Rückkehr zur vorigen Version, Hinweis für Admins |
| nichts davon klappt | `install.php` erneut hochladen und öffnen: Er erkennt die bestehende Installation und bietet **„Aktualisieren“** und **„Reparieren“** an |
| neue Hauptversion | nie automatisch; Admins sehen eine kurze Erklärung und aktualisieren mit einem Klick |

**Dateirechte:** Bei manchen Hostern kann PHP Dateien nicht überschreiben, die per FTP hochgeladen wurden. Deshalb lädt bei der eigenständigen Version der **Installer** alle Dateien selbst herunter. So gehören sie dem PHP-Benutzer, und spätere Updates funktionieren.

### Zurückrollen

Jede Kompanie kann in der App unter *Einstellungen → Updates* auf eine **ältere Version** zurückgehen, mit einem Klick und ohne Datenverlust:

- **Innerhalb einer Hauptversion ändern Migrationen nur additiv:** neue Tabellen oder Spalten, nie Umbenennen oder Löschen. Ältere `1.x`-Versionen laufen deshalb auch mit dem neueren Datenbestand. Zurückrollen heißt nur, den Programmcode zu tauschen. Die CI prüft das: Die Contract-Tests der vorigen Version laufen gegen den Datenbestand der neuen.
- Der Server behält die **letzten drei Versionen**. Ältere lädt er bei Bedarf signiert nach.
- Nach dem Zurückrollen **überspringt** das automatische Update diese Version, bis eine neuere erscheint.
- **Über eine Hauptversion zurück** geht nur mit der Sicherung, die vor dem Update automatisch angelegt wurde. Die App sagt deutlich, dass Einträge seit dem Update dann verloren gehen.

### Sicherung

- Vor jedem Update mit Migrationen: automatische Sicherung im Export-Format.
- Admins können jederzeit **„Datensicherung herunterladen“** und eine Sicherung wieder einspielen.
- Die App erinnert Admins regelmäßig an eine Sicherung, wenn länger keine heruntergeladen wurde.

### Update-Liste und gestaffelte Verteilung

- Die Server fragen **nicht die GitHub-API** (Limit von 60 Anfragen pro Stunde je IP-Adresse; auf Webspace teilen sich viele Seiten eine Adresse). Stattdessen liest jeder Server einmal täglich die **signierte Update-Liste** `updates.json` seiner Kompanie von GitHub Pages.
- **Zwei Ringe:** `early` (Demo-Installation, Testkompanien) bekommt ein Release sofort, `stable` nach einer Haltezeit, wenn nichts gestoppt wurde. Sicherheitskorrekturen gehen sofort an alle.
- Eine **zurückgezogene Version** rollen die Server automatisch zurück.

Einzelheiten: [Offene Punkte, Abschnitt 1](OFFENE-PUNKTE.md#1-update-verteilung-und-gestaffelte-releases).

## 12. Installation und Einrichtung

### Web-Installer (`installer/install.php`)

Eine einzelne Datei, auf die Kompanie zugeschnitten (Repo-Name und Signaturschlüssel sind eingesetzt).

1. **Voraussetzungen prüfen**: PHP-Version, Erweiterungen, Schreibrechte, HTTPS, Speicherplatz, ausgehende Verbindungen. Jedes Problem wird in Alltagssprache erklärt, mit Anleitung für den jeweiligen Hoster (erkannt am Server, wenn möglich).
2. **WordPress erkennen.** Liegt WordPress daneben, wird nach Anmeldung eines WP-Administrators das Plugin installiert. Sonst die eigenständige Version.
3. **Datenbank anlegen** (SQLite automatisch, siehe Abschnitt 6) und **Selbsttest** für geschützte Ordner.
4. **Release laden**, Signatur prüfen, entpacken. Funktioniert im Domain-Hauptverzeichnis und in einem Unterordner (`/app/`), mit und ohne URL-Umschreibung.
5. Übergabe an den **Einrichtungsassistenten**.
6. Der Installer **löscht sich selbst** (sonst sperrt er sich). Erneut hochgeladen dient er als Werkzeug zum Aktualisieren und Reparieren.

### Einrichtungsassistent (beide Varianten)

1. erster Admin (nur Standalone)
2. Kalender: geheime iCal-Adresse einfügen (mit bebilderter Anleitung, wo man sie in Google findet), Testabruf
3. Galerie: „Mit Google verbinden“ bzw. Ordnerfreigabe, Live-Prüfung
4. News (nur Standalone): Adresse einer vorhandenen Webseite oder überspringen
5. optional: Statusmeldungen an uns erlauben (siehe Abschnitt 13)

Jeder Schritt lässt sich überspringen und später unter *Einstellungen* nachholen.

## 13. Pflege ohne technisches Wissen

- **Statusseite für Admins** mit Ampel in Alltagssprache: Updates, Galerie-Abgleich, Kalender, letzte Sicherung, Server. Bei Gelb/Rot steht dabei, was zu tun ist, oder „Bitte melde dich bei …“ mit unseren Kontaktdaten.
- **Hinweise direkt in der App** für Admins, statt E-Mails (E-Mail-Versand ist auf Webspace oft unzuverlässig).
- **Statusmeldungen an uns, nur mit Zustimmung:** Version, PHP-Version, Ergebnis des letzten Updates und Abgleichs, Fehlercodes. Keine Mitgliederdaten. Damit können wir Kompanien helfen, bevor sie ein Problem bemerken. Gehört in die Datenschutzerklärung; abschaltbar. Einzelheiten: [Offene Punkte, Abschnitt 4](OFFENE-PUNKTE.md#4-statusmeldungen).
- **Hilfe-Texte** an jeder Einstellung; Fachbegriffe nur mit Erklärung.

## 14. Die beiden Adapter

### WordPress-Plugin (`adapters/wordpress`)

- Liefert die App unter `/app/` aus, inkl. geschützter Galeriebilder unter `/app/foto/…`.
- Zusätzliche WP-Rollen für Mitglieder und Verwalter, Einstellung im Benutzerprofil.
- Einstellungsseite im WP-Admin mit demselben Assistenten und derselben Statusseite.
- Shortcodes `[kompanie_termine]` und `[kompanie_galerie]`.
- „Angemeldet bleiben“ 400 Tage mit automatischer Verlängerung.
- **Verträglich mit typischen WordPress-Umgebungen:** App-Seite und API senden „nicht zwischenspeichern“ an Cache-Plugins und CDNs; der Assistent erkennt, wenn ein Sicherheits-Plugin die REST-API sperrt, und erklärt die Freigabe. Nur Einzelseiten, kein Multisite (vorerst).

### Eigenständige Version (`adapters/standalone`)

- **Benutzer:** eigene Benutzer, Passwörter mit `password_hash`. Vergessene Passwörter setzt ein Admin neu (kein E-Mail-Versand nötig).
- **Sitzung:** Cookie `HttpOnly`, `Secure`, `SameSite=Lax`, Sitzungsschlüssel nur gehasht gespeichert. 400 Tage mit automatischer Verlängerung. Passwortänderung meldet andere Geräte ab.
- **Datenbank:** SQLite (legt der Installer an) oder MySQL/MariaDB.
- **Sicherheit:** Begrenzung fehlgeschlagener Anmeldungen, geschützte Dateien und Datenbank außerhalb des Web-Verzeichnisses oder gesperrt **und** geprüft, Sicherheits-Header.
- **Läuft auf** Apache, nginx und LiteSpeed, im Hauptverzeichnis oder Unterordner, mit oder ohne URL-Umschreibung.
- **Termine auf fremden Webseiten:** einbettbarer Ausschnitt `/embed/termine`. *Später.*

## 15. Umstellung der ersten Kompanie (Lank)

Wird **getrennt geplant**. Bis dahin gilt: **Neue Funktionen werden im Kern gebaut**; im Lank-Repo nur noch Fehlerkorrekturen, die auch in den Kern übernommen werden.

Festgehalten für die Planung:

- Lank nutzt das WordPress-Plugin des Kerns. Ihr eigenes Design kommt über ihr Kompanie-Repo: Farben, Logo, Begrüßung, Rollenbezeichnungen.
- MySQL-Konnektor mit einstellbarem Tabellenpräfix, erste Migration = Schema Version 3: Tabellen `wp_mkl_*` und Galerie-Dateien bleiben.
- Offene Punkte: API-Namensraum und installierte PWAs mit altem Service Worker, bisherige Rollen und Optionen, Shortcodes `[mkl_termine]`/`[mkl_galerie]` als Alias, Kalender über das Plugin „Private Google Calendars“, bestehende Anmeldungen sollen erhalten bleiben.

## 16. Tests

- **Unit-Tests** für den Kern (PHPUnit), mit Test-Umsetzungen der Ports.
- **Repository-Contract-Tests** gegen MySQL/MariaDB und SQLite.
- **API-Contract-Tests** gegen beide Adapter.
- **E2E-Tests** (Playwright) gegen beide Adapter.
- **Installer und Updater:** frische Installation (Apache und nginx, Hauptverzeichnis und Unterordner), Update, Zurückrollen, falsche Signatur, zu alte PHP-Version, abgebrochenes Update.
- **Rückwärtstest:** Contract-Tests der vorigen Version gegen den Datenbestand der neuen.
- **Branding:** Kontrastprüfung, Icon-Erzeugung, Screenshots.
- **Grenz- und Neutralitätsprüfung** in der CI.

## 17. Fahrplan

| Phase | Inhalt | Ergebnis |
|---|---|---|
| 0 | Architektur, API-Spezifikation, Konfigurationsschema, Schnittstellen | dieses Dokument und das Gerüst |
| G | **Test-Prototyp Galerie** (`drive.file` + Picker), parallel möglich | Entscheidung „Mit Google verbinden“ oder „Ordner freigeben“ |
| 1 | **Kern und WordPress-Adapter:** Das bestehende Plugin wird zum WordPress-Adapter, ein Bereich nach dem anderen zieht in den Kern (Repositories, MySQL-Konnektor). Bestehende Tests bleiben grün. | Plugin mit vollem Funktionsumfang auf dem Kern |
| 2 | **Frontend neutral und verallgemeinert:** `/config`, CSRF, Anmeldearten, Branding, Bezeichnungen, Neutralitätsprüfung | eine neutrale App für alle |
| 3 | **Eigenständige Version:** Benutzer, Sitzung, Einstellungen, SQLite und MySQL über PDO, Export/Import | eigenständige Version |
| 4 | **Branding und Build:** Farben, Icons, Signatur, Screenshots, `Kompanie-App-Vorlage`, Download-Seite | Kompanie-Repo baut signierte Pakete |
| 5 | **Installer, Assistent, Updates:** automatische Updates, Ein-Klick-Update, Zurückrollen, Sicherung, Statusseite | Installation und Pflege ohne Handarbeit |
| 6 | **Für uns:** Formulare „Neue Kompanie“/„Kompanie ändern“, „Alle Kompanien neu bauen“, Statusübersicht | neue Kompanie nur durch Eintragen |
| später | Hoster-Anleitungen erweitern, `/embed/termine`, Multisite | |
| – | Umstellung Lank | eigener Plan |

### Was wir dafür in der Organisation brauchen

- eine **GitHub App** zum Anlegen der Kompanie-Repos und Auslösen ihrer Builds
- einen **Ed25519-Signaturschlüssel** als Organisations-Secret
- ein **Google-Cloud-Projekt** mit OAuth-Client (Galerie-Test)
- ein leeres Repo **`Kompanie-App-Vorlage`**
- eine Subdomain auf unserem Webspace für den Empfang der **Statusmeldungen** (nur von Kompanien, die zustimmen)

Die Planung der offenen Punkte steht in [OFFENE-PUNKTE.md](OFFENE-PUNKTE.md).
