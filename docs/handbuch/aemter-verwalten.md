---
titel: "{bereich.offices} verwalten"
reihenfolge: 58
rechte: [manage_offices]
bereiche: [offices]
---
Wer die Rolle „{bereich.offices} verwalten“ oder eine Rolle mit diesem Recht hat, pflegt unter **Verwaltung → {bereich.offices}**, wer welches Amt wann hatte. Was du hier einträgst, steht im Zeitstrahl unter **{bereich.directory} → {bereich.offices}** und ist für alle sichtbar, auch ohne Anmeldung (siehe [{bereich.offices}](seite:aemter)).

## Gruppen und Ämter
**Gruppen** ordnen die Ämter, z. B. „Verein“ und „Dachverband“. Unter **Neue Gruppe** eine Bezeichnung eintragen und **Gruppe anlegen** tippen. Die Reihenfolge änderst du, indem du eine Gruppe am Griff links (sechs Punkte) an die gewünschte Stelle ziehst; am Computer geht es auch mit Tab und den Pfeiltasten. Löschen lässt sich nur eine leere Gruppe.

Unter **Neues Amt** trägst du Bezeichnung und Gruppe ein (oder „Ohne Gruppe“). Innerhalb einer Gruppe ziehst du die Ämter am Griff in die gewünschte Reihenfolge. Ein Tipp auf ein Amt öffnet es: Dort änderst du unter **Amt bearbeiten** Bezeichnung und Gruppe. **Amt löschen** löscht das Amt mit allen Amtszeiten.

## Amtszeiten
Im Amt tippst du auf **Amtszeit eintragen**:
- **Art:** *Zeitraum* (von–bis, oder ohne „Bis“: läuft noch) oder *Einzelnes Jahr*, z. B. für eine Ehrung.
- **Personen:** Wähle zuerst unter **Profil**, wer die Person ist: *Ohne Profil* (sie hat kein Konto, z. B. frühere oder verstorbene Mitglieder; dann trägst du den Namen ein), *Mitglied* oder *Ehemaliger* (dann wählst du sie im Feld daneben aus). Ehemalige stehen dort, wenn sie unter **Verwaltung → Ehemalige** eingetragen sind. Mit **Weitere Person** trägst du mehrere ein, z. B. zwei Minister.
- **Zusatz:** ein kurzer Hinweis, z. B. „Unter …“ oder „Ehrenvorsitz“.

Die Reihenfolge im Zeitstrahl ergibt sich von selbst: laufende zuerst, dann nach Jahren. **Bearbeiten** und **Löschen** stehen bei jeder Amtszeit. Endet ein Amt, trage bei der Amtszeit das Jahr unter **Bis** ein.

Wird ein Konto gelöscht, bleiben seine Amtszeiten mit dem Namen stehen; nur die Verbindung zum Konto fällt weg.

## {bereich.titles} automatisch vergeben
Nutzt die Kompanie **{bereich.titles}**, kannst du ein Amt unter **{bereich.titles} vergeben** mit einem Titel verknüpfen (dafür brauchst du auch das Recht, {bereich.titles} zu verwalten). Dann gilt:
- Wer eine laufende Amtszeit hat (mit Konto, als {rolle.member}), bekommt den Titel automatisch; wer keine laufende mehr hat, verliert ihn.
- Bevor du verknüpfst, zeigt die App, wer den Titel bekommt und wer ihn verliert. Erst **Verknüpfen** übernimmt das.
- Von Hand lässt sich ein verknüpfter Titel nicht mehr vergeben oder entfernen.
- Einzelne Jahre (Ehrungen) vergeben keinen Titel.
- **Verknüpfung lösen** ändert an den vergebenen Titeln nichts; danach vergibst du sie wieder von Hand.

Ein Titel gehört zu höchstens einem Amt.

## Tabelle importieren
Für die Ersterfassung vieler Amtszeiten tippst du auf **Tabelle importieren**. Eine Zeile je Amtszeit, Spalten durch Semikolon getrennt (oder durch Tabulator, z. B. direkt aus einer Tabellenkalkulation kopiert):

`gruppe;amt;beginn;ende;art;personen;zusatz`

- **ende:** ein Jahr, „heute“ für eine laufende Amtszeit, oder leer für ein einzelnes Jahr.
- **art:** meist leer; „zeitraum“ oder „zeitpunkt“ erzwingt die Art.
- **personen:** mehrere mit „&“ trennen.

Eine Kopfzeile ist erlaubt. Tabelle einfügen oder eine CSV-Datei wählen, dann **Vorschau**. Die Vorschau zeigt jede Zeile und, was sie anlegt; Zeilen mit Fehler sind markiert und müssen erst korrigiert werden. Gruppen und Ämter findet die App nach ihrem Namen oder legt sie an. Was es genau so schon gibt, überspringt sie, du kannst also dieselbe Tabelle gefahrlos noch einmal einspielen. Ist **Personen mit dem Konto verknüpfen** angehakt, verbindet die App einen Namen mit einem Konto, wenn er genau zu einem Konto passt. **Übernehmen** speichert alles auf einmal.
