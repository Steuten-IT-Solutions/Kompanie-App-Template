---
titel: "Google einrichten ({bereich.gallery} und Kalender)"
reihenfolge: 115
rechte: [manage_settings]
---
Die {bereich.gallery} liest Fotos aus Google Drive. Ein privater Google Kalender lässt sich über denselben Zugang lesen. Dafür braucht die App einen **Google-Zugang**. Empfohlen ist **„Mit Google verbinden“**: Du legst einmal ein kleines Projekt in der Google Cloud Console an, trägst zwei Werte in der App ein und meldest dich bei Google an. Das dauert etwa 20 Minuten und kostet nichts. Die Alternative mit einem Dienstkonto steht unten.

> Für den {bereich.calendar} allein brauchst du das nicht: Die **geheime Adresse im iCal-Format** geht ohne Google Cloud (siehe [Ersteinrichtung](seite:admin-einrichtung)).

Den Google-Zugang findest du in der App unter **Verwaltung → Einstellungen → Google-Zugang** (Menü oben in der Verwaltung). {bereich.calendar} und {bereich.gallery} haben dort je eine eigene Seite (**Kalender** und **{bereich.gallery}**). Mit WordPress gibt es den Google-Zugang auch im WP-Admin unter *Einstellungen* auf der Seite der App.

