---
titel: Rollen
reihenfolge: 114
rechte: [manage_settings]
---
Hier legst du fest, welche Rollen es gibt und was jede Rolle in der App darf. Wer welche Rolle bekommt, wählst du bei der Person in der Verwaltung (siehe [Konto und Rolle](seite:rollen-vergeben)).

![Die Seite Rollen mit der Liste aller Rollen](bild:admin-rollen-und-mitglieder)

## So öffnest du die Seite
Tippe unten auf **Verwaltung**, dann auf **Einstellungen** und auf **Rollen**.

## Was du hier siehst
Oben schaltest du zwischen **Rollen** (die Liste) und **Wer sieht was** (die Übersicht) um.

- **Liste der Rollen**: je Rolle der Name, wie viele Personen sie haben (davon als Zusatzrolle) und ihre Rechte. Bei eigenen Rollen steht außerdem **für neue Konten** oder **aufnehmen als {rolle.member}**, wenn das eingestellt ist. Weicht der {bereich.points}faktor von 1 ab, steht er als Abzeichen neben dem Namen, z. B. „×0,5“.
- **Griff** links neben jeder Rolle: zum Ändern der Reihenfolge.
- **Ansehen**: zeigt die App so, wie diese Rolle sie sieht (siehe [App als Rolle ansehen](seite:admin-vorschau)). Bei {rolle.admin} fehlt der Knopf.
- **Bearbeiten**: öffnet die Rolle zum Ändern.
- **+ Neue Rolle**: legt eine weitere Rolle an.
- **App ohne Anmeldung ansehen**: zeigt die App, wie Besucher ohne Konto sie sehen.
- **{bereich.attendance}: Gruppen im Zähler** (nur wenn {bereich.attendance} an ist): als was jede Rolle in den Anwesenheitslisten zählt.
- **Wer sieht was**: eine Tabelle mit allen Rechten als Zeilen und allen Rollen als Spalten. So siehst du auf einen Blick, welche Rolle welchen Knopf sieht, und änderst es direkt.

## So gehst du vor
### Eine Rolle anlegen
1. Tippe auf **+ Neue Rolle**.
2. Trage den **Name der Rolle** ein (höchstens 30 Zeichen).
3. Setze unter **Rechte** die Häkchen für alles, was die Rolle sieht und darf. Eine neue Rolle sieht zuerst, was {rolle.member} sieht. Die Rechte stehen in Gruppen; tippe auf den Namen einer Gruppe, um sie auf- oder zuzuklappen. Der Haken vor der Gruppe setzt oder entfernt alle Rechte der Gruppe auf einmal.
4. Stelle bei Bedarf unter **Konten** ein, ob die Rolle **Beim Anlegen neuer Konten wählbar** ist und ob, wer Mitglieder anlegen darf, Personen mit dieser Rolle als {rolle.member} aufnimmt.
5. Nutzt ihr {bereich.points} und gehört die Rolle zur Kompanie, trage den **{bereich.points}faktor** ein (z. B. 0,5 für die Hälfte).
6. Tippe auf **Anlegen**.

![Eine neue Rolle „Anwärter“ mit dem internen Kalender, noch nicht angelegt](bild:admin-rollen-und-mitglieder-neu)

### Eine Rolle ändern oder löschen
1. Tippe bei der Rolle auf **Bearbeiten**.
2. Ändere Name, Rechte, Konten oder {bereich.points}faktor und tippe auf **Speichern**. Mit **Abbrechen** verwirfst du die Änderungen.
3. Zum Löschen einer eigenen Rolle tippst du stattdessen auf **Löschen**. Haben noch Personen die Rolle, wählst du vorher bei **Beim Löschen bekommen die … Personen die Rolle**, welche Rolle sie stattdessen bekommen. Bestätige die Rückfrage.

