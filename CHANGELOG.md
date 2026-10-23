# Changelog

Was sich in der Kompanie-App von Version zu Version ändert, in Worten für Admins. Admins sehen diese Liste auch in der
App unter *Einstellungen → Was ist neu*, jeweils passend zur installierten Version.

Pflege (siehe [docs/RELEASE.md](docs/RELEASE.md)):

- Jeder Pull Request nach `develop`, der etwas für die Nutzer der App ändert, ergänzt einen Eintrag unter
  `[Unveröffentlicht]`, im passenden Unterabschnitt: `Neu`, `Geändert`, `Behoben`, `Entfernt` oder `Sicherheit`.
  Interne Änderungen (Tests, CI, Werkzeuge zum Veröffentlichen) gehören nicht hierher.
- Wer `VERSION` erhöht, macht aus `[Unveröffentlicht]` den Abschnitt `## [x.y.z] – JJJJ-MM-TT` und legt darüber einen
  neuen, leeren `[Unveröffentlicht]` an. Ein Release `develop → main` ist nur mit diesem Abschnitt möglich
  (`php scripts/changelog.php --check --require-version`).
- Die Version ist die Kern-Version (`1.4.0`), nicht die einer Kompanie (`1.4.0.27`).

## [Unveröffentlicht]

### Neu

- **Hilfe als Bedienungsanleitung zu jedem Bildschirm**: Das „?“ oben öffnet jetzt die Anleitung genau zu der Seite,
  auf der man gerade ist – mit Bildschirmfotos aus der eigenen App (in euren Farben und mit euren Bezeichnungen) und
  Schritt für Schritt erklärt: wozu die Seite da ist, wie man sie öffnet, was man dort sieht und wie man vorgeht. Die
  Texte sind neu geschrieben, verständlich und ohne Technik. Dasselbe Handbuch mit Fotos steht auf der Download-Seite.
- **Konten in der Datensicherung** (WordPress): Die Sicherung enthält jetzt auch die Benutzer der Webseite mit
  Benutzername, E-Mail, Namen, Passwort (als Prüfwert) und Rollen. Ist die Webseite verloren, legt das Wiederherstellen
  auf einer neu installierten WordPress-Seite unter derselben Adresse die Konten mit ihrer bisherigen Nummer wieder an;
  alle melden sich wie gewohnt an. Vorhandene Konten bleiben, wie sie sind, und bekommen nur ihre Rolle in der App
  zurück. Passen Konten nicht zusammen, ändert das Wiederherstellen nichts und sagt, welches Konto stört.
- **Dokumente, Abzeichen und Benachrichtigungen in der Datensicherung**: Die Sicherung enthält jetzt auch die Dateien
  der Dokumente und die Abzeichen der Dienstgrade sowie die Anmeldungen der Geräte für Benachrichtigungen. Nach dem
  Wiederherstellen auf einem neuen Server lassen sich die Dokumente öffnen, und Benachrichtigungen kommen weiter an.
- **Sicherungen in Google Drive**: Unter *Einstellungen → Datensicherung* legt die App auf Wunsch einmal pro Woche eine
  Sicherung in einem eigenen Ordner im verbundenen Google-Konto ab und behält die letzten acht. So liegt auch nach dem
  Verlust des Servers eine aktuelle Sicherung woanders. Dafür einmal unter *Google-Zugang* die Verbindung trennen und
  neu verbinden und dabei das Häkchen für eigene Dateien in Google Drive setzen.
- **Mitglieder aus Excel oder CSV übernehmen**: In der Ersteinrichtung und unter *Verwaltung → Mitglied anlegen →
  Aus Datei importieren* legt die App alle Personen einer Mitgliederliste auf einmal an, mit Status (Mitglied, eine
  andere Rolle wie Anwärter oder ehemalig), E-Mail, Geburtstag, Eintritt und auf Wunsch Punkten, Dienstgrad und Titeln.
  Die Vorschau zeigt Fehler je Zeile, danach stehen die Zugangsdaten zum Ausdrucken bereit; Ehemalige bekommen ein Konto
  ohne Anmeldung. Wer schon ein Konto mit demselben Namen hat, wird übersprungen.
- **Ehemalige importieren** nimmt jetzt auch Excel-Dateien (.xlsx).
- **Wischen auch im Tab „Mitglieder“**: Auf der Seite einer Person blättert Wischen wie in der Verwaltung zur nächsten
  bzw. vorherigen Person der Liste (mit Suche und Sortierung), auch bei den Ehemaligen; am Computer mit den Pfeilen oben
  rechts. **‹ Zurück** führt weiter direkt zur Liste.
