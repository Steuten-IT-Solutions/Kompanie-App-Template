---
titel: "{bereich.gallery} einrichten"
reihenfolge: 122
rechte: [manage_settings]
---
Hier legst du fest, woher die {bereich.gallery} ihre Bilder nimmt: aus einem Ordner in Google Drive (die App holt neue Bilder von selbst dazu) oder nur vom eigenen Server. In beiden Fällen kannst du Alben in der App anlegen und Bilder selbst hochladen.

![Die Seite {bereich.gallery} in den Einstellungen](bild:admin-galerie)

## So öffnest du die Seite
Tippe unten auf **Verwaltung**, dann auf **Einstellungen** und auf **{bereich.gallery}**. Im [Einrichtungsassistenten](seite:admin-einrichtung) steht derselbe Abschnitt im Schritt für die {bereich.gallery}.

## Was du hier siehst
- **Quelle**: **Google Drive (dazu Alben der App)** oder **Eigener Server (nur Alben der App)**.
- **Ablageort für hochgeladene Bilder**: vorerst immer **Eigener Server**. Darunter steht, wie viel Platz die hochgeladenen Bilder belegen.
- Mit der Quelle Google Drive außerdem:
- **Gelesen wird über …**: welcher Google-Zugang genutzt wird, mit einem Verweis auf **Google-Zugang**. Gibt es noch keinen, steht das hier.
- **Link zum Galerie-Ordner**: die Adresse des Ordners in Google Drive.
- **Letzter Abgleich: …**: erscheint, wenn beim letzten Abgleich etwas schiefging, mit der Meldung.
- **Jetzt abgleichen**: liest den Ordner sofort ein (erscheint, sobald ein Ordner eingetragen ist).
- **… Bilder ließen sich nicht laden – anzeigen**: erscheint nur, wenn Bilder nicht geladen werden konnten.
- **Knöpfe zum Herunterladen zeigen**: Ist der Haken gesetzt, kann jeder, der die {bereich.gallery} sieht, Bilder und Videos herunterladen: jedes einzeln in der Großansicht und jedes Album als ZIP. Ohne Haken (Vorgabe) gibt es diese Knöpfe nicht.
- **Einreichen von Bildern und Videos durch Mitglieder erlauben**: Ist der Haken gesetzt, können alle mit dem Recht *Bilder und Videos einreichen* Bilder und Videos vorschlagen. Ohne Haken gibt es in der {bereich.gallery} keinen Knopf zum Einreichen, und die App nimmt keine Einreichungen an. Neu eingerichtete Apps starten ohne Haken; wer die {bereich.gallery} schon vor dem Haken genutzt hat, hat ihn gesetzt.
- **Speichern**: übernimmt Quelle, Link und die Haken für die Knöpfe zum Herunterladen und das Einreichen.
- **Alben und eigene Bilder**: alle Alben mit der Zahl der Bilder aus Drive und der hochgeladenen, darunter **Neues Album in der App** mit **Titel**, **Jahr** und **Album anlegen**.

## So gehst du vor
### Den Ordner in Google Drive vorbereiten
Lege in Google Drive einen Galerie-Ordner an. Darin kommt je Jahr ein Ordner und darin je Album ein Ordner mit den Bildern und Videos. Bilder und Videos im Unterordner `intern` eines Albums sehen nur Mitglieder. Freigeben musst du den Ordner nicht: Auch Videos spielt die App über den Google-Zugang ab.

### Den Ordner verbinden
1. Richte zuerst den [Google-Zugang](seite:admin-google) ein. Mit einem Dienstkonto gibst du den Galerie-Ordner vorher für dessen E-Mail-Adresse frei (Rolle Betrachter).
2. Öffne den Galerie-Ordner in Google Drive und kopiere die Adresse aus der Adresszeile des Browsers (z. B. `https://drive.google.com/drive/folders/…`).
3. Füge sie bei **Link zum Galerie-Ordner** ein und tippe auf **Speichern**.
4. Tippe auf **Jetzt abgleichen**. Die App liest die Alben, bereitet die Bilder auf und zeigt dabei den Fortschritt, am Ende zum Beispiel „Fertig: 3 Alben, 12 Bilder und Videos.“

![Nach dem Abgleich: wie viele Alben und Bilder gefunden wurden](bild:admin-galerie-abgleich)

