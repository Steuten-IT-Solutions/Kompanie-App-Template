# Changelog

Was sich in der Kompanie-App von Version zu Version ändert, in Worten für Admins. Admins sehen diese Liste auch in der
App unter [Einstellungen → Was ist neu](app:einstellungen/neuerungen), jeweils passend zur installierten Version.

Pflege (siehe [docs/RELEASE.md](docs/RELEASE.md)):

- Jeder Pull Request nach `develop`, der etwas für die Nutzer der App ändert, ergänzt einen Eintrag unter
  `[Unveröffentlicht]`, im passenden Unterabschnitt: `Neu`, `Geändert`, `Behoben`, `Entfernt` oder `Sicherheit`.
  Interne Änderungen (Tests, CI, Werkzeuge zum Veröffentlichen) gehören nicht hierher.
- Ein Pull Request, der ein Thema fortführt (z. B. weitere Blöcke, mehr zum Schwarzen Brett), ändert den bestehenden
  Eintrag zu diesem Thema, statt einen neuen anzulegen. Ein Eintrag beschreibt den Stand für die Admins, nicht die
  einzelnen Schritte dorthin.
- Wer `VERSION` erhöht, fasst vorher die Einträge unter `[Unveröffentlicht]` nach Thema zusammen und macht dann aus
  `[Unveröffentlicht]` den Abschnitt `## [x.y.z] – JJJJ-MM-TT` und legt darüber einen
  neuen, leeren `[Unveröffentlicht]` an. Ein Release `develop → main` ist nur mit diesem Abschnitt möglich
  (`php scripts/changelog.php --check --require-version`).
- Wo ein Eintrag sagt, wo man etwas findet oder einstellt, steht der Weg als Verweis in die App:
  `[Einstellungen → Rollen](app:einstellungen/rollen)`, mit der Adresse der Seite ohne `#/` (in der App z. B.
  `#/einstellungen/rollen`, `#/verwaltung/aemter`). In der App lässt sich der Weg antippen und öffnet die Seite. Ein Weg
  nur als Text (`*Einstellungen → …*`, `*Verwaltung → …*`) lehnt die Prüfung ab, ebenso ein Verweis auf eine Seite, die
  es nicht gibt (`packages/frontend/test/changelogLinks.test.ts`).
- Die Version ist die Kern-Version (`1.4.0`), nicht die einer Kompanie (`1.4.0.27`).

## [Unveröffentlicht]

### Neu

- **Internen Kalender direkt in der App pflegen, ohne Google**: Unter [Einstellungen → Kalender](app:einstellungen/kalender)
  wählst du jetzt die Quelle des internen Kalenders: **In der App pflegen**, **Google Kalender** oder **iCal-Adresse**.
  Es gilt immer genau eine Quelle. Mit „In der App pflegen“ legt, wer das neue Recht „Kalender pflegen“ hat (z. B. über
  die neue Rolle „Kalender verwalten“), unter [Termine](app:termine) → „Internen Kalender verwalten“ interne Termine an.
  Die sehen nur Angemeldete mit dem internen Kalender, getrennt von den öffentlichen Terminen; Benachrichtigungen und
  Anwesenheitslisten nutzen sie wie bisher die Termine aus Google. Serientermine gibt es dafür noch nicht.

- **Reihenfolge der Knöpfe in der Verwaltung und in „Intern“**: Unter [Einstellungen → Navigation](app:einstellungen/navigation)
  ordnest du im neuen Abschnitt „Verwaltung“ die Knöpfe der Startseite der Verwaltung und unter „Einrichtungen“ per Ziehen,
  für alle gleich. Jede Person sieht weiter nur die Knöpfe, für die sie das Recht hat. Auch die Bereiche in „Intern“ der
  Standardnavigation lassen sich jetzt umordnen, ohne dass daraus eine eigene Navigation wird.

### Behoben

- **Ämter, Ansicht „Jahre“: Namen nicht mehr abgeschnitten**: Unter [Mitglieder → Ämter](app:mitglieder/aemter) sind
  die Spalten jetzt so breit, dass Bezeichnungen wie „Könige/Königinnen“ und lange Namen ganz zu lesen sind. Bezeichnungen
  brechen nach „/“ um, ein Jahr mit zweizeiligem Namen wird höher statt die zweite Zeile zu verdecken.

## [0.8.2] – 2026-11-09

### Geändert

- Der Countdown-Block zählt jetzt nach dem Kalender in der Zeitzone der App. Bisher fehlte bei einem Countdown über
  die Umstellung auf Sommerzeit eine Stunde (z. B. 227 Tage 1 Stunde statt 2 Stunden bis zu einem Termin im Juni),
  weil er die tatsächlich vergehende Zeit gezählt hat.
- Kompanieliste: Die Logos der Kompanien werden nicht mehr mit abgerundeten Ecken angezeigt, sondern so, wie sie
  hochgeladen sind. Logos mit eigenem Hintergrund (z. B. weiß) haben jetzt eckige Ecken.

### Behoben

- Namen und Inhalte in Großbuchstaben (Plaketten, Überschriften, Leiste unten) zeigen ein „ß“ jetzt als großes „ẞ“
  statt als „SS“, z. B. „NUR FÜR ERIKA GROẞ“ im Verlauf des Schwarzen Bretts. Namen mit „ß“ und „ss“ bleiben so
  unterscheidbar. Feste Beschriftungen von Feldern und Knöpfen bleiben wie bisher („STRASSE“).

## [0.8.1] – 2026-11-09

### Geändert

- **Webadressen ohne https:// eingeben**: Bei [Verwaltung → Einrichtungen → Kompanien](app:verwaltung/kompanien) (Webseite,
  App der Kompanie) und [Verwaltung → Einrichtungen → Sponsoren](app:verwaltung/sponsoren) (Link) reicht jetzt z. B.
  `beispiel.de/app`; die App ergänzt `https://` beim Verlassen des Felds. Eine ungültige Adresse (ohne Endung wie `.de`,
  mit `http://` statt `https://`, kein Weblink) zeigt die App mit einer verständlichen Meldung direkt am Feld, statt
  der knappen Meldung des Browsers „Gib eine URL ein.“.

## [0.8.0] – 2026-11-09

### Neu

- **Update als Datei hochladen**: Unter [Einstellungen → Zustand und Updates](app:einstellungen/zustand) lässt sich bei
  *Update-Paket hochladen* ein Update-Paket (ZIP-Datei) direkt in der App hochladen und einspielen, ohne Zugang zum
  Server. Die App zeigt vorher, welche Version es ist, und spielt nur unveränderte Pakete aus der geprüften Update-Liste
  ein, auch eine Vorabversion oder eine ältere Version. Wie immer wird vorher gesichert.
- **Navigation: Bereiche umbenennen, Symbole wählen, Startseite festlegen**: Unter [Einstellungen → Navigation](app:einstellungen/navigation) gebt
  ihr jedem Bereich, jeder eigenen Seite und dem Untermenü *Intern* der Standardnavigation einen eigenen Namen und ein
  eigenes Symbol (dazu gibt es zehn neue, allgemeine Symbole). Der Name gilt überall in der App: in der Leiste, in den
  Überschriften, in der Verwaltung, in Benachrichtigungen, Mails und in der Hilfe; bei eigenen Seiten nur in der Leiste.
  Die Startseite beim Öffnen der App wählt ihr getrennt für Gäste und für Angemeldete. Unten in der Leiste dürfen jetzt
  beliebig viele Punkte stehen; Schrift und Symbole werden mit der Zahl kleiner, und auf Wunsch zeigt die Leiste nur
  Symbole. *Standardnavigation wiederherstellen* setzt all das zusammen zurück. Feste Sätze wie „Keine anstehenden
  Termine“ sind dafür neutral formuliert („Zurzeit steht nichts an.“), Benachrichtigungen heißen z. B. „Termine: neu“.
- **Galerie: Videos hochladen und einreichen**: Unter [Einstellungen → Galerie](app:einstellungen/galerie) lassen sich jetzt neben Bildern auch
  Videos als MP4 oder WebM in jedes Album hochladen, mit höchstens 1 GB je Video. Wer Bilder einreichen darf, reicht
  ebenso Videos ein (*Bilder und Videos einreichen*); beim Prüfen spielt ihr ein Video vor der Freigabe ab. Die App lädt
  Videos in Stücken hoch, damit auch große Handyvideos unter der Grenze des Webspace bleiben, und zeigt den Fortschritt
  in Prozent. Videos liegen auf dem eigenen Server und werden nicht umgewandelt; als Vorschaubild dient ein Standbild
  aus der ersten Sekunde. In der Sicherung sind diese Videos wegen ihrer Größe nicht enthalten.
- **Galerie: hochgeladene Bilder nachträglich intern oder öffentlich stellen**: Bilder, die in der App hochgeladen
  oder über Einreichungen freigegeben wurden, lassen sich jetzt jederzeit umstellen: unter [Einstellungen → Galerie](app:einstellungen/galerie)
  mit dem Haken *intern* neben dem Bild, beim Prüfen unter *Zuletzt freigegeben* und in der Großansicht der Galerie
  (*Nur für Mitglieder* bzw. *Öffentlich zeigen*). Das dürfen alle, die die Einstellungen verwalten oder Einreichungen
  prüfen. Das Bild bekommt dabei eine neue Adresse, die alte liefert nichts mehr. Bilder aus Google Drive stellt
  weiterhin der Unterordner *intern* in Drive um.