- **Ganze Liste vorgeladen**: Auf der Seite einer Person lädt die App jetzt nacheinander alle Personen der Liste vor,
  zuerst die direkten Nachbarn, dann immer weiter nach außen. Ist eine Seite beim Wischen doch noch nicht geladen,
  steht ihr Name schon da, darunter das Gerüst, und man kann gleich weiterwischen.
- **Ehemalige einzeln anlegen**: Unter *Verwaltung → Ehemalige* steht jetzt **+ Ehemalige Person anlegen**, aufgebaut wie
  **+ Mitglied anlegen**: Name, Grund, Eintritt, Ausscheiden, Gründungsmitglied und auf Wunsch Geburtstag und Foto. Es
  entsteht ein Konto ohne Anmeldung, das gleich als ehemalig eingetragen ist. **Tabelle importieren (z. B. Chronik)**
  steht jetzt oben in diesem Formular.
- **Zwischen Mitgliedern wischen**: Auf der Seite einer Person in der Verwaltung blättert Wischen nach links bzw.
  rechts zur nächsten bzw. vorherigen Person, auch bei den Ehemaligen; am Computer gibt es dafür Pfeile oben rechts
  mit der Position („3 / 25“). Die Reihenfolge ist die der Liste mit Suche, Filtern und Sortierung. Die Seite folgt
  dabei dem Finger, daneben ist die nächste Person schon zu sehen, und weil die App die Nachbarn vorlädt, steht deren
  Seite nach dem Wechsel sofort da, ohne Laden. Die Liste ist ein Karussell: Nach der letzten Person kommt wieder die
  erste und umgekehrt (auch im Tab „Mitglieder“). Gewischt werden kann überall auf der Seite, auch während sie noch lädt.
- **Ehemalige nach Austritt sortieren**: Die Liste der Ehemaligen (in der Verwaltung und in der Mitgliederliste) lässt
  sich jetzt auch nach dem Datum des Ausscheidens sortieren, früheste oder neueste zuerst; wer keines hat, steht am Ende.
- **Platzhalterbild für Mitglieder ohne Foto auf der Webseite** (WordPress): Im WP-Admin unter *Einstellungen* auf der
  Seite der App lässt sich ein Bild aus der Mediathek wählen, z. B. eine Silhouette oder das Logo. `[kompanie_mitglieder]`
  zeigt es bei Personen ohne Foto statt der Anfangsbuchstaben. In der App bleiben die Anfangsbuchstaben.

### Geändert

- **„Meins → Übersicht“ wie die Seite eines Mitglieds in der Verwaltung**: Name, darunter Dienstgradabzeichen und Foto,
  Rolle, die Kacheln Punkte und Offen, die Titel, Geburtstag und Eintritt sowie die Ämter als Zeitstrahl. Antippen
  führt zur passenden Seite (Kacheln, Abzeichen) bzw. zu „Konto“ (Geburtstag, Eintritt); das Foto vergrößert sich.
  Die eigenen Ämter stehen jetzt in der Übersicht statt auf einer eigenen Seite im Menü.
- **Verwaltung mit Startseite**: Statt eines Aufklappmenüs zeigt die Verwaltung zuerst eine Übersicht, in der jeder
  Bereich (Mitglieder, Punkte, Dienstgrade, Einstellungen …) ein eigener Knopf mit Symbol ist. **‹ Verwaltung** führt
  von jeder Seite zurück. Wer nur einen Bereich pflegt, landet wie bisher direkt dort.
- **Anwärter in der Mitgliederliste erkennbar**: In der Mitgliederliste der Verwaltung steht bei Personen, deren
  Rolle das Häkchen *Als Mitglied aufnehmen* hat (z. B. Anwärter), der Name der Rolle neben dem Namen wie ein Titel.
- **Geburtstag und Eintritt auf der eigenen Seite**: Unter *Konto* steht die Auswahl „Auf meiner Seite …“ jetzt
  von Anfang an auf **Mitgliedern anzeigen** (bisher „Nicht anzeigen“); nur angemeldete Mitglieder sehen die Angabe.
  Das gilt auch für alle schon eingetragenen Geburtstage und Eintritte, die auf „Nicht anzeigen“ standen. Wer sie
  nicht zeigen will, stellt unter *Konto* wieder „Nicht anzeigen“ ein.
