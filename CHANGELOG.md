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

- **Wer sieht was, je Knopf einstellbar**: Für jeden Knopf unter **Intern** (Mitglieder, Ämter, Ehemalige,
  Dokumente, Schwarzes Brett, Dienstgrade, Punktekatalog), für die Tabs unten (Termine, News, Galerie, Intern) und für
  jeden Teil des Mitgliederbereichs (eigene Punkte, Strafen, Dienstgrad und Anwesenheit, interner Kalender, interne
  Bilder, Geburtstage, Umfragen) gibt es jetzt ein eigenes Recht. Der Server prüft es: Wer es nicht hat, sieht den
  Knopf nicht und kommt auch über einen Link nicht an die Daten.
- **Übersicht „Wer sieht was“**: Unter *Einstellungen → Rollen* zeigt eine Tabelle alle Rechte für alle Rollen auf
  einen Blick. Die Haken lassen sich dort direkt setzen und gemeinsam speichern.
- **Gast und Mitglied einstellbar**: Bei den festen Rollen **Gast** und **Mitglied** lässt sich jetzt einstellen, was
  sie sehen. **Gast** gilt auch für alle, die die App ohne Anmeldung öffnen, und für die Shortcodes auf der Webseite.
- **E-Mails: Mailgruppen**: Unter *Verwaltung → E-Mails → Mailgruppen verwalten* lassen sich Empfängergruppen aus
  Rollen, einzelnen Mitgliedern und freien Adressen anlegen (z. B. Vorstand) und beim Schreiben wie eine Rolle auswählen.
  Zwei Standardgruppen pflegt die App selbst: **Alle Mitglieder** (alle, die zur Kompanie gehören, auch Admins und eigene
  Rollen wie Spieß, ohne Anwärter) und **Alle Konten** (alle außer Gästen). Bisher erreichte eine Mail an die Rolle
  „Mitglied“ keine Admins und Spieße. Ehemalige bekommen nie eine Mail, auch wenn sie noch in einer Gruppe stehen.
- **Querformat**: Die App dreht sich mit dem Handy, auch als installierte App. Quer steht die Leiste mit den Bereichen
  links, Kopfleiste und Hinweise oben sind flacher, und die Inhalte nutzen die ganze Breite: Listen stehen in zwei
  Spalten, in Formularen je zwei Felder nebeneinander, die Kacheln der Verwaltung in vier Spalten. Längere Texte (News,
  Hilfe) bleiben gut lesbar schmal. Hochkant, auf Tablets und am Computer ändert sich nichts. Eine schon installierte
  App übernimmt die Drehung, sobald das Handy die App-Angaben neu einliest (beim Öffnen, spätestens nach einem Tag).

### Geändert

- **Rechte in Gruppen**: Die Rechte einer Rolle und einer einzelnen Person stehen jetzt in Gruppen (Tabs und
  Navigation, Mitgliederbereich, Intern, Inhalte verwalten, Kompanie verwalten, Mitglieder und Rollen). Jede Gruppe lässt
  sich auf- und zuklappen; ein Haken vor der Gruppe setzt oder entfernt alle ihre Rechte auf einmal, ein Zähler zeigt,
  wie viele davon gesetzt sind.
- **Mitgliederbereich aufgeteilt**: Das frühere Recht *Mitgliederbereich* heißt jetzt **Gehört zur Kompanie** und
  bedeutet nur noch, dass die Person Punkte, Strafen, Dienstgrade und Titel bekommt und in Listen steht. Was sie sieht,
  regeln die einzelnen Rechte. Beim Update bekommt jede Rolle und jede Ausnahme genau die Rechte, mit denen alle dasselbe
  sehen wie vorher.
