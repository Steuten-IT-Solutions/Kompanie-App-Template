---
titel: "{bereich.ranks} verwalten"
reihenfolge: 57
rechte: [manage_ranks]
bereiche: [ranks]
---
Wer die Rolle „{bereich.ranks} verwalten“ oder eine Rolle mit diesem Recht hat, findet in der App den Bereich **Verwaltung** (unten in der Leiste, wenn es mehrere Bereiche gibt). Die einzelnen Seiten wählst du dort im Menü oben.

## Liste pflegen
Unter **Verwaltung → {bereich.ranks}** legst du die {bereich.ranks} an, vom niedrigsten zum höchsten: Bezeichnung (und optional eine {bereich.points}schwelle) eintragen und **Anlegen** tippen. Neue {bereich.ranks} kommen ans obere Ende. Mit **niedriger** und **höher** änderst du die Reihenfolge, mit **Ändern** Bezeichnung und {bereich.points}schwelle.

Ein {begriff.rank}, den niemand hat, lässt sich **löschen**. Ein {begriff.rank}, den jemand hat, lässt sich nur **deaktivieren**: Er wird nicht mehr neu vergeben, wer ihn hat, behält ihn. **Aktivieren** macht das rückgängig.

## Abzeichen
Zu jedem {begriff.rank} kannst du direkt in dieser Liste ein Abzeichen (Bild) hochladen: **Abzeichen hochladen**, später **Ersetzen** oder **Entfernen**. Welche Dateien gehen, wie das Abzeichen auf hellem und dunklem Hintergrund gut aussieht und was bei SVG zu beachten ist, steht unter [Abzeichen für {bereich.ranks}](seite:dienstgrade-abzeichen).

## Befördern und degradieren
Im Mitglied (**Verwaltung → Mitglieder**, dann im Menü unter dem Namen **{begriff.rank}**) unter **Neuer {begriff.rank}** den {begriff.rank} wählen, optional eine Begründung eintragen und **{begriff.rank} ändern** tippen. Jede Änderung steht im Verlauf des Mitglieds, mit Datum, Begründung und wer sie vorgenommen hat. Wer [Benachrichtigungen](seite:benachrichtigungen) eingeschaltet hat, bekommt eine, wenn sich sein {begriff.rank} ändert.

Einen {begriff.rank} bekommen nur Mitglieder. Wer kein Mitglied mehr ist (z. B. nach einem Wechsel zur Rolle {rolle.guest}), behält seinen {begriff.rank}; du kannst ihn dort jederzeit auf „Kein {begriff.rank}“ setzen.

## Zusammen mit {bereich.points}
Jeder {begriff.rank} kann eine **{bereich.points}schwelle** haben (eine ganze Zahl ab 0, leer = ohne). Sie wirkt nur, wenn auch {bereich.points} eingeschaltet ist. Die Schwellen müssen mit der Reihenfolge steigen. Dann gilt für alle, auch für {rolle.admin}s:
- **Befördern** erst, wenn die {bereich.points} des Mitglieds die Schwelle des neuen {begriff.rank}s erreichen. Mehrere {bereich.ranks} auf einmal zu überspringen ist erlaubt.
- **Degradieren** geht immer, auch wenn die {bereich.points} die Schwelle des aktuellen {begriff.rank}s noch erreichen, z. B. um eine zu hohe Einstufung zu korrigieren. Wer danach die Schwelle eines höheren {begriff.rank}s erreicht, steht unter **Beförderungen** oben.
- Für {bereich.ranks} ohne Schwelle gelten diese Regeln nicht; trage die Schwellen nach, wenn ihr sie nutzen wollt.

Die Auswahl unter **Neuer {begriff.rank}** bietet nur an, was nach diesen Regeln erlaubt ist. Wer {bereich.ranks} verwaltet, sieht dafür die Punktestände der Mitglieder, auch ohne selbst {bereich.points} vergeben zu dürfen.

Unter **Verwaltung → Beförderungen** (nur mit {bereich.points}) stehen alle Mitglieder, die noch einen höheren {begriff.rank} erreichen können, in zwei Abschnitten: unter **Schwelle erreicht** die, deren Schwelle für den nächsten {begriff.rank} mit Schwelle erreicht ist (mit dem Datum, seit dem; die längsten zuerst), darunter unter **Noch nicht erreicht** die übrigen, nach fehlenden {bereich.points} sortiert. Der Schalter **Nur unter der Schwelle des aktuellen {begriff.rank}s** zeigt, wer degradiert werden könnte; dort erscheinen auch Mitglieder mit dem höchsten {begriff.rank}. Ein Tipp auf einen Namen öffnet den {begriff.rank} des Mitglieds, mit dem nächsten {begriff.rank} schon vorgeschlagen.

## Bestehende Mitglieder übernehmen
Wer zusätzlich Mitglieder anlegen darf, kann beim Anlegen gleich den {begriff.rank} eintragen (mit dem Recht für {bereich.points} auch den Anfangsbestand an {bereich.points} mit Datum). Mit {bereich.points} geht nur ein {begriff.rank}, dessen Schwelle der Anfangsbestand erreicht. Im Verlauf steht das als „Ersteinstufung“.
