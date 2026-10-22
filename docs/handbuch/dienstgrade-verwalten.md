---
titel: "{bereich.ranks} verwalten"
reihenfolge: 54
rechte: [manage_ranks]
bereiche: [ranks]
---
Hier legst du fest, welche {bereich.ranks} es bei euch gibt, in welcher Reihenfolge, ab wie vielen {bereich.points} sie erreichbar sind und welches Abzeichen sie haben. Wer welchen {begriff.rank} hat, änderst du auf der Seite des Mitglieds.

![Die Liste der Dienstgrade mit Schwellen und Abzeichen](bild:dienstgrade-verwalten)

## So öffnest du die Seite
Tippe unten auf **Verwaltung** und dann auf den Knopf **{bereich.ranks}**. Mit **‹ Verwaltung** kommst du zurück.

## Was du hier siehst
- Die {bereich.ranks} vom niedrigsten (oben) zum höchsten (unten), jeweils mit Abzeichen, Bezeichnung, Schwelle („ab … {bereich.points}“ oder „ohne Schwelle“) und wie oft er vergeben ist. Deaktivierte tragen das Schild „inaktiv“.
- Bei jedem {begriff.rank} die Knöpfe **niedriger**, **höher**, **Ändern**, **Deaktivieren** (bzw. **Aktivieren**) oder **Löschen**.
- Darunter das Abzeichen: eine Vorschau auf heller und dunkler Fläche („hell“, „dunkel“) mit **Ersetzen** und **Entfernen**, ohne Abzeichen der Knopf **Abzeichen hochladen**.
- Unten das Formular **Neuer {begriff.rank}** mit **Bezeichnung**, **{bereich.points}schwelle (optional)** und **Anlegen**.

![Ein Dienstgrad mit seinen Knöpfen und der Vorschau des Abzeichens](bild:dienstgrade-verwalten-abzeichen)

## So gehst du vor
### Einen {begriff.rank} anlegen
1. Trage unter **Neuer {begriff.rank}** die **Bezeichnung** ein.
2. Trage bei Bedarf die **{bereich.points}schwelle** ein: ab wie vielen {bereich.points} man ihn bekommen kann.
3. Tippe auf **Anlegen**. Der neue {begriff.rank} steht ganz unten, also als höchster.

![Ausgefülltes Formular für einen neuen Dienstgrad](bild:dienstgrade-verwalten-neu)

### Die Reihenfolge ändern
1. Tippe beim {begriff.rank} auf **niedriger** oder **höher**. Er rückt eine Stufe weiter.
2. Wiederhole das, bis er an der richtigen Stelle steht.

### Bezeichnung oder Schwelle ändern
1. Tippe beim {begriff.rank} auf **Ändern**.
2. Ändere **Bezeichnung** oder **{bereich.points}schwelle**. Leer heißt: ohne Schwelle.
3. Tippe auf **Speichern** (oder **Abbrechen**).

### Ein Abzeichen hochladen
1. Tippe beim {begriff.rank} auf **Abzeichen hochladen** und wähle die Bilddatei.
2. Prüfe in der Vorschau, ob das Abzeichen auf heller und auf dunkler Fläche gut zu erkennen ist.
3. Ein anderes Bild nimmst du mit **Ersetzen**, ganz weg geht es mit **Entfernen**.

### Einen {begriff.rank} löschen oder stilllegen
- Hat niemand den {begriff.rank}, tippe auf **Löschen** und bestätige. Sein Abzeichen wird mit gelöscht.
- Hat jemand den {begriff.rank}, geht nur **Deaktivieren**: Er wird nicht mehr neu vergeben, wer ihn hat, behält ihn. **Aktivieren** macht das rückgängig.

## Gut zu wissen
- Die Bezeichnung hat höchstens 60 Zeichen, und jede gibt es nur einmal.
- **Schwellen**: eine ganze Zahl ab 0, leer = ohne. Sie müssen mit der Reihenfolge steigen; sonst meldet die App, welche {bereich.ranks} nicht passen. Schwellen wirken nur, wenn es bei euch auch {bereich.points} gibt; sonst steht beim Feld „(wirkt nur mit {bereich.points})“.
- **Regeln mit Schwelle** (gelten für alle, auch für {rolle.admin}s): Befördern geht erst, wenn die {bereich.points} des Mitglieds die Schwelle des neuen {begriff.rank}s erreichen. Mehrere Stufen auf einmal sind erlaubt. Degradieren geht immer. Für {bereich.ranks} ohne Schwelle gelten keine Regeln; haben noch nicht alle eine, weist die Seite oben darauf hin.
- **Abzeichen**: PNG, JPG, WebP oder SVG, höchstens 1 MB (PNG, JPG und WebP höchstens 4096 × 4096 Pixel). Etwa 256 Pixel an der längeren Kante reichen; die App verkleinert selbst.
- Am besten wirkt ein Abzeichen mit **transparentem Hintergrund** (PNG, WebP oder SVG). Die App legt keine Fläche dahinter, und der Hintergrund ist je nach Einstellung des Handys hell oder dunkel. Ein heller Rand um dunkle Teile hilft. JPG hat immer einen eigenen Hintergrund.
- Das Abzeichen erscheint überall, wo der {begriff.rank} steht: unter **Meins**, in der Mitgliederliste der Verwaltung, auf der Seite eines Mitglieds und unter **Beförderungen**. Wer es einmal gesehen hat, sieht es auch ohne Netz.
- Bei einer Beförderung zeigt die Benachrichtigung das neue Abzeichen als Bild. Bei einem SVG zeichnet die App dafür beim Hochladen ein Bild; klappt das nicht, steht „Ohne Bild in Benachrichtigungen“ beim {begriff.rank}. Lade das SVG dann erneut hoch, z. B. auf einem anderen Gerät, oder nimm ein PNG.
- SVG-Dateien werden beim Hochladen bereinigt: Skripte, Verweise nach außen, eingebettete Bilder und fremde Inhalte fallen weg. Ein normales SVG aus einem Grafikprogramm sieht danach gleich aus. Dateien mit einer DOCTYPE-Angabe lehnt die App ab.
- Ein deaktivierter {begriff.rank} behält sein Abzeichen.

## Siehe auch
- [{begriff.rank} eines Mitglieds](seite:mitglied-dienstgrad)
