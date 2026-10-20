---
titel: Google einrichten (Galerie und Kalender)
reihenfolge: 115
rechte: [manage_settings]
---
Die {bereich.gallery} liest Fotos aus Google Drive. Ein privater Google Kalender lässt sich über denselben Zugang lesen. Dafür braucht die App einen **Google-Zugang**: ein **Dienstkonto** aus einem Projekt in der Google Cloud Console. Das ist einmalige Arbeit, etwa 15 Minuten, und kostet nichts.

> Für den {bereich.calendar} allein brauchst du das nicht: Die **geheime Adresse im iCal-Format** geht ohne Google Cloud (siehe [Ersteinrichtung](seite:admin-einrichtung)).

## 1. Projekt anlegen
1. [console.cloud.google.com](https://console.cloud.google.com/) öffnen und mit dem Google-Konto der Kompanie anmelden. Beim ersten Mal die Nutzungsbedingungen bestätigen.
2. Oben links auf die **Projektauswahl** klicken → **Neues Projekt**. Name z. B. „Kompanie-App“, dann **Erstellen**.
3. Prüfen, dass oben das neue Projekt ausgewählt ist. Alle weiteren Schritte gelten für dieses Projekt.

## 2. Schnittstellen aktivieren
Im Projekt jeweils die Seite öffnen und **Aktivieren** klicken:
- für die {bereich.gallery}: [Google Drive API](https://console.cloud.google.com/apis/library/drive.googleapis.com)
- für einen privaten Kalender über diesen Zugang: [Google Calendar API](https://console.cloud.google.com/apis/library/calendar-json.googleapis.com)

## 3. Dienstkonto anlegen
1. [IAM und Verwaltung → Dienstkonten](https://console.cloud.google.com/iam-admin/serviceaccounts) öffnen → **Dienstkonto erstellen**.
2. Einen Namen eingeben, z. B. „kompanie-app“, und **Erstellen und fortfahren** klicken. Die beiden optionalen Schritte (Rollen, Zugriff für Nutzer) überspringen und **Fertig** klicken. Rollen im Projekt braucht das Dienstkonto nicht.
3. Das neue Dienstkonto anklicken. Seine **E-Mail-Adresse** (endet auf `…iam.gserviceaccount.com`) brauchst du gleich für die Freigaben.
4. Reiter **Schlüssel** → **Schlüssel hinzufügen** → **Neuen Schlüssel erstellen** → **JSON** → **Erstellen**. Der Browser lädt eine JSON-Datei herunter.

> Die JSON-Datei ist ein Zugangsschlüssel. Nicht weitergeben, nicht per E-Mail verschicken und nach dem Einfügen in die App vom Rechner löschen.

> **„Schlüssel erstellen ist deaktiviert“?** In Organisationen von Google Workspace sperrt eine Richtlinie das oft standardmäßig. Dann den Admin der Organisation bitten, die Organisationsrichtlinie `iam.disableServiceAccountKeyCreation` („Erstellen von Dienstkontoschlüsseln deaktivieren“) für dieses Projekt aufzuheben, oder das Projekt mit einem privaten Google-Konto anlegen.

## 4. Freigeben
Das Dienstkonto sieht nur, was du ihm freigibst:
- **{bereich.gallery}:** In Google Drive den Galerie-Ordner → **Freigeben** → die E-Mail-Adresse des Dienstkontos eintragen, Rolle **Betrachter**. Eine Benachrichtigung musst du nicht senden.
- **{bereich.calendar}** (nur über diesen Zugang): In Google Kalender beim Kalender **⋮ → Einstellungen und Freigabe** → **Für bestimmte Personen oder Gruppen freigeben** → die E-Mail-Adresse des Dienstkontos, Berechtigung **Alle Termindetails sehen**. In derselben Einstellung unter **Kalender integrieren** steht die **Kalender-ID**; beim Hauptkalender eines Kontos ist das dessen E-Mail-Adresse. Gehört der Kalender zu Google Workspace, kann der Admin der Organisation das Freigeben nach außen gesperrt haben.

## 5. In der App eintragen
Unter **Konto → Einstellungen**:
- **{bereich.gallery}:** den Inhalt der JSON-Datei bei **Schlüssel des Dienstkontos (JSON)** einfügen und den **Link zum Galerie-Ordner** eintragen. Danach erscheint die E-Mail-Adresse des Dienstkontos in den Einstellungen.
- **{bereich.calendar}:** die **Kalender-ID** eintragen, mit WordPress auf der Einstellungsseite der App im WP-Admin im Abschnitt „Kalender“, Feld **„Oder: Google-Kalender-IDs“** (mehrere durch Komma getrennt). Ohne WordPress hat die App dafür noch kein eigenes Feld; nutze dort die iCal-Adresse. Ist keine Kalender-ID eingetragen, nutzt die App die iCal-Adresse.

Mit WordPress gibt es dieselben Felder auch auf der Einstellungsseite der App im WP-Admin („Google-Zugang (JSON-Schlüssel)“, „Galerie-Ordner“).

## Prüfen
- **{bereich.gallery}:** **Jetzt abgleichen** (in den Einstellungen der App, mit WordPress auch im WP-Admin). Danach gleicht die App den Ordner stündlich im Hintergrund ab; neue Bilder erscheinen spätestens nach dem nächsten Abgleich.
- **{bereich.calendar}:** **Kalender testen** in den Einstellungen der App zeigt die ersten Termine. Lehnt Google ab, nennt die Meldung die wahrscheinliche Ursache: Kalender nicht für das Dienstkonto freigegeben oder Google Calendar API nicht aktiviert.
- Unter **Zustand der App** sind {bereich.gallery} und {bereich.calendar} grün.

## Gut zu wissen
- Das Dienstkonto liest nur. Es sieht ausschließlich die Ordner und Kalender, die du ihm freigibst.
- Freigabe zurücknehmen oder den Schlüssel in der Cloud Console löschen beendet den Zugriff sofort.
- Wurde eine **Verbindung zu Google** aus einer früheren App übernommen, steht in den Einstellungen „Verbunden mit Google“. Sie hat Vorrang vor dem Dienstkonto, bis du sie trennst. Den Kalender liest sie nur, wenn beim Verbinden auch der Lesezugriff auf Google Kalender erlaubt wurde.
