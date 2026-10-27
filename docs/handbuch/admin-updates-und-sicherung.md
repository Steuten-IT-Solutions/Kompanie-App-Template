---
titel: Datensicherung
reihenfolge: 132
rechte: [manage_settings]
---
Hier lädst du eine Sicherung aller Daten der App herunter, spielst eine Sicherung wieder ein oder lässt die App jede Woche eine Sicherung in Google Drive ablegen.

![Die Seite Datensicherung](bild:admin-updates-und-sicherung)

## So öffnest du die Seite
Tippe unten auf **Verwaltung**, dann auf **Einstellungen** und auf **Datensicherung**.

## Was du hier siehst
- **Datensicherung**: was die Sicherung enthält, mit den Knöpfen **Sicherung herunterladen** und **Sicherung wiederherstellen …**.
- **Sicherungen in Google Drive**: der Schalter **Wöchentlich in Google Drive sichern**, darunter wann zuletzt gesichert wurde, **Ordner öffnen** und **Jetzt in Google Drive sichern**. Fehlt die nötige Verbindung zu Google, steht hier stattdessen, was zu tun ist.

## So gehst du vor
### Eine Sicherung herunterladen
1. Tippe auf **Sicherung herunterladen**.
2. Der Browser speichert eine Datei `sicherung-<Datum>.json`. Bewahre sie an einem sicheren Ort auf, nicht nur auf dem Server.

### Sicherungen in Google Drive einschalten
1. Verbinde die App unter **Google-Zugang** über **Mit Google verbinden** und setze dabei das Häkchen für eigene Dateien in Google Drive (siehe [Google-Zugang](seite:admin-google)).
2. Schalte hier **Wöchentlich in Google Drive sichern** ein.
3. Willst du gleich eine Sicherung haben, tippe auf **Jetzt in Google Drive sichern**.

### Eine Sicherung wiederherstellen
1. Tippe auf **Sicherung wiederherstellen …**.
2. Wähle die Sicherungsdatei. Liegt sie in Google Drive, lädst du sie vorher von dort herunter.
3. Bestätige die Rückfrage. Die Sicherung ersetzt **alle** Daten der App; danach lädt die App neu.

## Gut zu wissen
- **Inhalt:** Die Sicherung enthält alle Daten der App, die Einstellungen, die Zugangsschlüssel und die Konten (Passwörter nur als Prüfwerte), dazu die Dateien der {bereich.documents}, die Abzeichen der {bereich.ranks} und die Anmeldungen der Geräte für Benachrichtigungen. Mit WordPress sind alle Benutzer der Webseite dabei. Behandle die Datei wie ein Passwort.
- **Nicht enthalten:** die Fotos der {bereich.gallery} (die App holt sie danach neu aus Google Drive; bei vielen Fotos dauert das einige Stunden), wartende Fehlermeldungen und der Zustand dieser Installation (z. B. Update-Stand, Fehlerprotokoll). Mit WordPress gehören Beiträge, Seiten, Design und andere Plugins zu WordPress selbst; dafür braucht es eine Sicherung von WordPress bzw. vom Hoster.
- **Automatische Sicherungen vor Updates:** Vor jedem Update sichert die App auf dem Server. Diese Sicherungen sind mit dem Server verloren; sind die Sicherungen in Google Drive eingeschaltet, kommt jede davon zusätzlich in den Ordner dort (die letzten fünf, Name `vor-update-<Datum>-<Version>.json`). Klappt das nicht, fragt die App vor dem Update nach (siehe [Zustand und Updates](seite:admin-zustand)). Ohne Google Drive die Sicherungen deshalb auch herunterladen. Die Ampel unter [Zustand und Updates](seite:admin-zustand) wird gelb, wenn 30 Tage lang keine Sicherung heruntergeladen oder in Google Drive abgelegt wurde.
- **Google Drive:** Die App legt im verbundenen Google-Konto einen eigenen Ordner an, lädt einmal pro Woche eine Sicherung hoch und behält die letzten acht; eine Sicherung vor einem Update zählt dabei als die der Woche. Sie sieht und ändert dort nur Dateien, die sie selbst angelegt hat. Scheitert das Hochladen (z. B. weil der Speicher voll ist), steht der Grund hier und in der Ampel; die App versucht es am nächsten Tag wieder. Mit einem Dienstkonto geht das nicht. Gib den Ordner nicht frei: Die Sicherungen enthalten Zugangsschlüssel und Passwort-Prüfwerte.
- **Wiederherstellen ohne WordPress:** geht auch auf einer neuen Installation, z. B. beim Umzug, aber nicht aus einer Sicherung der WordPress-Version. Danach melden sich alle neu an.
- **Wiederherstellen mit WordPress:** geht nur auf derselben Webseite (Adresse). Fehlende Konten legt die App dabei mit ihrer bisherigen Nummer wieder an; alle melden sich mit ihrem bisherigen Benutzernamen und Passwort an. Vorhandene Konten behalten Anmeldedaten und E-Mail und bekommen nur ihre Rolle in der App aus der Sicherung. Gehört eine Konto-Nummer aus der Sicherung inzwischen jemand anderem, bricht das Wiederherstellen ab und nennt das Konto; geändert wird dann nichts.
- Eine Sicherung aus einer neueren Version lässt sich erst einspielen, wenn die App aktualisiert ist. Rückgängig machen lässt sich ein Wiederherstellen nur mit einer anderen Sicherung.

### Nach dem Verlust der Webseite (WordPress)
1. Installiere WordPress unter derselben Adresse neu. Lege den Administrator mit **Benutzername und E-Mail deines bisherigen Kontos** an (oder mit einem Namen und einer E-Mail, die es bisher nicht gab).
2. Installiere das Plugin der Kompanie und öffne die App.
3. Spiele unter **Einstellungen → Datensicherung** die neueste Sicherung ein.

## Siehe auch
- [Zustand und Updates](seite:admin-zustand)
- [Google-Zugang](seite:admin-google)
