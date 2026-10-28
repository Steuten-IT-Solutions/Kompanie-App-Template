---
titel: Ehemalige
reihenfolge: 74
rechte: [create_users]
---
Wer austritt, verstirbt oder sonst ausscheidet, muss nicht gelöscht werden. Als ehemalig eingetragen bleibt das Konto mit allen Daten erhalten: {bereich.points}, {bereich.penalties}, Anwesenheit, {begriff.rank} mit Verlauf, {bereich.titles}, {bereich.offices}, Geburtstag und Foto. Unter **Ehemalige** findest du alle diese Personen.

![Liste der Ehemaligen in der Verwaltung](bild:ehemalige-mitglieder)

## So öffnest du die Seite
Tippe unten auf **Verwaltung** und dann auf **Ehemalige**.

## Was du hier siehst
- **Zähler** oben: wie viele ausgetreten, verstorben und sonst ausgeschieden sind.
- **+ Ehemalige Person anlegen**: jemanden eintragen, der schon vor der App ausgeschieden ist (siehe [Ehemalige Person anlegen](seite:ehemalige-anlegen)). Dort geht es auch zum Import vieler auf einmal.
- **Suchen …**: filtert die Liste nach dem Namen.
- **Filter und Sortierung**: zunächst eingeklappt. Darin filterst du bei **Alle Gründe** nach dem Grund und sortierst nach **Nachname**, **Vorname**, **Eintritt** oder **Austritt**, mit **Reihenfolge** daneben. Ist ein Filter gesetzt, steht **aktiv** daneben.
- **Die Liste**: je Person der Name, darunter „Gründungsmitglied“, Eintritt und Ausscheiden, soweit eingetragen, und offene {bereich.penalties}, wenn du sie siehst.

## So gehst du vor
### Jemanden als ehemalig eintragen
1. Öffne in der Mitgliederliste die Seite der Person.
2. Tippe unter den Kacheln auf **Austritt** (oder unter **Konto und Rolle** auf **Als ehemalig eintragen …**). Es öffnet sich das Fenster **Austritt**.
3. Wähle den **Grund des Ausscheidens**: **Ausgetreten**, **Verstorben** oder **Sonstiges**.
4. Trage, wenn bekannt, **Ausgeschieden am** (bei Verstorbenen **Verstorben am**) und den **Eintritt** ein, jeweils als TT.MM.JJJJ oder nur das Jahr. Ein schon eingetragener Eintritt steht dort bereits.
5. Setze den Haken bei **Gründungsmitglied**, wenn es passt.
6. Hat die Person laufende {bereich.offices}, steht darunter, welche mit dem Ausscheiden enden und mit welchem Jahr, z. B. „Mit dem Ausscheiden enden: Fahne (seit 2006) – Ende 2026.“
7. Tippe auf **Als ehemalig eintragen**.

![Fenster Austritt](bild:ehemalige-mitglieder-eintragen)

### Eine ehemalige Person öffnen
1. Tippe in der Liste auf die Person. Es öffnet sich ihre Seite wie bei aktiven Mitgliedern.
2. **‹ Ehemalige** führt zurück. Mit Wischen (oder **‹** und **›** oben rechts) blätterst du zur nächsten bzw. vorherigen Person; nach der letzten kommt wieder die erste.

### Angaben ändern oder wieder aufnehmen
1. Tippe auf der Seite der Person auf **Eintritt** oder **Austritt**, ändere Grund, Daten oder **Gründungsmitglied** und tippe auf **Speichern**.
2. Zum Wiederaufnehmen (z. B. bei einem Wiedereintritt) klappe **Konto und Rolle** auf und tippe unter **Ehemalig** auf **Wieder aufnehmen**.
3. Erzeuge danach unter **Konto und Rolle** ein neues Startpasswort, damit sie sich wieder anmelden kann.

### Ehemalige aus einer Tabelle übernehmen
1. Tippe auf **+ Ehemalige Person anlegen** und dann oben auf **Tabelle importieren (z. B. Chronik)**. **‹ Einzeln anlegen** führt zurück zum Formular.
2. Füge die Tabelle ins Feld **Tabelle** ein oder wähle bei **Oder Datei wählen (Excel .xlsx oder CSV)** eine Datei. Eine Zeile je Person, Spalten getrennt durch Semikolon oder Tabulator: `vorname;nachname;eintritt;ausgeschieden;grund`.
3. Tippe auf **Vorschau**. Sie zeigt jede Zeile mit dem, was die App verstanden hat, und Fehler je Zeile.
4. Hat keine Zeile einen Fehler, tippe auf **Übernehmen**.

![Vorschau beim Import der Ehemaligen](bild:ehemalige-mitglieder-import)

### Eine Aufbewahrungsfrist einstellen ({rolle.admin}s)
Personenbezogene Daten sollen nicht länger gespeichert werden als nötig. Unten auf der Seite stellt ein {rolle.admin} unter **Aufbewahrungsfrist** ein, nach wie vielen Jahren seit dem Ausscheiden die App Ehemalige endgültig löscht.
1. Trage bei **Jahre nach dem Ausscheiden** die Zahl der Jahre ein (leer oder 0: aus, so ist es voreingestellt).
2. Setze bei Bedarf **Gründungsmitglieder auch löschen** und **Verstorbene auch löschen**; ohne die Haken bleiben sie.
3. Tippe auf **Speichern** und bestätige. Darunter steht, wen die App beim nächsten Lauf (täglich) löscht und wer in den nächsten 30 Tagen dazukommt.