- **Inhalte aus Blöcken**: Eigene Seiten, Beiträge in den News, die in der App geschrieben werden, und Nachrichten am
  Schwarzen Brett bestehen jetzt aus Blöcken, die ihr mit *+ Block einfügen* hinzufügt, mit ↑/↓ verschiebt und
  entfernt. Jeder Block hat ein eigenes *Für wen?*: Blöcke *Nur Mitglieder* samt ihren Bildern fehlen auf öffentlichen
  Seiten und Nachrichten ohne Anmeldung. Bestehende Inhalte bleiben unverändert (ein Textblock). Ohne Titelbild ist
  das Vorschaubild in der Liste der News das erste Bild in einem Text für alle. Die Mail vom Brett enthält alle
  Blöcke, den Countdown als Satz; der Verlauf zeigt Blöcke ebenso.
- **Vorgefertigte Blöcke**: Neben *Text* gibt es
  *Countdown* (bis zu einem Datum oder Termin, verschoben: zählt mit; immer zwei Angaben wie Tage und Stunden, auf
  Wunsch bis zu einem Ende ein Text wie „läuft“ und danach ein eigener Text oder nichts),
  *Knopf mit Link* (Webseite, E-Mail-Adresse, Telefonnummer oder Seite der App),
  *Nächste Termine* (1 bis 20 ab heute, immer aktuell),
  *Ansprechpartner* (wer ausgewählte Ämter gerade innehat, mit Foto; E-Mail-Adresse und Handynummer nur für alle, die
  persönliche Angaben sehen dürfen),
  *Hinweis-Kasten* (Info, Warnung oder Erfolg), *Trennlinie* (Linie oder Abstand),
  *Ablauf* (Zeit und Programmpunkt untereinander, z. B. für ein Fest),
  *Zahlen und Fakten* (kurze Kennzahlen wie „1848 gegründet“),
  *Umfrage* (Stand und Weg zum Abstimmen, nur für alle, die an Umfragen teilnehmen dürfen),
  *Album* (einige Vorschaubilder eines Albums; jeder sieht nur die Bilder, die er in der Galerie sehen darf),
  *Dokument* (Datei aus den Dokumenten zum Öffnen und Herunterladen, nur für alle, die sie dort sehen dürfen),
  *Sponsoren* (alle mit Logo und Link) und
  *Karte zur Anfahrt* (ein Ort mit dem Knopf *Karte*, der ihn in der Karten-App des Geräts öffnet; es wird keine fremde
  Karte eingebettet).
- **Titelbild für Neuigkeiten**: Beim Schreiben eines Beitrags in der App gibt es das Feld *Titelbild (optional)* mit
  Vorschau im Format der Liste. Das Titelbild steht in der Liste über dem Beitrag und groß oben im Beitrag; ohne
  Titelbild bleibt wie bisher das erste Bild im Text das Vorschaubild. Neuigkeiten von einer Webseite oder einem Feed
  zeigen weiter ihr mitgeliefertes Bild.
- **Mitglieder ohne Zugang**: Unter *Mitglied anlegen* gibt es den Haken *Ohne Zugang (meldet sich noch nicht an)*.
  So tragt ihr Personen ein, die noch kein Konto haben, z. B. für Chroniken und Ämter. Sie sind gewöhnliche Mitglieder,
  lassen sich überall auswählen und tragen in der Mitgliederliste der Verwaltung die Marke *ohne Zugang*; ein
  Startpasswort gibt es nicht. Einen Zugang bekommen sie später auf ihrer Seite mit *Zugang einrichten*
  (Startpasswort) oder beim Freigeben ihrer eigenen Anfrage. Beim Import aus Excel oder CSV legt die neue Spalte
  *Zugang* mit „nein“ Mitglieder ohne Zugang an (leer oder „ja“: wie bisher mit Startpasswort). Unter *Mehrere auf
  einmal anlegen* gibt es je Person den Haken *Ohne Zugang*.
- **Fehlermeldungen zuerst an die Admins**: Was jemand über *Fehler melden* schickt, geht nicht mehr gleich an die
  Betreuer der App, sondern landet unter [Verwaltung → Fehlermeldungen](app:verwaltung/meldungen) (mit Zahl der offenen und einer
  Benachrichtigung). Dort schließt ihr eine Meldung mit Begründung, z. B. wenn ihr selbst helfen konntet, oder leitet
  sie an die Betreuer weiter; erst dann wird daraus ein Eintrag bei ihnen. Wer angemeldet meldet, sieht unter
  *Deine Meldungen* den Stand und eure Begründung und wird benachrichtigt. Die Admins sehen den Namen angemeldeter
  Personen; an die Betreuer geht er weiter nur mit Haken. Neues Recht *Fehlermeldungen bearbeiten*, das ihr an Rollen
  vergeben könnt (Admins haben es immer). *Fehler melden* gibt es jetzt auch in Apps ohne Empfänger für
  Fehlermeldungen; dort bleiben die Meldungen bei den Admins. Erledigte Meldungen werden nach 30 Tagen gelöscht.
- **Hinweis zum Einschalten der Benachrichtigungen**: Angemeldete Mitglieder sehen auf der Startseite einen Hinweis
  *Nichts mehr verpassen* mit dem Knopf *Benachrichtigungen einschalten*, solange ihr Browser auf diesem Gerät noch
  nicht nach Benachrichtigungen gefragt hat. Ein Tippen fragt nach der Erlaubnis und schaltet alle Arten ein; *Nicht
  jetzt* blendet den Hinweis für 30 Tage aus. Ohne Zustimmung der Person im Browser geht es technisch nicht.
- **Neue Arten von Benachrichtigungen kommen von selbst dazu**: Bekommt jemand eine Art neu angeboten, z. B. nach einer
  neuen Rolle (Bilder prüfen, Anfragen freigeben, Mitglied statt Gast) oder weil ihr einen Bereich wie News oder
  Termine einschaltet, schaltet die App sie auf seinen Geräten mit Benachrichtigungen ein (spätestens nach
  15 Minuten). Arten, die jemand selbst abgeschaltet hat, bleiben aus. Bei Geräten, die schon vor diesem Update
  angemeldet waren, gilt das für alles, was ab jetzt neu dazukommt.
- **Rolle und Zusatzrollen gleich beim Anlegen**: Wer Rollen vergeben darf, wählt beim Anlegen eines Mitglieds jetzt
  jede Rolle, die er auch danach unter *Konto und Rolle* vergeben dürfte (z. B. Vorstand), und hakt Zusatzrollen an.
  Das gilt für *Mitglied anlegen*, *Mehrere auf einmal anlegen* (Rolle und Zusatzrollen für alle), den Import aus
  Excel oder CSV (Spalte *Status* mit dem Namen der Rolle, neue Spalte *Zusatzrollen*, mit Komma getrennt) und das
  Freigeben von Anfragen (nur Rolle). Wer nur Mitglieder anlegen darf, wählt wie bisher Mitglied und die Rollen, die
  unter [Einstellungen → Rollen](app:einstellungen/rollen) für neue Konten freigegeben sind. Die Rechte prüft der Server.
- **Ämter: freie Namen mit Mitgliedern verknüpfen**: Unter *Ämter → Namen mit Mitgliedern verknüpfen* schlägt die App
  vor, welche Personen ohne Profil schon als Mitglied (auch ohne Zugang), in der Mitgliederliste oder als Ehemalige
  angelegt sind, mit denselben toleranten Namensregeln wie bei den Anfragen. Mit *Verknüpfen* gehören alle Einträge
  mit diesem Namen zur gewählten Person; Titel, die das Amt vergibt, werden abgeglichen.
- **Anfragen mit einem Mitglied ohne Zugang verbinden**: Beim Freigeben einer Anfrage schlägt die App passende
  Mitglieder ohne Zugang vor (gleiche E-Mail-Adresse, gleicher oder ähnlicher Name, auch mit ä/ae oder Bindestrich),
  und ihr könnt jedes Mitglied ohne Zugang wählen. Mit *Freigeben und verbinden* bekommt das Mitglied Benutzername,
  E-Mail-Adresse und Passwort aus der Anfrage; es entsteht kein zweites Konto, Rolle und Einträge bleiben. E-Mail-Adresse
  und Benutzername eines Mitglieds ohne Zugang gelten im Formular *Zugang beantragen* als frei.
- **Schwarzes Brett nur für eine Kompanie oder einzelne Personen**: Unter *Sichtbar für* gibt es neben *intern* und
  *öffentlich* jetzt *Kompanie* (nur, wer einer der angehakten Kompanien zugeordnet ist; nur mit eingeschaltetem
  Bereich Kompanien) und *Einzeln* (nur die angehakten Personen). Benachrichtigung und E-Mail gehen dann nur an sie.
  Alle, die ans Brett schreiben dürfen, sehen auch diese Nachrichten. Die Auswahl lässt sich beim Bearbeiten ändern
  und steht im Verlauf. Hinweis: Wer danach auf eine ältere Version zurückgeht, zeigt solche Nachrichten wieder allen
  Mitgliedern und Anwärtern.
