# Eigenständige Version

Die Kompanie-App ohne WordPress, auf normalem Webspace mit PHP ab 8.1. Daten liegen in einer
SQLite-Datei (Standard, keine Einrichtung nötig) oder in einer MySQL/MariaDB-Datenbank.
Kern, API und App sind dieselben wie beim WordPress-Plugin (siehe [Architektur](../../docs/ARCHITEKTUR.md)).

Für Kompanien gibt es später den Installer `install.php` mit Einrichtungsassistent (Phase 5).
Diese Seite beschreibt den Aufbau und die Einrichtung von Hand, z. B. für Hoster mit SSH.

## Aufbau

| Pfad | Inhalt |
|---|---|
| `index.php` | Einstieg für alle Anfragen (App, API, Manifest, Galeriebilder, Cron) |
| `sw.js` | Service Worker; liegt neben `index.php`, damit er die ganze App abdeckt |
| `app/` | die gebaute App (`scripts/build-frontend.sh`) |
| `src/`, `core/` | Programmcode (im Paket; während der Entwicklung kommt der Kern aus `packages/core`) |
| `bin/kompanie.php` | Kommandozeile: einrichten, Admin anlegen, Cron, Sicherung |
| `media/` | öffentliche Galeriebilder (vom Webserver direkt ausgeliefert) |
| `data/` | `config.php`, Datenbank, interne Galeriebilder – **nie von außen erreichbar** |

Das Datenverzeichnis kann außerhalb des Web-Verzeichnisses liegen: Dazu neben `index.php` eine Datei
`data-dir.php` mit `<?php return '/pfad/zu/den/daten';` anlegen.

## Adressen

Ohne URL-Umschreibung (läuft überall):

| Adresse | |
|---|---|
| `https://beispiel.de/app/` | App |
| `…/index.php?r=/api/v1/…` | API |
| `…/index.php?r=/foto/<id>/<größe>` | interne oder noch nicht aufbereitete Galeriebilder |
| `…/index.php?r=/cron&key=…` | fällige Aufgaben (Galerie-Abgleich) |

Mit URL-Umschreibung (`url_mode = 'rewrite'` in `config.php`, Apache/LiteSpeed über die mitgelieferte
`.htaccess`) heißen sie `…/api/v1/…`, `…/foto/…` und `…/cron?key=…`.

## Einrichtung von Hand

```sh
php bin/kompanie.php install --url=https://beispiel.de/app/            # SQLite (Adresse ist Pflicht)
php bin/kompanie.php install --url=… --mysql --db-host=localhost \
    --db-name=… --db-user=… --db-pass=… [--db-prefix=kompanie_]         # oder MySQL/MariaDB
php bin/kompanie.php create-admin --name="Vorname Nachname" --login=vorname
```

`--rewrite` schaltet die schönen Adressen ein (nur, wenn `.htaccess` ausgewertet wird).
SQLite muss mindestens Version 3.24 haben (seit 2018 überall vorhanden).
Ohne `--password` erzeugt `create-admin` ein Startpasswort und zeigt es einmal an.

Weitere Befehle: `status`, `cron`, `reset-password --login=…`, `export [--out=datei.json]`, `import datei.json`.
Die Sicherung enthält alle Daten, Benutzer (mit Passwort-Hashes) und Einstellungen; Galeriebilder werden
nach dem Wiederherstellen neu aus Google Drive erzeugt. `import` sichert vorher den bisherigen Stand in das
Datenverzeichnis (`vor-import-….json`).

## Wiederkehrende Aufgaben

Ein Cronjob ist **nicht nötig**: Nach Seitenaufrufen erledigt der Server fällige Aufgaben selbst, nachdem
die Antwort verschickt ist (PHP-FPM, LiteSpeed) oder über einen Aufruf der eigenen, fest eingetragenen
Adresse im Hintergrund, auf den der Besucher nicht wartet (höchstens einmal pro Minute; schlägt er
wiederholt fehl, seltener, und die Statusseite meldet es). Ob etwas fällig ist, steht in den
Einstellungen; normale Seitenaufrufe lesen und schreiben die Aufgaben nicht.

**Empfohlen** ist trotzdem ein Cronjob beim Hoster, alle 15 Minuten: `php /pfad/bin/kompanie.php cron` oder
ein Abruf der Cron-Adresse (`php bin/kompanie.php status` und die Statusseite der App zeigen sie an).
Dann laufen Galerie, Kalender und Updates auch, wenn die App selten aufgerufen wird.

## Zwischenspeicher und Komprimierung

Auf Apache und LiteSpeed regeln `app/.htaccess` und `app/assets/.htaccess` das selbst: Die gehashten Dateien
unter `app/assets/` bleiben ein Jahr im Browser, Icons und Manifest einen Tag; Text wird komprimiert
(`mod_deflate`, falls vorhanden). Auf **nginx** bitte entsprechend einstellen, z. B.:

