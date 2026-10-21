---
titel: Rollen vergeben
reihenfolge: 75
rechte: [promote_users]
---
Wer Rollen vergeben darf ({rolle.admin} oder eine Rolle mit diesem Recht), findet in der App den Bereich **Verwaltung** (unten in der Leiste, wenn es mehrere Bereiche gibt).

## Rolle ändern
Unter **Verwaltung → Mitglieder** die Person öffnen, im Menü unter dem Namen **Konto und Rolle** wählen und unter **Rolle in der App** die Rolle auswählen. Welche Rollen es gibt und was sie dürfen, legen die Admins unter **Verwaltung → Einstellungen → Rollen** fest.

- Die eigene Rolle ändert niemand selbst.
- Der letzte {rolle.admin} kann nicht herabgestuft werden.
- Mit WordPress wird {rolle.admin} nicht in der App vergeben oder entzogen, sondern im WordPress-Backend.
- Wer kein {rolle.admin} ist, vergibt nur Rollen, deren Rechte er selbst hat – nie {rolle.admin} und nie Rollen mit „Mitglieder anlegen“ oder „Rollen vergeben“ – und ändert nur Personen, die keines dieser beiden Rechte und keine weiteren Rechte als er selbst haben.

## Einzelne Rechte geben oder entziehen
Soll eine Person ein Recht bekommen, das ihre Rolle nicht hat, oder ein Recht der Rolle nicht haben, brauchst du dafür keine eigene Rolle. Unter **Konto und Rolle** stehen unter **Rechte** alle Rechte der Person. Setze oder entferne die Haken und tippe auf **Rechte speichern**. Was von der Rolle abweicht, ist mit „zusätzlich“ oder „entzogen“ markiert; in der Übersicht der Person steht dann „mit Ausnahmen bei den Rechten“. **Wie die Rolle** entfernt alle Ausnahmen.

- Die Ausnahmen bleiben bei einem Rollenwechsel bestehen. Was die neue Rolle ohnehin regelt, fällt weg: Ein zusätzlich gegebenes Recht, das die neue Rolle schon hat, und ein entzogenes Recht, das sie nicht hat.
- Rechte zum Verwalten brauchen den Mitgliederbereich. Der interne Kalender gehört zum Mitgliederbereich und lässt sich nur mit ihm entziehen.
- Die Rolle {rolle.admin} hat immer alle Rechte, die Rolle {rolle.guest} keine: Für sie gibt es keine Ausnahmen. Bekommt jemand eine dieser beiden Rollen, verfallen seine Ausnahmen.
- Einstellungen, Updates und Datensicherung bleiben der Rolle {rolle.admin} vorbehalten.
- Wer kein {rolle.admin} ist, ändert nur Rechte, die er selbst hat, nie „Mitglieder anlegen“ oder „Rollen vergeben“, und nur bei Personen, die er auch sonst verwalten darf. Die eigenen Rechte ändert niemand selbst.

## Gäste und {rolle.candidate}
Konten ohne Rolle in der App ({rolle.guest}) stehen nicht in der Liste. Mit **Auch Gast-Konten anzeigen** erscheinen sie, und du kannst ihnen eine Rolle geben. {rolle.candidate} und Personen mit Rollen nur für den internen Kalender erscheinen mit **Auch {rolle.candidate} anzeigen**.