- **Ämter nach Jahren auch auf dem Handy**: Den Schalter „Chronik | Jahre“ im Tab Ämter gibt es jetzt auf jedem
  Bildschirm. Auf dem Handy ist die Übersicht schmaler; quer gedreht nutzt sie die ganze Breite, sodass mehr Ämter
  nebeneinander passen. Nach oben und unten scrollt die ganze Seite, seitlich die Übersicht; dabei bleiben oben die
  Ämter und links die Jahre stehen. Ein Tipp auf einen Balken zeigt unten Jahre, Personen und Zusatz.
- **Benachrichtigung „Termin geändert“ zeigt die Änderung**: Statt noch einmal aller Angaben zum Termin steht in der
  Nachricht nur, was sich geändert hat, mit vorher und jetzt, z. B. „Probe – Uhrzeit: 19:00 Uhr → 20:00 Uhr“ oder
  „Ort: Halle → Sporthalle“. Das gilt für öffentliche Termine und für den internen Kalender.

### Behoben

- **Mitgliederliste für die Anwesenheit**: Wer nur die Anwesenheit führt, sah in der Verwaltung den Knopf
  **Mitglieder**, bekam beim Öffnen aber eine Fehlermeldung. Jetzt öffnet sich die Liste mit Namen, Rollen, Fotos und
  Dienstgraden, ohne Punkte und Strafen.
- **Benachrichtigungen über geänderte Kalendertermine kamen spät**: Ob sich im Google Kalender etwas geändert hat,
  prüft die App alle 15 Minuten. Dabei nahm sie bisher die zwischengespeicherten Termine, solange sie nach
  **Aktualisieren alle … Minuten** noch als aktuell galten, und verschickte die Nachricht erst im nächsten Lauf der
  Hintergrundaufgaben. Mit einer langen Aktualisierungszeit oder ohne häufige Seitenaufrufe konnte eine Nachricht so
  weit über eine Stunde brauchen. Jetzt fragt die App bei jeder Prüfung bei Google nach und verschickt die Nachricht
  gleich im selben Lauf.

## [0.3.0] – 2026-10-26

### Neu

- **Schwarzes Brett, Dienstgrade und Punktekatalog unter „Intern“**: Unter **Intern** gibt es drei neue Knöpfe.
  **Schwarzes Brett** öffnet das Brett mit allen aktuellen Nachrichten, ohne den Umweg über das Banner (für Mitglieder
  und Anwärter mit dem Recht **Intern**; wer am Brett schreibt, findet es weiter auch in der Verwaltung).
  **Dienstgrade** zeigt alle Dienstgrade der Reihe nach mit Abzeichen, den eigenen markiert und, wenn die
  Kompanie Punkte nutzt, ab wie vielen Punkten jeder erreichbar ist. **Punktekatalog** zeigt, wofür es wie viele Punkte
  gibt (nur aktive Vorlagen, in der Reihenfolge aus der Verwaltung). Die Knöpfe erscheinen nur, wenn der jeweilige
  Bereich eingeschaltet ist; Dienstgrade und Punktekatalog sehen nur Mitglieder.
- **Schwarzes Brett: Titel, Enddatum, formatierte Nachrichten**: Jede Nachricht hat jetzt einen Titel; das Banner
  oben zeigt nur noch ihn, ein Tipp darauf öffnet die ganze Nachricht. Wie lange sie stehen bleibt, wählt man beim
  Schreiben frei (vorgegeben 24 Stunden) und kann es später verlängern oder verkürzen. Der Text lässt sich wie in einem
  Mailprogramm gestalten: Schrift, Größe, Farben, Ausrichtung, Einzug, Listen, Links, Bilder, Trennlinie und Tabellen.
  Einfügen aus Word und Outlook übernimmt Formatierung, Aufzählungen und, wo möglich, Bilder. Eine Längengrenze gibt
  es nicht mehr.
- **Signatur**: Unter *Meins → Konto* richten Mitglieder und Anwärter eine Signatur mit Text und Bildern ein. Beim
  Schreiben am Brett fügt **Signatur einfügen** sie ans Ende; ohne Signatur führt ein Knopf direkt zur Einrichtung und
  wieder zurück, der begonnene Text bleibt erhalten.
