---
titel: "{bereich.attendance} erfassen"
reihenfolge: 59
rechte: [manage_attendance]
bereiche: [attendance]
---
Wer die Rolle „{bereich.attendance} verwalten“ oder eine Rolle mit diesem Recht hat, führt unter **Verwaltung → {bereich.attendance}** je Termin eine Liste, wer **anwesend** war, wer **entschuldigt** war und wer **fehlt**.

## Liste anlegen
- **Termin aus dem Kalender:** Ist der Kalender eingerichtet, stehen dort die Termine der letzten 90 Tage. Ein Tipp öffnet die Liste zu diesem Termin; gibt es sie schon, öffnet sich die vorhandene.
- **Freier Termin:** Für alles, was nicht im Kalender steht, z. B. einen spontanen Arbeitsdienst: Titel und Datum eintragen und **Liste anlegen** tippen.

Titel und Datum werden mit der Liste gespeichert. Sie bleibt also erhalten, auch wenn der Termin später im Kalender verschoben oder gelöscht wird.

## Eintragen
In der Liste stehen alle mit einer Rolle (ohne Gäste), auch {rolle.candidate}. Je Person ein Häkchen setzen; ein zweiter Tipp auf dasselbe Häkchen entfernt es wieder. **Alle anwesend** setzt alle gefundenen auf anwesend. Danach **Speichern** tippen. Oben steht, wie viele anwesend, entschuldigt und fehlend sind, aufgeteilt nach Gruppen (siehe unten).

## {bereich.points} und {bereich.penalties} aus der Liste
Darfst du zusätzlich {bereich.points} bzw. {bereich.penalties} vergeben, schlägt die Liste vor:
- **Punkte für Anwesende:** öffnet das Formular für {bereich.points} mit allen Anwesenden, die für diese Liste noch keine {bereich.points} haben. Wer schon welche hat, ist in der Liste mit „Punkte gebucht“ markiert und bekommt sie kein zweites Mal. Der Punktefaktor der Rolle gilt wie immer.
- **Eintrag für Fehlende:** öffnet das Formular für {bereich.penalties} mit allen, die gefehlt haben.

{bereich.points} und {bereich.penalties} gibt es nur für Mitglieder, nicht für {rolle.candidate}. Die Auswahl lässt sich vor dem Buchen noch ändern.

## Gruppen im Zähler
Welche Rollen im Zähler gesondert erscheinen, stellt ein Admin unter **Einstellungen → Rollen → {bereich.attendance}: Gruppen** ein. Jede Rolle zählt dort als eigene Gruppe oder als eine andere Rolle, z. B. „Punkte verwalten“ als {rolle.member}. Ohne Einstellung zählen alle Rollen mit Mitgliederbereich als {rolle.member}, die übrigen (z. B. {rolle.candidate}) gesondert.
