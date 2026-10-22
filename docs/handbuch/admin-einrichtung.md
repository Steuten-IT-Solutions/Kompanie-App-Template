---
titel: Ersteinrichtung und Bereiche
reihenfolge: 110
rechte: [manage_settings]
---
Nach der Installation führt die App jeden {rolle.admin} durch die Einrichtung, bis einer sie abgeschlossen hat. Bis dahin steht oben auf jeder Seite **Willkommen!** mit dem Knopf **Einrichtung starten**. Alles lässt sich später unter **Verwaltung → Einstellungen** ändern (Menü oben in der Verwaltung; Überblick: [Einstellungen im Überblick](seite:admin-einstellungen)).

## Ablauf der Einrichtung
1. **Bereiche wählen** (siehe unten), dann **Weiter**. Diesen Schritt kannst du nicht überspringen.
2. Nur für die gewählten Bereiche: **Google-Zugang** und **Kalender für Mitglieder** (bei {bereich.calendar}), **{bereich.gallery} aus Google Drive** (bei {bereich.gallery}) und **Neuigkeiten** (bei {bereich.news}, nur ohne WordPress). Mit **Weiter** überspringst du einen Schritt, mit **Zurück** gehst du einen zurück.
3. **Mitglieder übernehmen:** Gibt es eine Mitgliederliste in Excel oder als CSV, legst du hier alle auf einmal an, auf Wunsch auch die Ehemaligen (siehe [Mitglieder anlegen → Aus Datei importieren](seite:mitglieder-anlegen)). Nach dem Übernehmen zeigt die App die Zugangsdaten zum Ausdrucken; **Weiter zum letzten Schritt** geht weiter. Ohne Liste einfach **Weiter**.
4. **Fast fertig:** Falls angeboten, kannst du hier die Statusmeldungen an die Betreuer der App erlauben (siehe [Zustand und Statusmeldungen](seite:admin-zustand)). Dann **Einrichtung abschließen**. Die App öffnet die Verwaltung; dort legst du weitere Mitglieder an (siehe [Rollen und Mitglieder](seite:admin-rollen-und-mitglieder)).

## Bereiche wählen
Eine neue Installation hat keine Bereiche. Wähle, was eure Kompanie nutzt: {bereich.events}, {bereich.calendar}, {bereich.news}, {bereich.gallery}, {bereich.points}, {bereich.penalties}, {bereich.ranks}, {bereich.titles}, {bereich.attendance}, {bereich.documents}, {bereich.board}, {bereich.polls}, {bereich.birthdays}, {bereich.inventory}, {bereich.directory}, {bereich.offices} und {bereich.lists}. Bei jedem Schalter steht ein Satz, wofür der Bereich da ist. {bereich.polls} lässt sich nur zusammen mit {bereich.board} einschalten. Ohne Bereich zeigt die App nur die Anleitung zum Installieren.

Später schaltest du Bereiche unter **Verwaltung → Einstellungen → Bereiche** ein und aus. Jeder Schalter gilt sofort; beim Ausschalten fragt die App nach. Ausgeschaltete Bereiche verschwinden für alle aus der App; ihre Daten bleiben erhalten und sind beim Wiedereinschalten wieder da. Mit WordPress geht das auch im WP-Admin unter *Einstellungen* auf der Seite der App (**Bereiche speichern**).

{bereich.calendar}, {bereich.gallery} und {bereich.news} erscheinen erst, wenn sie auch eingerichtet sind. Bis dahin steht in der Liste **Noch nicht eingerichtet** mit dem Knopf **Jetzt einrichten**.

Bereiche mit eigenen Aufgaben bringen beim Einschalten eine **Standardrolle** mit, z. B. „{bereich.points} verwalten“. Beim Ausschalten verschwindet sie wieder, solange niemand sie hat. Rollen siehe [Rollen und Mitglieder](seite:admin-rollen-und-mitglieder).

## Google-Zugang
{bereich.gallery} und ein privater Google Kalender lesen über einen gemeinsamen **Google-Zugang** (**Verwaltung → Einstellungen → Google-Zugang**). Empfohlen ist **Speichern und mit Google verbinden** mit einem eigenen OAuth-Client; alternativ ein Dienstkonto mit JSON-Schlüssel. Schritt für Schritt: [Google einrichten](seite:admin-google).

