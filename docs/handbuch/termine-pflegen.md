---
titel: Öffentliche Termine pflegen
reihenfolge: 60
rechte: [manage_events]
bereiche: [events]
---
Öffentliche Termine sehen alle ohne Anmeldung in der App und (mit WordPress) über den Shortcode `[kompanie_termine]` auch auf der Webseite. Ist der Bereich {bereich.calendar} eingeschaltet, sehen {rolle.member} und Personen mit dem internen Kalender (z. B. Anwärter) unter **{bereich.events}** stattdessen den Kalender der Kompanie mit allen Terminen; tragt öffentliche Termine dann auch dort ein.

## Anlegen
1. Unter **{bereich.events}** auf **Öffentliche Termine verwalten** tippen.
2. **+ Öffentlichen Termin anlegen** tippen.
3. **Titel** und **Datum** sind Pflicht. Freiwillig sind **Bis** (für mehrtägige Termine), **Beginn** und **Ende** (Uhrzeit), **Ort**, **Beschreibung** und ein **Link**. Ohne Uhrzeit gilt der Termin als ganztägig. Ein Ende braucht auch einen Beginn, und der Link muss mit http:// oder https:// beginnen.
4. **Speichern** tippen.

Darunter stehen die kommenden öffentlichen Termine, jeder mit **Bearbeiten** und **Löschen**. Vergangene Termine verschwinden von selbst aus der Liste.

## Benachrichtigungen
Wer ohne Kalender die öffentlichen Termine sieht (z. B. Gäste) und Benachrichtigungen für {bereich.events} eingeschaltet hat, bekommt bei einem neuen Termin „Neuer Termin“. Änderst du später Datum, Uhrzeit oder Ort, kommt „Termin geändert“; Änderungen nur am Titel oder an der Beschreibung lösen keine Benachrichtigung aus. Für vergangene Termine kommt nichts. Mehr dazu unter [Benachrichtigungen](seite:benachrichtigungen).

> Die internen Termine für {rolle.member} kommen nicht von hier, sondern aus dem Kalender der Kompanie (Bereich {bereich.calendar}). So sehen alle die Termine: [{bereich.events} und Kalender](seite:termine-und-kalender).
