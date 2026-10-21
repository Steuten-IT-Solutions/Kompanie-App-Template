---
titel: Ersteinrichtung und Bereiche
reihenfolge: 110
rechte: [manage_settings]
---
Beim ersten Öffnen führt die App durch die Einrichtung (so lange, bis ein {rolle.admin} sie einmal abgeschlossen hat). Alles lässt sich später unter **Verwaltung → Einstellungen** ändern.

## Bereiche wählen
Eine neue Installation hat keine Bereiche. Wähle, was eure Kompanie nutzt: {bereich.events}, {bereich.calendar}, {bereich.news}, {bereich.gallery}, {bereich.points}, {bereich.penalties}. Ausgeschaltete Bereiche verschwinden aus der App; ihre Daten bleiben erhalten.

Bereiche mit eigenen Aufgaben bringen beim Einschalten eine **Standardrolle** mit, z. B. „{bereich.points} verwalten“ (siehe [Rollen und Mitglieder](seite:admin-rollen-und-mitglieder)).

## Google-Zugang
{bereich.gallery} und ein privater Google Kalender lesen über einen gemeinsamen **Google-Zugang** (Seite **Google-Zugang** in den Einstellungen). Empfohlen ist **Speichern und mit Google verbinden** mit einem eigenen OAuth-Client; alternativ ein Dienstkonto mit JSON-Schlüssel. Schritt für Schritt: [Google einrichten](seite:admin-google).

## {bereich.calendar}
Für den Bereich {bereich.calendar} gibt es zwei Wege:

- **Ohne Google-Zugang:** in Google Kalender beim Kalender der Kompanie unter **Einstellungen → Kalender integrieren** die **geheime Adresse im iCal-Format** kopieren und bei **Geheime iCal-Adresse** einfügen.
- **Über den Google-Zugang** (auch für private Kalender): bei **Google-Kalender-IDs** die **Kalender-ID** eintragen (Google Kalender → Einstellungen des Kalenders → **Kalender integrieren** → „Kalender-ID“; mehrere durch Komma trennen). Mit WordPress gibt es dafür im WP-Admin auch das Feld „Oder: Google-Kalender-IDs“. Bei einer Verbindung mit Google muss beim Verbinden auch das Häkchen für Google Kalender gesetzt worden sein, und das verbundene Konto muss den Kalender sehen können; ein Dienstkonto braucht eine Freigabe des Kalenders für seine E-Mail-Adresse („Alle Termindetails sehen“). Im Google-Cloud-Projekt muss die **Google Calendar API** aktiviert sein. Fehlt die Erlaubnis für den Kalender, steht in den Einstellungen und auf der Zustandsseite, dass neu verbunden werden muss; bis dahin gilt die iCal-Adresse, falls eingetragen.

**Kalender testen** zeigt die ersten Termine; lehnt Google ab, nennt die Meldung die wahrscheinliche Ursache.

Welche Quelle gilt: Ist ein eigener Google-API-Schlüssel eingetragen (nur für öffentliche Kalender, nur im WP-Admin), geht er vor; dann kommen die Kalender-IDs über den Google-Zugang, zuletzt die iCal-Adresse. In den Einstellungen steht, worüber der Kalender gerade eingerichtet ist.

## {bereich.gallery}
Für den Bereich {bereich.gallery}: Die App zeigt Fotos aus einem Google-Drive-Ordner. Ordnerstruktur: Galerie-Ordner → Jahr → Album → Bilder; Bilder im Unterordner `intern` eines Albums sehen nur Mitglieder. Gelesen wird über den Google-Zugang (siehe oben). Trag bei **Link zum Galerie-Ordner** den Link zum Ordner ein und klicke **Jetzt abgleichen**. Mit einem Dienstkonto gibst du den Ordner vorher in Google Drive für dessen E-Mail-Adresse frei (Betrachter).

## {bereich.news}
Mit WordPress kommen sie aus den Beiträgen der Webseite. Sonst gebt ihr die Adresse eines RSS- oder Atom-Feeds an (oder einer anderen WordPress-Webseite).
