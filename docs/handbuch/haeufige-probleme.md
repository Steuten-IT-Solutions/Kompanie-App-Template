---
titel: Häufige Probleme
reihenfolge: 150
rechte: [manage_settings]
---
Hier findest du als {rolle.admin} Lösungen für Probleme, die häufig vorkommen: Zu jedem Problem steht, woran es meist liegt und was du tun kannst. Einen eigenen Bildschirm gibt es dafür nicht.

## Zuerst: die Ampel ansehen
1. Tippe unten auf **Verwaltung** und dann auf **Einstellungen**.
2. Wähle **Zustand und Updates**.
3. Sieh nach, welche Zeile gelb oder rot ist. Der Text daneben sagt meist schon, was zu tun ist.

Mehr dazu unter [Zustand und Updates](seite:admin-zustand). Wo welche Einstellung liegt, zeigt [Einstellungen](seite:admin-einstellungen).

## Ein Bereich fehlt in der App
1. Öffne **Verwaltung** → **Einstellungen** → **Bereiche**.
2. Prüfe, ob der Bereich eingeschaltet ist, und schalte ihn bei Bedarf ein.
3. Steht beim Bereich „Noch nicht eingerichtet“, tippe auf **Jetzt einrichten**. {bereich.calendar}, {bereich.gallery} und {bereich.news} erscheinen erst, wenn sie auch eingerichtet sind.

{bereich.polls} lassen sich nur zusammen mit dem {bereich.board} einschalten.

## Jemand sieht etwas nicht, das er sehen sollte
1. Öffne unter **Verwaltung** → **Mitglieder** die Person.
2. Klappe ganz unten **Konto und Rolle** auf und prüfe ihre Rolle.
3. Prüfe unter **Einstellungen** → **Rollen**, welche Rechte diese Rolle hat.

Rechte wirken nur in eingeschalteten Bereichen. Außerdem sieht jede Rolle nur, wofür sie ein Recht hat: Eine Rolle mit dem internen Kalender, aber ohne **{bereich.documents}** (z. B. Anwärter), sieht den Kalender, aber keine {bereich.documents}. Was welche Rolle sieht, zeigt **Wer sieht was** unter *Einstellungen → Rollen*. Mehr dazu unter [Rollen](seite:admin-rollen-und-mitglieder).

## Jemand hat sein Passwort vergessen
1. Öffne unter **Verwaltung** → **Mitglieder** die Person.
2. Klappe **Konto und Rolle** auf und erzeuge ein neues Startpasswort.
3. Gib es der Person weiter; sie ändert es danach unter **Meins** → **Konto**.

Siehe auch [Mitglied anlegen](seite:mitglieder-anlegen).

## Bilder der {bereich.gallery} erscheinen grau
Neue Bilder bereitet die App beim ersten Abruf und im Hintergrund auf. Bei vielen neuen Bildern zeigt sie für einige kurz einen grauen Platzhalter und lädt sie dann von selbst nach. Meist genügt es zu warten.

> **Für Fortgeschrittene:** Ohne WordPress geht das zügiger mit einem Cronjob beim Hoster; die Adresse steht in der Zeile **Hintergrundaufgaben** der Ampel. Mit WordPress hilft es, wenn die Webseite regelmäßig aufgerufen wird.

## Die {bereich.gallery} gleicht nicht mehr ab
1. Öffne **Verwaltung** → **Einstellungen** → **{bereich.gallery}**. Dort steht der Fehler des letzten Abgleichs.
2. Tippe auf **Jetzt abgleichen**, um es sofort noch einmal zu versuchen.

Klappt es weiter nicht, liegt es meist an einem dieser Gründe:
- Der Ordner in Google Drive ist für das verbundene Google-Konto bzw. das Dienstkonto nicht mehr sichtbar, z. B. weil die Freigabe entfernt wurde. Gib ihn dort wieder frei.
- Die Verbindung zu Google ist abgelaufen oder wurde widerrufen. Tippe unter **Einstellungen** → **Google-Zugang** auf **Verbindung trennen** und verbinde neu mit Google.

Läuft die Verbindung immer wieder nach 7 Tagen ab, ist das Google-Projekt noch im Status „Testen“, siehe [Google-Zugang](seite:admin-google).

## Der Kalender zeigt nichts an
1. Öffne **Verwaltung** → **Einstellungen** → **Kalender**.
2. Tippe auf **Kalender testen**. Die App prüft, ob der Kalender erreichbar ist.
3. Meldet die Ampel, dass die Verbindung mit Google den Kalender nicht lesen darf: Trenne unter **Einstellungen** → **Google-Zugang** die Verbindung und verbinde neu. Setze dabei auch das Häkchen für den Kalender.

## Benachrichtigungen kommen nicht an
- Die Zeile **Push-Benachrichtigungen** der Ampel zeigt, ob der Server Benachrichtigungen senden kann. Fehlt dort etwas, kann das nur der Hoster nachrüsten.
- Liegt es am Gerät (abgelehnte Nachfrage des Browsers, iPhone ohne installierte App), hilft [Benachrichtigungen](seite:benachrichtigungen).

## Updates kommen nicht an
Unter **Verwaltung** → **Einstellungen** → **Zustand und Updates** steht unter **Updates**, was zuletzt passiert ist und warum eine neue Version nicht eingespielt werden kann. Häufige Gründe:
- **Automatische Updates sind gesperrt** (mit WordPress z. B. in den Einstellungen der Webseite): Spiel neue Versionen dort von Hand ein.
- **Zu wenig Speicherplatz**: Die Zeile **Server** wird gelb, wenn weniger als 200 MB frei sind. Schaffe Platz oder erweitere das Paket beim Hoster.
- **Zu alte PHP- oder WordPress-Version**: PHP lässt sich meist im Kundenmenü des Hosters umstellen; WordPress zuerst aktualisieren.

> **Für Fortgeschrittene:** Fragt WordPress nach FTP-Zugangsdaten, darf es die Plugin-Dateien nicht selbst schreiben; der Hoster muss die Dateirechte anpassen, oder du lädst das Update von Hand hoch. Ohne WordPress: Darf PHP die Programmdateien nicht ändern, prüfe beim Hoster die Dateirechte oder lade `install.php` erneut hoch und wähle **Aktualisieren / Reparieren**.

Mehr dazu unter [Datensicherung](seite:admin-updates-und-sicherung).

## Die Zeile „Hintergrundaufgaben“ ist gelb oder rot (ohne WordPress)
Abgleiche und Updates laufen dann nur, wenn jemand die App aufruft.

> **Für Fortgeschrittene:** Richte beim Hoster einen Cronjob ein, der alle 15 Minuten die Adresse aufruft, die in der Zeile **Hintergrundaufgaben** steht.

## Die Zeile „Schutz der Daten“ ist rot (ohne WordPress)
Dann sind Dateien mit Daten aus dem Internet abrufbar. Handle sofort.

> **Für Fortgeschrittene:** Der Webserver wertet die Sperre des Datenverzeichnisses nicht aus. Lass beim Hoster das Verzeichnis `data` sperren oder lege die Daten außerhalb des Web-Verzeichnisses ab.

## Nichts davon hilft
- Ist unter der Ampel bei **Hilfe** eine Kontaktadresse hinterlegt, melde dich dort.
- Oder melde den Fehler direkt aus der App, siehe [Fehler melden](seite:fehler-melden).
- Haben die Statusmeldungen eure Zustimmung, sehen die Betreuer schwere Fehler oft schon von selbst.