- **Was ist neu: Wege direkt antippen**: Nennt eine Änderung, wo ihr etwas findet oder einstellt (z. B.
  [Einstellungen → Rollen](app:einstellungen/rollen)), ist dieser Weg unter
  [Einstellungen → Was ist neu](app:einstellungen/neuerungen) jetzt ein Verweis und öffnet gleich die Seite, auch bei
  älteren Versionen. Wege zu Seiten, die inzwischen umgezogen sind, führen auf die heutige Seite.

### Geändert

- **Ämter in der Einzahl**: Auf den Karten gemeinsamer Amtszeiten (z. B. „Königshaus 2026–2028“) und beim Antippen
  eines Balkens in der Jahresansicht steht jetzt die Einzahl des Amts („Prinz“ statt „Prinzen“), wie schon auf der
  Seite einer Person. Ebenso im Block *Ansprechpartner* über jeder Person und in der Mail vom Brett, wenn das Amt nur
  eine Person hat. Ohne Einzahl bleibt die Bezeichnung.
- **Rollen Mitglied und Gast: auch Rechte zum Verwalten**: Unter [Einstellungen → Rollen](app:einstellungen/rollen) lässt sich den Rollen
  Mitglied und Gast jetzt jedes Recht geben, nicht mehr nur Rechte zum Sehen, z. B. *Bilder und Videos einreichen*.
  Jede Person mit der Rolle bekommt es dann, bei Mitglied auch jedes neue Konto. Gast gehört weiter nie zur Kompanie;
  Rechte, die sonst *Gehört zur Kompanie* voraussetzen, gehen bei Gast auch ohne. Was Gast darf, darf jeder, der die
  App ohne Anmeldung öffnet; die App weist darauf hin. Wer Mitglieder anlegen darf, kann weiterhin Konten mit der Rolle
  Mitglied anlegen.
- **Kompanien: Mitglieder nur mit dem Bereich Mitglieder**: Die Seiten der Kompanien zeigen ihre Mitglieder jetzt
  nur noch, wenn der Bereich *Mitglieder* eingeschaltet ist, und dann für die Rollen mit dem Recht *Mitglieder*. So
  schaltest du die Mitgliederlisten unter [Einstellungen → Bereiche](app:einstellungen/bereiche) zentral ein oder aus und legst unter [Einstellungen → Rollen](app:einstellungen/rollen)
  fest, wer sie sieht. Bisher erschienen die Mitglieder auf den Kompanieseiten auch bei ausgeschaltetem Bereich, und
  das Recht ließ sich dann nicht ändern. Die Zuordnungen bleiben erhalten.
- **Album herunterladen als kleiner Knopf**: In einem Album der Galerie steht statt des großen Knopfs *Album
  herunterladen (… Bilder, ZIP)* jetzt rechts neben dem Titel ein runder Knopf mit Pfeil nach unten, wie beim
  Herunterladen eines einzelnen Bildes. Die Anzahl der Bilder und Videos zeigt er beim Darüberfahren mit der Maus;
  während des Ladens steht darunter „Lädt … von …“ mit *Abbrechen*.
- **Einstellungen neu geordnet**: Die Übersicht zeigt Kacheln in Gruppen (App, Mitglieder und Zugang,
  Benachrichtigungen, Verbindungen, Termine und Kalender, Betrieb) statt einer langen Liste. *Zugang beantragen* steht
  jetzt als Abschnitt unter *Rollen*, *Geburtstage* unter *Kalender*, zusammen mit der Benachrichtigung am Geburtstag
  (bisher unter *Benachrichtigungen*). Alte Links auf die beiden Seiten führen an die neue Stelle.
- **Rolle nur für alle, die Rollen vergeben**: Auf der Seite eines Mitglieds stehen Rolle, Zusatzrollen und
  „mit Ausnahmen bei den Rechten“ nur noch für alle mit dem Recht *Rollen vergeben*; ebenso der Filter *nach Rolle* in
  der Mitgliederliste. Die anderen sehen dort nur „Ehemalig“ oder „Ohne Zugang“, wenn das zutrifft. Die Zusatzrollen
  schickt der Server auf der Seite eines Mitglieds nur noch mit diesem Recht.
- **„Was ist neu“ kürzer**: Aufgeklappt ist nur noch die Vorabversion bzw. die installierte Version; ältere
  Versionen stehen eingeklappt mit Datum und Anzahl der Änderungen darunter und öffnen sich beim Antippen.
- **Erklärungen nur noch in der Hilfe**: Lange Erklärtexte oben auf vielen Seiten (z. B. Import, Ehemalige, Ämter,
  Rollen, E-Mail, Google-Zugang, Datensicherung, Punktekatalog) sind aus der App entfernt; was dort stand, findet ihr
  über den Knopf **?** im Handbuch der jeweiligen Seite. Warnungen und Hinweise auf etwas, das zu tun ist, bleiben.
  Unter *Zustand und Updates* steht der Text zu einem Punkt nur noch, wenn dort etwas zu tun ist.
- **Schwarzes Brett übersichtlicher**: Das Banner oben zeigt je Eintrag nur noch den Titel, ohne Überschrift,
  Verfasser und Uhrzeit (die stehen am Brett); ein Tipp darauf öffnet die Nachricht, *+1 weitere* und *Brett öffnen*
  erscheinen erst bei mehreren Einträgen. Auch laufende Umfragen stehen so in einer Zeile. Beim Schreiben ist der Titel
  eine große Eingabe, *Sichtbar für*, *Sichtbar bis* und die Versandwege stehen im Abschnitt *Sichtbarkeit und
  Versand*. Die Werkzeugleiste des Texteditors (auch bei Neuigkeiten, eigenen Seiten und E-Mails) zeigt nur die
  häufigen Werkzeuge; Schriftart, Größe, Ausrichtung, Einzug, Trennlinie, Tabelle und Weiteres liegen hinter **⋯**.
- **Gleichmäßigere Abstände**: Erklärtexte kleben nicht mehr an der ersten Karte darunter (z. B. bei Dienstgraden,
  Navigation und Rollen), Umfragen stehen mit Abstand untereinander, Karten am Brett, in den Umfragen und unter
  *Umfragen verwalten* haben einen Innenrand, beim Anlegen mehrerer Mitglieder stehen die Felder je Person mit Abstand
  in einem Rahmen, und die Knöpfe *Ansehen* und *Bearbeiten* der Rollen stehen auf dem Handy nebeneinander.
- **Rückfragen im Fenster der App**: Fragen wie „Wirklich löschen?“, Eingaben (z. B. Grund beim Ablehnen eines
  Bildes, Adresse eines Links) und Hinweise erscheinen jetzt in einem eigenen Fenster der App statt im Fenster des
  Browsers. Es sieht auf jedem Gerät gleich aus; Löschen und Entfernen steht auf einem roten Knopf. Hinter einem Fenster ist die Seite
  stärker abgedunkelt und unscharf, das Fenster hat oben eine Linie in der Farbe der Kompanie und einen kräftigeren
  Schatten.
- **Benachrichtigung vom Schwarzen Brett auch an den Verfasser**: Wer eine Nachricht mit *Benachrichtigung senden*
  schreibt, bekommt die Benachrichtigung jetzt selbst auch (auf seinen Geräten, auf denen die Art *Schwarzes Brett:
  dringende Nachrichten* eingeschaltet ist), wie schon bisher die Mail. Das gilt für intern, öffentlich, Kompanie und
  Einzeln, auch wenn der Verfasser selbst nicht ausgewählt ist.
- **Datei auswählen auf Deutsch**: Beim Hochladen von Dokumenten, beim Import von Mitgliedern, Ehemaligen und Ämtern,
  beim Einreichen von Bildern und beim Fehlermelden steht jetzt überall ein eigener Knopf *Datei auswählen* mit dem
  Namen der gewählten Datei, statt des Feldes des Browsers (je nach Gerät z. B. „Choose File“).
- **„… verwalten“ immer oben**: Bei den eigenen Seiten steht der Knopf jetzt oben wie bei Terminen und Dokumenten;
  bei Sponsoren und Kompanien gibt es ihn neu, für alle, die sie pflegen dürfen.
- **Punktekatalog verwalten**: *Wert ändern* und *Deaktivieren* stehen in einer Reihe, die Punkte neben dem Titel.

### Behoben

- **Vorabversionen erhalten**: Der Haken unter [Einstellungen → Zustand und Updates](app:einstellungen/zustand) bleibt nach
  dem Bestätigen der Rückfrage gesetzt, und die Installation bekommt wieder Vorabversionen. Bisher war er danach wieder
  aus, und der Kanal blieb bei den freigegebenen Versionen.
- **Hilfe zu Impressum und Datenschutz**: Der Knopf *?* auf der Seite *Impressum und Datenschutz* der Einstellungen
  öffnet jetzt deren Anleitung statt der Übersicht der Einstellungen.
- **Benachrichtigungen, Zuletzt verschickt**: Die Liste unter [Einstellungen → Benachrichtigungen](app:einstellungen/benachrichtigungen) ist jetzt nach
  Zeit sortiert, das Neueste oben, statt nach Art.
- **Eigene Seiten**: Der Text einer Seite klebt nicht mehr am Rand ihrer Karte.
- **Darstellung**: Lange Wörter in Kacheln, Überschriften und Knöpfen werden richtig getrennt (z. B. nicht mehr
  „Punktekatalo-g“); *Sicherung wiederherstellen* ragt nicht mehr über den Rand; Zurück-Knöpfe in Formularen stehen
  links statt mittig; Eingabefelder nebeneinander stehen auf einer Höhe, auch bei zweizeiliger Beschriftung; Foto und
  Knopf beim Anlegen von Mitgliedern und Ehemaligen sind mittig.
