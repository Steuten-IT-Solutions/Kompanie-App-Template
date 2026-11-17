---
titel: Kalender einrichten
reihenfolge: 124
rechte: [manage_settings]
---
Hier legst du fest, woher der interne {bereich.calendar} für Mitglieder seine Termine bekommt: direkt aus der App, aus eurem Google Kalender oder über eine iCal-Adresse. Die App zeigt die Termine daraus angemeldeten Mitgliedern. Der interne Kalender ist von den öffentlichen Terminen getrennt.

![Die Seite Kalender in den Einstellungen](bild:admin-kalender)

## So öffnest du die Seite
Tippe unten auf **Verwaltung**, dann auf **Einstellungen** und unter **Termine und Kalender** auf **Kalender**. Darunter steht, wenn der Bereich an ist, der Abschnitt **{bereich.birthdays}**. Im [Einrichtungsassistenten](seite:admin-einrichtung) steht derselbe Abschnitt im Schritt für den Kalender.

## Was du hier siehst
- **Kalender für Mitglieder**: der Abschnitt für den internen Kalender.
- Eine Zeile, wie der Kalender gerade eingerichtet ist, z. B. **✓ Die Termine werden in der App gepflegt.**, **✓ Eingerichtet über die iCal-Adresse.** oder **Noch kein Kalender eingerichtet.**
- **Quelle**: woher die Termine kommen, **In der App pflegen**, **Google Kalender (Kalender-IDs)** oder **iCal-Adresse**. Es gilt immer genau eine Quelle.
- **Google-Kalender-IDs**: nur bei der Quelle Google Kalender.
- **iCal-Adresse** (oder, wenn schon eine gespeichert ist, **Neue iCal-Adresse (ersetzt die bisherige)**): nur bei der Quelle iCal-Adresse.
- **Aktualisieren alle … Minuten**: nur bei Google Kalender und iCal-Adresse; wie lange die App die geladenen Termine zeigt, bevor sie neu nachfragt (Standard 15). Für die Benachrichtigungen über neue und geänderte Termine fragt die App unabhängig davon alle 15 Minuten nach.
- **Kalender testen** und **Entfernen**: erscheinen bei Google Kalender und iCal-Adresse, sobald der Kalender eingerichtet ist.
- **Speichern**: übernimmt die Eingaben.
- **Zu- und Absagen**: ein eigener Abschnitt darunter mit eigenem **Speichern**. **Zu- und Absagen bei Terminen** (anfangs aus) schaltet die Knöpfe **Zusagen** und **Absagen** bei den Terminen ein. Erst dann erscheinen die Einstellungen der Erinnerung: **An Zu- und Absage erinnern** (anfangs an) schickt allen, die bei einem Termin noch nicht geantwortet haben, eine Benachrichtigung. **Erinnerung … Tage vor dem Termin** legt fest, wie lange vorher (1 bis 30, anfangs 3).
- **Terminarten**: ein eigener Abschnitt mit eigenem **Speichern**, z. B. **Versammlung** oder **Arbeitsdienst** (anfangs leer). Mit **+ Terminart** kommt eine Zeile dazu, **×** entfernt eine. Unter jeder Art steht der Haken **Als Schild am Termin zeigen** (anfangs an).

## So gehst du vor
### Termine direkt in der App pflegen (ohne Google)
1. Wähle bei **Quelle** **In der App pflegen**.
2. Tippe auf **Speichern**. Die App legt dabei die Rolle **{bereich.calendar} verwalten** an, falls es sie noch nicht gibt.
3. Gib die Rolle oder das Recht **Kalender pflegen** allen, die interne Termine eintragen sollen (siehe [Rollen und Rechte](seite:admin-rollen-und-mitglieder)). Als {rolle.admin} darfst du es ohnehin.
4. Die Termine legst du dann unter **{bereich.events}** → **Internen Kalender verwalten** an.