## {bereich.calendar}
Unter **Verwaltung → Einstellungen → Kalender**, Abschnitt **Kalender für Mitglieder**, gibt es zwei Wege:

- **Ohne Google-Zugang:** In Google Kalender beim Kalender der Kompanie unter **⋮ → Einstellungen und Freigabe → Kalender integrieren** die **geheime Adresse im iCal-Format** kopieren und bei **Geheime iCal-Adresse** einfügen. Die App zeigt sie danach nicht wieder an; eine neue Adresse ersetzt die bisherige.
- **Über den Google-Zugang** (auch für private Kalender): bei **Google-Kalender-IDs** die **Kalender-ID** eintragen (an derselben Stelle in Google Kalender unter „Kalender-ID“; mehrere durch Komma trennen). Das verbundene Google-Konto muss den Kalender sehen können und beim Verbinden das Häkchen für Google Kalender gesetzt haben; ein Dienstkonto braucht eine Freigabe des Kalenders für seine E-Mail-Adresse („Alle Termindetails sehen“). Im Google-Cloud-Projekt muss die **Google Calendar API** aktiviert sein (siehe [Google einrichten](seite:admin-google)).

Dann **Speichern**. Über dem Feld steht, worüber der Kalender gerade eingerichtet ist. Sind Kalender-IDs eingetragen und darf der Google-Zugang den Kalender lesen, gelten sie vor der iCal-Adresse. Darf die Verbindung mit Google den Kalender nicht lesen, steht das dort und auf der Seite **Zustand und Updates**: Verbindung trennen und neu verbinden; bis dahin gilt die iCal-Adresse, falls eingetragen.

**Kalender testen** zeigt, wie viele kommende Termine gefunden wurden; lehnt Google ab, nennt die Meldung die wahrscheinliche Ursache. **Entfernen** löscht alle Angaben zum Kalender.

Mit WordPress gibt es im WP-Admin zusätzlich die Felder „Kalender-Link (iCal)“, „Oder: Google-Kalender-IDs“ und einen eigenen **Google-API-Schlüssel** (nur für öffentliche Kalender). Ist ein API-Schlüssel eingetragen, geht er vor; dann kommen die Kalender-IDs über den Google-Zugang, zuletzt die iCal-Adresse.

## {bereich.gallery}
Die App zeigt Fotos aus einem Google-Drive-Ordner. Aufbau: Galerie-Ordner → je Jahr ein Ordner → je Album ein Ordner → Bilder. Bilder im Unterordner `intern` eines Albums sehen nur Mitglieder. Gelesen wird über den Google-Zugang (siehe oben); mit einem Dienstkonto gibst du den Ordner vorher in Google Drive für dessen E-Mail-Adresse frei (Betrachter).

Unter **Verwaltung → Einstellungen → Galerie**, Abschnitt **{bereich.gallery} aus Google Drive**, bei **Link zum Galerie-Ordner** den Link zum Ordner eintragen und **Speichern**. Danach erscheint **Jetzt abgleichen**: Die App liest die Alben und bereitet die Bilder auf und zeigt dabei den Fortschritt. Was dann noch fehlt, erledigt sie stündlich im Hintergrund; neue Bilder kommen ebenso stündlich dazu. Ging beim letzten Abgleich etwas schief, steht die Meldung über dem Knopf.

Mit WordPress heißen die Felder im WP-Admin „Galerie-Ordner“ und **Galerie speichern**.

## {bereich.news}
Mit WordPress kommen die Neuigkeiten aus den Beiträgen der Webseite; es gibt nichts einzustellen.

Ohne WordPress wählst du unter **Verwaltung → Einstellungen → Neuigkeiten** bei **Quelle** **WordPress-Webseite** (dann die **Adresse der Webseite**, z. B. `https://www.beispiel.de`) oder **RSS- oder Atom-Feed** (dann die **Adresse des Feeds**) und klickst **Speichern**. Danach prüft **Neuigkeiten testen**, wie viele Beiträge gefunden werden. **Keine Neuigkeiten** schaltet die Quelle ab.