- **Eingabefelder**: Einige Felder (Titel am Schwarzen Brett, Absendername und Mailserver, Name eines Untermenüs)
  waren grau statt wie alle anderen; Hinweise unter einem Feld sind nicht mehr fett und in Großbuchstaben.

## [0.7.0] – 2026-11-07

### Neu

- **Öffentliche Nachrichten am Schwarzen Brett**: Beim Schreiben wählt ihr unter *Sichtbar für*, ob eine Nachricht
  *intern* bleibt (wie bisher: Mitglieder und alle mit dem internen Kalender) oder *öffentlich* ist. Öffentliche
  Nachrichten sehen alle, auch Besucher ohne Anmeldung, im Banner oben auf jeder Seite und auf der Seite des Bretts,
  samt Bildern, Anhängen und dem Namen der Person, die sie geschrieben hat. Die Benachrichtigung geht dann zusätzlich an
  alle Geräte ohne Brett, auch ohne Anmeldung (neue Art *Schwarzes Brett: öffentliche Nachrichten*, bei der ersten
  öffentlichen Nachricht einmalig dazugeschaltet); die E-Mail geht weiter nur an Mitglieder und alle mit dem internen
  Kalender. Die Sichtbarkeit lässt sich beim Bearbeiten ändern und steht im Verlauf. Bisherige Nachrichten bleiben
  intern.
- **Ämter: Begleitpersonen**: Bei einer Amtszeit kann jede Person eine Begleitung bekommen, die das Amt nicht selbst
  bekleidet, z. B. Partnerin oder Partner. Sie steht im Zeitstrahl abgesetzt darunter („Max Muster“, darunter „mit
  Erika Muster“), als Mitglied, Ehemaliger oder Name ohne Profil, auf Wunsch mit Foto. Einzutragen in der Verwaltung
  der Ämter bei der Amtszeit mit *+ Begleitung*. Begleitpersonen bekommen keinen mit dem Amt verknüpften Titel, und
  ihr Ausscheiden beendet keine Amtszeit. Partner, die bisher als eigene Person im Amt stehen, dort entfernen und als
  Begleitung neu eintragen.
- **Kompanien: Suche und Sortierung**: Über der Liste der Kompanien stehen wie in der Mitgliederliste ein Suchfeld
  (Name oder Kurzname) und die Sortierung: wie festgelegt, nach Name, Gründung oder Mitgliedern, jeweils auch
  umgekehrt. Aufgelöste Kompanien stehen weiter unten; die Wahl merkt sich das Gerät.
- **Ämter: Einzahl für Gruppen**: Eine Gruppe der Ämter kann neben ihrer Bezeichnung (z. B. „Königshäuser“) eine
  Einzahl bekommen (z. B. „Königshaus“). Über einer einzelnen gemeinsamen Amtszeit steht dann „Königshaus 2026–2028“
  statt „Königshäuser 2026–2028“. Einzutragen in der Verwaltung der Ämter unter *Gruppen → Bearbeiten*; ohne Einzahl
  bleibt es wie bisher.
- **News direkt in der App schreiben**: Unter [Einstellungen → Neuigkeiten](app:einstellungen/news) gibt es die Quelle *In der App schreiben*,
  mit WordPress und ohne. Dann steht unter News der Knopf *Neuer Beitrag*: Titel und formatierter Text mit Bildern,
  das erste Bild erscheint auch in der Liste. Beiträge lassen sich später bearbeiten und löschen; wer News abonniert
  hat, bekommt bei einem neuen Beitrag eine Benachrichtigung. Schreiben darf, wer das neue Recht *News schreiben* hat;
  mit der Quelle legt die App die Rolle „News verwalten“ an. Es gilt immer eine Quelle, Beiträge der Webseite oder
  eines Feeds und der App werden nicht gemischt; beim Wechsel bleibt alles gespeichert. Wer die News von der Webseite
  oder aus einem Feed bezieht, merkt nichts davon.
- **Zugang beantragen**: Personen können ihren Zugang zur App jetzt selbst beantragen, ihr müsst nicht mehr jedes
  Konto selbst anlegen. Eingeschaltet wird das unter [Einstellungen → Zugang beantragen](app:einstellungen/rollen) (bis dahin bleibt es aus).
  Die Person füllt ein Formular mit Name, E-Mail-Adresse, Benutzername und Passwort aus; die Anfrage erscheint unter
  [Verwaltung → Anfragen](app:verwaltung/anfragen). Freigeben oder ablehnen darf, wer Mitglieder anlegen darf, Admins also immer. Erst mit
  der Freigabe entsteht das Konto, mit der dort gewählten Rolle (Admin lässt sich dabei nicht vergeben) und dem
  Passwort aus der Anfrage. Ist der Mailversand eingerichtet, bestätigt die Person zuerst ihre E-Mail-Adresse und
  erfährt das Ergebnis per Mail. Ohne Mailversand sieht sie es bei der Anmeldung oder unter *Stand meiner Anfrage*.
  Unbearbeitete Anfragen löscht die App nach 30 Tagen. Wer Mitglieder anlegen darf, kann sich über neue Anfragen
  benachrichtigen lassen.

### Geändert

- **Kompanien ohne Ämter**: Ämter gehören zu den Personen, nicht zu einer Kompanie. Die Seite einer Kompanie zeigt
  deshalb keine Amtszeiten mehr, und beim Anlegen oder Bearbeiten gibt es keine *Gruppe der Ämter* mehr. Bisher
  gesetzte Verknüpfungen wertet die App nicht mehr aus.
- **Kompanien haben eine eigene Seite**: Ein Tipp auf eine Kompanie klappt die Karte nicht mehr auf, sondern öffnet
  ihre Seite mit Logo, Beschreibung, laufenden Ämtern, Links und der Liste ihrer Mitglieder (wie bisher nur für alle,
  die die Mitgliederliste sehen dürfen). Die Seite hat einen eigenen Link zum Teilen; „‹ Alle Kompanien“ führt zurück
  an dieselbe Stelle der Liste. Auch der Name einer Kompanie auf der Seite einer Person führt jetzt direkt dorthin.
- **Ämter einer Person**: Im Zeitstrahl einer Person steht nur noch das Amt (bzw. seine Einzahl), nicht mehr
  zusätzlich die Gruppe.
- **Jahreszahl bei Terminen**: Liegt ein Termin nicht im laufenden Jahr, zeigt die App beim Datum jetzt auch das Jahr,
  z. B. „Fr., 8. Jan. 2027“. Das gilt für die internen und öffentlichen Termine, die Termine bei der Anwesenheit, die
  Verwaltung der öffentlichen Termine und die anstehenden Geburtstage.

### Behoben

- **Mitglieder auf der Seite einer Kompanie**: Ist der Bereich *Mitglieder* aus (z. B. in einer Bruderschaft ohne
  eigene Mitgliederliste), fehlten die zugeordneten Personen. Jetzt stehen ihre Namen dort für alle mit dem Recht, die
  Mitgliederliste zu sehen, auch für Gäste mit diesem Recht. Die Liste sieht aus wie die Mitgliederliste (Foto, Titel,
  Dienstgrad); ein Tipp öffnet die Seite der Person, auch ohne Mitgliederliste, wenn ihr *Ämter* nutzt. Dort blättert
  man in den Mitgliedern dieser Kompanie weiter.
- **Kein Leerraum zum Scrollen auf kurzen Seiten**: Seiten mit wenig Inhalt, etwa die leere Galerie, ließen sich im
  Handy-Browser ein Stück nach unten scrollen, so dass die Knöpfe oben aus dem Bild rutschten. Die Mindesthöhe der App
  richtet sich jetzt nach dem sichtbaren Bildschirm (ohne die Adressleiste des Browsers).
- **Formatierte Texte im dunklen Design lesbar**: Eingefügter Text (z. B. von eurer Webseite) brachte oft eine feste
  Schriftfarbe mit, die im dunklen Design kaum zu lesen war, etwa in der Beschreibung einer Kompanie. Farben mit zu
  wenig Kontrast zum Hintergrund zeigt die App jetzt in ihrer normalen Schriftfarbe, sehr große Zeilenabstände setzt
  sie auf normal. Gespeichert bleibt der Text unverändert. Links in formatierten Texten sind im dunklen Design jetzt
  ebenfalls gut lesbar.
- **Kompanien**: *Webseite* und *App der Kompanie* sind jetzt Knöpfe im Stil der App statt blauer Standard-Links.
- **Kompanien: transparente Logos**: Logos der Kompanien standen in der Liste, auf der Seite der Kompanie und in der
  Verwaltung immer in einem weißen Kasten, auch wenn sie einen durchsichtigen Hintergrund haben. Jetzt stehen sie
  direkt auf der Karte, im hellen wie im dunklen Design. Ein Logo ohne durchsichtigen Hintergrund (z. B. ein JPG)
  zeigt weiter seinen eigenen Hintergrund.
- **Hinweis „Einrichtung starten“ nach dem Wiederherstellen**: Wurde in einer frisch installierten, noch nicht
  eingerichteten App eine Sicherung eingespielt, stand der Hinweis „Einrichtung starten“ weiter über jeder Seite. Mit
  der Sicherung gilt die App jetzt als eingerichtet; Bereiche und Verbindungen lassen sich wie gewohnt unter
  *Einstellungen* ändern.