- **Schwarzes Brett: Verlauf**: Wer am Brett schreiben darf, sieht bei jeder Nachricht, wer sie wann erstellt, geändert,
  per Mail verschickt oder gelöscht hat, bei Änderungen mit vorher und nachher. Abgelaufene und gelöschte Nachrichten
  bleiben mit ihrem Verlauf noch 30 Tage einsehbar.
- **Schwarzes Brett per E-Mail**: Neue Nachrichten gehen auf Wunsch (Haken **E-Mail senden**, standardmäßig an) auch als
  Mail an alle Mitglieder und Anwärter mit hinterlegter Adresse, jede einzeln, mit dem Titel als Betreff und den Bildern
  in der Mail. Jede Person kann das unter *Meins → Konto* abschalten. Absender ist die Kompanieadresse, die Admins
  unter *Einstellungen → E-Mail* mit dem Mailserver (SMTP) eintragen, prüfen und mit einer Testmail ausprobieren.
- **E-Mails aus der Verwaltung**: Der neue Bereich **E-Mails** (Recht „E-Mails versenden“, Standardrolle „E-Mails
  verwalten“) erscheint unter *Verwaltung*, sobald der Mailversand unter *Einstellungen → E-Mail* eingerichtet ist.
  Empfänger wählt man wie in einem Mailprogramm in **An**, **Cc** und **Bcc**: ganze Rollen, einzelne Mitglieder oder
  beliebige Adressen. Der Text entsteht im selben Editor wie am Schwarzen Brett, mit **Signatur einfügen**. Jede Adresse
  bekommt die Mail einmal; An und Cc sehen sich gegenseitig, Bcc sieht niemand. Vor dem Senden nennt eine Rückfrage die
  Zahl der Empfänger und wer keine Adresse hat. Versendete Mails stehen ein Jahr im Verlauf, mit dem Stand je Empfänger.
- **Dateianhänge am Schwarzen Brett und an E-Mails**: Beim Schreiben einer Nachricht am Brett oder einer E-Mail aus
  der Verwaltung lassen sich Dateien jeder Art anhängen (je Datei bis 20 MB, bei manchen Servern weniger). Am Brett
  sehen und laden alle sie herunter, die die Nachricht sehen; mit **E-Mail senden** hängen sie auch an der Mail.
  Beim Bearbeiten einer Nachricht kommen Anhänge dazu oder werden entfernt; das steht im Verlauf. Heruntergeladen wird
  immer als Datei, nie im Browser geöffnet.
- **Lizenzen**: Unter *Hilfe → Lizenzen* steht, unter welcher Lizenz die App steht (GPL-2.0-or-later), wo es den
  vollständigen Quellcode gibt und welche Bausteine anderer Anbieter in der App enthalten sind, jeweils mit ihrer
  Lizenz. Beide Pakete enthalten dazu die Dateien `LICENSE` und `THIRD-PARTY-NOTICES.txt`.
- **Benachrichtigung öffnet den Eintrag**: Wer auf eine Benachrichtigung zu einem Termin tippt, landet direkt bei
  diesem Termin. Die Terminliste scrollt dorthin, klappt ihn auf und hebt ihn kurz hervor. Das gilt auch für die
  Erinnerung an einen einzelnen Termin am nächsten Tag, für Nachrichten am Schwarzen Brett sowie für eigene Punkte und
  Strafen. Ist der Eintrag nicht mehr da, öffnet sich wie bisher die Liste.
- **Videos in der Galerie**: Videos in den Album-Ordnern in Google Drive erscheinen jetzt im Album, mit ▶ und Dauer,
  in der App und auf der Webseite (`[kompanie_galerie]`). Antippen spielt sie ab. Videos im Ordner `intern` sehen wie
  Bilder nur Mitglieder. Die App spielt sie direkt aus Google Drive ab und speichert sie nicht; der Ordner muss dafür
  nicht freigegeben werden. Unterstützt sind MP4 und WebM; andere Formate (z. B. MOV, AVI) stehen unter
  *Einstellungen → Galerie* in der Liste „ließen sich nicht laden“ als nicht unterstützt.