### In der Übersicht festlegen, wer was sieht
1. Tippe oben auf **Wer sieht was**.
2. Klappe eine Gruppe auf, indem du auf ihren Namen tippst, z. B. **Intern**.
3. Setze oder entferne in der Spalte einer Rolle die Haken, z. B. bei **{bereich.ranks}** für {rolle.member}. Der Haken in der Zeile der Gruppe setzt alle Rechte der Gruppe für diese Rolle.
4. Tippe auf **Speichern**. Mit **Verwerfen** nimmst du alle Änderungen zurück.

### Die Reihenfolge ändern
1. Halte eine Rolle am Griff links fest und ziehe sie nach oben oder unten. Am Computer geht das am Griff auch mit den Pfeiltasten.
2. Lass los. Die App speichert die neue Reihenfolge sofort.

### Festlegen, wie Rollen in der {bereich.attendance} zählen
1. Wähle unter **{bereich.attendance}: Gruppen im Zähler** je Rolle **Eigene Gruppe** oder **zählt als …** eine andere Rolle.
2. Tippe auf **Gruppen speichern**.

## Die Rechte
Jedes Recht gilt für sich; keines schließt ein anderes ein. Die Rechte stehen in diesen Gruppen:
- **Tabs und Navigation**: öffentliche {bereich.events}, {bereich.news}, die öffentlichen Alben der {bereich.gallery} und **Intern** (die Knöpfe unter **Intern** gesammelt unter einem Knopf, siehe [Intern](seite:intern)).
- **Mitgliederbereich**: **Gehört zur Kompanie** (die Person bekommt {bereich.points}, {bereich.penalties} und {bereich.ranks} und steht in Listen und in der {bereich.attendance}), die eigenen {bereich.points}, {bereich.penalties}, den eigenen {begriff.rank} und die eigene {bereich.attendance} in **Meins**, den internen Kalender, die internen Bilder der {bereich.gallery}, die {bereich.birthdays} der anderen und das Teilnehmen an {bereich.polls}.
- **Intern**: je Knopf unter **Intern** ein Recht: {bereich.directory}, {bereich.offices}, Ehemalige, {bereich.documents}, {bereich.board} lesen, {bereich.ranks} und {bereich.points}katalog.
- **Inhalte verwalten** und **Kompanie verwalten**: je Bereich mit eigenen Aufgaben ein Recht, zum Beispiel **{bereich.points} vergeben, Katalog pflegen** oder **Öffentliche {bereich.events} pflegen**.
- **Mitglieder und Rollen**: **Persönliche Angaben sehen** (Adresse, Handynummer, E-Mail-Adresse und Geburtsdatum der Mitglieder auf ihrer Seite in der Verwaltung), **Persönliche Angaben pflegen** (diese Angaben ändern), **Mitglieder anlegen und Startpasswörter erzeugen** und **Rollen vergeben**.

Die eigenen Daten in **Meins** und alle Rechte zum Verwalten gibt es nur zusammen mit **Gehört zur Kompanie**: Hakst du eines davon an, hakt die App **Gehört zur Kompanie** mit an. Nimmst du es weg, entfallen sie. Ebenso gibt es **Persönliche Angaben pflegen** nur zusammen mit **Persönliche Angaben sehen**. Die übrigen Rechte zum Sehen gehen auch ohne, z. B. für Anwärter.

Einstellungen, Updates und Datensicherung bleiben immer den {rolle.admin}s vorbehalten; dieses Recht kannst du keiner anderen Rolle geben.

