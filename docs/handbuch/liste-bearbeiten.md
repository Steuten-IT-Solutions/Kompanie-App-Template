---
titel: Liste bearbeiten
reihenfolge: 62
rechte: [manage_lists]
bereiche: [lists]
---
Hier stellst du eine Liste zusammen: Titel, Spalten und Zeilen. Unten siehst du sie so, wie sie gedruckt wird, und druckst sie oder lädst sie als Datei für Excel herunter.

![Eine Liste mit Titel, Untertitel, Datum und Druckformat](bild:liste-bearbeiten)

## So öffnest du die Seite
Tippe unten auf **Verwaltung**, dann auf **{bereich.lists}**. Tippe bei einer Liste auf **Öffnen** oder lege eine neue an.

## Was du hier siehst
- **‹ Alle {bereich.lists}**: führt zurück zur Übersicht. Hast du noch nicht gespeichert, fragt die App, ob sie die Änderungen verwerfen soll.
- **Liste**: **Titel**, **Untertitel / Hinweis (optional)** (z. B. „Bitte bis Freitag eintragen“), **Datum (optional)** und **Druck** (**Hochformat** oder **Querformat**).
- **Spalten**: die Spalten der Liste, in ihrer Reihenfolge.
- **Zeilen**: wer in die Liste kommt, die Zeilen der Liste mit Nummer und Name und **Sortieren nach**.
- **Speichern**: erscheint unten, sobald du etwas geändert hast.
- **Ausgabe**: **PDF herunterladen**, **Drucken**, **CSV herunterladen** und darunter die Vorschau.

## So gehst du vor
### Spalten einrichten
Es gibt zwei Arten von Spalten:
- **Datenspalten** füllt die App mit den Angaben der Mitglieder: Nr. (zählt die Zeilen), Name, Vorname, Nachname, Rolle, {begriff.rank}, {bereich.titles}, Geburtstag, Adresse (die ganze Anschrift in einer Spalte), Straße und Nr., PLZ, Ort, Land, Handynummer, E-Mail, {bereich.points} und {bereich.penalties} (offen).
- **Leere Spalten** bleiben zum Ausfüllen auf Papier, z. B. „Bezahlt“ oder „Größe“.

1. Wähle unter **Datenspalte** die Angabe und tippe auf **+ Datenspalte**. Für eine Spalte zum Ausfüllen tippe auf **+ Leere Spalte**.
2. Trage bei Bedarf eine **Überschrift** ein. Ohne eigene Überschrift nimmt die App die Bezeichnung der Datenspalte.
3. Wähle die **Breite**: **schmal**, **normal**, **breit** oder **automatisch**. Sie bestimmt, wie viel Platz die Spalte im Ausdruck bekommt. **automatisch** macht die Spalte so breit wie ihren längsten Eintrag oder ihre Überschrift; die übrigen Spalten teilen sich den Rest.
4. Soll in einer leeren Spalte in jeder Zeile ein Kästchen stehen, setze das Häkchen bei **Kästchen zum Abhaken**.
5. Um die Reihenfolge zu ändern, ziehe eine Spalte am Griff links (sechs Punkte) an die gewünschte Stelle. **Entfernen** nimmt eine Spalte heraus.
6. Tippe auf **Speichern**.

![Spalten mit Überschrift, Breite und Kästchen zum Abhaken](bild:liste-bearbeiten-spalten)

### Die Mitglieder in die Liste holen
1. Gibt es Rollen ohne Mitgliederbereich (z. B. Anwärter), wähle unter **In die Liste kommen**, wer in die Liste gehört: **Mitglieder** und/oder diese Rollen. Mindestens eins bleibt angehakt.
2. Tippe unter **Zeilen** auf **Mitglieder aktualisieren**. Ungespeicherte Änderungen speichert die App vorher.
3. Die App zeigt, welche Zeilen dazukommen und welche gelöscht werden. Ist die Liste schon aktuell, meldet sie das nur.
4. Tippe auf **Übernehmen**. Mit **Abbrechen** bleibt alles, wie es ist.

![Mitglieder aktualisieren: Die App zeigt, was sich ändert](bild:liste-bearbeiten-zeilen)

### Eigene Zeilen hinzufügen
1. Tippe auf **+ Leere Zeile** oder **+ 5 leere Zeilen**, z. B. für Gäste, die sich vor Ort eintragen.
2. Schreibe einen Namen in die Zeile oder lass sie leer.
3. Tippe auf **Speichern**.

Zeilen verschiebst du am Griff links. **✕** entfernt eine Zeile.