- **Ämter nach Jahren**: Auf breiten Bildschirmen zeigt der Schalter „Jahre“ im Tab Ämter alle Ämter nebeneinander,
  eine Spalte je Amt und eine Zeile je Jahr. So sieht man auf einen Blick, wer wann welches Amt hatte.
- **Ämter: Einzahl, Hinweis bei Titeln, einzelnes Amt auf der Webseite**: Ein Amt hat jetzt ein Feld für die Einzahl
  („Kapitän“ zu „Kapitäne“), die auf der Seite einer Person bei ihren Amtszeiten steht. Titel, die ein Amt vergibt,
  zeigen in *Titel verwalten* und in den Mitgliedsdetails „Wird über das Amt … vergeben“ mit einem Link zum Amt. Der
  Shortcode `[kompanie_aemter amt="…"]` zeigt auf der Webseite nur ein Amt.
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
  erste und umgekehrt (auch im Tab „Mitglieder“). Gewischt werden kann überall auf der Seite, auch während sie noch lädt, und gleich weiter: Liegt der Finger schon wieder
  auf, während die Karte noch gleitet, folgt ihr die nächste Seite.
- **Ehemalige nach Austritt sortieren**: Die Liste der Ehemaligen (in der Verwaltung und in der Mitgliederliste) lässt
  sich jetzt auch nach dem Datum des Ausscheidens sortieren, früheste oder neueste zuerst; wer keines hat, steht am Ende.
- **Platzhalterbild für Mitglieder ohne Foto auf der Webseite** (WordPress): Im WP-Admin unter *Einstellungen* auf der
  Seite der App lässt sich ein Bild aus der Mediathek wählen, z. B. eine Silhouette oder das Logo. `[kompanie_mitglieder]`
  zeigt es bei Personen ohne Foto statt der Anfangsbuchstaben. In der App bleiben die Anfangsbuchstaben.
- **Nutzung der App auswerten**: Unter *Einstellungen → Nutzung* sehen Admins, wie viele Konten in den letzten 24
  Stunden, 7 und 30 Tagen aktiv waren, wie viele Personen je Tag die App genutzt haben und welche Bereiche wie oft
  genutzt werden, für einen frei wählbaren Zeitraum. Dazu alle, die im Zeitraum aktiv waren, mit dem Zeitpunkt ihrer
  letzten Nutzung und ihrem Verlauf; ein Schalter zeigt stattdessen, wer seit 30 Tagen nicht da war. Außerdem alles
  als CSV-Datei. Gespeichert werden nur Bereich, Art (z. B.
  angesehen, angelegt) und Uhrzeit, keine Inhalte und keine IP-Adressen. Die Einträge je Person bleiben 90 Tage, nur
  auf eurem Server und nicht in der Datensicherung; mit einem Konto werden auch seine Einträge gelöscht. Die Erfassung
  je Person ist eingeschaltet und lässt sich dort ausschalten; dann zählt die App nur noch ohne Namen. Bitte
  informiert eure Mitglieder darüber, z. B. in der Datenschutzerklärung.
- **Großansicht in voller Auflösung**: Galerie und Mitgliedsfotos erscheinen beim Antippen erst wie bisher und werden
  nach einem Moment durch die Originalauflösung ersetzt (App und Webseite). In der Galerie entsteht sie aus dem
  Original in Google Drive, wenn jemand das Bild zum ersten Mal öffnet. Mitgliedsfotos lädt die App jetzt bis
  4096 Pixel hoch; vorhandene Fotos haben die volle Auflösung erst, wenn sie neu hochgeladen werden.
