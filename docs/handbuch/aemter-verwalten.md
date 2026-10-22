---
titel: "{bereich.offices} verwalten"
reihenfolge: 58
rechte: [manage_offices]
bereiche: [offices]
---
Hier pflegst du, wer welches Amt wann hatte – auch frühere Inhaber ohne Konto. Was du einträgst, steht im Zeitstrahl unter **{bereich.directory} → {bereich.offices}** und ist für alle sichtbar, auch ohne Anmeldung.

![{bereich.offices} in der Verwaltung](bild:aemter-verwalten)

## So öffnest du die Seite
Tippe unten auf **Verwaltung** und dann auf **{bereich.offices}**.

## Was du hier siehst
- **Tabelle importieren**: viele Amtszeiten auf einmal übernehmen.
- **Zeitstrahl ansehen**: öffnet die Ansicht, die alle sehen.
- **Gruppen**: je Gruppe **Umbenennen** und **Löschen**, darunter **Neue Gruppe** mit **Gruppe anlegen**.
- **Die Ämter** je Gruppe (und unter **Ohne Gruppe**), mit der Zahl der Amtszeiten und, falls verknüpft, dem {bereich.titles}, den das Amt vergibt.
- **Neues Amt** mit **Bezeichnung**, **Gruppe** und **Anlegen**.
- Links an Gruppen und Ämtern ein Griff (sechs Punkte) zum Verschieben.

## So gehst du vor
### Eine Gruppe oder ein Amt anlegen
1. Trage unter **Neue Gruppe** eine Bezeichnung ein, z. B. „Vorstand“, und tippe auf **Gruppe anlegen**.
2. Trage unten unter **Neues Amt** die **Bezeichnung** ein, wähle die **Gruppe** (oder „Ohne Gruppe“) und tippe auf **Anlegen**.

### Die Reihenfolge ändern
1. Halte eine Gruppe oder ein Amt am Griff links fest.
2. Zieh es an die gewünschte Stelle. Ämter verschiebst du innerhalb ihrer Gruppe. Am Computer geht es auch mit Tab und den Pfeiltasten.

### Eine Amtszeit eintragen
1. Tippe auf das Amt. Es öffnet sich mit allen seinen Amtszeiten.
2. Tippe auf **Amtszeit eintragen**.
3. Wähle die **Art**: **Zeitraum (von–bis oder bis heute)** oder **Einzelnes Jahr (z. B. eine Ehrung)**.
4. Trage **Von (Jahr)** und, wenn die Amtszeit vorbei ist, **Bis** ein. Leer heißt: läuft noch.
5. Wähle unter **Personen** bei **Profil**, wer die Person ist: **Ohne Profil** (kein Konto, z. B. frühere oder verstorbene Mitglieder; dann trägst du den **Name**n ein), **Mitglied** oder **Ehemaliger** (dann wählst du sie aus). Mit **Weitere Person** trägst du mehrere ein.
6. Trage, wenn gewünscht, einen **Zusatz** ein, z. B. „Unter …“ oder „Ehrenvorsitz“.
7. Tippe auf **Speichern**.

![Amtszeiten eines Amts](bild:aemter-verwalten-amt)

![Formular „Neue Amtszeit“](bild:aemter-verwalten-amtszeit)

### Eine Amtszeit ändern oder beenden
1. Tippe im Amt bei der Amtszeit auf **Bearbeiten** (oder **Löschen**).
2. Endet ein Amt, trage bei der Amtszeit das Jahr unter **Bis** ein und tippe auf **Speichern**.

### Ein Amt umbenennen oder löschen
1. Öffne das Amt und ändere unter **Amt bearbeiten** **Bezeichnung** oder **Gruppe**; tippe auf **Speichern**.
2. **Amt löschen** löscht das Amt mit allen Amtszeiten. **‹ Alle {bereich.offices}** führt zurück.

### Eine Tabelle importieren
1. Tippe auf **Tabelle importieren**.
2. Füge die Tabelle ins Feld **Tabelle** ein oder wähle eine CSV-Datei. Eine Zeile je Amtszeit, Spalten durch Semikolon oder Tabulator getrennt: `gruppe;amt;beginn;ende;art;personen;zusatz`.
3. Lass **Personen mit dem Konto verknüpfen, wenn der Name genau zu einem Konto passt** angehakt, wenn die App Namen mit Konten verbinden soll.
4. Tippe auf **Vorschau**, prüfe die Zeilen und tippe auf **Übernehmen**.

## Gut zu wissen
- Die Reihenfolge der Amtszeiten im Zeitstrahl ergibt sich von selbst: laufende zuerst, dann nach Jahren.
- Löschen lässt sich nur eine leere Gruppe.
- Ehemalige stehen bei **Ehemaliger** zur Auswahl, wenn sie in der Verwaltung als ehemalig eingetragen sind.
- Wird ein Konto gelöscht, bleiben seine Amtszeiten mit dem Namen stehen; nur die Verbindung zum Konto fällt weg.
- Import: **ende** ist ein Jahr, „heute“ für eine laufende Amtszeit oder leer für ein einzelnes Jahr. **art** ist meist leer; „zeitraum“ oder „zeitpunkt“ erzwingt die Art. Mehrere **personen** trennst du mit „&“. Eine Kopfzeile ist erlaubt. Gruppen und Ämter findet die App nach ihrem Namen oder legt sie an. Was es genau so schon gibt, überspringt sie; du kannst dieselbe Tabelle also gefahrlos noch einmal einspielen. Zeilen mit Fehler musst du erst korrigieren.

### {bereich.titles} automatisch vergeben
Nutzt die Kompanie {bereich.titles}, kannst du im Amt unter **{bereich.titles} vergeben** einen Titel bei **Verknüpfter {bereich.titles}** wählen (dafür musst du auch {bereich.titles} verwalten dürfen). Bevor du auf **Verknüpfen** tippst, zeigt die App, wer den Titel bekommt und wer ihn verliert.
- Wer eine laufende Amtszeit hat (mit Konto, als {rolle.member}), bekommt den Titel automatisch; wer keine laufende mehr hat, verliert ihn. Einzelne Jahre (Ehrungen) vergeben keinen Titel.
- Von Hand lässt sich ein verknüpfter Titel nicht mehr vergeben oder entfernen. Ein Titel gehört zu höchstens einem Amt.
- **Verknüpfung lösen** ändert an den vergebenen Titeln nichts; danach vergibst du sie wieder von Hand.
- Auf der Seite einer Person steht bei ihren Amtszeiten der Name des Titels statt der Bezeichnung des Amts. Benenne das Amt also in der Mehrzahl („Kompaniekönige“) und den Titel in der Einzahl („Kompaniekönig“).

## Siehe auch
- [{bereich.offices}](seite:aemter)
