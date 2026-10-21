---
titel: Rollen vergeben
reihenfolge: 75
rechte: [promote_users]
---
Wer Rollen vergeben darf ({rolle.admin} oder eine Rolle mit diesem Recht), findet in der App den Bereich **Verwaltung** (unten in der Leiste, wenn es mehrere Bereiche gibt).

## Rolle ändern
Unter **Verwaltung → Mitglieder** die Person öffnen, im Menü unter dem Namen **Konto und Rolle** wählen und unter **Grundrolle** die Rolle auswählen. Welche Rollen es gibt und was sie dürfen, legen die Admins unter **Verwaltung → Einstellungen → Rollen** fest.

## Zusatzrollen
Darunter kreuzt du unter **Zusatzrollen** weitere Rollen an, z. B. „{bereich.points} verwalten“ für ein {rolle.member}. Die Person hat dann die Rechte aller ihrer Rollen zusammen; bei {bereich.points} zählt der kleinste Punktefaktor ihrer Rollen. Zusatzrollen gibt es nur zu einer Grundrolle mit Mitgliederbereich, und die festen Rollen ({rolle.guest}, {rolle.candidate}, {rolle.member}, {rolle.admin}) sind nur Grundrolle.

In der Mitgliederliste stehen die Zusatzrollen neben dem Namen; über **Alle Rollen** filterst du die Liste nach einer Rolle, egal ob Grund- oder Zusatzrolle.

- Die eigene Rolle ändert niemand selbst.
- Der letzte {rolle.admin} kann nicht herabgestuft werden.
- Mit WordPress wird {rolle.admin} nicht in der App vergeben oder entzogen, sondern im WordPress-Backend.
- Wer kein {rolle.admin} ist, vergibt und entfernt (auch als Zusatzrolle) nur Rollen, deren Rechte er selbst hat – nie {rolle.admin} und nie Rollen mit „Mitglieder anlegen“ oder „Rollen vergeben“ – und ändert nur Personen, die keines dieser beiden Rechte und keine weiteren Rechte als er selbst haben.

## Gäste und {rolle.candidate}
Konten ohne Rolle in der App ({rolle.guest}) stehen nicht in der Liste. Mit **Auch Gast-Konten anzeigen** erscheinen sie, und du kannst ihnen eine Rolle geben. {rolle.candidate} und Personen mit Rollen nur für den internen Kalender erscheinen mit **Auch {rolle.candidate} anzeigen**.
