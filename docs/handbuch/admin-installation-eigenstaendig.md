---
titel: Installation ohne WordPress
reihenfolge: 100
rechte: [manage_settings]
variante: standalone
---
So bringst du die App auf euren eigenen Webspace, wenn eure Webseite nicht mit WordPress läuft. Die App bekommt dann eine eigene Adresse, z. B. `eure-webseite.de/app/`, und eigene Konten.

## Was du brauchst
- **Webspace mit PHP ab Version 8.1.** Webspace ist der Speicherplatz bei eurem Hoster (der Firma, bei der eure Webseite liegt). PHP ist die Programmiersprache, in der die App auf dem Server läuft; fast jeder Webspace hat sie. Die Version lässt sich meist im Kundenmenü des Hosters umstellen.
- **Zugang zum Dateimanager des Hosters** (oder per FTP, einem Programm zum Hochladen von Dateien).
- Eine Datenbank musst du nicht anlegen. Die App speichert ihre Daten dann in einer eigenen Datei auf dem Webspace.

## So gehst du vor
### Die App installieren
1. Lade auf der Download-Seite der Kompanie die Datei `install.php` herunter.
2. Lege im Dateimanager des Hosters einen Ordner `app` an und lade die Datei hinein.
3. Öffne `eure-webseite.de/app/install.php` im Browser. Unter **1. Server prüfen** sagt der Installer, ob alles da ist, und falls nicht, was fehlt.
4. Prüfe unter **2. Einrichten** die **Adresse der App**.
5. Trage unter **Erster Admin** **Name**, **Benutzername**, **E-Mail (optional)** und ein **Passwort (mindestens 10 Zeichen)** ein und wiederhole das Passwort.
6. Lass bei der Datenbank **Automatisch (SQLite-Datei, empfohlen)** stehen. Nur wenn ihr eine MySQL- oder MariaDB-Datenbank nutzen wollt, die ihr im Kundenmenü des Hosters angelegt habt, wählst du sie und trägst ihre Zugangsdaten ein.
7. Klicke auf **App installieren**. Der Installer lädt die App, richtet sie ein und löscht sich danach selbst. Klappt das Löschen nicht, sagt er es; dann löschst du `install.php` im Dateimanager.
8. Melde dich mit dem eben angelegten Konto an. Die App führt dich durch den [Einrichtungsassistenten](seite:admin-einrichtung).

### Hintergrundaufgaben zuverlässig machen (empfohlen)
Die App erledigt Aufgaben im Hintergrund, z. B. den Abgleich von {bereich.gallery} und Kalender und die Updates. Das klappt auch ohne dein Zutun: Nach einem Besuch der App erledigt sie fällige Aufgaben, ohne den Besucher warten zu lassen. Wird die App selten aufgerufen, bleiben die Aufgaben aber liegen.

Zuverlässiger geht es mit einem **Cronjob**: einem Auftrag im Kundenmenü des Hosters, der eine Adresse regelmäßig aufruft.
1. Kopiere die Cron-Adresse. Der Installer zeigt sie am Ende; sie steht auch unter **Einstellungen → Zustand und Updates** bei **Hintergrundaufgaben**.
2. Lege im Kundenmenü des Hosters einen Cronjob an, der diese Adresse alle 15 Minuten aufruft.
3. Prüfe nach einer Weile unter **Hintergrundaufgaben**, ob dort steht, dass ein Cronjob die Aufgaben erledigt (siehe [Zustand und Updates](seite:admin-zustand)).

> **Für Fortgeschrittene:** Der Schlüssel in der Cron-Adresse (`key=…`) landet im Zugriffsprotokoll des Webservers. Kann der Cronjob einen Header senden, ist das besser: `curl -s -H "X-Kompanie-Cron-Key: <Schlüssel>" "<Cron-Adresse ohne &key=…>"`. Der Schlüssel stößt nur fällige Hintergrundaufgaben an.

### Die App reparieren oder neu einspielen
Lade `install.php` erneut in den Ordner `app` und öffne es im Browser. Nach der Anmeldung eines {rolle.admin} bietet der Installer **Aktualisieren / Reparieren** an: Die aktuelle Version wird neu eingespielt, die Daten bleiben erhalten.

## Gut zu wissen
- Läuft auf dem Webspace schon WordPress, richtet der Installer nichts ein und empfiehlt stattdessen das Plugin.
- Updates spielt die App danach selbst ein (siehe [Zustand und Updates](seite:admin-zustand)).
- Die Daten der App müssen vor Zugriffen aus dem Internet geschützt sein. Die App prüft das selbst und zeigt das Ergebnis unter **Zustand und Updates** bei **Schutz der Daten**.
- Die App sollte nur über `https://` laufen, sonst gehen Passwörter und Daten der Mitglieder unverschlüsselt durchs Netz. Ein Zertifikat richtet der Hoster ein, oft kostenlos. Liegt die App im Hauptverzeichnis der Adresse (z. B. `https://app.example.org/`), sagt sie dem Browser über HTTPS, die Adresse künftig nur noch verschlüsselt zu öffnen (HSTS); wer das nicht will, trägt in `config.php` `'hsts' => false` ein. In einem Unterordner (z. B. `https://example.org/app/`) gälte das auch für die übrigen Seiten unter derselben Adresse; dort schickt die App es nur mit `'hsts' => true`.

> **Für Fortgeschrittene: Server mit nginx.** Die meisten Hoster nutzen den Webserver Apache oder LiteSpeed; dann richtet die App alles selbst ein (über die Dateien `.htaccess`). Läuft euer Webspace mit **nginx**, wertet der Server diese Dateien nicht aus. Die App läuft trotzdem, aber bittet den Hoster, die Ordner `data`, `src`, `core` und `bin` im App-Ordner für Zugriffe von außen zu sperren (der Installer prüft das und legt die Daten sonst außerhalb des Web-Verzeichnisses ab). Damit die App schneller lädt, sollten außerdem Dateien unter `app/assets/` ein Jahr im Browser zwischengespeichert werden (`Cache-Control: public, max-age=31536000, immutable`; ihr Name ändert sich mit jeder Version), Symbole und Manifest unter `app/` einen Tag, und Textdateien (HTML, CSS, JavaScript, JSON, SVG, Manifest) komprimiert (gzip) ausgeliefert werden.

## Siehe auch
- [Einrichtungsassistent](seite:admin-einrichtung)
- [Zustand und Updates](seite:admin-zustand)
