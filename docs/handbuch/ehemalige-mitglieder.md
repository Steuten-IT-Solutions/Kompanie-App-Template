---
titel: Ehemalige Mitglieder
reihenfolge: 71
rechte: [create_users]
---
Wer austritt, verstirbt oder sonst ausscheidet, muss nicht gelöscht werden. Als **ehemalig** eingetragen bleibt das Konto mit allen Daten erhalten: {bereich.points}, {bereich.penalties}, Anwesenheit, {begriff.rank} mit Verlauf, {bereich.titles}, {bereich.offices}, Geburtstag und Foto. Das darf, wer Mitglieder anlegen darf, mit denselben Grenzen wie beim Löschen (Konten von {rolle.admin}s nur {rolle.admin}s).

## Als ehemalig eintragen
1. In der Mitgliederliste die Person antippen und **Konto und Rolle** öffnen.
2. Unter **Ehemalig** auf **Als ehemalig eintragen …** tippen.
3. Den **Grund des Ausscheidens** wählen: Ausgetreten, Verstorben oder Sonstiges. Optional das Datum des Ausscheidens (bei Verstorbenen den Todestag) und den Eintritt, jeweils als TT.MM.JJJJ oder nur das Jahr. Ist unter **Eintritt** schon ein Jahr eingetragen, steht es hier bereits. **Gründungsmitglied** ankreuzen, wenn es passt.
4. **Als ehemalig eintragen** tippen.

Danach hat die Person keine Rolle mehr: Sie steht nicht mehr in der Mitgliederliste, nicht in der öffentlichen Liste der {bereich.directory}, bekommt keine Benachrichtigungen, nimmt nicht an Umfragen teil und taucht bei den Geburtstagen nicht mehr auf. Sie wird auf allen Geräten abgemeldet. In der eigenständigen Version kann sie sich nicht mehr anmelden; mit WordPress bleibt der Benutzer der Webseite bestehen, hat in der App aber keine Rolle mehr. Zusatzrollen und Ausnahmen bei den Rechten merkt sich die App für ein späteres Wiederaufnehmen.

> Sind noch {bereich.penalties} offen, weist die App vorher darauf hin. Sie bleiben offen und stehen auch in der Liste der Ehemaligen, damit sie nicht in Vergessenheit geraten.

Ist ein Amt mit einem Titel verknüpft, verliert eine ehemalige Person diesen Titel beim nächsten Abgleich; ihre Amtszeiten bleiben im Zeitstrahl stehen.

## Die Liste der Ehemaligen
In der Verwaltung im Menü oben **Ehemalige** wählen. Oben steht, wie viele ausgetreten, verstorben und sonst ausgeschieden sind. Die Liste lässt sich durchsuchen und nach dem Grund filtern; unter **Sortierung** wählst du Nachname, Vorname, Eintritt (Gründungsmitglieder zuerst, wer keinen Eintritt hat, am Ende) oder Austritt (das Datum des Ausscheidens, auch bei Verstorbenen; wer keines hat, am Ende), daneben die **Reihenfolge**. Die Wahl gilt auch für die Mitgliederliste (dort ohne Austritt) und bleibt auf dem Gerät gemerkt. Jede Zeile zeigt Eintritt und Ausscheiden und, wer {bereich.penalties} sieht, offene Beträge. Ein Tipp öffnet die Seite der Person wie gewohnt; **‹ Ehemalige** führt zurück. Mit Wischen nach links oder rechts (oder den Pfeilen oben rechts) blätterst du wie in der Mitgliederliste zur nächsten bzw. vorherigen Person.

## Angaben ändern und wieder aufnehmen
Auf der Seite einer ehemaligen Person unter **Konto und Rolle**, Abschnitt **Ehemalig**:
- **Angaben ändern**: Grund, Daten und Gründungsmitglied korrigieren.
- **Wieder aufnehmen** (z. B. bei einem Wiedereintritt): Die Person bekommt ihre Rolle von vorher zurück, dazu ihre Zusatzrollen, soweit es sie noch gibt und du sie vergeben darfst; Ausnahmen bei den Rechten stellt nur ein {rolle.admin} wieder her. Gibt es die Rolle nicht mehr, wird sie {rolle.member}. Danach unter **Konto und Rolle** ein neues Startpasswort erzeugen, damit sie sich wieder anmelden kann.

Rolle und Passwort einer ehemaligen Person lassen sich erst nach dem Wiederaufnehmen ändern. **Konto löschen** (mit WordPress **Aus der App entfernen**) geht weiterhin und entfernt dann wirklich alle Daten.

## Ehemalige importieren (z. B. aus einer Chronik)
Gibt es schon eine Liste aller früheren Mitglieder, lässt sie sich auf einmal übernehmen: In der Liste der Ehemaligen auf **Tabelle importieren (z. B. Chronik)** tippen. Aktive und Ehemalige aus einer gemeinsamen Liste übernimmst du dagegen unter [Mitglieder anlegen → Aus Datei importieren](seite:mitglieder-anlegen) (Spalte „Status“ mit „ehemalig“).

Eine Zeile je Person, Spalten getrennt durch Semikolon oder Tabulator: `vorname;nachname;eintritt;ausgeschieden;grund`. Eine Kopfzeile mit genau diesen Namen ist erlaubt.
- **eintritt** und **ausgeschieden**: TT.MM.JJJJ oder nur das Jahr, oder leer. Eintritt „Gründer“ trägt ein Gründungsmitglied ohne Datum ein.
- **grund**: ausgetreten (auch leer), verstorben oder sonstiges. Bei Verstorbenen ist „ausgeschieden“ der Todestag.

Eine Excel-Datei (.xlsx) wählst du direkt aus (die App liest das erste Tabellenblatt), ebenso eine CSV-Datei; oder du kopierst die Zellen und fügst sie ins Feld ein. **Vorschau** zeigt jede Zeile mit dem, was die App verstanden hat, und Fehler je Zeile. Erst wenn keine Zeile einen Fehler hat, legt **Übernehmen** die Personen an.

Für jede Person entsteht ein Konto (ohne Startpasswort, also ohne Anmeldung), das gleich als ehemalig eingetragen ist. Wer schon ein Konto mit genau diesem Vor- und Nachnamen hat, wird übersprungen; ein zweiter Import mit derselben Tabelle legt deshalb niemanden doppelt an.

Steht eine importierte Person in den {bereich.offices} bisher nur mit ihrem Namen (ohne Konto), verknüpft der Import diesen Eintrag mit ihrem neuen Konto. Der Name muss genau passen (Groß- und Kleinschreibung egal); Abkürzungen wie „M. Muster“ bleiben, wie sie sind, und lassen sich dort von Hand zuordnen. Die Vorschau sagt vorher, wie viele Einträge verknüpft werden. Auch ein erneuter Import verknüpft Personen, die schon früher als ehemalig importiert wurden. Zwei Personen mit gleichem Namen in der Tabelle sind ein Fehler: Mach sie unterscheidbar, z. B. mit „I“ und „II“ am Nachnamen. Höchstens 500 Zeilen auf einmal.
