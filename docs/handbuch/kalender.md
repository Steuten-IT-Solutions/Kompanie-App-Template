---
titel: "{bereich.events} mit Kalender"
reihenfolge: 31
rechte: [calendar]
bereiche: [calendar]
---
Bist du angemeldet, siehst du unter **{bereich.events}** alle kommenden Termine aus dem Kalender der {kompanie.name}, auch die internen, nach Monaten geordnet. Du kannst einen Termin in deinen eigenen Kalender übernehmen.

![Termine aus dem Kalender der Kompanie, oben die anstehenden Geburtstage](bild:kalender)

## So öffnest du die Seite
Tippe unten auf **{bereich.events}**. Meist ist das auch die Startseite der App.

## Was du hier siehst
- **Anstehende {bereich.birthdays}** (oben, nur für {rolle.member} und nur, wenn die {kompanie.name} {bereich.birthdays} nutzt): wer heute oder in den nächsten Tagen Geburtstag hat (wie viele Tage vorher, legt ein {rolle.admin} fest; anfangs 7).
- **Internen Kalender verwalten**: nur, wenn die {kompanie.name} ihren Kalender in der App pflegt und du das darfst.
- **Öffentliche Termine verwalten**: nur, wenn du öffentliche Termine pflegen darfst.
- **Monatsüberschrift**: z. B. „Oktober 2026“; darunter die Termine dieses Monats.
- **Termin**: links das Datum, rechts Titel, Wochentag und Uhrzeit und, falls angegeben, der Ort. Liegt der Termin nicht im laufenden Jahr, steht auch das Jahr dabei. Steht keine Uhrzeit dabei, dauert der Termin den ganzen Tag.

## So gehst du vor
### Einen Termin ansehen
1. Tippe auf den Termin. Er klappt auf und zeigt die Beschreibung, falls es eine gibt.
2. Tippe auf **In meinen Kalender**, um den Termin in deinen eigenen Kalender zu übernehmen: Die App lädt eine Kalenderdatei herunter. Öffne sie, und dein Handy oder Computer bietet die Übernahme an.
3. Ist ein Ort angegeben, zeigt **Karte** ihn in der Karten-App: Auf Android wählst du die App aus (z. B. Google Maps oder Waze), auf iPhone und Mac öffnet sich Apple Karten, am PC Google Maps.
4. Tippe noch einmal auf den Termin, um ihn wieder zuzuklappen.

![Aufgeklappter Termin mit Beschreibung und dem Knopf „In meinen Kalender“](bild:kalender-termin)

## Gut zu wissen
- Die Termine kommen aus dem internen Kalender der {kompanie.name}, bis etwa ein Jahr im Voraus. Den pflegt die {kompanie.name} direkt in der App oder z. B. in einem Google Kalender. Termine, die in einem solchen Kalender als privat markiert oder abgesagt sind, erscheinen nicht.
- Kommt der Kalender von außen (z. B. aus Google Kalender), erscheinen neue oder geänderte Termine nicht sofort, sondern nach kurzer Zeit. In der App gepflegte Termine erscheinen sofort.
- Die öffentlichen Termine, die Gäste ohne Anmeldung sehen, stehen hier nicht zusätzlich. Du siehst sie nur, wenn sie auch im internen Kalender der {kompanie.name} eingetragen sind.
- Diese Ansicht haben angemeldete {rolle.member} und Personen mit Zugang zum internen Kalender (z. B. Anwärter). Gäste ohne Anmeldung sehen unter **{bereich.events}** nur die öffentlichen Termine.
- Läuft die App über eine WordPress-Webseite, zeigt der Shortcode `[kompanie_kalender]` diese Termine auch auf einer Seite der Webseite, aber nur für angemeldete Personen mit Zugang zum internen Kalender.
- Über neue und geänderte Termine und mit einer Erinnerung am Vortag kann dich die App benachrichtigen, siehe [Benachrichtigungen](seite:benachrichtigungen).

## Siehe auch
- [Benachrichtigungen](seite:benachrichtigungen)
- [Die App im Überblick](seite:app-ueberblick)
