---
titel: "{bereich.inventory} pflegen"
reihenfolge: 66
rechte: [manage_inventory]
bereiche: [inventory]
---
Im {bereich.inventory} steht, was die Kompanie besitzt, wie viel davon da ist und wo es liegt. Es sehen und pflegen nur alle mit der Rolle „{bereich.inventory} verwalten“ oder einer Rolle mit diesem Recht (und die Admins); andere {rolle.member} sehen es nicht. Du findest es unter **Verwaltung** → **{bereich.inventory}** (Menü oben).

## Die Liste
Die Liste ist **nach Material sortiert**, nicht nach Ort: Jede Zeile zeigt ein Material mit der **Summe über alle Orte**, z. B. „12 Stück“. Gleiche Bezeichnungen gehören zusammen, auch in anderer Groß- und Kleinschreibung. Verschiedene Einheiten werden nicht zusammengezählt, sondern nebeneinander angezeigt, z. B. „12 Stück · 3 kg“.

Tippe auf ein Material, um zu sehen, wo wie viel davon liegt. Über das Suchfeld findest du Material, Orte, Kategorien und Notizen.

## Anlegen
Mit **+ Eintrag anlegen**:
- **Bezeichnung** und **Ort** sind Pflicht. Ein Eintrag hat genau einen Ort. Liegt dasselbe Material an zwei Orten, sind das zwei Einträge.
- **Menge**: 0 oder mehr, auch mit Komma (z. B. 2,5).
- Optional: **Einheit** (z. B. Stück, kg, m), **Kategorie** und **Notiz**.

Beim Ort schlägt die App die Orte vor, die es schon gibt. Schreibst du einen bekannten Ort nur anders groß oder klein, übernimmt die App die bekannte Schreibweise.

## Ändern
- **−** und **+** ändern die Menge um 1.
- **Bearbeiten** ändert alle Angaben. Ein **Grund** (optional) steht danach im Verlauf, z. B. „Inventur“ oder „Sturmschaden“.
- **Löschen** nimmt den Eintrag aus der Liste. Die App fragt nach einem Grund (optional). Der Verlauf bleibt erhalten.

## Umlagern
Mit **Umlagern** bringst du einen Teil oder alles an einen anderen Ort:
- Gibt es am Zielort schon dasselbe Material mit derselben Einheit, kommt die Menge dort dazu. Sonst entsteht am Zielort ein neuer Eintrag.
- Lagerst du alles um, verschwindet der Eintrag am alten Ort (oder es ändert sich nur sein Ort, wenn es am Ziel noch nichts davon gibt).
- Mehr als vorhanden geht nicht; dann ändert sich nichts.

## Verlauf
Jede Änderung steht im Verlauf: wann, wer, was sich geändert hat und der Grund. **Verlauf** bei einem Eintrag zeigt seine Änderungen, **Gesamter Verlauf** oben alle. Beim Umlagern bekommen der alte und der neue Eintrag je einen Verlaufseintrag. Auch gelöschte Einträge behalten ihren Verlauf.
