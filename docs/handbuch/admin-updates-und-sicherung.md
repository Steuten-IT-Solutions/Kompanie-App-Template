---
titel: Updates und Datensicherung
reihenfolge: 130
rechte: [manage_settings]
---
## Updates
Unter **Konto → Einstellungen → Zustand und Updates** siehst du die installierte Version und wann zuletzt nach Updates gesucht wurde. Die App sucht einmal am Tag selbst; mit **Nach Updates suchen** geht es sofort.

Neue Versionen spielt die App automatisch ein, solange **Updates automatisch einspielen (empfohlen)** eingeschaltet ist. Ausnahmen:
- Eine neue Hauptversion spielt die App nie automatisch ein. Lies vorher die Hinweise zur Version und tippe dann auf **Hauptversion einspielen**.
- Mit WordPress kann die Webseite automatische Updates sperren. Dann zeigt die App das an, und du spielst neue Versionen hier von Hand ein.

Gibt es eine neue Version, tippst du auf **Jetzt auf … aktualisieren**, um sie sofort einzuspielen (das dauert bis zu zwei Minuten). Danach lädt die App neu. Kann die Version auf eurem Server nicht laufen (z. B. zu alte PHP- oder WordPress-Version, fehlende Schreibrechte), steht der Grund darüber; dann muss das zuerst behoben werden.

Schlägt ein Update fehl, läuft die bisherige Version weiter, und der Grund steht unter **Updates**. Vor Updates mit Änderungen an der Datenbank und vor einer neuen Hauptversion sichert die App die Daten automatisch auf dem Server (die letzten fünf Sicherungen bleiben).

Wird eine fehlerhafte Version zurückgezogen, wechselt die App von selbst auf eine geprüfte Version (außer automatische Updates sind gesperrt); mit **Jetzt auf … wechseln** geht das sofort. Gibt es noch keine geprüfte Version, meldest du dich bei den Betreuern der App.

### Auf eine ältere Version zurück
Klappt nach einem Update etwas nicht mehr, öffnest du **Auf eine ältere Version zurück**, wählst eine frühere Version derselben Hauptversion und tippst auf **Zurückrollen**. Die Version, von der du kommst, spielt die App danach nicht mehr automatisch ein, erst wieder die nächste neuere. Hinter eine neue Hauptversion zurück geht es nicht per Klick, sondern nur mit Hilfe der Betreuer der App.

## Datensicherung
Unter **Konto → Einstellungen → Datensicherung** tippst du auf **Sicherung herunterladen**. Die Datei enthält alle App-Daten, die Einstellungen und Zugangsschlüssel, ohne WordPress auch die Konten. Behandle sie wie ein Passwort und bewahre sie an einem sicheren Ort auf. Die Ampel unter **Zustand und Updates** wird gelb, wenn noch nie eine Sicherung heruntergeladen wurde oder die letzte älter als 90 Tage ist.

### Wiederherstellen
Mit **Sicherung wiederherstellen …** wählst du eine Sicherungsdatei. Nach einer Rückfrage ersetzt sie **alle** Daten der App; rückgängig machen lässt sich das nur mit einer anderen Sicherung.
- Mit WordPress geht das nur auf derselben Webseite, z. B. nach einem missglückten Update.
- Ohne WordPress auch auf einer neuen Installation (z. B. beim Umzug), aber nicht aus einer Sicherung des WordPress-Plugins. Danach melden sich alle neu an.
- Eine Sicherung aus einer neueren Version lässt sich erst einspielen, wenn die App aktualisiert ist.

### Was nicht in der Sicherung ist
Die Fotos der {bereich.gallery} sind nicht in der Sicherung; die App holt sie danach neu aus Google Drive. Auch die Dateien der {bereich.documents} und die Abzeichen der {bereich.ranks} sind nicht in der Sicherung, nur ihre Angaben (Titel, Kategorie, Stand bzw. welcher {begriff.rank} welches Abzeichen hat). Sie bleiben auf dem Server. Beim Umzug ohne WordPress nimm die Ordner `data/files/dokumente` und `data/files/abzeichen` mit (bzw. das Datenverzeichnis, falls es woanders liegt). Sonst lassen sich die Dokumente danach nicht öffnen und müssen neu hochgeladen werden; fehlende Abzeichen zeigt die App nicht an, bis du sie neu hochlädst.

Was die Ampel bedeutet, steht unter [Zustand und Statusmeldungen](seite:admin-zustand).
