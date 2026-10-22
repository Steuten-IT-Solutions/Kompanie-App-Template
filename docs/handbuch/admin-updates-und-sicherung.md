---
titel: Updates und Datensicherung
reihenfolge: 130
rechte: [manage_settings]
---
## Updates
Unter **Verwaltung → Einstellungen → Zustand und Updates** siehst du die installierte Version und wann zuletzt nach Updates gesucht wurde. Die App sucht einmal am Tag selbst; mit **Nach Updates suchen** geht es sofort.

Neue Versionen spielt die App automatisch ein, solange **Updates automatisch einspielen (empfohlen)** eingeschaltet ist. Ausnahmen:
- Eine neue Hauptversion spielt die App nie automatisch ein. Lies vorher die Hinweise zur Version und tippe dann auf **Hauptversion einspielen**.
- Mit WordPress kann die Webseite automatische Updates sperren. Dann zeigt die App das an, und du spielst neue Versionen hier von Hand ein.

Gibt es eine neue Version, tippst du auf **Jetzt auf … aktualisieren**, um sie sofort einzuspielen (das dauert bis zu zwei Minuten). Danach lädt die App neu. Kann die Version auf eurem Server nicht laufen (z. B. zu alte PHP- oder WordPress-Version, fehlende Schreibrechte), steht der Grund darüber; dann muss das zuerst behoben werden.

Schlägt ein Update fehl, läuft die bisherige Version weiter, und der Grund steht unter **Updates**. Vor Updates mit Änderungen an der Datenbank und vor einer neuen Hauptversion sichert die App die Daten automatisch auf dem Server (die letzten fünf Sicherungen bleiben).

Wird eine fehlerhafte Version zurückgezogen, wechselt die App von selbst auf eine geprüfte Version (außer automatische Updates sind gesperrt); mit **Jetzt auf … wechseln** geht das sofort. Gibt es noch keine geprüfte Version, meldest du dich bei den Betreuern der App.

### Was ist neu
Unter **Verwaltung → Einstellungen → Was ist neu** (oder über den Link neben der installierten Version) steht, was sich von Version zu Version geändert hat, bis zur installierten Version. Mit **Vorabversionen erhalten** steht ganz oben, was in der Vorabversion schon dabei ist, aber noch nicht für alle freigegeben wurde.

### Auf eine ältere Version zurück
Klappt nach einem Update etwas nicht mehr, öffnest du **Auf eine ältere Version zurück**, wählst eine frühere Version derselben Hauptversion und tippst auf **Zurückrollen**. Die Version, von der du kommst, spielt die App danach nicht mehr automatisch ein, erst wieder die nächste neuere. Hinter eine neue Hauptversion zurück geht es nicht per Klick, sondern nur mit Hilfe der Betreuer der App.

### Vorabversionen
Mit **Vorabversionen erhalten (zum Testen)** bekommt diese Installation jede neue Version, sobald sie gebaut ist – noch bevor sie für alle freigegeben wird. Beim Einschalten fragt die App nach. Das ist für eine Testseite gedacht: Vorabversionen können noch Fehler enthalten. Schaltest du es wieder aus, bleibt die jetzige Version installiert, bis eine neuere für alle freigegeben ist.

## Datensicherung
Unter **Verwaltung → Einstellungen → Datensicherung** tippst du auf **Sicherung herunterladen**. Die Datei enthält alle App-Daten, die Einstellungen, die Zugangsschlüssel und die Konten (mit WordPress alle Benutzer der Webseite, mit Passwort als Prüfwert). Dazu kommen die Dateien der {bereich.documents}, die Abzeichen der {bereich.ranks} und die Anmeldungen der Geräte für Benachrichtigungen. Behandle sie wie ein Passwort und bewahre sie an einem sicheren Ort auf, nicht nur auf dem Server: Die automatischen Sicherungen vor Updates liegen auf demselben Server und sind mit ihm verloren. Die Ampel unter **Zustand und Updates** wird gelb, wenn 30 Tage lang keine Sicherung heruntergeladen oder in Google Drive abgelegt wurde.

