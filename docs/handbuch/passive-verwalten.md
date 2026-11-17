---
titel: Passive verwalten
reihenfolge: 75
rechte: [manage_passive]
bereiche: [passive]
---
Hier führst du die interne Liste eurer passiven Mitglieder: Name, Vorname, Adresse, Telefon, E-Mail-Adresse, Geburtsdatum, Eintritt und eine Bemerkung, dazu je ein Mitglied als Betreuer. Eine bisher in Excel geführte Liste übernimmst du mit **Tabelle importieren**. Außerdem trägst du ein, wer den Beitrag für ein Jahr bezahlt hat. Passive sind keine Konten. Sie bekommen nie einen Zugang zur App, stehen in keiner Mitgliederliste und haben keine {bereich.points}, {bereich.penalties} oder {bereich.attendance}.

## So öffnest du die Seite
Tippe unten auf **Verwaltung** und dann auf den Knopf **Passive**.

Den Knopf gibt es, sobald unter **Einstellungen → Bereiche** der Bereich **Passive** an ist, und nur für alle mit dem Recht **Passive verwalten**. Beim Einschalten des Bereichs legt die App die Rolle **Passive verwalten** an, die du als Zusatzrolle vergeben kannst.

## Was du hier siehst
- **Zähler** oben: wie viele Passive eingetragen sind und wie viele davon den Beitrag für das gewählte Jahr bezahlt haben.
- **Jahresbeitrag (Vorschlag)** und **Zahlungsarten**: der Betrag, der beim Eintragen einer Zahlung schon im Feld steht, und die Zahlungsarten zur Auswahl. Mit **Ändern** richtest du beides ein.
- **Beitrag für**: das Jahr, dessen Zahlungen die Liste zeigt. Vorgegeben ist das laufende Jahr; wählbar sind auch das nächste Jahr und frühere Jahre.
- **Zeigen**: alle Passiven, nur die mit offenem Beitrag oder nur die, die bezahlt haben.
- **+ Passiven anlegen**: öffnet das Formular für einen neuen Eintrag.
- **Tabelle importieren**: übernimmt viele Passive auf einmal aus einer Excel-Datei oder CSV, auf Wunsch mit dem Beitrag eines Jahres.
- **Suche**: findet Passive nach Vor- und Nachnamen und nach dem Namen des Betreuers.
- **Liste**: je Passiver der Name (Nachname, Vorname), der Betreuer, der Beitrag für das gewählte Jahr (Betrag, Datum und Zahlungsart oder **offen**) Eintritt und Bemerkung (wenn eingetragen) und, wenn du persönliche Angaben sehen darfst, Adresse, Telefon, E-Mail-Adresse und Geburtsdatum. Antippen der Adresse öffnet den Ort in der Karten-App, Antippen der Nummer ruft an.
- Bei jedem Eintrag die Knöpfe **Bearbeiten** (Stift) und **Löschen** (Papierkorb).

## So gehst du vor
### Einen Passiven anlegen
1. Tippe auf **+ Passiven anlegen**.
2. Trage **Vorname** und **Nachname** ein. Beides ist Pflicht.
3. Wähle bei **Betreuer** ein Mitglied, oder lass **kein Betreuer** stehen.
4. Trage bei Bedarf den **Eintritt** ein, z. B. 2023 oder vor 2014, und eine **Bemerkung**. Beides ist optional.
5. Trage, wenn du persönliche Angaben pflegen darfst, Adresse, Telefon, E-Mail-Adresse und Geburtsdatum ein. Alles davon ist optional.
6. Tippe auf **Speichern**.

### Passive aus einer Tabelle importieren
1. Tippe auf **Tabelle importieren**.
2. Wähle bei **Datei wählen** die Excel-Datei (.xlsx) oder eine CSV-Datei. Die App liest das erste Tabellenblatt. Du kannst die Tabelle auch aus Excel kopieren und in das Feld darunter einfügen.
3. Prüfe bei **Beitrag für das Jahr** das Jahr, für das die Beträge in der Liste gelten, und bei **Datum für Zahlungen ohne Datum** das Datum, das eine Zahlung bekommt, wenn in der Liste keins steht.
4. Tippe auf **Vorschau**. Die App sucht die Kopfzeile (darüber darf z. B. ein Titel oder ein Stand stehen) und ordnet die Spalten an ihren Namen zu, z. B. Vorname, Nachname, Straße, PLZ, Ort, Telefon, E-Mail, Geburtsdatum, Eintritt, Bemerkung und Betreuer.
5. Prüfe unter **Spalten zuordnen**, ob jede Spalte richtig erkannt ist. Ändere, was nicht passt, z. B. wähle bei **Bezahlt am** die Spalte, in der das Datum der Zahlung steht, und tippe wieder auf **Vorschau**.
6. Lies die Vorschau: je Zeile, was die App übernimmt, Hinweise und Fehler. Zeilen mit **Fehler** werden nicht übernommen. Sind das z. B. Summen unter der Liste, setze den Haken **Zeilen mit Fehler überspringen**. Sonst korrigiere die Datei und wähle sie neu.
7. Tippe auf **Übernehmen**. Die App legt alle Passiven der Vorschau auf einmal an.