- **Zurück-Knopf in Untermenüs oben**: Auf *Sponsoren*, *Kompanien*, eigenen Seiten und auf *Termine*, *News* und
  *Galerie*, wenn sie in einem Untermenü stehen (z. B. *Intern*), stand *‹ Intern* bzw. *‹ <Untermenü>* unter dem
  Inhalt. Er steht jetzt wie auf den übrigen Seiten oben.
- **Hinweis „Einrichtung starten“**: Die Karte hatte keinen Innenabstand, der Text stand am linken Rand, und die
  Kacheln darunter schlossen ohne Abstand an. Beides ist behoben; dasselbe gilt für den Hinweis „Anmelden“ auf Seiten,
  die erst nach der Anmeldung zu sehen sind.

## [0.6.0] – 2026-11-06

### Neu

- **Bilder und Videos aus der Galerie herunterladen**: Unter [Einstellungen → Galerie](app:einstellungen/galerie) schaltest du mit *Knöpfe zum
  Herunterladen zeigen* ein, dass alle, die die Galerie sehen, jedes Bild und Video in der Großansicht und jedes Album
  als ZIP-Datei herunterladen können, Bilder in voller Auflösung, Videos unverändert. Große Alben passen auch über
  4 GB in ein ZIP (ZIP64). Vorgabe: aus.
- **Eigene Seiten**: Mit dem neuen Bereich *Seiten* (unter [Einstellungen → Bereiche](app:einstellungen/bereiche)) legt ihr unter [Verwaltung →
  Einrichtungen → Seiten](app:verwaltung/seiten) freie Seiten wie Geschichte, Festprogramm oder Anfahrt an, mit formatiertem Text und
  Bildern. Je Seite wählst du, ob sie alle sehen, auch ohne Anmeldung, oder nur Mitglieder. Jede Seite ist ein eigener
  Punkt der Navigation, den du unter [Einstellungen → Navigation](app:einstellungen/navigation) in die Leiste oder ein Untermenü schiebst; ohne eigene
  Navigation stehen die Seiten in der Reihenfolge, die du per Ziehen festlegst, nach den Bereichen. Speichert jemand
  dazwischen, überschreibt die App seine Fassung nicht. Mit dem Bereich gibt es die Rolle *Seiten verwalten*.
- **Bilder für die Galerie einreichen**: Mitglieder mit dem neuen Recht *Bilder einreichen* schlagen unter *Galerie →
  Bilder einreichen* Bilder für ein Album vor und bestätigen, dass sie die Rechte haben und die erkennbaren Personen
  einverstanden sind. Wer *Einreichungen prüfen* hat, bekommt eine Benachrichtigung und gibt sie unter *Galerie →
  Einreichungen prüfen* öffentlich oder nur für Mitglieder frei oder lehnt sie mit Grund ab; die Person erfährt das
  Ergebnis. Abgelehnte Bilder löscht der Server sofort. Mit der Galerie gibt es die neue Rolle *Galerie verwalten*
  (mit dem Recht zum Prüfen), auch bei Installationen mit schon eingeschalteter Galerie.
- **Galerie ohne Google Drive und eigene Bilder**: Unter [Einstellungen → Galerie](app:einstellungen/galerie) wählst du als Quelle *Google
  Drive* oder *eigener Server*. Mit beiden Quellen legst du dort Alben nur in der App an und lädst Bilder (JPG, PNG,
  WebP) in jedes Album hoch, auch in eines aus Drive, öffentlich oder nur für Mitglieder. Der Server entfernt beim
  Hochladen Metadaten wie den Aufnahmeort. Der Abgleich mit Drive lässt hochgeladene Bilder in Ruhe, und die Sicherung
  enthält sie. Mit *eigener Server* braucht die Galerie keinen Google-Zugang.

- **Fotos für Personen ohne Konto in den Ämtern**: Unter [Verwaltung → Einrichtungen → Ämter](app:verwaltung/aemter) lädst du bei einer
  Amtszeit für Personen ohne Profil (z. B. frühere Mitglieder oder Hofdamen) ein Foto hoch. Steht derselbe Name schon
  mit Foto in einer früheren Amtszeit, bietet die App an, es zu übernehmen. Die Fotos sind wie die Ämter für alle
  sichtbar; bei Mitgliedern bleibt das Foto ihres Profils maßgeblich.
- **Gemeinsame Amtszeiten mit Gruppenbild**: Haben alle Ämter einer Gruppe dieselben Jahre, z. B. ein Königshaus oder
  ein Vorstand, schaltest du unter [Verwaltung → Einrichtungen → Ämter](app:verwaltung/aemter) bei der Gruppe *Amtszeiten gemeinsam* ein.
  Dann trägst du Jahre, Gruppenbild und die Personen aller Ämter in einem Formular ein; vorhandene Amtszeiten mit
  denselben Jahren fasst die App beim Einschalten zusammen. Unter *Ämter* steht je gemeinsame Amtszeit eine Karte mit
  dem Bild und wer welches Amt hatte, für alle sichtbar wie die Ämter. Ausschalten behält alle Daten.
- **Kompanien**: Der neue Bereich *Kompanien* (unter [Einstellungen → Bereiche](app:einstellungen/bereiche) einschalten) zeigt eure Kompanien mit
  Logo in eurer Reihenfolge, für alle, auch ohne Anmeldung. Jede Karte klappt auf und zeigt Beschreibung (auch mit
  Bildern), Gründungsjahr, Links zur Webseite und zur App der Kompanie, die laufenden Ämter einer verknüpften Gruppe der Ämter
  und die Mitglieder (die nur, wer die Mitgliederliste sieht). Aufgelöste Kompanien stehen unten als „ehemalig“.
  Gepflegt werden sie unter [Verwaltung → Einrichtungen → Kompanien](app:verwaltung/kompanien); Mitglieder ordnest du dort oder auf der Seite
  der Person in der Verwaltung zu, auch zu mehreren Kompanien. In der Mitgliederliste stehen die Kompanien auf der
  Seite der Person, und die Liste lässt sich nach Kompanie filtern. Dazu kommt die Rolle *Kompanien verwalten*. Passen
  in der Standardnavigation nicht alle Punkte in die Leiste, stehen Kompanien und Sponsoren unter *Intern*.
- **Sponsoren**: Der neue Bereich *Sponsoren* (unter [Einstellungen → Bereiche](app:einstellungen/bereiche) einschalten) zeigt eure Sponsoren mit
  Logo und Link in eurer Reihenfolge, für alle, auch ohne Anmeldung. Gepflegt werden sie unter [Verwaltung →
  Einrichtungen → Sponsoren](app:verwaltung/sponsoren): anlegen, Link und Logo (PNG, JPG, WebP oder SVG) hinzufügen, per Ziehen ordnen. Dazu kommt
  die Rolle *Sponsoren verwalten*. Wer die Sponsoren sieht, legst du unter [Einstellungen → Rollen](app:einstellungen/rollen) mit dem Haken
  *Sponsoren* fest.
- **Navigation einrichten**: Unter [Einstellungen → Navigation](app:einstellungen/navigation) legst du fest, welche Bereiche unten in der Leiste
  stehen und in welcher Reihenfolge, fasst Bereiche zu Untermenüs mit eigenem Namen und Symbol zusammen (wie *Intern*)
  oder blendest sie aus. *Verwaltung* und *Meins* stehen immer ganz rechts. Jede Person sieht weiter nur, was sie sehen
  darf. Solange du nichts speicherst, bleibt die Navigation wie bisher; *Standardnavigation wiederherstellen* führt
  jederzeit dorthin zurück.
- **Impressum und Datenschutz**: Unter [Einstellungen → Impressum und Datenschutz](app:einstellungen/rechtliches) (und im Einrichtungsassistenten) trägst
  du die Links auf euer Impressum und eure Datenschutzerklärung ein, z. B. auf eurer Webseite. Die App zeigt sie allen,
  auch ohne Anmeldung: auf der Anmeldeseite, unten im Konto und unten in der Hilfe. Solange einer fehlt, ist die Zeile
  *Impressum und Datenschutz* unter *Zustand und Updates* gelb. Die Hilfe listet, welche Daten die App verarbeitet, als
  Hilfe für eure Datenschutzerklärung.
- **Nachvollziehen, was an Benachrichtigungen verschickt wurde**: Unter [Einstellungen → Benachrichtigungen](app:einstellungen/benachrichtigungen) steht
  jetzt für jede Art, wann sie zuletzt verschickt wurde und an wie viele Geräte, dazu wann der Push-Dienst zuletzt eine
  Nachricht angenommen oder abgelehnt hat (mit Grund). So siehst du ohne Blick in die Datenbank, ob eine Benachrichtigung
  verschickt wurde oder ob niemand sie eingeschaltet hatte.

### Geändert

- **Bilder in formatierten Texten** (Schwarzes Brett, E-Mails, Signatur, Beschreibung der Kompanien) werden beim
  Hochladen auf höchstens 2048 Pixel an der längeren Kante verkleinert, damit Datenbank und Sicherungen nicht unnötig
  wachsen. Kleinere Bilder bleiben, wie sie sind.
