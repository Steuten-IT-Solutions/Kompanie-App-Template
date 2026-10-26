---
titel: "Anwesenheitsliste „…“"
reihenfolge: 60
rechte: [manage_attendance]
bereiche: [attendance]
---
In einer Anwesenheitsliste trägst du für einen Termin ein, wer **anwesend** war, wer **entschuldigt** war und wer **fehlt**. Darfst du auch {bereich.points} oder {bereich.penalties} vergeben, buchst du sie von hier aus für alle Anwesenden bzw. Fehlenden.

![Eine Anwesenheitsliste mit Zählern und Häkchen](bild:anwesenheitsliste)

## So öffnest du die Seite
Tippe unten auf **Verwaltung**, dann auf **{bereich.attendance}**. Tippe dort auf einen Termin oder unter **Letzte Listen** auf eine Liste.

## Was du hier siehst
- **‹ {bereich.attendance}**: führt zurück zur Übersicht.
- **Titel und Datum**: Bei einem Termin aus dem {bereich.calendar} stehen sie fest. Bei einem freien Termin stehen sie in Feldern, die du ändern kannst.
- **Zähler**: z. B. „3 anwesend · 0 entschuldigt · 1 fehlt“. Ist alles gespeichert, steht darunter die Aufteilung nach Gruppen, z. B. „anwesend: Mitglieder 3“.
- **Person suchen …**: zeigt nur Personen, deren Name passt.
- **Alle anwesend**: setzt alle gezeigten Personen auf anwesend (bei einer Suche nur die gefundenen).
- **Die Liste**: alle Personen mit einer Rolle, außer {rolle.guest}. Je Person gibt es ein Kästchen für **anwesend**, **entschuldigt** und **fehlt**. Neben dem Namen steht die Rolle, wenn sie nicht {rolle.member} ist. **{bereich.points} gebucht** heißt: Diese Person hat für diese Liste schon {bereich.points} bekommen.
- **Speichern**: speichert deine Änderungen. Ist alles gespeichert, steht dort **Gespeichert**.
- **{bereich.points} für … Anwesende** und **Eintrag für … Fehlende**: nur, wenn du {bereich.points} bzw. {bereich.penalties} vergeben darfst.
- **Liste löschen**: ganz unten.

## So gehst du vor
### Eintragen
1. Tippe bei jeder Person auf das passende Kästchen. Tippst du ein gesetztes Häkchen noch einmal an, ist es wieder weg.
2. Waren fast alle da, tippe zuerst auf **Alle anwesend** und ändere dann nur die Ausnahmen.
3. Tippe auf **Speichern**.

![Häkchen setzen in der Liste](bild:anwesenheitsliste-eintragen)

### {bereich.points} für die Anwesenden buchen
1. Tippe auf **{bereich.points} für … Anwesende**. Ungespeicherte Änderungen speichert die App vorher.
2. Das Formular für {bereich.points} öffnet sich. Ausgewählt sind alle anwesenden {rolle.member}, die für diese Liste noch keine {bereich.points} haben. Grund und Datum kommen aus der Liste.
3. Prüfe die Auswahl, wähle die {bereich.points} und buche sie wie sonst unter **Verwaltung** → **{bereich.points} +**. Mit **‹** und dem Titel der Liste oben kommst du zurück.

![Die Knöpfe zum Buchen unter der Liste](bild:anwesenheitsliste-buchen)

### Einen Eintrag für die Fehlenden anlegen
1. Tippe auf **Eintrag für … Fehlende**. Ungespeicherte Änderungen speichert die App vorher.
2. Das Formular für {bereich.penalties} öffnet sich mit allen {rolle.member}, die gefehlt haben. Als Grund steht „Gefehlt:“ und der Titel der Liste.
3. Prüfe die Auswahl und trage den Eintrag ein wie sonst unter **Verwaltung** → **{bereich.penalties} +**.

### Eine Liste löschen
1. Tippe ganz unten auf **Liste löschen**.
2. Bestätige die Frage.

## Gut zu wissen
- In der Liste stehen auch Personen, deren Rolle nicht zur Kompanie gehört (z. B. Anwärter). {bereich.points} und {bereich.penalties} bekommen aber nur Personen, die zur Kompanie gehören.
- Wer für diese Liste schon {bereich.points} hat, bekommt sie kein zweites Mal. Haben alle Anwesenden schon welche, steht dort „{bereich.points} für alle Anwesenden bereits gebucht.“
- Bei {bereich.penalties} merkt sich die Liste nicht, wer schon einen Eintrag hat. Lege ihn also nicht zweimal an.
- Löschst du eine Liste, bleiben die schon gebuchten {bereich.points} erhalten.
- Unten steht, wer die Liste zuletzt gespeichert hat.
- Was im Zähler als eigene Gruppe erscheint, legt ein {rolle.admin} unter **Einstellungen** → **Rollen** im Abschnitt **{bereich.attendance}: Gruppen im Zähler** fest. Ohne Einstellung zählen alle Rollen, die zur Kompanie gehören, als {rolle.member}, die übrigen (z. B. Anwärter) je für sich.
- Jede Person sieht ihre eigenen Einträge unter **Meins** → **{bereich.attendance}**.

## Siehe auch
- [{bereich.attendance} erfassen](seite:anwesenheit-erfassen)
