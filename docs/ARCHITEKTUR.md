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
| Handbuch | Eine Quelle in `docs/handbuch/`: in der App hinter „?“ (offline, passend zu Version, Rechten und Bereichen) und je Kompanie auf GitHub Pages unter `/handbuch/`, jeweils mit ihren Bezeichnungen. |
| Statusmeldungen an uns | **Nur mit Zustimmung** der Kompanie. |
| Galerie (Google Drive) | „Mit Google verbinden“ mit dem eigenen OAuth-Client der Installation (`drive.readonly`, `calendar.readonly`), alternativ Ordnerfreigabe an ein Dienstkonto; siehe Abschnitt 9. |
| Erste Kompanie (Lank) | Wird umgestellt, aber in einem eigenen, später geplanten Schritt. |

Technische Rahmenbedingungen:

- **Server: nur PHP.** Keine Node.js, kein Docker, kein Shell-Zugang nötig. Node.js braucht es nur beim Build in GitHub Actions.
- **Keine Laufzeit-Abhängigkeiten über Composer.** Der Kern bringt alles selbst mit. Das fertige ZIP enthält alles. Einzige Ausnahme ist PHPMailer (LGPL-2.1, Issue #172) für den Mailversand: Es steht in `composer.json`, `scripts/package.php` legt es aus `vendor/` ins Paket der eigenständigen Version (`src/lib/PHPMailer`, denn ältere Updater tauschen nur die Einträge aus `StandaloneUpdateTarget::PROGRAM`); deshalb läuft vor dem Bauen und Paketieren `composer install --no-dev` (der Build nimmt auch den Lizenztext für die Drittanbieter-Hinweise aus `vendor/`, `docs/LIZENZEN.md`). Das WordPress-Plugin nutzt die Kopie von WordPress (`wp-includes/PHPMailer`) und liefert keine eigene mit.
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

Danach führt der **Einrichtungsassistent** durch das, was nur die Kompanie wissen kann: erster Admin (nur ohne WordPress), welche Bereiche sie nutzt, Kalender-Link, Galerie. Jeder Schritt wird sofort geprüft. **Eine Datenbank muss die Kompanie nicht anlegen**: Der Installer wählt den Datenspeicher selbst.

Das Hochladen einer Datei auf den Webspace ist der einzige technische Schritt ohne WordPress. Dafür gibt es **Anleitungen und Kurzvideos für die gängigen Hoster** (z. B. IONOS, Strato, all-inkl, netcup, Hetzner). Eine Installation durch uns gibt es nicht (siehe [Offene Punkte, Abschnitt 2](OFFENE-PUNKTE.md#2-hilfe-beim-hochladen-von-installphp)).

### Update (niemand)

1. Wir veröffentlichen ein Kern-Release.
2. Das Kern-Repo baut daraus automatisch für jede Kompanie eine neue Version („Kompanien abgleichen“), zuerst für die Demo-Installation; nach dem Test geben wir sie für alle frei.
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

Welche Bereiche eine Kompanie nutzt, steht **nicht** in `kompanie.json`: Das entscheidet ihr Admin in der App (siehe Abschnitt 12, „Bereiche“).

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
- **SQLite und viele Nutzer:** Für die Zugriffe einer Kompanie (wenige gleichzeitige Schreibvorgänge) reicht SQLite. Die Verbindung nutzt den WAL-Modus mit `synchronous = NORMAL` (kein fsync je Commit; bei einem Stromausfall können die letzten Transaktionen fehlen, die Datei bleibt aber heil) und eine Wartezeit bei Sperren.
- **Ein gemeinsames Export-Format** (JSON) für beide Datenbanken. Es dient als Datensicherung und zum **Wechsel** (z. B. von SQLite zu MySQL oder von WordPress zu Standalone).
- **Schutz der Daten:** Die SQLite-Datei liegt außerhalb des Web-Verzeichnisses, wenn der Hoster das erlaubt. Sonst gibt es eine Sperre per `.htaccess` **und** einen Selbsttest, ob die Datei von außen wirklich nicht erreichbar ist (nginx wertet `.htaccess` nicht aus). Schlägt der Test fehl, installiert der Installer nicht und erklärt, was zu tun ist.
- **Contract-Tests je Repository** laufen gegen MySQL/MariaDB und SQLite.
- **Migrationen** für beide Dialekte. Für MySQL entspricht die erste Migration genau dem Schema der ersten Umsetzung in Version 3, damit Lank seine Daten behält.

## 7. Der Kern und seine Schnittstellen

Neben den Repositories bekommt der Kern diese Ports von außen:

| Port | Zweck | WordPress | Standalone |
|---|---|---|---|
| `AuthContext` | aktueller Benutzer mit Rolle und Rechten | WP-Benutzer + Capabilities | eigene Sitzung |
| `UserDirectory` | Benutzer anlegen, Rolle setzen, Passwort, stilllegen (ehemalig) | WP-Benutzer | `UserRepository` |
| `Cache` | Zwischenspeicher mit Ablaufzeit | Transients | Repository |
| `HttpClient` | Google, iCal, News, Updates | `wp_remote_*` | cURL oder Streams |
| `FileStorage` | öffentliche und geschützte Dateien | Upload-Verzeichnis | `storage/` und `media/` |
| `ImageResizer` | Bildgrößen erzeugen | `wp_get_image_editor` | GD oder Imagick |
| `Scheduler` | wiederkehrende Aufgaben | WP-Cron | Pseudo-Cron, optional Cronjob |
| `NewsSource` | Neuigkeiten | eigene Beiträge | fremde WordPress-Seite, RSS oder keine |
| `Clock` | Zeit und Zeitzone | `wp_timezone` | Europe/Berlin |
| `Mailer` | Mails über SMTP (Issue #172) | PHPMailer von WordPress | mitgeliefertes PHPMailer |

**Lange Aufgaben in Häppchen.** Auf Webspace bricht PHP oft nach 30 Sekunden ab. Der Galerie-Abgleich arbeitet mit einem Zeitbudget pro Aufruf und setzt beim nächsten fort. **Ein Cronjob beim Hoster ist nicht nötig**, macht die Aufgaben aber zuverlässiger; der Installer empfiehlt ihn am Ende mit der Cron-Adresse, die Statusseite ebenso (rot, wenn der Selbstaufruf scheitert).

**Aufgaben (Scheduler).** Wiederkehrend sind: Galerie-Abgleich (`gallery_sync`, stündlich), Aufbereitung ausstehender Galeriebilder (`gallery_process`), Kalender erneuern (`calendar_refresh`, stündlich), Update-Prüfung, Statusmeldung (stündlich, bei schweren Fehlern vorgezogen), Fehlermeldungen (`bug_reports`, stündlich, nach dem Einreihen vorgezogen). Eingeplant bzw. ausgeplant wird **nicht bei jeder Anfrage**, sondern beim Speichern von Einstellungen (Bereiche, Kalender, Galerie, Updates), im Cron-Lauf, nach einem Update oder einer Migration (`App::upgrade()` vergleicht die Version mit der Einstellung `tasks_planned`) und nach dem Wiederherstellen einer Sicherung. WordPress plant außerdem beim Aktivieren und beim Öffnen der Einstellungsseite, nicht mehr auf `init`. `Scheduler::runSoon()` lässt eine Aufgabe bald laufen (z. B. weitere Bilder aufbereiten).

**Pseudo-Cron (eigenständige Version).** Der nächste Fälligkeitszeitpunkt steht in der Einstellung `tasks_next_run`; die Einstellungen lädt jede Anfrage ohnehin mit einer Abfrage. Eine normale Anfrage liest deshalb die Tabelle `tasks` nicht und schreibt nichts. Ist etwas fällig:
- Mit PHP-FPM oder LiteSpeed schließt der Server die Antwort ab (`fastcgi_finish_request`) und arbeitet im selben Prozess weiter.
- Sonst ruft er die eigene Cron-Adresse auf, **ohne auf die Antwort zu warten**: Verbindung aufbauen (Zeitgrenze 250 ms, bei https auch für TLS), Anfrage schreiben, schließen. Nur an die fest eingetragene Adresse, der Schlüssel geht im Header. Die Cron-Adresse schickt ihr „OK“ mit `Content-Length` und `Connection: close`, bevor sie arbeitet.
- Höchstens ein Versuch pro Minute. Kommt ein Selbstaufruf nicht an (keine Verbindung, oder er vermerkt sich nicht innerhalb einer Minute in `cron_runs`), zählt das als Fehlschlag (`cron_spawn`); danach wird es seltener versucht (1, 2, 4 … bis 60 Minuten). Ab drei Fehlschlägen in Folge zeigt die Statusseite Rot und empfiehlt einen Cronjob mit der Cron-Adresse. Läuft ein Cronjob beim Hoster (Cron-Adresse oder `bin/kompanie.php cron`), zeigt sie Grün.

### Dienstgrade (Issue #9)

- Bereich `ranks`, unabhängig von Punkten und Strafen; arbeitet mit Punkten zusammen, wenn beide an sind (`Features::ranksUsePoints()`, die einzige Stelle für die Kopplung).
- Tabellen `ranks` (`title`, `sort`, `min_points` nullable, `active`), `member_ranks` (zugewiesener Dienstgrad je Benutzer; der Kern hat keine Benutzer-Optionen) und `rank_history` (Titel als Momentaufnahme, `kind`, `points_at_change`). Felder wie in Lank (`wp_mkl_ranks`, `wp_mkl_rank_history`, Meta `mkl_rank_id`), damit die Lanker Daten übernommen werden können.
- Eine Reihenfolge (`sort`, der niedrigste zuerst). Der Dienstgrad ändert sich nie von selbst; Schwellen ändern nur die Hinweise.
- **Ohne Punkte:** frei befördern und degradieren. **Mit Punkten** (Lank #16, für alle, auch Admins): befördern erst ab der Schwelle des Ziels; degradieren immer, auch über der Schwelle (z. B. Korrektur einer zu hohen Einstufung); Dienstgrade ohne Schwelle frei. Gesetzte Schwellen aktiver Dienstgrade müssen mit `sort` streng steigen. Nächster Dienstgrad, fehlende Punkte, erreichbarer Dienstgrad und „Schwelle erreicht seit“ werden berechnet (Beförderungsübersicht in einem Durchlauf über alle Buchungen).
- Anlegen eines Mitglieds mit Anfangspunkten (`manage_points`) und Dienstgrad (`manage_ranks`): alles wird vor dem Anlegen des Kontos geprüft.

#### Abzeichen (Issue #17)

- Optional ein Bild je Dienstgrad, pflegen mit `manage_ranks` (`POST /ranks/{id}/badge`, `DELETE /ranks/{id}/badge`). Spalten `badge_file`, `badge_ext`, `badge_version` in `ranks` (Migration 9, leer = keins). Ein deaktivierter Dienstgrad behält sein Abzeichen.
- PNG, JPG, WebP, SVG, höchstens 1 MB, Base64 im JSON. Rasterbilder: Endung und Signatur, höchstens 4096 × 4096 Pixel; daraus PNG in 64/128/256 Pixeln mit Alphakanal (`ImageResizer::resizeToPng`, GD bzw. `wp_get_image_editor`, nie vergrößert). Das Original wird nicht aufgehoben.
- SVG: `SvgSanitizer` (DOMDocument, ohne Netz, DOCTYPE wird abgelehnt) lässt nur Elemente und Attribute einer Liste stehen; `href` nur auf `#…`, `url()` nur auf `#…`, kein `<style>` mit `@import`, keine Animationen, kein `<script>`, `<foreignObject>`, `<image>`, keine `on*`-Attribute. Gespeichert wird das bereinigte SVG. Weil GD kein SVG rastert, zeichnet die App beim Hochladen eine PNG-Fassung (256 Pixel, transparent, Canvas) und schickt sie als `png` mit; der Server prüft sie wie ein PNG. Fehlt sie, gibt es nur kein Bild in Benachrichtigungen.
- Dateien in der geschützten Dateiablage (`abzeichen/abz-<24 hex>(.svg|-64.png|-128.png|-256.png)`), Namen vor jedem Zugriff gegen ein Muster geprüft; Ersetzen, Entfernen und Löschen des Dienstgrads löschen die alten Dateien.
- Auslieferung über `Environment::badgeUrl()` (`<App>/abzeichen/<id>?v=<Fassung>&s=<64|128|256>`, im Bereich des Service Workers). `RankService::badgeForDelivery` legt Status und Header für beide Adapter fest: angemeldet mit `member` oder `calendar` (auch Anwärter), sonst 401/403; Wartungsmodus 503; Bereich aus 404. SVG als `image/svg+xml` mit `Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox` und `nosniff`, sonst `image/png`; `Cache-Control: private`, bei passender Fassung ein Jahr (`immutable`).
- `badge_url` (128er-Adresse, die App setzt `s` je nach Anzeigegröße) überall, wo ein Dienstgrad steht: Liste, Stand (`rank`, `next_rank`, `reachable_rank`), Beförderungsübersicht, Mitgliederliste (`rank_badge_url`) und -details, Verlauf (`to_badge_url`, das heutige Abzeichen; der Verlauf speichert nur Bezeichnungen). Fehlt die Datei (z. B. Sicherung auf einem anderen Server), ist `badge_url` null.
- Die Benachrichtigung zur Beförderung bekommt `image` (vollständige Adresse der 256er-PNG-Fassung); der Service Worker setzt daraus `image` und `icon`. Der Service Worker hält Abzeichen zuerst aus dem Speicher (`kompanie-badges-v1`, je Dienstgrad und Größe nur die aktuelle Fassung).
- In Sicherungen wie Dokumente: die Zeilen ja, die Dateien nicht.
- App: `RankBadge` (klein/mittel/groß, `alt` = Bezeichnung, ohne eigenen Hintergrund, Rahmen oder Farbfilter); die Pflege zeigt eine Vorschau auf heller und dunkler Fläche.

### Titel (Issue #22)

- Bereich `titles`: Ehrentitel und Ämter, unabhängig von Dienstgrad und Punkten; Recht `manage_titles` für Liste und Zuweisung.
- Tabellen `titles` (`title`, `sort`, `active`) und `member_titles` (mehrere je Benutzer). Wie in Lank (`wp_mkl_titles`, Meta `mkl_title_id`), damit die Lanker Daten übernommen werden können.
- Zugewiesene Titel lassen sich nicht löschen, nur umbenennen oder deaktivieren; deaktivierte werden nicht neu vergeben, bleiben aber bei ihren Trägern. Neu verliehene Titel lösen eine Benachrichtigung (Art `titles`) aus.
- Die Mitgliederliste holt alle Titel in zwei Abfragen (`TitleService::byUser`).
- Wird ein Konto gelöscht (in der App oder bei WordPress `deleted_user`), entfernt `App::userDeleted` alle seine Daten (siehe *Konten verwalten*).
- Ein Titel kann mit einem Amt verknüpft sein (Issue #112, siehe *Ämter*): Dann vergeben ihn die laufenden Amtszeiten, `TitleService::assign` lehnt Änderungen daran ab (400), und `GET /titles` nennt das Amt (`office`).

### Konten verwalten (Issue #24)

- Wer Konten anlegt (`create_users`), ändert Name, E-Mail und (nur Standalone) Benutzernamen und löscht Konten, mit denselben Grenzen wie beim Passwort-Zurücksetzen (`MemberService::guardAccount`: Admins nur durch Admins, Personen ohne Mitgliederbereich nur durch Admins, nie mehr Rechte als man selbst). Nicht das eigene Konto, nicht den letzten Admin.
- Löschen: Port `UserDirectory::removeAccount()` beendet alle Sitzungen; Standalone löscht das Konto, WordPress entfernt nur die Rolle in der App (Entscheidung: der Benutzer der Webseite bleibt). Danach löscht `App::userDeleted()` alle eigenen Daten (Punkte, Strafen, Anwesenheit, Dienstgrad mit Verlauf, Titel, Zusatzrollen, Push-Abos), auch wenn WordPress einen Benutzer im Backend löscht. Amtszeiten (Issue #112) bleiben als Chronik mit dem gespeicherten Namen stehen; nur `office_holders.user_id` wird `NULL` (Entscheidung im Issue). Zeilen in Listen (Issue #121) bleiben ohne Namen stehen (`list_rows.user_id` wird `NULL`), bis die Liste aktualisiert wird. Was die Person für andere eingetragen hat, bleibt; angezeigt als „gelöschtes Konto“ (`UserNames`).
- Mehrere anlegen (`POST /users/bulk`, höchstens 50): erst alles prüfen (`MemberService::prepare`, Benutzernamen und E-Mail auch untereinander, Anfangspunkte und Dienstgrad), dann anlegen. Die Zugangsdaten enthalten den Link zur Installationsanleitung und lassen sich als Zettel je Person ausdrucken.

- **Auskunft (Art. 15 DSGVO)**: `Domain\PersonalDataExport`, `GET /me/data` (angemeldet) und `GET /members/{id}/data`
  (`create_users` mit `guardAccount` und `view_personal`), nicht in der Vorschau. Tabellen aus `UserData::tables()` (dieselbe Liste wie beim
  Löschen; ein Test prüft, dass jede Kerntabelle mit `user_id` dort steht oder begründet ausgenommen ist), dazu Push-Abos
  ohne Schlüssel, Ämter, Listen, eigene Änderungen am Brett und eigene Bilder (Signatur, Entwürfe) mit Inhalt; ohne Pseudonyme. Antworten in
  anonymen Umfragen nur für die Person selbst, Nutzungsauswertung nur für sie und `view_activity`. Der Service Worker
  speichert die Antworten nie.

#### Persönliche Angaben: E-Mail-Adresse, Handynummer, Adresse, Geburtsdatum

- `GET`/`PATCH /me/account` (angemeldet, Meins → Konto): E-Mail-Adresse, Handynummer und Adresse der Person selbst (`MemberService::ownAccount`/`updateOwnAccount`). Eine neue E-Mail-Adresse nur mit `current_password` (gleiche Begrenzung wie beim Passwortwechsel, `MemberService::checkPassword`), weil sich bei WordPress darüber das Passwort zurücksetzen lässt; Handynummer und Adresse ohne. Erst wird alles geprüft, dann geschrieben. Name und Benutzername ändert hier niemand.
- Handynummer und Adresse gibt es in WordPress und der Benutzertabelle der eigenständigen Version nicht: Tabelle `member_contacts` (Migration 31, Adresse Migration 35) mit `user_id`, `phone`, `street`, `house_number`, `address_extra`, `postal_code`, `city`, `country` (ISO 3166-1 alpha-2, leer = anderes Land), `country_name`, `updated_at`; eine Zeile je Konto, sie fällt weg, wenn Handynummer und Adresse leer sind (`MemberContactRepository`). `UserData::purge` löscht sie mit dem Konto, die Sicherung enthält sie (`Backup::CORE_TABLES`). Handynummer: Ziffern, Leerzeichen und `+ ( ) / -`, 5 bis 20 Ziffern, höchstens 30 Zeichen.
- Adresse (`Domain\Address::parse`, in der App gleich `address.ts`): alles leer heißt keine; sonst Pflicht Straße, PLZ und Ort, Land aus derselben Auswahl wie die Ländervorwahl (ohne Angabe DE). Geglättet wird vorher (Leerzeichen, Hausnummer am Ende der Straße in ihr Feld, PLZ groß, ohne Länderkennung wie `D-`, mit den Leerzeichen bzw. dem Bindestrich des Landes). PLZ je Land nach der Formatliste des Weltpostvereins (Wikipedia „List of postal codes“): DE fünfstellig ohne Leitregion 00, AT/CH/BE vierstellig ohne führende 0, LU/DK vierstellig, NL `1234 AB`, FR/IT fünfstellig, ES 01–52, TR 01–81, PL `00-950`, CZ `110 00` (1–7), GB Outward und Inward Code; anderes Land 2–12 Zeichen mit Ländername. Ziffern im Ort sind erlaubt (z. B. „Paris Cedex 08“), die App weist nur darauf hin.
- **Rechte** `view_personal` (sehen) und `manage_personal` (ändern, setzt `view_personal` voraus; `Capability::requires()` mehrstufig, `RoleService::withRequired` löst die Kette auf): E-Mail-Adresse, Handynummer und Adresse (`personal` in `GET /members/{id}`, `MemberService::personal`; ändern mit `PUT /members/{id}/personal`, `MemberService::updatePersonal`) und das ganze Geburtsdatum (`birthday` in `GET /members/{id}`, `PUT`/`DELETE /members/{id}/birthday`). Für alle Konten, ohne die Grenzen von `guardAccount`; nur eine **andere E-Mail-Adresse** gilt weiter als Kontoverwaltung (`guardAccount`), denn über sie lässt sich bei WordPress das Passwort zurücksetzen. `GET /members/{id}/account` (`create_users`) liefert `phone` nur dem Konto selbst und mit `view_personal`; `PATCH` dort ändert `phone` anderer nur mit `manage_personal`.
- **Beim Anlegen** (`POST /users`, `/users/bulk`, `/members/import`) trägt `create_users` alle persönlichen Angaben ein (`phone`, `address`, `email`, `birthday`), vorab geprüft (`MemberService::prepare`); Eingeben ist nicht Sehen. Danach zeigt sie nur `view_personal`.
- **Update**: `RoleService::migratePersonalRights` (einmalig, Merker `roles_personal_split`) gibt jeder Rolle mit `create_users` und jeder Person, der `create_users` zusätzlich gegeben ist, beide neuen Rechte; wem `create_users` entzogen ist, dem auch diese. So sieht danach jede Person dieselben Angaben wie vorher.

#### Ehemalige Mitglieder

- Ausgetreten, verstorben oder sonst ausgeschieden: Das Konto bleibt mit allen Daten (Punkte, Strafen, Anwesenheit, Dienstgrad mit Verlauf, Titel, Amtszeiten, Geburtstag, Foto), bekommt aber die Grundrolle Gast über den Port `UserDirectory::deactivate()`: keine Rechte, nicht in Mitgliederliste, `directory`, Umfragen, Geburtstagen oder Benachrichtigungen (alles hängt an Rechten bzw. der Liste ohne Gäste). Alle Sitzungen enden. **Standalone** sperrt zusätzlich die Anmeldung (`users.disabled_at`, Standalone-Migration 3; `SessionAuth::login` antwortet nach der Passwortprüfung 403 `account_disabled`). **WordPress** setzt die Benutzer-Option auf `guest` und entfernt frühere WP-Rollen der App wie beim Entfernen, behält aber die Konto-Markierung (`kompanie_app_account`); die Anmeldung an der Webseite sperrt die App nicht. Push-Abos werden gelöscht.
- Tabelle `former_members` (Migration 19, in Sicherungen, mit dem Konto gelöscht über `UserData::purge`): `reason` (`left` | `deceased` | `other`), `joined_on` und `left_on` (`JJJJ-MM-TT`, nur `JJJJ` oder leer; nicht in der Zukunft, nicht vor 1800, Eintritt nicht nach dem Ausscheiden), `founder`, `restore` (JSON: Grundrolle, Zusatzrollen und Ausnahmen bei den Rechten von vorher). Ehemalig ist, wer einen Eintrag hat **und** Gast ist (`FormerMemberService::previousRole`): Bekommt das Konto anderswo wieder eine Rolle (z. B. WP-Backend), gilt es nicht mehr als ehemalig.
- Recht `create_users`, Grenzen wie beim Löschen (`MemberService::guardAccount`); bei Ehemaligen gelten sie mit der Grundrolle von vorher (wer jemanden eintragen durfte, darf ihn weiter verwalten, wieder aufnehmen oder löschen). Nicht das eigene Konto, nicht den letzten Admin, keine Gäste. Rolle ändern (`PATCH /members/{id}/role`, `promote`) und Startpasswort erst nach dem Wiederaufnehmen (400).
- `GET /members/former` (Liste mit Punkten bzw. offenen Beträgen je nach Recht und Foto), `PUT /members/{id}/former` (eintragen), `PATCH /members/{id}/former` (Angaben ändern), `DELETE /members/{id}/former` (wieder aufnehmen: Grundrolle von vorher, sonst Mitglied, wenn es sie nicht mehr gibt bzw. Admin nicht in der App vergebbar ist; Zusatzrollen, soweit es sie gibt und `$actor` sie vergeben darf; Ausnahmen nur durch Admins). `GET /members/{id}` liefert `former` (oder `null`). `GET /members` lässt Ehemalige auch dann weg, wenn sie noch Dienstgrad oder Titel haben (sonst stünden sie als „frühere Mitglieder“ in der Liste).
- **Ämter enden beim Ausscheiden** (Issue #181): `mark()` legt das Konto still und beendet in **einer** Transaktion (`Connection::transaction`, verschachtelt; unter WordPress läuft auch die Rolle über `wpdb`) mit `OfficeService::endFor` jede laufende Amtszeit des Kontos (`period` ohne Ende, auch mit `honor`). Ende = Jahr aus `left_on`, leer `Time::today()`, nie vor `start_year`. Allein: `end_year` setzen. Gemeinsam: neue Amtszeit für das Konto (gleiches Amt, Art, Beginn, `note`, `honor`, Inhaber mit gespeichertem Namen), aus der laufenden entfernt; die anderen laufen weiter. Dann `syncTitles` für die verknüpften Titel (das Konto ist Gast, also kein `isMember()`: verknüpfte Titel entfallen, andere bleiben). Antwort mit `offices_ended`; Vorschau `GET /members/{id}/former/offices?left_on=` (gleiche Prüfung wie `mark`, ändert nichts, `clamped` bei Austritt vor dem Beginn, `shared`). `office_terms.ended_for` (Migration 26) merkt das Konto: `update()` mit neuem `left_on` setzt das Ende dieser Amtszeiten neu (`moveEndFor`); jede Änderung von Hand (`updateTerm`), `reactivate()` (öffnet nichts wieder) und das Löschen des Kontos (`UserData::purge`) setzen `ended_for` auf NULL. `create()` und der Import legen neue Konten an, die keine laufenden Amtszeiten haben.
- **Einzeln anlegen** (`POST /members/former`, in der App „+ Ehemalige Person anlegen“ wie „+ Mitglied anlegen“): Vor- und Nachname, Felder wie bei `PUT /members/{id}/former`, dazu Geburtstag und Foto wie bei `POST /users`. Erst prüfen (`FormerMemberService::valid`, `MemberService::prepare`), dann `createImported` wie eine Zeile des Imports. Gleicher Anzeigename eines vorhandenen Kontos → 400 (wie `existing` beim Import, damit niemand doppelt entsteht); danach `OfficeService::linkNames` für diesen Namen.
- **Import** (`POST /members/former/import`, Ersterfassung z. B. aus einer Chronik): CSV mit Semikolon oder Tabulator `vorname;nachname;eintritt;ausgeschieden;grund`, UTF-8 (mit oder ohne BOM) oder Windows-1252, höchstens 200 KB bzw. 500 Zeilen. Eintritt „Gründer“ setzt `founder`. Ohne `apply` Vorschau je Zeile (Fehler, `existing` bei gleichem Anzeigenamen eines vorhandenen Kontos, gleiche Namen in der Tabelle sind ein Fehler); mit `apply` nur ohne Fehler. Je Person `MemberService::prepare`/`insert` (Benutzername aus dem Namen, zufälliges Passwort, das niemand erfährt) und gleich `deactivate`. Keine Transaktion (WordPress-Benutzer): Bricht es ab, findet ein zweiter Lauf die schon angelegten als `existing`. Danach verknüpft `OfficeService::linkNames` freie Namen in Amtszeiten (ohne `user_id`) mit den neuen Konten und mit schon früher importierten Ehemaligen, wenn der Name nach `Text::line` ohne Unterschied in Groß- und Kleinschreibung gleich ist und die Person in der Amtszeit noch nicht steht; `name` bleibt. `offices_linked` zählt die Einträge (in der Vorschau die zu verknüpfenden).

- **Aufbewahrungsfrist** (Einstellung `former_retention`, gehört zur Installation und kommt nicht mit einer Sicherung zurück, Standard aus; `GET`/`PUT /admin/former/retention`
  mit `manage_settings`): Aufgabe `former_retention` (täglich, nur mit Frist) löscht bei Ehemaligen nach `years` Jahren ab
  `left_on` (nur Jahr: 31.12., leer: `created_at` in Ortszeit) die persönlichen Angaben: `UserData::purge` mit
  `keepChronicle` (es bleiben `UserData::CHRONICLE`, Ämter und Listen), Entwürfe, eigene Rechte, Pseudonym der
  Nutzungsauswertung und `UserDirectory::forgetLogin` (Standalone: E-Mail, Benutzername, Passwort; WordPress: nur
  Sitzungen). Zum Schluss `former_members.purged_at`: danach nicht wieder; bis dahin wiederholt der nächste Lauf alles.
  Ins Protokoll kommt nur die Anzahl.

#### Fotos der Mitglieder

- Ein Foto je Konto (`MemberPhotoService`). Hochladen, ersetzen, entfernen mit `create_users` (`PUT`/`DELETE /members/{id}/photo`) und denselben Grenzen wie oben (`MemberService::guardAccount`); beim Anlegen optional `photo` in `POST /users` und je Person in `POST /users/bulk`, vor dem Anlegen geprüft (`MemberPhotoService::parse`), danach gespeichert (scheitert das, `warning` wie beim Geburtstag).
- JPG, PNG, WebP, höchstens 5 MB und 4096 × 4096 Pixel, Base64 im JSON; Endung und Signatur geprüft. Gespeichert werden nur neu erzeugte JPEGs in 128, 480 und 1024 Pixeln (1024 für die vergrößerte Ansicht; Fotos von vor Datenbankversion 18 haben diese Fassung nicht, die App nimmt dann die 480er) und in Originalauflösung (`s=4096`, Spalte `original`, Datenbankversion 24; Qualität 90, notfalls 82 oder 74, höchstens 3 MB, damit auch eine Datenbank mit knappem `max_allowed_packet` sie annimmt; eigenes UPDATE nach den übrigen Größen, scheitert es, bleibt sie NULL) (`ImageResizer::resizeToJpeg`: nach EXIF gedreht, ohne Metadaten, nie vergrößert). Die Großansicht zeigt erst die 1024er und lädt die Originalauflösung nach (404 bei älteren Fotos: dann bleibt die 1024er). Die App speichert vorher im Browser neu als JPEG bis 4096 Pixel und höchstens 4 MB (Qualität 0,9 bis 0,7, notfalls kleiner), damit eine Anfrage unter `post_max_size` bleibt; beim Anlegen mehrerer schickt sie die Fotos danach einzeln mit `PUT /members/{id}/photo`, statt alle in einer Anfrage (`post_max_size`).
- Tabelle `member_photos` (Migration 16): `user_id`, `version`, `small`, `large` (Base64), `updated_by`, `updated_at`. In der Datenbank statt in der Dateiablage: `UserData::purge` löscht das Foto mit dem Konto (auch auf allen Seiten eines WordPress-Netzwerks), und die Sicherung enthält es (`Backup::CORE_TABLES`).
- Sehen: die Person selbst (`photo_url` in `/config` → `user`, Anzeige unter Konto) und alle, die die Mitgliederliste der Verwaltung sehen (`Capability::memberList()`, wie `GET /members`); `photo_url` in Mitgliederliste und -details, dazu `photo_editable` in den Details. Mit dem Bereich `directory` sind die Fotos aller Personen der öffentlichen Mitgliederliste für jeden abrufbar, auch ohne Anmeldung (Issue #112); sonst sehen andere Mitglieder keine Fotos.
- Auslieferung über `Environment::memberPhotoUrl()` (`<App>/mitgliedsfoto/<id>?v=<Fassung>&s=<128|480|1024>`). `MemberPhotoService::forDelivery` legt Status, Header und Inhalt für beide Adapter fest: ohne Anmeldung 401 und fremdes Foto ohne Recht 403 (beides nicht für Fotos der öffentlichen Mitgliederliste), Wartungsmodus 503, kein Foto bzw. unbekannte Größe 404; `image/jpeg`, `nosniff`, `X-Robots-Tag: noindex`, `Cache-Control: private` (öffentliche Fotos `public`), bei passender Fassung einen Tag (ein entferntes Foto oder entzogene Rechte gelten so bald auch auf Geräten, die es schon geladen haben), sonst `no-cache`; ETag aus Fassung und Größe. Der Service Worker speichert Fotos nicht selbst. In der Vorschau mit `?vorschau=…` wie Abzeichen.

### Wartungsmodus (Issue #42)

- Einstellung `maintenance` (`on`, `reason`, `since`, `by`), Verlauf in `maintenance_log` (höchstens 20 Einträge). Schalten nur mit `manage_settings` (`POST /admin/maintenance`, in der App unter *Einstellungen*, bei WordPress auch im WP-Admin). Nur manuell, ohne Endzeit; die Begründung ist Klartext (höchstens 500 Zeichen) und während der Wartung änderbar.
- Zentrale Sperre in `Api::dispatch` vor allen Routen: Wer `manage_settings` nicht hat, bekommt 503 `maintenance` mit `reason`. Ausgenommen sind `/config` (liefert `maintenance`, damit die App die Wartungsseite zeigt) und die Anmeldung (`/auth/login`, `/auth/logout`, `/auth/csrf`). Geschützte Fotos und Dokumente antworten ebenso mit 503; öffentliche Bilder liefert der Webserver direkt aus.
- Hintergrundaufgaben pausieren außer Updates und Statusmeldungen (`MaintenanceService::runsDuringMaintenance`); die Push-Warteschlange wird beim Beenden gleich verschickt. Die Statusmeldung enthält `maintenance: true/false`, nie die Begründung.
- Die App zeigt Nicht-Admins die Wartungsseite (auch wenn eine Anfrage 503 `maintenance` bekommt), Admins ein rotes Banner mit „Beenden“. Der Service Worker speichert nur erfolgreiche Antworten; offline gespeicherte Daten bleiben erhalten. Die Shortcodes der Webseite zeigen weiter an (Entscheidung im Issue).

### Fehlermeldungen (Issue #64)

- **Weg:** App → Cockpit (signiert wie die Statusmeldungen) → Issue in einem privaten Repository für Meldungen. Die Installationen haben keinen GitHub-Zugang; nur das Cockpit legt Issues an. Wire-Format, Antworten und Spamschutz: [FEHLERMELDUNGEN.md](FEHLERMELDUNGEN.md).
- **Konfiguration:** `reportUrl` in `update.json` (Repo-Variable `KOMPANIE_REPORT_URL`, `--report-url`, nur https). Fehlt sie, bleibt die Prüfsumme der Paket-Konfiguration unverändert und `/config` meldet `reports: false` (kein Knopf).
- **Kern:** `Domain\BugReportService` (Formular mit Zeitmarke, Prüfung, Warteschlange `bug_reports`, Aufgabe `bug_reports`). Installations-ID und Geheimnis kommen aus `StatusReporter`, der Stand „vom Cockpit bestätigt“ ist eigen (`bug_reports_registered`), damit Fehlermeldungen ohne Zustimmung zu den Statusmeldungen funktionieren.
- **Bildschirmfoto in der Datenbank** (Base64, ≤ 2 MB, eine Zeile je Meldung) statt im Datenverzeichnis: Löschen nach der Zustellung, nach 7 Tagen oder mit dem Konto entfernt alles auf einmal; es liegt nie in einem vom Webserver ausgelieferten Ordner und nicht in Sicherungen. Die Tabelle ist keine Kerntabelle der Sicherung.
- **Gäste dürfen melden:** Honeypot, Zeitfalle (signierte Zeitmarke, frühestens 3 s), Begrenzung je IP-Hash und je Installation, Obergrenze der Warteschlange; kein Captcha.
- **Frontend:** `#/hilfe/melden` (`views/FehlerMelden.tsx`), Knopf auf jeder Hilfeseite und im Verzeichnis. `lastError.ts` merkt die letzte Fehlermeldung (API-Fehler, Programmfehler) und die Seite, auf der „?“ gedrückt wurde, nur im Speicher.

### Dokumente (Issue #2)

- Bereich `documents` (eigener Tab), Recht `manage_documents`. Tabellen `doc_categories` (`title`, `sort`) und `documents` (`title`, `category_id`, `doc_date` = Stand, `visibility` members|managers, `file`, `ext`, `size`, `version`). Wie in Lank (`wp_mkl_documents`, `wp_mkl_doc_categories`; Sichtbarkeit dort mitglieder|spiess, Dateien in `uploads/mkl-private/`).
- Dateien liegen geschützt in der Dateiablage (`FileStorage`, privates Verzeichnis `dokumente/`, zufälliger Name) und kommen nur über `Environment::documentUrl()` (`<App>/dokument/<id>?v=<Fassung>`) nach Prüfung der Anmeldung (`DocumentService::forDelivery`), in beiden Adaptern neben den Galeriebildern. Bei nginx muss das private Verzeichnis außerhalb des Web-Verzeichnisses liegen (`.htaccess` wirkt nur unter Apache).
- Hochladen: Base64 im JSON (beide Adapter nehmen nur JSON an, CSRF bleibt gleich). Geprüft werden Endung und Signatur; höchstens 20 MB bzw. was `post_max_size` und `memory_limit` zulassen (`DocumentService::maxBytes`, die App bekommt die Grenze mitgeliefert).
- Eine neue Fassung ersetzt die Datei und ändert `version` (Prüfsumme des Inhalts), damit die Adresse; der Service Worker hält Dokumente zuerst aus dem Speicher (`kompanie-docs-v1`, je Dokument nur die aktuelle Fassung). Die Auslieferung sendet `Cache-Control: private, no-cache` mit ETag und 304 wie bei den Galeriebildern; bewusst kein langes Speichern im HTTP-Cache des Browsers, weil das Abmelden ihn nicht leert (geteilte Geräte). PDFs zeigt pdf.js an (eigenes, nachgeladenes Modul; der Worker heißt `.js`, siehe `vite.config.ts`): Seiten werden erst gezeichnet, wenn sie in die Nähe des sichtbaren Bereichs kommen (IntersectionObserver, eine nach der anderen), als JPEG; bis dahin hält ein Platzhalter im Seitenverhältnis den Platz. Legacy-Build von pdf.js, weil der moderne iOS 17.4 bzw. 18 voraussetzt (`Promise.withResolvers`, `URL.parse`).
- Sortierung und Einklappen passieren in der App; die API liefert eine feste Reihenfolge (Titel).

### Anwesenheit (Issue #21)

- Bereich `attendance`, Recht `manage_attendance`. Tabellen `attendance_lists` (`source` calendar|free, `event_key`, `title` und `event_date` als Momentaufnahme, eindeutig je `source`+`event_key`) und `attendance_entries` (`status` present|excused|absent, `updated_by`); `points.attendance_id`. Wie in Lank (`wp_mkl_attendance_lists`, `wp_mkl_attendance`, `wp_mkl_points.attendance_id`; Status dort deutsch).
- In jeder Liste stehen alle mit einer Rolle (ohne Gäste). Punkte und Strafen nur für Mitglieder; sie werden über die üblichen Formulare gebucht (`POST /points` mit `attendance_id` bucht nur für, wer für die Liste noch keine Punkte hat).
- Der Kalender lädt dafür auch die letzten 90 Tage (`CalendarService::PAST_DAYS`); `/calendar` und die Benachrichtigungen sehen weiter nur anstehende Termine.
- Zähler nach Gruppen: Einstellung `attendance_groups` (Rolle → Rolle, als die sie zählt; keine Ketten). Ohne Einstellung zählen Rollen mit Mitgliederbereich als Mitglied. Es zählt die Grundrolle (Zusatzrollen ändern die Gruppe nicht, Issue #66).
- Feste Zahl von Abfragen je Liste, unabhängig von der Zahl der Personen (gemessen im Test mit 40 Personen).

### Schwarzes Brett (Issue #5, Umbau #172)

- Bereich `board`, Recht `post_board` (Standardrolle „Schwarzes Brett verwalten“: `member`, `post_board`). Tabelle `board_messages` (`title`, `body`, `author_id`, `created_at`, `updated_at`, `expires_at`, `deleted_at`, `deleted_by`; Migration 10 und 29), in Sicherungen enthalten.
- Empfänger sind Mitglieder und Anwärter: `BoardService::isRecipient` (`member` oder `calendar`, wie `/calendar`) ist die einzige Stelle dafür – für `GET /board`, die Push- und Mail-Empfänger, die Art `board` in den Benachrichtigungen und (gespiegelt in `board.ts`) die App. Wer `post_board` hat, darf auch lesen.
- **Titel** (Pflicht, reiner Text, `Text::line`, ohne Längengrenze): Banner, Text der Benachrichtigung, Betreff der Mail. **Inhalt**: formatiert (`RichText`, s. u.), ohne Längengrenze; MySQL-Spalten `longtext`. Migration 29 escaped bestehende Nachrichten (reiner Text), macht Zeilenumbrüche zu `<br>` und setzt die erste Zeile (höchstens 80 Zeichen) als Titel – in SQL, wiederholbar (`WHERE title IS NULL`). `title` ist NULL-fähig, damit ältere Versionen weiter schreiben können.
- **Enddatum** frei wählbar (`expires_at`, UTC): Standard `LIFETIME` (24 Stunden), keine Obergrenze, nur in der Zukunft; änderbar, solange die Nachricht gilt. `Time::parseToUtc` (gemeinsam mit den Umfragen) nimmt ISO 8601 mit Zeitzone oder `JJJJ-MM-TTTHH:MM` in der Zeitzone der Kompanie. Die App erfasst und zeigt Ortszeit des Geräts (`toLocalInput`/`fromLocalInput` in `format.ts`) und schickt `…Z`.
- `updated_at` wechselt nur bei neuem Titel oder Inhalt (immer später als vorher, Issue #68); ein neues Enddatum allein lässt es, damit ausgeblendete Nachrichten ausgeblendet bleiben.
- **Änderungsverfolgung** (`board_message_revisions`, ohne Fremdschlüssel): `created` (Momentaufnahme), `title`, `content`, `expires` (je vorher/nachher), `mailed`, `deleted`; mit Konto und Zeit (UTC). Nur `post_board` (`GET /board/{id}/history`, `GET /board/history`, `last_edit` in der Liste). Vorzeitiges Löschen setzt `deleted_at`/`deleted_by` und `expires_at` = jetzt (ältere Versionen zeigen sie so auch nicht mehr). `board_cleanup` (stündlich) löscht Nachrichten 30 Tage (`HISTORY_KEEP_DAYS`) nach ihrem Ende samt Verlauf, Mailprotokoll und Bildern, dazu Verlaufseinträge ohne Nachricht und nie gespeicherte Bildentwürfe.
- Benachrichtigung nur beim Schreiben (`PushTriggers::boardPosted` → `PushService::notifyBoard`): Überschrift „Brett: Verfasser“, Text = Titel, an alle Geräte der Empfänger mit Art `board` außer denen des Verfassers, `Urgency: high`, TTL = Restlaufzeit, gekappt auf `PushService::MAX_TTL` (2 419 200 s = 28 Tage, Obergrenze von Firebase Cloud Messaging). Neue Abos bekommen `board` wie alle angebotenen Arten; beim ersten Einschalten des Bereichs auch die bestehenden Abos der Empfänger (`offerBoardToExisting`, einmalig, `push_board_offered`).
- Die Liste besteht aus Einträgen mit `kind` (`message`, für Mitglieder auch `poll` – laufende Umfragen, Issue #4); App und Banner überspringen unbekannte.
- App: Banner oben auf jeder Seite (`BoardBanner`, Titel der neuesten Nachricht, per CSS gekürzt, Tipp öffnet `#/brett/<id>`; „+N weitere“), „×“ blendet je Gerät aus (`localStorage`, Schlüssel aus Art, ID und `updated_at`). Seite `#/brett` mit Titel und formatiertem Inhalt (`RichTextView`); Schreiben mit Editor, „Sichtbar bis“, Haken Benachrichtigung und E-Mail, Bestätigung mit Titel, Ende und Versandwegen; Bearbeiten (Titel, Inhalt, Ende), Verlauf und Bereich „Verlauf“ mit `post_board`. Der Entwurf liegt in `localStorage` (Sprung zur Signatur und zurück).
- Gelöschte Konten: Nachrichten und Verlauf bleiben, Verfasser bzw. Bearbeiter „gelöschtes Konto“ (`UserNames`).

### Formatierte Inhalte, Bilder und Signaturen (Issue #172)

- Allgemeine Bausteine, nicht an das Brett gebunden. `RichText` (Kern): Bereinigung nach Allowlist mit DOMDocument (Vorbild `SvgSanitizer`), die Ausgabe wird neu geschrieben; Elemente für Absätze, Überschriften, Hervorhebungen, Listen, Links (`https:`, `mailto:`, `tel:`, `target=_blank`, `rel=noopener noreferrer`), Bilder (nur `data-image`), Trennlinie, einfache Tabellen; Inline-Styles nur aus einer Liste und ohne `url()`, `expression()`, Escapes, Kommentare oder unpaarige Anführungszeichen. Dazu `excerpt` (reiner Text, z. B. Text-Teil der Mail). Die App bereinigt beim Anzeigen zusätzlich mit DOMPurify.
- `RichImageService` und Tabelle `rich_images` (Base64 wie die Fotos der Mitglieder; Besitzer `draft`, `signature`, `board`): `POST /rich-images` legt einen Entwurf an (JPG/PNG/WebP, neu kodiert ohne Metadaten in Originalgröße, PNG bleibt PNG). Beim Speichern übernimmt der Inhalt seine Bilder (`claim`): eigene Entwürfe werden umgehängt, Bilder anderer Besitzer kopiert (z. B. die der Signatur beim Einfügen in eine Nachricht), fremde entfernt. Auslieferung über `Environment::richImageUrl` (`<App>/bild/<Kennung>`) nur an Angemeldete, die den Inhalt sehen dürfen.
- Keine fachlichen Grenzen; technische liest `ServerLimits` und meldet sie verständlich: `post_max_size` (Bilder gehen als Base64 im JSON; die App prüft vorab über `GET /rich-images/limits`), `memory_limit` (Pixelzahl gegen freien Speicher vor dem Dekodieren), `max_allowed_packet` (MySQL). `upload_max_filesize` gilt nur für Formular-Uploads und wird zur Information mitgeliefert.
- `SignatureService` (`user_signatures`, `GET/PUT/DELETE /me/signature`): für Mitglieder, Anwärter und `post_board`. Die App fügt die Signatur als Kopie ein; spätere Änderungen wirken nicht auf Nachrichten. Konto löschen entfernt Signatur, ihre Bilder und Entwürfe (`UserData::purge`).
- Editor (`richtext/RichTextEditor.tsx`): TipTap v3 (MIT) auf ProseMirror (MIT), nur freie Pakete (StarterKit, TextStyleKit, TextAlign, Image mit `resize`, TableKit, FileHandler), eigene Werkzeugleiste und Einzug (`indent.ts`, `margin-left` in Pixeln). Einfügen aus Word/Outlook (`wordPaste.ts`, eigene Umsetzung): `mso-list`-Absätze → echte Listen, Ballast entfernen, lokale Bilder aus dem RTF-Teil (`\pngblip`/`\jpegblip`) übernehmen, sonst Hinweis. Wird wie pdf.js erst bei Bedarf geladen.

### Mailversand (Issue #172)

- Port `Mailer` (`check`, `measure`, `send`, `serverMaxSize`, `close`), Umsetzung `Mail\PhpMailerMailer` (ab PHPMailer 6.1.0) in beiden Varianten; Tests nutzen einen Test-Mailer (`App`-Parameter `customMailer`).
- `MailService`: Kompanieadresse (Absender, Antworten und Rückläufer), Absendername (Standard: Name der Kompanie), SMTP (Server, Port, `starttls`/`ssl`/`none`, Benutzer, Passwort – nie ausgeliefert, nur `password_set`), Versand in Portionen (`mail_batch_size`, `mail_batch_pause`). In der App unter *Einstellungen → E-Mail* (`/admin/mail`, `…/check`, `…/test`), auch mit WordPress dort (nicht im WP-Backend).
- `BoardMailService`: Empfänger wie beim Brett, nur mit Adresse (`UserDirectory::emails`) und ohne Abmeldung (`user_settings` `board_mail` = `0`, Standard an, `/me/mail`), auch an den Verfasser (anders als die Benachrichtigung). Eine Mail je Empfänger, Betreff = Titel, HTML und Text (`multipart/alternative`), Bilder eingebettet (`multipart/related`, `cid:`), Fußzeile mit Link `#/brett/<id>`, Verfasser und Hinweis zum Abschalten. `board_mails` ist Warteschlange und Protokoll (`pending`, `sent`, `failed`, `cancelled`; nicht in Sicherungen). Aufgabe `board_mail` (alle 15 Minuten, nach dem Einreihen vorgezogen) mit Zeitbudget, Portionen, bis zu 5 Versuchen bei vorübergehenden Fehlern (Pause 5, 10, 20, 40 Minuten), dauerhafte (5xx, z. B. 552) sofort als fehlgeschlagen. Löschen der Nachricht bricht ausstehende Mails ab.
- Höchstgröße: Die EHLO-Antwort (`SIZE`, RFC 1870) wird beim Verbindungsaufbau gelesen und gemerkt (`mail_server_size`, „Verbindung prüfen“ zeigt sie). Mit Limit prüft `POST /board` die fertige Mail vorab und lehnt mit Erklärung ab, bevor etwas veröffentlicht wird; ohne Limit (`SIZE` fehlt, `0` oder ohne Zahl) gibt es keine Prüfung.

### E-Mails aus der Verwaltung

- Bereich `mail` (Bezeichnung „E-Mails“, `labels.sections.mail`), Recht `send_mail` (Standardrolle „E-Mails verwalten“: `member`, `send_mail`). Für die App erst sichtbar, wenn der Mailversand eingerichtet ist (`Features::configured`, Einrichtung `email`); die Routen sperrt nur der Bereich. Tabellen `mailings` (Betreff, bereinigtes HTML, Auswahl als JSON) und `mailing_recipients` (Warteschlange und Protokoll je Empfänger mit Feld `to`/`cc`/`bcc`; `user_id` 0 bei freien Adressen), Migration 32.
- `MailingService`: Empfänger je An, Cc und Bcc aus Rollen (Grund- und Zusatzrollen, ohne `guest`), Konten (ohne Gäste) und freien Adressen; je Adresse einmal, An vor Cc vor Bcc; Konten ohne gültige Adresse übersprungen (`POST /mails/preview` nennt sie). `GET /mails/options` liefert Rollen und Konten ohne Adressen. Bilder gehören der Mail (`rich_images` Besitzer `mail`, sichtbar mit `send_mail`), in der Mail eingebettet. Keine Fußzeile, keine Abmeldung. Absender und Antworten: Kompanieadresse.
- Zustellung je Empfänger einzeln (`Mail::headerTo`/`headerCc`): `PhpMailerMailer` baut die Kopfzeilen mit allen Empfängern aus An und Cc (`preSend`) und stellt danach nur an den einen Empfänger zu (`clearAllRecipients`, `addBCC`, `postSend`); Bcc steht nie in den Kopfzeilen, nur Bcc ergibt `To: undisclosed-recipients:;`. So gelten Stand, erneute Versuche und Portionen wie beim Brett.
- Eine Warteschlange für alle Mails: Aufgabe `board_mail` ruft `MailService::runQueue` (erst Brett, dann Verwaltung; Portionsgröße, Pause `board_mail_next` und Zeitbudget gemeinsam). Eingeplant, wenn Brett oder E-Mails an und der Mailversand eingerichtet ist.
- Verlauf `GET /mails`, `GET /mails/{id}` für `send_mail`; Aufgabe `mailing_cleanup` (täglich) löscht Mails nach 365 Tagen samt Protokoll und Bildern. Sicherungen enthalten `mailings`, nicht `mailing_recipients`; Konto löschen entfernt dessen Zeilen im Protokoll.
- App: *Verwaltung → E-Mails* (`#/verwaltung/mail`, `views/EmailsVerwalten.tsx`), Empfängerfelder mit Kärtchen und Vorschlägen, derselbe Editor wie am Brett (`composeEditor.tsx`, mit „Signatur einfügen“ und Rücksprung aus der Signatur-Einrichtung), Entwurf je Gerät.

### Dateianhänge (Schwarzes Brett, E-Mails)

- `AttachmentService`, Tabelle `attachments` (Migration 33: Kennung, Besitzer `draft`/`board`/`mail`, Name, MIME-Typ aus der Endung, Größe, Datei), Dateien geschützt unter `anhaenge/anh-<Kennung>.bin`. Jeder Dateityp, je Datei höchstens `DocumentService::requestLimit()` (20 MB oder weniger nach `post_max_size`; `GET /attachments/limits`).
- `POST /attachments` (Recht `post_board` oder `send_mail`) legt einen Entwurf des Kontos an. `POST /board`, `PATCH /board/{id}` und `POST /mails` nennen die Kennungen (`attachments`); übernommen werden nur eigene Entwürfe bzw. schon vorhandene Anhänge desselben Inhalts. `PATCH` setzt die Liste genau: Fehlende werden samt Datei gelöscht, die Änderung steht im Verlauf (`attachments`, vorher/nachher als Dateinamen) und gibt der Nachricht ein neues `updated_at`.
- Auslieferung `<App>/anhang/<Kennung>` (beide Adapter, `Environment::attachmentUrl`) nach Anmeldung und Rechten wie beim Inhalt (Brett: `BoardService::canSeeImage`, Mail: `send_mail`, Entwurf: eigenes Konto); immer `Content-Disposition: attachment`, `nosniff`, `Content-Security-Policy: sandbox`, Typ nur für PDF und Bilder (außer SVG), sonst `application/octet-stream`. Der Service Worker reicht diese Adressen immer durch.
- Mails (Brett und Verwaltung) hängen die Dateien an (`Mail::attachments`, `multipart/mixed`); die Größenprüfung gegen `SIZE` zählt sie mit.
- Aufräumen: mit der Nachricht (`board_cleanup`) bzw. Mail (`mailing_cleanup`); nie gespeicherte Entwürfe nach 7 Tagen (beide Aufgaben); Konto löschen entfernt seine Entwürfe samt Dateien. Sicherungen enthalten Tabelle und Dateien gespeicherter Anhänge (`Backup::FILE_DIRS`), keine Entwürfe.

### Umfragen (Issue #4)

- Bereich `polls`, Recht `manage_polls` (Standardrolle „Umfragen verwalten“: `member`, `manage_polls`). Braucht den Bereich `board` (`requires`), denn laufende Umfragen stehen am Schwarzen Brett; es gibt keinen eigenen Tab und nichts unter „Meins“. Tabellen `polls`, `poll_options`, `poll_votes`, `poll_answers` (Migration 11), in Sicherungen enthalten.
- Teilnehmen und sehen: `PollService::isParticipant` (Recht `member`; Anwärter nicht). Verwalten (anlegen, ändern, beenden, löschen): jede und jeder mit `manage_polls`; diese sehen die Ergebnisse immer.
- Eine Frage je Umfrage: `single`, `multiple` oder `text`; Frage höchstens 150 Zeichen, Beschreibung 1000, 2–20 Möglichkeiten zu je höchstens 100 Zeichen (doppelte → 400), Freitext-Antwort höchstens 1000. Ende optional (`ends_at`, Eingabe in der Zeitzone der Kompanie oder ISO mit Zeitzone – die App schickt die Ortszeit des Geräts als ISO –, gespeichert in UTC, muss in der Zukunft liegen).
- Beim Anlegen fest: `anonymous` und `results` (`after_vote`: wer geantwortet hat, nach dem Ende alle Mitglieder; `after_end`: alle nach dem Ende; `managers`: nur `manage_polls`). Nach der ersten Antwort sind beide sowie Art und Möglichkeiten fest (`PATCH` → 400, außer gleicher Wert). Beendete Umfragen lassen sich nicht mehr ändern und nicht wieder öffnen.
- Läuft bis `ends_at` oder bis `POST /polls/{id}/close` (`closed_at`). Bis dahin `PUT`/`DELETE /polls/{id}/vote`, danach 400.
- **Anonym und trotzdem änderbar:** `poll_votes (poll_id, user_id, voted_at, updated_at)` hält fest, *wer* teilgenommen hat (für `has_voted`, das Banner und die Erinnerung). Die Antworten liegen in `poll_answers (poll_id, vote_ref, user_id, option_id, answer_text)` unter `vote_ref` = HMAC-SHA256 der Benutzer-ID mit dem Geheimnis `polls_secret` (Einstellung, beim ersten Bedarf erzeugt). `user_id` ist nur bei namentlichen Umfragen gesetzt, sonst NULL; die Tabelle hat keine Zeitstempel und keine laufende Nummer (Primärschlüssel `poll_id, vote_ref, option_id`, SQLite `WITHOUT ROWID`), damit sich anonyme Antworten nicht über Zeit oder Reihenfolge zuordnen lassen. Die API gibt bei anonymen Umfragen nie Namen oder IDs zu Antworten aus (Freitexte alphabetisch); die eigene Antwort (`my_answer`) sieht nur man selbst. Namen je Möglichkeit/Freitext gibt es nur bei namentlichen Umfragen und nur für die, die die Ergebnisse sehen dürfen.
- **`polls_secret` gehört zu den Daten, nicht in `Backup::LOCAL_SETTINGS`:** Wird eine Sicherung auf einer anderen Installation eingespielt, müssen die Pseudonyme weiter passen, sonst könnten Mitglieder ihre anonymen Antworten weder ändern noch zurückziehen, und das Löschen eines Kontos fände sie nicht. Das vorhandene `installation_secret` (Statusmeldungen) ist bewusst lokal und taugt deshalb nicht.
- Ergebnisse serverseitig gefiltert (`results_visible`, `results_data`): je Möglichkeit Anzahl und Prozent der Teilnehmenden (gerundet; bei Mehrfachauswahl zusammen über 100), Freitexte als Liste.
- Konto gelöscht (`UserData::purge`): `poll_votes` und namentliche Antworten über `user_id`, anonyme über das Pseudonym (der Kern reicht das Geheimnis mit; bei WordPress-Multisite liest der Adapter es je Seite mit `get_blog_option`). Umfragen, die die Person angelegt hat, bleiben („gelöschtes Konto“). Umfrage löschen entfernt alle Antworten.
- Benachrichtigungen (Art `polls`, Empfängerkreis `members`, für neue Abos an wie die übrigen Mitglieder-Arten): neue Umfrage an alle Mitglieder außer dem, der sie anlegt (`PushTriggers::pollCreated`); Erinnerung 24 Stunden vor dem Ende an alle Mitglieder ohne Antwort (`pollReminder`), über die stündliche Aufgabe `poll_reminders` (`PollService::remind`, nur mit Bereich). Einmal je Umfrage: `reminded_at` wird vor dem Versand mit `UPDATE … WHERE reminded_at IS NULL` beansprucht, wiederholte oder gleichzeitige Läufe erinnern nicht doppelt. Endet eine Umfrage beim Anlegen in weniger als 24 Stunden, gilt sie als erinnert; ein neues Ende mehr als 24 Stunden hin macht eine neue Erinnerung möglich. TTL höchstens bis zum Ende. Beim ersten Einschalten des Bereichs bekommen bestehende Abos der Mitglieder die Art einmalig dazu (`PushService::offerTypeToExisting`, verallgemeinert aus dem Schwarzen Brett, Einstellung `push_polls_offered`).
- **Am Schwarzen Brett:** `GET /board` liefert nach den Nachrichten für Mitglieder jede laufende Umfrage als `kind: poll` (`id`, `title`, `type`, `ends_at`, `expires_at` = `ends_at`, `has_voted`, `created_at`, `updated_at`). Das Banner zeigt nur Umfragen, die man **noch nicht** beantwortet hat (weniger Lärm), mit „Teilnehmen“; „×“ blendet je Gerät aus (Schlüssel aus Art, ID und `updated_at`, d. h. nach einer Änderung erscheint sie wieder). Die Seite `#/brett` listet alle laufenden mit „offen“/„beantwortet“ und führt zu „Beendete Umfragen“ (`#/umfragen`, mit Ergebnissen, soweit sichtbar) und mit `manage_polls` zu „Umfragen verwalten“ (`#/umfragen/verwalten`, auch über *Meins → Konto* → *Umfrage anlegen*). Seite einer Umfrage: `#/umfragen/<id>` (Ziel der Benachrichtigungen), zeigt vor dem Antworten „namentlich“/„anonym“ und wann die Ergebnisse sichtbar werden.

### Geburtstage (Issue #1)

- Bereich `birthdays` (aus, nie automatisch eingeschaltet), **kein eigenes Recht und keine Standardrolle** (`role: null`): Das Datum anderer pflegt, wer persönliche Angaben pflegt (`manage_personal`, früher `create_users`); beim Anlegen trägt es `create_users` ein. Tabelle `birthdays (user_id PK, month, day, year NULL, visible DEFAULT 1, show_age DEFAULT 0, last_notified, updated_by, updated_at)` (Migration 12), in Sicherungen enthalten; `UserData::purge` löscht sie mit dem Konto (bei WordPress-Multisite auf allen Seiten).
- Prüfung (`BirthdayService::validate`): gültiges Datum, 29.02. nur im Schaltjahr oder ohne Jahr (Maßstab 2000), Jahr ab 1900, nicht in der Zukunft (heute in der Zeitzone der Kompanie) → sonst 400.
- Selbst: `GET`/`PUT`/`DELETE /me/birthday` (Recht `member`), mit Jahr und den Schaltern `visible` („Anderen Mitgliedern zeigen“, Standard an) und `show_age` („Alter anzeigen“, Standard aus); nicht mitgeschickte Schalter bleiben. Verwaltung: `PUT`/`DELETE /members/{id}/birthday` (`manage_personal`) ändert **nur das Datum**; mitgeschickte Schalter werden ignoriert, die Person entscheidet allein. `GET /members/{id}` liefert mit Bereich `birthday` mit Jahr und Schaltern (nur mit `view_personal`; wer etwa nur Punkte verwaltet oder Konten anlegt, sieht kein Geburtsdatum). `POST /users` und `/users/bulk` nehmen optional `birthday` als `JJJJ-MM-TT` oder `MM-TT` (vorab geprüft; bei Mehrere-Anlegen mit Zeilennummer, ohne Bereich 400).
- Sehen und erscheinen nur Konten mit `member` (Gäste und Anwärter weder noch). `GET /birthdays` liefert nur `visible`-Einträge von Mitgliedern, ab heute sortiert (`days_until`, dann Name), mit `date` (nächster Geburtstag), `today` und `age` (nur mit Jahr und `show_age`; das Alter, das an diesem Geburtstag erreicht wird); **nie das Geburtsjahr**. `?days=N` = heute und die nächsten N Tage (Tag N eingeschlossen). Den Vorlauf der App stellen Admins ein (`birthday_upcoming_days`, 0–30, Standard 7; `GET`/`PATCH /admin/birthdays` mit `upcoming_days`, nur mit Bereich); `/config` nennt ihn als `birthdays.upcomingDays` (ohne Bereich `null`), die App fragt damit `?days=N`. „Heute“ aus `Time::today()` (Zeitzone der Kompanie, nicht des Servers); der 29.02. gilt in Jahren ohne Schalttag am 28.02. (`BirthdayService::inYear`).
- Benachrichtigung (Art `birthdays`, „Geburtstage: am Tag selbst“, Empfängerkreis `members`, für neue Abos an; beim ersten Einschalten des Bereichs einmalig zu bestehenden Abos, `push_birthdays_offered`): Aufgabe `birthday_notifications` stündlich (nur mit Bereich, im Wartungsmodus pausiert). Sie tut nichts, wenn Admins die Benachrichtigung ausgeschaltet haben (`birthday_notify`, Standard an), und vor der eingestellten Stunde (`birthday_notify_hour`, 0–23 Ortszeit, Standard **7 Uhr**; beides über `PATCH /admin/push` mit `birthday_notify`/`birthday_hour`, `GET /admin/push` nennt sie unter `birthdays`, ohne den Bereich `null`). Ausgeschaltet wird nichts beansprucht; eine später gewählte Stunde schickt einen heute schon gemeldeten Geburtstag nicht noch einmal; danach für jeden heutigen, sichtbaren Geburtstag eines Mitglieds einmal „Heute hat ‹Name› Geburtstag (wird N)“ an alle Mitglieder außer dem Geburtstagskind, TTL bis Mitternacht. Gegen doppelten Versand (wiederholte Läufe, Pseudo-Cron) wird je Geburtstag `last_notified` = heutiger Tag mit `UPDATE … WHERE last_notified IS NULL OR last_notified <> ?` vor dem Versand beansprucht (wie `reminded_at` bei Umfragen). Ein im Laufe des Tages eingetragener Geburtstag von heute kommt beim nächsten Lauf noch dran; unsichtbare werden nicht beansprucht.
- App: kein eigener Tab. Unter *Termine* oben die Karte „Anstehende Geburtstage“ (nur wenn es welche gibt, heute hervorgehoben); eine Seite mit allen Geburtstagen gibt es nicht (die Benachrichtigung öffnet `#/termine`). Admins stellen den Vorlauf unter *Einstellungen → Geburtstage* ein. Der Tab *Termine* erscheint für Mitglieder deshalb auch ohne Termine-Bereich. *Meins → Konto*: Karte „Geburtstag“ (Tag/Monat als Auswahl, Jahr freiwillig, beide Schalter, „Entfernen“). *Verwaltung*: in den Mitgliedsdetails „Geburtstag“ (Schalter nur zur Info), beim Anlegen (einzeln und mehrere) ein freiwilliges Feld.

### Inventar (Issue #6)

- Bereich `inventory` (aus, nie automatisch eingeschaltet), Recht `manage_inventory` (Standardrolle „Inventar verwalten“: `member`, `manage_inventory`; Admins haben es ohnehin). Nur wer das Recht hat, sieht das Inventar; Mitglieder ohne das Recht, Anwärter und Gäste bekommen 403. Alle Routen hängen am Bereich (aus → 403 `feature_disabled`).
- Tabellen (Migration 13, in Sicherungen enthalten): `inventory_items (title, category, quantity decimal(15,3), unit, location, note, created_by/at, updated_by/at, deleted_at, deleted_by)` und `inventory_log (item_id, action create|update|move|delete, changes JSON, reason, title, unit, location, user_id, created_at)`. Optionale Texte sind leer statt NULL; die API liefert dafür `null`.
- **Ein Ort je Eintrag**; dasselbe Material an zwei Orten sind zwei Einträge. Orte sind freier Text. Unterscheidet sich ein eingegebener Ort (ebenso Kategorie, Einheit) nur in Groß- und Kleinschreibung von dem eines vorhandenen Eintrags, gilt dessen Schreibweise (`InventoryService::canonical`). `GET /inventory/locations` liefert die Orte der vorhandenen Einträge als Vorschläge (`<datalist>`).
- **Mengen** ≥ 0 mit höchstens drei Nachkommastellen, als Zahl oder Text mit Komma („2,5“). Im Kern als ganze Tausendstel gerechnet (`InventoryRepository::toMilli`), damit Summen und der Vergleich „mehr als vorhanden“ auf MySQL (decimal) und SQLite (NUMERIC) exakt sind; die API liefert ganze Zahlen ohne Nachkommastellen.
- **Verlauf:** Jede Änderung schreibt genau einen Eintrag je betroffenem Inventareintrag, mit den geänderten Feldern (`changes`: Feld → `{old, new}`), optionalem Grund (`reason`, bei allen Änderungen) und Bezeichnung, Einheit und Ort als Momentaufnahme. Ändert ein PATCH nichts, entsteht kein Eintrag. Löschen ist weich (`deleted_at`), damit der Verlauf lesbar bleibt (`GET /inventory/{id}/log` auch für gelöschte). Namen über `UserNames`; gelöschte Konten erscheinen als „gelöschtes Konto“. `UserData::purge` lässt Inventar und Verlauf stehen (dort steht nur, wer etwas geändert hat).
- **Ändern:** `PATCH` mit `quantity` (absolut) oder `adjust` (z. B. ±1 von den Knöpfen −/+; addiert in der Transaktion, gleichzeitige Klicks gehen nicht verloren). `location` im PATCH berichtigt nur den Ort, ohne Zusammenführen.
- **Umlagern** (`POST /inventory/{id}/move`, eine Transaktion; MySQL sperrt Quelle und Ziel mit `FOR UPDATE`, SQLite mit `BEGIN IMMEDIATE`): Gibt es am Zielort einen Eintrag mit derselben Bezeichnung und Einheit (ohne Unterschied in Groß- und Kleinschreibung), kommt die Menge dazu; sonst entsteht ein neuer Eintrag (Kategorie und Notiz übernommen). Wird alles umgelagert, verschwindet die Quelle (beim Zusammenführen weich gelöscht), ohne passendes Ziel ändert sich nur ihr Ort. Mehr als vorhanden, 0 oder derselbe Ort → 400 ohne jede Änderung. Verlauf: Quelle und Ziel bekommen je einen Eintrag `move` mit demselben `changes.move = {from, to, quantity, source_id, target_id}` und ihrer eigenen Mengenänderung (`quantity {old, new}`, beim neuen Ziel `old: null`; ganz geleerte Quelle `new: 0` und `move.source_removed`); alles ohne Ziel ist ein einziger Eintrag mit `location {old, new}`.
- App: kein eigener Tab; Einstieg über *Meins → Konto* → *Inventar öffnen* (`#/inventar`, wie Schwarzes Brett und Umfragen). Die Liste ist **nach Material gruppiert** (Entscheidung im Issue): Schlüssel ist die Bezeichnung ohne Unterschied in Groß- und Kleinschreibung, je Gruppe die Summe über alle Orte, je Einheit getrennt („12 Stück · 3 kg“). Aufgeklappt die Einträge je Ort mit −/+, *Umlagern*, *Bearbeiten*, *Verlauf* und *Löschen* (fragt nach einem Grund); Suche über Bezeichnung, Ort, Kategorie und Notiz. Verlauf je Eintrag (`#/inventar/verlauf/<id>`) und gesamt (`#/inventar/verlauf`); je 100 Einträge, „Ältere Einträge laden“ holt die nächste Seite über `before`.
- Offen: Die Übernahme der Lanker Inventardaten (Lank #44) gehört wie die übrigen Daten ins Übernahme-Plugin (Abschnitt 15).

### Mitgliederliste für alle

- Bereich `directory` (Bezeichnung „Mitglieder“, `labels.sections.directory`; aus, nie automatisch eingeschaltet), **kein eigenes Recht und keine Standardrolle** (`role: null`).
- **Öffentlich** (Issue #112, Entscheidung im Issue): `GET /directory` mit `view_directory`; das hat anfangs jede Rolle und Gast, also auch ohne Anmeldung (Bereich aus → 403 `feature_disabled`; ohne das Recht 403, ohne Anmeldung 401). Die App-Seite, die Fotos und alle Antworten der API (beide Adapter: `Kernel::sendApi`, `RestBridge`) senden `X-Robots-Tag: noindex`, damit auch die öffentlichen JSON-Antworten von `/directory` und `/offices` nicht in Suchmaschinen landen. Darin stehen dieselben Personen (`MemberService::inDirectory`: Mitgliederbereich oder nur Kalender), nie Gast-Konten. Je Person nur `id`, `name`, `first_name`, `last_name`, `photo_url`, mit Bereich Dienstgrade `rank_title`/`rank_badge_url`, mit Bereich Titel `titles`, dazu `birthday` und `join_year` nach Wahl der Person (unten) und `candidate_role`: der Name der Grundrolle, wenn sie `promotable` ist (z. B. Anwärter, die App zeigt ihn wie einen Titel), sonst null. Sonst keine Rollen, Punkte, Strafen oder Kontodaten. `GET /directory/counts` (gleiche Rechte, also ebenfalls öffentlich) liefert für den Zähler über der Liste nur die Summen je Gruppe wie in der Anwesenheit (`[{group, count}]`, `AttendanceService::groupMap`), keine Rolle je Person. Feste Zahl von Abfragen (`QueryCountTest`).
- **Geburtstag und Eintrittsjahr auf der Seite einer Person:** Jedes Mitglied wählt je Angabe, wem sie dort erscheint (`ProfileVisibility`: `none` = nicht, Standard; `members` = angemeldete Konten mit dem Recht `member`, wie bei den Geburtstagen; `public` = allen, auch ohne Anmeldung). Geburtstag: Spalte `birthdays.profile` (Migration 21), unabhängig von `visible` (Liste der Geburtstage und Benachrichtigung); mit `show_age` und Jahr das ganze Datum und das heutige Alter (`BirthdayService::ageOn`, 29.02. in Jahren ohne Schalttag ab dem 28.02.), sonst nur Tag und Monat; nur mit dem Bereich `birthdays`. Eintritt: Tabelle `join_years` (`user_id`, `year`, `profile`; Migration 21, in Sicherungen, mit dem Konto gelöscht über `UserData::purge`; dazu `joined_on` mit dem ganzen Datum `JJJJ-MM-TT` oder leer bei nur dem Jahr, Migration 22), ab 1800 und nicht in der Zukunft. Eingabe `date` als TT.MM.JJJJ, `JJJJ-MM-TT` oder `JJJJ` (wie bei den Ehemaligen, `FormerMemberService::date`), ohne `date` das Jahr `year`; die Antwort `join_year` ist `{year, date, profile}` mit `date` als `JJJJ-MM-TT` oder `JJJJ`. Selbst eintragen: `PUT /me/birthday` (`profile`) und `GET`/`PUT`/`DELETE /me/join-year` (Recht `member`); die Verwaltung (`create_users`) setzt nur Datum bzw. Jahr (`PUT`/`DELETE /members/{id}/join-year`), nie die Anzeige; `GET /members/{id}` liefert `join_year` mit Anzeige. `GET /directory` liefert je Person `birthday` (`{month, day, year, age}`) und `join_year` (nur das Jahr, nie das ganze Datum), beides null ohne Freigabe für den Betrachter, je eine Abfrage für alle (`QueryCountTest`). Ehemalige haben stattdessen ihre Jahre aus `former_members`; beim Eintragen als ehemalig schlägt die App den Eintritt (Datum oder Jahr) als `joined_on` vor. `GET /members` liefert für `create_users` je Person `joined_on` (`JJJJ-MM-TT`, `JJJJ` oder leer) zum Sortieren nach Eintritt.
- **Ehemalige** (Tab rechts neben den Ämtern, nur mit dem Bereich `directory`): `GET /directory/former`, gleiche Rechte wie `GET /directory`. Alle als ehemalig eingetragenen Personen (`FormerMemberService::directory`) mit denselben Angaben wie die Liste, dazu nur `founder`, `joined_year` und `left_year` (Jahr bzw. leer); nie der Grund, genaue Daten, Punkte oder Strafen. Ihre Fotos sind damit ebenfalls öffentlich (`MemberPhotoService::inDirectory`). Feste Zahl von Abfragen (`QueryCountTest`).
- Fotos: Mit dem Bereich liefert `MemberPhotoService::forDelivery` die Fotos der Personen in der Liste an alle aus, auch ohne Anmeldung (`Cache-Control: public`), nie die von Gästen. Ebenso sind die Abzeichen der Dienstgrade dann öffentlich (`RankService::badgeForDelivery`).
- App: eigener Tab *Mitglieder* (`#/mitglieder`) zwischen *Dokumente* und *Verwaltung*, für alle sichtbar, mit Suche und Sortierung nach Nach- oder Vorname (gemerkt wie in der Verwaltung). Mit dem Bereich `offices` oben umschaltbar zwischen *Mitglieder* (`#/mitglieder/liste`) und *Ämter* (`#/mitglieder/aemter`, zuletzt gewählter Abschnitt im `localStorage`); Antippen einer Person (Liste, *Im Amt*, Chronik) öffnet ihre Seite `#/mitglieder/<id>` mit Foto, Dienstgrad, Titeln und darunter ihren Ämtern als Zeitstrahl (ältere Adresse `#/mitglieder/aemter/<id>` führt ebenfalls dorthin). Dort blättert Wischen bzw. die Pfeile wie in der Verwaltung (`swipe.ts`) in der Reihenfolge der Liste, aus der die Person geöffnet wurde (`setBrowseOrder` mit `directory` bzw. `directory-former`, sonst die ganze Liste in der gemerkten Sortierung), ohne Verlaufseintrag; `usePrefetchChain` lädt Fotos und Abzeichen aller Personen nacheinander vor (in der Verwaltung zusätzlich `members/<id>`).

### Ämter (Issue #112)

- Bereich `offices` (Bezeichnung „Ämter“, `labels.sections.offices`; aus, nie automatisch eingeschaltet), Recht `manage_offices` (Standardrolle „Ämter verwalten“: `member`, `manage_offices`).
- Tabellen (Migration 31): `office_groups` (`title`, `sort`), `offices` (`group_id` nullable, `title`, `sort`, `title_id` nullable), `office_terms` (`office_id`, `kind` = `period` | `point`, `start_year`, `end_year` nullable, `note`, `honor` seit Migration 25), `office_holders` (`term_id`, `user_id` nullable, `name`, `sort`). Nur Jahre (Entscheidung im Issue). `period` ohne Ende = laufend („seit …“), `point` = einzelnes Jahr. `honor` (Issue #180) ist der Haken „Ehrung“, bei beiden Arten; nur er steuert Stern und Abzeichen im Zeitstrahl, die Jahre (`termYears`) hängen weiter an `kind`. Migration 25 setzt `honor = 1` für alle bisherigen `point`-Einträge, ebenso `Backup::restore` für Sicherungen ohne die Spalte. Titel vergibt nur eine laufende Amtszeit, unabhängig von `honor`. Import: 8. Spalte `ehrung` (`ja`/`nein`/leer, ohne sie keine Ehrung); `termKey` (Dubletten) ohne `honor`. Inhaber: Konto oder freier Name; bei Konten ist `name` die Momentaufnahme beim Speichern, angezeigt wird der aktuelle Kontoname. In Sicherungen (`Backup::CORE_TABLES`).
- `GET /offices` ist öffentlich (`Route::PUBLIC`, Bereich aus → 403), mit fester Zahl von Abfragen (`QueryCountTest`). Ämter nach der Reihenfolge ihrer Gruppen (ohne Gruppe zuletzt), Amtszeiten laufende zuerst, dann nach Beginn und Ende absteigend, gleiche Jahre in der Reihenfolge der Erfassung. `user_id`/`photo_url` eines Inhabers nur, wenn er in der Mitgliederliste steht oder ehemalig ist (Bereich `directory`); `manage_offices` sieht alle Konten, jede Person ihr eigenes (für *Meins → Ämter*). `title_id`/`linked_title` nur für `manage_offices`. `holder_title` ist öffentlich: die eigene Einzahl des Amts (`offices.singular`, Migration 27, höchstens 60 Zeichen, leer ohne), sonst der Name des verknüpften Titels (nur mit Bereich `titles`). Der Zeitstrahl einer Person zeigt damit die Einzahl („Kompaniekönig“) statt der Bezeichnung des Amtes („Kompaniekönige“). In der Verwaltung zeigen *Titel verwalten* und die Mitgliedsdetails bei verknüpften Titeln „Wird über das Amt … vergeben“ (mit `manage_offices` als Link zum Amt); das Häkchen in den Mitgliedsdetails ist gesperrt.
- Pflege: `POST /office-groups`, `PATCH`/`DELETE /office-groups/{id}` (nur leere), `PUT /office-groups/order`, `POST /offices`, `PATCH`/`DELETE /offices/{id}` (mit allen Amtszeiten), `PUT /offices/order` (alle IDs, angezeigt innerhalb der Gruppe), `POST /offices/{id}/terms`, `PATCH`/`DELETE /office-terms/{id}`. Bezeichnungen höchstens 60 Zeichen, Ämter je Gruppe eindeutig; Jahre ab 1800 bis zehn Jahre in die Zukunft, Ende nicht vor dem Beginn; 1–20 Personen je Amtszeit, jede nur einmal; Zusatz höchstens 100 Zeichen.
- **Verknüpfter Titel** (Entscheidung im Issue): `offices.title_id`, je Titel höchstens ein Amt. `OfficeService::syncTitles` gleicht nach jeder Änderung an Amtszeiten, beim Verknüpfen, beim Löschen des Amts und nach dem Import ab: Wer eine laufende Amtszeit hat (Konto, `isMember()`), bekommt den Titel (mit Benachrichtigung wie bei der Vergabe von Hand; deaktivierte Titel nicht neu), wer keine mehr hat, verliert ihn. Lösen oder Wechseln der Verknüpfung lässt den bisherigen Titel bei seinen Trägern. `GET /offices/{id}/title-preview?title_id=` zeigt vorher, wer ihn bekommt bzw. verliert. Wird der Titel gelöscht, fällt die Verknüpfung weg.
- **Import** (`POST /offices/import`, Ersterfassung): CSV mit Semikolon oder Tabulator `gruppe;amt;beginn;ende;art;personen;zusatz`, höchstens 200 KB bzw. 2000 Zeilen. Ohne `apply` Vorschau je Zeile (Fehler, `duplicate`), mit `apply` alles in einer Transaktion (bei einem Fehler nichts). Gruppen und Ämter nach Namen gefunden oder angelegt; gleiche Amtszeiten (Amt, Art, Jahre, Namen) übersprungen, damit ein zweiter Lauf nichts verdoppelt. `link`: eindeutiger Kontoname → verknüpft.
- Konto löschen: Amtszeiten bleiben mit Namen stehen (`UserData::purge` setzt `office_holders.user_id` auf `NULL`).
- App: im Tab *Mitglieder* (s. o.) je Amt eine aufklappbare Karte: zugeklappt Siegel (Monogramm der Bezeichnung), aktuelle Inhaber mit Fotos (sonst die letzte Amtszeit) und „Chronik seit …“; aufgeklappt oben *Im Amt* (laufende Amtszeiten auf der Kompaniefarbe, Foto, Dauer), darunter *Frühere* als Chronik: Jahr | Linie | Karte (Personen als Chips, Dauer, Zusatz; einzelne Jahre mit Stern), Jahrzehnt-Marken ab fünf Einträgen; ab 720 px Breite Linie in der Mitte, Karte und Jahr abwechselnd gegenüber. *Meins → Ämter* und die Mitgliedsdetails der Verwaltung zeigen die Ämter einer Person. Pflege unter *Verwaltung → Ämter* (`#/verwaltung/aemter`, `/<id>`, `/import`).
- **Jahresübersicht** (Punkt 14 im Issue, nur Frontend): Auf jeder Breite schaltet *Chronik | Jahre* (gemerkt in `localStorage`, `kompanie.offices.view`) auf `YearGrid` in `views/Aemter.tsx` um: CSS-Grid mit einer Spalte je Amt (nach Gruppen) und einer Zeile je Jahr, neueste oben. `yearGrid.ts` rechnet ohne React: Ein Zeitraum belegt Beginn bis Endjahr − 1 (das Endjahr gehört dem Nachfolger, „2022–2024“ und „2024–2026“ in einer Spur), laufende bis heute; gleichzeitige Amtszeiten eines Amts bekommen eigene Spuren. Seitlich scrollt nur das Raster, senkrecht nur die Seite: Ein eigener senkrechter Scrollbereich im Raster war auf dem Handy kaum zu bedienen (Wischen bewegte das Raster statt der Seite, der Schalter *Chronik | Jahre* war nicht mehr zu erreichen). Der Kopf (Gruppen, Ämter) ist deshalb ein eigener Streifen `.year-grid-head` mit denselben Spalten wie das Raster; er bleibt unter der festen Kopfleiste stehen (`position: sticky`, Abstand `--yg-head-top`, gemessen in `useStickyHead`) und läuft seitlich mit (`scrollLeft` in beide Richtungen abgeglichen). Die Jahre stehen links fest (`position: sticky`); das Raster hat einen eigenen Stapel (`z-index: 0`), damit nichts über den Kopf malt. Spaltenbreite und Zeilenhöhe kommen aus CSS-Variablen (`--yg-years`, `--yg-col`, `--yg-row`), unter 1000 px schmaler; quer gilt das allgemeine Querformat (Abschnitt 8). Hochkant auf Touch-Geräten der Hinweis aufs Drehen. Ein Tipp auf einen Balken zeigt dessen Beschreibung unten über der Tab-Leiste (`.yg-detail`), da `title` auf Touch-Geräten nicht erscheint.
- Webseite (WordPress): Shortcode `[kompanie_aemter]` (Punkt 18 im Issue) mit `OfficeService::overview(null)`, also immer der Sicht eines Gastes; Karte je Amt mit laufenden Amtszeiten (sonst der letzten) und den übrigen als aufklappbare Chronik (`<details>`, ohne JavaScript). `gruppe="…"` zeigt nur eine Gruppe, `amt="…"` nur ein Amt (beides ohne Groß-/Kleinschreibung, kombinierbar; Werte mit typografischen Anführungszeichen setzt `Shortcodes::attribute` wieder zusammen); Bereich aus → keine Ausgabe.

### Listen (Issue #121)

- Bereich `lists` (Bezeichnung „Listen“, `labels.sections.lists`; aus, nie automatisch eingeschaltet), Recht `manage_lists` (Standardrolle „Listen verwalten“: `member`, `manage_lists`). Alle mit dem Recht sehen und bearbeiten dieselben Listen (Entscheidung im Issue).
- Tabellen (Migration 20): `lists` (`title` ≤ 120, `subtitle` ≤ 200, `list_date` nullable, `orientation` = `portrait` | `landscape`, angelegt/geändert von/am), `list_columns` (`kind` = `field` | `blank`, `field`, `label` ≤ 60, `width` = `narrow` | `normal` | `wide`, `checkbox` nur bei `blank`), `list_rows` (`kind` = `member` | `free`, `user_id` nullable, `name` ≤ 100 nur bei `free`). Höchstens 20 Spalten und 500 Zeilen. Spalten und Zeilen werden beim Speichern als Ganzes ersetzt; die App schickt den geladenen Stand (`updated_at`) mit, und hat jemand die Liste inzwischen geändert, antwortet der Server mit 409 `conflict`, statt dessen Änderungen zu überschreiben (Sekundengenauigkeit). In Sicherungen (`Backup::CORE_TABLES`).
- **Werte werden nie gespeichert**, sondern bei `GET /lists/{id}` ermittelt (feste Zahl von Abfragen, `QueryCountTest`), und nur, soweit die anfragende Person sie in der App ohnehin sieht (`ListService::availableFields`, je Spalte `available`): Name immer; Rolle (Grundrolle und Zusatzrollen) nur mit Zugang zur Mitgliederliste der Verwaltung (`Capability::memberList()`); Dienstgrad und Titel mit Bereich und öffentlicher Mitgliederliste (`directory`) oder dem Recht, sie zu pflegen; Geburtstag (nur „TT.MM.“, nur freigegebene, Issue #1) für Mitglieder; persönliche Angaben nur mit `view_personal` (`ListService::PERSONAL_FIELDS`: `address` als eine Zeile wie `Address::oneLine`, dazu einzeln `street`, `postal_code`, `city`, `country` für Serienbriefe aus der CSV, `phone`, `email`; aus `MemberContactRepository::all()` und `UserDirectory::emails()`, je eine Abfrage); Punkte nur mit `manage_points`, offene Strafen nur mit `manage_penalties`. `nr` zählt die App.
- **Mitglieder aktualisieren** (`POST /lists/{id}/sync-members`, ohne `apply` nur die Übersicht): Zeilen für alle Konten mit `member` ohne Zeile anlegen, Mitgliedszeilen gelöschter Konten und von Konten ohne `member` löschen; freie Zeilen bleiben. Neue Zeilen nach Nachname (ohne Nachnamen der Vorname, Akzente und Umlaute wie Grundbuchstaben, Zahlen nach Wert, wie `memberSort.ts`; mit `intl` über `Collator`, sonst eigene Umsetzung) vor die erste Mitgliedszeile, die später kommt, sonst hinter die letzte; ohne Mitgliedszeilen an den Anfang. Per `PUT` kommen Mitgliedszeilen nur für Konten, die schon in der Liste stehen, oder für Mitglieder dazu.
- **Konto löschen**: `UserData::purge` setzt `list_rows.user_id` auf `NULL`; die Zeile bleibt ohne Namen („Konto gelöscht“), bis die Liste aktualisiert wird. Personenbezogene Werte stehen nicht in den Tabellen.
- App: *Verwaltung → Listen* (`#/verwaltung/listen`, `/<id>`): Übersicht (anlegen, kopieren, löschen), Bearbeiten mit Entwurf und *Speichern*, Spalten und Zeilen per Griff sortierbar, *Sortieren nach* Name oder einer Datenspalte (`sortRowsBy` in `lists.ts`), Breite *automatisch* (Inhalt mit Canvas gemessen, `columnWidths`), Personen der Liste je Liste (`groups`: members und Rollen ohne Mitgliederbereich, Spalte `lists.people`). Ausgabe: Vorschau wie gedruckt, gestaltet wie die Tabellen der Kompanie (Titelbalken und Kopfzeile weiß auf `themeColor`, Überschriften schmaler Spalten außer Nr. um 45° gedreht in mitgekippten Zellen: je Spalte eine SVG-Grafik in ihrer tatsächlichen Breite (geschert), damit die schrägen Linien genau über den senkrechten beginnen; Spalten und Höhe des Kopfs in ganzen Pixeln der A4-Druckbreite (186 bzw. 273 mm), da Browser Rahmen auf ganze Pixel einrasten; Zeilen abwechselnd mit 20 % `themeColor` über alle Spalten, `print-color-adjust: exact`); *Drucken / PDF* über `window.print()` mit einer Kopie der Tabelle direkt unter `<body>` (beim Drucken nur sie, `body.list-printing`), A4 hoch oder quer (`@page`), Tabellenkopf auf jeder Seite, Zeilen nicht geteilt; *PDF herunterladen* (`listPdf.ts`, jsPDF erst beim Klick geladen; html2canvas, canvg und fast-png per `resolve.alias` durch `jspdfUnused.ts` ersetzt): dieselbe Tabelle mit Helvetica (Windows-1252, andere Zeichen ohne Akzent bzw. „?“), Maße in mm, Kopf auf jeder Seite, Seitenzahlen; *CSV* im Browser erzeugt: UTF-8 mit BOM, Semikolon, CRLF, RFC 4180, Texte mit `= + - @` am Anfang mit `'` entschärft (CSV-Injection). Gedruckt und exportiert wird der Entwurf auf dem Bildschirm.

### Nutzungsauswertung (Issue #175)

- Erfasst in `Http\Api::run()` nach jeder erfolgreichen Antwort (Status < 400), außer `Api::NOT_TRACKED` (`/config`, `/auth/*`, Abgleich der Benachrichtigungen, Formular zum Fehlermelden); die Anmeldung erfasst `POST /auth/login` selbst. Bereich = `Route::$feature` oder `app`, dazu `login`; Aktion aus der Methode (`GET` → `view`, `POST` → `create`, `PUT`/`PATCH` → `update`, `DELETE` → `delete`, `…/vote` → `vote`). Nur Konten mit Rechten, nicht in der Vorschau. Fehler beim Erfassen lassen die Anfrage nicht scheitern. Was offline aus dem Zwischenspeicher kommt, zählt nicht (kein Nachreichen aus dem Frontend).
- Tabellen (Migration 28, nicht in Sicherungen): `activity` (`user_id`, `area`, `action`, `at`; nur mit Erfassung je Person, 90 Tage), `activity_last` (zuletzt aktiv je Konto), `activity_seen` (zuletzt gesehen je Pseudonym = HMAC-SHA256 der Benutzer-ID mit `activity_secret` (`Backup::LOCAL_SETTINGS`) und Bereich, 31 Tage; `_all` = irgendein Bereich), `activity_daily` (Tag in Ortszeit, Bereich, Personen, Aktionen; ohne Personenbezug, bleibt). Geschrieben mit `ON CONFLICT` bzw. `ON DUPLICATE KEY`: höchstens fünf Abfragen je Anfrage, eine bei zusammengefassten Ansichten (`view` höchstens einmal je Person und Bereich in 15 Minuten; `QueryCountTest`).
- Einstellung `activity_personal` (Standard an): aus löscht `activity` und `activity_last` sofort; gezählt wird weiter in `activity_seen` und `activity_daily`. Aufgabe `activity_prune` täglich. Konto löschen: `UserData::purge` (Einträge, zuletzt aktiv) und `ActivityService::forget` (Pseudonym).
- Recht `view_activity` (nur Admins, nicht in `Roles::GRANTABLE`; WordPress: mit `manage_options`). API `GET /admin/activity` (Übersicht), `GET /admin/activity/entries` (Verlauf, CSV), `PUT /admin/activity/settings` (`manage_settings`). App: *Einstellungen → Nutzung*.
- Statusmeldungen (Format 3): `usage` im Lebenszeichen nur als Summen (`ActivityService::usage`, [STATUSMELDUNGEN.md](STATUSMELDUNGEN.md)).

### Rechte und Rollen

| Recht | Bedeutung |
|---|---|
| `member` | gehört zur Kompanie: bekommt Punkte, Strafen, Dienstgrade und Titel, steht in Listen, Anwesenheit und mit Geburtstag; schließt **nichts** ein (bis zur Aufteilung: alles Sehen) |
| `calendar` | interner Kalender (allein z. B. für Anwärter) |
| `view_intern` | Navigation: die Knöpfe unter „Intern“ gesammelt unter einem Knopf statt Mitglieder und Dokumente als eigene Tabs; regelt nicht, was die Person sieht |
| `view_events`, `view_news`, `view_gallery` | Tabs: öffentliche Termine, News, öffentliche Alben (`GET /events`, `/news`, `/gallery`) |
| `view_gallery_internal` | interne Bilder und Videos der Galerie (dann auch die öffentlichen Alben) |
| `view_own_points`, `view_own_penalties`, `view_own_rank`, `view_own_attendance` | eigene Daten in „Meins“ (`/me/points`, `/me/penalties`, `/me/rank` und Rang/Titel in `/me`, `/me/attendance`); nur mit `member` |
| `view_birthdays` | Geburtstage der anderen sehen und am Tag benachrichtigt werden (eintragen und erscheinen: `member`) |
| `vote_polls` | an Umfragen teilnehmen (mit Konto) |
| `view_directory`, `view_offices`, `view_former` | Intern: Mitgliederliste (`/directory`, `/directory/counts`), Ämter (`/offices`), Ehemalige (`/directory/former`) |
| `view_documents`, `view_board`, `view_ranks`, `view_catalog` | Intern: Dokumente, Schwarzes Brett lesen (auch Mails und Push dazu), Dienstgrade, Punktekatalog |
| `manage_points` | Punkte vergeben und stornieren, Katalog pflegen |
| `manage_penalties` | Strafen vergeben, als bezahlt markieren, stornieren |
| `manage_ranks` | Dienstgradliste pflegen, befördern und degradieren |
| `manage_titles` | Titelliste pflegen und Titel vergeben |
| `manage_attendance` | Anwesenheitslisten je Termin führen |
| `manage_events` | öffentliche Termine pflegen |
| `manage_documents` | Dokumente pflegen und **alle** Dokumente sehen, auch die mit eingeschränkter Sichtbarkeit |
| `post_board` | Nachrichten ans Schwarze Brett schreiben, ändern und vorzeitig löschen |
| `manage_polls` | Umfragen anlegen, ändern, beenden und löschen; alle Ergebnisse sehen |
| `manage_inventory` | Inventar sehen und pflegen: anlegen, ändern, umlagern, löschen, Verlauf |
| `manage_offices` | Ämter, ihre Gruppen und Amtszeiten pflegen, auch frühere; Import (Issue #112) |
| `manage_lists` | Listen zum Drucken und als CSV anlegen, bearbeiten und löschen; Werte nur, soweit sichtbar (Issue #121) |
| `send_mail` | E-Mails aus der Verwaltung an Rollen, Mitglieder und freie Adressen versenden; Verlauf mit Protokoll |
| `view_personal` | Persönliche Angaben anderer sehen: E-Mail-Adresse, Handynummer, Adresse, ganzes Geburtsdatum (Verwaltung → Mitglied) |
| `manage_personal` | Persönliche Angaben anderer ändern (setzt `view_personal` voraus); Geburtstage anderer eintragen (nur das Datum) |
| `create_users` | Mitglieder anlegen (auch mit ihren persönlichen Angaben), Startpasswörter erzeugen; Ehemalige eintragen, wieder aufnehmen, importieren |
| `promote_users` | Rollen vergeben (Einschränkungen siehe unten) |
| `manage_settings` | Einstellungen, Updates, Sicherung (Endpunkte `/admin/*`; bei WordPress haben das die WP-Administratoren, die Einstellungen stehen dort zusätzlich im WP-Admin) |

**Jedes Recht gilt für sich** (Aufteilung der Sichtbarkeit): Der Server prüft die `view_*`-Rechte an den Routen, Dateien (Abzeichen, Dokumente, interne Bilder) und Empfängern (Push, Mails vom Brett). Voraussetzungen stehen nur in `Capability::requires()` (eigene Daten und alles zum Verwalten brauchen `member`, `manage_personal` braucht `view_personal`); was seine Voraussetzung nicht hat, fällt aus den wirksamen Rechten (`RoleService::effective`). **Ohne Anmeldung gelten die Rechte der Rolle `guest`** (`App::viewer()`/`App::anonymous()`, `Api::authorize`, `/config.guestCapabilities`, auch die Shortcodes der Webseite). Die Oberfläche (`capabilities.ts`, `rightsTree.tsx`, `RechteMatrix.tsx`) zeigt die Rechte in Gruppen zum Ein- und Ausklappen mit einem Haken je Gruppe und als Übersicht „Wer sieht was“ (Rechte × Rollen).

**Migration** (`RoleService::migrateViewRights`, einmalig, Einstellung `roles_view_split`): Früher schloss `member` alles Sehen ein, Öffentliches sah jeder, `calendar` brachte das Brett mit. Jede gespeicherte Rolle bekommt `Roles::expandLegacy(...)` (Gast `PUBLIC_DEFAULTS`, Mitglied `MEMBER_DEFAULTS`, Rollen nur mit Kalender dazu `view_board`); die Ausnahmen einzelner Personen werden aus ihren alten wirksamen Rechten neu berechnet, sodass jede Person danach genau dasselbe sieht (`ViewRightsMigrationTest`).

**Rollen legt der Admin der Kompanie in der App an** (*Einstellungen → Rollen*, Issue #28; `Domain\RoleService`). Jede Person hat genau **eine Grundrolle** und beliebig viele **Zusatzrollen** (Issue #66, Abschnitt unten). Eine Rolle hat einen Namen, Rechte aus der Tabelle oben und einen **Punktefaktor**.

- **Fest** (umbenennen; Namen bei der Ersteinrichtung aus `kompanie.json`, `labels.roles`; nicht löschbar):

  | Rolle | Rechte |
  |---|---|
  | `guest` (Gast) | anfangs `Roles::PUBLIC_DEFAULTS`; nur Rechte zum Sehen ohne Voraussetzung änderbar; gilt auch ohne Anmeldung |
  | `member` (Mitglied) | anfangs `Roles::MEMBER_DEFAULTS`; immer `member`, sonst nur Rechte zum Sehen änderbar – Rolle neuer Konten; Punktefaktor änderbar |
  | `admin` (Admin) | alle; Punktefaktor änderbar |

  Reihenfolge überall (Auswahl, Zusatzrollen; Zähler umgekehrt, von unten nach oben) so, wie der Admin alle Rollen per Ziehen ordnet (`PUT /admin/roles/order`, auch die festen); anfangs `guest`, `member`, eigene Rollen, `admin`. Neue Rollen kommen ans Ende, vor `admin`, wenn der dort steht.

- **Standardrollen der Bereiche:** Schaltet der Admin einen Bereich ein, der Rechte braucht, kommt dessen Standardrolle „‹Bereich› verwalten“ dazu (`Domain\Features`, Feld `role`), sofern es sie oder eine Rolle mit diesem Namen noch nicht gibt. Schaltet er ihn aus, verschwindet sie, wenn ihr niemand zugeordnet ist. Ihre Rechte wirken nur, solange der Bereich an ist.

  | Bereich | Standardrolle | Rechte |
  |---|---|---|
  | Termine | Termine verwalten | `member`, `manage_events` |
  | Punkte | Punkte verwalten | `member`, `manage_points` |
  | Strafen | Strafen verwalten | `member`, `manage_penalties` |
  | Dienstgrade | Dienstgrade verwalten | `member`, `manage_ranks` |
  | Titel | Titel verwalten | `member`, `manage_titles` |
  | Anwesenheit | Anwesenheit verwalten | `member`, `manage_attendance` |
  | Dokumente | Dokumente verwalten | `member`, `manage_documents` |
  | Schwarzes Brett | Schwarzes Brett verwalten | `member`, `post_board` |
  | Umfragen | Umfragen verwalten | `member`, `manage_polls` |
  | Inventar | Inventar verwalten | `member`, `manage_inventory` |
  | Ämter | Ämter verwalten | `member`, `manage_offices` |
  | Listen | Listen verwalten | `member`, `manage_lists` |
  | E-Mails | E-Mails verwalten | `member`, `send_mail` |

- **Eigene Rollen** bekommen feste Schlüssel `r1`, `r2`, … (nie wiederverwendet, `roles_seq`; höchstens 10 Zeichen, Spalte `users.level`).
- **Vergebbar** sind alle Rechte außer `view_activity` und `manage_settings`: Einstellungen, Updates und Datensicherung (also auch das Einspielen von Programmcode) bleiben den Admins. Verwaltungsrechte und `view_own_*` brauchen `member` (`Capability::requires()`); die übrigen Rechte zum Sehen gehen auch ohne. Standardrollen der Bereiche bekommen dazu, was Mitglieder anfangs sehen (`RoleService::featureChanged`).
- **Eigene Rollen für neue Konten und zum Aufnehmen:** Je eigener Rolle `new_accounts` (wählbar beim Anlegen von Konten, `POST /users`; `member` immer) und `promotable` (wer `create_users` hat, macht Personen dieser Rolle zum Mitglied, `POST /members/{id}/promote`; nur für Rollen ohne `member`). Wer kein Admin ist, legt Konten nur mit Rollen an, die höchstens `member` bzw. `calendar` haben oder deren Rechte er weitergeben darf (`RoleService::canCreateAs`). Andere Rollenwechsel bleiben bei `promote_users`.
- **Anwärter** (Issue #23) sind eine eigene Rolle, z. B. nur mit `calendar`, `new_accounts` und `promotable`. Bis zu dieser Version war `candidate` fest; `RoleService::migrateCandidate` macht sie einmalig (Einstellung `roles_candidate_own`) zu einer eigenen Rolle mit demselben Schlüssel, ihrem Namen, `calendar`, `new_accounts` und `promotable`, vor `member` (die Zähler zeigen die Rollen von unten nach oben, Anwärter also nach den Mitgliedern) – auch, wenn sie nie gespeichert war, aber jemand sie hat. Neue Installationen haben sie nicht. Seit der Aufteilung bekommt sie dazu `view_board` und das Öffentliche (`Roles::CANDIDATE_DEFAULTS`). Bei WordPress steht die Rolle in der Benutzer-Option; eine eigene WP-Rolle gibt es nicht.
- **Keine Rechte weitergeben, die man nicht hat:** Wer Rollen vergeben darf, ohne Admin zu sein, vergibt und ändert nur Rollen, deren Rechte er selbst hat und die weder Konten anlegen noch Rollen vergeben dürfen. Dieselbe Regel gilt für das Zurücksetzen von Passwörtern (sonst ließe sich ein Konto mit mehr Rechten übernehmen).
- **Verwaltung:** Wer nur Punkte (bzw. nur Strafen) vergibt, sieht in der Mitgliederliste nur diese Daten.
- **Löschen:** Sind einer Rolle noch Personen zugeordnet, wählt der Admin eine Ersatzrolle.
- **Gespeichert** als Einstellung `roles` (die Einstellungen lädt jede Anfrage ohnehin auf einmal; die Rechte einer Rolle kosten keine eigene Abfrage). Ändert der Admin Rechte, gilt das sofort.
- **Punktefaktor** (z. B. 0,5 für junge Mitglieder, in Lank „Jungmariner“; bei Zusatzrollen der kleinste aller Rollen der Person): gilt **beim Buchen** und wird mit dem Eintrag gespeichert (`points.factor`); es zählt `points × factor` mit zwei Nachkommastellen. Eine spätere Änderung der Rolle ändert alte Einträge nicht. Beim Buchen zeigt die App den Faktor je Person und lässt ihn übergehen (`apply_factor: false`).
- **Ältere Installationen** (vor #28) behalten beim Update ihre festen Rollen `organizer` (Verwalter) und `manager` (Leitung); ein früheres Recht `manage` wird zu `manage_points` und `manage_penalties`.

- **Grundrolle und Zusatzrollen** (Issue #66): Die Grundrolle ist die bisherige eine Rolle (Standalone `users.level`, WordPress Benutzer-Option `kompanie_app_role`); bestehende Installationen behalten sie unverändert. Zusatzrollen stehen in der Tabelle `member_roles (user_id, role, assigned_at)` des Kerns (Migration 14), in beiden Varianten gleich; bei WordPress gibt es dafür **keine** WP-Rollen, das WP-Backend sieht nur die Grundrolle.
  - **Rechte** = Vereinigung der Rechte aller Rollen. Ausgerechnet nur in `RoleService::capabilitiesFor(id, Grundrolle)`; die Adapter rufen es über ihre Closure (`fn (string $role, int $id)`) beim Erzeugen jedes `User` auf. Alle Zuordnungen kosten zusammen **eine** Abfrage je Anfrage (zwischengespeichert in `RoleService`), Listen bleiben ohne Abfrage je Person; für Gäste und Rollen ohne Mitgliederbereich fällt sie ganz weg. Eine Vorschau „App als Rolle ansehen“ (#63) setzt dort andere Rollen ein.
  - **Zusatzrollen sind nur eigene Rollen** (auch Standardrollen der Bereiche und frühere wie Verwalter), nicht Gast, Mitglied oder Admin: Diese drei bestimmen, wer überhaupt zur Kompanie gehört; Admin hätte zudem `manage_settings`, das sonst keine Rolle vergeben kann. `/config.roles[].extra` sagt, welche Rollen taugen.
  - **Nur zu einer Grundrolle mit `member`** und nicht für Admins (die haben ohnehin alles). Damit ändert eine Zusatzrolle nie, ob jemand Mitglied, Anwärter oder Gast ist. Bekommt jemand in der App eine Grundrolle ohne `member`, fallen die Zusatzrollen weg; wechselt die Grundrolle anderswo (WP-Backend, geänderte Rechte der Rolle), wirken gespeicherte Zusatzrollen nicht, bis die Grundrolle wieder `member` hat.
  - **Was an den Rechten hängt, gilt für alle Rollen zusammen:** Sichtbarkeit (Mitgliederbereich, interner Kalender, Brett, Geburtstage, Dokumente …) und Empfänger von Benachrichtigungen fragen Rechte ab (`User::can`, `isMember`). **Was am Schlüssel der Rolle hängt, nur an der Grundrolle:** `isAdmin()`, „Als Mitglied aufnehmen“ (nur Rollen mit `promotable`), die Gruppen der Anwesenheit, die WordPress-Administratoren, die Liste ohne Gäste.
  - **Vergeben** über `PATCH /members/{id}/role` mit `extra_roles` (Liste; allein oder zusammen mit `level`, dann gegen die neue Grundrolle geprüft), Recht `promote_users`. Dieselben Regeln wie bei der Grundrolle: nicht die eigenen Rollen; wer kein Admin ist, vergibt **und entfernt** nur Rollen, die er auch als Grundrolle vergeben dürfte (`canHandle`), und nur bei Personen, die nicht mehr Rechte haben als er (`canHandleUser`, mit den Rechten aus Zusatzrollen).
  - **API abwärtskompatibel:** `level` bleibt überall die Grundrolle; dazu `extra_roles` in `/members`, `/members/{id}`, `/me` und `/config.user`, `capabilities` enthält alle Rechte zusammen. `/admin/roles` zählt in `users` Grund- und Zusatzrollen, `extra_users` nur diese.
  - **Löschen einer Rolle:** Auch wer sie nur als Zusatzrolle hat, zählt als zugeordnet (Ersatz nötig). Als Zusatzrolle wird sie durch die Ersatzrolle ersetzt, wenn die als Zusatzrolle taugt und nicht schon die Grundrolle ist; sonst fällt sie weg. Eine Standardrolle verschwindet beim Ausschalten des Bereichs nur, wenn sie auch niemand als Zusatzrolle hat (ihr Schlüssel kommt beim Einschalten wieder; nicht wirkende Zuordnungen werden deshalb mit entfernt).
  - Konto löschen (`UserData::purge`) löscht die Zuordnungen; Sicherungen enthalten `member_roles`.

- **WordPress:** Admin ist, wer WP-Administrator ist. Admin-Rechte werden nur im WP-Backend vergeben.
- **Standalone:** Admins können andere zu Admins machen. Der **letzte Admin kann nicht herabgestuft** werden, niemand ändert seine eigene Rolle.
- **Mitglieder** legt nur ein Admin an (Startpasswort, nur einmal angezeigt). Vorschlag zur Erleichterung: mehrere Namen auf einmal eintragen und die Zugangsdaten als **Zettel zum Ausdrucken** ausgeben.

Die `/me`-Routen lesen die Benutzer-ID immer aus der Sitzung. Stornierte Einträge werden nur ausgeblendet; gespeichert wird, wer sie angelegt hat.

#### Vorschau „App als Rolle ansehen“ (Issue #63)

Ein Admin sieht die App mit den Rechten einer Rolle bzw. ohne Anmeldung (*Einstellungen → Rollen → Ansehen*, „App ohne Anmeldung ansehen“), ohne anderes Konto. Umgesetzt **einmal im Kern** (`Auth\Preview`), beide Adapter reichen nur den Wert der Anfrage durch.

- **Ohne Zustand auf dem Server, je Tab:** Die App merkt sich die Wahl in `sessionStorage` (übersteht Neuladen, nicht andere Tabs) und schickt bei jeder API-Anfrage den Header `X-Kompanie-Preview: <Rollenschlüssel>` bzw. `@public`. Bilder, Dokumente und Abzeichen lädt der Browser ohne eigene Header (`<img>`, Links, pdf.js): Ihre Adressen (`Environment::photoUrl/documentUrl/badgeUrl/memberPhotoUrl`) tragen in der Vorschau den Parameter `?vorschau=…` (`Auth\PreviewEnvironment` legt sich um das Environment des Adapters), und die Auslieferung wertet ihn genauso aus. So kommen auch Dateien nur mit den Rechten der Rolle – nicht nur die Liste zeigt weniger.
- **Wo:** `App` legt `Preview` um den `AuthContext` des Adapters; `$app->authContext->currentUser()` ist damit überall (API, Auslieferung von Dateien, Wartungsmodus, Dienste) die Person der Vorschau. `Api::dispatch` setzt die Vorschau je Anfrage aus `Request::$preview` (Header) oder `?vorschau` und danach zurück (WordPress-Tests laufen in einem Prozess); die Adapter rufen vor Foto, Dokument und Abzeichen `Preview::begin()`.
- **Nur für Admins:** Gilt nur, wenn die tatsächlich angemeldete Person `isAdmin()` ist und es die Rolle gibt (nicht `admin` selbst). Sonst wird sie **ignoriert** – Nicht-Admins bekommen nie andere Rechte; `/config.preview` ist dann `null`, und die App beendet die Vorschau. Entschieden gegen ein 403: Läuft die Sitzung des Admins ab, soll die App normal weiterlaufen, statt nur Fehler zu zeigen.
- **Als Rolle:** dieselbe Person (ID, Name, Benutzername – „Meins“, Geburtstag, Konto zeigen die eigenen Daten des Admins, soweit die Rolle sie hat), Grundrolle = die gewählte Rolle, **keine Zusatzrollen** (`User::$preview`; `RoleService::extraRolesOf` liefert dann `[]`, auch wenn am Konto welche gespeichert sind), Rechte = `RoleService::capabilities(rolle)`. Kombinationen mehrerer Rollen gibt es nicht. **`@public`:** wie ohne Anmeldung (`currentUser()` = `null`).
- **Nur ansehen, auf dem Server:** Trägt eine Anfrage den Header oder Parameter – auch ungültig oder von Nicht-Admins –, lehnt die API alles außer `GET`/`HEAD` mit `403 preview_readonly` („In der Vorschau kannst du nichts ändern.“) ab, vor Bereich, Wartung und Rechten. Ausnahme `POST /auth/logout` (`Api::PREVIEW_OPEN`). Damit sind auch Anmelden, Passwort, Push-Abo und `POST /me/push/status` (gibt fremde Abos frei) gesperrt; die App fragt den Push-Status in der Vorschau gar nicht erst ab.
- **`/config`** liefert `user`, `capabilities`, `features`, `setupPending` wie für die Rolle und `preview: {role, label}`; das CSRF-Token bleibt das der echten Sitzung (zum Abmelden).
- **Wartungsmodus:** Als Nicht-Admin-Rolle sieht man die Wartungsseite (so sieht es die Rolle); das Banner mit „Beenden“ bleibt darüber.
- **Zwischenspeicher:** Start und Ende laden die App neu (leert `dataCache`, frische Konfiguration; die Seite liefert die des Admins mit, `preparePreview()` holt vor dem Start die der Rolle). Der Service Worker reicht Anfragen mit dem Header bzw. dem Parameter `vorschau` durch, ohne sie zu speichern oder aus dem Speicher zu beantworten; Dateien der Vorschau kommen mit `Cache-Control: no-store`. Die App räumt in der Vorschau keine gespeicherten Dokumente auf.
- Der Wert ist auf 32 Zeichen begrenzt; die Vorschau ändert keine Daten und braucht deshalb keine eigene Tabelle oder Sitzung.

### Routen

Der Kern enthält **eine Routentabelle**. WordPress registriert sie unter `/wp-json/kompanie-app/v1/` (bei einfachen Permalinks über `?rest_route=`), Standalone unter `/api/v1/` und **ohne URL-Umschreibung** über `index.php?r=/api/v1/…` (ein Query-Parameter funktioniert auf jedem Server, auch wo `PATH_INFO` fehlt; `index.php/api/v1/…` wird zusätzlich verstanden). Ist ein Bereich ausgeschaltet, sind auch seine Routen gesperrt.

## 8. Frontend

**Querformat:** Das Manifest legt keine Ausrichtung fest (`orientation: any`, beide Adapter); installierte Apps drehen mit dem Handy (Android übernimmt ein geändertes Manifest erst beim nächsten Abgleich, iOS beachtet die Angabe nicht). Für Handys quer gilt in `styles.css` ein Block `@media (orientation: landscape) and (max-height: 520px)`; Tablets und Computer sind höher und bleiben unverändert, hochkant ebenso:
- Die Tab-Leiste steht als Leiste links (`--rail-w`), `--tabbar-h` ist 0; alles, was damit rechnet (`.app`, `.toast`, `.list-save`, Jahresübersicht), braucht unten keinen Platz mehr. Wer in TypeScript mit der Tab-Leiste rechnet, prüft, ob sie unten steht (`dragSort.tsx`, `views/Aemter.tsx`: Breite mindestens halbe Fensterbreite).
- Kopfleiste einzeilig und nicht fest (`position: relative`), Banner (Schwarzes Brett, „App aufs Handy holen“) einzeilig.
- `.content` ohne 640-px-Grenze; rechts die Safe-Area (`--content-pad-r`, auch für das Karussell `.member-detail`). `.card-list` und ähnliche Listen werden zu einem Raster mit Spalten ab 320 px (bei 844 px zwei), aufgeklappte Karten nehmen die ganze Zeile; Listen zum Sortieren (`:has(.drag-handle)`) bleiben einspaltig. `.form` stellt direkte `.field`-Kinder paarweise nebeneinander, alles andere (Gruppen, Textfelder, Hinweise, Knöpfe) über die ganze Breite. Lesetexte (Artikel, Hilfe, Lizenzen, Neuerungen) höchstens 760 px.

| Heute (an WordPress gebunden) | Künftig |
|---|---|
| Konfiguration als `window.MKL_CONFIG` | dieselben Daten wie `GET /config`, direkt in die `index.html` geschrieben |
| `X-WP-Nonce`, Erneuerung über `admin-ajax.php` | Header-Name und Token aus `/config`, Erneuerung über `GET /auth/csrf` |
| Anmelden über `wp-login.php` | `auth.mode` aus `/config`: `form` oder `redirect` |
| `X-WP-TotalPages` | `X-Total-Pages` |
| Service Worker kennt den API-Pfad fest | API-Pfad beim Registrieren übergeben |
| Build im Plugin-Ordner | Build nach `dist/`, das Paket-Skript verteilt es |

### Ladezeit und Offline-Betrieb (Issue #18)

- **Code-Splitting:** Beim Start lädt die App nur React, die API-Schicht und die Ansichten, mit denen Gäste meist beginnen (Termine, News). Alle anderen Ansichten (Verwaltung, Einstellungen, Galerie, Meins, Anmelden, Hilfe, Wartungsseite …), DOMPurify (nur in der News-Detailansicht) und pdf.js kommen erst bei Bedarf (`lazyView` in `ui.tsx`). Fehlt ein Programmteil, z. B. weil nach einem Update die Dateien der alten Version weg sind, lädt die App einmal neu (höchstens einmal je Minute, gemerkt in `sessionStorage`; ohne `sessionStorage` oder offline erscheint eine Meldung).
- **Service Worker** (`packages/frontend/public/sw.js`, beim Build ergänzt von `vite.config.ts`; die eigenständige Version bekommt eine Kopie neben `index.php`, damit sein Bereich die ganze App umfasst): Der Build setzt die Liste aller Programmdateien (auch der nachgeladenen, ohne die selten gebrauchten Bild-Dekodierer von pdf.js) und der Icons sowie eine Build-Kennung ein. Beim Installieren speichert er sie vorab in `kompanie-static-<Build>` (unveränderte Dateien aus dem Speicher der vorigen Version) und die App-Seite in ihrer aktuellen Fassung; beim Aktivieren löscht er die Speicher alter Versionen. Die App-Seite kommt sofort aus dem Speicher, ohne auf den Server zu warten (`appPage`; vorher aus dem Netz mit bis zu 2,5 s Wartezeit: gemessen mit 0,5 s Serverzeit je PHP-Aufruf startete die angemeldete App nach 0,64 s, jetzt nach 0,12 s). Die aktuelle Seite holt der Service Worker im Hintergrund, speichert sie und meldet dem neuen Fenster (`resultingClientId`, sonst allen) `{type: 'kompanie-page', reload, csrf, auth}`, wenn sie anders ist (`pageChange`: Konfiguration aus `window.KOMPANIE_CONFIG` und Programmdatei verglichen). CSRF-Token und Adresse zum Abmelden (WordPress: mit Token) übernimmt die App still (`takeTokens`), bei anderen Unterschieden (Person, Rechte, Bereiche, Version, Programmdateien) lädt sie neu, aber nur in den ersten 20 Sekunden nach dem Start und höchstens einmal je Minute (`pageUpdate.ts`, `pageReload.ts`); sonst gilt die neue Seite beim nächsten Öffnen. Aus dem Netz (nach 2,5 s ohne Antwort aus dem Speicher) kommt sie nur noch kurz nach einer eigenen Änderung, mit Parametern in der Adresse (z. B. Rückkehr von Google) und bei einem Aufruf von außerhalb der App (`referrer`, z. B. zurück von `wp-login.php`: Die Anmeldung bei WordPress sieht der Service Worker nicht). API-Antworten (ohne `/admin/`) aus dem Netz, offline aus `kompanie-data-v2` (höchstens 100 Einträge, die ältesten fallen heraus, die App-Seite bleibt). Fragt die App mit `X-Kompanie-Stale-Ok` (alle GET außer `/config`, nicht in der Vorschau; Issue #108), kommt die gespeicherte Antwort sofort und die aus dem Netz im Hintergrund: Ist sie anders oder ein Fehler, meldet der Service Worker das den Fenstern (`postMessage`), `useAsync` und das Brett laden neu; dieses Neuladen kommt aus dem Speicher (nach einem Fehler aus dem Netz, damit die App ihn sieht). Sofort nur für dieselbe Person (`X-Kompanie-User`, beim Speichern an der Antwort vermerkt) und nur, was nach der letzten eigenen Änderung angefragt wurde (`X-Kompanie-Stored-At` ist der Beginn der Anfrage: Eine Antwort auf eine Anfrage von vor der Änderung, die erst danach ankommt, zeigt den Stand davor) (API-Anfrage außer GET, nicht der Push-Status beim Start; der Zeitpunkt liegt auch im Speicher und gilt nach einem Neustart weiter). Kurz nach einer Änderung (60 s, z. B. Anmeldung) wartet die App-Seite auf das Netz, statt die gespeicherte zu zeigen: Diese enthält evtl. die Konfiguration der Person davor. So bleibt kein „Lädt …“ stehen, wenn das Netz hängt, solange es einen gespeicherten Stand gibt. Dokumente (`kompanie-docs-v1`) und Abzeichen (`kompanie-badges-v1`) kommen zuerst aus dem Speicher. Beim Abmelden löscht die App alle Speicher außer den Programmdateien.
- **Daten im Client:** Alle Ansichten laden über `useAsync` mit Schlüssel (Issue #100): Beim nächsten Öffnen erscheinen sofort die zuletzt geladenen Daten, im Hintergrund kommen frische (`dataCache.ts`, nur im Speicher, höchstens 200 Einträge, der am längsten nicht gespeicherte fällt heraus). Ansichten mit denselben Daten teilen sich den Schlüssel (z. B. `documents`, `settings`, `ranks-all`). Jede Anfrage außer GET (eigene Änderung) leert ihn; Antworten auf Anfragen, die vorher begonnen haben, werden nicht mehr abgelegt, und die Ansicht lädt dann einmal neu (sonst überschriebe die alte Antwort den gerade gespeicherten Stand). Weichen die frischen Daten von den gezeigten ab, zählt `revision` hoch; Formulare, die ihre Felder einmal übernehmen (Google, Kalender, Galerie, Neuigkeiten), bauen damit neu auf. Nicht darüber: das Brett (eigener Speicher in `board.ts`) und der Push-Status unter Benachrichtigungen (gilt je Gerät, erneuert ein Abo still). *Meins* lädt `/me` einmal für Punkte und Strafen.
- **Vorabladen (Issue #104):** Damit auch das *erste* Öffnen einer Ansicht ohne „Lädt …“ auskommt, lädt die App ihre Daten vorab. Schlüssel und Ladefunktion je Ansicht stehen an einer Stelle (`viewData.ts`, `sources`); die Ansichten laden mit denselben, `tabSources(tab)` sagt, was die Startseite eines Tabs braucht (mit denselben Bedingungen wie die Ansicht, z. B. der zuletzt gewählte Abschnitt unter *Mitglieder*). Auslöser: `pointerdown` und Fokus auf einem Tab der Tab-Leiste (auf dem Handy etwa 100–300 ms vor dem `click`), dazu der Programmteil der Ansicht (`lazyView(…).preload()`; ist er schon geladen, erscheint die Ansicht ohne Umweg über `Suspense`); ebenso Album, Umfrage, Anwesenheitsliste und Mitglied in der Verwaltung (`prefetchOnTap`). Nach dem Start, wenn der Browser untätig ist (`requestIdleCallback`, sonst nach einer Sekunde), die Daten der übrigen sichtbaren Tabs in der Reihenfolge der Tab-Leiste, und nur mit aktivem Service Worker deren Programmteile (aus seinem Speicher, kein Download). Die Warteschlange (`prefetch.ts`) stellt höchstens eine Anfrage gleichzeitig; Antippen kommt vor dem Leerlauf. Abgelegt wird über `dataCache.prefetch`: nur ohne eigene Änderung seit dem Start der Anfrage, nichts doppelt. Öffnet die Ansicht, während die Anfrage noch läuft, wartet `useAsync` auf sie (`pendingPrefetch`); kam die Antwort eines Antippens vor höchstens 10 s (`FRESH_MS`), fragt sie nicht gleich noch einmal (`takeFresh`, einmalig; eine Meldung „neue Daten“ des Service Workers hebt das auf). Was im Leerlauf kam, zeigt sie sofort und lädt wie sonst im Hintergrund neu: Bis dahin kann sich auf dem Server etwas geändert haben. Vorab-Anfragen laufen still (`api.inBackground`: `requestFull` leitet weder bei 401 noch bei abgelaufenem CSRF-Token zur Anmeldung weiter und vermerkt nichts für „Fehler melden“); die Ansicht lädt beim Öffnen selbst und meldet, was dann noch schiefgeht. Nichts vorab in der Vorschau „App als Rolle ansehen“ und im Wartungsmodus; im Leerlauf außerdem nicht offline, bei `navigator.connection.saveData` und bei `effectiveType` `2g`/`slow-2g` (Safari kennt `navigator.connection` nicht). Nur JSON, keine Bilder und Dokumente; nur Tabs, die die Person sieht (`allowed` in `App.tsx`), daher keine 403. Daten der Verwaltung (`/admin/…`) nie im Leerlauf: Deren Startseite ist ein Menü ohne Daten. `/calendar` und `/gallery` sind dabei kein Sonderfall: Der Kalender erneuert eine veraltete Fassung höchstens einmal je 30 s und sonst stündlich im Hintergrund, die Galerie ist eine Abfrage der Datenbank.
- **Seiten hinter einem Menü:** Ist das Menü von *Meins*, die Startseite der Verwaltung (`AdminMenu`, auch *Einrichtungen*) oder die Übersicht der Einstellungen zu sehen, lädt die App im Leerlauf die Daten der Seiten hinter den angezeigten Einträgen vorab (`menuSources`, `usePrefetchMenu` in `viewData.ts`; welche Einträge es gibt, entscheidet das Menü mit seinen Rechten), beim Antippen eines Knopfs sofort (`prefetchOnTap`); ebenso *Termine → Öffentliche Termine verwalten* für alle mit `manage_events`. Die Ansichten laden mit denselben `sources`. Gemessen mit 0,8 s Serverzeit je Anfrage (Admin, 36 Seiten nacheinander geöffnet): Vorher warteten 21 Seiten beim ersten Öffnen eine ganze Serverantwort, danach 7 (Album, Umfragen und Inventar ohne vorheriges Menü, Konto teilweise, Rollen, Nutzung, Einstellungen bei vollem Vorrat). Antworten unter `/admin/` speichert der Service Worker weiterhin nie; sie liegen nur im Speicher der App.
- **Gerüst beim Laden:** Hat eine Ansicht noch keine Daten (auch nicht aus dem Speicher des Service Workers), zeigt `Loading` statt „Lädt …“ den Aufbau, den der Bereich beim letzten Mal hatte, als graue Platzhalter (`skeleton.tsx`, `skeletonCapture.ts`). Gemerkt wird nach dem Laden, was an die Stelle des `Loading` getreten ist: Elemente, Klassen, Textlängen in Stufen von 4 Zeichen und die Größe von Bildern und Feldern, kein Text und keine Adressen; Schlüssel ist die Seite und die Stelle im DOM (Elternelemente bis `main` mit Klassen und welches ladende Element darin; `useId` taugt nicht, es zählt beim Rendern im Browser nur weiter). Fehlermeldungen werden nicht gemerkt, Listen nach 12 Einträgen gekürzt. Das Gerüst steht mit `display: contents` im Raster des Bereichs wie der echte Inhalt. Die Gerüste liegen im Cache Storage `kompanie-ansicht-geruest-v1` (höchstens 80, `deviceStore.ts`); `main.tsx` lädt sie vor dem Start (höchstens 300 ms warten). Jeder Eintrag gehört zu Version der App und Benutzer (bzw. Gast); andere löscht die App beim Start, ohne sie zu zeigen. In der Vorschau wird nichts gemerkt oder gezeigt. Beim Abmelden gelöscht (wie alle Speicher außer den Programmdateien), bei einem Update behält der Service Worker sie.
- **Logo:** Kopfzeile, Wartungsseite und Wasserzeichen nutzen eine kleine WebP-Fassung (höchstens 144 px hoch); das 512-px-PNG bleibt für Anmeldeseite, Anleitung und Icons. WebP können alle Browser, auf denen die App läuft (Build-Ziel ES2020).
- **Galerie:** Die Lightbox lädt das vorige und das nächste Bild vorab.
- **Keine Abfragen je Mitglied:** Listen laden jede Tabelle einmal; Konten holt der Kern gemeinsam (`UserDirectory::findMany`: Standalone eine Abfrage, WordPress `cache_users()`; Namen über `UserNames::prime`). Sammelbuchungen (`POST /points`, `/penalties`) schreiben je Person nur ihren Eintrag; Abos und Warteschlange der Benachrichtigungen gesammelt (`PushService::notifyUsers`). `QueryCountTest` prüft, dass die Listen mit 40 Mitgliedern nicht mehr Abfragen brauchen als mit 10. `/me` liest vom Dienstgrad nur die Bezeichnung (`RankService::current`), nicht noch einmal die Punkte.
- **Ziel einer Benachrichtigung** (Issue #190): `route` zeigt, wo es einen Datensatz gibt, auf ihn: `termine/termin/<id>` (`PushTriggers::eventRoute`; Kennung wie in der Liste der Empfänger, Kalendertermine wie im Kalender, öffentliche mit `p` davor; die Erinnerung nur bei genau einem Termin), `brett/<id>`, `meins/punkte/<id>`, `meins/strafen/<id>`, `umfragen/<id>`, `news/<id>`. Die Ansicht scrollt mit `useFocusEntry` (`focus.ts`) zum Eintrag mit `data-focus-id`, sobald er in den geladenen Daten steht, je Kennung einmal, und hebt ihn 2,5 Sekunden hervor (`.card.focused`); Termine klappen dabei auf. Fehlt er, bleibt die Liste. Alte Benachrichtigungen ohne Kennung öffnen weiter die Liste.
- **Push-Versand:** parallel (`ExtendedHttpClient::requestAll`), das VAPID-Token einmal je Push-Dienst und Lauf, Zustellung je Durchgang mit einem `UPDATE` vermerkt. Die App fragt beim Start nur nach dem Abo (`POST …/push/status`), wenn sich Push-Adresse oder Konto geändert haben, sonst einmal je Woche (`pushCheck.ts`).
- **`sw.js` über PHP** (WordPress immer, eigenständig nur ohne statische Auslieferung): mit ETag, meist 304.

### Lizenzen (Issue #173)

Die App steht unter GPL-2.0-or-later (`LICENSE`). Beim Bauen erfasst `packages/frontend/build/licenses.ts` die
Drittanbieter-Bausteine, die tatsächlich in der App stecken, prüft ihre Lizenz gegen die Positivliste und schreibt
`THIRD-PARTY-NOTICES.txt` (kommt mit `LICENSE` in beide Pakete) und die Daten der Seite *Hilfe → Lizenzen*. Ein
Baustein ohne oder mit nicht erlaubter Lizenz (GPL, AGPL, proprietär) bricht den Build ab. Einzelheiten und
Begründung: [LIZENZEN.md](LIZENZEN.md).

### Handbuch

Die Seiten stehen in `docs/handbuch/*.md`, mit Kopfbereich (Titel, Reihenfolge, Rechte, `einrecht` für Bildschirme, die man mit einem von mehreren Rechten sieht, Bereiche, Variante) und Platzhaltern für die Bezeichnungen der Kompanie (`{bereich.points}`, `{rolle.member}` …). `scripts/handbuch.php` prüft sie (`--check`), erzeugt die Daten für die Hilfe in der App (`--app`) und die statischen Seiten für GitHub Pages (`--site`). Die App zeigt nur Seiten, die zu Rechten, eingeschalteten Bereichen und Variante passen.

**Eine Seite je Bildschirm:** Der Knopf „?“ öffnet die Anleitung zum aktuellen Bildschirm (Zuordnung in `packages/frontend/src/helpSlug.ts`). Jede Seite folgt derselben Gliederung: wozu die Seite da ist, Foto, „So öffnest du die Seite“, „Was du hier siehst“, „So gehst du vor“ (Schritte), „Gut zu wissen“, „Siehe auch“. Du-Form, Knöpfe **fett** wie in der App, keine internen Begriffe.

**Bildschirmfotos:** `![Beschreibung](bild:name)` als eigene Zeile. `scripts/handbuch-bilder.sh` fotografiert sie mit Beispieldaten (`packages/frontend/e2e/handbuch-bilder/`, Daten in `tests/standalone/handbuch-daten/`), beim Veröffentlichen einer Kompanie in deren Farben, Logo und Bezeichnungen. Die Fotos liegen in der gebauten Oberfläche unter `handbuch/bilder/` (nicht im Programmteil, damit die App klein bleibt; der Service Worker speichert angesehene Fotos) und auf der Download-Seite unter `handbuch/bilder/`. Fehlt ein Foto, schlägt der Lauf fehl; eine selbst gebaute App ohne Fotos blendet die Bilder aus.

**Regel:** Wer eine Funktion ändert oder hinzufügt, passt die Handbuchseiten und ihre Fotos im selben Pull Request an. `--check` verlangt für jeden Bereich mit eigenen Rechten eine Seite.

Die App setzt **HTTPS** voraus (sonst funktioniert die Installation aufs Handy nicht). Der Installer prüft das und erklärt, wie man beim Hoster ein kostenloses Zertifikat einschaltet.

## 9. Google: Galerie und Kalender

### Kalender

Standard ist die **geheime iCal-Adresse** des Google Kalenders. Das ist ein Link zum Einfügen, ohne Google Cloud. Daneben gehen Google-Kalender-IDs (`calendar_ids`) über die Google Calendar API. `CalendarService::source()` wählt in dieser Reihenfolge:

1. `google`: Kalender-IDs mit eigenem API-Key (`google_api_key`, nur öffentliche Kalender).
2. `google_connection`: Kalender-IDs über den gemeinsamen Google-Zugang (`DriveClient`, siehe Galerie), auch für private Kalender. Abgerufen wird mit `Authorization: Bearer <Zugriffstoken>` und ohne `key`, sonst wie bei 1. (dieselben Parameter mit `singleEvents=true`, Weiterblättern, mehrere Kalender gleichzeitig über `requestAll`). Voraussetzung ist `DriveClient::calendarAccess()`: Bei einer OAuth-Verbindung (`drive_oauth`) muss deren `scope` (durch Leerzeichen getrennt, wie Google ihn beim Token-Austausch liefert; „Mit Google verbinden“ speichert ihn so, die Übernahme kopiert ihn unverändert) `https://www.googleapis.com/auth/calendar.readonly` enthalten. Ein Dienstkonto fragt im JWT immer `drive.readonly` und `calendar.readonly` an (`DriveClient::scopes()`); der Zwischenspeicher des Tokens hängt dort an den Berechtigungen, damit ein Token von vorher nicht weiterverwendet wird. Lehnt Google mit 401/403/404 ab, nennt die Meldung die wahrscheinliche Ursache (Kalender für das verbundene Konto sichtbar bzw. für die Adresse des Dienstkontos freigegeben, Calendar API im Projekt aktiviert).
3. `ics`: die iCal-Adresse (`calendar_ics_url`).

Sind Kalender-IDs ohne API-Key eingetragen, darf die OAuth-Verbindung den Kalender aber nicht lesen (verbunden ohne Kalender-Berechtigung), meldet `GET /admin/settings` `calendar.connection_needs_calendar_access`; Einstellungen (App und WP-Admin) und Zustandsseite weisen darauf hin, dass neu verbunden werden muss (gelb, wenn es dann keine Quelle gibt): trennen und mit Häkchen für den Kalender neu verbinden. `google.calendar_access` (auch `gallery.calendar_access`) zeigt, ob der Google-Zugang den Kalender lesen darf. Ändert sich der Google-Zugang und sind Kalender-IDs eingetragen, wird der Zwischenspeicher des Kalenders geleert. Kalender-IDs lassen sich in der App (beide Varianten) und im WP-Admin eintragen; den eigenen API-Schlüssel gibt es nur im WP-Admin.

**Zwischenspeicher:** Die letzte gute Fassung bleibt 30 Tage erhalten, mit „frisch bis“ (Einstellung `calendar_cache_minutes`, 1–1440, Standard 15; einstellbar in der App unter *Einstellungen → Kalender* und im WP-Admin, beide über `SettingsService::update`, das beim Ändern den Zwischenspeicher leert). Ist sie veraltet, erneuert sie höchstens eine Anfrage je 30 Sekunden (Sperre über `Cache::increment`); alle anderen bekommen sofort die alte Fassung, bei einer Störung auch diese. Ein Fehler wird 60 Sekunden gemerkt; ohne alte Fassung antwortet `/calendar` in dieser Zeit sofort mit 502 statt bis zum Timeout (15 s) zu warten. Die Aufgabe `calendar_refresh` erneuert den Kalender stündlich im Hintergrund. Der Abgleich für Benachrichtigungen (`push_watch`, alle 15 Minuten) ruft ihn über `CalendarService::latest()` unabhängig von „frisch bis“ neu ab (außer die Kopie ist höchstens eine Minute alt, ein Fehler ist gemerkt oder die Sperre ist belegt; bei einer Störung gilt die alte Fassung), damit geänderte Termine nicht bis zu `calendar_cache_minutes` warten; was er einreiht, verschickt `App::runTask` gleich im selben Lauf (`processQueue`), nicht erst mit dem nächsten Lauf von `push_send`. cURL fordert komprimierte Antworten an; mehrere Google-Kalender werden gleichzeitig abgerufen (`ExtendedHttpClient::requestAll`: eigenständig `curl_multi`, im WordPress-Adapter `WpOrg\Requests\Requests::request_multiple` mit denselben Filtern und Einstellungen wie `wp_remote_request`; Test-Doubles nacheinander über `Infra\SequentialHttp`). Die Methode ist allgemein gehalten (Anfragen je Schlüssel, Antwort oder Fehler je Schlüssel), z. B. auch für den Push-Versand.

### Galerie

**Google-Zugang** (gemeinsam für Galerie und Kalender, `DriveClient`), in dieser Reihenfolge:

1. **„Mit Google verbinden“** mit dem **eigenen OAuth-Client der Installation**: Der Admin legt in einem eigenen Google-Cloud-Projekt einen OAuth-Client (Typ Webanwendung) an und trägt Client-ID und Client-Secret in der App ein. Angefragt werden `drive.readonly` und `calendar.readonly`. Erprobt im Betrieb: externe App im Status „In Produktion“, **nichts** unter Google Auth Platform → Datenzugriff eingetragen (dort eingetragene Berechtigungen verlangen Googles Prüfung mit Demo-Video); der Admin bestätigt die Warnung „Google hat diese App nicht überprüft“ über „Erweitert → Weiter“ und setzt beide Häkchen. Im Status „Testen“ laufen Refresh-Tokens nach 7 Tagen ab. Anleitung: [Handbuch, Google-Zugang](handbuch/admin-google.md).
2. **Dienstkonto** (JSON-Schlüssel, nur auf dem Server): Galerie-Ordner bzw. Kalender werden für dessen E-Mail-Adresse freigegeben; das Zugriffstoken kommt per signiertem JWT (RS256).

Ablauf von „Mit Google verbinden“:

- **Beginn:** `POST /admin/google/connect` (App) bzw. das Formular im WP-Admin (`admin-post.php?action=kompanie_app_google_connect`, `GoogleConnect::start` mit Rückkehr `admin`). `DriveClient::saveClient` prüft die Client-ID (`….apps.googleusercontent.com`); ein leeres Secret behält das gespeicherte (gleiche Client-ID). Ein anderer Client leert Refresh-Token, E-Mail und `scope`. Die Antwort ist die Adresse der Anmeldung bei Google (`DriveClient::authorizeUrl`: `response_type=code`, `access_type=offline`, `prompt=consent`, damit Google auch bei erneuter Verbindung ein Refresh-Token liefert, `include_granted_scopes=true`).
- **state:** `kompanie_app_<rückkehr>_<48 Hex-Zeichen>`, gespeichert nur als Hash in `drive_oauth_state` je Benutzer, zusammen mit einem Hash der Sitzung (`Authenticator::sessionKey`: eigenständig HMAC über den Hash des Sitzungsschlüssels, WordPress Hash von Benutzer-ID und `wp_get_session_token()`) und dem Ablauf (15 Minuten). Es gilt einmal; bei der Rückkehr müssen Benutzer, Sitzung und Ablauf passen, und der Benutzer braucht `manage_settings`.
- **Weiterleitungs-URI** (`Environment::googleRedirectUri`, fest je Installation, in den Einstellungen zum Kopieren angezeigt): eigenständig `<App>/google/callback` bzw. ohne URL-Umschreibung `<App>/index.php?r=/google/callback` (eigene Route im `Kernel`, keine API-Route, weil die Antwort eine Weiterleitung ist; der Service Worker reicht sie durch, statt nach dem Zeitlimit die gespeicherte App-Seite zu zeigen). WordPress: `wp-admin/admin-post.php` ohne Parameter, wie in der früheren App von Lank (ein übernommener OAuth-Client passt so weiter). Dort ist der Admin über das Anmelde-Cookie angemeldet; die REST-API behandelt eine Anfrage ohne Nonce dagegen als nicht angemeldet. Der Adapter hängt sich an `admin_post`/`admin_post_nopriv` und reagiert nur auf ein state mit dem Präfix `kompanie_app_` (andere Plugins auf derselben Adresse bleiben unberührt). Google vergleicht die Adresse genau; ändert sich die URL-Umschreibung oder die Adresse der Installation, muss sie im OAuth-Client angepasst werden.
- **Rückkehr:** `GoogleConnect::callback` prüft das state, wertet `error` aus (`access_denied` = abgebrochen) und tauscht den Code am Token-Endpunkt ein (`grant_type=authorization_code`, mit derselben Weiterleitungs-URI). Ohne Refresh-Token oder ohne erlaubtes `drive.readonly` gilt die Verbindung als fehlgeschlagen. Gespeichert wird in `drive_oauth` im selben Format wie bei der Übernahme: `{client_id, client_secret, refresh_token, email, scope}`; `scope` sind die tatsächlich erlaubten Berechtigungen aus der Token-Antwort. Die E-Mail kommt aus dem `id_token`, falls Google eines liefert, sonst wie in der früheren App aus Drive (`about?fields=user(emailAddress)`). Das Ergebnis steht als deutsche Meldung in `drive_oauth_result` (`google.last_result` in `GET /admin/settings`); danach geht es in die App (`<App>?google=1#/einstellungen`, die Einstellungen zeigen die Meldung einmal an) bzw. auf die Einstellungsseite im WP-Admin. Ohne angemeldeten Admin gibt es nur eine Fehlerseite (401/403), gespeichert wird nichts.
- **Trennen:** `POST /admin/google/disconnect` (oder wie bisher `PATCH /admin/settings` mit `drive_oauth: ""`) widerruft das Refresh-Token bei Google (`oauth2.googleapis.com/revoke`, Fehler werden ignoriert) und leert Refresh-Token, E-Mail und `scope`; Client-ID und Client-Secret bleiben für eine neue Verbindung.
- Der Token wird wie bisher per `grant_type=refresh_token` erneuert; der Zwischenspeicher hängt am Refresh-Token. Läuft die Verbindung ab oder wird sie widerrufen (`invalid_grant`), nennt die Meldung den Weg: trennen und neu verbinden.

**Oberfläche:** Abschnitt **Google-Zugang** über Kalender und Galerie, in der App (`GoogleAccess` in `Einstellungen.tsx`, im Assistenten beim ersten Schritt, der ihn braucht) und im WP-Admin (`SettingsPage::renderGoogle`): Weiterleitungs-URI, Client-ID, Client-Secret, „Speichern und mit Google verbinden“, verbundenes Konto, ob es den Kalender lesen darf, „Verbindung trennen“; darunter als Alternative das Dienstkonto. Kalender und Galerie verweisen auf diesen Abschnitt. Geheime Werte (Client-Secret, Refresh-Token, Schlüssel) liefert die API nie aus.

**Später (optional, Issue #40):** `drive.file` mit Ordnerauswahl über den Google Picker gilt bei Google als nicht sensibel und würde die Warnung vor der nicht überprüften App vermeiden; ob die App damit an später hochgeladene Bilder kommt, klärt der Test in [Offene Punkte, Abschnitt 5](OFFENE-PUNKTE.md#5-google-mit-google-verbinden-und-optional-drivefile).

Verworfen: ein **gemeinsamer OAuth-Client bzw. ein gemeinsames Dienstkonto für alle** (ein kompromittierter Server könnte die Ordner aller Kompanien lesen; `drive.readonly` bräuchte für eine gemeinsame App Googles Sicherheitsbewertung) und ein **zentraler Galerie-Dienst** bei uns (wir würden die internen Fotos aller Kompanien verarbeiten; das bedeutet Auftragsverarbeitung und eigenen Betrieb).

**Abgleich und Aufbereitung:** Der Abgleich lädt alle Alben mit einer Abfrage und die vorhandenen Bilder je Album mit einer Abfrage und schreibt nur, was sich geändert hat (Name, Inhalt/md5, intern, Album; bei Alben Jahr, Titel, Sortierdatum), je geändertem Album in einer Transaktion. Ein Abgleich ohne Änderungen schreibt außer dem Status (Zeitpunkt des Abgleichs) nichts und öffnet keine Transaktion (SQLite holt dafür sonst die Schreibsperre); gemessen mit 23 Alben und 205 Bildern 27 statt 50 Abfragen und 0 statt 24 Transaktionen. Alben mit unverändertem `modifiedTime` werden nicht übersprungen: Bei Ordnern in Drive ändert er sich nicht zuverlässig, wenn sich darin Bilder ändern. Die Galerie-Übersicht holt Alben, Anzahl und Titelbild mit **einer** Abfrage (Unterabfrage je Zeile statt Fensterfunktion, damit auch ältere MySQL-Versionen gehen; vorher eine Abfrage je Album).

Bilder werden im Hintergrund aufbereitet (`gallery_sync` und `gallery_process`; außerdem beim Abgleich von Hand in den Einstellungen, mit Zeitbudget; immer nur ein Prozess gleichzeitig, Dateisperre) und, damit neue Bilder sofort erscheinen, auch beim Abruf über `/foto/<id>/<größe>`. Damit ein Album-Raster nicht viele Downloads und Bildberechnungen gleichzeitig auslöst: **Sperre je Bild** (`flock`, 64 Sperrdateien nach Bild-ID; Vorschau und Großansicht entstehen zusammen aus einem Download, nie doppelt; wer auf ein entstehendes Bild trifft, wartet bis zu 20 s darauf) und **höchstens zwei Anfragen gleichzeitig** (`GalleryService::REQUEST_SLOTS`, Sperrdateien `.erzeugen-<n>.lock`; die Aufbereitung im Hintergrund zählt nicht mit). Ist kein Platz frei, liefert die Adresse sofort einen grauen SVG-Platzhalter mit **202** und `Retry-After: 10`, ohne Zwischenspeichern, und stößt die Aufbereitung an (höchstens einmal pro Minute). 202 statt 503, weil nichts gestört ist; das Original kann nicht ersatzweise geliefert werden, es liegt nur in Drive. Die App lädt ein Album mit ausstehenden Bildern alle 10 s neu. Scheitert die Aufbereitung, antwortet die Adresse mit 404. Das Original lädt der HttpClient direkt in eine temporäre Datei (cURL bzw. `stream` in WordPress), nicht in den Arbeitsspeicher. Solange Bilder ausstehen, läuft `gallery_process` gleich wieder.

**Originalauflösung für die Großansicht:** `GalleryService::ORIGINAL` (`/foto/<id>/original`, im Album als `original`). Erst erzeugt, wenn jemand sie abruft: Download aus Drive, `resizeToJpeg` mit höchstens 8192 Pixeln und Qualität 90 (bei RAW das eingebettete JPEG), danach liegt sie neben den anderen Größen (öffentliche Bilder als statische Datei). So belegen nur angesehene Bilder Platz, und Abgleich und Aufbereitung bleiben unverändert. Gleiche Sperre je Bild und dieselben REQUEST_SLOTS wie oben; ist kein Platz frei oder scheitert es, antwortet sie mit 404, und die Großansicht (App und Shortcodes) behält die 1600er, die sie zuerst zeigt. Ein Merker im geschützten Verzeichnis (`.original-<key>.merker`) entsteht vor dem Versuch und verschwindet erst nach Erfolg: Nach einem Abbruch (Speicher, Zeitlimit) oder Fehler lädt ein weiterer Klick das Original höchstens einmal am Tag erneut aus Drive. `deleteFiles` entfernt Original und Merker mit.

**Videos (Issue #182):** Der Abgleich nimmt `video/*` mit auf (`photos.media = 'video'`, dazu `mime`, `bytes` aus `size` und `duration_ms` aus `videoMediaMetadata`; Migration 30). Abgespielt werden nur `video/mp4` und `video/webm` (`DriveClient::PLAYABLE_VIDEO`); andere Formate kommen gleich als fehlgeschlagen mit `GalleryService::UNSUPPORTED_VIDEO` und `MAX_ATTEMPTS` Versuchen in die Liste für Admins (`unsupported` in `/admin/gallery/failed`) und werden nicht erneut versucht. Die Aufbereitung erzeugt nur das Vorschaubild aus Drives `thumbnailLink` (`fromDriveThumbnail`, wie bei Bildern thumb und large); fehlt es noch (frisch hochgeladen), bleibt das Video ausstehend, ein Versuch je Lauf, nach `MAX_ATTEMPTS` fertig ohne Vorschaubild (`thumb` ''). **Kein Speichern, keine Freigabe in Drive:** `/foto/<id>/video` (`GalleryService::deliverVideo`) prüft wie bei Bildern die Rechte (intern nur Mitglieder) und reicht den angefragten Bereich per `Range` aus `files.get?alt=media` durch (`DriveClient::downloadRange`), immer als 206 mit höchstens `VIDEO_CHUNK` (4 MB) je Anfrage, damit jede PHP-Anfrage auch auf Webspace kurz bleibt; der Browser fordert den Rest selbst nach. Ohne Range ab dem Anfang (die Player senden immer Range), hinter dem Ende 416. Höchstens `VIDEO_SLOTS` Abrufe gleichzeitig (Dateisperren, kurz warten), Fehler bei Google → 502. Verworfen: der Player von Drive im iframe (`/preview`, rechnet um, braucht aber „Jeder mit dem Link“ am Ordner; die Freigabe vererbt sich auf `intern`) und eine Kopie auf dem Server (Speicher, Laufzeitgrenzen beim Herunterladen großer Dateien). Formate rechnet die App nicht um (ohne ffmpeg); ein MP4 mit HEVC spielt nicht jeder Browser, die Großansicht zeigt dann einen Hinweis. Der Service Worker lässt `/foto/` unberührt.

**Metadaten:** Jede gespeicherte JPEG-Fassung (Galerie, Mitgliedsfotos) geht durch `JpegMetadata::strip` (EXIF samt GPS, XMP, IPTC, Kommentare; Farbprofil und Adobe-Segment bleiben). Nötig, weil der Bildeditor von WordPress (Imagick) die Metadaten behält, wenn er nicht verkleinern muss, und die Originalauflösung genau das ist.

**Formate, die der Server nicht lesen kann:** Scheitert `ImageResizer::resizeToJpeg` am Original (z. B. Imagick ohne Decoder für RAW oder TIFF: `NoDecodeDelegateForThisImageFormat`, GD kann beides nie), versucht `GalleryService::generate` zuerst `RawPreview::extract`: Kamera-RAW (NEF, CR2, ARW, DNG, PEF …) ist wie TIFF aufgebaut; gelesen werden nur die IFDs (Kette und SubIFDs) und das größte eingebettete JPEG (`JPEGInterchangeFormat`, bei DNG ein JPEG-Streifen eines verkleinerten Bildes) mit mindestens der Kantenlänge der Vorschau; die Ausrichtung aus IFD0 kommt als EXIF davor. Geht das nicht, lädt `DriveClient::thumbnail` Googles Vorschaubild (`thumbnailLink`, mit Zugriffstoken; die Größe über die nicht dokumentierte Endung `=s<Pixel>`). Bilder, an denen PHP `MAX_ATTEMPTS`-mal abgebrochen ist, bekommen nur noch das Vorschaubild von Google (vorher als fehlgeschlagen vermerkt, damit ein weiterer Abbruch keine Schleife wird). Gespeichert wird die Meldung des Originals, wenn alle Wege scheitern.

**Auslieferung mit ETag:** Fertige Bilder über `/foto/…`, Dokumente und Abzeichen tragen einen ETag (`Http\FileCache`: Schlüssel, Größe und Änderungszeit der Datei). Passt `If-None-Match`, antworten beide Adapter mit **304** ohne Inhalt, aber erst nach der üblichen Prüfung der Rechte. Sitzung und Konto werden für Bilder nur ermittelt, wenn es nötig ist (internes Bild, Wartungsmodus): Ein öffentliches Bild kostet in der eigenständigen Version zwei Abfragen (Einstellungen, Bild).

**Bestehende OAuth-Verbindung (Übernahme):** Bringt eine Kompanie aus ihrer früheren App eine Verbindung mit (Client-ID, Client-Secret, Refresh-Token, optional E-Mail und `scope`), speichert die Übernahme sie mit `DriveClient::saveOAuth` in `drive_oauth`, im selben Format wie „Mit Google verbinden“. Sie funktioniert weiter; enthält `scope` `calendar.readonly`, liest der Kalender über sie. Neu verbinden geht mit dem gespeicherten Client, ohne das Secret noch einmal einzugeben.

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

**Bereiche** stehen nicht in `kompanie.json` (siehe Abschnitt 12). Ein altes Feld `features` ist weiterhin gültig, wird aber ignoriert; der Build gibt dazu einen Hinweis aus.

**Rechtstexte:** Felder `legal.imprintUrl`/`legal.privacyUrl` oder Dateien `legal/impressum.md`/`legal/datenschutz.md`. *Ob die App ein eigenes Impressum braucht und was in die Datenschutzerklärung gehört, muss rechtlich geprüft werden.* Danach liegen Vorlagen im Kern, die die App mit ihren Daten füllt (siehe [Offene Punkte, Abschnitt 3](OFFENE-PUNKTE.md#3-rechtstexte-und-datenschutz)).

**Vorschau:** Die CI jedes Kompanie-Repos startet die App mit Beispieldaten und hängt Screenshots (hell/dunkel) an Pull Requests und Releases.

## 11. Updates und Zurückrollen

### Versionen

- Kern: SemVer, Tag `v1.2.3`, beweglicher Tag `v1`.
- Kompanie: Kern-Version plus laufende Build-Nummer, z. B. `1.4.0.3`. Auch eine Logo-Änderung wird so als Update ausgeliefert.
- Jedes Release enthält `release.json`: Version, Mindest-PHP-Version, nötige Erweiterungen, ob Migrationen dabei sind, Signatur.
- Jede Änderung am Programmcode auf `main` baut über **„Kompanien abgleichen“** (alle 30 Minuten, findet die Repos über das Topic `kompanie-app`) neue Versionen für alle Kompanien, zuerst im Kanal `early`.

### Signatur

`release.json` (mit SHA-256 und Größe jedes Pakets und von `install.php`) und die Update-Liste werden mit **Ed25519** signiert (privater Schlüssel nur als Secret der Environment `veroeffentlichen` im Kern-Repo, öffentliche Schlüssel im Kern, `TrustedKeys`). Installer und Updater prüfen mit `sodium` die Signatur und vor dem Entpacken die Prüfsumme des Pakets. Schlüsselwechsel über die Liste erlaubter Schlüssel. Ablauf und Schutz der Signierung: [RELEASE.md](RELEASE.md).

### Ein Update in jedem Fall einspielen

| Situation | Was passiert |
|---|---|
| Normalfall | automatisch, ohne Zutun |
| automatische Updates abgeschaltet (von der Kompanie, `AUTOMATIC_UPDATER_DISABLED` oder Filter `auto_update_plugin` in WordPress) | Hinweis für Admins in der App: **„Jetzt aktualisieren“**, ein Klick |
| Plugin-Änderungen in WordPress ganz gesperrt (`DISALLOW_FILE_MODS`) | kein Update über die App; Hinweis, das Update von Hand einspielen zu lassen |
| WordPress verlangt FTP-Zugangsdaten zum Schreiben | Hinweis mit Erklärung; als Ausweg die Plugin-Datei über *Plugin hochladen* ersetzen |
| Server erfüllt die Voraussetzungen der neuen Version nicht (z. B. PHP zu alt) | Update wird **nicht** eingespielt, die App läuft weiter. Admins sehen in Alltagssprache, was beim Hoster umzustellen ist (Anleitungen je Hoster: später) |
| Update scheitert mittendrin | automatische Rückkehr zur vorigen Version (eigenständig: Dateitausch mit Rücktausch; WordPress: Upgrader mit Sicherung des Plugin-Ordners, ab WordPress 6.3), Hinweis für Admins |
| nichts davon klappt | `install.php` erneut hochladen und öffnen: Er erkennt die bestehende Installation und bietet **„Aktualisieren“** und **„Reparieren“** an |
| neue Hauptversion | nie automatisch; Admins sehen eine kurze Erklärung und aktualisieren mit einem Klick |

**Dateirechte:** Bei manchen Hostern kann PHP Dateien nicht überschreiben, die per FTP hochgeladen wurden. Deshalb lädt bei der eigenständigen Version der **Installer** alle Dateien selbst herunter. So gehören sie dem PHP-Benutzer, und spätere Updates funktionieren.

### Zurückrollen

Jede Kompanie kann in der App unter *Einstellungen → Zustand und Updates* auf eine **ältere Version** zurückgehen, mit einem Klick und ohne Datenverlust:

- **Innerhalb einer Hauptversion ändern Migrationen nur additiv:** neue Tabellen oder Spalten, nie Umbenennen oder Löschen. Ältere `1.x`-Versionen laufen deshalb auch mit dem neueren Datenbestand. Zurückrollen heißt nur, den Programmcode zu tauschen. (Geplant: Die CI prüft das, indem die Contract-Tests der vorigen Version gegen den Datenbestand der neuen laufen.)
- Ältere Versionen lädt der Server bei Bedarf über die signierte Update-Liste nach (sie behält die letzten 60 Versionen und die zuletzt freigegebenen).
- Nach dem Zurückrollen **überspringt** das automatische Update diese Version, bis eine neuere erscheint.
- **Über eine Hauptversion zurück** geht nicht per Klick. Vor einer neuen Hauptversion sichert die App die Daten immer; mit dieser Sicherung und dem Paket der alten Version stellen wir den Stand bei Bedarf von Hand wieder her (Einträge seit dem Update gehen dabei verloren). Ein geführter Weg in der App ist geplant.

### Sicherung

- Vor jedem Update mit Migrationen und vor jeder neuen Hauptversion: automatische Sicherung im Export-Format (die letzten fünf, im geschützten Datenbereich).
- Die automatische Sicherung und die in Google Drive entstehen Stück für Stück (`BackupProvider::exportTo` → `Backup::write`: Tabellen in Portionen, große Tabellen zeilenweise, Dateien in Base64-Blöcken), erst unter vorläufigem Namen (`.tmp`), und werden in Stücken von 8 MB hochgeladen (`DriveClient::uploadFile`). Ebenso das Herunterladen in der App: `/app/sicherung` (`BackupDownload::forDelivery`, nur `manage_settings`, nicht in der Vorschau; Adresse in `/config` als `backupUrl`, der Service Worker reicht sie durch) schreibt die Sicherung direkt in die Antwort (`php://output`). Der Arbeitsspeicher hängt so nicht von der Größe der Sicherung ab. `GET /admin/backup` (ganze Sicherung als JSON-Antwort) bleibt für ältere App-Stände.
- Admins können jederzeit **„Datensicherung herunterladen“** und eine Sicherung wieder einspielen. Die Sicherung enthält
  Tabellen, Einstellungen, Konten (Standalone: Tabelle `users`; WordPress: `accounts` mit den Benutzern der Website),
  die Dateien der Dokumente, Abzeichen und Anhänge (`files`, Base64) sowie Push-Abos und ihren Schlüssel. Nicht enthalten:
  Galeriebilder (kommen neu aus Drive), Warteschlangen, Fehlermeldungen und der Zustand der Installation
  (`Backup::LOCAL_SETTINGS`).
- **Sicherungen in Google Drive** (`BackupUploadService`, optional): wöchentlich in einen eigenen Ordner des über
  „Mit Google verbinden“ verbundenen Kontos (Berechtigung `drive.file`, die App sieht nur eigene Dateien), die letzten
  acht bleiben.
- **Verschlüsselte Sicherungen** (`Storage\BackupCrypto`, `Domain\BackupEncryptionService`): Aus dem Passwort für
  Sicherungen und einem zufälligen Salz entsteht mit Argon2id (`sodium_crypto_pwhash`, Parameter INTERACTIVE, 64 MiB) ein
  X25519-Schlüsselpaar; gespeichert wird nur der öffentliche Schlüssel mit Salz und Parametern (Einstellung
  `backup_encryption`, in `Backup::LOCAL_SETTINGS`). So verschlüsselt der Server jederzeit, auch die wöchentliche
  Sicherung, lesen kann sie nur, wer das Passwort kennt. Datei (`.json.enc`): `KOMPANIE-BACKUP-ENC\n`, Länge und Kopf
  (JSON mit Salz, Parametern, dem mit `crypto_box_seal` versiegelten Dateischlüssel und dem Kopf des Streams), dann
  Blöcke von 64 KiB mit `crypto_secretstream_xchacha20poly1305` (Kopf als zusätzliche Daten des ersten Blocks, letzter
  Block `TAG_FINAL`: geänderter Kopf und abgeschnittene Dateien fallen auf). Nach Drive gehen Sicherungen mit
  Passwort verschlüsselt (ohne wie bisher als `.json`, `BackupUploadService`), der Download auf Wunsch (`/sicherung?verschluesselt=1`),
  `POST /admin/backup` nimmt verschlüsselte Dateien als `backup_base64` mit `passphrase`. Die Sicherungen vor Updates
  auf dem Server bleiben unverschlüsselt (gleicher Ort wie die Datenbank; Wiederherstellen ohne Passwort).
  Bewusst **keine** Verschlüsselung einzelner Felder in der Datenbank: Der Schlüssel läge auf demselben Webspace wie
  die Daten (`config.php` bzw. `wp-config.php`) und schützte nur gegen eine gestohlene Datenbank ohne die Dateien.
- Die Ampel wird gelb, wenn 30 Tage lang keine Sicherung außerhalb des Servers entstand (heruntergeladen oder in Google Drive).

### Update-Liste und gestaffelte Verteilung

- Die Server fragen **nicht die GitHub-API** (Limit von 60 Anfragen pro Stunde je IP-Adresse; auf Webspace teilen sich viele Seiten eine Adresse). Stattdessen liest jeder Server einmal täglich die **signierte Update-Liste** `updates.json` seiner Kompanie von GitHub Pages.
- **Erst die Demo, dann alle:** Jede Version mit neuem Programmcode läuft zuerst auf unserer Demo-Installation (Kanal `early`) und wird getestet (automatisch: Phase 6). Danach bekommen sie alle Kompanien (Kanal `stable`, Freigabe im Kern-Repo). Testkompanien gibt es nicht.
- Eine **zurückgezogene Version** rollen die Server automatisch zurück, auf die zuletzt freigegebene derselben Hauptversion (gibt es keine, zeigt die Statusseite das rot an).

Einzelheiten: [Offene Punkte, Abschnitt 1](OFFENE-PUNKTE.md#1-update-verteilung-und-gestaffelte-releases).

## 12. Installation und Einrichtung

### Web-Installer (`installer/install.php`)

Eine einzelne Datei, auf die Kompanie zugeschnitten (Repo-Name und Signaturschlüssel sind eingesetzt).

1. **Voraussetzungen prüfen**: PHP-Version (ab 8.1), Erweiterungen, Datenbank-Treiber, Schreibrechte, Speicherplatz, ausgehende Verbindungen. Jedes Problem wird in Alltagssprache erklärt (Anleitungen je Hoster: später).
2. **WordPress erkennen.** Liegt WordPress im selben Ordner, erklärt der Installer, wie das Plugin installiert wird, und richtet nichts ein.
3. **Release laden**: signierte Update-Liste der eigenen Kompanie, stabile Version, SHA-256 und Größe des Pakets prüfen, sicher entpacken. Funktioniert im Domain-Hauptverzeichnis und in einem Unterordner (`/app/`), mit und ohne URL-Umschreibung.
4. **Datenbank anlegen** (SQLite automatisch, siehe Abschnitt 6) und **Selbsttest** für geschützte Ordner: Sind die Daten von außen erreichbar, verlegt er sie außerhalb des Web-Verzeichnisses oder bricht ab.
5. **Ersten Admin anlegen**; danach führt die App durch den **Einrichtungsassistenten**.
6. Der Installer **löscht sich selbst**. Erneut hochgeladen dient er (nach Anmeldung eines Admins) zum Aktualisieren und Reparieren; die Daten bleiben.

### Einrichtungsassistent (beide Varianten, in der App)

Beim ersten Öffnen durch einen Admin bietet die App den Assistenten an:

1. **Bereiche wählen** (alle 17 aus `Domain\Features`: Termine, Kalender, News, Galerie, Punkte, Strafen, Dienstgrade, Titel, Anwesenheit, Dokumente, Schwarzes Brett, Umfragen, Geburtstage, Inventar, Mitglieder, Ämter, Listen), je Bereich ein Satz, wofür er da ist. Eine neue Installation hat **keine** Bereiche; dieser Schritt lässt sich deshalb nicht überspringen.
2. Google-Zugang („Mit Google verbinden“ oder Dienstkonto) beim ersten Schritt, der ihn braucht: Galerie, sonst Kalender (für Kalender-IDs)
3. Kalender (nur wenn gewählt): Kalender-IDs über den Google-Zugang oder geheime iCal-Adresse (mit Anleitung, wo man sie in Google findet), Testabruf
4. Galerie (nur wenn gewählt): Link zum Ordner, Abgleich mit Fortschritt
5. News (nur wenn gewählt, nur eigenständige Version): Adresse einer vorhandenen Webseite oder eines Feeds, Testabruf
6. „Fast fertig“: optional Statusmeldungen an uns erlauben, Haken vorausgewählt (siehe Abschnitt 13), dann „Einrichtung abschließen“

Der erste Admin entsteht bei der eigenständigen Version im Installer, bei WordPress ist es ein WordPress-Administrator.

Die Schritte 2–5 lassen sich überspringen und später unter *Einstellungen* nachholen.

### Bereiche

- **Nur der Admin entscheidet** (`manage_settings`): in der Ersteinrichtung, später unter *Einstellungen → Bereiche* in der App, bei WordPress zusätzlich im WP-Admin. API: `GET`/`PUT /admin/features`.
- **Eine Stelle im Kern:** `Domain\Features` sagt, ob ein Bereich an ist, und führt die Liste aller Bereiche (Bezeichnung, Beschreibung, Abhängigkeiten, ob er eingerichtet werden muss). Neue Bereiche tragen sich dort ein, nicht in `kompanie.json`.
- **Ausgeschaltet:** Der Bereich verschwindet aus der App, seine Routen antworten mit **403 `feature_disabled`**, seine Hintergrundaufgaben laufen nicht. **Die Daten bleiben erhalten**; nach dem Wiedereinschalten ist alles wieder da. Beim Ausschalten fragt die App nach.
- **Eingeschaltet, aber nicht eingerichtet** (z. B. Kalender ohne Adresse): in den Einstellungen markiert, mit Link zur Einrichtung. `/config` meldet nur Bereiche, die an **und** eingerichtet sind.
- **Bestehende Installationen** übernehmen beim Wechsel auf die Version mit dieser Funktion einmalig die Werte aus dem alten Feld `features` ihrer `kompanie.json` (dort war „an“ der Standard), damit nichts plötzlich verschwindet. Danach gilt nur noch die Einstellung.

## 13. Pflege ohne technisches Wissen

- **Statusseite für Admins** mit Ampel in Alltagssprache: Updates, Galerie-Abgleich, Kalender, letzte Sicherung, Server. Bei Gelb/Rot steht dabei, was zu tun ist, oder „Bitte melde dich bei …“ mit unseren Kontaktdaten.
- **Hinweise direkt in der App** für Admins, statt E-Mails (E-Mail-Versand ist auf Webspace oft unzuverlässig).
- **Statusmeldungen an uns, nur mit Zustimmung:** stündlich ein Lebenszeichen (Version, Variante, PHP-Version, Datenbank-Art, Ampel je Bereich, Fehlercodes der letzten 24 Stunden mit Ursache, Nutzungszahlen als Summen: Konten, aktive Konten, Personen je Bereich), schwere Fehler sofort; signiert je Installation ([STATUSMELDUNGEN.md](STATUSMELDUNGEN.md)). Keine Namen, keine Benutzer-IDs und keine Fehlertexte (die stehen nur im Fehlerprotokoll der Installation, das Admins kopieren und uns schicken können); was genau gesendet wird, zeigt die App vor der Zustimmung an. Damit können wir Kompanien helfen, bevor sie ein Problem bemerken. Gehört in die Datenschutzerklärung; abschaltbar. Einzelheiten: [Offene Punkte, Abschnitt 4](OFFENE-PUNKTE.md#4-statusmeldungen).
- **Hilfe-Texte** an jeder Einstellung; Fachbegriffe nur mit Erklärung.

## 14. Die beiden Adapter

### WordPress-Plugin (`adapters/wordpress`)

- Liefert die App unter `/app/` aus, inkl. geschützter Galeriebilder unter `/app/foto/…`.
- **Einstellungen** als Options mit Präfix `kompanie_app_`, mit Autoload (außer den großen Werten in `WpSettings::NO_AUTOLOAD`, die bei Bedarf gemeinsam mit einer Abfrage geladen werden). Was nicht in `alloptions` steht, gibt es deshalb nicht: `WpSettings::get()` fragt dafür nicht die Datenbank. Nach einem Update stellt `Plugin::upgrade()` Einstellungen älterer Versionen auf Autoload um, bevor es sie liest. Seiten der Website außerhalb der App kosten das Plugin keine Abfrage (`upgrade()` auf `init` liest nur Einstellungen aus `alloptions` und tut nur nach einem Update etwas).
- Die Rolle in der App (Grundrolle) steht in der Benutzer-Option `kompanie_app_role` (je Website, auch bei Multisite), die Rechte dazu kommen aus der App; Auswahl auch im Benutzerprofil des WP-Backends. Zusatzrollen (Issue #66) stehen in der Tabelle des Kerns, nicht in WP-Rollen; das Backend zeigt und ändert sie nicht. Die früheren WP-Rollen `kompanie_member`, `kompanie_organizer` und `kompanie_manager` bleiben angelegt und werden für diese Rollen weiter vergeben: Bis zur einmaligen Übernahme nach dem Update (`Roles::migrateUsers`) und nach einem Zurückrollen gelten sie.
- Einstellungsseite im WP-Admin (Google-Zugang, Kalender, Galerie); Assistent, Statusseite, Updates und Datensicherung für WordPress-Administratoren in der App.
- **Updates** über den Upgrader von WordPress mit dem geprüften Paket (Sicherung des Plugin-Ordners ab WordPress 6.3); verlangt WordPress FTP-Zugangsdaten, erklärt die App den Ausweg.
- **Datensicherung** der App-Daten, Einstellungen und WordPress-Benutzer; Wiederherstellen nur auf derselben Website
  (Adresse). Fehlende Benutzer legt `WpBackup` mit ihrer bisherigen ID an (nach dem Verlust der Website), vorhandene
  behalten ihre Anmeldedaten; widersprechen sich IDs und Benutzernamen, ändert es nichts.
- Shortcodes `[kompanie_termine]`, `[kompanie_galerie]`, `[kompanie_aemter]` und `[kompanie_mitglieder]` (dieselbe Liste wie `GET /directory` über `Api::directory`, als Bildergalerie: Fotos im Hochformat 2:3 mit 480 Pixeln, Name als Balken im Bild, nach Namen sortiert, Großansicht mit 1024 Pixeln; ohne Foto die Anfangsbuchstaben oder, falls im WP-Admin hinterlegt, ein Platzhalterbild aus der Mediathek (Einstellung `members_placeholder` mit der ID des Anhangs, `wp_get_attachment_image` mit srcset, ohne Großansicht; ein gelöschter Anhang fällt auf die Anfangsbuchstaben zurück); Fotos der Inhaber in `[kompanie_aemter]` ebenfalls mit 480 Pixeln, oben ausgerichtet wie in der App; Schrift in der Textfarbe der Webseite, die Kompaniefarbe nur für Flächen, damit helle und dunkle Themes lesbar bleiben; Bereich aus → keine Ausgabe).
- „Angemeldet bleiben“ 400 Tage mit automatischer Verlängerung.
- **Verträglich mit typischen WordPress-Umgebungen:** App-Seite und API senden „nicht zwischenspeichern“ an Cache-Plugins und CDNs; später soll der Assistent erkennen, wenn ein Sicherheits-Plugin die REST-API sperrt, und die Freigabe erklären. Nur Einzelseiten, kein Multisite (vorerst).

### Eigenständige Version (`adapters/standalone`)

- **Benutzer:** eigene Benutzer, Passwörter mit `password_hash`. Vergessene Passwörter setzt ein Admin neu (kein E-Mail-Versand nötig).
- **Sitzung:** Cookie `HttpOnly`, `Secure`, `SameSite=Lax`, Sitzungsschlüssel nur gehasht gespeichert. 400 Tage mit automatischer Verlängerung. Passwortänderung meldet andere Geräte ab.
- **Datenbank:** SQLite (legt der Installer an) oder MySQL/MariaDB.
- **Sicherheit:** Begrenzung fehlgeschlagener Anmeldungen, geschützte Dateien und Datenbank außerhalb des Web-Verzeichnisses oder gesperrt **und** geprüft, Sicherheits-Header.
- **Läuft auf** Apache, nginx und LiteSpeed, im Hauptverzeichnis oder Unterordner, mit oder ohne URL-Umschreibung.
- **Zwischenspeicher und Komprimierung** (beide Adapter, Apache/LiteSpeed): `app/assets/.htaccess` lässt die gehashten JS/CSS-Dateien ein Jahr unverändert im Browser (`immutable`), `app/.htaccess` komprimiert Text-Dateien (`mod_deflate`) und cacht Icons und Manifest einen Tag. Die Vorlagen liegen in `adapters/shared/`; `scripts/package.php` und `scripts/build-frontend.sh` legen sie ab (nie aus dem Build-Ergebnis). Auf nginx muss der Hoster das selbst einstellen.
- **Einstellungen in der App** für Admins: Google-Zugang, Kalender, Galerie, Quelle der Neuigkeiten (fremde WordPress-Seite oder RSS/Atom-Feed). Geheime Werte zeigt die App nie wieder an.
- **Datensicherung** im gemeinsamen Export-Format, in der App und über `bin/kompanie.php export`/`import`.
- **Updates** durch Tausch der Programmdateien (mit Rücktausch bei Fehlern), auch über `bin/kompanie.php update-check`/`update`/`rollback`.
- Einzelheiten: [adapters/standalone/README.md](../adapters/standalone/README.md).
- **Termine auf fremden Webseiten:** einbettbarer Ausschnitt `/embed/termine`. *Später.*

## 15. Umstellung der ersten Kompanie (Lank)

Geplant in Issue #25. Lank läuft danach auf dem WordPress-Plugin des Kerns; ihr Design kommt aus ihrem Kompanie-Repo.

**Grundsatz: kopieren, nicht zerstören.** Ein eigenes **Übernahme-Plugin** im bisherigen Lank-Repo (Ordner `uebernahme/`) kopiert die Daten des alten Plugins in die Tabellen und Einstellungen des Kerns. Die alten Tabellen, Optionen und User-Meta bleiben unverändert; so lässt sich in den ersten Tagen auf das alte Plugin zurückschalten. Lank-spezifischer Code gehört nicht in den Kern (Neutralitätsprüfung); er wird nach der Aufräumfrist mit dem Übernahme-Plugin gelöscht.

- **IDs bleiben gleich:** Die Übernahme schreibt mit den bisherigen IDs in die leeren Tabellen des Kerns. Dadurch bleiben Konten, Verweise (Punkte → Anwesenheitsliste, Dokument → Kategorie) und Adressen (`/app/dokument/<id>`, `/app/foto/<id>/<größe>`) gültig; ein wiederholter Lauf erkennt Übernommenes an der ID und legt nichts doppelt an.
- **Konten:** WordPress-Benutzer bleiben, die Rolle in der App kommt in die Benutzer-Option des Kerns. Anmeldungen bleiben erhalten, weil beide die Sitzung von WordPress nutzen (400 Tage, siehe Abschnitt 14).
- **Installierte Apps:** Adresse, Bereich und Service Worker bleiben unter `/app/`; das Manifest hat wie bisher keine `id`. Der neue Service Worker löscht beim Aktivieren die Caches des alten.
- **Push:** Der VAPID-Schlüssel und die Abos werden übernommen, sonst müssten alle Geräte neu zustimmen. Die Nutzlast enthält neben `route` auch `url`, damit ein noch nicht ausgetauschter alter Service Worker Nachrichten richtig öffnet.

**Erweiterungspunkte im Kern** (neutral, auch für andere Übernahmen):

- `RestBridge::register($app, '<namensraum>')` registriert die Routen des Kerns zusätzlich unter einem früheren Namensraum.
- Bestehende OAuth-Verbindung zu Google Drive (`DriveClient::saveOAuth`, Abschnitt 9).
- Shortcode-Aliase legt das Übernahme-Plugin selbst an (`add_shortcode`, Weiterleitung auf `kompanie_termine`/`kompanie_galerie`).

Ablauf, Probelauf, Bericht und Rückfallplan beschreibt das Übernahme-Plugin in seinem README.

## 16. Tests

- **Unit-Tests** für den Kern (PHPUnit), mit Test-Umsetzungen der Ports.
- **Repository-Contract-Tests** gegen MySQL/MariaDB und SQLite.
- **API-Contract-Tests** gegen beide Adapter.
- **E2E-Tests** (Playwright) gegen beide Adapter.
- **Installer und Updater** (`tests/update/run-tests.sh`, mit dem PHP-Testserver): frische Installation mit `install.php`, Update, Zurückrollen, Zurückziehen, manipuliertes Paket, ältere Liste, Reparaturmodus, Liste einer fremden Kompanie. Release-Ablauf: `tests/release/run-tests.sh`. Geplant: Apache und nginx, zu alte PHP-Version.
- **Rückwärtstest** (geplant): Contract-Tests der vorigen Version gegen den Datenbestand der neuen.
- **Branding:** Kontrastprüfung, Icon-Erzeugung, Screenshots.
- **Handbuch** (`tests/handbuch/run-tests.sh`): Kopfbereich, Platzhalter, Verweise, Ausgabe für App und Webseite.
- **Grenz- und Neutralitätsprüfung** in der CI (auch für das Handbuch).

## 17. Fahrplan

| Phase | Inhalt | Ergebnis |
|---|---|---|
| 0 | Architektur, API-Spezifikation, Konfigurationsschema, Schnittstellen | dieses Dokument und das Gerüst |
| G | „Mit Google verbinden“ mit eigenem OAuth-Client je Installation (umgesetzt); optional Test `drive.file` + Picker (Issue #40) | Verbindung ohne Dienstkonto; optional ohne Warnbildschirm |
| 1 | **Kern und WordPress-Adapter:** Das bestehende Plugin wird zum WordPress-Adapter, ein Bereich nach dem anderen zieht in den Kern (Repositories, MySQL-Konnektor). Bestehende Tests bleiben grün. | Plugin mit vollem Funktionsumfang auf dem Kern |
| 2 | **Frontend neutral und verallgemeinert:** `/config`, CSRF, Anmeldearten, Branding, Bezeichnungen, Neutralitätsprüfung | eine neutrale App für alle |
| 3 | **Eigenständige Version:** Benutzer, Sitzung, Einstellungen, SQLite und MySQL über PDO, Export/Import | eigenständige Version |
| 4 | **Branding und Build:** Farben, Icons, Signatur, Screenshots, `Kompanie-App-Vorlage`, Download-Seite | Kompanie-Repo baut signierte Pakete |
| 5 | **Installer, Assistent, Updates:** automatische Updates, Ein-Klick-Update, Zurückrollen, Sicherung, Statusseite | Installation und Pflege ohne Handarbeit |
| 6 | **Für uns:** Formulare „Neue Kompanie“/„Kompanie ändern“, „Kompanien abgleichen“, Empfang und Übersicht der Statusmeldungen (im Cockpit, Repo `project-cockpit`; Format in [STATUSMELDUNGEN.md](STATUSMELDUNGEN.md)); offen: automatischer Demo-Test mit Befördern (braucht die Demo-Installation) | neue Kompanie nur durch Eintragen |
| später | Hoster-Anleitungen erweitern, `/embed/termine`, Multisite | |
| – | Umstellung Lank | eigener Plan |

### Was wir dafür in der Organisation brauchen

- eine **GitHub App** mit Schreibrecht auf die Kompanie-Repos (Releases, `gh-pages`; später auch zum Anlegen neuer Repos)
- einen **Ed25519-Signaturschlüssel** als Secret der Environment `veroeffentlichen` im Kern-Repo (siehe [RELEASE.md](RELEASE.md))
- optional ein **Google-Cloud-Projekt** mit OAuth-Client für den Test `drive.file` + Picker (Issue #40)
- ein leeres Repo **`Kompanie-App-Vorlage`**
- eine Subdomain auf unserem Webspace für den Empfang der **Statusmeldungen** (nur von Kompanien, die zustimmen)

Die Planung der offenen Punkte steht in [OFFENE-PUNKTE.md](OFFENE-PUNKTE.md).
