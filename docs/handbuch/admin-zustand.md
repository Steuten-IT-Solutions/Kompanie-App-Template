---
titel: Zustand und Updates
reihenfolge: 130
rechte: [manage_settings]
---
Hier siehst du auf einen Blick, ob die App rund läuft, welche Version installiert ist und ob es Updates gibt. Außerdem findest du hier das Fehlerprotokoll und die Statusmeldungen an die Betreuer der App.

![Die Seite Zustand und Updates mit der Ampel](bild:admin-zustand)

## So öffnest du die Seite
Tippe unten auf **Verwaltung**, dann auf **Einstellungen** und auf **Zustand und Updates**.

## Was du hier siehst
### Zustand der App
Eine Ampel mit einer Zeile je Thema. Jede Zeile sagt in einem kurzen Text, was los ist und was zu tun ist.
- **Updates**: ob die App aktuell ist, ob ein Update ansteht oder fehlgeschlagen ist.
- **{bereich.gallery}**: wann der letzte Abgleich mit Google Drive war, ob er geklappt hat und ob Bilder noch aufbereitet werden oder sich nicht laden ließen.
- **Kalender**: ob und wie der Kalender eingerichtet ist und ob die Verbindung mit Google ihn lesen darf.
- **Datensicherung**: wann zuletzt eine Sicherung heruntergeladen bzw. in Google Drive abgelegt wurde.
- **Server**: welche PHP-Version und Datenbank laufen; gelb, wenn auf dem Webspace weniger als 200 MB frei sind.
- **Push-Benachrichtigungen**: wie viele Geräte angemeldet sind, oder was dem Server fehlt, um Benachrichtigungen zu senden.
- Nur ohne WordPress: **Schutz der Daten** (ob Datenbank und Zugangsdaten aus dem Internet abrufbar wären; ist die Zeile rot, lass das sofort beim Hoster beheben), **Verschlüsselte Verbindung** (gelb, wenn die App über `http://` statt `https://` läuft; dann gehen Passwörter und Daten unverschlüsselt durchs Netz) und **Hintergrundaufgaben** (ob ein Cronjob beim Hoster die Aufgaben erledigt, mit der Adresse dafür).

Die Farben: **Grün** heißt alles in Ordnung. **Gelb** heißt, etwas sollte erledigt werden, z. B. eine neue Datensicherung. **Rot** heißt, etwas funktioniert nicht. **Grau** heißt ausgeschaltet, noch nicht eingerichtet oder in dieser Installation nicht verfügbar. Ist bei euch eine Kontaktadresse der Betreuer hinterlegt, steht sie darunter bei **Hilfe**.

### Updates
- **Installiert: …** mit dem Zeitpunkt der letzten Prüfung und dem Verweis **Was ist neu**.
- **✓ Die App ist auf dem neuesten Stand.** oder **Neue Version: …** mit dem Knopf **Jetzt auf … aktualisieren**.
- **Das automatische Update auf … wurde zurückgestellt**: nur, wenn vor einem automatischen Update die Sicherung in Google Drive nicht geklappt hat, mit den Knöpfen **Trotzdem aktualisieren** und **Abbrechen**.
- **Nach Updates suchen** und **Zustand neu laden**.
- **Updates automatisch einspielen (empfohlen)** und **Vorabversionen erhalten (zum Testen)**.
- **Auf eine ältere Version zurück**: zum Aufklappen, wenn es frühere Versionen gibt.
- **Statusmeldungen an die Betreuer der App senden**: nur, wenn eure App Statusmeldungen vorsieht.

### Fehlerprotokoll
Die Fehler der letzten 14 Tage (höchstens 50) mit Zeitpunkt, Art des Fehlers und vollständigem Fehlertext, mit den Knöpfen **Kopieren** und **Leeren**. Gab es keine, steht dort **Keine Fehler in den letzten 14 Tagen.**

