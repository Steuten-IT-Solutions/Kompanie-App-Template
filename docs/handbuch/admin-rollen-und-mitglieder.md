---
titel: Rollen und Mitglieder
reihenfolge: 120
rechte: [manage_settings]
---
Jede Person hat genau eine Rolle. Welche Rollen es gibt und was sie dürfen, legst du unter **Konto → Einstellungen → Rollen** fest. Dort siehst du je Rolle, wie viele Personen sie haben und welche Rechte sie hat.

## Feste Rollen
- **{rolle.guest}**: sieht nur öffentliche Inhalte.
- **{rolle.candidate}**: vor der Aufnahme; sieht den internen Kalender, aber nicht den Mitgliederbereich. Wer Mitglieder anlegen darf, nimmt {rolle.candidate} als {rolle.member} auf.
- **{rolle.member}**: die Rolle neuer Konten, mit dem Mitgliederbereich (eigene Daten, interner Kalender, interne Bilder).
- **{rolle.admin}**: darf alles, auch Einstellungen und Updates.

Diese vier lassen sich umbenennen, aber nicht löschen; ihre Rechte lassen sich nicht ändern.

Mit WordPress sind die Administratoren der Webseite die {rolle.admin}s der App. Diese Rolle vergibst du im WordPress-Backend, nicht in der App. Rechte, die jemand in WordPress hat (z. B. Benutzer anlegen), gelten auch in der App.

## Standardrollen der Bereiche
Schaltest du unter **Bereiche** einen Bereich mit eigenen Aufgaben ein, kommt seine Rolle dazu. Sie heißt wie der Bereich mit „verwalten“ dahinter und hat den Mitgliederbereich und die Rechte des Bereichs:

- „{bereich.events} verwalten“
- „{bereich.points} verwalten“
- „{bereich.penalties} verwalten“
- „{bereich.ranks} verwalten“
- „{bereich.titles} verwalten“
- „{bereich.attendance} verwalten“
- „{bereich.documents} verwalten“
- „{bereich.board} verwalten“
- „{bereich.polls} verwalten“
- „{bereich.inventory} verwalten“

Gibt es schon eine Rolle mit diesem Namen, legt die App keine zweite an. Schaltest du den Bereich aus und niemand hat die Rolle, verschwindet sie wieder. {bereich.calendar}, {bereich.news}, {bereich.gallery} und {bereich.birthdays} bringen keine eigene Rolle mit; die Geburtstage anderer pflegt, wer Mitglieder anlegen darf.

## Eigene Rollen
Mit **+ Neue Rolle** legst du weitere Rollen an (zusammen höchstens 30), z. B. eine Leitung, die {bereich.points}, {bereich.penalties} und {bereich.events} verwaltet. Du gibst einen Namen ein (höchstens 30 Zeichen) und wählst die Rechte, z. B. nur den internen Kalender ohne Mitgliederbereich. Falls ihr {bereich.points} nutzt und die Rolle den Mitgliederbereich hat, legst du einen **{bereich.points}faktor** fest (z. B. 0,5 für junge Mitglieder); er gilt für neue Buchungen, alte bleiben, wie sie sind. Den {bereich.points}faktor kannst du auch bei {rolle.member} ändern. {rolle.guest}, {rolle.candidate} und Rollen ohne Mitgliederbereich haben keinen, weil sie keine {bereich.points} bekommen; {rolle.admin}s zählen immer voll.

Mit **Bearbeiten** änderst du eine Rolle, mit **Löschen** entfernst du eine eigene Rolle. Haben noch Personen die Rolle, wählst du vorher, welche Rolle sie stattdessen bekommen.

Nutzt ihr {bereich.attendance}, legst du darunter unter **{bereich.attendance}: Gruppen im Zähler** fest, als was jede Rolle in den Anwesenheitslisten zählt.

> Einstellungen, Updates und Datensicherung bleiben immer den {rolle.admin}s vorbehalten. Wer Rollen vergeben darf, ohne {rolle.admin} zu sein, vergibt nur Rollen, deren Rechte er selbst hat – nie {rolle.admin} und nie Rollen mit „Mitglieder anlegen“ oder „Rollen vergeben“ – und ändert nur Personen, die nicht mehr Rechte haben als er.

## Mitglieder und ihre Rollen
Wie du Mitglieder anlegst, Startpasswörter erzeugst und Rollen vergibst, steht unter [Mitglieder anlegen](seite:mitglieder-anlegen) und [Rollen vergeben](seite:rollen-vergeben). Sieht jemand etwas nicht, hilft [Häufige Probleme](seite:haeufige-probleme).