- **Seite eines Mitglieds erscheint schneller**: Öffnest du in der Verwaltung eine Person oder blätterst weiter, stehen
  Dienstgrad, Foto, Rolle, Punkte und Offen sofort da (aus der Mitgliederliste); der Rest der Seite folgt, sobald ihre
  Daten geladen sind. Die übrigen Personen lädt die App jetzt zu zwölft in einer Anfrage vor statt einzeln, und ohne
  dabei auf Fotos und Abzeichen zu warten; so sind auch weiter entfernte Seiten deutlich früher bereit.
- **Listen öffnen schneller**: Öffnest du unter [Verwaltung → Listen](app:verwaltung/listen) eine Liste erneut, steht sie sofort da und wird im
  Hintergrund aktualisiert, wie die übrigen Seiten der App. Hat jemand sie inzwischen geändert, zeigt die App gleich
  danach den neuen Stand.
- **Benachrichtigungen mit hoher Dringlichkeit**: Alle Benachrichtigungen gehen jetzt mit hoher Dringlichkeit an den
  Push-Dienst, wie Google es für sichtbare Benachrichtigungen empfiehlt. Mit normaler Dringlichkeit kann Android sie im
  Ruhezustand zurückhalten, z. B. die Benachrichtigung am Geburtstag am frühen Morgen.

### Behoben

- **Download-Seite mit langem Namen auf dem Handy**: Ein langer Kompaniename lief im Kopf der Download- und
  Installationsseite über den Bildschirmrand, die Seite wurde breiter als das Handy. Der Name bricht jetzt um, Logo und
  Überschrift sind auf schmalen Bildschirmen etwas kleiner.
- **Download-Seite: Abstand unter dem Kopf**: Zwischen dem Kopf der Download- und Installationsseite und der ersten Karte
  fehlte der Abstand, ebenso unten unter dem Fußtext.

## [0.5.0] – 2026-11-01

### Neu

- **Fortschritt beim Update**: Während ein Update läuft, zeigt [Einstellungen → Updates](app:einstellungen/zustand) einen Fortschrittsbalken mit
  dem aktuellen Schritt (Paket laden, Daten sichern, Sicherung in Google Drive, einspielen), beim Sichern und Hochladen
  auch in MB. Die Größe der Sicherung ist dabei aus der letzten geschätzt („ca.“). Läuft beim Öffnen der Seite
  schon ein Update (z. B. das automatische), wird es ebenso angezeigt.
- **Adresse**: Jedes Mitglied trägt seine Postanschrift (Land, Straße, Hausnummer, Zusatz, PLZ, Ort) unter
  *Meins → Konto* selbst ein und ändert sie dort. Die App prüft die Eingabe wie bei E-Mail und Handynummer: Die
  Postleitzahl muss zum gewählten Land passen (z. B. fünf Ziffern in Deutschland, „1234 AB“ in den Niederlanden), die
  Hausnummer rückt aus der Straße in ihr Feld, und Hinweise wie „Hausnummer vergessen?“ stehen direkt am Feld.
- **Rechte „Persönliche Angaben sehen“ und „Persönliche Angaben pflegen“**: Adresse, Handynummer, E-Mail-Adresse und
  Geburtsdatum anderer stehen in der Verwaltung auf der Seite der Person unter **Persönliche Angaben**, sichtbar nur mit
  diesem Recht; ändern lassen sie sich mit „Persönliche Angaben pflegen“.
- **Beim Anlegen alles eintragen**: Beim Anlegen einzelner und mehrerer Mitglieder und beim Import lassen sich jetzt
  auch Handynummer und Adresse eintragen (im Import mit den Spalten Handynummer, Straße, Hausnummer, Adresszusatz, PLZ,
  Ort und Land).
- **Adresse, Handynummer und E-Mail in Listen**: Unter [Verwaltung → Listen](app:verwaltung/listen) gibt es die Datenspalten Adresse (die ganze
  Anschrift in einer Spalte), Straße und Nr., PLZ, Ort, Land, Handynummer und E-Mail, auch im CSV-Export. Mit den
  einzelnen Spalten lassen sich aus der CSV Serienbriefe und Adressetiketten erstellen. Die Werte sieht nur, wer das
  Recht „Persönliche Angaben sehen“ hat. Die Handynummer steht in der CSV als „0049 …“, damit Excel sie unverändert
  übernimmt.
- **Vorlauf der anstehenden Geburtstage einstellbar**: Unter [Einstellungen → Geburtstage](app:einstellungen/kalender) legen Admins fest, wie viele
  Tage vorher ein Geburtstag oben bei den Terminen unter „Anstehende Geburtstage“ erscheint (nur am Tag selbst bis 30
  Tage vorher, anfangs wie bisher 7 Tage).
- **Verschlüsselte Sicherungen**: Unter [Einstellungen → Datensicherung](app:einstellungen/datensicherung) lässt sich ein **Passwort für Sicherungen**
  einrichten. Heruntergeladene Sicherungen und alle Sicherungen in Google Drive sind damit verschlüsselt; wer die Datei
  findet, kann ohne das Passwort nichts darin lesen. Die App speichert das Passwort nicht: **Ist es verloren, lässt
  sich keine verschlüsselte Sicherung mehr wiederherstellen.** Beim Wiederherstellen fragt die App danach.
- **Meine Daten herunterladen (Auskunft nach DSGVO)**: Unter *Konto → Meine Daten* lädt jede Person alles herunter,
  was die App über sie gespeichert hat. Wer jeden darin enthaltenen Bereich auch in der App sehen darf (in der Regel
  Admins), kann das auch für eine Person tun ([Verwaltung → Mitglied → Daten exportieren (Auskunft)](app:verwaltung/mitglieder)), dann ohne
  Antworten in anonymen Umfragen.
- **Aufbewahrungsfrist für Ehemalige**: Admins können unter [Verwaltung → Ehemalige](app:verwaltung/ehemalige) einstellen, nach wie vielen Jahren
  seit dem Ausscheiden die App die persönlichen Angaben von Ehemaligen löscht (Kontaktdaten, Anmeldung, Geburtstag,
  Strafen, Anwesenheiten u. a.). Für die Chronik bleiben Name, Ämter, Punkte, Dienstgrad, Titel, Foto und Eintrittsjahr.
  Voreingestellt ist aus; eine Vorschau zeigt, wen es trifft.
- **Listen als PDF-Datei**: [Verwaltung → Listen → PDF herunterladen](app:verwaltung/listen) speichert die Liste direkt als PDF, ohne
  Druckdialog. *Drucken* gibt es weiterhin als eigenen Knopf.
- **Listen: Breite „automatisch“**: Eine Spalte kann so breit sein wie ihr längster Inhalt bzw. ihre Überschrift; die
  übrigen Spalten teilen sich den Rest.
- **Listen: Sortieren nach jeder Spalte**: Unter *Zeilen* sortiert **Sortieren nach** die Liste nach dem Namen oder einer
  beliebigen Datenspalte, auf- oder absteigend (Geburtstage nach Tag im Jahr). Zeilen ohne Wert kommen ans Ende.
- **Listen: weitere Personen**: Unter *Zeilen → In die Liste kommen* wählst du neben den Mitgliedern auch Rollen ohne
  Mitgliederbereich (z. B. Anwärter oder eine Jugendgruppe). „Mitglieder aktualisieren“ holt dann auch sie in die Liste.

### Geändert

- **Interne Bilder der Galerie schneller**: Interne Bilder bleiben geschützt (der Server prüft bei jedem Abruf, ob
  jemand angemeldet ist und sie sehen darf), erscheinen aber nach dem ersten Laden sofort, auch offline: Die App
  speichert sie auf dem Gerät und löscht sie beim Abmelden. Beim Antippen eines Albums lädt sie die ersten
  Vorschaubilder schon vorab. Unter WordPress antwortet die App auf Bildabrufe, bevor Theme und andere Plugins starten.
- **Ladeanzeige in Galeriebildern**: Solange ein Bild der Galerie noch lädt oder aufbereitet wird, dreht sich darin ein
  kleiner Ring (in der Übersicht, im Album und in der Großansicht). Ist das Bild schon auf dem Gerät, erscheint er nicht.
- **Wischen in der Großansicht der Galerie**: Beim Wischen folgt das Bild dem Finger, daneben steht schon das nächste
  bzw. vorherige, und beim Loslassen gleitet es weiter, wie auf den Seiten der Mitglieder. Zu kurz gezogen schnappt es
  zurück. Auch **‹**, **›** und die Pfeiltasten blättern mit dieser Bewegung. Das gilt auch auf Videos; nur in den
  Leisten des Players (Zeitleiste, Ton, Vollbild) bedient Wischen weiter das Video. Tippen auf das Video spielt es ab
  bzw. hält es an.
- **Videos der Galerie starten schneller**: Der Server reicht ein Video jetzt weiter, während es aus Google Drive kommt,
  statt jedes Stück erst ganz zu laden. Unter WordPress antwortet die App auf Videoabrufe wie bei Bildern, bevor Theme
  und andere Plugins starten. In der Großansicht lädt ein Video schon beim Öffnen, nicht erst beim Tippen auf ▶.
  Anmeldung und Rechte prüft der Server wie bisher vor jedem Abruf. Tipp für Admins: „Fürs Web optimiert“
  gespeicherte Videos starten noch schneller (siehe Handbuch, Galerie einrichten).
