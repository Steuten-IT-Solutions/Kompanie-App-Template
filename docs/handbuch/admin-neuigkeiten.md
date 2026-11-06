---
titel: "{bereich.news} einrichten"
reihenfolge: 126
rechte: [manage_settings]
---
Hier legst du fest, woher die {bereich.news} kommen: von der Webseite eurer Kompanie, aus einem Feed oder direkt aus der App, in der ihr die Beiträge selbst schreibt. Die App zeigt die Beiträge dann im Bereich {bereich.news}.

![Die Seite zu den {bereich.news} in den Einstellungen](bild:admin-neuigkeiten)

## So öffnest du die Seite
Tippe unten auf **Verwaltung**, dann auf **Einstellungen** und auf **Neuigkeiten**. Heißt der Bereich bei euch nicht „News“, steht dort sein Name. Im [Einrichtungsassistenten](seite:admin-einrichtung) steht derselbe Abschnitt im Schritt für die {bereich.news}.

## Was du hier siehst
- **Quelle**: Mit WordPress **Beiträge dieser WordPress-Seite** (Vorgabe) oder **In der App schreiben**. Ohne WordPress **Keine Neuigkeiten**, **Andere WordPress-Webseite**, **RSS- oder Atom-Feed** oder **In der App schreiben**.
- **Adresse der Webseite** bzw. **Adresse des Feeds**: erscheint, sobald du eine Webseite oder einen Feed gewählt hast.
- **Neuigkeiten testen**: erscheint, wenn eine Webseite oder ein Feed gespeichert ist. Hast du gerade etwas geändert, steht dort zuerst **Erst speichern, dann testen.**
- **Speichern**: übernimmt die Eingaben.

## So gehst du vor
### Die {bereich.news} in der App schreiben
1. Wähle bei **Quelle** **In der App schreiben** und tippe auf **Speichern**.
2. Unter **{bereich.news}** steht jetzt für alle mit dem Recht **{bereich.news} schreiben** der Knopf **Neuer Beitrag**. Als {rolle.admin} hast du das Recht immer.
3. Die App legt dazu die Rolle „{bereich.news} verwalten“ an. Gib sie unter **Mitglieder** allen, die Beiträge schreiben sollen (siehe [Rollen](seite:admin-rollen-und-mitglieder)).

### Die Webseite der Kompanie verbinden (ohne WordPress)
1. Wähle bei **Quelle** **Andere WordPress-Webseite**, wenn eure Webseite mit WordPress läuft. Sonst wähle **RSS- oder Atom-Feed**.
2. Trage die **Adresse der Webseite** ein (z. B. `https://www.beispiel.de`) bzw. die **Adresse des Feeds** (z. B. `https://www.beispiel.de/feed`).
3. Tippe auf **Speichern**.
4. Tippe auf **Neuigkeiten testen**. Die App zeigt, wie viele Beiträge sie gefunden hat.

![Das Ergebnis von Neuigkeiten testen](bild:admin-neuigkeiten-test)

### Die {bereich.news} abschalten
1. Ohne WordPress: Wähle bei **Quelle** **Keine Neuigkeiten** und tippe auf **Speichern**. Mit WordPress schaltest du den Bereich unter [Bereiche](seite:admin-bereiche) aus.

## Gut zu wissen
- Die {bereich.news} erscheinen für die anderen erst, wenn der Bereich an und eine Quelle eingerichtet ist (siehe [Bereiche](seite:admin-bereiche)).
- Es gilt immer genau eine Quelle; Beiträge aus der App und von der Webseite werden nicht gemischt. Wechselst du die Quelle, bleiben die Beiträge der App gespeichert, ebenso die Adresse der Webseite oder des Feeds. Wechselst du zurück, sind sie wieder da.
- Nach einem Wechsel meldet die App die Beiträge der neuen Quelle nicht als neu; Benachrichtigungen gibt es erst für die danach erscheinenden Beiträge.
- Mit WordPress und **Beiträge dieser WordPress-Seite** kommen die {bereich.news} aus den veröffentlichten Beiträgen eurer Seite; einzustellen gibt es dafür nichts.

> **Für Fortgeschrittene:** Ein RSS- oder Atom-Feed ist eine Adresse, unter der eine Webseite ihre neuesten Beiträge in maschinenlesbarer Form anbietet. Viele Webseiten haben einen, oft unter `/feed` oder `/rss`.

## Siehe auch
- [Einstellungen](seite:admin-einstellungen)