- **Kalender: Aktualisierung einstellbar**: Unter *Einstellungen → Kalender* (und mit WordPress auch im Backend) legt
  ihr fest, nach wie vielen Minuten die App neue Termine bei Google holt (1 bis 1440, Standard 15). Kürzere Zeiten
  zeigen Änderungen wie gelöschte Termine schneller; Speichern und „Kalender testen“ laden sofort neu.
- **Punktekatalog und Dienstgrade per Ziehen sortieren**: Wie bei Titeln hat jeder Eintrag links einen Griff. Mit
  Maus, Finger oder Pfeiltasten ändert ihr die Reihenfolge; sie gilt auch für die Vorlagen beim Punkte vergeben. Neue
  Vorlagen kommen ans Ende. Bei Dienstgraden ersetzt das die Knöpfe „niedriger“ und „höher“; passt ein Zug nicht zu den
  Punkteschwellen, springt der Dienstgrad zurück und die App sagt, welche nicht passen.

### Geändert

- **E-Mail-Adressen und Handynummern einfacher eingeben**: Alle Felder dafür öffnen auf dem Handy die passende
  Tastatur (E-Mail mit @, Handynummer als Zahlenblock) und lassen Großschreibung und Autokorrektur weg. Die App glättet
  die Eingabe selbst: Leerzeichen, „mailto:“ oder „Name <adresse>“ aus kopiertem Text verschwinden, „GMX,de“ wird
  „gmx.de“. Was noch nicht stimmt (z. B. fehlendes @ oder fehlende Endung), steht direkt unter dem Feld; bei vertippten
  Anbietern wie „gmial.com“ schlägt sie die richtige Adresse zum Antippen vor. Bei der Handynummer wählt ihr die
  Ländervorwahl (vorgegeben +49) und tippt die Nummer wie gewohnt, z. B. 0171 1234567. Gespeichert wird sie einheitlich
  als +49 171 1234567; vorhandene Nummern wie +491716438655 schreibt das Update in diese Form um.
- **Neuer Bereich „Intern“ mit eigenem Recht**: Mitglieder und Dokumente stehen unten nicht mehr als eigene Knöpfe,
  sondern gesammelt unter **Intern**, für alle mit dem neuen Recht **Intern**. Die Seite zeigt wie die Verwaltung je
  Bereich einen Knopf; **‹ Intern** führt zurück. Mitglieder haben das Recht immer (es gehört zum Mitgliederbereich);
  eigenen Rollen ohne Mitgliederbereich, z. B. Anwärtern, und einzelnen Personen können Admins es unter
  *Einstellungen → Rollen* bzw. bei den Rechten einer Person geben. Wer es nicht hat, z. B. Gäste, behält die Knöpfe
  einzeln. Das Recht ändert nur die Navigation: Dokumente sieht weiter nur, wer den Mitgliederbereich hat. Alte Links
  auf Mitglieder und Dokumente funktionieren weiter.
- **Benachrichtigung zum Schwarzen Brett**: Sie zeigt jetzt den Titel der Nachricht statt des ganzen Texts und wird,
  wenn die Nachricht länger steht, bis zu 28 Tage nachgeliefert (vorher höchstens einen Tag).
- **Eigenständige Version**: Das Paket enthält jetzt PHPMailer (LGPL-2.1) für den Mailversand. Mit WordPress nutzt die
  App die Kopie von WordPress.
- **Statusmeldungen mit Nutzungszahlen**: Wenn ihr den Statusmeldungen zugestimmt habt, enthält das stündliche
  Lebenszeichen jetzt auch Zahlen ohne Namen: wie viele Konten es gibt, wie viele in den letzten 24 Stunden, 7 und 30
  Tagen aktiv waren und wie viele Personen welchen Bereich in den letzten 30 Tagen genutzt haben. Was genau gesendet
  wird, steht wie bisher unter *Einstellungen → Zustand und Updates*; dort lassen sich die Statusmeldungen auch
  ausschalten.
- **Keine Daten der App in Suchmaschinen**: Alle Antworten der Schnittstelle, auch die öffentliche Mitgliederliste und
  die Ämter, tragen jetzt den Hinweis `noindex` für Suchmaschinen, wie schon die App-Seite und die Fotos.
