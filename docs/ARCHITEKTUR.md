# Architektur der Kompanie-App

Dieses Repo ist der **gemeinsame Kern** der Kompanie-App. Jede Kompanie bekommt in unserer GitHub-Organisation ein eigenes, kleines Repo. Darin stehen nur Name, Logo, Farben und ein paar Texte. Alles andere passiert automatisch: Build, Icons, Prüfungen, Releases und Updates auf den Servern der Kompanien.

Ausgangspunkt ist die App der Marinekompanie 1973 Lank (Repo `Marinekompanie-Lank-App`). Ihre Funktionen werden hier herausgelöst und von WordPress getrennt. Die Umstellung von Lank selbst wird getrennt geplant (siehe [Abschnitt 12](#12-umstellung-von-lank)).

## 1. Leitlinie und Entscheidungen

**Leitlinie:** Für eine neue Kompanie tragen wir nur Logo, Farben, Namen und Texte ein. Was sich berechnen, prüfen oder automatisieren lässt, wird automatisiert.

| Frage | Entscheidung |
|---|---|
| Wie kommen Updates zu den Kompanien? | Gemeinsamer Kern mit Versionen. Kern-Releases bauen alle Kompanie-Repos automatisch neu. |
| Kompanie-Repos | Ein Repo pro Kompanie in unserer Organisation, **öffentlich** (enthält keine Geheimnisse). |
| Wer hostet? | Jede Kompanie selbst, auf ihrem eigenen Webspace. |
| WordPress? | Optional. Es gibt ein **WordPress-Plugin** und eine **eigenständige Version** ohne WordPress, beide auf demselben Kern. Beide werden jetzt gebaut. |
| Installation | **Web-Installer** (`install.php`) für die Kompanien und **Formular „Neue Kompanie“** für uns. |
| Updates auf den Servern | **Automatisch**, signiert, abschaltbar. |
| Galerie (Google Drive) | Erst ein Test-Prototyp für „Mit Google verbinden“, sonst Ordnerfreigabe an ein Dienstkonto je Kompanie (siehe [Abschnitt 8](#8-google-galerie-und-kalender)). |
| Lank | Wird umgestellt, aber in einem eigenen, später geplanten Schritt. |

Technische Rahmenbedingungen:

- **Server: nur PHP und MySQL/MariaDB.** Vereine haben meist günstigen Webspace ohne Node.js, ohne Docker und ohne Shell-Zugang. Node.js braucht es nur beim Build in GitHub Actions.
- **Nur MySQL/MariaDB, kein SQLite.** Die Lank-Tests laufen zwar mit SQLite, aber nur über das WordPress-Plugin `sqlite-database-integration`, das MySQL-Befehle übersetzt. Das SQL selbst ist MySQL (`AUTO_INCREMENT`, `dbDelta` usw.). Die CI startet deshalb eine MariaDB.
- **Keine Laufzeit-Abhängigkeiten über Composer.** Der Kern bringt alles selbst mit, wie das heutige Plugin, das das Google-JWT ohne Bibliothek signiert. Das fertige ZIP enthält alles.
- **PHP ≥ 8.1** als Mindestversion für die Kompatibilität. PHP 8.1 bekommt seit Ende 2025 keine Sicherheitsupdates mehr; der Installer empfiehlt deshalb eine aktuelle Version. *Offen:* prüfen, welche Version der Webspace von Lank und der ersten weiteren Kompanie anbietet. Das heutige Lank-Plugin läuft noch ab PHP 7.4.
- **Benötigte PHP-Erweiterungen:** `pdo_mysql` (Standalone), `gd` oder `imagick`, `curl` oder `allow_url_fopen`, `openssl`, `sodium` (Signaturprüfung), `zip` (Updates). Der Installer prüft sie.

## 2. Die Abläufe, um die es geht

### Neue Kompanie (wir)

1. Im Kern-Repo das Formular **„Neue Kompanie“** ausfüllen: Name, Kurzname, Hauptfarbe, Akzentfarbe, Begrüßung, Rollenbezeichnungen. Logo per Drag & Drop anhängen.
2. Ein Workflow prüft alles (Farbformat, Kontrast, Logo-Größe), legt das Repo `kompanie-<name>` aus der Vorlage an, schreibt `kompanie.json`, legt das Logo ab und setzt das Topic `kompanie-app`.
3. Der Build erzeugt Icons, das signierte ZIP und Screenshots (hell/dunkel, Startseite, Termine).
4. Der Workflow antwortet im Formular-Issue mit Screenshots, Repo-Link und dem Link zum Installer.

Spätere Änderungen (neues Logo, andere Farbe) laufen über das Formular **„Kompanie ändern“** oder direkt im Repo per Pull Request. Auch dort hängen die Screenshots am Pull Request.

### Installation (Kompanie)

1. `install.php` herunterladen und per FTP auf den Webspace laden, im Browser öffnen.
2. Der Installer prüft die Voraussetzungen, erkennt WordPress und lädt das neueste signierte Release der Kompanie.
3. Der Einrichtungsassistent fragt ab, was nur die Kompanie wissen kann: Datenbank (nur Standalone), erster Admin, Kalender-Link, Galerie-Ordner. Jeder Schritt wird live geprüft.

### Update (niemand)

1. Wir veröffentlichen ein Kern-Release.
2. Alle Kompanie-Repos bauen automatisch neu und veröffentlichen ein Release.
3. Die Server holen sich das Update selbst, prüfen die Signatur, spielen es ein und führen die Migrationen aus.

## 3. Überblick

```
                ┌──────────────────────────────────────────┐
                │  Frontend (React-PWA, für alle gleich)   │
                │  Branding aus kompanie.json beim Build   │
                └───────────────────┬──────────────────────┘
                                    │  HTTP: api/openapi.yaml
             ┌──────────────────────┴───────────────────────┐
             │                                              │
┌────────────┴─────────────┐                ┌───────────────┴───────────┐
│ Adapter WordPress        │                │ Adapter Standalone         │
│ (Plugin)                 │                │ (index.php)                │
│ $wpdb, WP-Login, Options,│                │ PDO, eigene Benutzer,      │
│ WP-Cron, Shortcodes      │                │ eigene Sitzung, Cronjob    │
└────────────┬─────────────┘                └───────────────┬───────────┘
             │      setzen die Schnittstellen (Ports) um    │
             └──────────────────────┬───────────────────────┘
                ┌───────────────────┴──────────────────────┐
                │  Kern (PHP, ohne WordPress)              │
                │  Punkte, Strafen, Katalog, Termine,      │
                │  Galerie, Kalender, News, Rechte,        │
                │  Routen, Migrationen, Updater            │
                └──────────────────────────────────────────┘
```

Grundregel: **Im Kern steht kein einziger WordPress-Aufruf.** Alles, was von der Umgebung abhängt, läuft über eine Schnittstelle in `packages/core/src/Port/`. `scripts/check-core-boundary.sh` sichert das in der CI ab.

## 4. Verzeichnisse

```
api/openapi.yaml            Vertrag zwischen Frontend und Server (beide Adapter)
config/
  kompanie.schema.json      Schema der Kompanie-Konfiguration
  kompanie.example.json     Beispiel
packages/
  core/                     PHP-Kern, Namespace KompanieApp\Core
    src/Domain/             Fachlogik: Punkte, Strafen, Katalog, Termine, Galerie, Kalender, News
    src/Port/               Schnittstellen zur Umgebung (siehe Abschnitt 5)
    src/Http/               Routentabelle, Rechteprüfung, Controller, Fehlerformat
    src/Migration/          Datenbank-Migrationen (MySQL/MariaDB)
    src/Update/             Update-Prüfung, Signaturprüfung, Einspielen
    tests/                  Unit-Tests
  frontend/                 React + TypeScript (Vite), die PWA
  branding/                 Build-Werkzeug: Farben ableiten, Kontrast prüfen, Icons erzeugen
adapters/
  wordpress/                WordPress-Plugin
  standalone/               eigenständige Installation inkl. Einrichtungsassistent
installer/install.php       Web-Installer (eine Datei)
tests/contract/             dieselben API- und E2E-Tests gegen beide Adapter
.github/
  ISSUE_TEMPLATE/           Formulare „Neue Kompanie“, „Kompanie ändern“
  workflows/                CI des Kerns, Kompanie anlegen, alle Kompanien neu bauen,
                            wiederverwendbarer Build-Workflow für die Kompanie-Repos
```

Die Vorlage für Kompanie-Repos ist ein **eigenes Template-Repo** (`Kompanie-App-Vorlage`), damit der Workflow neue Repos daraus erzeugen kann.

## 5. Der Kern und seine Schnittstellen

Der Kern bekommt alle Abhängigkeiten von außen übergeben. Jeder Adapter baut beim Start einen `Container` mit seinen Umsetzungen zusammen.

| Port | Zweck | WordPress | Standalone |
|---|---|---|---|
| `Database` | SQL mit Platzhaltern, Transaktionen, Tabellenpräfix | `$wpdb` | PDO (MySQL/MariaDB) |
| `AuthContext` | aktueller Benutzer mit Rolle und Rechten | WP-Benutzer + Capabilities | eigene Sitzung |
| `UserDirectory` | Benutzer suchen, anlegen, Rolle setzen, Passwort prüfen/ändern | `get_users`, `wp_insert_user`, … | eigene Tabelle `users` |
| `Settings` | Einstellungen lesen/schreiben | Options | Tabelle `settings` |
| `Cache` | Zwischenspeicher mit Ablaufzeit | Transients | Tabelle `cache` |
| `HttpClient` | Abrufe von Google, iCal, News-Quellen, Updates | `wp_remote_*` | cURL |
| `FileStorage` | öffentliche und geschützte Dateien (Galerie) | Upload-Verzeichnis | `storage/` und `media/` |
| `ImageResizer` | Bildgrößen erzeugen | `wp_get_image_editor` | GD oder Imagick |
| `Scheduler` | wiederkehrende Aufgaben (Galerie-Abgleich, Update-Prüfung) | WP-Cron | Cronjob des Hosters, sonst Pseudo-Cron |
| `NewsSource` | Neuigkeiten | eigene Beiträge (`WP_Query`) | fremde WordPress-Seite, RSS oder keine |
| `Clock` | aktuelle Zeit und Zeitzone (für Tests austauschbar) | `wp_timezone` | Europe/Berlin |

Das SQL steht **nur einmal im Kern**. Die Adapter liefern nur die Verbindung.

Das **Tabellenpräfix** ist einstellbar. Die **erste Migration entspricht genau dem Lank-Schema in Version 3** (`MKL_DB_VERSION = 3`). So kann Lank später seine Tabellen `wp_mkl_*` weiter nutzen, und jede neue Kompanie startet mit demselben Stand.

**Lange Aufgaben in Häppchen.** Auf Webspace bricht PHP oft nach 30 Sekunden ab. Der Galerie-Abgleich arbeitet deshalb wie heute in Lank mit einem Zeitbudget pro Aufruf und setzt beim nächsten Aufruf fort. Der Pseudo-Cron läuft nach dem Absenden der Antwort, wo der Server das unterstützt (`fastcgi_finish_request`), damit Besucher nicht warten.

### Rechte und Rollen

| Recht | Bedeutung | heute in Lank |
|---|---|---|
| `member` | eigene Punkte/Strafen, interner Kalender, interne Galerie | `mkl_member` |
| `manage` | Punkte und Strafen vergeben, Katalog pflegen (Spieß) | `mkl_manage` |
| `manage_events` | öffentliche Termine pflegen | `mkl_manage_events` |
| `create_users` | Mitglieder anlegen, Startpasswörter erzeugen | WP `create_users` |
| `promote_users` | Rollen vergeben | WP `promote_users` |
| `manage_settings` | Einstellungen und Updates (nur Standalone; bei WordPress im WP-Admin) | WP `manage_options` |

Rollen: `guest`, `member`, `manager` (Spieß), `admin`. Die **Bezeichnungen** kommen aus `kompanie.json`.

- **WordPress:** Admin ist, wer WP-Administrator ist. Admin-Rechte werden bewusst nur im WP-Backend vergeben, wie heute.
- **Standalone:** Admins können in der App andere zu Admins machen. Der **letzte Admin kann nicht herabgestuft** werden, und niemand ändert seine eigene Rolle.

Unverändert gilt: Die `/me`-Routen lesen die Benutzer-ID immer aus der Sitzung. Stornierte Einträge werden nur ausgeblendet, und es wird gespeichert, wer sie angelegt hat.

### Routen

Der Kern enthält **eine Routentabelle** (Methode, Pfad, nötiges Recht, Bereich, Controller). Beide Adapter lesen sie:

- **WordPress** registriert jede Route mit `register_rest_route` unter `/wp-json/kompanie-app/v1/`.
- **Standalone** hat einen eigenen Front-Controller unter `/api/v1/`.

Ist ein Bereich (z. B. Strafen) ausgeschaltet, sind auch seine Routen gesperrt, nicht nur in der App ausgeblendet. Die Contract-Tests prüfen, dass beide Adapter `api/openapi.yaml` einhalten.

## 6. Frontend

Das Frontend kommt aus `Marinekompanie-Lank-App/app` und wird so geändert, dass es keinen bestimmten Server voraussetzt:

| Heute (an WordPress gebunden) | Künftig |
|---|---|
| Konfiguration als `window.MKL_CONFIG` aus PHP | dieselben Daten wie `GET /config`; der Server schreibt sie direkt in die `index.html` (kein zusätzlicher Abruf beim Start), sonst holt die App sie über `/config` |
| Header `X-WP-Nonce`, Erneuerung über `admin-ajax.php` | Header-Name und Token aus `/config` (`csrf.header`, `csrf.token`), Erneuerung über `GET /auth/csrf`. WordPress nennt `X-WP-Nonce`, Standalone `X-CSRF-Token`. |
| Anmelden immer über `wp-login.php` | `auth.mode` aus `/config`: `form` (Anmeldeformular in der App) oder `redirect` (WordPress: `wp-login.php`, damit Login-Schutz- und Zwei-Faktor-Plugins weiter greifen) |
| `X-WP-TotalPages` bei News | `X-Total-Pages` |
| Service Worker erkennt `/wp-json/mk-lank/v1/` | API-Pfad wird beim Registrieren übergeben (`sw.js?api=…`) |
| Build landet im Plugin-Ordner | Build nach `dist/`, das Paket-Skript verteilt es |
| Name, Domain, Farben, Logo fest im Code | aus `kompanie.json`; die Domain in der Installationsanleitung ist die Adresse, unter der die App gerade läuft |

## 7. Kompanie-Konfiguration und automatisches Branding

Jede Kompanie beschreibt sich in `kompanie.json` (Schema: [`config/kompanie.schema.json`](../config/kompanie.schema.json), Beispiel: [`config/kompanie.example.json`](../config/kompanie.example.json)). **Pflicht sind nur `name` und `colors.primary`**, dazu das Logo als `assets/logo.png`. Alles andere hat Standardwerte.

Was der Build automatisch erledigt:

| Eingabe | Automatisch daraus |
|---|---|
| `colors.primary`, optional `colors.accent` | alle Farbabstufungen und der Dunkelmodus; Prüfung des Kontrasts nach WCAG (mindestens 4,5 : 1 für Text). Bei zu wenig Kontrast bricht der Build mit einer verständlichen Meldung ab. |
| `assets/logo.png` (quadratisch, mindestens 512 px) | App-Icons 192 und 512 px, Android-Icon mit Sicherheitsabstand, Apple-Touch-Icon, Favicon |
| `name`, `shortName` | Fenstertitel, Manifest, Name unter dem App-Symbol, Texte der Installationsanleitung |
| Repo-Name | technischer Name, Dateinamen, Update-Adresse |
| Kern-Release | Versionsnummer der Kompanie (siehe Abschnitt 9) |

Schriften gibt es nur aus einer **festen, mitgelieferten Auswahl**. Beliebige Schriften müssten jedes Mal als Datei beschafft und auf ihre Lizenz geprüft werden.

**Was beim Build, was zur Laufzeit?** Alles aus `kompanie.json` wird beim Build eingesetzt. Zur Laufzeit kommt nur, was der Server wissen muss: Adressen, angemeldeter Benutzer, Anmeldeart, CSRF und welche Bereiche eingerichtet sind (z. B. ist die Galerie aus, solange kein Ordner verbunden ist).

**Zugangsdaten** (Google, Datenbank) gehören **nie** in `kompanie.json` oder ins Repo. Die Kompanie-Repos sind öffentlich.

**Rechtstexte:** Für die eigenständige Version ohne eigene Webseite gibt es die Felder `legal.imprintUrl`/`legal.privacyUrl` (Links auf vorhandene Seiten) oder `legal/impressum.md`/`legal/datenschutz.md` im Kompanie-Repo. *Ob die App ein eigenes Impressum braucht und was in die Datenschutzerklärung gehört, sollte rechtlich geprüft werden.*

**Vorschau:** Die CI jedes Kompanie-Repos startet die eigenständige Version mit Beispieldaten und hängt Screenshots an Pull Requests und Releases.

## 8. Google: Galerie und Kalender

### Kalender

Standard ist die **geheime iCal-Adresse** des Google Kalenders. Das ist ein Link zum Einfügen, ohne Google Cloud. Google-Kalender-ID mit API-Key bleibt als Option.

### Galerie

Gesucht ist ein Weg, bei dem die Kompanie kein Google-Cloud-Projekt braucht. Zwei Varianten, die Entscheidung fällt nach einem **Test-Prototyp**:

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

## 9. Versionen, Releases und automatische Updates

### Kern

- Versionen nach **SemVer**, Tag `v1.2.3`, dazu ein beweglicher Tag `v1` auf das neueste `1.x`-Release.
- Brechende Änderungen an API, Konfiguration oder Datenbank gibt es nur mit einer neuen Hauptversion. Sie stehen im Changelog.
- Ein Kern-Release startet den Workflow **„Alle Kompanien neu bauen“**. Er findet die Kompanie-Repos am Topic `kompanie-app` und stößt dort den Build an. Dafür braucht der Workflow eine **GitHub App** der Organisation mit Schreibrechten auf die Kompanie-Repos.

### Kompanie-Repo

```
kompanie.json          Name, Farben, Texte, Bereiche
assets/logo.png        Logo
legal/                 optional: Impressum, Datenschutz
.github/workflows/build.yml
```

- `build.yml` ruft den wiederverwendbaren Workflow des Kerns auf (`…/build-kompanie.yml@v1`) und folgt damit automatisch allen `1.x`-Releases.
- **Versionsnummer:** Kern-Version plus laufende Build-Nummer der Kompanie, z. B. `1.4.0.3`. So wird auch eine reine Logo-Änderung als Update ausgeliefert.
- **Ein ZIP für beide Varianten:** Liegt es in `wp-content/plugins`, arbeitet es als WordPress-Plugin, sonst als eigenständige Version. Dann kann niemand die falsche Datei erwischen. *Beim Bau prüfen; falls das nicht sauber geht, gibt es zwei ZIPs, und der Installer wählt das richtige.*

### Signatur

- Jedes ZIP wird beim Build mit einem **Ed25519-Schlüssel** signiert. Der private Schlüssel liegt als Secret in der Organisation, der öffentliche ist im Kern eingebaut.
- Installer und Updater prüfen die Signatur mit `sodium` vor dem Entpacken. Ein ZIP ohne gültige Signatur wird nie eingespielt.
- Die Schlüssel lassen sich wechseln: Der Kern akzeptiert eine Liste öffentlicher Schlüssel, ein Update bringt den neuen mit, bevor der alte abgeschaltet wird.

### Automatische Updates auf den Servern

- **WordPress:** Das Plugin nutzt den Plugin-Header `Update URI` und den passenden Filter (seit WordPress 5.8). Updates erscheinen im Dashboard, automatische Updates sind standardmäßig an. Die Signatur prüft das Plugin vor dem Einspielen.
- **Standalone:** Eine geplante Aufgabe prüft täglich auf neue Releases des eigenen Kompanie-Repos. Das Einspielen läuft so:
  1. ZIP laden und Signatur prüfen
  2. in ein neues Verzeichnis entpacken
  3. Wartungsmodus an, Verzeichnisse tauschen
  4. Migrationen ausführen, Wartungsmodus aus
  5. bei einem Fehler zurück auf die alte Version
- Automatische Updates sind standardmäßig an und im Admin-Bereich abschaltbar. Ein Hinweis zeigt, wenn eine neue **Hauptversion** bereitliegt; die wird nie automatisch eingespielt.
- Bei Releases mit Datenbank-Migrationen erstellt der Updater vorher eine Sicherung der App-Tabellen.

## 10. Installation

### Web-Installer (`installer/install.php`)

Eine einzelne Datei. Sie kennt die Kompanie (Repo-Name ist beim Build eingesetzt) und den öffentlichen Signaturschlüssel.

1. **Voraussetzungen prüfen:** PHP-Version, Erweiterungen, Schreibrechte, HTTPS, Speicherplatz. Jedes Problem wird verständlich erklärt, mit einem Hinweis, was beim Hoster einzustellen ist.
2. **WordPress erkennen:** Liegt eine WordPress-Installation daneben, wird das Plugin installiert und aktiviert, nachdem sich ein WP-Administrator angemeldet hat. Sonst die eigenständige Version.
3. **Release laden**, Signatur prüfen, entpacken.
4. Übergabe an den **Einrichtungsassistenten**.
5. Der Installer **löscht sich selbst**. Gelingt das nicht, sperrt er sich und fordert zum Löschen auf.

### Einrichtungsassistent (beide Varianten)

Fragt nur ab, was nicht automatisch geht, und prüft jeden Schritt sofort:

1. Datenbank-Zugang (nur Standalone), Test der Verbindung
2. erster Admin (nur Standalone)
3. Kalender-Link, Testabruf
4. Galerie: „Mit Google verbinden“ bzw. Ordnerfreigabe, Live-Prüfung
5. News-Quelle (nur Standalone: Adresse einer WordPress-Seite oder eines RSS-Feeds)
6. Cron (nur Standalone): Anleitung für den Cronjob beim Hoster, Prüfung, ob er läuft; sonst Pseudo-Cron
7. Selbsttest: Sind geschützte Ordner von außen wirklich nicht erreichbar?

## 11. Die beiden Adapter

### WordPress-Plugin (`adapters/wordpress`)

- Setzt die Ports mit WordPress-Funktionen um und liefert die App unter `/app/` aus, inkl. geschützter Galeriebilder unter `/app/foto/…`.
- WP-Rollen „Mitglied“ und Spieß-Rolle zusätzlich zur bisherigen Rolle, Einstellung im Benutzerprofil (wie heute).
- Einstellungsseite im WP-Admin mit demselben Einrichtungsassistenten.
- Shortcodes `[kompanie_termine]` und `[kompanie_galerie]`.
- „Angemeldet bleiben“ 400 Tage mit automatischer Verlängerung (heute `MKL_Session`).
- News-Quelle standardmäßig: die eigenen Blogbeiträge.

### Eigenständige Version (`adapters/standalone`)

- **Benutzer:** eigene Tabelle, Passwörter mit `password_hash`. Admins legen Mitglieder an, ein Startpasswort wird nur einmal angezeigt (wie heute). Hat jemand sein Passwort vergessen, erzeugt ein Admin ein neues Startpasswort; E-Mail-Versand ist nicht nötig.
- **Sitzung:** Cookie `HttpOnly`, `Secure`, `SameSite=Lax`, Sitzungsschlüssel nur gehasht in der Datenbank. 400 Tage mit automatischer Verlängerung. Passwortänderung meldet alle anderen Geräte ab.
- **Sicherheit:**
  - Begrenzung fehlgeschlagener Anmeldeversuche pro Konto und IP
  - `config.php` und geschützte Dateien außerhalb des Web-Verzeichnisses, wenn der Hoster das erlaubt; sonst Sperre per `.htaccess` **und** Prüfung im Selbsttest (nginx wertet `.htaccess` nicht aus)
  - Cron-Adresse mit langem Zufallsschlüssel
  - Sicherheits-Header (CSP, `X-Content-Type-Options`, `Referrer-Policy`)
- **Einstellungen und Updates:** im Admin-Bereich der App.
- **Termine auf fremden Webseiten:** einbettbarer Ausschnitt `/embed/termine`. *Später.*

## 12. Umstellung von Lank

Wird **getrennt geplant**. Bis dahin gilt für die Entwicklung: **Neue Funktionen für Lank werden im Kern gebaut**; im Lank-Repo nur noch Fehlerkorrekturen, die dann auch in den Kern übernommen werden. Sonst laufen beide auseinander.

Für die Planung festgehalten:

- Lank bleibt auf WordPress und nutzt das WordPress-Plugin des Kerns.
- Tabellenpräfix einstellbar, erste Migration = Lank-Schema 3: Tabellen `wp_mkl_*` und Galerie-Dateien bleiben.
- Offene Punkte: API-Namensraum (`mk-lank/v1` → `kompanie-app/v1`) und bereits installierte PWAs mit altem Service Worker, Rollen `mkl_mitglied`/`mkl_spiess`, Optionen `mkl_*`, Shortcodes `[mkl_termine]`/`[mkl_galerie]` (als Alias weiterführen), Kalender über das Plugin „Private Google Calendars“ (`pgc_*`), Anmelde-Cookies der Mitglieder sollen erhalten bleiben.

## 13. Tests

- **Unit-Tests** für den Kern (PHPUnit) mit Test-Umsetzungen der Ports (feste Uhrzeit, simuliertes Google Drive wie heute `drive-mock.php`).
- **Contract-Tests:** Die heutigen REST-Tests (`plugin/tests/rest-api-test.php`) werden zu HTTP-Tests und laufen gegen **beide** Adapter.
- **E2E-Tests** (Playwright) gegen beide Adapter.
- **Installer und Updater:** Installation in einem frischen Verzeichnis, Update von der vorigen Version, Abbruch bei falscher Signatur, Zurückrollen bei fehlgeschlagener Migration.
- **Branding:** Kontrastprüfung, Icon-Erzeugung, Screenshots.
- CI mit **MariaDB**; **Grenzprüfung**, dass im Kern keine WordPress-Funktionen auftauchen.

## 14. Fahrplan

| Phase | Inhalt | Ergebnis |
|---|---|---|
| 0 | Architektur, API-Spezifikation, Konfigurationsschema, Schnittstellen | dieses Dokument und das Gerüst |
| G | **Test-Prototyp Galerie** (`drive.file` + Picker), parallel möglich | Entscheidung „Mit Google verbinden“ oder „Ordner freigeben“ |
| 1 | **Kern und WordPress-Adapter zusammen:** Das Lank-Plugin wird zum WordPress-Adapter, dann zieht ein Bereich nach dem anderen in den Kern. Die bestehenden Tests bleiben dabei grün. | Plugin mit dem Funktionsumfang von Lank 0.3 auf dem Kern |
| 2 | Frontend verallgemeinern: `/config`, CSRF, Anmeldearten, Branding aus `kompanie.json` | eine App für alle |
| 3 | Eigenständige Version: Benutzer, Sitzung, Einstellungen, Cron, Sicherheit | eigenständige Version |
| 4 | Branding-Werkzeug, Build-Workflow, Signatur, `Kompanie-App-Vorlage`, Screenshots | Kompanie-Repo baut signierte ZIPs |
| 5 | Web-Installer, Einrichtungsassistent, automatische Updates | Installation und Updates ohne Handarbeit |
| 6 | Formulare „Neue Kompanie“ / „Kompanie ändern“, „Alle Kompanien neu bauen“ | neue Kompanie nur durch Eintragen |
| später | Übersicht aller Installationen (Version je Kompanie), `/embed/termine` | |
| – | Umstellung Lank | eigener Plan |

### Was wir dafür in der Organisation brauchen

- eine **GitHub App** (oder ein fein abgestimmtes Token) mit Rechten zum Anlegen der Kompanie-Repos und Auslösen ihrer Builds
- einen **Ed25519-Signaturschlüssel** als Organisations-Secret
- ein **Google-Cloud-Projekt** mit OAuth-Client (für den Test-Prototyp und „Mit Google verbinden“) bzw. Berechtigung zum Anlegen von Dienstkonten (Ersatzlösung)
