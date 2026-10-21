---
titel: "{bereich.ranks} verwalten"
reihenfolge: 57
rechte: [manage_ranks]
bereiche: [ranks]
---
Wer die Rolle „{bereich.ranks} verwalten“ oder eine Rolle mit diesem Recht hat, findet in der App den Bereich **Verwaltung** (unten in der Leiste, wenn es mehrere Bereiche gibt).

## Liste pflegen
Unter **Verwaltung → {bereich.ranks}** legst du die {bereich.ranks} an, vom niedrigsten zum höchsten. Mit **niedriger** und **höher** änderst du die Reihenfolge, mit **Ändern** Bezeichnung und Punkteschwelle. Ein {begriff.rank}, den jemand hat, lässt sich nicht löschen, nur deaktivieren; die Person behält ihn.

Zu jedem {begriff.rank} kannst du ein Abzeichen (Bild) hinterlegen, siehe [Abzeichen für {bereich.ranks}](seite:dienstgrade-abzeichen).

## Befördern und degradieren
Im Mitglied (**Verwaltung → Mitglieder**, dann im Menü unter dem Namen **{begriff.rank}**) den neuen {begriff.rank} wählen, optional eine Begründung eintragen und **{begriff.rank} ändern** tippen. Jede Änderung steht im Verlauf des Mitglieds, mit Datum, Begründung und wer sie vorgenommen hat. Wer kein Mitglied mehr ist (z. B. nach einem Wechsel zur Rolle {rolle.guest}), behält seinen {begriff.rank}; du kannst ihn dort jederzeit auf „Kein {begriff.rank}“ setzen.

## Zusammen mit {bereich.points}
Ist auch {bereich.points} eingeschaltet, kann jeder {begriff.rank} eine **Punkteschwelle** haben. Die Schwellen müssen mit der Reihenfolge steigen. Dann gilt für alle, auch für Admins:
- **Befördern** erst, wenn die {bereich.points} des Mitglieds die Schwelle des neuen {begriff.rank}s erreichen. Mehrere {bereich.ranks} auf einmal zu überspringen ist erlaubt.
- **Degradieren** nur, wenn die {bereich.points} unter der Schwelle des aktuellen {begriff.rank}s liegen, also nachdem {bereich.points} abgezogen wurden.
- Für {bereich.ranks} ohne Schwelle gelten diese Regeln nicht; trage die Schwellen nach, wenn ihr sie nutzen wollt.

Wer {bereich.ranks} verwaltet, sieht dafür die Punktestände der Mitglieder, auch ohne selbst {bereich.points} vergeben zu dürfen.

Unter **Verwaltung → Beförderungen** stehen alle Mitglieder, die noch einen höheren {begriff.rank} erreichen können: zuerst die, deren Schwelle für den nächsten {begriff.rank} mit Schwelle erreicht ist (mit dem Datum, seit dem), dann – durch einen Trenner abgesetzt – nach fehlenden {bereich.points} sortiert. Ein Filter zeigt, wer unter der Schwelle seines aktuellen {begriff.rank}s liegt; dort erscheinen auch Mitglieder mit dem höchsten {begriff.rank}.

## Bestehende Mitglieder übernehmen
Wer zusätzlich Mitglieder anlegen darf, kann beim Anlegen gleich den Anfangsbestand an {bereich.points} (mit Datum) und den {begriff.rank} eintragen; mit {bereich.points} nur einen {begriff.rank}, dessen Schwelle der Anfangsbestand erreicht.
