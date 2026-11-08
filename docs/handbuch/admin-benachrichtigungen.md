---
titel: Benachrichtigungen einstellen
reihenfolge: 118
rechte: [manage_settings]
---
Hier siehst du, wie viele Geräte für Benachrichtigungen angemeldet sind, und stellst ein, wann die Erinnerung an Termine kommt. Die Benachrichtigung am Geburtstag stellst du unter **Kalender** im Abschnitt **{bereich.birthdays}** ein (nur, wenn {bereich.birthdays} an ist).

![Die Seite Benachrichtigungen in den Einstellungen](bild:admin-benachrichtigungen)

## So öffnest du die Seite
Tippe unten auf **Verwaltung**, dann auf **Einstellungen** und auf **Benachrichtigungen**.

## Was du hier siehst
- **… Geräte angemeldet**: wie viele Geräte Benachrichtigungen bekommen, davon wie viele mit Konto und wie viele als Gast. Warten noch Nachrichten auf den Versand, steht ihre Zahl dahinter.
- **Erinnerung an Termine am nächsten Tag ab**: die Uhrzeit, ab der die Erinnerung an die Termine des Folgetags verschickt wird (anfangs 18:00 Uhr).
- **Zuletzt verschickt**: für jede Art von Benachrichtigung, wann die App sie zuletzt verschickt hat und an wie viele Geräte. „an kein Gerät“ heißt: Niemand, für den sie gedacht war, hat diese Art auf seinem Gerät eingeschaltet. Darunter steht, wann der Push-Dienst (z. B. von Google oder Apple) zuletzt eine Nachricht angenommen hat und, falls es vorkam, wann er zuletzt eine abgelehnt hat und warum. Der Abschnitt erscheint erst, wenn etwas verschickt wurde.

## So gehst du vor
### Die Uhrzeit der Erinnerung ändern
1. Wähle bei **Erinnerung an Termine am nächsten Tag ab** eine Uhrzeit.
2. Die App speichert sofort und meldet „Gespeichert“.

## Gut zu wissen
- Worüber eine Person benachrichtigt wird, wählt sie selbst auf ihrem Gerät (**Meins → Konto → Benachrichtigungen**, siehe [Benachrichtigungen](seite:benachrichtigungen)). Anfangs sind alle Arten an; neue Arten (neuer Bereich, neue Rolle) kommen von selbst dazu, selbst abgeschaltete bleiben aus.
- Einschalten muss jede Person die Benachrichtigungen auf ihrem Gerät selbst, weil der Browser dafür ihre Zustimmung braucht. Mitglieder ohne Benachrichtigungen sehen dazu auf der Startseite den Hinweis **Nichts mehr verpassen**.
- Kommt eine Benachrichtigung auf einem Gerät nicht an, obwohl sie unter **Zuletzt verschickt** steht, der Push-Dienst sie danach angenommen hat und keine Nachrichten mehr warten, hat der Server seinen Teil erledigt. Dann liegt es meist am Gerät: Prüfe dort, ob die Art eingeschaltet ist und ob das Handy Benachrichtigungen des Browsers zurückhält (z. B. im Energiesparmodus).
- Kann der Server keine Benachrichtigungen senden, steht statt der Zahl der Geräte, was ihm fehlt. Das kann euer Hoster einschalten.

> **Für Fortgeschrittene:** Was fehlt, betrifft die Verschlüsselung in PHP, der Programmiersprache, in der die App auf dem Server läuft (z. B. die PHP-Erweiterung OpenSSL). Der Hoster schaltet sie in den PHP-Einstellungen eures Webspace ein.

## Siehe auch
- [Einstellungen](seite:admin-einstellungen)
