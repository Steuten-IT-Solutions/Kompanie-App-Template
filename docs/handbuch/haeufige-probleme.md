---
titel: Häufige Probleme
reihenfolge: 150
rechte: [manage_settings]
---
Ein erster Blick lohnt sich immer unter **Verwaltung → Einstellungen → Zustand und Updates**: Die Ampel zeigt, wo etwas hakt, und der Text daneben sagt meist schon, was zu tun ist (siehe [Zustand und Statusmeldungen](seite:admin-zustand)). Wo welche Einstellung liegt, zeigt [Einstellungen im Überblick](seite:admin-einstellungen).

## Ein Bereich fehlt in der App
Unter **Einstellungen → Bereiche** prüfen, ob er eingeschaltet ist. {bereich.calendar}, {bereich.gallery} und {bereich.news} erscheinen erst, wenn sie auch eingerichtet sind; dann steht dort „Noch nicht eingerichtet“ mit **Jetzt einrichten**. {bereich.polls} lassen sich nur zusammen mit dem {bereich.board} einschalten.

## Jemand sieht etwas nicht, das er sehen sollte
Unter **Verwaltung → Mitglieder** die Person öffnen und unter **Konto und Rolle** ihre Rolle prüfen, dann unter **Einstellungen → Rollen**, welche Rechte diese Rolle hat. Rechte wirken nur in eingeschalteten Bereichen. Eine Rolle nur mit dem internen Kalender (z. B. Anwärter) sieht zum Beispiel den internen Kalender, aber nicht den Mitgliederbereich. Mehr dazu unter [Rollen und Mitglieder](seite:admin-rollen-und-mitglieder).

## Jemand hat sein Passwort vergessen
Im Mitglied unter **Konto und Rolle** ein neues Startpasswort erzeugen, siehe [Mitglieder anlegen](seite:mitglieder-anlegen).

## Fotos erscheinen grau
Neue Bilder bereitet die App beim ersten Abruf und im Hintergrund auf. Bei vielen neuen Bildern zeigt sie für einige kurz einen grauen Platzhalter und lädt sie dann von selbst nach. Ohne WordPress geht das zügiger mit einem Cronjob beim Hoster (die Adresse steht in der Zeile **Hintergrundaufgaben** der Ampel); mit WordPress hilft es, wenn die Webseite regelmäßig aufgerufen wird.

## Die {bereich.gallery} gleicht nicht mehr ab
Unter **Verwaltung → Einstellungen → {bereich.gallery}** steht der Fehler des letzten Abgleichs; mit **Jetzt abgleichen** versuchst du es sofort. Häufige Ursachen:
- Der Ordner in Google Drive ist für das verbundene Google-Konto bzw. das Dienstkonto nicht mehr sichtbar, z. B. weil die Freigabe entfernt wurde.
- Die Verbindung zu Google ist abgelaufen oder wurde widerrufen. Dann unter **Einstellungen → Google-Zugang** auf **Verbindung trennen** tippen und neu mit Google verbinden. Läuft sie immer wieder nach 7 Tagen ab, ist das Google-Projekt noch im Status „Testen“, siehe [Google einrichten](seite:admin-google).

## Der {bereich.calendar} zeigt nichts an
Unter **Einstellungen → Kalender** mit **Kalender testen** prüfen, ob er erreichbar ist. Meldet die Ampel, dass die Verbindung mit Google den Kalender nicht lesen darf: unter **Einstellungen → Google-Zugang** die Verbindung trennen und neu verbinden, dabei auch das Häkchen für den Kalender setzen.

## Benachrichtigungen kommen nicht an
Die Zeile **Push-Benachrichtigungen** der Ampel zeigt, ob der Server Benachrichtigungen senden kann. Fehlt etwas, kann das nur der Hoster nachrüsten. Liegt es am Gerät (abgelehnte Nachfrage, iPhone ohne installierte App), hilft [Benachrichtigungen](seite:benachrichtigungen).

## Updates kommen nicht an
Unter **Verwaltung → Einstellungen → Zustand und Updates** steht unter **Updates**, was zuletzt passiert ist und warum eine neue Version nicht eingespielt werden kann. Häufig:
- **Automatische Updates sind gesperrt** (mit WordPress z. B. in den Einstellungen der Webseite): Neue Versionen hier von Hand einspielen.
- **WordPress fragt nach FTP-Zugangsdaten**: WordPress darf die Plugin-Dateien nicht selbst schreiben. Der Hoster muss die Dateirechte anpassen, oder du lädst das Update von Hand hoch.
- **Zu alte PHP- oder WordPress-Version**: PHP lässt sich meist im Kundenmenü des Hosters umstellen; WordPress zuerst aktualisieren.
- **Ohne WordPress darf PHP die Programmdateien nicht ändern**: beim Hoster die Dateirechte prüfen oder `install.php` erneut hochladen und **Aktualisieren / Reparieren** wählen.
- **Zu wenig Speicherplatz**: Die Zeile **Server** wird gelb, wenn weniger als 200 MB frei sind. Platz schaffen oder das Paket beim Hoster erweitern.

Mehr dazu unter [Updates und Datensicherung](seite:admin-updates-und-sicherung).

## Hintergrundaufgaben laufen nicht (ohne WordPress)
Ohne Cronjob laufen Abgleiche und Updates nur, wenn jemand die App aufruft. Ist die Zeile **Hintergrundaufgaben** gelb oder rot, richte beim Hoster einen Cronjob ein, der alle 15 Minuten die dort genannte Adresse aufruft.

## Die Zeile „Schutz der Daten“ ist rot (ohne WordPress)
Dann sind Dateien mit Daten aus dem Internet abrufbar, weil der Webserver die Sperre nicht auswertet. Lass sofort beim Hoster das Verzeichnis `data` sperren oder lege die Daten außerhalb des Web-Verzeichnisses ab.

## Nichts davon hilft
Ist unter der Ampel bei **Hilfe** eine Kontaktadresse hinterlegt, melde dich dort. Haben die Statusmeldungen eure Zustimmung, sehen die Betreuer schwere Fehler oft schon von selbst.
