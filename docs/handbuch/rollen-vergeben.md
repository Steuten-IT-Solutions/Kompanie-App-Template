---
titel: Rollen vergeben
reihenfolge: 75
rechte: [promote_users]
---
Wer Rollen vergeben darf ({rolle.admin} oder eine Rolle mit diesem Recht), findet in der App den Bereich **Verwaltung** (unten in der Leiste, wenn es mehrere Bereiche gibt). Dort öffnet sich zuerst die **Mitgliederliste**.

## Die Mitgliederliste
Das Suchfeld **Mitglied suchen …** filtert die Liste nach dem Namen. Ein Tipp auf eine Person öffnet ihre Seite; unter dem Namen wählst du im Menü die Unterseite (**Übersicht**, **Konto und Rolle** und je nach deinen Rechten weitere). **‹ Alle Mitglieder** führt zurück.

## Rolle ändern
Unter **Verwaltung → Mitglieder** die Person öffnen, im Menü unter dem Namen **Konto und Rolle** wählen und unter **Grundrolle** die Rolle auswählen. Die Änderung gilt sofort. Welche Rollen es gibt und was sie dürfen, legen die Admins unter **Verwaltung → Einstellungen → Rollen** fest.

## Zusatzrollen
Darunter kreuzt du unter **Zusatzrollen** weitere Rollen an, z. B. „{bereich.points} verwalten“ für ein {rolle.member}. Die Person hat dann die Rechte aller ihrer Rollen zusammen; bei {bereich.points} zählt der kleinste Punktefaktor ihrer Rollen. Zusatzrollen gibt es nur zu einer Grundrolle mit Mitgliederbereich, und die festen Rollen ({rolle.guest}, {rolle.candidate}, {rolle.member}, {rolle.admin}) sind nur Grundrolle.

Die Mitgliederliste zeigt die Rollen nicht an; unter **Filter und Sortierung** filterst du über **Alle Rollen** die Liste nach einer Rolle, egal ob Grund- oder Zusatzrolle. {rolle.guest} kannst du dort auch wählen, ohne vorher das Häkchen darunter zu setzen.

- Die eigene Rolle ändert niemand selbst.
- Der letzte {rolle.admin} kann nicht herabgestuft werden.
- Wer jemanden zum {rolle.admin} macht, muss das bestätigen: {rolle.admin}s dürfen alles, auch Einstellungen ändern.
- Mit WordPress wird {rolle.admin} nicht in der App vergeben oder entzogen, sondern im WordPress-Backend.
- Wer kein {rolle.admin} ist, vergibt und entfernt (auch als Zusatzrolle) nur Rollen, deren Rechte er selbst hat – nie {rolle.admin} und nie Rollen mit „Mitglieder anlegen“ oder „Rollen vergeben“ – und ändert nur Personen, die keines dieser beiden Rechte und keine weiteren Rechte als er selbst haben.

## Einzelne Rechte geben oder entziehen
Soll eine Person ein Recht bekommen, das keine ihrer Rollen hat, oder ein Recht ihrer Rollen nicht haben, brauchst du dafür keine eigene Rolle. Unter **Konto und Rolle** stehen unter **Rechte** alle Rechte der Person. Setze oder entferne die Haken und tippe auf **Rechte speichern**. Was von ihren Rollen (Grundrolle und Zusatzrollen) abweicht, ist mit „zusätzlich“ oder „entzogen“ markiert; in der Übersicht der Person steht dann „mit Ausnahmen bei den Rechten“. **Wie die Rolle** entfernt alle Ausnahmen.

- Die Ausnahmen bleiben bestehen, wenn sich die Grundrolle oder die Zusatzrollen ändern. Was die Rollen dann ohnehin regeln, fällt weg: ein zusätzlich gegebenes Recht, das die Rollen jetzt schon haben, und ein entzogenes Recht, das sie nicht mehr haben.
- Rechte zum Verwalten brauchen den Mitgliederbereich. Der interne Kalender gehört zum Mitgliederbereich und lässt sich nur mit ihm entziehen.
- Die Rolle {rolle.admin} hat immer alle Rechte, die Rolle {rolle.guest} keine: Für sie gibt es keine Ausnahmen. Bekommt jemand eine dieser beiden Rollen als Grundrolle, verfallen seine Ausnahmen.
- Einstellungen, Updates und Datensicherung bleiben der Rolle {rolle.admin} vorbehalten.
- Wer kein {rolle.admin} ist, ändert nur Rechte, die er selbst hat, nie „Mitglieder anlegen“ oder „Rollen vergeben“, und nur bei Personen, die er auch sonst verwalten darf. Die eigenen Rechte ändert niemand selbst.

## Gäste und {rolle.candidate}
Konten ohne Rolle in der App ({rolle.guest}) stehen nicht in der Liste. Mit dem Häkchen **Auch Gast-Konten anzeigen (um ihnen eine Rolle zu geben)** erscheinen sie, und du kannst ihnen eine Rolle geben. {rolle.candidate} und Personen mit Rollen nur für den internen Kalender stehen immer in der Liste.

Neue Konten anlegen, Passwörter zurücksetzen und {rolle.candidate} mit **Als {rolle.member} aufnehmen** aufnehmen darf nur, wer Mitglieder anlegen darf.