### Kalender über eine iCal-Adresse verbinden (ohne Google-Zugang)
1. Öffne in Google Kalender beim Kalender der Kompanie **⋮ → Einstellungen und Freigabe** und dort **Kalender integrieren**.
2. Kopiere die **Geheime Adresse im iCal-Format**.
3. Wähle in der App bei **Quelle** **iCal-Adresse**, füge die Adresse bei **iCal-Adresse** ein und tippe auf **Speichern**.

### Kalender über den Google-Zugang verbinden (auch für private Kalender)
1. Richte zuerst den [Google-Zugang](seite:admin-google) ein. Bei „Mit Google verbinden“ muss dabei das Häkchen für Google Kalender gesetzt sein; ein Dienstkonto braucht eine Freigabe des Kalenders („Alle Termindetails sehen“).
2. Kopiere in Google Kalender unter **⋮ → Einstellungen und Freigabe → Kalender integrieren** die **Kalender-ID**. Beim Hauptkalender eines Kontos ist das dessen E-Mail-Adresse.
3. Wähle in der App bei **Quelle** **Google Kalender (Kalender-IDs)** und trage die ID bei **Google-Kalender-IDs** ein. Mehrere trennst du durch Komma.
4. Tippe auf **Speichern**.

### Die Quelle wechseln
1. Wähle bei **Quelle** eine andere Quelle und ergänze, was sie braucht.
2. Tippe auf **Speichern**. Ab dann zeigt die App nur noch die Termine der neuen Quelle. Die Angaben und in der App gepflegten Termine der bisherigen Quelle bleiben gespeichert und erscheinen wieder, wenn du zurückwechselst.
3. Wechselst du weg von **In der App pflegen**, verschwindet die Rolle **{bereich.calendar} verwalten**, wenn sie niemand hat.

### Einstellen, wie schnell Änderungen erscheinen
1. Trage bei **Aktualisieren alle … Minuten** eine Zahl von 1 bis 1440 ein (1440 Minuten sind ein Tag).
2. Tippe auf **Speichern**. Der neue Wert gilt sofort, und die Termine werden neu geladen.

### Zu- und Absagen einschalten
1. Setze im Abschnitt **Zu- und Absagen** den Haken bei **Zu- und Absagen bei Terminen**.
2. Tippe in diesem Abschnitt auf **Speichern**. Ab jetzt stehen bei jedem Termin des internen Kalenders die Knöpfe **Zusagen** und **Absagen**, und beim Pflegen eines Termins gibt es den **Anmeldeschluss (optional)**.
3. Entfernst du den Haken wieder, verschwinden Knöpfe, Anmeldeschluss, Erinnerung und der Vorschlag **entschuldigt** in der Anwesenheit. Die bisherigen Rückmeldungen bleiben gespeichert und erscheinen wieder, wenn du einschaltest.

### Die Erinnerung an Zu- und Absagen einstellen
1. Schalte zuerst Zu- und Absagen ein (siehe oben).
2. Setze oder entferne im Abschnitt **Zu- und Absagen** den Haken bei **An Zu- und Absage erinnern**.
3. Trage bei **Erinnerung … Tage vor dem Termin** ein, wie viele Tage vor Beginn die Erinnerung kommen soll.
4. Tippe in diesem Abschnitt auf **Speichern**.

### Terminarten einrichten
1. Trage im Abschnitt **Terminarten** je Zeile eine Art ein, z. B. **Versammlung**. Mit **+ Terminart** kommt eine weitere Zeile dazu (höchstens 30, je höchstens 40 Zeichen).
2. Soll eine Art nicht als Schild neben dem Titel des Termins stehen, nimm darunter den Haken **Als Schild am Termin zeigen** heraus.
3. Tippe in diesem Abschnitt auf **Speichern**.
4. Ab jetzt wählen alle mit dem Recht **Kalender pflegen** die Art im aufgeklappten Termin unter **{bereich.events}**, und in der Anwesenheit steht sie an jeder Liste.

### Prüfen, ob der Kalender ankommt
1. Tippe auf **Kalender testen**.
2. Die App zeigt, wie viele kommende Termine sie gefunden hat, z. B. „5 Termine gefunden, z. B. „Schützenfest“.“ Lehnt Google ab, nennt die Meldung die wahrscheinliche Ursache.

