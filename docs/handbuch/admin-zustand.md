---
titel: Zustand und Statusmeldungen
reihenfolge: 140
rechte: [manage_settings]
---
Unter **Verwaltung → Einstellungen → Zustand und Updates** zeigt eine Ampel, ob alles läuft: Updates, {bereich.gallery}, {bereich.calendar}, Datensicherung und Server (PHP, Datenbank, freier Speicherplatz), ohne WordPress zusätzlich die Hintergrundaufgaben und der Schutz der Daten.

- **Grün**: alles in Ordnung.
- **Gelb**: etwas sollte erledigt werden, z. B. eine neue Datensicherung.
- **Rot**: etwas funktioniert nicht; der Text sagt, was zu tun ist.
- **Grau**: nicht eingerichtet bzw. in dieser Installation nicht verfügbar.

## Statusmeldungen an uns
Wenn du zustimmst, schickt die App den Betreuern der App stündlich ein kurzes Lebenszeichen (u. a. Version, Ampel je Bereich, Fehlercodes der letzten 24 Stunden mit Ursache, z. B. „Kalender-Link: HTTP 404“ oder „Zeitüberschreitung“) und meldet schwere Fehler sofort, z. B. ein gescheitertes Update. Namen, Inhalte und Fehlertexte werden nie gesendet. So sehen die Betreuer Probleme früh. Bei der Einrichtung ist der Haken dafür schon gesetzt; du kannst ihn dort entfernen.

Unter **Verwaltung → Einstellungen → Zustand und Updates** siehst du, was genau gesendet wird, und wann die letzte Meldung ging. Die Zustimmung lässt sich dort jederzeit zurücknehmen; dann werden auch die gezählten Fehler gelöscht.

## Fehlerprotokoll
Darunter steht das **Fehlerprotokoll**: die Fehler der letzten 14 Tage (höchstens 50) mit dem vollständigen Fehlertext, auch ohne Zustimmung zu den Statusmeldungen. Es bleibt auf eurem Server und wird nicht gesendet. Gleiche Fehler direkt hintereinander stehen nur einmal da, mit Anzahl und Zeitraum. Adressen sind auf den Server gekürzt (so bleibt z. B. ein geheimer Kalender-Link geheim).

Sollen die Betreuer bei einem Problem helfen, tippe auf **Kopieren** und schick ihnen den Text. Sieh ihn vorher kurz durch: Fehlertexte können Namen oder Inhalte enthalten, z. B. wenn ein Datenbankfehler den Wert eines Eintrags nennt. **Leeren** löscht das Protokoll, z. B. nachdem ein Problem behoben ist.
