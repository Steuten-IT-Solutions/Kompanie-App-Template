---
titel: Installation mit WordPress
reihenfolge: 100
rechte: [manage_settings]
variante: wordpress
---
Die App wird ein Teil der Webseite. Benutzer, Anmeldung und Neuigkeiten kommen aus WordPress. Das Plugin braucht WordPress ab 6.2 und PHP ab 8.1.

1. Auf der Download-Seite der Kompanie das **Plugin** herunterladen (nicht entpacken).
2. In WordPress unter **Plugins → Neues Plugin hinzufügen → Plugin hochladen** die Datei wählen und **Jetzt installieren** klicken.
3. **Aktivieren** klicken. Die App ist dann unter `eure-webseite.de/app/` erreichbar. Als WordPress-Administrator führt sie dich durch die [Ersteinrichtung](seite:admin-einrichtung).

Ohne „schöne“ Permalinks läuft die App unter einer längeren Adresse; WordPress zeigt sie dann oben im WP-Admin an. Für die kurze Adresse `/app/` unter **Einstellungen → Permalinks** z. B. „Beitragsname“ wählen.

Die Einstellungen der App gibt es in der App unter **Verwaltung → Einstellungen** und zusätzlich im WP-Admin unter *Einstellungen* auf der Seite der App (Wartungsmodus, Bereiche, Google-Zugang, {bereich.calendar} und {bereich.gallery}).

> Admin in der App ist, wer in WordPress Administrator ist; diese Rolle wird nur in WordPress vergeben. Die Rolle anderer Personen lässt sich in der App wählen oder im WordPress-Benutzerprofil unter **Rolle in der App** (siehe [Rollen und Mitglieder](seite:admin-rollen-und-mitglieder)).

## Server mit nginx
Auf Apache und LiteSpeed sorgt das Plugin selbst dafür, dass die Dateien der App lange im Browser bleiben und komprimiert ankommen (über `.htaccess`). Läuft die Webseite mit **nginx**, bittet den Hoster, die Dateien unter `wp-content/plugins/kompanie-app/app/assets/` ein Jahr zwischenspeichern zu lassen (`Cache-Control: public, max-age=31536000, immutable`; ihr Name ändert sich mit jeder neuen Version) und Text-Dateien komprimiert auszuliefern (gzip). Die App läuft auch ohne, lädt beim ersten Öffnen aber langsamer.