![Das Fehlerprotokoll mit zwei Einträgen](bild:admin-zustand-fehlerprotokoll)

## So gehst du vor
### Den aktuellen Stand holen
1. Tippe auf **Zustand neu laden**.

### Eine neue Version einspielen
1. Tippe auf **Nach Updates suchen**. Die App sucht ohnehin einmal am Tag selbst.
2. Gibt es eine neue Version, tippe auf **Jetzt auf … aktualisieren**. Das dauert einige Minuten, mit Sicherung in Google Drive auch länger. Ein Balken zeigt den Schritt (Paket laden, Daten sichern, Sicherung in Google Drive, einspielen), beim Sichern und Hochladen auch die MB; die Größe der Sicherung ist aus der letzten geschätzt („ca.“).
3. Danach lädt die App neu.

Lass die Seite offen, bis die App neu lädt. Bricht die Verbindung zwischendurch ab, läuft das Update auf dem Server weiter und die App wartet darauf. Läuft schon ein Update, wenn du die Seite öffnest (z. B. das automatische), zeigt sie dessen Fortschritt.

Klappt die Sicherung in Google Drive vor dem Update nicht (z. B. weil der Speicher voll ist), fragt die App nach: Bestätigst du, spielt sie das Update trotzdem ein; die Sicherung auf dem Server ist dann da. Brichst du ab, bleibt die bisherige Version.

### Ein zurückgestelltes Update entscheiden
Vor einem automatischen Update kann niemand nachgefragt werden. Klappt dann die Sicherung in Google Drive nicht, spielt die App das Update nicht ein und zeigt unter **Updates** den Grund.
1. Tippe auf **Trotzdem aktualisieren**, um es mit der Sicherung auf dem Server einzuspielen, oder
2. auf **Abbrechen**: Dann versucht es die App beim nächsten täglichen Abgleich wieder, mit Sicherung in Google Drive.

Entscheidest du nichts, versucht es die App ebenfalls am nächsten Tag wieder; klappt es dann, verschwindet der Hinweis.

Kann die neue Version auf eurem Server nicht laufen (z. B. zu alte PHP- oder WordPress-Version, fehlende Schreibrechte), steht der Grund darüber und der Knopf ist gesperrt. Das muss zuerst behoben werden.

### Eine neue Hauptversion einspielen
1. Lies die Hinweise zur Version (unter **Was ist neu** bzw. von den Betreuern der App).
2. Tippe auf **Hauptversion einspielen** und bestätige die Rückfrage.

Eine neue Hauptversion spielt die App nie automatisch ein. Vorher sichert sie die Daten. Zurück auf die bisherige Hauptversion geht es danach nicht per Knopf, nur mit Hilfe der Betreuer.

### Auf eine ältere Version zurückgehen
1. Tippe auf **Auf eine ältere Version zurück**.
2. Wähle bei **Version** eine frühere Version derselben Hauptversion.
3. Tippe auf **Zurückrollen** und bestätige die Rückfrage.

Die Version, von der du kommst, spielt die App danach nicht mehr automatisch ein, erst wieder die nächste neuere.

### Das Fehlerprotokoll an die Betreuer schicken
1. Sieh das Protokoll kurz durch: Fehlertexte können Namen oder Inhalte enthalten.
2. Tippe auf **Kopieren** und füge den Text in eine Nachricht an die Betreuer ein. Klappt das Kopieren nicht, zeigt die App den Text zum Markieren.
3. Ist das Problem behoben, kannst du mit **Leeren** das Protokoll löschen.