### Die Galerie ohne Google Drive nutzen
1. Wähle bei **Quelle** **Eigener Server (nur Alben der App)** und tippe auf **Speichern**. War vorher Google Drive eingetragen, verschwinden dessen Alben und Bilder aus der App; hochgeladene Bilder bleiben. Wechselst du zurück, holt der nächste Abgleich sie wieder.
2. Lege unter **Neues Album in der App** ein Album an: **Titel** und **Jahr** eintragen, **Album anlegen** tippen.

### Bilder und Videos hochladen
1. Tippe unter **Alben und eigene Bilder** auf ein Album. Es klappt auf. Das geht mit Alben aus der App und aus Google Drive.
2. Setze den Haken **Nur für Mitglieder (intern)**, wenn die Bilder und Videos nur Mitglieder sehen sollen.
3. Tippe auf **Bilder oder Videos hochladen** und wähle eine oder mehrere Dateien: Bilder als JPG, PNG oder WebP, Videos als MP4 oder WebM mit höchstens 1 GB je Video. Die App zeigt, wie viele schon oben sind, bei einem Video auch in Prozent, z. B. „Lädt … 40 %“. Lass die Seite dabei offen.
4. Bilder und Videos erscheinen kurz darauf in der {bereich.gallery}. Mit **Löschen** daneben nimmst du sie wieder heraus.

### Ein hochgeladenes Bild intern oder öffentlich stellen
Setze oder entferne neben dem Bild den Haken **intern**. Mit Haken sehen es nur {rolle.member}, ohne alle. Das Bild wird kurz neu aufbereitet und hat danach eine neue Adresse. Wer es vorher schon angesehen oder gespeichert hat, behält diese Kopie. Dasselbe geht in der Großansicht der {bereich.gallery}. Bilder aus Google Drive stellst du in Drive um: in den Unterordner `intern` des Albums verschieben oder heraus.

Bei einem Album der App änderst du darunter **Titel des Albums** und **Jahr des Albums** (**Album speichern**) oder löschst es mit **Album löschen** samt allen Bildern darin.

Verschieben, mehrere auf einmal löschen und Alben bearbeiten geht auch direkt in der {bereich.gallery}, auch für alle mit dem Recht „{bereich.gallery} verwalten“. Wie das geht, steht im Handbuch unter „Die Galerie verwalten“.

### Herunterladen erlauben
1. Setze den Haken **Knöpfe zum Herunterladen zeigen** und tippe auf **Speichern**.
2. In jedem Album steht dann rechts neben dem Titel ein runder Knopf mit Pfeil nach unten (ganzes Album als ZIP), in der Großansicht oben rechts neben **✕** derselbe Knopf für das einzelne Bild oder Video.
3. Zum Abschalten nimmst du den Haken wieder heraus und tippst auf **Speichern**.

### Einreichen erlauben
1. Setze den Haken **Einreichen von Bildern und Videos durch Mitglieder erlauben** und tippe auf **Speichern**.
2. Wer das Recht *Bilder und Videos einreichen* hat, sieht in der {bereich.gallery} oben den Knopf **Bilder und Videos einreichen** (Pfeil nach oben). Das Recht vergibst du unter [Rollen](seite:admin-rollen-und-mitglieder).
3. Zum Abschalten nimmst du den Haken wieder heraus und tippst auf **Speichern**. Schon eingereichte Bilder und Videos bleiben und lassen sich weiter prüfen.

### Bilder ansehen, die sich nicht laden ließen
1. Tippe auf **… Bilder ließen sich nicht laden – anzeigen**. Die Liste zeigt je Bild Dateiname, Album, Anzahl der Versuche, die Fehlermeldung und **In Google Drive öffnen**.
2. Lege das Bild als JPG in denselben Ordner in Google Drive und entferne das alte.
3. Tippe auf **Alle erneut versuchen**. Die App versucht alle noch einmal und gleicht danach ab.