- **Ämter enden mit dem Ausscheiden**: Wer unter *Austritt* als ehemalig eingetragen wird, hat danach keine laufenden
  Ämter mehr. Sie enden mit dem Jahr des Ausscheidens (ohne Datum mit dem aktuellen Jahr, nie vor ihrem Beginn); teilt
  die Person ein Amt mit anderen, bleiben die anderen im Amt. Verknüpfte Titel entfallen damit. Das Fenster zeigt vorher,
  welche Ämter enden. Ein später geändertes Austrittsdatum zieht das Ende nach; „Wieder aufnehmen“ öffnet nichts wieder.
- **Ämter: Haken „Ehrung“ an der Amtszeit**: Ob eine Amtszeit eine Ehrung ist (Stern und Hinweis „Ehrung“ im
  Zeitstrahl), entscheidet jetzt ein eigener Haken, bei einem einzelnen Jahr wie bei einem Zeitraum. Ein einzelnes Jahr
  ohne Haken, etwa ein Königsjahr, erscheint als gewöhnliches Jahr. Bisherige einzelne Jahre behalten ihren Stern. Der
  Import kennt dafür die 8. Spalte `ehrung` (`ja`, `nein` oder leer); ältere Tabellen mit 7 Spalten ergeben keine
  Ehrungen mehr.
- **Verwaltung aufgeräumt: Knopf „Einrichtungen“**: Punktekatalog, Dienstgrade, Titel und Ämter – was man einmal
  anlegt und selten ändert – liegen jetzt hinter dem neuen Knopf „Einrichtungen“ ganz unten vor „Einstellungen“.
  „Listen“ steht jetzt nach „Inventar“. Darf jemand nur einen der vier Bereiche pflegen, steht sein Knopf wie bisher
  direkt auf der Startseite.
- **„Meins → Übersicht“ wie die Seite eines Mitglieds in der Verwaltung**: Name, darunter Dienstgradabzeichen und Foto,
  Rolle, die Kacheln Punkte und Offen, die Titel, Geburtstag und Eintritt sowie die Ämter als Zeitstrahl. Antippen
  führt zur passenden Seite (Kacheln, Abzeichen) bzw. zu „Konto“ (Geburtstag, Eintritt); das Foto vergrößert sich.
  Die eigenen Ämter stehen jetzt in der Übersicht statt auf einer eigenen Seite im Menü.
- **Verwaltung mit Startseite**: Statt eines Aufklappmenüs zeigt die Verwaltung zuerst eine Übersicht, in der jeder
  Bereich (Mitglieder, Punkte, Dienstgrade, Einstellungen …) ein eigener Knopf mit Symbol ist. **‹ Verwaltung** führt
  von jeder Seite zurück. Wer nur einen Bereich pflegt, landet wie bisher direkt dort.
- **Anwärter in den Mitgliederlisten erkennbar**: In der Mitgliederliste der Verwaltung und in der öffentlichen Liste
  unter *Mitglieder* (auch auf der Seite der Person) steht bei Personen, deren Rolle das Häkchen *Als Mitglied
  aufnehmen* hat (z. B. Anwärter), der Name der Rolle neben dem Namen wie ein Titel. Damit ist öffentlich sichtbar, wer
  Anwärter ist.
- **Geburtstag und Eintritt auf der eigenen Seite**: Unter *Konto* steht die Auswahl „Auf meiner Seite …“ jetzt
  von Anfang an auf **Mitgliedern anzeigen** (bisher „Nicht anzeigen“); nur angemeldete Mitglieder sehen die Angabe.
  Das gilt auch für alle schon eingetragenen Geburtstage und Eintritte, die auf „Nicht anzeigen“ standen. Wer sie
  nicht zeigen will, stellt unter *Konto* wieder „Nicht anzeigen“ ein.