- **Gerüst statt Ladeschnecke**: Auch beim ersten Öffnen einer Seite (und nach jedem Update) erscheint beim Laden
  ein graues Gerüst in der Form der Seite (Liste, News, Galerie, Formular, Beitrag) statt „Lädt …“ mit sich drehendem
  Kreis. Ab dem zweiten Öffnen zeigt es wie bisher genau den Aufbau, den die Seite beim letzten Mal hatte. Ebenso
  zeigt das vergrößerte Foto einer Person beim Laden eine Fläche in seiner Größe.
- **Schnellerer Start**: Die App erscheint beim Öffnen sofort mit dem zuletzt geladenen Stand, statt zuerst auf den
  Webserver zu warten (bisher bis zu 2,5 Sekunden, je nach Hoster). Die aktuelle Fassung holt sie im Hintergrund; hat
  sich etwas Wichtiges geändert (z. B. Rechte, Bereiche oder eine neue Version), lädt sie sich kurz nach dem Start
  einmal neu. Nach dem Anmelden und beim Aufruf über einen Link von der Webseite wartet sie wie bisher auf den Server.
- **Seiten öffnen schneller**: Sobald *Meins*, die Startseite der *Verwaltung* (auch *Einrichtungen*) oder die
  *Einstellungen* zu sehen sind, lädt die App die Daten der Seiten dahinter im Hintergrund vor, ebenso die Seite
  *Öffentliche Termine verwalten* für alle, die Termine pflegen. Diese Seiten erscheinen dann schon beim ersten Öffnen
  ohne Warten auf den Server.
- **Karte** bei einem Termin öffnet den Ort jetzt wie die Adresse eines Mitglieds in der Karten-App des Geräts: Auf
  Android wählt man die App aus (z. B. Google Maps oder Waze), auf iPhone, iPad und Mac öffnet sich Apple Karten, am
  PC weiter Google Maps.
- Handynummer und Geburtsdatum anderer sieht und ändert nicht mehr jeder, der Mitglieder anlegen darf, sondern nur,
  wer die neuen Rechte für persönliche Angaben hat. Rollen, die bisher „Mitglieder anlegen“ hatten, bekommen beide
  Rechte beim Update automatisch; wer sie nicht braucht, dem nimmt der Admin sie unter [Einstellungen → Rollen](app:einstellungen/rollen) weg.
- Auf der Seite eines Mitglieds in der Verwaltung steht der Geburtstag jetzt bei **Persönliche Angaben** (ohne das Recht
  dazu weiter bei Eintritt und Austritt), und **Persönliche Angaben** stehen unter den Ämtern, über *Konto und Rolle*.
  Antippen der Adresse zeigt den Ort in der Karten-App: auf Android mit Auswahl der App, auf iPhone und Mac in Apple
  Karten, am PC in Google Maps.
- **Sicherungen in Google Drive verschlüsselt**, sobald unter [Einstellungen → Datensicherung](app:einstellungen/datensicherung) ein Passwort für
  Sicherungen eingerichtet ist (ohne Passwort wie bisher unverschlüsselt). Ältere, unverschlüsselte Sicherungen im Ordner
  löscht die App, sobald die erste verschlüsselte dort liegt und das Passwort einmal mit *Passwort prüfen* (oder einer
  Wiederherstellung) bestätigt ist.
- **Galerie**: Zurück aus einem Album (mit **‹ Alle Alben** oder der Zurück-Taste) führt wieder an die Stelle der
  Übersicht, an der man das Album geöffnet hat, statt an den Anfang. Nach dem Schließen der Großansicht steht das
  zuletzt gezeigte Bild im Album im Blick, auch wenn man weit geblättert hat.
  Die Zurück-Taste (oder -Geste) bei offener Großansicht schließt nur das Bild, statt das Album zu verlassen.
- **Listen: Geburtstag in langer Form**: Die Spalte Geburtstag zeigt das Datum ausgeschrieben („9. März“), mit dem
  Recht „Persönliche Angaben sehen“ samt Geburtsjahr („9. März 1990“), auch in CSV und PDF.

### Behoben

- **Gerüst passt zur Seite**: Das graue Gerüst beim Laden ragte auf manchen Seiten über den Rand (z. B.
  *Datensicherung*, *Benachrichtigungen*), zeigte unter *Einstellungen* die Zeile „‹ Verwaltung“ doppelt und stellte
  manche Knöpfe und Schalter anders dar als die fertige Seite.
- Direkt nach einer eigenen Änderung konnte eine Seite den Stand von vorher zeigen, wenn die App ihre Daten gerade im
  Hintergrund lud und die Antwort erst nach der Änderung ankam. Solche Antworten gelten jetzt als veraltet.
- **Neu laden zeigt Änderungen anderer**: Hatte jemand anderes gerade etwas geändert (z. B. eine Nachricht am Brett)
  und lud man die Seite kurz nach einer vorigen Aktualisierung neu, blieb bis zu zehn Sekunden der alte Stand stehen.
  Die App fragt jetzt auch dann im Hintergrund nach und zeigt die Änderung.
- **Logo im Hinweis „App aufs Handy holen“**: Ist das Logo nicht quadratisch, wurde es im Hinweis oben auf der
  Seite in die Breite gezogen. Es erscheint jetzt im richtigen Seitenverhältnis, wie in der Kopfzeile.
- **Update-Knopf nach langer Sicherung wieder aktiv**: Brach die Verbindung während eines langen Updates ab (z. B. durch
  ein Zeitlimit des Hosters), gab die App den Knopf wieder frei, obwohl das Update auf dem Server weiterlief; ein zweiter
  Klick meldete dann „Gerade läuft schon ein Update“. Jetzt wartet die App, bis das Update fertig ist, und lädt danach
  die neue Version.
- **Update mit Passwort für Sicherungen und Google Drive**: Ist ein Passwort für Sicherungen eingerichtet, scheiterte
  das Update bei einer großen Sicherung mit einem kritischen Fehler (zu wenig Arbeitsspeicher beim Verschlüsseln der
  Sicherung für Google Drive). Die Sicherung wird jetzt in kleinen Stücken verschlüsselt. Das wirkt bei Updates, die
  von dieser Version aus eingespielt werden.
- **WordPress mit SQLite**: Auf WordPress-Seiten, die statt MySQL die SQLite-Integration nutzen, brach das Aktivieren
  oder Aktualisieren des Plugins seit Version 0.3.0 mit einem Datenbankfehler ab (`SUBSTRING_INDEX` beim Umbau des
  Schwarzen Bretts). Der Umbau kommt jetzt ohne diese MySQL-Funktion aus; auf MySQL und MariaDB ändert sich nichts.

### Entfernt

- **Liste aller Geburtstage**: Die Seite „Alle Geburtstage“ gibt es nicht mehr. Die Karte „Anstehende Geburtstage“ bei
  den Terminen bleibt; die Benachrichtigung am Geburtstag öffnet jetzt die Termine.

### Sicherheit

- **Mitgliederfotos folgen den Rechten von Gast**: Hat ein Admin unter [Einstellungen → Rollen](app:einstellungen/rollen) der Rolle **Gast** die
  Mitgliederliste bzw. die Ehemaligen weggenommen, waren die Fotos darin trotzdem ohne Anmeldung abrufbar. Jetzt sind
  sie nur noch öffentlich, wenn Gast die Liste sehen darf. Die Originalauflösung gibt es nur noch nach der Anmeldung.
- **HTTPS (eigenständige Version)**: Über HTTPS schickt die App jetzt HSTS mit (der Browser öffnet sie danach nur noch
  verschlüsselt), wenn sie im Hauptverzeichnis ihrer Adresse liegt; in einem Unterordner nur mit `'hsts' => true` in
  `config.php`, weil es sonst auch für die übrigen Seiten unter derselben Adresse gälte. Läuft die App über `http://`, warnen der Installer und [Einstellungen → Zustand und Updates](app:einstellungen/zustand).
- **WordPress**: Die App-Seite lässt sich nicht mehr in fremde Seiten einbetten (Schutz vor Clickjacking).
- **Keine E-Mail-Adressen im Fehlerprotokoll des Servers**, wenn eine Mail nicht zugestellt werden kann.
- **Cronjob**: Der Schlüssel lässt sich jetzt auch im Header `X-Kompanie-Cron-Key` senden, dann steht er nicht im
  Zugriffsprotokoll des Webservers. Die bisherige Adresse mit `key=` funktioniert weiter.

## [0.4.0] – 2026-10-27

### Neu

- **Wer sieht was, je Knopf einstellbar**: Für jeden Knopf unter **Intern** (Mitglieder, Ämter, Ehemalige,
  Dokumente, Schwarzes Brett, Dienstgrade, Punktekatalog), für die Tabs unten (Termine, News, Galerie, Intern) und für
  jeden Teil des Mitgliederbereichs (eigene Punkte, Strafen, Dienstgrad und Anwesenheit, interner Kalender, interne
  Bilder, Geburtstage, Umfragen) gibt es jetzt ein eigenes Recht. Der Server prüft es: Wer es nicht hat, sieht den
  Knopf nicht und kommt auch über einen Link nicht an die Daten.
- **Übersicht „Wer sieht was“**: Unter [Einstellungen → Rollen](app:einstellungen/rollen) zeigt eine Tabelle alle Rechte für alle Rollen auf
  einen Blick. Die Haken lassen sich dort direkt setzen und gemeinsam speichern.