## Gut zu wissen
- Beim Hochladen entfernt der Server Metadaten wie Aufnahmeort (GPS) und Kamera und speichert das Bild neu als JPG mit höchstens 8192 Pixeln an der längeren Kante. Wie groß ein Bild sein darf, hängt vom Webspace ab; ist es zu groß, sagt die App, woran es liegt.
- Hochgeladene Bilder liegen auf dem eigenen Server und sind in der [Sicherung](seite:admin-updates-und-sicherung) enthalten. Hochgeladene Videos liegen ebenfalls dort, sind aber wegen ihrer Größe nicht in der Sicherung; bewahre ihre Originale selbst auf. Die App setzt keine Grenze für den Speicherplatz; wie viel belegt ist, steht hier und unter [Zustand und Updates](seite:admin-zustand).
- Der Abgleich mit Google Drive ändert und löscht keine hochgeladenen Bilder. Verschwindet ein Album aus Drive, in das du Bilder hochgeladen hast, bleibt es mit diesen Bildern.
- Mitglieder mit dem Recht *Bilder und Videos einreichen* schlagen Bilder und Videos für jedes Album vor, sobald der Haken zum Einreichen gesetzt ist; wer *Einreichungen prüfen* hat, gibt sie frei. Beide Rechte vergibst du unter [Rollen](seite:admin-rollen-und-mitglieder); mit der {bereich.gallery} entsteht die Rolle *{bereich.gallery} verwalten* mit dem Recht zum Prüfen. Freigegebene Bilder stehen hier wie hochgeladene.
- Was beim Abgleich nicht fertig wird, bereitet die App stündlich im Hintergrund weiter auf. Neue Bilder in Google Drive kommen ebenso stündlich dazu.
- Videos zeigt die {bereich.gallery} als MP4 oder WebM. Andere Formate (z. B. MOV vom iPhone, AVI) stehen in der Liste als „Videoformat nicht unterstützt“. Abhilfe: das Video als MP4 speichern (viele Handys bieten das beim Teilen oder Exportieren an) und in denselben Ordner legen.
- Videos aus Google Drive speichert die App nicht auf dem Server, sie spielt sie beim Ansehen aus Google Drive ab. Nur ihr Vorschaubild kommt von Google und liegt neben den Bildern.
- In der App hochgeladene Videos liegen auf dem eigenen Server, die App wandelt sie nicht um. Damit auch große Videos unter der Grenze des Webspace je Anfrage bleiben, lädt die App sie in Stücken hoch. Als Vorschaubild nimmt der Browser beim Hochladen ein Standbild aus der ersten Sekunde. Kann er das Video nicht abspielen, erscheint es ohne Vorschaubild mit ▶. Bricht das Hochladen ab, löscht der Server das Angefangene spätestens nach einem Tag. Ist auf dem Server zu wenig Platz frei, sagt die App das vorher.
- Videos starten schneller, wenn sie „fürs Web optimiert“ gespeichert sind. Viele Handys legen das Inhaltsverzeichnis eines Videos ans Ende der Datei; dann muss der Browser vor dem Start erst dorthin springen. Abhilfe: das Video vor dem Hochladen mit einem Programm wie HandBrake (Häkchen „Web Optimized“) neu speichern. Kurze Videos und eine kleinere Auflösung (z. B. 1080p statt 4K) laden ebenfalls schneller.
- Kann der Server ein Format nicht lesen (z. B. RAW, TIFF, HEIC), nimmt die App das in RAW-Dateien eingebettete JPEG oder das Vorschaubild von Google. In der Liste bleiben vor allem beschädigte Dateien.
- Heruntergeladen wird jedes Bild als JPG in voller Auflösung (höchstens 8192 Pixel), ohne Metadaten wie den Aufnahmeort. Videos kommen unverändert aus Google Drive; die App reicht sie dabei Stück für Stück durch, wie beim Abspielen, und speichert sie nicht. Das ZIP entsteht im Browser; über 4 GB nutzt die App die Erweiterung ZIP64. Ältere Programme zum Entpacken kennen sie manchmal nicht; dann hilft z. B. 7-Zip. Der Webspace liefert dabei wie beim Abspielen nur kleine Stücke, seine Zeitgrenzen spielen also keine Rolle. Interne Bilder und Videos laden nur {rolle.member} herunter, so wie nur sie sie sehen. Wer eine Datei heruntergeladen hat, kann es weitergeben; schalte die Knöpfe nur ein, wenn das für eure Bilder in Ordnung ist.
- Die {bereich.gallery} erscheint für die anderen erst, wenn der Bereich an ist und ein Ordner eingetragen oder die Quelle **Eigener Server** gewählt ist (siehe [Bereiche](seite:admin-bereiche)).
- Wie der Abgleich zuletzt lief, zeigt auch die Ampel unter [Zustand und Updates](seite:admin-zustand).
- Mit WordPress gibt es dieselbe Einstellung auch im WordPress-Backend auf der Seite der App (Feld „Galerie-Ordner“, Knopf **Galerie speichern**).

## Siehe auch
- [Google-Zugang](seite:admin-google)
