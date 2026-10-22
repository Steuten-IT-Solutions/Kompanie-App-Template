---
titel: "{bereich.attendance} erfassen"
reihenfolge: 59
rechte: [manage_attendance]
bereiche: [attendance]
---
Wer die Rolle „{bereich.attendance} verwalten“ oder eine Rolle mit diesem Recht hat, führt unter **Verwaltung → {bereich.attendance}** (Menü oben) je Termin eine Liste, wer **anwesend** war, wer **entschuldigt** war und wer **fehlt**.

## Liste anlegen
- **Termin aus dem Kalender:** Ist {bereich.calendar} eingeschaltet und eingerichtet, stehen dort die Termine der letzten 90 Tage, die jüngsten zuerst (weitere mit **Ältere Termine anzeigen**). Ein Tipp öffnet die Liste zu diesem Termin; gibt es sie schon („Liste vorhanden“), öffnet sich die vorhandene.
- **Freier Termin:** Für alles, was nicht im Kalender steht, z. B. einen spontanen Arbeitsdienst: Titel und Datum eintragen und **Liste anlegen** tippen. Bei einem freien Termin lassen sich Titel und Datum später in der Liste noch ändern.

Titel und Datum werden mit der Liste gespeichert. Sie bleibt also erhalten, auch wenn der Termin später im Kalender verschoben oder gelöscht wird. Unter **Letzte Listen** findest du die bisherigen Listen wieder.

## Eintragen
In der Liste stehen alle mit einer Rolle (ohne Gäste), auch Personen mit dem internen Kalender (z. B. Anwärter). Je Person ein Häkchen setzen; ein zweiter Tipp auf dasselbe Häkchen entfernt es wieder. **Alle anwesend** setzt alle gefundenen (bei einer Suche nur diese) auf anwesend. Danach **Speichern** tippen. Oben steht, wie viele anwesend, entschuldigt und fehlend sind; nach dem Speichern auch aufgeteilt nach Gruppen (siehe unten).

Eine Liste, die nicht mehr gebraucht wird, entfernst du unten mit **Liste löschen**. Schon gebuchte {bereich.points} bleiben dabei erhalten.

## {bereich.points} und {bereich.penalties} aus der Liste
Darfst du zusätzlich {bereich.points} bzw. {bereich.penalties} vergeben, stehen unter der Liste zwei Knöpfe. Ungespeicherte Änderungen werden dabei zuerst gespeichert.
- **{bereich.points} für … Anwesende:** öffnet das Formular für {bereich.points} mit allen Anwesenden, die für diese Liste noch keine {bereich.points} haben; Grund und Datum sind aus der Liste übernommen. Wer schon welche hat, ist in der Liste mit „{bereich.points} gebucht“ markiert und bekommt sie kein zweites Mal. Der {bereich.points}faktor der Rolle gilt wie immer.
- **Eintrag für … Fehlende:** öffnet das Formular für {bereich.penalties} mit allen, die gefehlt haben (Grund „Gefehlt: …“). Anders als bei {bereich.points} merkt sich die Liste hier nicht, wer schon einen Eintrag hat; buche also nicht zweimal.

{bereich.points} und {bereich.penalties} gibt es nur für Mitglieder, nicht für Rollen ohne Mitgliederbereich (z. B. Anwärter). Die Auswahl lässt sich vor dem Buchen noch ändern.

## Gruppen im Zähler
Welche Rollen im Zähler gesondert erscheinen, stellt ein {rolle.admin} unter **Verwaltung → Einstellungen → Rollen** im Abschnitt **{bereich.attendance}: Gruppen im Zähler** ein. Jede Rolle zählt dort als eigene Gruppe oder als eine andere Rolle, z. B. „{bereich.points} verwalten“ als {rolle.member}. Ohne Einstellung zählen alle Rollen mit Mitgliederbereich als {rolle.member}, die übrigen (z. B. Anwärter) gesondert. Im Zähler steht {rolle.member} zuerst, dann die Rollen, die gesondert zählen, in der Reihenfolge unter **Einstellungen → Rollen**.
