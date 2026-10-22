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
- **Meins → Übersicht**: Foto, Name, Rollen, Dienstgrad, Punkte und offene Strafen auf einen Blick, dazu die eigenen
  Titel als Kachel.
- **Fotos vergrößern**: Ein Tipp auf ein Mitgliedsfoto zeigt es groß und scharf (auch bei hoher Pixeldichte).
- **Rollen ordnen**: Eigene Rollen lassen sich unter *Einstellungen → Rollen* per Ziehen sortieren. Admins haben einen
  eigenen Punktefaktor.

### Geändert

- **Anwärter** ist keine feste Rolle mehr, sondern eine eigene Rolle mit zwei Einstellungen: „Beim Anlegen neuer Konten
  wählbar“ und „Als Mitglied aufnehmen“. Bestehende Installationen behalten ihre Anwärter mit denselben Rechten.
- Dienstgrad und Abzeichen stehen in der Mitgliederliste und den Mitgliedsdetails für alle, die die Liste sehen dürfen
  (bisher nur für die, die Dienstgrade verwalten).
- Mitgliederliste und Mitgliedsdetails neu angeordnet: Foto links, Dienstgrad rechts, Name und Titel mittig, größere
  Fotos und Abzeichen. Runde Fotos werden von oben zugeschnitten, damit der Kopf sichtbar bleibt.
- Zähler je Gruppe über der Mitgliederliste und in der Anwesenheit: Mitglieder zuerst, mit Mehrzahl („Mitglieder 45“).
  Anwärter werden immer angezeigt und mitgezählt.
- Die App zeigt gespeicherte Daten sofort und aktualisiert sie im Hintergrund, statt bei schwachem Netz bei
  „Lädt …“ stehen zu bleiben. Wechsel zwischen Unterseiten (Mitglied, Meins, Dokumente, Anwesenheit) ohne Neuladen.

### Behoben

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
