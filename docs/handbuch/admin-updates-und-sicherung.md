---
titel: Updates und Datensicherung
reihenfolge: 130
rechte: [manage_settings]
---
## Updates
Neue Versionen kommen automatisch, solange **Updates automatisch einspielen (empfohlen)** eingeschaltet ist. Ausnahmen: Eine neue Hauptversion spielt die App nur ein, wenn du sie bestätigst, und mit WordPress kann die Webseite automatische Updates sperren; dann spielst du sie hier per Klick ein. Unter **Verwaltung → Einstellungen → Zustand und Updates** siehst du die installierte Version und kannst ein Update sofort einspielen. Schlägt ein Update fehl, bleibt die bisherige Version. Unter **Auf eine ältere Version zurück** wählst du eine frühere Version derselben Hauptversion; hinter eine neue Hauptversion zurück geht es nicht per Klick, sondern nur mit Hilfe der Betreuer.

### Vorabversionen
Mit **Vorabversionen erhalten (zum Testen)** bekommt diese Installation jede neue Version, sobald sie gebaut ist – noch bevor sie für alle freigegeben wird. Das ist für eine Testseite gedacht: Vorabversionen können noch Fehler enthalten. Schaltest du es wieder aus, bleibt die jetzige Version installiert, bis eine neuere für alle freigegeben ist.

## Datensicherung
Unter **Einstellungen → Datensicherung** lädst du eine Sicherung aller App-Daten und Einstellungen herunter. Bewahre sie an einem sicheren Ort auf: Sie enthält die Daten der Mitglieder. Die Ampel wird gelb, wenn noch nie eine Sicherung heruntergeladen wurde oder die letzte älter als 90 Tage ist.

Wiederherstellen: Mit WordPress nur auf derselben Webseite. Ohne WordPress auch auf einer neuen Installation (z. B. beim Umzug), aber nicht aus einer Sicherung des WordPress-Plugins.

Die Dateien der Dokumente und die Abzeichen der {bereich.ranks} sind nicht in der Sicherung, nur ihre Angaben (Titel, Kategorie, Stand bzw. welcher {begriff.rank} welches Abzeichen hat). Sie bleiben auf dem Server. Beim Umzug ohne WordPress nimm die Ordner `data/files/dokumente` und `data/files/abzeichen` mit (bzw. das Datenverzeichnis, falls es woanders liegt), sonst lassen sich die Dokumente danach nicht öffnen und müssen neu hochgeladen werden; fehlende Abzeichen zeigt die App nicht an, bis du sie neu hochlädst.
