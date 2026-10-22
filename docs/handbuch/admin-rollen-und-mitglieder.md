---
titel: Rollen und Mitglieder
reihenfolge: 120
rechte: [manage_settings]
---
Jede Person hat genau eine **Grundrolle** und dazu beliebig viele **Zusatzrollen**; sie hat dann die Rechte aller ihrer Rollen zusammen. Welche Rollen es gibt und was sie dürfen, legst du unter **Verwaltung → Einstellungen → Rollen** fest. Dort siehst du je Rolle, wie viele Personen sie haben (davon als Zusatzrolle) und welche Rechte sie hat.

## Feste Rollen
- **{rolle.guest}**: sieht nur öffentliche Inhalte.
- **{rolle.member}**: die Rolle neuer Konten, mit dem Mitgliederbereich (eigene Daten, interner Kalender, interne Bilder).
- **{rolle.admin}**: darf alles, auch Einstellungen und Updates.

Diese drei lassen sich umbenennen, aber nicht löschen; ihre Rechte lassen sich nicht ändern. Sie stehen in der Liste immer vorn ({rolle.guest}, {rolle.member}) bzw. ganz hinten ({rolle.admin}).

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
- „{bereich.offices} verwalten“
- „{bereich.lists} verwalten“

Gibt es schon eine Rolle mit diesem Namen, legt die App keine zweite an. Schaltest du den Bereich aus und niemand hat die Rolle, verschwindet sie wieder. {bereich.calendar}, {bereich.news}, {bereich.gallery} und {bereich.birthdays} bringen keine eigene Rolle mit; die Geburtstage anderer pflegt, wer Mitglieder anlegen darf.

## Eigene Rollen
Mit **+ Neue Rolle** legst du weitere Rollen an (zusammen höchstens 30), z. B. eine Leitung, die {bereich.points}, {bereich.penalties} und {bereich.events} verwaltet. Du gibst einen Namen ein (höchstens 30 Zeichen) und wählst die Rechte, z. B. nur den internen Kalender ohne Mitgliederbereich. Jedes andere Recht setzt den Mitgliederbereich voraus: Kreuzt du eines an, hakt die App den Mitgliederbereich mit an; nimmst du den Mitgliederbereich weg, entfallen auch die übrigen Rechte. Falls ihr {bereich.points} nutzt und die Rolle den Mitgliederbereich hat, legst du einen **{bereich.points}faktor** fest (z. B. 0,5 für junge Mitglieder); er gilt für neue Buchungen, alte bleiben, wie sie sind. Den {bereich.points}faktor kannst du auch bei {rolle.member} und {rolle.admin} ändern. {rolle.guest} und Rollen ohne Mitgliederbereich haben keinen, weil sie keine {bereich.points} bekommen.

Unter **Konten** stellst du bei eigenen Rollen zwei Dinge ein:
- **Beim Anlegen neuer Konten wählbar:** Wer Mitglieder anlegt, kann neuen Konten diese Rolle statt {rolle.member} geben. Ohne solche Rollen sind neue Konten immer {rolle.member}.
- **Als {rolle.member} aufnehmen:** Wer Mitglieder anlegen darf, macht Personen mit dieser Rolle mit **Als {rolle.member} aufnehmen** zum {rolle.member}, auch ohne „Rollen vergeben“. Das geht nur bei Rollen ohne Mitgliederbereich.

## Anwärter einrichten
Nehmt ihr Personen vor der Aufnahme schon mit (Anwärter), legst du dafür eine eigene Rolle an, z. B. „Anwärter“: nur mit dem **internen Kalender**, ohne Mitgliederbereich, mit **Beim Anlegen neuer Konten wählbar** und **Als {rolle.member} aufnehmen**. Anwärter sehen dann den internen Kalender und das {bereich.board}, stehen in der Mitgliederliste und in der {bereich.attendance}, bekommen aber keine {bereich.points} oder {bereich.penalties}. Gab es die Rolle schon vor diesem Update als feste Rolle, ist sie jetzt eine eigene Rolle mit genau diesen Einstellungen; du kannst sie ändern oder löschen.