### Die Liste sortieren
1. Wähle unter **Sortieren nach** den **Name (Nachname, Vorname)** oder eine Datenspalte, z. B. Vorname, Geburtstag oder {bereich.points}.
2. Wähle die **Richtung**: **aufsteigend** oder **absteigend**.
3. Tippe auf **Sortieren** und dann auf **Speichern**.

Zahlen sortiert die App nach ihrem Wert, Geburtstage nach dem Tag im Jahr (Januar zuerst). Zeilen ohne Eintrag in der Spalte kommen ans Ende.

### Drucken oder herunterladen
1. Sieh dir unter **Ausgabe** die Vorschau an.
2. Tippe auf **PDF herunterladen**. Die App speichert die Liste als PDF-Datei, ohne Druckdialog.
3. Zum Drucken tippe auf **Drucken**.
4. Oder tippe auf **CSV herunterladen**. Die Datei öffnest du mit Excel oder einer anderen Tabellenkalkulation.

![Die Ausgabe mit Druckknöpfen und Vorschau](bild:liste-bearbeiten-ausgabe)

## Gut zu wissen
- **Mitglieder aktualisieren** legt für jedes {rolle.member} eine Zeile an, das noch keine hat, und ebenso für die Rollen, die du unter **In die Liste kommen** angehakt hast. Es löscht die Zeilen von Konten, die es nicht mehr gibt oder die nicht mehr dazugehören. {rolle.guest} und Ehemalige kommen nie in die Liste, Rollen ohne Mitgliederbereich (z. B. Anwärter) nur, wenn du sie anhakst. Neue Mitglieder ordnet die App nach dem Nachnamen zwischen die vorhandenen ein.
- Eigene Zeilen bleiben beim Aktualisieren stehen. Entfernst du die Zeile eines Mitglieds, legt das nächste **Mitglieder aktualisieren** sie wieder an.
- Wird ein Konto gelöscht, bleibt seine Zeile ohne Namen stehen, bis du die Liste aktualisierst. Die App weist darauf hin.
- Zur Auswahl stehen nur Datenspalten, deren Bereich eingeschaltet ist und deren Angaben du in der App ohnehin sehen darfst. Die Rolle siehst du z. B. nur, wenn du die Mitgliederliste der Verwaltung siehst, {bereich.points} nur, wenn du sie vergeben darfst. Geburtstage stehen nur in der Liste, wenn die Person sie für andere freigegeben hat. Sie stehen ausgeschrieben da („9. März“), das Geburtsjahr nur mit dem Recht **Persönliche Angaben sehen** („9. März 1990“). Adresse, Handynummer und E-Mail siehst du nur mit dem Recht **Persönliche Angaben sehen**.
- Für Serienbriefe oder Adressetiketten nimmst du statt **Adresse** die einzelnen Spalten **Straße und Nr.**, **PLZ**, **Ort** und **Land** und lädst die Liste als CSV herunter; jede Angabe steht dann in einer eigenen Spalte. Die Handynummer steht in der CSV mit 00 statt + (z. B. 0049 171 1234567), damit Excel sie unverändert übernimmt.
- Öffnet jemand die Liste, der eine Datenspalte nicht sehen darf, bleibt sie für ihn leer, auch im Ausdruck und in der Datei.
- Eine Liste hat höchstens 20 Spalten und 500 Zeilen.
- Hat jemand anderes die Liste inzwischen geändert, speichert die App nicht, sondern meldet das. Lade die Liste dann neu und trage deine Änderungen noch einmal ein.
- Gedruckt und heruntergeladen wird der Stand auf dem Bildschirm, auch wenn du ihn noch nicht gespeichert hast.
- Der Ausdruck hat oben einen Balken in der Farbe der Kompanie mit Logo, Titel und Untertitel, darunter den Namen der Kompanie, das Datum der Liste und den Stand (heutiges Datum). Der Tabellenkopf steht auf jeder Seite, unten rechts steht die Seitenzahl. In schmalen Spalten (außer Nr.) steht die Überschrift schräg, damit auch längere Überschriften passen. Spalten mit Namen sind mindestens so breit wie der längste Name.
- Das PDF sieht aus wie der Ausdruck, nutzt aber eine Standardschrift. Buchstaben, die sie nicht kennt (z. B. „ł“), stehen ohne Akzent oder als „?“ da. Ist das wichtig, drucke über **Drucken** und wähle dort „Als PDF speichern“.
- Als Dateinamen schlägt die App den Titel der Liste vor.

## Siehe auch
- [{bereich.lists}](seite:listen)