### Sicherungen in Google Drive
Darunter schaltest du **Wöchentlich in Google Drive sichern** ein. Die App legt dann im verbundenen Google-Konto einen Ordner „Sicherungen …“ an, lädt einmal pro Woche eine Sicherung hoch und behält die letzten acht; ältere löscht sie. **Jetzt in Google Drive sichern** lädt sofort eine hoch, **Ordner öffnen** zeigt den Ordner in Google Drive. Scheitert das Hochladen (z. B. weil der Speicher in Google Drive voll ist), steht der Grund dort und in der Ampel; die App versucht es am nächsten Tag wieder.
- Dafür braucht die App eine Verbindung über **„Mit Google verbinden“** (siehe [Google einrichten](seite:admin-google)), bei der du das Häkchen für **eigene Dateien in Google Drive** gesetzt hast. Wurde die Verbindung vor diesem Update hergestellt, trenne sie unter **Google-Zugang** und verbinde neu. Mit einem Dienstkonto geht es nicht: Es hat in Google Drive keinen eigenen Speicherplatz.
- Die App sieht und ändert in Google Drive nur Dateien, die sie selbst angelegt hat.
- Die Sicherungen enthalten Zugangsschlüssel und Passwort-Prüfwerte. Gib den Ordner deshalb nicht frei.
- Zum Wiederherstellen lädst du die neueste Sicherung aus dem Ordner herunter und spielst sie wie unten ein.

### Wiederherstellen
Mit **Sicherung wiederherstellen …** wählst du eine Sicherungsdatei. Nach einer Rückfrage ersetzt sie **alle** Daten der App; rückgängig machen lässt sich das nur mit einer anderen Sicherung.
- Mit WordPress geht das nur auf derselben Webseite (Adresse), z. B. nach einem missglückten Update oder auf einer neu installierten WordPress-Seite, wenn die alte verloren ist. Fehlende Konten legt die App dabei mit ihrer bisherigen ID wieder an; alle melden sich mit ihrem bisherigen Benutzernamen und Passwort an. Vorhandene Konten behalten Anmeldedaten und E-Mail und bekommen nur ihre Rolle in der App aus der Sicherung. Konten anlegen darf nur ein WordPress-Administrator.
- Ohne WordPress auch auf einer neuen Installation (z. B. beim Umzug), aber nicht aus einer Sicherung des WordPress-Plugins. Danach melden sich alle neu an.
- Eine Sicherung aus einer neueren Version lässt sich erst einspielen, wenn die App aktualisiert ist.

### Nach dem Verlust der Webseite (WordPress)
1. WordPress unter derselben Adresse neu installieren. Den Administrator dabei mit dem **Benutzernamen und der E-Mail deines bisherigen Kontos** anlegen (oder mit einem Namen und einer E-Mail, die es bisher nicht gab). Gehört die neue Konto-Nummer in der Sicherung jemand anderem, bricht das Wiederherstellen ab und nennt das Konto; geändert wird dann nichts.
2. Das Plugin der Kompanie installieren (Download-Seite der Kompanie) und die App öffnen.
3. Unter **Verwaltung → Einstellungen → Datensicherung** die neueste Sicherung einspielen (heruntergeladen oder aus dem Ordner in Google Drive).
4. Beiträge der Webseite (Neuigkeiten), Seiten und andere Plugins gehören zu WordPress selbst und sind nicht in der Sicherung der App; dafür braucht es eine Sicherung von WordPress bzw. vom Webhoster.

### Was nicht in der Sicherung ist
Die Fotos der {bereich.gallery} sind nicht in der Sicherung; die App holt sie danach neu aus Google Drive (bei vielen Fotos dauert das einige Stunden). Mit WordPress gehören außerdem Beiträge, Seiten, Design und andere Plugins zu WordPress selbst (siehe oben). Wartende Fehlermeldungen und der Zustand dieser Installation (z. B. Update-Stand, Fehlerprotokoll) sind ebenfalls nicht enthalten.

Ältere Sicherungen (vor dieser Version) enthalten noch keine Dateien der {bereich.documents}, keine Abzeichen und mit WordPress keine Konten. Lade nach dem Update deshalb einmal eine neue Sicherung herunter.

Was die Ampel bedeutet, steht unter [Zustand und Statusmeldungen](seite:admin-zustand).
