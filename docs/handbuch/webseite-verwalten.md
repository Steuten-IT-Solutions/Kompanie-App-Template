---
titel: Webseite verwalten
reihenfolge: 58
rechte: [manage_website]
bereiche: [website]
variante: standalone
---
Hier richtest du die Webseite für Besucher ein. Sie liegt unter der Adresse eures Webspace, zum Beispiel `eure-webseite.de`, während die App im Ordner darunter bleibt, zum Beispiel `eure-webseite.de/app/`. Die Webseite braucht keine Anmeldung, setzt keine Cookies und lädt nichts von fremden Servern. Als Startseite zeigt sie eine eurer eigenen Seiten aus {bereich.pages}, die für alle sichtbar ist.

## So öffnest du die Seite
Tippe unten auf **Verwaltung**, dann auf **Einrichtungen** und auf den Knopf **Webseite**. Mit **‹ Einrichtungen** kommst du zurück.

Den Knopf gibt es, sobald unter **Einstellungen → Bereiche** der Bereich **Webseite** an ist. Er braucht den Bereich {bereich.pages}.

## Was du hier siehst
- **Adresse**: ob die Einstiegsdatei schon da ist und unter welcher Adresse die Webseite erreichbar ist. Je nach Lage gibt es dazu den Knopf **Einstiegsdatei anlegen** oder **Webseite öffnen**.
- **Startseite**: welche Seite die Webseite zeigt, mit **Speichern**.

## So gehst du vor
### Die Einstiegsdatei anlegen
Die Einstiegsdatei ist eine kleine Datei `index.php` im Ordner über der App. Sie bindet nur die App ein und ändert sich mit Updates nicht.
1. Tippe unter **Adresse** auf **Einstiegsdatei anlegen**.
2. Die App meldet „Einstiegsdatei angelegt“. Danach steht dort die Adresse der Webseite und der Knopf **Webseite öffnen**.

Liegt in diesem Ordner schon eine andere Startseite, zum Beispiel eure bisherige Webseite, ersetzt die App sie nie. Sie nennt dann die Datei. Soll die neue Webseite die alte ablösen, sichere die alte Datei beim Hoster, benenne sie um oder lösche sie, und tippe danach auf **Einstiegsdatei anlegen**.

Darf die App im Ordner keine Dateien anlegen, zeigt sie den Inhalt der Datei. Lege dann im Dateimanager des Hosters im Ordner über der App eine Datei `index.php` mit genau diesem Inhalt an.

### Die Startseite wählen
1. Lege unter **Verwaltung → Einrichtungen → {bereich.pages}** eine Seite an und wähle bei **Für wen?** **Alle, auch ohne Anmeldung**. Dafür brauchst du das Recht, {bereich.pages} zu pflegen.
2. Wähle hier unter **Startseite** bei **Seite** diese Seite.
3. Tippe auf **Speichern**.

## Gut zu wissen
- Die Webseite zeigt die Seite so, wie Gäste sie in der App sehen. Blöcke nur für Mitglieder fehlen dort, ebenso Umfragen.
- Änderst du die Seite unter {bereich.pages}, zeigt die Webseite die Änderung sofort.
- Stellst du die Startseite auf **Nur Mitglieder** oder löschst du sie, zeigt die Webseite nur noch einen kurzen Hinweis mit dem Weg zur App. Hier steht dann, dass du eine andere Seite wählen sollst.
- Seiten mit dem Block **Ansprechpartner** zeigen Personen. Suchmaschinen sollen sie deshalb nicht aufnehmen.
- Im Fuß der Webseite stehen die Links auf Impressum und Datenschutzerklärung aus **Einstellungen → Impressum und Datenschutz**.
- Ist der Bereich **Webseite** aus, leitet die Adresse der Webseite zur App weiter.
- Die **Statusseite** unter **Einstellungen** zeigt, ob die Webseite erreichbar ist und eine Startseite hat.
- Die Webseite gibt es nur in der eigenständigen Version. Unter WordPress pflegt ihr eure Seiten weiter in WordPress.