- **Eintritt und Austritt mit ganzem Datum**: Auf der Seite eines Mitglieds steht der Eintritt jetzt so, wie er
  eingetragen ist, z. B. „Eintritt 01.04.2005“ (bisher nur das Jahr). Bei den Ehemaligen stehen Eintritt und
  Ausscheiden ebenso mit ganzem Datum, z. B. „01.04.1985 – 03.05.2010“; wer dort nur Jahre eingetragen hat, sieht
  weiter nur die Jahre. Das gilt für alle, die die Angaben sehen dürfen, bei den Ehemaligen also auch ohne Anmeldung.
- **Liste der Ehemaligen**: *Filter und Sortierung* ist wie in der Mitgliederliste zunächst eingeklappt und klappt mit
  einem Tipp auf. Ist der Filter nach dem Grund gesetzt, steht am eingeklappten Bereich „aktiv“.
- **Erinnerung an die Datensicherung** schon nach 30 statt 90 Tagen ohne Sicherung außerhalb des Servers
  (heruntergeladen oder in Google Drive).
- **Seite eines Mitglieds in der Verwaltung ohne Menü**: Alles steht auf einer Seite, geändert wird durch Antippen.
  Das Abzeichen öffnet Befördern und Degradieren, das Foto die große Ansicht mit „Foto ersetzen“ und „Foto
  entfernen“, die Kacheln Punkte und Offen ein Fenster mit dem Formular nur für diese Person und ihren Einträgen,
  die neue Kachel Titel die Auswahl der Titel. Darunter stehen Geburtstag, Eintritt und Austritt (Austritt trägt als
  ehemalig ein bzw. ändert die Angaben), dann die Ämter als Zeitstrahl und zum Aufklappen „Konto und Rolle“. Die
  Knöpfe unter dem Foto und unter den Kacheln entfallen. Gilt auch für Ehemalige.
- **Seiten beim Laden**: Statt „Lädt …“ steht eine Seite, deren Daten noch nicht da sind, sofort in ihrem gewohnten
  Aufbau da (Karten, Zeilen, Knöpfe als graue Platzhalter), bis die Daten kommen. Die App merkt sich dafür nur den
  Aufbau, keine Inhalte; beim Abmelden wird er gelöscht.
- **Punkte in der Mitgliederliste der Verwaltung** stehen nur noch, wenn die Liste nach Punkten sortiert ist, wie
  schon der Eintritt bei der Sortierung nach Eintritt. Offene Strafen stehen weiter immer da.
- **Shortcodes auf der Webseite**: `[kompanie_mitglieder]` sieht aus wie eine Bildergalerie: Fotos im Hochformat
  (scharf, auch auf Handys), Name als Balken im Bild, ohne Dienstgrad, nach Namen sortiert, groß beim Antippen. In
  `[kompanie_aemter]` sind die Fotos schärfer und oben ausgerichtet, damit der runde Ausschnitt keine Gesichter
  abschneidet. Schrift steht in der Textfarbe der Webseite, damit sie auch mit dunklem Hintergrund lesbar ist.
- **„Konto und Rolle“ in den Mitgliedsdetails** sieht beim Auf- und Zuklappen aus wie die Kategorien unter
  Dokumente: Überschrift mit Linien links und rechts und einem Pfeil, der beim Aufklappen nach unten zeigt.
- **`[kompanie_mitglieder]` ohne Anwärter**: Die Galerie auf der Webseite zeigt nur noch Mitglieder. Anwärter und
  andere Personen nur mit dem internen Kalender stehen weiter in der Mitgliederliste der App.

### Behoben

- **Shortcodes mittig**: Manche Themes (z. B. Astra) rückten `[kompanie_mitglieder]`, `[kompanie_termine]` und die
  Inhaber in `[kompanie_aemter]` nach rechts ein; links war mehr Platz als rechts. Die Listen stehen jetzt bündig.

### Behoben

- **Eingabefelder in Umfragen**: Die Felder *Frage* und *Antwortmöglichkeiten* sowie *Ende* beim Anlegen oder
  Bearbeiten einer Umfrage sehen jetzt aus wie alle anderen Eingabefelder der App (bisher graue, eckige Kästen).

## [0.2.0] – 2026-10-22

### Neu

- **Ämter und Mitglieder auf der Webseite** (WordPress): Die Shortcodes `[kompanie_aemter]` und
  `[kompanie_mitglieder]` zeigen die Ämter mit Inhabern und Chronik bzw. die Mitgliederliste mit Foto und Dienstgrad,
  in den Farben der Kompanie und mit denselben öffentlichen Angaben wie die App. `[kompanie_aemter gruppe="…"]` zeigt
  nur die Ämter einer Gruppe. So steht alles nur noch an einer Stelle, in der App.