## Reihenfolge
Die eigenen Rollen ordnest du, indem du eine Rolle am Griff links nach oben oder unten ziehst (am Griff auch mit den Pfeiltasten). Die Reihenfolge gilt überall, wo Rollen stehen: in den Auswahllisten, bei den Zusatzrollen und in den Zählern der Mitgliederliste und der {bereich.attendance} (nach {rolle.member}).

## Bearbeiten und löschen
Mit **Bearbeiten** änderst du eine Rolle, mit **Löschen** entfernst du eine eigene Rolle. Haben noch Personen die Rolle, wählst du vorher, welche Rolle sie stattdessen bekommen.

Nutzt ihr {bereich.attendance}, legst du darunter unter **{bereich.attendance}: Gruppen im Zähler** fest, als was jede Rolle in den Anwesenheitslisten zählt.

> Einstellungen, Updates und Datensicherung bleiben immer den {rolle.admin}s vorbehalten. Wer Rollen vergeben darf, ohne {rolle.admin} zu sein, vergibt nur Rollen, deren Rechte er selbst hat – nie {rolle.admin} und nie Rollen mit „Mitglieder anlegen“ oder „Rollen vergeben“ – und ändert nur Personen, die nicht mehr Rechte haben als er.

## Grundrolle und Zusatzrollen
Die Grundrolle sagt, wer jemand in der Kompanie ist: {rolle.guest}, {rolle.member}, {rolle.admin} oder eine eigene Rolle (z. B. Anwärter). Zusatzrollen sind Aufgaben obendrauf, z. B. „{bereich.points} verwalten“ und eine eigene Rolle „Kassenwart“. So brauchst du keine eigene Rolle für jede Kombination.

- Zusatzrollen können nur eigene Rollen und Standardrollen der Bereiche sein, nicht die drei festen Rollen.
- Zusatzrollen gibt es nur zu einer Grundrolle mit Mitgliederbereich; {rolle.admin} braucht keine, er darf ohnehin alles. Bekommt jemand eine Grundrolle ohne Mitgliederbereich (z. B. {rolle.guest} oder eine Rolle nur mit dem internen Kalender), fallen seine Zusatzrollen weg.
- Was nach der Rolle geht und nicht nach den Rechten, richtet sich nur nach der Grundrolle: die Gruppe in den Zählern der Anwesenheit, **Als {rolle.member} aufnehmen** und mit WordPress die Administratoren.
- Was jemand sieht und welche Benachrichtigungen er bekommt, richtet sich nach seinen Rechten, also nach allen Rollen zusammen.
- **Punktefaktor:** Bei mehreren Rollen gilt der kleinste, z. B. ×0,5, wenn eine der Rollen ×0,5 hat.
- Neben jeder Rolle steht, wie viele Personen sie haben, als Grund- oder als Zusatzrolle. Löschst du eine Rolle, die jemand als Zusatzrolle hat, bekommt er stattdessen die gewählte Ersatzrolle als Zusatzrolle, wenn das eine eigene Rolle ist; sonst fällt sie einfach weg.

## Rollen ausprobieren
Was eine Rolle in der App sieht, zeigt dir **Ansehen** neben der Rolle, ohne anderes Konto; siehe [App als Rolle ansehen](seite:admin-vorschau).

## Mitglieder und ihre Rollen
Wie du Mitglieder anlegst, Startpasswörter erzeugst und Rollen vergibst, steht unter [Mitglieder anlegen](seite:mitglieder-anlegen) und [Rollen vergeben](seite:rollen-vergeben). Sieht jemand etwas nicht, hilft [Häufige Probleme](seite:haeufige-probleme).

Braucht nur eine einzelne Person ein Recht mehr oder weniger als ihre Rollen, legst du dafür keine eigene Rolle an: Du gibst oder entziehst es direkt bei der Person (unter „Rollen vergeben“, Abschnitt „Einzelne Rechte geben oder entziehen“).
