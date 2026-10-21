---
titel: Zustand und Statusmeldungen
reihenfolge: 140
rechte: [manage_settings]
---
Unter **Verwaltung → Einstellungen → Zustand und Updates** zeigt die Karte **Zustand der App** mit einer Ampel, ob alles läuft. Jede Zeile hat einen kurzen Text, der sagt, was los ist und was zu tun ist. Mit **Zustand neu laden** holst du den aktuellen Stand.

## Die Zeilen
- **Updates**: ob die App aktuell ist, ob ein Update ansteht oder fehlgeschlagen ist. Mehr unter [Updates und Datensicherung](seite:admin-updates-und-sicherung).
- **{bereich.gallery}**: wann der letzte Abgleich mit Google Drive war, ob er geklappt hat und ob Bilder noch aufbereitet werden oder sich nicht laden ließen.
- **{bereich.calendar}**: ob und wie der Kalender eingerichtet ist und ob die Verbindung mit Google ihn lesen darf.
- **Datensicherung**: wann zuletzt eine Sicherung heruntergeladen wurde.
- **Server**: PHP-Version und Datenbank; gelb, wenn auf dem Webspace weniger als 200 MB frei sind.
- **Push-Benachrichtigungen**: wie viele Geräte angemeldet sind, oder was dem Server fehlt, um Benachrichtigungen zu senden.

Ohne WordPress kommen zwei Zeilen dazu:
- **Schutz der Daten**: ob die Datenbank und die Zugangsdaten aus dem Internet abrufbar wären. Ist diese Zeile rot, lass das sofort beim Hoster beheben.
- **Hintergrundaufgaben**: ob ein Cronjob beim Hoster die Aufgaben erledigt (Abgleich von {bereich.gallery} und {bereich.calendar}, Updates) oder ob sie nur nach Seitenaufrufen laufen. Hier steht auch die Adresse für den Cronjob.

## Die Farben
- **Grün**: alles in Ordnung.
- **Gelb** (in der Farbe eurer App): etwas sollte erledigt werden, z. B. eine neue Datensicherung.
- **Rot**: etwas funktioniert nicht; der Text sagt, was zu tun ist.
- **Grau**: ausgeschaltet, noch nicht eingerichtet oder in dieser Installation nicht verfügbar.

Was du bei typischen Meldungen tun kannst, steht unter [Häufige Probleme](seite:haeufige-probleme). Ist bei euch eine Kontaktadresse der Betreuer hinterlegt, steht sie unter der Ampel bei **Hilfe**.

## Statusmeldungen an die Betreuer
Wenn du zustimmst, schickt die App den Betreuern der App stündlich ein kurzes Lebenszeichen (u. a. Version, Farbe der Ampel je Zeile, Fehlercodes der letzten 24 Stunden) und meldet schwere Fehler sofort, z. B. ein gescheitertes Update. Namen, Inhalte und Fehlertexte werden nie gesendet. So sehen die Betreuer Probleme früh.

Du stimmst bei der Einrichtung zu oder später unter **Zustand und Updates** mit dem Schalter **Statusmeldungen an die Betreuer der App senden**. Dort siehst du unter **Was genau gesendet wird**, welche Angaben das Lebenszeichen enthält, und wann die letzte Meldung ging. Die Zustimmung lässt sich jederzeit zurücknehmen; dann werden auch die gezählten Fehler gelöscht. Fehlt der Schalter, sind Statusmeldungen in eurer App nicht vorgesehen.