- **Listen** (neuer Bereich, anfangs aus): Listen zum Ausdrucken, als PDF oder als CSV für Excel, z. B.
  Unterschriften-, Teilnehmer- oder Abhaklisten, unter *Verwaltung → Listen* mit dem neuen Recht dafür. Titel und
  Spalten wählt man selbst: Angaben der Mitglieder (Name, Dienstgrad, Titel, Punkte …, jeweils nur, soweit man sie in
  der App sehen darf) oder leere Spalten zum Ausfüllen, auch mit Kästchen. „Mitglieder aktualisieren“ legt Zeilen für
  alle Mitglieder an und entfernt ausgeschiedene; eigene Zeilen kommen von Hand dazu. Der Ausdruck ist gestaltet wie
  die Tabellen der Kompanie, mit schräg gestellten Überschriften schmaler Spalten.
- **Mitgliederliste für alle** (Bereich „Mitglieder“, anfangs aus): Liste aller Mitglieder und Anwärter mit Name,
  Foto, Dienstgrad und Titeln, mit Suche und Sortierung nach Vor- oder Nachname, ohne Punkte, Strafen oder Rollen.
  Darüber steht die Anzahl je Gruppe. Ein Tipp auf eine Person öffnet ihre Seite mit ihren Ämtern.
- **Ämter** (neuer Bereich): Gruppen, Ämter und Amtszeiten in Jahren mit einem oder mehreren Inhabern, als Konto oder
  freier Name. Die Chronik ist öffentlich und steht im Tab „Mitglieder“ als Ehrentafel. Pflege unter
  *Verwaltung → Ämter* mit dem neuen Recht dafür, mit CSV-Import für die Ersterfassung. Ein Amt kann einen Titel
  automatisch vergeben und wieder entziehen.
- **Ehemalige Mitglieder**: Wer ausscheidet, wird als ehemalig eingetragen statt gelöscht. Das Konto behält alle Daten,
  erscheint aber nicht mehr in Listen, Umfragen, Geburtstagen und Benachrichtigungen; in der eigenständigen Version ist
  die Anmeldung gesperrt. „Wieder aufnehmen“ stellt die Rollen wieder her. Ehemalige lassen sich aus einer Chronik
  (CSV, mit Vorschau) importieren und werden dabei mit gleichnamigen Einträgen in den Ämtern verknüpft. Die Liste steht
  unter *Verwaltung → Ehemalige*.
- **Ehemalige in der Mitgliederliste**: Neuer Tab „Ehemalige“ rechts neben „Ämter“, für alle sichtbar wie die
  Mitgliederliste: Foto, Name, Dienstgrad und Titel, dazu nur „Gründungsmitglied“ und die Jahre von Eintritt und
  Ausscheiden, nie der Grund. In den Ämtern öffnet der Name einer ehemaligen Person ihre Seite.
- **Geburtstag und Eintritt auf der Seite eines Mitglieds**: Jedes Mitglied wählt unter *Konto*, ob sein Geburtstag
  und sein Eintrittsjahr auf seiner Seite in der Mitgliederliste stehen: nicht (Standard), nur für angemeldete
  Mitglieder oder öffentlich. Beim Geburtstag stehen Jahr und Alter nur mit „Alter anzeigen“. Das Eintrittsjahr trägt
  das Mitglied selbst oder die Verwaltung ein (*Mitglied → Eintritt*); beim Eintragen als ehemalig wird es als
  Eintritt vorgeschlagen.
- **Meins → Übersicht**: Foto, Name, Rollen, Dienstgrad, Punkte und offene Strafen auf einen Blick, dazu die eigenen
  Titel als Kachel.
- **Fotos vergrößern**: Ein Tipp auf ein Mitgliedsfoto zeigt es groß und scharf (auch bei hoher Pixeldichte).
- **Rollen ordnen**: Alle Rollen, auch Gast, Mitglied und Admin, lassen sich unter *Einstellungen → Rollen* per Ziehen
  sortieren, z. B. Anwärter zwischen Gast und Mitglied. Admins haben einen eigenen Punktefaktor.

### Geändert

- **Anwärter** ist keine feste Rolle mehr, sondern eine eigene Rolle mit zwei Einstellungen: „Beim Anlegen neuer Konten
  wählbar“ und „Als Mitglied aufnehmen“. Bestehende Installationen behalten ihre Anwärter mit denselben Rechten.
- Dienstgrad und Abzeichen stehen in der Mitgliederliste und den Mitgliedsdetails für alle, die die Liste sehen dürfen
  (bisher nur für die, die Dienstgrade verwalten).
