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

## {bereich.gallery}
Für den Bereich {bereich.gallery}: Die App zeigt Fotos aus einem Google-Drive-Ordner. Ordnerstruktur: Galerie-Ordner → Jahr → Album → Bilder; Bilder im Unterordner `intern` eines Albums sehen nur Mitglieder. Dafür braucht es ein Dienstkonto bei Google: Dessen Schlüssel (JSON) fügst du ein, gibst den Galerie-Ordner in Google Drive für das Dienstkonto frei (Betrachter) und trägst den Link zum Ordner ein.

## {bereich.news}
Mit WordPress kommen sie aus den Beiträgen der Webseite. Sonst gebt ihr die Adresse eines RSS- oder Atom-Feeds an (oder einer anderen WordPress-Webseite).
