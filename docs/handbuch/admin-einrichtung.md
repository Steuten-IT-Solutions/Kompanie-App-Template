---
titel: Ersteinrichtung und Bereiche
reihenfolge: 110
rechte: [manage_settings]
---
Beim ersten Öffnen führt die App durch die Einrichtung (so lange, bis ein {rolle.admin} sie einmal abgeschlossen hat). Alles lässt sich später unter **Konto → Einstellungen** ändern.

## Bereiche wählen
Eine neue Installation hat keine Bereiche. Wähle, was eure Kompanie nutzt: {bereich.events}, {bereich.calendar}, {bereich.news}, {bereich.gallery}, {bereich.points}, {bereich.penalties}. Ausgeschaltete Bereiche verschwinden aus der App; ihre Daten bleiben erhalten.

Bereiche mit eigenen Aufgaben bringen beim Einschalten eine **Standardrolle** mit, z. B. „{bereich.points} verwalten“ (siehe [Rollen und Mitglieder](seite:admin-rollen-und-mitglieder)).

## {bereich.calendar}
Für den Bereich {bereich.calendar}: in Google Kalender beim Kalender der Kompanie unter **Einstellungen → Kalender integrieren** die **geheime Adresse im iCal-Format** kopieren und einfügen. **Kalender testen** zeigt die ersten Termine.

Ein privater Google Kalender geht auch über den **Google-Zugang der {bereich.gallery}**: Dafür trägst du statt der iCal-Adresse die **Kalender-ID** ein (Google Kalender → Einstellungen des Kalenders → **Kalender integrieren** → „Kalender-ID“; bei WordPress auf der Einstellungsseite der App im WP-Admin unter „Kalender“). Gelesen wird der Kalender dann mit der Verbindung zu Google bzw. dem Dienstkonto der {bereich.gallery}:

- **Verbindung zu Google** (z. B. aus einer früheren App übernommen): Sie muss beim Verbinden auch den Lesezugriff auf Google Kalender erlaubt haben, und das verbundene Konto muss den Kalender sehen können. Fehlt die Erlaubnis, steht in den Einstellungen und auf der Zustandsseite, dass die Verbindung neu verbunden werden muss; bis dahin gilt die iCal-Adresse, falls eingetragen.
- **Dienstkonto**: Gib den Kalender in Google Kalender für die E-Mail-Adresse des Dienstkontos frei („Alle Termindetails sehen“).
- In beiden Fällen muss im Google-Cloud-Projekt die **Google Calendar API** aktiviert sein. Lehnt Google den Abruf ab, nennt **Kalender testen** die wahrscheinliche Ursache. Schritt für Schritt: [Google einrichten](seite:admin-google).

Welche Quelle gilt: Ist ein eigener Google-API-Schlüssel eingetragen (nur für öffentliche Kalender), geht er vor; dann kommt der Google-Zugang der {bereich.gallery}, zuletzt die iCal-Adresse. In den Einstellungen steht, worüber der Kalender gerade eingerichtet ist.

## {bereich.gallery}
Für den Bereich {bereich.gallery}: Die App zeigt Fotos aus einem Google-Drive-Ordner. Ordnerstruktur: Galerie-Ordner → Jahr → Album → Bilder; Bilder im Unterordner `intern` eines Albums sehen nur Mitglieder. Dafür braucht es ein Dienstkonto bei Google: Dessen Schlüssel (JSON) fügst du ein, gibst den Galerie-Ordner in Google Drive für das Dienstkonto frei (Betrachter) und trägst den Link zum Ordner ein. Wie du das Dienstkonto in der Google Cloud Console anlegst, steht unter [Google einrichten](seite:admin-google). Wurde eine bestehende Verbindung zu Google aus einer früheren App übernommen, steht dort „Verbunden mit Google“; sie wird genutzt, bis du sie trennst.

## {bereich.news}
Mit WordPress kommen sie aus den Beiträgen der Webseite. Sonst gebt ihr die Adresse eines RSS- oder Atom-Feeds an (oder einer anderen WordPress-Webseite).