```nginx
location ~ /app/assets/ { add_header Cache-Control "public, max-age=31536000, immutable"; }
gzip on; gzip_types text/css application/javascript application/json application/manifest+json image/svg+xml;
```

## Updates

Die App prüft einmal täglich die signierte Update-Liste ihrer Kompanie und spielt neue Versionen derselben
Hauptversion selbst ein (abschaltbar unter *Einstellungen → Zustand und Updates*). Dabei werden die Programmdateien
getauscht; bei einem Fehler kommt der vorige Stand zurück. `data/`, `media/` und `data-dir.php` bleiben
unberührt. Vor Updates mit Datenbank-Änderungen sichert die App die Daten (die letzten fünf Sicherungen).

```sh
php bin/kompanie.php update-check              # Liste prüfen und anzeigen
php bin/kompanie.php update                    # neueste freigegebene Version einspielen
php bin/kompanie.php rollback --version=1.4.0.2   # auf eine ältere Version zurück
```

Bitte als derselbe Benutzer ausführen, unter dem PHP auf dem Webspace läuft (sonst gehören die neuen Dateien
einem anderen Benutzer). Klappt gar nichts mehr: `install.php` von der Download-Seite erneut hochladen und
öffnen; nach Anmeldung eines Admins spielt er die freigegebene Version neu ein, die Daten bleiben.

## Sicherheit

- Passwörter mit `password_hash`; vergessene Passwörter setzt ein Admin neu (kein E-Mail-Versand).
- Sitzung per Cookie (`HttpOnly`, `SameSite=Lax`, `Secure` bei HTTPS); in der Datenbank steht nur der Hash
  des Sitzungsschlüssels. „Angemeldet bleiben“: 400 Tage, verlängert sich beim Öffnen der App.
  Eine Passwortänderung meldet alle anderen Geräte ab.
- Änderungen nur als JSON, nicht von fremden Seiten (`Origin`) und mit CSRF-Token (HMAC über die Sitzung).
- Fehlversuche bei der Anmeldung sind begrenzt (in 15 Minuten: je Konto und Gerät 5, je IP-Adresse bzw.
  IPv6-/64-Netz 20, je Konto 50). Gezählt wird je Konto, gleich ob per Name oder E-Mail. Geräte, auf denen
  sich das Konto schon angemeldet hat, zählen beim Konto-Limit nicht mit – Fremde können den Besitzer so
  nicht aussperren. **Achtung hinter einem Proxy oder CDN** (z. B. Cloudflare): Dann kommen alle Anfragen
  scheinbar von derselben IP-Adresse, und die Grenzen je Gerät und je IP gelten für alle zusammen – Fremde
  könnten so die Anmeldung für alle sperren. Die App wertet absichtlich keine weitergeleiteten Adressen aus
  (sie ließen sich fälschen); solche Installationen sollten ohne vorgeschalteten Proxy laufen.
- Falsche „aktuelle Passwörter“ beim Passwortwechsel: höchstens 5 in 15 Minuten.
- Sicherheits-Header und Content-Security-Policy mit Nonce für die App-Seite; Fehler erscheinen nie mit
  Einzelheiten im Browser, sondern nur im Fehlerprotokoll des Servers.
- Die Adresse der App steht fest in `config.php` (`base_url`); Anfragen mit anderer Schreibweise (z. B. ohne
  `www`) werden dorthin umgeleitet.

### nginx

nginx wertet `.htaccess` nicht aus. Das Datenverzeichnis muss dann gesperrt werden, z. B.:

```nginx
location ~ ^/app/(data|src|core|bin)/ { deny all; return 404; }
location ~ ^/app/(data-dir\.php|autoload\.php|VERSION|kompanie\.json|update\.json)$ { deny all; return 404; }
location ~ ^/app/\. { deny all; return 404; }   # Arbeitsordner von Updates (.neu-…, .alt-…)
location /app/ { try_files $uri /app/index.php$is_args$args; }
location = /app/index.php { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root/app/index.php; fastcgi_pass php; }
```

Besser liegt das Datenverzeichnis ganz außerhalb des Web-Verzeichnisses (`data-dir.php`, siehe oben).
Die SQLite-Datei hat einen zufälligen Namen (`kompanie-<32 Zeichen>.sqlite`), damit sie auch bei einer
fehlenden Sperre nicht zu erraten ist.
Der Installer prüft, ob die Daten von außen wirklich nicht erreichbar sind, und legt sie sonst außerhalb
des Web-Verzeichnisses ab (oder bricht ab, wenn das nicht geht).

## Tests

```sh
php tests/standalone/run-tests.php                    # HTTP-Tests mit SQLite
php tests/standalone/run-tests.php --mysql='host;db;benutzer;passwort'
scripts/e2e-standalone.sh                              # E2E im Browser (vorher scripts/build-frontend.sh)
```
