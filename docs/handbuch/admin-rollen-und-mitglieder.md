---
titel: Rollen und Mitglieder
reihenfolge: 120
rechte: [manage_settings]
---
Jede Person hat genau eine **Grundrolle** und dazu beliebig viele **Zusatzrollen**; sie hat dann die Rechte aller ihrer Rollen zusammen. Die Rollen legst du unter **Konto → Einstellungen → Rollen** fest.

## Feste Rollen
- **{rolle.guest}**: sieht nur öffentliche Inhalte.
- **{rolle.candidate}**: vor der Aufnahme; sieht den internen Kalender, aber nicht den Mitgliederbereich. Wer Mitglieder anlegen darf, nimmt {rolle.candidate} als {rolle.member} auf.
- **{rolle.member}**: die Rolle neuer Konten, mit dem Mitgliederbereich (eigene Daten, interne Termine und Bilder).
- **{rolle.admin}**: darf alles, auch Einstellungen und Updates.

Diese vier lassen sich umbenennen, aber nicht löschen.

## Standardrollen der Bereiche
Schaltest du einen Bereich mit eigenen Aufgaben ein, kommt seine Rolle dazu: „{bereich.events} verwalten“, „{bereich.points} verwalten“, „{bereich.penalties} verwalten“. Schaltest du ihn aus und niemand hat die Rolle, verschwindet sie wieder.

## Eigene Rollen
Mit **+ Neue Rolle** legst du weitere Rollen an, z. B. eine Leitung, die {bereich.points}, {bereich.penalties} und {bereich.events} verwaltet. Für jede Rolle wählst du die Rechte (z. B. nur den internen Kalender, ohne Mitgliederbereich) und, falls ihr {bereich.points} nutzt, einen **Punktefaktor** (z. B. 0,5 für junge Mitglieder).

> Einstellungen, Updates und Datensicherung bleiben immer den Admins vorbehalten. Wer Rollen vergeben darf, ohne Admin zu sein, vergibt nur Rollen, deren Rechte er selbst hat – nie {rolle.admin} und nie Rollen mit „Mitglieder anlegen“ oder „Rollen vergeben“ – und ändert nur Personen, die nicht mehr Rechte haben als er.

## Grundrolle und Zusatzrollen
Die Grundrolle sagt, wer jemand in der Kompanie ist: {rolle.guest}, {rolle.candidate}, {rolle.member}, {rolle.admin} oder eine eigene Rolle. Zusatzrollen sind Aufgaben obendrauf, z. B. „{bereich.points} verwalten“ und eine eigene Rolle „Kassenwart“. So brauchst du keine eigene Rolle für jede Kombination.

- Zusatzrollen können nur eigene Rollen und Standardrollen der Bereiche sein, nicht die vier festen Rollen.
- Zusatzrollen gibt es nur zu einer Grundrolle mit Mitgliederbereich; {rolle.admin} braucht keine, er darf ohnehin alles. Bekommt jemand eine Grundrolle ohne Mitgliederbereich (z. B. {rolle.guest} oder {rolle.candidate}), fallen seine Zusatzrollen weg.
- Was nach der Rolle geht und nicht nach den Rechten, richtet sich nur nach der Grundrolle: die Gruppe in den Zählern der Anwesenheit, **Als {rolle.member} aufnehmen** und mit WordPress die Administratoren.
- Was jemand sieht und welche Benachrichtigungen er bekommt, richtet sich nach seinen Rechten, also nach allen Rollen zusammen.
- **Punktefaktor:** Bei mehreren Rollen gilt der kleinste, z. B. ×0,5, wenn eine der Rollen ×0,5 hat.
- Neben jeder Rolle steht, wie viele Personen sie haben, als Grund- oder als Zusatzrolle. Löschst du eine Rolle, die jemand als Zusatzrolle hat, bekommt er stattdessen die gewählte Ersatzrolle als Zusatzrolle, wenn das eine eigene Rolle ist; sonst fällt sie einfach weg.

## Mitglieder und ihre Rollen
Wie du Mitglieder anlegst, Startpasswörter erzeugst und Rollen vergibst, steht unter [Mitglieder anlegen](seite:mitglieder-anlegen) und [Rollen vergeben](seite:rollen-vergeben).
