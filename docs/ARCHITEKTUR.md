# Architektur der Kompanie-App

Dieses Repo ist der **gemeinsame Kern** der Kompanie-App. Jede Kompanie bekommt ein eigenes, kleines Repo mit ihrer Konfiguration und ihren Bildern. Daraus entsteht beim Build die fertige App der Kompanie. Fehlerkorrekturen und neue Funktionen fließen über neue Kern-Versionen zu allen Kompanien.

Ausgangspunkt ist die App der Marinekompanie 1973 Lank (Repo `Marinekompanie-Lank-App`). Ihre Funktionen werden hier herausgelöst und von WordPress getrennt. Die Umstellung von Lank selbst wird getrennt geplant (siehe [Abschnitt 9](#9-umstellung-von-lank)).

## 1. Entscheidungen

| Frage | Entscheidung |
|---|---|
| Wie kommen Updates zu den Kompanien? | Gemeinsamer Kern mit Versionen. Kompanie-Repos enthalten nur Konfiguration und Assets. |
| Wer hostet? | Jede Kompanie selbst, auf ihrem eigenen Webspace. |
| WordPress? | Optional. Es gibt ein **WordPress-Plugin** und eine **eigenständige Version** ohne WordPress. Beide nutzen denselben Kern. |
| Lank | Wird umgestellt, aber in einem eigenen, später geplanten Schritt. |

Daraus folgen die technischen Rahmenbedingungen:

- **Server: nur PHP und MySQL/MariaDB.** Vereine haben meist günstigen Webspace ohne Node.js, ohne Docker und ohne Shell-Zugang. Node.js braucht es nur beim Build (GitHub Actions).
- **Keine Laufzeit-Abhängigkeiten über Composer.** Der Kern bringt alles selbst mit (wie das heutige Plugin, das z. B. das Google-JWT ohne Bibliothek signiert). Composer dient nur für Autoloading und Entwicklungswerkzeuge; das fertige ZIP enthält alles.
- **PHP ≥ 8.1.** *Offen:* Vor der Festlegung prüfen, welche PHP-Version der Webspace von Lank und der ersten weiteren Kompanie anbietet. Das heutige Lank-Plugin verlangt nur PHP 7.4.

## 2. Überblick

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
│ (Plugin)                 │                │ (public/index.php)         │
│ $wpdb, WP-Login, Options,│                │ PDO, eigene Benutzer,      │
│ WP-Cron, Shortcodes      │                │ eigene Sitzung, Cronjob    │
└────────────┬─────────────┘                └───────────────┬───────────┘
             │      setzen die Schnittstellen (Ports) um    │
             └──────────────────────┬───────────────────────┘
                ┌───────────────────┴──────────────────────┐
                │  Kern (PHP, ohne WordPress)              │
                │  Punkte, Strafen, Katalog, Termine,      │
                │  Galerie, Kalender, News, Rechte, Routen │
                └──────────────────────────────────────────┘
```

Grundregel: **Im Kern steht kein einziger WordPress-Aufruf.** Alles, was von der Umgebung abhängt, läuft über eine Schnittstelle in `packages/core/src/Port/`. Eine automatische Prüfung in der CI soll das absichern (Suche nach `wp_`, `$wpdb`, `get_option` usw. in `packages/core/`).

## 3. Verzeichnisse

```
api/openapi.yaml            Vertrag zwischen Frontend und Server (beide Adapter)
config/
  kompanie.schema.json      Schema der Kompanie-Konfiguration
  kompanie.example.json     Beispiel
packages/
  core/                     PHP-Kern, Namespace KompanieApp\Core
    src/Domain/             Fachlogik: Punkte, Strafen, Katalog, Termine, Galerie, Kalender, News
    src/Port/               Schnittstellen zur Umgebung (siehe Abschnitt 4)
    src/Http/               Routentabelle, Rechteprüfung, Controller, Fehlerformat
    src/Migration/          Datenbank-Migrationen (MySQL und SQLite)
    tests/                  Unit-Tests
  frontend/                 React + TypeScript (Vite), die PWA
adapters/
  wordpress/                WordPress-Plugin
  standalone/               eigenständige Installation
tests/contract/             dieselben API- und E2E-Tests gegen beide Adapter
kompanie-vorlage/           Gerüst für ein neues Kompanie-Repo
.github/workflows/          CI des Kerns + wiederverwendbarer Build-Workflow für Kompanie-Repos
```

## 4. Der Kern und seine Schnittstellen

Der Kern bekommt alle Abhängigkeiten von außen übergeben. Jeder Adapter baut beim Start einen `Container` mit seinen Umsetzungen zusammen.

| Port | Zweck | WordPress | Standalone |
|---|---|---|---|
| `Database` | SQL-Abfragen mit Platzhaltern, Transaktionen, Tabellenpräfix | `$wpdb` | PDO (MySQL/MariaDB, SQLite für Tests) |
| `AuthContext` | aktueller Benutzer mit Rolle und Rechten | WP-Benutzer + Capabilities | eigene Sitzung |
| `UserDirectory` | Benutzer suchen, anlegen, Rolle setzen, Passwort prüfen/ändern | `get_users`, `wp_insert_user`, … | eigene Tabelle `users` |
| `Settings` | Einstellungen lesen/schreiben | `get_option` | Tabelle `settings` |
| `Cache` | Zwischenspeicher mit Ablaufzeit | Transients | Tabelle `cache` |
| `HttpClient` | Abrufe von Google, iCal, News-Quellen | `wp_remote_*` | cURL |
| `FileStorage` | öffentliche und geschützte Dateien (Galerie) | Upload-Verzeichnis | `storage/` (geschützt) und `public/media/` |
| `ImageResizer` | Bildgrößen erzeugen | `wp_get_image_editor` | GD oder Imagick |
| `Scheduler` | wiederkehrende Aufgaben (Galerie-Abgleich) | WP-Cron | Cronjob des Hosters, sonst Pseudo-Cron |
| `NewsSource` | Neuigkeiten | eigene Beiträge (`WP_Query`) | fremde WordPress-Seite, RSS oder keine |
| `Clock` | aktuelle Zeit und Zeitzone (für Tests austauschbar) | `wp_timezone` | `date_default_timezone` aus Konfiguration |

Damit steht das **SQL nur einmal im Kern**. Die Adapter liefern nur die Verbindung. Die Abfragen müssen auf MySQL/MariaDB und SQLite laufen; das ist schon heute so, weil die Lank-Tests mit SQLite laufen.

Das **Tabellenpräfix** ist einstellbar. So kann Lank später seine vorhandenen Tabellen `wp_mkl_*` weiter nutzen.

### Rechte

Der Kern kennt eigene, von WordPress unabhängige Rechte (`Capability`):

| Recht | Bedeutung | heute in Lank |
|---|---|---|
| `member` | eigene Punkte/Strafen, interner Kalender, interne Galerie | `mkl_member` |
| `manage` | Punkte und Strafen vergeben, Katalog pflegen (Spieß) | `mkl_manage` |
| `manage_events` | öffentliche Termine pflegen | `mkl_manage_events` |
| `create_users` | Mitglieder anlegen | WP `create_users` |
| `promote_users` | Rollen vergeben | WP `promote_users` |

Die Rollen Gast, Mitglied, Spieß und Admin ergeben sich daraus wie heute. Die **Bezeichnungen** („Spieß“, „Mitglied“) kommen aus der Kompanie-Konfiguration; intern heißen die Rollen `guest`, `member`, `manager`, `admin`.

Unverändert gilt: Die `/me`-Routen lesen die Benutzer-ID immer aus der Sitzung, nie aus der Anfrage. Stornierte Einträge werden nur ausgeblendet, nicht gelöscht, und es wird gespeichert, wer sie angelegt hat.

### Routen

Der Kern enthält **eine Routentabelle** (Methode, Pfad, nötiges Recht, Controller). Beide Adapter lesen sie:

- **WordPress** registriert jede Route mit `register_rest_route` unter `/wp-json/kompanie-app/v1/`. Die Rechteprüfung ruft den Kern auf.
- **Standalone** hat einen eigenen Front-Controller unter `/api/v1/`.

So können sich die beiden Varianten nicht auseinanderentwickeln. Die Routen sind in `api/openapi.yaml` beschrieben; die Contract-Tests prüfen, dass beide Adapter sie einhalten.

## 5. Frontend

Das Frontend kommt aus `Marinekompanie-Lank-App/app` und wird so geändert, dass es keinen Server mehr voraussetzt:

| Heute (an WordPress gebunden) | Künftig |
|---|---|
| Konfiguration als `window.MKL_CONFIG` aus PHP | `GET {apiBase}/config`; nur `apiBase` steht in der ausgelieferten `index.html` |
| Header `X-WP-Nonce`, Erneuerung über `admin-ajax.php?action=rest-nonce` | Header `X-CSRF-Token`, Erneuerung über `GET /auth/csrf` |
| Anmelden immer über `wp-login.php` | `auth.mode` aus `/config`: `form` (Anmeldeformular in der App, `POST /auth/login`) oder `redirect` (Weiterleitung, z. B. zu `wp-login.php`) |
| `X-WP-TotalPages` bei News | `X-Total-Pages` |
| Service Worker erkennt `/wp-json/mk-lank/v1/` | API-Pfad wird beim Registrieren übergeben (`sw.js?api=…`) |
| Build landet im Plugin-Ordner | Build nach `dist/`, die Adapter-Builds kopieren es |
| Name, Domain, Farben, Logo fest im Code | aus `kompanie.json` (siehe Abschnitt 6) |

**CSRF beim WordPress-Adapter:** WordPress prüft bei Cookie-Anmeldung den Header `X-WP-Nonce`. Der Adapter liefert den REST-Nonce als CSRF-Token aus und übernimmt `X-CSRF-Token` als `X-WP-Nonce`, bevor WordPress die Anmeldung prüft. Das Frontend muss davon nichts wissen. *Vor der Umsetzung im Test-WordPress prüfen.*

**Anmeldung beim WordPress-Adapter:** Standard ist `redirect` zu `wp-login.php`. Damit funktionieren Login-Schutz- und Zwei-Faktor-Plugins der Webseite weiter. `form` über `wp_signon` ist möglich, umgeht diese Plugins aber teilweise und ist deshalb nur eine Option.

## 6. Kompanie-Konfiguration und Branding

Jede Kompanie beschreibt sich in `kompanie.json` (Schema: [`config/kompanie.schema.json`](../config/kompanie.schema.json), Beispiel: [`config/kompanie.example.json`](../config/kompanie.example.json)):

- Name, Kurzname (Home-Bildschirm), Beschreibung, Domain, App-Pfad
- Farben (die CSS-Variablen aus `styles.css`), Schriften, Logo, App-Icons
- Bezeichnungen der Rollen und Begrüßung (heute „Ahoi, …“)
- welche Bereiche es gibt: Termine, Kalender, News, Galerie, Punkte, Strafen
- Sprache und Zeitzone, Währung für Strafen

**Was beim Build, was zur Laufzeit?** Jede Kompanie baut ihr eigenes Paket. Deshalb wird fast alles **beim Build** eingesetzt: `manifest.webmanifest`, `index.html`, Icons, Farben, Texte und die Bilder der Installationsanleitung (heute fest „marinekompanie-lank.de“ in `Installieren.tsx`). Zur Laufzeit kommt nur, was der Server wissen muss: Adressen, angemeldeter Benutzer, Anmeldeart und welche Bereiche eingerichtet sind (z. B. ist die Galerie aus, solange kein Google-Ordner hinterlegt ist).

Zugangsdaten (Google-Dienstkonto, API-Keys, Datenbank) gehören **nie** in `kompanie.json` oder ins Repo. Sie werden auf dem Server eingegeben: im WP-Admin bzw. im Einstellungsbereich der eigenständigen Version.

## 7. Die beiden Adapter

### WordPress-Plugin (`adapters/wordpress`)

- Ein Plugin `kompanie-app`, das den Kern einbindet und die Ports mit WordPress-Funktionen umsetzt.
- Liefert die App unter `/app/` aus (wie heute `class-mkl-app-page.php`), inkl. geschützter Galeriebilder unter `/app/foto/…`.
- Rollen „Mitglied“ und Spieß-Rolle als zusätzliche WP-Rollen, Einstellung im Benutzerprofil (wie heute).
- Einstellungsseite im WP-Admin (Kalender, Google-Dienstkonto, Galerie-Ordner, News-Quelle).
- Shortcodes `[kompanie_termine]` und `[kompanie_galerie]` für die Webseite.
- „Angemeldet bleiben“ mit 400 Tagen und automatischer Verlängerung (heute `MKL_Session`).
- News-Quelle standardmäßig: die eigenen Blogbeiträge.

### Eigenständige Version (`adapters/standalone`)

Für Kompanien ohne WordPress. Läuft auf jedem Webspace mit PHP und MySQL/MariaDB:

- **Installation:** ZIP hochladen, Adresse öffnen, Einrichtungsassistent fragt Datenbank-Zugang und den ersten Admin ab und schreibt `config.php`. Liegt `config.php` außerhalb des Web-Verzeichnisses, wenn der Hoster das erlaubt.
- **Benutzer:** eigene Tabelle, Passwörter mit `password_hash` (bcrypt/Argon2id). Admins legen Mitglieder an und vergeben ein Startpasswort, das nur einmal angezeigt wird (wie heute). Hat ein Mitglied sein Passwort vergessen, setzt ein Admin ein neues Startpasswort; dafür braucht es keinen E-Mail-Versand. Passwort-Zurücksetzen per E-Mail ist optional für später.
- **Sitzung:** Cookie `HttpOnly`, `Secure`, `SameSite=Lax`. Der Sitzungsschlüssel wird nur gehasht in der Datenbank gespeichert. 400 Tage mit automatischer Verlängerung wie beim Plugin. Passwortänderung meldet alle anderen Geräte ab. Begrenzung fehlgeschlagener Anmeldeversuche.
- **Einstellungen:** im Admin-Bereich der App statt im WP-Admin.
- **Galerie-Abgleich:** über einen Cronjob des Hosters (`php bin/cron.php` oder Aufruf einer geheimen URL). Gibt es keinen Cronjob, stößt die App den Abgleich höchstens stündlich bei einem Seitenaufruf an.
- **News:** wahlweise aus einer vorhandenen WordPress-Seite (öffentliche `/wp-json/wp/v2/posts`), aus einem RSS-Feed oder ausgeschaltet.
- **Termine auf der Webseite:** statt Shortcodes ein einbettbarer Ausschnitt (`/embed/termine`) für beliebige Webseiten. *Später.*

Der größte Aufwand liegt hier, nicht im Frontend: Benutzerverwaltung, Sitzung, Einstellungsoberfläche, Installer und Cron gibt es bei WordPress geschenkt und müssen für die eigenständige Version gebaut und abgesichert werden.

## 8. Versionen, Build und Kompanie-Repos

- Der Kern bekommt **Versionsnummern nach SemVer** und ein Release pro Version (Tag `v1.2.3`). Brechende Änderungen an API, Konfiguration oder Datenbank erhöhen die erste Stelle und stehen im Changelog.
- Ein Kompanie-Repo (Gerüst in `kompanie-vorlage/`) enthält:
  ```
  kompanie.json        Name, Farben, Bereiche, gewünschte Kern-Version
  assets/              Logo, Icons, Bilder
  .github/workflows/build.yml
  ```
- `build.yml` ruft den **wiederverwendbaren Workflow** dieses Repos auf (`uses: Steuten-IT-Solutions/Kompanie-App-Template/.github/workflows/build-kompanie.yml@v1`). Er lädt die angegebene Kern-Version, setzt Konfiguration und Assets ein, baut das Frontend und erzeugt zwei ZIPs: das WordPress-Plugin und die eigenständige Version. Jede Kompanie lädt sich das passende ZIP aus ihrem Release.
- Ein Update für eine Kompanie heißt: Kern-Version in `kompanie.json` erhöhen, mergen, ZIP einspielen.
- Datenbank-Migrationen laufen beim ersten Aufruf nach einem Update automatisch, wie heute beim Plugin.

*Hinweis zum Namen:* Das Repo heißt `Kompanie-App-Template`, ist aber der Kern, keine Kopiervorlage. Als GitHub-„Template repository“ eignet sich eher das Kompanie-Gerüst. Eine spätere Umbenennung (z. B. in `Kompanie-App`) wäre sauberer; GitHub leitet alte Adressen weiter, aber feste Verweise in Workflows sollten dann angepasst werden.

## 9. Umstellung von Lank

Wird **getrennt geplant**. Für diese Planung festgehalten:

- Lank bleibt auf WordPress und nutzt später das WordPress-Plugin des Kerns.
- Tabellenpräfix einstellbar, damit die vorhandenen Tabellen `wp_mkl_*` und Galerie-Dateien weiter genutzt werden können.
- Offene Punkte: API-Namensraum (`mk-lank/v1` → `kompanie-app/v1`) und bereits installierte PWAs mit altem Service Worker, Rollen `mkl_mitglied`/`mkl_spiess`, Optionen `mkl_*`, Shortcodes `[mkl_termine]`/`[mkl_galerie]` auf der Webseite, Anmelde-Cookies der Mitglieder sollen erhalten bleiben.

## 10. Tests

- **Unit-Tests** für den Kern (PHPUnit), mit Test-Umsetzungen der Ports (SQLite, feste Uhrzeit, simuliertes Google Drive wie heute `drive-mock.php`).
- **Contract-Tests:** Die heutigen REST-Tests (`plugin/tests/rest-api-test.php`) werden in HTTP-Tests umgeschrieben und laufen gegen **beide** Adapter.
- **E2E-Tests** (Playwright) gegen beide Adapter.
- **Grenzprüfung:** CI schlägt fehl, wenn im Kern WordPress-Funktionen auftauchen.

## 11. Fahrplan

| Phase | Inhalt | Ergebnis |
|---|---|---|
| 0 | Architektur, API-Spezifikation, Konfigurationsschema, Schnittstellen | dieses Dokument und das Gerüst |
| 1 | Kern aus dem Lank-Plugin herauslösen: Fachlogik, SQL, Routentabelle, Unit-Tests | Kern ohne WordPress |
| 2 | WordPress-Adapter | Plugin mit gleichem Funktionsumfang wie Lank 0.3 |
| 3 | Frontend verallgemeinern: `/config`, CSRF, Anmeldearten, Branding aus `kompanie.json` | eine App für alle |
| 4 | Eigenständige Version: Installer, Benutzer, Sitzung, Einstellungen, Cron | ZIP ohne WordPress |
| 5 | Build-Workflow für Kompanie-Repos, `kompanie-vorlage/`, erste Releases | neue Kompanie in kurzer Zeit eingerichtet |
| – | Umstellung Lank | eigener Plan |