- **Gast und Mitglied einstellbar**: Bei den festen Rollen **Gast** und **Mitglied** lässt sich jetzt einstellen, was
  sie sehen. **Gast** gilt auch für alle, die die App ohne Anmeldung öffnen, und für die Shortcodes auf der Webseite.
- **E-Mails: Mailgruppen**: Unter [Verwaltung → E-Mails → Mailgruppen verwalten](app:verwaltung/mail/gruppen) lassen sich Empfängergruppen aus
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

- **Sicherung vor jedem Update, auch in Google Drive**: Die App sichert die Daten jetzt vor jedem Update auf dem
  Server, auch beim Zurückrollen (bisher nur bei Änderungen an der Datenbank und vor einer neuen Hauptversion). Sind
  die Sicherungen in Google Drive eingeschaltet, kommt dieselbe Sicherung zusätzlich in den Ordner dort; die letzten
  fünf bleiben. Klappt nur die Sicherung in Google Drive nicht, fragt die App nach, ob sie trotzdem aktualisieren soll.
  Ein automatisches Update wird in diesem Fall zurückgestellt: Unter [Einstellungen → Zustand und Updates](app:einstellungen/zustand) steht der
  Grund mit **Trotzdem aktualisieren** und **Abbrechen**, die Ampel wird gelb, und am nächsten Tag versucht es die App
  wieder.
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

- **Änderungen am Manifest kamen verzögert bei installierten Apps an**: Neues Logo, Startbildschirm und die freie
  Drehung des Bildschirms übernimmt Chrome in eine installierte App erst, wenn es beim Abgleich das neue Manifest
  bekommt. Bisher durfte das Manifest einen Tag zwischengespeichert werden; jetzt prüft der Browser es jedes Mal beim
  Server nach (meist nur eine kurze Antwort „unverändert“). Wann Chrome die installierte App danach aktualisiert,
  entscheidet weiterhin Chrome (höchstens einmal am Tag).
- **Mitgliederliste für die Anwesenheit**: Wer nur die Anwesenheit führt, sah in der Verwaltung den Knopf
  **Mitglieder**, bekam beim Öffnen aber eine Fehlermeldung. Jetzt öffnet sich die Liste mit Namen, Rollen, Fotos und
  Dienstgraden, ohne Punkte und Strafen.
- **Ämter einer Person: Mitinhaber anklickbar**: Hatte jemand ein Amt gemeinsam mit einer anderen Person inne, steht
  diese im Zeitstrahl unter „mit“. Hat sie ein Konto, führt ein Tipp auf sie jetzt zu ihrer Seite, wie im Zeitstrahl
  aller Ämter: auf der Seite einer Person, unter „Meins“ und in der Verwaltung. Aus der Verwaltung geöffnet, landet man
  beim Mitglied in der Verwaltung.
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
  unter [Einstellungen → E-Mail](app:einstellungen/email) mit dem Mailserver (SMTP) eintragen, prüfen und mit einer Testmail ausprobieren.
- **E-Mails aus der Verwaltung**: Der neue Bereich **E-Mails** (Recht „E-Mails versenden“, Standardrolle „E-Mails
  verwalten“) erscheint unter *Verwaltung*, sobald der Mailversand unter [Einstellungen → E-Mail](app:einstellungen/email) eingerichtet ist.
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
  [Einstellungen → Galerie](app:einstellungen/galerie) in der Liste „ließen sich nicht laden“ als nicht unterstützt.
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
- **Sicherungen in Google Drive**: Unter [Einstellungen → Datensicherung](app:einstellungen/datensicherung) legt die App auf Wunsch einmal pro Woche eine
  Sicherung in einem eigenen Ordner im verbundenen Google-Konto ab und behält die letzten acht. So liegt auch nach dem
  Verlust des Servers eine aktuelle Sicherung woanders. Dafür einmal unter *Google-Zugang* die Verbindung trennen und
  neu verbinden und dabei das Häkchen für eigene Dateien in Google Drive setzen.
- **Mitglieder aus Excel oder CSV übernehmen**: In der Ersteinrichtung und unter [Verwaltung → Mitglied anlegen →
  Aus Datei importieren](app:verwaltung/import) legt die App alle Personen einer Mitgliederliste auf einmal an, mit Status (Mitglied, eine
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
- **Ehemalige einzeln anlegen**: Unter [Verwaltung → Ehemalige](app:verwaltung/ehemalige) steht jetzt **+ Ehemalige Person anlegen**, aufgebaut wie
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
- **Nutzung der App auswerten**: Unter [Einstellungen → Nutzung](app:einstellungen/nutzung) sehen Admins, wie viele Konten in den letzten 24
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
- **Kalender: Aktualisierung einstellbar**: Unter [Einstellungen → Kalender](app:einstellungen/kalender) (und mit WordPress auch im Backend) legt
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
  [Einstellungen → Rollen](app:einstellungen/rollen) bzw. bei den Rechten einer Person geben. Wer es nicht hat, z. B. Gäste, behält die Knöpfe
  einzeln. Das Recht ändert nur die Navigation: Dokumente sieht weiter nur, wer den Mitgliederbereich hat. Alte Links
  auf Mitglieder und Dokumente funktionieren weiter.
- **Benachrichtigung zum Schwarzen Brett**: Sie zeigt jetzt den Titel der Nachricht statt des ganzen Texts und wird,
  wenn die Nachricht länger steht, bis zu 28 Tage nachgeliefert (vorher höchstens einen Tag).
- **Eigenständige Version**: Das Paket enthält jetzt PHPMailer (LGPL-2.1) für den Mailversand. Mit WordPress nutzt die
  App die Kopie von WordPress.
- **Statusmeldungen mit Nutzungszahlen**: Wenn ihr den Statusmeldungen zugestimmt habt, enthält das stündliche
  Lebenszeichen jetzt auch Zahlen ohne Namen: wie viele Konten es gibt, wie viele in den letzten 24 Stunden, 7 und 30
  Tagen aktiv waren und wie viele Personen welchen Bereich in den letzten 30 Tagen genutzt haben. Was genau gesendet
  wird, steht wie bisher unter [Einstellungen → Zustand und Updates](app:einstellungen/zustand); dort lassen sich die Statusmeldungen auch
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
- **Eigenes Konto auch in der Verwaltung ändern**: Auf der eigenen Seite unter [Verwaltung → Mitglieder → Konto und
  Rolle](app:verwaltung/mitglieder) gibt es jetzt auch den Bereich „Konto“ mit **Angaben ändern**, etwa für die eigene Handynummer als Admin.
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
  [Einstellungen → Datensicherung → Sicherung herunterladen](app:einstellungen/datensicherung): Der Server schickt die Datei jetzt direkt Stück für
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
- **Doppelpunkt bei den Empfängern einer E-Mail**: In der Ansicht einer gesendeten E-Mail ([Verwaltung → E-Mails](app:verwaltung/mail))
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
  Unterschriften-, Teilnehmer- oder Abhaklisten, unter [Verwaltung → Listen](app:verwaltung/listen) mit dem neuen Recht dafür. Titel und
  Spalten wählt man selbst: Angaben der Mitglieder (Name, Dienstgrad, Titel, Punkte …, jeweils nur, soweit man sie in
  der App sehen darf) oder leere Spalten zum Ausfüllen, auch mit Kästchen. „Mitglieder aktualisieren“ legt Zeilen für
  alle Mitglieder an und entfernt ausgeschiedene; eigene Zeilen kommen von Hand dazu. Der Ausdruck ist gestaltet wie
  die Tabellen der Kompanie, mit schräg gestellten Überschriften schmaler Spalten.
- **Mitgliederliste für alle** (Bereich „Mitglieder“, anfangs aus): Liste aller Mitglieder und Anwärter mit Name,
  Foto, Dienstgrad und Titeln, mit Suche und Sortierung nach Vor- oder Nachname, ohne Punkte, Strafen oder Rollen.
  Darüber steht die Anzahl je Gruppe. Ein Tipp auf eine Person öffnet ihre Seite mit ihren Ämtern.
- **Ämter** (neuer Bereich): Gruppen, Ämter und Amtszeiten in Jahren mit einem oder mehreren Inhabern, als Konto oder
  freier Name. Die Chronik ist öffentlich und steht im Tab „Mitglieder“ als Ehrentafel. Pflege unter
  [Verwaltung → Ämter](app:verwaltung/aemter) mit dem neuen Recht dafür, mit CSV-Import für die Ersterfassung. Ein Amt kann einen Titel
  automatisch vergeben und wieder entziehen.
- **Ehemalige Mitglieder**: Wer ausscheidet, wird als ehemalig eingetragen statt gelöscht. Das Konto behält alle Daten,
  erscheint aber nicht mehr in Listen, Umfragen, Geburtstagen und Benachrichtigungen; in der eigenständigen Version ist
  die Anmeldung gesperrt. „Wieder aufnehmen“ stellt die Rollen wieder her. Ehemalige lassen sich aus einer Chronik
  (CSV, mit Vorschau) importieren und werden dabei mit gleichnamigen Einträgen in den Ämtern verknüpft. Die Liste steht
  unter [Verwaltung → Ehemalige](app:verwaltung/ehemalige).
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
- **Rollen ordnen**: Alle Rollen, auch Gast, Mitglied und Admin, lassen sich unter [Einstellungen → Rollen](app:einstellungen/rollen) per Ziehen
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
