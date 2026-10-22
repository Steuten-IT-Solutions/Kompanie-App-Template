---
titel: "{bereich.points} vergeben"
reihenfolge: 50
rechte: [manage_points]
bereiche: [points]
---
Hier trägst du {bereich.points} für ein oder mehrere Mitglieder auf einmal ein, z. B. für alle, die beim Arbeitsdienst geholfen haben. Auch Abzüge gehen hier.

![Das Formular zum Vergeben von Punkten](bild:punkte-vergeben)

## So öffnest du die Seite
Tippe unten auf **Verwaltung** und dann auf den Knopf **{bereich.points} +**. Mit **‹ Verwaltung** kommst du zurück zur Startseite der Verwaltung.

## Was du hier siehst
- **Vorlage aus dem Katalog**: häufige Anlässe mit fester Punktzahl. „– freie Eingabe –“ heißt: ohne Vorlage.
- **{bereich.points}**: die Punktzahl, eine ganze Zahl. Für einen Abzug schreibst du ein Minus davor.
- **Datum**: heute ist vorausgewählt.
- **Grund**: steht später beim Mitglied im Verlauf.
- **Mitglieder**: alle Mitglieder zum Ankreuzen, mit **Mitglied suchen …**, **Alle** (bzw. **Keine**) und darunter, wie viele ausgewählt sind.
- **{bereich.points}faktor der Rolle anwenden**: erscheint nur, wenn jemand Ausgewähltes eine Rolle mit einem anderen Faktor als 1 hat.

## So gehst du vor
### {bereich.points} vergeben
1. Wähle unter **Vorlage aus dem Katalog** eine Vorlage. Sie füllt **{bereich.points}** und **Grund** aus; beides kannst du noch ändern. Ohne Vorlage trägst du beides selbst ein.
2. Ändere das **Datum**, wenn der Anlass nicht heute war.
3. Kreuze die Mitglieder an. Bei vielen Namen hilft die Suche; **Alle** kreuzt alle gefundenen an.
4. Tippe auf **{bereich.points} vergeben**. Unten erscheint kurz, wie viele Mitglieder die {bereich.points} bekommen haben.

![Formular mit Vorlage und zwei ausgewählten Mitgliedern](bild:punkte-vergeben-ausgefuellt)

### {bereich.points} abziehen
1. Wähle „– freie Eingabe –“ und trage die Punktzahl mit Minus ein, z. B. -2.
2. Trage den **Grund** ein, kreuze die Mitglieder an und tippe auf **{bereich.points} vergeben**.

### Vorschlag aus einer {bereich.attendance}sliste übernehmen
Wer zusätzlich die {bereich.attendance} erfasst, kann aus einer {bereich.attendance}sliste heraus {bereich.points} vergeben. Dann öffnet sich dieses Formular mit einem Vorschlag:
1. Oben steht der Rückweg „‹ …“ mit dem Namen der Liste, darunter „Vorschlag: alle Anwesenden, die für … noch keine {bereich.points} haben.“ Diese Mitglieder sind schon angekreuzt.
2. **Grund** und **Datum** kommen aus der Liste. Wählst du eine Vorlage, heißt der Grund „Vorlage: Name der Liste“.
3. Prüfe die Auswahl und tippe auf **{bereich.points} vergeben**. Danach bist du wieder bei der Liste.

## Gut zu wissen
- Die Punktzahl darf nicht 0 sein und höchstens 10.000 (bzw. -10.000) betragen. Der Grund ist Pflicht.
- {bereich.points} bekommen nur Mitglieder, keine Gäste.
- Aus einer {bereich.attendance}sliste bekommt niemand zweimal {bereich.points}: Wer für diese Liste schon welche hat, wird übersprungen.
- **{bereich.points}faktor**: Manche Rollen haben einen Faktor, z. B. ×0,5 für junge Mitglieder. Er steht in der Auswahl hinter dem Namen, z. B. „×0,5“. Mit Häkchen bei **{bereich.points}faktor der Rolle anwenden** zählt der Eintrag entsprechend weniger (oder mehr); ohne Häkchen zählt er für alle voll. Hat jemand mehrere Rollen, gilt der kleinste Faktor. Den Faktor legt ein {rolle.admin} bei den Rollen in den Einstellungen fest.
- Wer Benachrichtigungen eingeschaltet hat, bekommt eine Nachricht mit Wert und Grund.
- Einen falschen Eintrag stornierst du auf der Seite des Mitglieds.

## Siehe auch
- [{bereich.points}katalog](seite:punktekatalog)
- [{bereich.points} eines Mitglieds](seite:mitglied-punkte)
- [Benachrichtigungen](seite:benachrichtigungen)