## Gut zu wissen
- **Automatische Updates:** Solange **Updates automatisch einspielen (empfohlen)** an ist, spielt die App neue Versionen derselben Hauptversion selbst ein. Sie sucht dafür einmal am Tag und spielt eine passende Version dabei gleich ein; eine feste Uhrzeit gibt es nicht. Ohne Cronjob beim Hoster passiert das nur, wenn jemand die App (mit WordPress: die Webseite) aufruft; wird sie selten aufgerufen, kommen Updates entsprechend später (siehe **Für Fortgeschrittene** unten). Mit WordPress kann die Webseite automatische Updates sperren; dann steht das hier, und du spielst neue Versionen von Hand ein.
- **Vorsorge:** Vor jedem Update (auch beim Zurückrollen) sichert die App die Daten automatisch auf dem Server; die letzten fünf dieser Sicherungen bleiben. Sind die [Sicherungen in Google Drive](seite:admin-updates-und-sicherung) eingeschaltet, kommt dieselbe Sicherung auch dorthin; auch dort bleiben die letzten fünf. Klappt die Sicherung auf dem Server nicht, wird nicht aktualisiert. Klappt nur Google Drive nicht, fragt die App nach (siehe oben). Schlägt ein Update fehl, läuft die bisherige Version weiter und der Grund steht hier.
- **Zurückgezogene Version:** Wird eine fehlerhafte Version zurückgezogen, wechselt die App von selbst auf eine geprüfte Version (außer automatische Updates sind gesperrt); mit **Jetzt auf … wechseln** geht das sofort. Gibt es noch keine geprüfte Version, meldest du dich bei den Betreuern der App.
- **Vorabversionen:** Mit **Vorabversionen erhalten (zum Testen)** bekommt diese Installation jede neue Version, sobald sie gebaut ist, noch bevor sie für alle freigegeben wird. Das ist für eine Testseite gedacht. Schaltest du es aus, bleibt die jetzige Version installiert, bis eine neuere für alle freigegeben ist.
- **Statusmeldungen:** Wenn du zustimmst, schickt die App den Betreuern stündlich ein kurzes Lebenszeichen (u. a. Version, Farbe der Ampel je Zeile, Fehlercodes mit Ursache, z. B. „Kalender: HTTP 404“, und Nutzungszahlen ohne Namen: wie viele Konten es gibt, wie viele davon in den letzten 24 Stunden, 7 und 30 Tagen aktiv waren und wie viele Personen welchen Bereich genutzt haben) und meldet schwere Fehler sofort. Namen, Inhalte und Fehlertexte werden nie gesendet. Was genau gesendet wird, zeigt **Was genau gesendet wird (Beispiel: Lebenszeichen)**; darüber steht, wann die letzte Meldung ging. Nimmst du die Zustimmung zurück, werden auch die gezählten Fehler gelöscht.
- **Fehlerprotokoll:** Es bleibt auf eurem Server und wird nicht gesendet, auch ohne Zustimmung zu den Statusmeldungen. Gleiche Fehler direkt hintereinander stehen nur einmal da, mit Anzahl und Zeitraum. Adressen sind auf den Server gekürzt; so bleibt z. B. eine geheime Kalender-Adresse geheim.
- Bekommt eure Installation keine Updates über die App, steht im Abschnitt **Updates** nur die installierte Version.

> **Für Fortgeschrittene:** Ein *Cronjob* ist ein Auftrag im Kundenmenü eures Hosters, der eine Adresse regelmäßig aufruft. Ohne ihn erledigt die App ihre Hintergrundaufgaben (Abgleich von {bereich.gallery} und Kalender, Updates) nach Seitenaufrufen. Zuverlässiger ist ein Cronjob, der alle 15 Minuten die Adresse aufruft, die bei **Hintergrundaufgaben** steht. Mit WordPress gibt es diese Zeile nicht; dort ersetzt der Cronjob WP-Cron, wie in der Anleitung zur Installation mit WordPress beschrieben.

Was du bei typischen Meldungen tun kannst, steht unter [Häufige Probleme](seite:haeufige-probleme).

## Siehe auch
- [Was ist neu](seite:admin-neuerungen)
- [Datensicherung](seite:admin-updates-und-sicherung)