![Das Ergebnis von Kalender testen](bild:admin-kalender-test)

### Den Kalender entfernen
1. Tippe auf **Entfernen** und bestätige die Rückfrage. Alle Angaben zum Kalender werden gelöscht.

## Gut zu wissen
- Änderungen im Google Kalender, z. B. ein gelöschter Termin, erscheinen in der App spätestens nach der eingestellten Zeit. Kürzere Zeiten zeigen sie schneller, bedeuten aber mehr Abrufe bei Google, und das Öffnen der Termine dauert dann öfter länger. Zusätzlich erneuert die App den Kalender stündlich im Hintergrund. Sofort neu geladen wird beim Speichern und bei **Kalender testen**.
- Die iCal-Adresse zeigt die App nach dem Speichern nicht wieder an. Eine neue Adresse ersetzt die bisherige.
- Hast du den Kalender eingerichtet, bevor es die Auswahl der Quelle gab, gilt bis zum nächsten Speichern das Eingetragene wie bisher: Kalender-IDs vor der iCal-Adresse.
- Darf die Verbindung mit Google den Kalender nicht lesen, steht das auf dieser Seite und unter [Zustand und Updates](seite:admin-zustand). Dann trennst du unter **Google-Zugang** die Verbindung und verbindest neu, mit dem Häkchen für den Kalender.
- Zu- und Absagen sind anfangs aus, auch nach dem Update auf die Version, die sie neu bringt. Erst mit dem Haken bei **Zu- und Absagen bei Terminen** gibt es sie.
- Ist der Haken gesetzt, können bei jedem Termin des internen Kalenders alle mit dem Recht **Bei Terminen des internen Kalenders zu- und absagen** zu- oder absagen, bei jeder Quelle. {rolle.member} haben das Recht anfangs, {rolle.guest} nicht. Wer wie geantwortet hat, sehen nur Personen mit dem Recht **Kalender pflegen**, auch bei Google Kalender und iCal-Adresse, unter **{bereich.events}** im aufgeklappten Termin.
- Die Erinnerung an Zu- und Absagen kommt einmal am Tag zur Uhrzeit der Erinnerung (unter **Benachrichtigungen**, üblich 18 Uhr), nur auf Geräten mit eingeschalteten Benachrichtigungen und nie per E-Mail. Hat ein Termin aus der App einen Anmeldeschluss, kommt sie am Tag vor dem Anmeldeschluss statt die eingestellten Tage vor Beginn. Wer abgesagt hat, bekommt auch die Erinnerung am Vortag zu diesem Termin nicht.
- Terminarten gelten bei jeder Quelle, auch bei Google Kalender und iCal-Adresse. Die App speichert die Art selbst und ändert nichts im Google Kalender. Steht der Name einer Art als eigenes Wort im Titel eines Termins, schlägt die App sie vor. Eine neue Anwesenheitsliste übernimmt die Art ihres Termins, sonst diesen Vorschlag.
- Ohne Schild bleibt die Art am Termin gespeichert und zählt weiter, z. B. für die Aufnahme von Anwärtern. Wer Termine pflegt, sieht sie im aufgeklappten Termin, und in der Anwesenheit steht sie wie bisher an der Liste.
- Entfernst du eine Terminart aus der Liste, behalten Termine und Anwesenheitslisten sie. Legst du sie später neu an, steht sie wieder als Schild am Termin.
- Der {bereich.calendar} erscheint für die anderen erst, wenn der Bereich an und ein Kalender eingerichtet ist (siehe [Bereiche](seite:admin-bereiche)).
- Mit WordPress gibt es die Felder für Google Kalender und die iCal-Adresse auch im WordPress-Backend auf der Seite der App (dort mit **Speichern und testen**), auch **Aktualisieren alle … Minuten**, dazu ein Feld für einen eigenen Google-API-Schlüssel für öffentliche Kalender. Ist er eingetragen, geht er bei Kalender-IDs vor. Die Quelle wählst du nur hier in der App.

## Siehe auch
- [Google-Zugang](seite:admin-google)