## 1. Projekt anlegen
1. [console.cloud.google.com](https://console.cloud.google.com/) öffnen und mit dem Google-Konto der Kompanie anmelden. Beim ersten Mal die Nutzungsbedingungen bestätigen.
2. Oben links auf die **Projektauswahl** klicken → **Neues Projekt**. Name z. B. „Kompanie-App“, dann **Erstellen**.
3. Prüfen, dass oben das neue Projekt ausgewählt ist. Alle weiteren Schritte gelten für dieses Projekt.

## 2. Schnittstellen aktivieren
Im Projekt jeweils die Seite öffnen und **Aktivieren** klicken:
- für die {bereich.gallery}: [Google Drive API](https://console.cloud.google.com/apis/library/drive.googleapis.com)
- für einen privaten Kalender: [Google Calendar API](https://console.cloud.google.com/apis/library/calendar-json.googleapis.com)

## 3. Google Auth Platform einrichten
1. [Google Auth Platform](https://console.cloud.google.com/auth/overview) öffnen und **Jetzt starten** klicken.
2. **App-Informationen:** als **App-Name** z. B. den Namen der Kompanie und bei **E-Mail-Adresse für den Nutzersupport** deine Adresse wählen.
3. **Zielgruppe:** **Intern** nur, wenn das Projekt zu einer Google-Organisation gehört (Google Workspace) und das verbundene Konto dazugehört. Sonst, also mit einem normalen Google-Konto, **Extern**.
4. **Kontaktdaten:** eine E-Mail-Adresse eintragen, den Richtlinien zustimmen und **Erstellen** klicken.
5. Nur bei **Extern:** Links **Zielgruppe** öffnen und unter „Veröffentlichungsstatus“ **App veröffentlichen** klicken und bestätigen. Der Status ist danach **In Produktion**. Bleibt die App im Status „Testen“, läuft die Verbindung nach 7 Tagen ab und die {bereich.gallery} hört auf, sich abzugleichen.

> Unter **Datenzugriff** trägst du **nichts** ein. Dort eingetragene Berechtigungen verlangen eine Prüfung durch Google (mit Demo-Video). Die App fragt die beiden Lese-Berechtigungen beim Verbinden selbst an; das reicht.

## 4. OAuth-Client anlegen
1. In der App auf der Seite **Google-Zugang** die **Weiterleitungs-URI** kopieren (Knopf **Adresse kopieren**). Sie ist für jede Installation anders: ohne WordPress endet sie auf `google/callback`, mit WordPress auf `wp-admin/admin-post.php`. Mit WordPress steht dieselbe Adresse auch im WP-Admin im Feld „Weiterleitungs-URI“.
2. In der Google Auth Platform links **Clients** öffnen → **Client erstellen**.
3. **Anwendungstyp:** **Webanwendung**. Name z. B. „Kompanie-App“.
4. Unter **Autorisierte Weiterleitungs-URIs** → **URI hinzufügen** die kopierte Adresse genau so einfügen (ohne Leerzeichen, mit `https://`). Dann **Erstellen**.
5. Google zeigt **Client-ID** und **Clientschlüssel** (Client-Secret). Beide kopieren. Den Clientschlüssel zeigt Google nur jetzt einmal an; am besten gleich zusätzlich die JSON-Datei herunterladen und sicher aufbewahren. Geht er verloren, legst du beim Client einen neuen Schlüssel an.

> Client-ID und Clientschlüssel gehören zusammen wie Benutzername und Passwort. Nicht weitergeben und nicht per E-Mail verschicken.

## 5. Mit Google verbinden
1. In der App auf der Seite **Google-Zugang** die **Client-ID** und den **Clientschlüssel (Client-Secret)** eintragen und **Speichern und mit Google verbinden** klicken.
2. Bei Google mit dem Konto anmelden, das den Galerie-Ordner und den Kalender sehen kann.
3. Google warnt: **„Google hat diese App nicht überprüft“**. Das ist bei einer eigenen, nicht geprüften App normal. Auf **Erweitert** klicken und dann auf **Weiter zu …** (dahinter steht die Adresse eurer App).
4. Google fragt nach den Berechtigungen: Lesen in Google Drive und Lesen in Google Kalender. **Beide Häkchen setzen** und **Weiter** klicken.
5. Google schickt dich zurück in die App. Dort steht auf der Seite **Google-Zugang** „Verbunden mit Google als …“ und **„Es darf auch den Kalender lesen.“**

Steht stattdessen, dass es den Kalender noch nicht lesen darf, wurde das Häkchen für den Kalender nicht gesetzt: **Verbindung trennen** und noch einmal verbinden. Client-ID und Clientschlüssel bleiben dabei gespeichert; den Clientschlüssel musst du nicht neu eintragen.

Meldet die App einen Fehler, steht die Ursache dabei. Häufig: Die Weiterleitungs-URI bei Google weicht ab (genau die Adresse aus der App eintragen), der Clientschlüssel stimmt nicht, das Häkchen für Google Drive fehlte oder die Anmeldung wurde abgebrochen. Die Client-ID muss auf `.apps.googleusercontent.com` enden.

## 6. {bereich.gallery} und {bereich.calendar} eintragen
- **{bereich.gallery}:** Unter **Verwaltung → Einstellungen → Galerie** im Abschnitt **{bereich.gallery} aus Google Drive** bei **Link zum Galerie-Ordner** den Link des Ordners aus Google Drive eintragen (z. B. `https://drive.google.com/drive/folders/…`), **Speichern**, dann **Jetzt abgleichen**. Danach gleicht die App den Ordner stündlich im Hintergrund ab; neue Bilder erscheinen spätestens nach dem nächsten Abgleich. Mit WordPress heißen Feld und Knopf im WP-Admin „Galerie-Ordner“ und **Galerie speichern**.
- **{bereich.calendar}:** Unter **Verwaltung → Einstellungen → Kalender** im Abschnitt **Kalender für Mitglieder** bei **Google-Kalender-IDs** die Kalender-ID eintragen (Google Kalender → beim Kalender **⋮ → Einstellungen und Freigabe** → **Kalender integrieren** → „Kalender-ID“; beim Hauptkalender eines Kontos ist das dessen E-Mail-Adresse; mehrere durch Komma trennen), **Speichern**, dann **Kalender testen**. Mit WordPress gibt es dafür im WP-Admin auch das Feld **„Oder: Google-Kalender-IDs“** und **Speichern und testen**. Das verbundene Google-Konto muss den Kalender sehen können.

Mehr zu beiden Seiten (Reihenfolge der Quellen, iCal-Adresse, Abgleich): [Ersteinrichtung und Bereiche](seite:admin-einrichtung).

## Prüfen
- **Jetzt abgleichen** zeigt, wie viele Alben und Bilder gefunden wurden.
- Ließen sich Bilder nicht laden, steht darunter **„… Bilder ließen sich nicht laden – anzeigen“**. Aufgeklappt zeigt die Liste je Bild Dateiname, Album, Fehlermeldung und einen Link **In Google Drive öffnen**. Kann der Server ein Format nicht lesen (z. B. RAW, TIFF, HEIC) oder ist ein Bild für seinen Speicher zu groß, nimmt die App das in RAW-Dateien eingebettete JPEG der Kamera oder das Vorschaubild, das Google selbst erzeugt (Großansicht dann höchstens so groß wie dieses). Übrig bleiben vor allem beschädigte Dateien und Formate, für die auch Google keine Vorschau hat. Abhilfe: das Bild als JPG in denselben Ordner legen und das alte entfernen. **Alle erneut versuchen** bereitet alle noch einmal auf, z. B. nachdem der Hoster mehr Speicher gibt.
- **Kalender testen** zeigt die ersten Termine. Lehnt Google ab, nennt die Meldung die wahrscheinliche Ursache, z. B. Google Calendar API nicht aktiviert oder Kalender für das Konto nicht sichtbar.
- Unter **Verwaltung → Einstellungen → Zustand und Updates** sind {bereich.gallery} und {bereich.calendar} grün (siehe [Zustand und Statusmeldungen](seite:admin-zustand)).

## Alternative: Dienstkonto mit JSON-Schlüssel
Statt „Mit Google verbinden“ geht auch ein **Dienstkonto**. Es braucht keine Anmeldung bei Google, aber einen Schlüssel, den manche Google-Organisationen sperren. Schritte 1 und 2 wie oben, dann:

### Dienstkonto anlegen
1. [IAM und Verwaltung → Dienstkonten](https://console.cloud.google.com/iam-admin/serviceaccounts) öffnen → **Dienstkonto erstellen**.
2. Einen Namen eingeben, z. B. „kompanie-app“, und **Erstellen und fortfahren** klicken. Die beiden optionalen Schritte (Rollen, Zugriff für Nutzer) überspringen und **Fertig** klicken. Rollen im Projekt braucht das Dienstkonto nicht.
3. Das neue Dienstkonto anklicken. Seine **E-Mail-Adresse** (endet auf `…iam.gserviceaccount.com`) brauchst du gleich für die Freigaben.
4. Reiter **Schlüssel** → **Schlüssel hinzufügen** → **Neuen Schlüssel erstellen** → **JSON** → **Erstellen**. Der Browser lädt eine JSON-Datei herunter.

> Die JSON-Datei ist ein Zugangsschlüssel. Nicht weitergeben, nicht per E-Mail verschicken und nach dem Einfügen in die App vom Rechner löschen.

> **„Schlüssel erstellen ist deaktiviert“?** In Organisationen von Google Workspace sperrt eine Richtlinie das oft standardmäßig. Dann den Admin der Organisation bitten, die Organisationsrichtlinie `iam.disableServiceAccountKeyCreation` („Erstellen von Dienstkontoschlüsseln deaktivieren“) für dieses Projekt aufzuheben, oder „Mit Google verbinden“ nutzen.

### Freigeben
Das Dienstkonto sieht nur, was du ihm freigibst:
- **{bereich.gallery}:** In Google Drive den Galerie-Ordner → **Freigeben** → die E-Mail-Adresse des Dienstkontos eintragen, Rolle **Betrachter**. Eine Benachrichtigung musst du nicht senden.
- **{bereich.calendar}:** In Google Kalender beim Kalender **⋮ → Einstellungen und Freigabe** → **Für bestimmte Personen oder Gruppen freigeben** → die E-Mail-Adresse des Dienstkontos, Berechtigung **Alle Termindetails sehen**. Gehört der Kalender zu Google Workspace, kann der Admin der Organisation das Freigeben nach außen gesperrt haben.

### In der App eintragen
Auf der Seite **Google-Zugang** **Alternative: Dienstkonto mit JSON-Schlüssel** aufklappen, den Inhalt der JSON-Datei bei **Schlüssel des Dienstkontos (JSON)** einfügen und **Schlüssel speichern** klicken. Danach steht dort die E-Mail-Adresse des Dienstkontos. {bereich.gallery} und {bereich.calendar} trägst du wie in Schritt 6 ein.

## Gut zu wissen
- Der Zugang liest nur. Mit „Mit Google verbinden“ darf die App alles lesen, was das verbundene Konto in Drive und Kalender sehen kann, liest aber nur den eingetragenen Galerie-Ordner und die eingetragenen Kalender. Ein Dienstkonto sieht ausschließlich, was du ihm freigibst.
- Besteht eine Verbindung mit Google, hat sie Vorrang vor dem Dienstkonto.
- **Verbindung trennen** widerruft die Verbindung auch bei Google. Ebenso beendet es den Zugriff, wenn du in deinem Google-Konto unter „Sicherheit → Drittanbieter-Apps und -Dienste“ den Zugriff der App entfernst oder den Schlüssel des Dienstkontos in der Cloud Console löschst.
- Wurde eine Verbindung zu Google aus einer früheren App übernommen, steht auf der Seite **Google-Zugang** „Verbunden mit Google“. Sie funktioniert weiter. Darf sie den Kalender nicht lesen, trenne sie und verbinde neu; die Client-ID ist schon eingetragen. Mit WordPress bleibt die Weiterleitungs-URI dieselbe wie in der früheren App.