Gelöscht wird wie mit **Konto löschen**: Konto und alle Daten der Person, das lässt sich nicht rückgängig machen. Amtszeiten bleiben als Chronik mit dem Namen stehen. Stichtag ist **Ausgeschieden am** (nur ein Jahr: der 31.12.), ohne Datum der Tag, an dem die Person als ehemalig eingetragen wurde. Welche Frist für eure {kompanie.name} angemessen ist (z. B. wegen einer Chronik), klärt ihr am besten mit eurer bzw. eurem Datenschutzbeauftragten.

## Gut zu wissen
- Danach hat die Person keine Rolle mehr: Sie steht nicht mehr in der Mitgliederliste, nicht in der öffentlichen Liste der {rolle.member}, bekommt keine Benachrichtigungen, nimmt nicht an {bereich.polls} teil und taucht bei den {bereich.birthdays} nicht mehr auf. Sie wird auf allen Geräten abgemeldet und kann sich nicht mehr anmelden.
- Sind noch {bereich.penalties} offen, weist die App vorher darauf hin. Sie bleiben offen und stehen auch in der Liste der Ehemaligen.
- Laufende {bereich.offices} enden mit dem Ausscheiden, auch solche mit dem Haken **Ehrung**. Ende ist das Jahr von **Ausgeschieden am**; ist es leer, das aktuelle Jahr. Liegt das Ausscheiden vor dem Beginn einer Amtszeit, endet sie im Jahr ihres Beginns. Einzelne Jahre und schon beendete Amtszeiten bleiben, wie sie sind. Nichts wird gelöscht: Die Amtszeiten bleiben mit der Person im Zeitstrahl.
- Teilt die Person eine laufende Amtszeit mit anderen (z. B. Fahne), bekommt sie einen eigenen, beendeten Eintrag; die anderen bleiben im Amt.
- Ist ein Amt mit einem Titel verknüpft, verliert die Person diesen Titel mit dem Ausscheiden. Andere Titel behält sie.
- Änderst du später **Ausgeschieden am**, ziehen die beim Eintragen beendeten Amtszeiten mit: Sie enden dann mit dem neuen Jahr. Das gilt nicht für Amtszeiten, die inzwischen jemand unter {bereich.offices} von Hand geändert hat.
- **Wieder aufnehmen** öffnet beendete Amtszeiten nicht wieder. Hat die Person ein Amt weiter inne, trage unter {bereich.offices} eine neue Amtszeit ein oder entferne dort das Ende.
- Beim Wiederaufnehmen bekommt die Person ihre Rolle von vorher zurück, dazu ihre Zusatzrollen, soweit es sie noch gibt und du sie vergeben darfst. Ausnahmen bei den Rechten stellt nur ein {rolle.admin} wieder her. Gibt es die Rolle nicht mehr, wird sie {rolle.member}.
- Konten von {rolle.admin}s tragen nur {rolle.admin}s als ehemalig ein. Das eigene Konto kannst du nicht als ehemalig eintragen.
- Import: **eintritt** und **ausgeschieden** als TT.MM.JJJJ, nur das Jahr oder leer; „Gründer“ als Eintritt trägt ein Gründungsmitglied ohne Datum ein. **grund**: ausgetreten (auch leer), verstorben oder sonstiges. Eine Kopfzeile mit genau diesen Spaltennamen ist erlaubt. Höchstens 500 Zeilen auf einmal.
- Für jede importierte Person entsteht ein Konto ohne Anmeldung, das gleich als ehemalig eingetragen ist. Wer schon ein Konto mit genau diesem Vor- und Nachnamen hat, wird übersprungen; ein zweiter Import legt also niemanden doppelt an. Zwei Personen mit gleichem Namen in der Tabelle sind ein Fehler: Mach sie unterscheidbar, z. B. mit „I“ und „II“ am Nachnamen.
- Steht eine importierte Person in den {bereich.offices} bisher nur mit ihrem Namen, verknüpft der Import diesen Eintrag mit ihrem neuen Konto, wenn der Name genau passt (Groß- und Kleinschreibung egal). Die Vorschau sagt vorher, wie viele Einträge verknüpft werden.
- Aktive und Ehemalige aus einer gemeinsamen Liste übernimmst du unter [Mitglieder importieren](seite:mitglieder-import).

> **Für Fortgeschrittene:** In der WordPress-Version bleibt der Benutzer der Webseite bestehen, hat in der App aber keine Rolle mehr. Das gilt auch, wenn die Aufbewahrungsfrist die Person löscht: Name und E-Mail-Adresse löscht ein Administrator der Webseite im WordPress-Backend unter **Benutzer**.

## Siehe auch
- [Seite eines Mitglieds](seite:mitglied-uebersicht)
