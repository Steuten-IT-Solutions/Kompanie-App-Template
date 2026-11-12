---
titel: Änderungen
reihenfolge: 69
rechte: [view_changes]
---
Hier siehst du, wer wann was in der App angelegt, geändert oder gelöscht hat, mit den alten und neuen Werten. So findest du z. B. heraus, wer einen Termin gelöscht oder die Rolle einer Person geändert hat.

## So öffnest du die Seite
Tippe unten auf **Verwaltung** und dann auf **Änderungen**.

## Was du hier siehst
- **Auswahl**: der Zeitraum mit **Von** und **Bis** (am Anfang die letzten 30 Tage, höchstens zwei Jahre), **Wer** (alle oder eine Person) und **Bereich**.
- **Als CSV-Datei herunterladen**: alle Einträge der Auswahl, eine Zeile je geändertem Feld, z. B. für Excel.
- Die Einträge nach Tagen, die neuesten oben. Jeder Eintrag nennt, was es ist (z. B. **Mitglied „Max Muster“ geändert**), die Uhrzeit, wer es war und den Bereich.
- Was auf einmal geändert wurde, steht in einer Zeile, z. B. **3 × Punkte angelegt**, wenn du Punkte für drei Mitglieder vergeben hast. Tippst du darauf, siehst du jede einzelne Änderung mit ihren Werten.
- Tippst du auf einen Eintrag, siehst du je Feld den alten und den neuen Wert, z. B. **Rolle: Mitglied → Kassierer**. Beim Anlegen steht nur der neue Wert da, beim Löschen nur der alte.
- **Einstellungen** (nur für {rolle.admin}s): der Schalter für das Protokoll und die Frist, nach der Einträge gelöscht werden.

## Was aufgezeichnet wird
- **Mitglieder und Konten**: anlegen (auch mehrere, per Import oder durch Freigabe einer Anfrage), Angaben und persönliche Angaben ändern, Rolle, Zusatzrollen und Rechte, Dienstgrad, Titel, Kompanien, Foto, Eintritt, Geburtsdatum, als ehemalig eintragen und wieder aufnehmen, Passwort neu erzeugen, löschen. Auch was jemand am eigenen Konto ändert.
- **Rollen** anlegen, ändern, löschen und ihre Reihenfolge.
- **Einstellungen** wie E-Mail, Bereiche, Navigation, Benachrichtigungen, Wartungsmodus, Updates und Datensicherung. Auch wer ein Update eingespielt, eine Sicherung erstellt oder wiederhergestellt hat. Ändert jemand ein Passwort oder einen Schlüssel, z. B. das SMTP-Passwort, steht dort nur **geändert**.
- **Was von selbst passiert**, mit **System (automatisch)** statt eines Namens: automatische Updates, das Ersetzen einer zurückgezogenen Version, automatische Sicherungen und das Löschen der persönlichen Angaben Ehemaliger nach der Aufbewahrungsfrist.
- **Auf dem Server**: was über die Kommandozeile (z. B. ein neues Admin-Konto oder Passwort) oder mit `install.php` geschieht.
- **Unter WordPress** auch die Einstellungsseite der App im Backend und die Benutzerverwaltung: Benutzer anlegen, bearbeiten, ihre Rolle ändern und löschen.
- **Alles in den Bereichen**, was angelegt, geändert oder gelöscht wird, z. B. Termine, News, Seiten, Punkte, Strafen, Dienstgrade, Titel, Ämter und Amtszeiten, Listen, Anwesenheit, Dokumente, Nachrichten am Brett, Umfragen, Inventar, E-Mails und Mailgruppen, Bilder, Alben und Einreichungen der Galerie. Auch die Reihenfolge, z. B. der Dienstgrade.
- **Fehlermeldungen**: wer eine Meldung geschlossen oder an die Betreuer weitergeleitet hat.

Nicht aufgezeichnet werden das Anmelden, Abstimmungen in Umfragen, das An- und Abmelden der Benachrichtigungen auf einem Gerät, Tests (z. B. Test-Mail), der Abgleich der Galerie mit Google Drive und das regelmäßige Aufräumen nach festen Fristen (z. B. abgelaufene Nachrichten am Brett, alte E-Mails und Anfragen).

## So gehst du vor
### Herausfinden, was jemand gelöscht hat
1. Wähle bei **Auswahl** den Zeitraum und bei **Wer** die Person.
2. Suche den Eintrag mit **gelöscht** und tippe darauf. Du siehst, was der Eintrag vor dem Löschen enthielt.

### Das Protokoll ausschalten oder die Frist ändern
1. Ganz unten bei **Einstellungen** nimmst du das Häkchen bei **Änderungen protokollieren** heraus und bestätigst die Rückfrage. Alle bisherigen Einträge werden sofort gelöscht.
2. Bei **Einträge löschen nach** trägst du die Frist in Tagen ein (30 bis 730) und tippst auf **Frist speichern**. Eine kürzere Frist löscht ältere Einträge sofort.

## Gut zu wissen
- Das Protokoll ist zu Beginn eingeschaltet, die Frist beträgt 365 Tage. Es enthält persönliche Angaben. Informiert eure Mitglieder darüber, z. B. in der Datenschutzerklärung.
- Werte persönlicher Angaben (E-Mail-Adresse, Handynummer, Adresse, Geburtsdatum) siehst du nur mit dem Recht **Persönliches sehen**. Sonst steht dort nur, dass sie geändert wurden.
- Passwörter, Zugangsdaten und Schlüssel kommen nie ins Protokoll, bei Fotos, Logos, Abzeichen und Dateien nur, dass sie geändert wurden.
- Bei Seiten, News und Nachrichten am Brett steht der Inhalt als reiner Text da. Lange Werte werden gekürzt.
- Wird ein Konto gelöscht, verlieren die Einträge über diese Person ihre Werte. Es bleibt z. B. **Mitglied „Max Muster“ gelöscht** mit dem Namen dessen, der gelöscht hat. Was die Person selbst geändert hat, bleibt mit ihrem Namen bis zum Ende der Frist.
- Die Einträge bleiben auf eurem Server und kommen nicht in die Datensicherung. In der Auskunft über eine Person (**Meine Daten**) stehen die Einträge über sie mit Werten und die Einträge, die sie gemacht hat, ohne Werte.
- Andere Plugins, die direkt in die Datenbank schreiben, gehen an der App vorbei; das steht nicht im Protokoll.
- Schaust du dir die App gerade als andere Rolle an, kannst du nichts ändern, also entsteht auch kein Eintrag.
- Sehen dürfen das Protokoll {rolle.admin}s und alle, deren Rolle das Recht **Änderungen ansehen** hat.