- Mitgliederliste und Mitgliedsdetails neu angeordnet: Foto links, Dienstgrad rechts, Name und Titel mittig, größere
  Fotos und Abzeichen. Runde Fotos werden von oben zugeschnitten, damit der Kopf sichtbar bleibt.
- Zähler je Gruppe über der Mitgliederliste und in der Anwesenheit: in umgekehrter Reihenfolge der Rollen (von unten nach oben), mit Mehrzahl
  („Mitglieder 45“).
  Anwärter werden immer angezeigt und mitgezählt.
- Die App zeigt gespeicherte Daten sofort und aktualisiert sie im Hintergrund, statt bei schwachem Netz bei
  „Lädt …“ stehen zu bleiben. Wechsel zwischen Unterseiten (Mitglied, Meins, Dokumente, Anwesenheit) ohne Neuladen.
- **Sortierung nach Eintritt**: Die Mitgliederliste der Verwaltung (wer Mitglieder anlegen darf), die Liste der
  Ehemaligen in der Verwaltung und die Mitgliederliste für alle (Tabs „Mitglieder“ und „Ehemalige“) lassen sich nach
  Eintritt sortieren, früheste oder neueste zuerst. Wer keinen Eintritt hat, steht am Ende, Gründungsmitglieder ohne
  Datum vorn. In der Mitgliederliste für alle zählt nur das Jahr, das eine Person auf ihrer Seite zeigt.
- **Eintritt mit Tag und Monat**: Den Eintritt aktiver Mitglieder kann man unter *Konto → Eintritt* und im Mitglied
  unter *Eintritt* jetzt als ganzes Datum (TT.MM.JJJJ) oder weiter nur als Jahr eintragen, wie bisher schon bei den
  Ehemaligen. Auf der Seite in der Mitgliederliste steht weiterhin nur das Jahr. Beim Eintragen als ehemalig übernimmt
  die App das ganze Datum.

### Behoben

- **Shortcode `[kompanie_aemter gruppe="…"]`**: erkennt die Gruppe auch mit typografischen Anführungszeichen
  (z. B. `gruppe=„Kompanie“` aus Word oder dem Editor), auch bei Namen mit Leerzeichen.
- Galerie: Kamera-RAW- und TIFF-Bilder erscheinen auch, wenn der Server sie nicht lesen kann (eingebettete Vorschau
  oder Vorschaubild von Google Drive).
- Titel-Abzeichen behalten ein „ß“ (keine automatischen Großbuchstaben mehr).
- Rollen-Abzeichen in der Anwesenheit erscheinen so, wie sie eingegeben wurden.

## [0.1.1] – 2026-10-21

### Neu

- **Foto für Mitglieder**: im eigenen Profil und beim Anlegen eines Mitglieds hochladen, ersetzen oder entfernen.
- Mitgliederliste der Verwaltung: Gäste und Anwärter im Rollenfilter, zusätzlich Filter nach Titel.

### Geändert

- Die Mitgliederliste der Verwaltung zeigt keine Rollen mehr je Person.

## [0.1.0] – 2026-10-21

### Neu

- Erste Version der Kompanie-App: eine installierbare Web-App (PWA) als WordPress-Plugin oder als eigenständige Version
  mit SQLite oder MySQL/MariaDB, mit Name, Logo, Farben und Bezeichnungen der eigenen Kompanie.
- Bereiche, die der Admin einzeln einschaltet: Termine, Kalender für Mitglieder (Google Kalender oder iCal), News,
  Galerie aus Google Drive, Punkte, Strafen, Dienstgrade mit Abzeichen, Titel (Ehrentitel und Ämter), Anwesenheit,
  Dokumente, Schwarzes Brett mit dringenden Nachrichten, Umfragen, Geburtstage und Inventar.
- Rollen frei anlegen und Rechte zuweisen, Punktefaktor je Rolle, Zusatzrollen, Rechte einzelner Personen zusätzlich
  geben oder entziehen, Vorschau der App als eine bestimmte Rolle.
- Konten verwalten: mehrere auf einmal anlegen, ändern, löschen, Benutzernamen nachträglich ändern.
- Benachrichtigungen per Web Push für Mitglieder und Gäste.
- Für Admins: Zustand der App mit Ampel, Updates mit Zurückrollen, Vorabversionen zum Testen, Datensicherung,
  Wartungsmodus, Fehlerprotokoll, Statusmeldungen (nur mit Zustimmung) und „Fehler melden“.
- Handbuch in der App hinter „?“ auf jeder Seite, passend zu Rechten und Bereichen.