- **Eintritt und Austritt mit ganzem Datum**: Auf der Seite eines Mitglieds steht der Eintritt jetzt so, wie er
  eingetragen ist, z. B. „Eintritt 01.04.2005“ (bisher nur das Jahr). Bei den Ehemaligen stehen Eintritt und
  Ausscheiden ebenso mit ganzem Datum, z. B. „01.04.1985 – 03.05.2010“; wer dort nur Jahre eingetragen hat, sieht
  weiter nur die Jahre. Das gilt für alle, die die Angaben sehen dürfen, bei den Ehemaligen also auch ohne Anmeldung.
- **Geburtstag im selben Format wie Eintritt und Austritt**: Auf *Meins*, in der Verwaltung und auf der Seite einer
  Person in der Mitgliederliste steht der Geburtstag jetzt als „02.12.1987“ (bisher „2. Dezember 1987“), ohne Jahr als
  „02.12.“.
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
- **Eintritt ohne „am“**: Unter *Meins* und in den Mitgliedsdetails steht neben „Eintritt“ nur noch das Datum
  („17.04.2023“) bzw. das Jahr. Der Satz beim Bearbeiten lautet weiter „Eingetreten am …“.
- **Beförderungen mit Abschnitt „Unter der Schwelle“**: Wer weniger Punkte hat, als sein jetziger Dienstgrad verlangt
  (Kandidaten zum Degradieren), steht jetzt ganz oben in einem eigenen, rot markierten Abschnitt, zusammen mit der
  übrigen Übersicht. Der Schalter „Nur unter der Schwelle …“ entfällt. Ist niemand darunter, fehlt der Abschnitt.

- **Tabs öffnen schon beim ersten Mal ohne Warten**: Nach dem Start lädt die App, sobald nichts anderes zu tun ist, die
  Daten der übrigen Tabs vorab, und beim Antippen eines Tabs, Albums, einer Umfrage, Anwesenheitsliste oder eines
  Mitglieds in der Verwaltung beginnt das Laden schon beim Berühren. So erscheinen die Seiten meist sofort statt mit
  „Lädt …“. Vorab geladen werden nur Daten, keine Bilder oder Dokumente; im Datensparmodus des Handys, bei langsamem
  Netz, in der Vorschau „App als Rolle ansehen“ und im Wartungsmodus lädt die App nichts im Voraus.
- **Anwesenheitsliste ohne Rollen-Abzeichen**: Hinter den Namen stehen keine Rollen (etwa Admin, Vorstand, Gast) mehr,
  wie überall sonst in der App auch.
- **Eigenes Konto auch in der Verwaltung ändern**: Auf der eigenen Seite unter *Verwaltung → Mitglieder → Konto und
  Rolle* gibt es jetzt auch den Bereich „Konto“ mit **Angaben ändern**, etwa für die eigene Handynummer als Admin.
  Löschen lässt sich das eigene Konto dort nicht. Eine neue eigene E-Mail-Adresse verlangt hier wie unter *Meins →
  Konto* das aktuelle Passwort.
- **Mail vom Schwarzen Brett auch an den Verfasser**: Wer eine Nachricht mit „E-Mail senden“ veröffentlicht, bekommt
  die Mail jetzt selbst auch (sofern eine Adresse hinterlegt ist und die Mails vom Brett nicht abgeschaltet sind). Die
  Zahl am Haken zählt ihn mit. Die Benachrichtigung aufs Handy bekommt der Verfasser weiterhin nicht.

### Behoben

