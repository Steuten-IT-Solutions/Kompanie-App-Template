---
titel: Installation mit WordPress
reihenfolge: 100
rechte: [manage_settings]
variante: wordpress
---
Läuft eure Webseite mit WordPress, installierst du die App als Plugin, also als Erweiterung von WordPress. Die App wird dann ein Teil der Webseite: Benutzer, Anmeldung und {bereich.news} kommen aus WordPress.

## Was du brauchst
- WordPress ab Version 6.2 und PHP ab Version 8.1. PHP ist die Programmiersprache, in der WordPress und die App auf dem Server laufen; die Version lässt sich meist im Kundenmenü des Hosters umstellen.
- Ein Konto als Administrator der WordPress-Webseite.

## So gehst du vor
### Das Plugin installieren
1. Lade auf der Download-Seite der Kompanie das **Plugin** herunter. Entpacke die Datei nicht.
2. Öffne in WordPress **Plugins → Neues Plugin hinzufügen → Plugin hochladen**, wähle die Datei und klicke auf **Jetzt installieren**.
3. Klicke auf **Aktivieren**. Die App ist dann unter `eure-webseite.de/app/` erreichbar.
4. Öffne die App. Als Administrator führt sie dich durch den [Einrichtungsassistenten](seite:admin-einrichtung).

### Inhalte der App auf der Webseite zeigen
Auf Seiten und in Beiträgen von WordPress zeigen diese Kurzbefehle (Shortcodes) Inhalte der App. Du schreibst sie einfach in den Text der Seite:
- `[kompanie_termine]`: die öffentlichen {bereich.events}
- `[kompanie_galerie]`: die {bereich.gallery}
- `[kompanie_aemter]`: die {bereich.offices}
- `[kompanie_mitglieder]`: die {bereich.directory} als Bildergalerie

### Ein Platzhalterbild für Mitglieder ohne Foto wählen
In `[kompanie_mitglieder]` stehen bei Personen ohne Foto ihre Anfangsbuchstaben. Stattdessen kann dort ein eigenes Bild stehen, z. B. eine Silhouette oder das Logo.
1. Öffne im WordPress-Backend unter *Einstellungen* die Seite der App und dort den Abschnitt **Webseite: Mitglieder ohne Foto**.
2. Klicke auf **Bild aus der Mediathek wählen**, lade ein Bild hoch oder wähle eines aus und übernimm es.
3. Es gilt sofort. Am besten passt ein Bild im Hochformat 2 : 3; andere Formate werden mittig zugeschnitten.

**Platzhalterbild entfernen** bringt die Anfangsbuchstaben zurück, ebenso das Löschen des Bilds aus der Mediathek. In der App selbst bleiben die Anfangsbuchstaben.

## Gut zu wissen
- **Einstellungen:** Die Einstellungen der App findest du in der App unter **Verwaltung → Einstellungen**. Zusätzlich gibt es im WordPress-Backend unter *Einstellungen* eine Seite der App mit Wartungsmodus, Bereichen, Google-Zugang, {bereich.calendar}, {bereich.gallery} und dem Platzhalterbild. Sie hilft, wenn die App selbst nicht erreichbar ist.
- **{rolle.admin}s:** {rolle.admin} in der App ist, wer in WordPress Administrator ist. Diese Rolle vergibst du nur in WordPress. Die Rolle anderer Personen wählst du in der App oder im WordPress-Benutzerprofil unter **Rolle in der App** (siehe [Rollen](seite:admin-rollen-und-mitglieder)).
- **Adresse:** Ohne „schöne“ Permalinks (die lesbaren Adressen von WordPress) läuft die App unter einer längeren Adresse; WordPress zeigt sie dann oben im Backend an. Für die kurze Adresse `/app/` wählst du unter **Einstellungen → Permalinks** z. B. „Beitragsname“.
- **{bereich.news}:** Sie kommen immer aus den Beiträgen der Webseite; dafür gibt es nichts einzustellen.

> **Für Fortgeschrittene: Server mit nginx.** Auf den Webservern Apache und LiteSpeed sorgt das Plugin selbst dafür, dass die Dateien der App lange im Browser bleiben und komprimiert ankommen (über `.htaccess`). Läuft die Webseite mit **nginx**, bitte den Hoster, die Dateien unter `wp-content/plugins/kompanie-app/app/assets/` ein Jahr zwischenspeichern zu lassen (`Cache-Control: public, max-age=31536000, immutable`; ihr Name ändert sich mit jeder Version) und Textdateien komprimiert (gzip) auszuliefern. Die App läuft auch ohne, lädt beim ersten Öffnen aber langsamer.

> **Für Fortgeschrittene: Hintergrundaufgaben (WP-Cron).** Die Abgleiche von {bereich.calendar} und {bereich.gallery}, Benachrichtigungen und die tägliche Suche nach Updates erledigt die App über WP-Cron. WP-Cron läuft nur, wenn jemand die Webseite oder die App aufruft; auf wenig besuchten Webseiten kommen diese Aufgaben deshalb verspätet, Updates z. B. erst nach dem nächsten Besuch. Zuverlässiger ist ein Cronjob beim Hoster: Lege im Kundenmenü des Hosters zuerst einen Cronjob an, der alle 15 Minuten `https://eure-webseite.de/wp-cron.php` aufruft. Trage erst danach in der `wp-config.php` `define('DISABLE_WP_CRON', true);` ein; ohne den Cronjob laufen die Aufgaben sonst gar nicht mehr. Manche Hoster haben dafür eine eigene Einstellung im Kundenmenü.

## Siehe auch
- [Einrichtungsassistent](seite:admin-einrichtung)
- [Einstellungen](seite:admin-einstellungen)