## Gut zu wissen
- **Feste Rollen:** {rolle.guest} gilt auch für alle, die die App ohne Anmeldung öffnen. {rolle.member} ist die Rolle neuer Konten und gehört immer zur Kompanie. {rolle.admin} darf alles. Diese drei kannst du umbenennen, aber nicht löschen. Bei {rolle.guest} und {rolle.member} stellst du ein, was sie sehen; Rechte zum Verwalten bekommen sie nur über eigene Rollen. Bei {rolle.admin} lassen sich die Rechte nicht ändern. Den {bereich.points}faktor kannst du bei {rolle.member} und {rolle.admin} ändern.
- **{rolle.guest} ohne Anmeldung:** Was {rolle.guest} sieht, sieht jeder, der die App öffnet. Gib {rolle.guest} deshalb z. B. die {bereich.documents} oder die {bereich.birthdays} nur, wenn sie wirklich öffentlich sein sollen. Auch die Shortcodes auf der Webseite zeigen nur, was {rolle.guest} sieht.
- **Persönliche Angaben:** Seine eigenen Angaben sieht und ändert jede Person unter **Meins → Konto**. Wer Mitglieder anlegen darf, trägt sie beim Anlegen mit ein, sieht sie danach aber nur mit **Persönliche Angaben sehen**. Rollen, die vor diesem Recht **Mitglieder anlegen** hatten, haben nach dem Update beide Rechte für persönliche Angaben; nimm sie dort weg, wenn die Rolle sie nicht braucht.
- **Nach dem Update:** Jede Rolle sieht zuerst genau das, was sie vorher sah. Danach kannst du jedes Recht einzeln wegnehmen oder dazugeben.
- **Rollen der Bereiche:** Schaltest du einen Bereich mit eigenen Aufgaben ein, kommt eine Rolle dazu, die wie der Bereich mit „verwalten“ dahinter heißt, z. B. „{bereich.points} verwalten“. Sie sieht, was {rolle.member} anfangs sieht, und hat das Recht des Bereichs (siehe [Bereiche](seite:admin-bereiche)).
- **Grundrolle und Zusatzrollen:** Jede Person hat genau eine Grundrolle und dazu auf Wunsch Zusatzrollen; sie hat die Rechte aller ihrer Rollen zusammen. Zusatzrollen können nur eigene Rollen und Rollen der Bereiche sein. Sie gibt es nur zu einer Grundrolle, die zur Kompanie gehört.
- **{bereich.points}faktor:** Er gilt beim Buchen, für neue Buchungen; alte bleiben, wie sie sind. Hat jemand mehrere Rollen, gilt der kleinste Faktor. {rolle.guest} und Rollen, die nicht zur Kompanie gehören, haben keinen, weil sie keine {bereich.points} bekommen.
- **Reihenfolge:** Sie gilt überall, wo Rollen stehen: in Auswahllisten, bei den Zusatzrollen und in den Zählern der Mitgliederliste und der {bereich.attendance}.
- **Löschen:** Hat jemand die gelöschte Rolle als Zusatzrolle, bekommt er die gewählte Ersatzrolle als Zusatzrolle, wenn das eine eigene Rolle ist; sonst fällt sie weg.
- Insgesamt gibt es höchstens 30 Rollen.
- Mit WordPress sind die Administratoren der Webseite die {rolle.admin}s der App. Diese Rolle vergibst du im WordPress-Backend, nicht in der App.

> **Beispiel Anwärter:** Lege eine Rolle „Anwärter“ an, mit **Interner Kalender** und **{bereich.board} lesen**, ohne **Gehört zur Kompanie**, mit **Beim Anlegen neuer Konten wählbar** und dem Häkchen zum Aufnehmen als {rolle.member}. Anwärter sehen dann den internen Kalender und das {bereich.board} und stehen in der Mitgliederliste und der {bereich.attendance}, bekommen aber keine {bereich.points} oder {bereich.penalties}. Der Name der Rolle steht in beiden Mitgliederlisten wie ein Titel neben dem Namen, auch in der öffentlichen unter **{bereich.directory}**. Später nimmt sie auf, wer Mitglieder anlegen darf.

## Siehe auch
- [App als Rolle ansehen](seite:admin-vorschau)
- [Konto und Rolle](seite:rollen-vergeben)
- [Mitglied anlegen](seite:mitglieder-anlegen)