So liest die App die Spalten:
- **Straße** darf die Hausnummer enthalten, z. B. Musterstraße 12.
- **Betreuer** darf so aussehen: Anna Beispiel, Beispiel, Anna oder A. Beispiel. Passt das zu keinem oder zu mehreren Mitgliedern, legt die App den Passiven ohne Betreuer an und zeigt einen Hinweis. Den Betreuer trägst du dann mit **Bearbeiten** nach.
- **Beitrag**: Entweder hat die Tabelle die Spalten **Betrag** und **Zahlungsart**, oder sie hat je Zahlungsart eine Spalte, die so heißt wie eine eingerichtete Zahlungsart (z. B. Bar, SEPA, PayPal) und den Betrag enthält. Abkürzungen gehen auch, z. B. „Überw.“ für eine Zahlungsart Überweisung. Richte fehlende Zahlungsarten deshalb vorher unter **Zahlungsarten → Ändern** ein. Steht nur die Zahlungsart ohne Betrag da, gilt der Jahresbeitrag.
- **Bezahlt am** darf mit einem Datum beginnen: 12.03.2026, 12.03. (dann im Jahr des Beitrags) oder 2026-03-12. Text dahinter, z. B. „per PayPal“, übernimmt die App nicht. Steht kein Datum in der Zelle, gilt das Datum für Zahlungen ohne Datum.
- Zellen nur mit Strichen, z. B. „- - -“, gelten als leer.
- Eine ungültige Angabe, z. B. eine Adresse im Ausland ohne Spalte Land, übernimmt die App nicht. Der Passive wird trotzdem angelegt, und die Vorschau zeigt einen Hinweis.
- Spalten, die die App nicht kennt (z. B. eine IBAN), übernimmt sie nicht.

Wer schon als Passiver eingetragen ist (gleicher Vor- und Nachname), wird übersprungen. Du kannst dieselbe Tabelle also ein zweites Mal importieren, ohne dass jemand doppelt angelegt wird. Steht ein Name zweimal in der Tabelle, ist das ein Fehler.

### Angaben ändern
1. Tippe beim Eintrag auf **Bearbeiten**.
2. Ändere, was nötig ist, und tippe auf **Speichern**. Mit **Abbrechen** bleibt alles, wie es war.

### Eine Zahlung eintragen
1. Wähle bei **Beitrag für** das Jahr.
2. Tippe beim Passiven auf **Zahlung eintragen**.
3. Prüfe den **Betrag in €** (vorgegeben ist der Jahresbeitrag) und das Datum bei **Bezahlt am** (vorgegeben ist heute), und wähle die **Zahlungsart**, z. B. Bar, SEPA oder PayPal.
4. Tippe auf **Speichern**. Beim Passiven steht jetzt der Betrag mit Datum und Zahlungsart.

Je Passiver gibt es für jedes Jahr nur eine Zahlung. Um eine Zahlung zu ändern oder zu löschen, tippe beim Passiven auf **Ändern** und dann auf **Speichern** bzw. **Zahlung löschen**.

### Jahresbeitrag und Zahlungsarten einrichten
1. Tippe oben bei **Zahlungsarten** auf **Ändern**.
2. Trage bei **Jahresbeitrag in €** den Betrag ein, z. B. 24,00. Lässt du das Feld leer, gibt es keinen Vorschlag.
3. Passe die **Zahlungsarten** an. Vorgegeben sind Bar, SEPA und PayPal. Mit **+ Zahlungsart** kommt eine dazu, mit **×** entfernst du eine. Mindestens eine muss bleiben.
4. Tippe auf **Speichern**.

Frühere Zahlungen behalten ihre Zahlungsart, auch wenn du sie aus der Auswahl entfernst.

### Einen Passiven löschen
1. Tippe beim Eintrag auf **Löschen**.
2. Bestätige die Frage. Der Eintrag ist dann mit allen Angaben und Zahlungen gelöscht.

## Gut zu wissen
- Adresse, Telefon, E-Mail-Adresse und Geburtsdatum sind persönliche Angaben. Sie sieht nur, wer das Recht **Persönliche Angaben sehen** hat, und ändert nur, wer **Persönliche Angaben pflegen** hat. Ohne diese Rechte siehst und pflegst du nur die Namen, den Betreuer, den Eintritt und die Bemerkung, und beim Import übergeht die App die Spalten mit persönlichen Angaben.
- Der Betreuer sieht seine eigenen Passiven unter **Meins → Meine Passiven** mit allen Angaben, auch ohne die Rechte für persönliche Angaben. Ändern kann er dort nichts.
- Zahlungen trägt ein, wer **Passive verwalten** darf, auch ohne die Rechte für persönliche Angaben. Der Betreuer sieht unter **Meins → Meine Passiven**, ob der Beitrag für das laufende Jahr bezahlt ist.
- Betreuer kann nur ein Mitglied sein. Wird das Konto des Betreuers gelöscht, bleibt der Passive ohne Betreuer eingetragen.
- Der Geburtstag eines Passiven erscheint nicht bei den {bereich.birthdays} der Mitglieder.
- Anlegen (auch per Import), Ändern und Löschen stehen im Änderungsprotokoll. Die Werte persönlicher Angaben sieht dort nur, wer sie auch hier sehen darf.
