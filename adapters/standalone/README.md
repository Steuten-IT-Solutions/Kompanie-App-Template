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
php bin/kompanie.php install --url=https://beispiel.de/app/            # SQLite
php bin/kompanie.php install --url=… --mysql --db-host=localhost \
    --db-name=… --db-user=… --db-pass=… [--db-prefix=kompanie_]         # oder MySQL/MariaDB
php bin/kompanie.php create-admin --name="Vorname Nachname" --login=vorname
```

`--rewrite` schaltet die schönen Adressen ein (nur, wenn `.htaccess` ausgewertet wird).
Ohne `--password` erzeugt `create-admin` ein Startpasswort und zeigt es einmal an.

Weitere Befehle: `status`, `cron`, `reset-password --login=…`, `export [--out=datei.json]`, `import datei.json`.
Die Sicherung enthält alle Daten, Benutzer (mit Passwort-Hashes) und Einstellungen; Galeriebilder werden
nach dem Wiederherstellen neu aus Google Drive erzeugt.

## Wiederkehrende Aufgaben

Ein Cronjob ist **nicht nötig**: Nach Seitenaufrufen erledigt der Server fällige Aufgaben selbst, nachdem
die Antwort verschickt ist (PHP-FPM, LiteSpeed) oder über einen Aufruf im Hintergrund. Zuverlässiger wird es
mit einem stündlichen Cronjob beim Hoster: `php /pfad/bin/kompanie.php cron` oder ein Abruf der Cron-Adresse
(`php bin/kompanie.php status` zeigt sie an).

## Sicherheit

- Passwörter mit `password_hash`; vergessene Passwörter setzt ein Admin neu (kein E-Mail-Versand).
- Sitzung per Cookie (`HttpOnly`, `SameSite=Lax`, `Secure` bei HTTPS); in der Datenbank steht nur der Hash
  des Sitzungsschlüssels. „Angemeldet bleiben“: 400 Tage, verlängert sich beim Öffnen der App.
  Eine Passwortänderung meldet alle anderen Geräte ab.
- Änderungen nur als JSON, nicht von fremden Seiten (`Origin`) und mit CSRF-Token (HMAC über die Sitzung).
- Fehlversuche bei der Anmeldung sind begrenzt (je Gerät 5, je IP-Adresse 20, je Benutzer 50 in 15 Minuten).
- Sicherheits-Header und Content-Security-Policy mit Nonce für die App-Seite.

### nginx

nginx wertet `.htaccess` nicht aus. Das Datenverzeichnis muss dann gesperrt werden, z. B.:

```nginx
location ~ ^/app/(data|src|core|bin)/ { deny all; return 404; }
location /app/ { try_files $uri /app/index.php$is_args$args; }
location = /app/index.php { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root/app/index.php; fastcgi_pass php; }
```

Besser liegt das Datenverzeichnis ganz außerhalb des Web-Verzeichnisses (`data-dir.php`, siehe oben).
Der Installer (Phase 5) prüft, ob die Daten von außen wirklich nicht erreichbar sind.

## Tests

```sh
php tests/standalone/run-tests.php                    # HTTP-Tests mit SQLite
php tests/standalone/run-tests.php --mysql='host;db;benutzer;passwort'
scripts/e2e-standalone.sh                              # E2E im Browser (vorher scripts/build-frontend.sh)
```