- **Update brach mit „Allowed memory size … exhausted“ ab**: Vor einem Update mit Änderungen an der Datenbank sichert
  die App alle Daten. Diese Sicherung entstand bisher komplett im Arbeitsspeicher; mit vielen Fotos, Dokumenten und
  Bildern war sie größer, als PHP erlaubt (z. B. 256 MB), und das Update wurde nicht eingespielt. Jetzt schreibt die App
  die Sicherung Stück für Stück in die Datei und braucht dafür nur wenige MB, egal wie groß sie ist. Ebenso beim
  wöchentlichen Hochladen nach Google Drive, das große Sicherungen jetzt in Stücken überträgt, und bei
  *Einstellungen → Datensicherung → Sicherung herunterladen*: Der Server schickt die Datei jetzt direkt Stück für
  Stück an den Browser, statt sie erst ganz zusammenzubauen. Hinweis: Das gilt ab dieser Version; das Update auf
  diese Version führt noch die bisherige aus.
- **Änderungen von anderen Geräten kamen manchmal nicht an**: Fragte die App gerade im Hintergrund nach neuen Daten,
  während z. B. eine Nachricht am Schwarzen Brett auf einem anderen Gerät geändert wurde, blieb sie beim alten Stand,
  auch nach dem Neuladen – eine geänderte, vorher ausgeblendete Nachricht erschien dann nicht wieder. Jetzt fragt die
  App danach noch einmal nach und zeigt den neuen Stand.
- **Shortcodes mittig**: Manche Themes (z. B. Astra) rückten `[kompanie_mitglieder]`, `[kompanie_termine]` und die
  Inhaber in `[kompanie_aemter]` nach rechts ein; links war mehr Platz als rechts. Die Listen stehen jetzt bündig.
- **Neues Logo erreicht auch bereits installierte Apps**: Wer die App schon auf dem Android-Handy installiert hatte,
  sah nach einer Änderung am Logo (z. B. der neuen Lage im Startbildschirm) weiter das alte Symbol, denn Chrome
  übernimmt neue Icons nur, wenn sich ihre Adresse im Manifest ändert. Die Adressen enthalten jetzt eine Kennung, die
  sich mit dem Bild ändert; Chrome übernimmt das neue Symbol dann beim nächsten Abgleich, bei größeren Änderungen erst
  nach einer Bestätigung. Auf dem iPhone bleibt das Symbol, das beim Hinzufügen gespeichert wurde.
- **Altes Icon nach einem Update**: Hatte das Handy das Icon am Tag vor einem Update schon geladen, speicherte die App
  beim Update manchmal das alte Icon (z. B. für Benachrichtigungen) und zeigte es bis zum nächsten Update. Jetzt fragt
  sie dafür immer beim Server nach.
- **Doppelpunkt bei den Empfängern einer E-Mail**: In der Ansicht einer gesendeten E-Mail (*Verwaltung → E-Mails*)
  steht jetzt „An:“, „Cc:“ und „Bcc:“ statt „An“, „Cc“ und „Bcc“.

### Behoben

- **Eingabefelder in Umfragen**: Die Felder *Frage* und *Antwortmöglichkeiten* sowie *Ende* beim Anlegen oder
  Bearbeiten einer Umfrage sehen jetzt aus wie alle anderen Eingabefelder der App (bisher graue, eckige Kästen).
- **Großansicht älterer Mitgliedsfotos auf der Webseite**: In `[kompanie_mitglieder]` öffnete sich bei Fotos, die vor
  der Fassung in 1024 Pixeln hochgeladen wurden, beim Antippen kein Bild. Jetzt zeigt die Großansicht dann das größte
  vorhandene Foto (480 Pixel). Wer volle Auflösung will, lädt das Foto neu hoch.
- **Abstände in der Verwaltung**: Unter *Listen* stößt die Karte „Neue Liste“ nicht mehr an die letzte Liste, und beim
  Bearbeiten eines Amts hat „Amtszeit eintragen“ (oder das Formular für eine neue Amtszeit) wieder Abstand nach oben
  und unten. Dasselbe gilt überall, wo eine Formular-Karte auf eine Liste, einen Hinweis oder einen breiten Knopf folgt.

### Sicherheit

- **Keine Metadaten in Bildern**: Gespeicherte Bilder enthalten keine EXIF-Daten mehr, auch keinen GPS-Ort, auch
  wenn der Server sie nicht verkleinern musste.

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
