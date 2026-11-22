---
titel: Aufnahmen
reihenfolge: 55
rechte: []
einrecht: [view_accounts, create_users]
bereiche: [attendance]
---
Die Übersicht **Aufnahmen** zeigt alle Anwärter und wer von ihnen die Voraussetzung erfüllt, als {rolle.member} aufgenommen zu werden, zum Beispiel sechs Teilnahmen an Versammlungen. Aufgenommen wird nie automatisch: Die Liste ist ein Vorschlag, aufnehmen tust du auf der Seite der Person.

## So öffnest du die Seite
Tippe unten auf **Verwaltung** und dann auf den Knopf **Aufnahmen**. Mit **‹ Verwaltung** kommst du zurück. Die kleine Zahl im roten Kreis an der Ecke des Knopfs zeigt, wie viele Anwärter die Voraussetzung gerade erfüllen. Daneben zeigt eine Zahl in einem Kreis in der Farbe eurer Kompanie, wie viele kurz vor der Aufnahme stehen (siehe **Bald erfüllt**). Ist es niemand, fehlt der jeweilige Kreis.

Den Knopf gibt es, wenn {bereich.attendance} an ist. Damit dort jemand erscheint, richtet ein {rolle.admin} unter *Einstellungen → Rollen* eine Anwärter-Rolle ein: an der Rolle den Haken **Anwärter-Rolle**. Die Rolle kann die Rolle der Person sein oder eine Zusatzrolle. Solange es keine gibt, steht auf der Seite, wie das geht.

## Was du hier siehst
- **Voraussetzung erfüllt**: wer alles erfüllt, mit dem Tag, seit dem. Wer am längsten wartet, steht oben.
- **Bald erfüllt**: wer kurz vor der Aufnahme steht. Das heißt: Bei jeder Bedingung mit Teilnahmen fehlt höchstens noch eine, und jede Mindestdauer ist in höchstens 30 Tagen erreicht. Bei **Eine reicht** genügt es, wenn eine Möglichkeit so nah ist. Fehlt **Anwärter seit**, gilt eine Mindestdauer nie als bald erfüllt.
- **Noch nicht erfüllt**: wer noch etwas braucht, z. B. „noch 2 Teilnahmen an „Versammlung““ oder „Mindestdauer ab …“. Bei einer Voraussetzung mit **Eine reicht** steht „oder“ zwischen den Möglichkeiten.
- **Ohne Voraussetzung**: Anwärter, deren Rolle keine Voraussetzung hat.
- Bei jedem Namen: die Anwärter-Rolle, **Anwärter seit** (wenn bekannt) und was noch fehlt. Auf der Seite der Person steht unter **Aufnahme** jede Bedingung einzeln mit ✓ (erfüllt) oder ○ (offen) und dem Stand, z. B. „4 von 6 Teilnahmen an „Versammlung““; Gruppen sind eingerückt.

## So gehst du vor
### Jemanden als {rolle.member} aufnehmen
1. Tippe auf den Namen. Die Seite der Person öffnet sich mit **Konto und Rolle**.
2. Unter **Aufnahme** tippst du auf **Als {rolle.member} aufnehmen** und bestätigst die Rückfrage. Ist die Voraussetzung noch nicht erfüllt, sagt die Rückfrage das; aufnehmen geht trotzdem.

Ist die Anwärter-Rolle die Grundrolle der Person, wird sie {rolle.member}. Ist sie eine Zusatzrolle, wird nur diese Zusatzrolle entfernt. Hat die Person noch keinen **Eintritt**, trägt die App den Tag der Aufnahme ein.

### „Anwärter seit“ nachtragen oder ändern
1. Öffne die Seite der Person und klappe **Konto und Rolle** auf.
2. Trage unter **Aufnahme** das Datum bei **Anwärter seit** ein und tippe auf **Datum speichern**.

## Gut zu wissen
- Es zählen alle Anwesenheitslisten mit der passenden Terminart, in denen die Person **anwesend** ist, auch aus der Zeit vor **Anwärter seit**. **Entschuldigt** zählt nicht. Die Terminarten einer Liste kommen vom Termin im Kalender oder werden an der Liste gewählt. Hat eine Liste mehrere Arten, zählt sie für jede.
- **Anwärter seit** setzt die App selbst, wenn jemand in der App die Anwärter-Rolle bekommt (beim Anlegen, beim Ändern der Rolle oder bei der Freigabe einer Anfrage). Bei Personen, die schon vorher Anwärter waren, fehlt das Datum; die Mindestdauer gilt dann erst, wenn du es nachträgst.
- Nach dem Speichern einer Anwesenheitsliste zeigt die App oben in der Verwaltung, wer dadurch neu die Voraussetzung erfüllt.
- Die Voraussetzung legt ein {rolle.admin} je Anwärter-Rolle unter *Einstellungen → Rollen* fest. Bedingungen lassen sich mit **Alle müssen erfüllt sein** (UND) und **Eine reicht** (ODER) verknüpfen, auch in Gruppen. Bei **Eine reicht** gilt als „erfüllt seit“ der Tag, an dem die erste Möglichkeit erfüllt war, bei **Alle müssen erfüllt sein** der Tag der letzten.
- Anwärter sehen ihren eigenen Stand: unter *Meins* (mit Mitgliederbereich) oder unter *Konto*, mit derselben Liste, aber ohne Knöpfe. Den Stand anderer sehen sie nicht.
- **Nur sehen:** Mit dem Recht **Konten sehen** ohne **Mitglieder anlegen** siehst du die Übersicht und auf der Seite der Person den Stand, aber ohne **Datum speichern** und **Als {rolle.member} aufnehmen**.

## Siehe auch
- [Konto und Rolle](seite:rollen-vergeben)
