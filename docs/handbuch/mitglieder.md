---
titel: "{bereich.directory}"
reihenfolge: 39
rechte: []
bereiche: [directory]
---
Unter **{bereich.directory}** (unten in der Leiste) siehst du, wer zur Kompanie gehört: alle {rolle.member} und Personen mit dem internen Kalender (z. B. Anwärter). Sehen kann die Liste jeder, auch Gäste und ohne Anmeldung; Gäste stehen nicht darin. Oben wechselst du zwischen der Liste, dem Zeitstrahl der Ämter (nutzt die Kompanie **{bereich.offices}**, siehe dort im Handbuch) und den **Ehemaligen**. Mit WordPress zeigt der Shortcode `[kompanie_mitglieder]` die Liste als Bildergalerie (Foto mit Namen, groß beim Antippen) auch auf der Webseite.

## Was in der Liste steht
Jede Zeile zeigt den Namen und vorn ein kleines rundes Foto; wer keines hat, bekommt dort seine Anfangsbuchstaben. Tippst du auf ein Foto, erscheint es groß und ganz – so kannst du dir die Gesichter besser einprägen. Schließen mit **✕** oder durch Tippen neben das Foto. Nutzt die Kompanie **{bereich.titles}**, stehen sie beim Namen. Ist der Bereich **{bereich.ranks}** eingeschaltet, steht rechts das Abzeichen mit dem {begriff.rank} darunter; wer keinen {begriff.rank} hat, hat dort nichts.

Ein Tipp auf eine Person öffnet ihre Seite: Foto, Name, {bereich.titles} und {begriff.rank}, und nutzt die Kompanie **{bereich.offices}**, darunter alle ihre Ämter als Zeitstrahl. **‹ Zurück** führt wieder zur Liste. Mehr zeigt die Liste nicht: keine Rollen, keine {bereich.points} oder {bereich.penalties}, keine E-Mail-Adressen oder Benutzernamen.

## Geburtstag und Eintritt
Auf seiner Seite kann jedes {rolle.member} auch seinen Geburtstag und seinen Eintritt zeigen, z. B. „Geburtstag 12. März 1988 (38 Jahre) · Eintritt 01.04.2005“. Das entscheidet nur die Person selbst unter **Konto**, je Angabe: **Nicht anzeigen**, **Mitgliedern anzeigen** (von Anfang an; nur angemeldete {rolle.member} sehen es) oder **Öffentlich anzeigen** (jeder, auch ohne Anmeldung). Beim Geburtstag stehen Jahr und Alter nur, wenn die Person **Alter anzeigen** eingeschaltet und ein Jahr eingetragen hat, sonst nur Tag und Monat.

So stellst du es für dich ein:
- **Geburtstag:** unter **Konto** in der Karte **Geburtstag** bei **Auf meiner Seite unter „{bereich.directory}“** (nur wenn die Kompanie {bereich.birthdays} nutzt).
- **Eintritt:** unter **Konto** in der Karte **Eintritt** das Datum (TT.MM.JJJJ) oder nur das Jahr eintragen, die Anzeige wählen und **Eintritt speichern** tippen. **Entfernen** löscht ihn. Auf deiner Seite steht der Eintritt so, wie er eingetragen ist: das ganze Datum oder nur das Jahr. Den Eintritt kann auch die Verwaltung für dich eintragen; die Anzeige wählst aber nur du.

## Ehemalige
Unter **Ehemalige** stehen alle, die die Verwaltung als ehemalig eingetragen hat (ausgetreten, verstorben oder sonst ausgeschieden). Die Liste sieht aus wie die der {rolle.member}, mit Suche und Sortierung; unter dem Namen stehen, soweit eingetragen, „Gründungsmitglied“ sowie Eintritt und Ausscheiden, so wie sie eingetragen sind, z. B. „01.04.1985 – 03.05.2010“ oder nur mit den Jahren „1985–2010“. Der Grund des Ausscheidens steht dort nicht. Ein Tipp öffnet die Seite der Person mit ihren Ämtern.

## Suchen und sortieren
Über der Liste steht oben, wie viele Personen darin stehen, je Gruppe wie in der Verwaltung: {rolle.member} und die Rollen, die gesondert zählen (z. B. Anwärter), in umgekehrter Reihenfolge der Rollen, die ein {rolle.admin} festlegt (von unten nach oben). Suche und Sortierung ändern den Zähler nicht. Im Suchfeld genügt ein Teil des Namens. Unter **Sortierung** wählst du **Nachname** (dann steht „Nachname, Vorname“ in der Liste) oder **Vorname**, mit **A → Z** bzw. **Z → A**, oder **Eintritt** mit **Früheste zuerst** bzw. **Neueste zuerst**. Nach Eintritt zählt der Eintritt, den eine Person auf ihrer Seite zeigt (unter dem Namen steht dann z. B. „Eintritt 01.04.2005“); wer keinen sichtbaren Eintritt hat, steht am Ende. Unter **Ehemalige** stehen Gründungsmitglieder ganz vorn. Die Sortierung merkt sich die App auf diesem Gerät.

## Fotos
Die Fotos laden die zuständigen Personen in der Verwaltung hoch (siehe **Mitglieder anlegen**). Dein eigenes Foto siehst du unter **Konto**. Möchtest du ein anderes Foto oder keines, sprich die Verwaltung an.

## Für Admins
Die Liste ist ein eigener Bereich: **Einstellungen → Bereiche → {bereich.directory}**. Solange er an ist, sind Namen, Fotos, {bereich.titles} und {begriff.rank} aller {rolle.member}, Personen mit dem internen Kalender (z. B. Anwärter) und Ehemaligen (bei diesen auch Eintritt und Ausscheiden, mit dem ganzen Datum, soweit eingetragen) öffentlich, ebenso Geburtstag und Eintritt der {rolle.member}, die das selbst so gewählt haben, also für jeden mit der Adresse der App sichtbar (Suchmaschinen sollen die App nicht aufnehmen). Bitte nur mit dem Einverständnis der Personen einschalten. Ist er aus, verschwindet die Liste, und {rolle.member} sehen die Fotos der anderen nicht mehr.
