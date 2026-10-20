---
titel: Installation mit WordPress
reihenfolge: 100
rechte: [manage_settings]
variante: wordpress
---
Die App wird ein Teil der Webseite. Benutzer, Anmeldung und Neuigkeiten kommen aus WordPress.

1. Auf der Download-Seite der Kompanie das **Plugin** herunterladen (nicht entpacken).
2. In WordPress unter **Plugins → Neues Plugin hinzufügen → Plugin hochladen** die Datei wählen, installieren und **Aktivieren**.
3. Die App ist dann unter `eure-webseite.de/app/` erreichbar. Als WordPress-Administrator führt sie dich durch die [Ersteinrichtung](seite:admin-einrichtung).

> Admin in der App ist, wer in WordPress Administrator ist. Die Rolle in der App lässt sich auch im WordPress-Benutzerprofil wählen.

## Server mit nginx
Auf Apache und LiteSpeed sorgt das Plugin selbst dafür, dass die Dateien der App lange im Browser bleiben und komprimiert ankommen (über `.htaccess`). Läuft die Webseite mit **nginx**, bittet den Hoster, die Dateien unter `wp-content/plugins/kompanie-app/app/assets/` ein Jahr zwischenspeichern zu lassen (`Cache-Control: public, max-age=31536000, immutable`; ihr Name ändert sich mit jeder neuen Version) und Text-Dateien komprimiert auszuliefern (gzip). Die App läuft auch ohne, lädt beim ersten Öffnen aber langsamer.
