# Konzept: Eigene Seiten und Bruderschaft

Stand: 05.11.2026. Noch nicht umgesetzt; je Schritt gibt es ein Issue (siehe [Phasen](#phasen)).

## Kurzfassung

Stand der Entscheidungen vom 05.11.2026: Die Bruderschaft bekommt **eine eigene Installation wie jede Kompanie**; ihre Kompanien sind darin **Einträge einer Liste**, keine Mandanten. Es gibt **keine eigene App**: Alles kommt als abschaltbare, neutrale Bereiche in den Kern. Die **Webseite bleibt vorerst außen vor**.

- **Eigene Seiten:** einfache Inhaltsseiten in der App (Text und Bilder), für alle oder nur für Mitglieder.
- **Kompanieliste:** neuer Bereich mit Name, Logo, Kurztext, Gründungsjahr und Link; Mitglieder lassen sich optional einer oder mehreren Kompanien zuordnen.
- **Königshaus und Chronik:** als Gruppe in *Ämter*, ergänzt um gemeinsame Amtszeiten einer Gruppe mit Gruppenbild.
- **Bilder einreichen:** eigene Rechte fürs Einreichen und Freigeben, Ablageort und Quelle der Galerie einrichtbar (zuerst eigener Server und Google Drive), Platz unbegrenzt, kein automatisches Löschen.
- **Sponsoren:** sortierbare Liste mit Logo und Link. Die Navigation wird vom Admin einrichtbar, mit Untermenüs.

Alle Fragen sind beantwortet.

## Ausgangslage im Code

Geprüft auf `develop` (Stand 7c4fdbc, 05.11.2026). Was davon für das Konzept zählt:

| Thema | Heute im Kern | Beleg |
| --- | --- | --- |
| Eine Installation | genau eine Kompanie: ein Repo, eine `kompanie.json` mit Name, Logo, Farben | `docs/ARCHITEKTUR.md` Abschnitt 2 und 10 |
| Bereiche | Admin schaltet sie ein; eine Liste in `Features::DEFINITIONS`, aus = Routen 403 `feature_disabled`, Daten bleiben | `packages/core/src/Domain/Features.php` |
| Rechte | Voraussetzungen nur in `Capability::requires()`: Verwaltungsrechte brauchen `member`, Sehrechte nichts | `packages/core/src/Auth/Capability.php` |
| Standardrollen | Bereich mit `role` legt beim Einschalten „‹Bereich› verwalten“ an | `Features::update`, `RoleService::featureChanged` |
| Ämter | Gruppen, Ämter, Amtszeiten (nur Jahre) mit 1–20 Personen (Konto oder freier Name), öffentlich, Chronik und Jahresübersicht, Import per CSV, verknüpfter Titel; Beispiel im Code ist schon „Kompaniekönig“ | `ARCHITEKTUR.md` „Ämter (Issue #112)“, Migration 31 |
| Galerie | nur lesend aus Google Drive (`drive.readonly`); der Abgleich löscht Bilder, die nicht in Drive sind; `albums.drive_id` und `photos.drive_file_id` sind Pflicht | `GalleryService`, `GalleryRepository::photosExcept`, Migration 1 |
| Formatierter Text, Bilder | `RichText` (Allowlist), `rich_images` als Base64 in der Datenbank, Editor TipTap | `ARCHITEKTUR.md` „Formatierte Inhalte“ |
| Webseite | Seiten aus Blöcken mit Entwurf und Veröffentlichen geplant, Migration 38 und API als `x-status: planned` | Issue #273, PR #271 (Entwurf), `docs/website.md` im PR |
| Webseite WordPress | Shortcodes `kompanie_termine`, `kompanie_galerie`, `kompanie_aemter`, `kompanie_mitglieder` | `adapters/wordpress/includes/Shortcodes.php` |
| Migrationen | letzte auf `develop` ist 37, PR #271 belegt 38; neue also ab 39, nur additiv | `Storage/Sql/Migrations.php` |

Wichtig für die Galerie: Bilder aus Drive werden in `Backup` nur als Zeilen gesichert, weil sie sich aus Drive neu erzeugen lassen. Hochgeladene Bilder lassen sich nicht neu erzeugen; sie müssen in die Sicherung (`Backup::FILE_DIRS`).

## Grundsatzfrage Bruderschaft: eine Instanz oder mehrere

**Entschieden am 05.11.2026:** Variante A. Die Kompanien sollen keine eigenen Mandanten sein; gefordert ist vorerst nur eine Liste.

**Entschieden am 05.11.2026:** Keine eigene App für Bruderschaften. Die Funktionen kommen als abschaltbare Bereiche in den Kern (standardmäßig aus).

Empfehlung: **Variante A** jetzt, **Variante C** als spätere Ergänzung. Variante B lohnt sich nur, wenn die Kompanien ihre internen Daten (Punkte, Strafen, Dokumente) wirklich in der App der Bruderschaft führen wollen.

|  | A: Eine Installation für die Bruderschaft, Kompanien als Einträge | B: Eine Installation, Kompanien als Mandanten | C: Verbund getrennter Installationen |
| --- | --- | --- | --- |
| Idee | Die Bruderschaft ist für die App eine „Kompanie“. Ihre Kompanien stehen in einer Liste. Wer eine eigene App will, behält bzw. bekommt eine eigene Installation. | Alle Kompanien in einer Installation; Punkte, Strafen, Rollen, Dokumente usw. je Kompanie getrennt. | Jede Kompanie und die Bruderschaft haben eine eigene Installation; sie lesen gegenseitig öffentliche Daten (Termine, News, Königshaus). |
| Änderung am Kern | nur neue Bereiche, additiv | fast jede Tabelle braucht eine Kompanie-Spalte, jedes Recht einen Geltungsbereich („Punkte verwalten nur für Kompanie X“), Admin je Kompanie | neue Quellen für öffentliche Daten anderer Installationen |
| Branding | wie heute, ein Logo, eine Farbe | nur eines möglich: Manifest, Icons und Farben kommen aus einer `kompanie.json` | jede Kompanie ihr eigenes |
| Sicherung, Updates, Statusmeldungen | wie heute | eine Sicherung für alle Kompanien; Zurückrollen trifft alle | wie heute, je Installation |
| Datenschutz | wie heute | eine Admin-Rolle sieht ggf. die Daten aller Kompanien; Trennung muss lückenlos sein | wie heute |
| Aufwand (Schätzung) | klein bis mittel | sehr groß, hohes Risiko für alle bestehenden Installationen | mittel |

Warum nicht B: Der Kern ist durchgängig auf „eine Installation = eine Kompanie“ gebaut (Abschnitt 2 und 10 der Architektur). B würde Rechte, Rollen, Abfragen, Sicherung und Oberfläche überall berühren. Das ist eine Vermutung aus dem Aufbau, keine gemessene Schätzung.

Was schon heute für C geht: Eine Kompanie kann News einer anderen WordPress-Seite oder eines RSS-Feeds zeigen (`RemoteNewsSource`), und der Kalender liest eine iCal-Adresse. Die App einer Kompanie kann so heute schon News und Termine der Bruderschaft zeigen, sofern die Bruderschaft sie veröffentlicht.

## Eigene Seiten

**Entschieden am 05.11.2026:** Die Webseite bleibt vorerst ganz außen vor (eigenes Vorhaben, Issue #273). Eigene Seiten gibt es nur in der App, in beiden Varianten (WordPress und eigenständig).

Empfehlung: einfache **Inhaltsseiten in der App** mit formatiertem Text und Bildern, ohne Fassungen und ohne Blöcke.

**Was darunter fällt:** Kompanieliste, Königshaus, Sponsoren und Bilder einreichen werden eigene Bereiche und sind damit keine eigenen Seiten. Satzung und Formulare gehören zu *Dokumente*, dringende Hinweise ans *Schwarze Brett*. Eigene Seiten bleiben für freie Texte, zum Beispiel:

- Geschichte der Kompanie oder Bruderschaft
- Ablauf des Schützenfests, Festprogramm
- Anfahrt, Treffpunkte, Kontakt
- Uniform und Kleiderordnung
- Infos für neue Mitglieder und Anwärter

**Datenmodell** (Tabelle `app_pages`, Migration 39 ff.):

| Feld | Bedeutung |
| --- | --- |
| `title` | Titel, auch auf der Kachel |
| `body` | formatierter Text (`RichText`), Bilder als `rich_images` mit eigenem Besitzer `page` |
| `audience` | `public` = alle, auch ohne Anmeldung; `members` = Mitglieder und Rollen mit internem Kalender (wie `BoardService::isRecipient`) |
| `sort` | Reihenfolge per Ziehen |
| `updated_by`, `updated_at` | wer zuletzt geändert hat; beim Speichern 409 `conflict`, wenn jemand dazwischen gespeichert hat (wie bei den Listen) |

**Rechte:** `manage_pages` (braucht `member`, Standardrolle „Seiten verwalten“). Lesen prüft der Server nach `audience`.

**Später zur Webseite:** Der Inhalt hat dasselbe Format wie der geplante Block `text` der Webseite. Eine Seite lässt sich dann übernehmen, ohne neu zu schreiben.

**Grenzen:** Kein eigenes HTML, CSS oder JavaScript (Bereinigung durch `RichText`).

## Kompanieliste

Empfehlung: neuer Bereich `companies` (Bezeichnung „Kompanien“, über `labels.sections` umbenennbar, z. B. „Züge“ oder „Gesellschaften“), öffentlich wie *Mitglieder* und *Ämter*.

**Je Kompanie** (Tabelle `companies`, Migration 39 ff.):

| Feld | Pflicht | Hinweis |
| --- | --- | --- |
| `name`, `short_name` | Name ja |  |
| `logo` | nein | Bild wie bei Abzeichen der Dienstgrade, SVG über `SvgSanitizer` |
| `founded_year` | nein | ab 1800, wie bei den Ämtern |
| `description` | nein | formatierter Text (`RichText`) |
| `website_url`, `app_url` | nein | nur `https:` |
| `office_group_id` | nein | Verknüpfung mit einer Gruppe in *Ämter*, z. B. „Vorstand Kompanie X“; zeigt Hauptmann usw. ohne doppelte Pflege |
| `sort`, `active` | ja | Reihenfolge per Ziehen wie bei Ämtern; aufgelöste Kompanien bleiben als „ehemalig“ sichtbar |

**Mitglieder einer Kompanie zuordnen** (entschieden: wird gebaut, die Zuordnung ist optional): Tabelle `member_companies (user_id, company_id, assigned_at)`, in Sicherungen, beim Löschen des Kontos mit entfernt (`UserData::purge`).

- Entschieden: Eine Person kann zu mehreren Kompanien gehören; eindeutig ist das Paar aus Person und Kompanie. In der Mitgliederliste stehen dann alle Kompanien der Person.
- Zuordnen in *Verwaltung → Mitglied* und gesammelt auf der Seite der Kompanie, mit `manage_companies`.
- Anzeige: Kompanie auf der Seite der Person und als Filter in der Mitgliederliste; auf der Karte der Kompanie die Zahl bzw. die Namen ihrer Mitglieder, nur so weit wie in der Mitgliederliste sichtbar (Bereich `directory`, Recht `view_directory`).
- Ohne Zuordnung bleibt alles wie heute.

**Rechte:** `view_companies` (Sehrecht, anfangs auch Gast), `manage_companies` (braucht `member`, Standardrolle „Kompanien verwalten“).

**Ausgabe:** App (Liste mit Logo, aufklappbare Karte) und API `GET /companies` (öffentlich, feste Zahl von Abfragen). Die Webseite bleibt vorerst außen vor.

**Später (Variante C):** Hat eine Kompanie eine eigene Installation, könnte die Liste deren öffentliches `/config` lesen (Logo, Farbe, Version) und die nächsten öffentlichen Termine zeigen.

## Königshaus und Chronik

**Entschieden am 05.11.2026:** Das Königshaus wird wie die anderen Ämter gepflegt. Es gibt keinen eigenen Bereich.

Damit braucht es **kaum neuen Code**. Der Admin schaltet *Ämter* ein und legt eine Gruppe „Königshaus“ mit diesen Ämtern an:

| Amt | Personen je Amtszeit |
| --- | --- |
| König | 1 |
| Königin | 1 |
| Minister | mehrere |
| Partner\*innen der Minister | mehrere |
| Ordonanzoffizier | 1 |
| Hofdamen | mehrere |

Das passt in die vorhandenen Grenzen: 1–20 Personen je Amtszeit, jede als Konto oder freier Name (auch ohne Konto, z. B. Partner\*innen oder Verstorbene). Jahre als Zeitraum („2025–2026“) oder einzelnes Jahr.

- **Aktuelles Königshaus:** die laufenden Amtszeiten (*Im Amt*) der Gruppe.
- **Chronik:** *Frühere* je Amt und die Ansicht *Jahre* mit einer Zeile je Jahr und einer Spalte je Amt.
- **Ersterfassung:** Import per CSV (`POST /offices/import`).
- **Titel:** Wer möchte, verknüpft z. B. „König“ mit einem Titel; die laufende Amtszeit vergibt ihn dann.

**Gemeinsame Amtszeit einer Gruppe** (entschieden am 05.11.2026, statt Bild je Jahr oder je Einzelamt): Die Ämter einer Gruppe lassen sich zu einer Amtszeit bündeln, z. B. „Königshaus 2025–2026“. Ihr gehören alle Personen mit ihren Ämtern und ein Gruppenbild.

| Änderung (additiv, Migration 39 ff.) | Zweck |
| --- | --- |
| `office_groups.joint_terms` (0/1) | Schalter je Gruppe „Amtszeiten gemeinsam“. Für das Königshaus an, für z. B. einen Vorstand mit versetzten Wahlen aus. |
| Tabelle `office_group_terms` | `group_id`, `kind`, `start_year`, `end_year`, `note`, `image_uid` (Gruppenbild als `rich_images`) |
| `office_terms.group_term_id` (nullable) | verknüpft die Amtszeit eines Amts mit der gemeinsamen Amtszeit |

- **Pflege:** Eine gemeinsame Amtszeit wird in einem Formular angelegt: Jahre, Bild, darunter je Amt der Gruppe die Personen. Die Jahre gelten für alle Ämter darin.
- **Die bisherigen Amtszeiten bleiben die Grundlage.** Der Server schreibt je Amt weiter eine normale Zeile in `office_terms` mit denselben Jahren. Chronik, Jahresübersicht, Zeitstrahl der Person, verknüpfte Titel und Import laufen deshalb unverändert weiter. Auch eine ältere Version nach einem Zurückrollen zeigt alle Personen; nur das Bild und die Bündelung sieht sie nicht.
- **Anzeige:** Mit dem Schalter zeigt die App die Gruppe als Chronik der gemeinsamen Amtszeiten: je Amtszeit eine Karte mit Gruppenbild und allen Personen mit ihren Ämtern; die laufende oben als „aktuelles Königshaus“.
- **Import:** Zeilen mit derselben Gruppe und denselben Jahren werden in einer Gruppe mit Schalter zu einer gemeinsamen Amtszeit zusammengefasst.
- Rechte unverändert: Pflege mit `manage_offices`, sichtbar wie die Ämter (`view_offices`, öffentlich).

**Personen ohne Konto** (z. B. Partner\*innen, Hofdamen): Sie werden wie heute als freier Name eingetragen (`office_holders.user_id` leer). Entschieden am 05.11.2026: Sie bekommen ein **eigenes Foto**. Neue Spalte `office_holders.image_uid` (nullable, Bild als `rich_images`), also ein Foto je Eintrag in einer Amtszeit. Das Formular bietet bei gleichem Namen das Foto aus einer früheren Amtszeit zur Übernahme an. Bei Konten bleibt das Foto des Kontos maßgeblich. Keine eigene Personenseite und kein Titel für freie Namen (so bestätigt). Hinweis: Die Fotos sind wie die Ämter öffentlich; die Einwilligung der Personen holt die Bruderschaft ein (Handbuch).

Optional später: „aus Kompanie X“ aus der Zuordnung der Person (Kompanieliste). Die öffentliche Chronik mit Namen und Fotos berührt die offene Rechtsfrage in `OFFENE-PUNKTE.md` Abschnitt 3, Punkt 9; die Prüfung kommt laut Entscheidung nicht vor dem Start.

## Galerie: Bilder einreichen und freigeben

**Entschieden am 05.11.2026:** Einreichen und Freigeben sind zwei eigene Rechte. Wer beide hat, darf auch eigene Bilder freigeben. Der Speicherplatz ist nicht begrenzt. Der Speicherort ist einrichtbar, und weitere Speicherorte sollen sich später anbinden lassen.

**Ablauf:**

1. Wer `submit_gallery` hat, wählt ein Album, wählt Bilder und bestätigt: „Ich habe die Rechte an den Bildern, und die erkennbaren Personen sind einverstanden.“
2. Der Server prüft Format und technische Grenzen des Webspace (`ServerLimits`), kodiert neu und entfernt Metadaten samt GPS (`JpegMetadata::strip`).
3. Alle mit `review_gallery` bekommen eine Benachrichtigung (neue Art im Push).
4. Freigeben (öffentlich oder intern) oder ablehnen mit kurzem Grund; die einreichende Person wird benachrichtigt.
5. Abgelehnte Bilder löscht der Server sofort. Unbearbeitete Einreichungen bleiben, bis jemand sie bearbeitet (entschieden, kein automatisches Löschen).

**Rechte** (in `Capability`, Voraussetzung in `requires()`):

| Recht | Bedeutung | Voraussetzung |
| --- | --- | --- |
| `submit_gallery` | Bilder einreichen, eigene Einreichungen sehen und zurückziehen | `member` |
| `review_gallery` | Einreichungen sehen, freigeben, ablehnen, freigegebene wieder entfernen | `member` |

Wer beide Rechte hat, darf eigene Einreichungen freigeben. Neu wäre die Standardrolle „Galerie verwalten“ (`member`, `review_gallery`). Bestehende Installationen mit eingeschalteter Galerie bekommen sie nicht von selbst, weil Standardrollen nur beim Einschalten entstehen (`Features::update`); eine einmalige Migration legt sie an.

**Speicherort einrichtbar** (entschieden: sowohl der Ablageort der Einreichungen als auch die Quelle der Galerie):

- Neue Schnittstelle im Kern, z. B. `Port/MediaStore`: Datei ablegen, lesen, löschen, Album zuordnen. Die Galerie spricht nur mit ihr.
- **Jetzt:** Speicherort „eigener Server“ (`FileStorage`). Die Bilder erscheinen im gewählten Album neben denen aus Drive.
- **Später:** z. B. Google Drive. Freigegebene Bilder landen dann im Album-Ordner in Drive, und der normale Abgleich holt sie. Das braucht mehr als `drive.readonly`; ob `drive.file` reicht, ist der offene Test in `OFFENE-PUNKTE.md` Abschnitt 5 (Issue #40).
- Einstellung unter *Einstellungen → Galerie*, nur für Admins (`manage_settings`).

**Quelle der Galerie einrichtbar:** Heute holt der Abgleich Alben und Bilder fest aus Google Drive (`GalleryService`, `DriveClient`). Neu kommt eine zweite Schnittstelle, z. B. `Port/GallerySource` (Alben und Bilder auflisten, Datei lesen). Erste Umsetzungen: „Google Drive“ (der heutige Weg, unverändert) und „eigener Server“ (Alben nur in der App, Bilder aus Einreichungen oder vom Admin hochgeladen). Weitere Quellen lassen sich später anbinden, ohne die Galerie selbst zu ändern. Bestehende Installationen behalten Drive als Quelle.

**Datenmodell** (additiv):

| Änderung | Zweck |
| --- | --- |
| `photos.source` (`drive` Standard, `upload`) | Der Abgleich mit Drive löscht und ändert nur Zeilen mit `drive` (`photosExcept`, Aufbereitung). |
| `photos.drive_file_id` für Uploads `upload:<Kennung>` | Spalte ist `NOT NULL UNIQUE`; das Präfix vermeidet einen Umbau der Tabelle. |
| `albums.source` (`drive`, `app`) | Alben nur in der App, wenn kein Drive eingerichtet ist; `drive_id` dann `app:<Kennung>`. |
| Tabelle `gallery_submissions` | `user_id`, Album, Datei, `status` (`pending`, `approved`, `rejected`), `consent_at`, `reviewed_by`, `reviewed_at`, `reason` |
| `Backup::FILE_DIRS` + Ordner `einreichungen` | Bilder auf dem eigenen Server lassen sich nicht aus Drive neu erzeugen und müssen in die Sicherung. |

**Galerie ohne Drive:** Heute gilt die Galerie nur mit Drive als eingerichtet (`GalleryService::isConfigured`). Mit App-Alben ist sie auch ohne Drive nutzbar.

**Speicherplatz:** keine Grenze in der App. Die Statusseite zeigt den belegten Platz, damit ein voller Webspace auffällt. Sicherungen werden mit vielen Bildern entsprechend groß.

**Konto löschen** (`UserData::purge`): offene Einreichungen werden gelöscht, freigegebene Bilder bleiben ohne Verknüpfung zur Person. Der Export der eigenen Daten (Art. 15 DSGVO) nennt die Einreichungen.

## Sponsoren

**Entschieden am 05.11.2026:** Vorerst nur eine Liste der Sponsoren mit Logo, in der Verwaltung sortierbar. Keine Stufen, keine Laufzeit, kein Streifen.

Bereich `sponsors` (Bezeichnung „Sponsoren“, über `labels.sections` umbenennbar), öffentlich.

| Feld (Tabelle `sponsors`) | Hinweis |
| --- | --- |
| `name` | Pflicht, auch als Alternativtext des Logos |
| `logo` | Bild oder SVG über `SvgSanitizer`, wie die Abzeichen der Dienstgrade |
| `url` | optional, nur `https:`; Link mit `rel="sponsored noopener noreferrer"` (von Google empfohlen für bezahlte Links) |
| `sort` | Reihenfolge per Ziehen, `PUT /sponsors/order` wie bei den Ämtern |

- App: Tab bzw. Kachel „Sponsoren“ mit einem Raster der Logos; Pflege unter *Verwaltung → Sponsoren*.
- Rechte: `view_sponsors` (Sehrecht, anfangs auch Gast), `manage_sponsors` (braucht `member`, Standardrolle „Sponsoren verwalten“).
- Stufen, Laufzeit und ein Streifen unter Terminen lassen sich später additiv ergänzen.

## Navigation einrichtbar

Empfehlung: Ja, der Admin stellt Hauptnavigation und Untermenüs selbst zusammen. Damit ist auch die Frage erledigt, wo Seiten, Kompanien und Sponsoren erscheinen.

**Heute:** Die Tab-Leiste ist im Code fest: Termine, News, Galerie, dann „Intern“ (mit `view_intern`) oder Mitglieder und Dokumente als eigene Tabs, dann Verwaltung und Meins; ausgeblendet wird, was die Person nicht sehen darf (`App.tsx`, `tabs`). „Intern“ ist schon ein Untermenü mit Kacheln (`internNav.tsx`).

**Neu:**

- Einstellung `navigation` (JSON im Einstellungsspeicher, wie `roles`): geordnete Liste der Hauptpunkte. Ein Punkt ist ein Bereich (Termine, Galerie, Kompanien, Sponsoren …), eine eigene Seite oder ein **Untermenü** mit Name, Symbol und eigenen Punkten (eine Ebene, als Kacheln wie „Intern“).
- Pflege unter *Einstellungen → Navigation*: Punkte per Ziehen ordnen, in Untermenüs schieben, umbenennen, Symbol wählen.
- **Nur Darstellung, keine Rechte:** Jeder sieht nur die Punkte, die er sehen darf; der Server prüft wie bisher. Ein Untermenü ohne sichtbare Punkte verschwindet.
- **Fest:** „Meins“ und „Verwaltung“ bleiben immer erreichbar, damit sich niemand aussperrt.
- **Platz:** Auf dem Handy passen unten nur wenige Tabs. Heute sind höchstens 7 Tabs zu sehen; mehr gehen nur in Untermenüs. Die genaue Zahl wird bei der Umsetzung an der Oberfläche geprüft.
- **Bestehende Installationen:** Ohne gespeicherte Einstellung gilt die heutige Reihenfolge, also ändert sich nichts. Das Recht `view_intern` steuert dann weiter das Untermenü „Intern“ der Standardnavigation.
- Neue Bereiche und Seiten erscheinen nach dem Einschalten zunächst am Ende der Hauptnavigation bzw. in einem Untermenü, bis der Admin sie verschiebt (genaue Regel bei der Umsetzung).

Aufwand: mittel, vor allem Oberfläche und Tests. Es berührt jede Installation, deshalb als eigene Phase, und zwar als erste (Entscheidung vom 05.11.2026).

## Review (05.11.2026)

Ergebnis: Das Konzept trägt, aber vier Lücken würden bei der Umsetzung sonst erst spät auffallen. Geprüft gegen `develop` (7c4fdbc).

| Befund | Beleg im Code | Folge fürs Konzept |
| --- | --- | --- |
| Formatierte Bilder (`rich_images`) gibt es nur für Angemeldete. | `RichImageService::forDelivery` antwortet ohne Anmeldung mit 401. | Öffentliche Seiten, Gruppenbilder und Fotos freier Namen brauchen eine öffentliche Auslieferung, abhängig vom Besitzer und dessen Sehrecht. Eigener Grundlagen-Schritt. |
| Neue Sehrechte haben bestehende Rollen nicht. | Gast-Standard steht in `Roles::PUBLIC_DEFAULTS`; für frühere Änderungen gab es einmalige Migrationen (`RoleService::migrateViewRights`, `migratePersonalRights`). | `view_companies` und `view_sponsors` brauchen eine einmalige Migration, die sie Gast und Mitglied gibt, sonst sieht nach dem Einschalten niemand etwas. Außerdem in `Capability::isView()` und `Roles::GRANTABLE` eintragen. |
| Der Drive-Abgleich löscht Alben, die es in Drive nicht mehr gibt. | `GalleryService` ruft `deleteAlbums($staleAlbums)`. | Hochgeladene Bilder in so einem Album gingen mit verloren. Der Abgleich muss Alben mit Uploads behalten. |
| `drive_file_id` wird als Drive-Kennung benutzt. | Fehlerliste baut `drive_url` daraus (`GalleryService`, Zeile 418). | Mit dem Präfix `upload:` müssen alle Stellen, die daraus Drive-Adressen bauen, `photos.source` beachten. |

Kleinere Korrekturen, im Konzept schon berücksichtigt:

- „Königshaus“ ist kein Bereich mehr, sondern der Name einer Gruppe, den der Admin vergibt. Nur „Kompanien“ und „Sponsoren“ sind über `labels.sections` umbenennbar.
- Navigation: Heute sind höchstens 7 Tabs zu sehen (Termine, News, Galerie, Mitglieder, Dokumente, Verwaltung, Meins; `App.tsx`). Die Grenze für die einrichtbare Navigation wird bei der Umsetzung an der Oberfläche festgelegt.
- Migrationsnummern: PR #271 belegt 38, ist aber zurückgestellt. Neue Migrationen bekommen die nächste freie Nummer zum Zeitpunkt des Merges; #271 muss dann neu nummerieren.
- Große Fotos als `rich_images` liegen als Base64 in der Datenbank und in jeder Sicherung. Vorschlag: Gruppenbilder und Personenfotos beim Hochladen auf z. B. 2048 px längste Kante verkleinern (Vorschlag, nicht gemessen).

## Phasen

Sechs Phasen (0 bis 5), sortiert nach Nutzen für die Bruderschaft und steigendem Risiko für bestehende Installationen. Jede Phase ist für sich nutzbar; innerhalb einer Phase je Schritt ein Issue und ein PR nach `develop`. Aufwände sind grobe Schätzungen, nicht gemessen.

**Phase 0: Bruderschaft starten (kein Code)**

| Schritt | Inhalt | Aufwand |
| --- | --- | --- |
| 0.1 | Bruderschaft per Formular „Neue Kompanie“ anlegen | kein Code |
| 0.2 | Königshaus als Gruppe in *Ämter* erfassen, auch per CSV-Import, freie Namen für Partner\*innen und Hofdamen | kein Code |

**Phase 1: Navigation** (entschieden am 05.11.2026: zuerst, damit die Navigation steht, wenn die neuen Bereiche kommen)

| Schritt | Inhalt | Aufwand |
| --- | --- | --- |
| 1.1 | Navigation einrichtbar: Hauptpunkte und Untermenüs, „Meins“ und „Verwaltung“ fest, ohne Einstellung wie heute | mittel |

**Phase 2: Kompanien und Sponsoren**

| Schritt | Inhalt | Aufwand |
| --- | --- | --- |
| 2.1 | Grundlage: einmalige Migration für neue Sehrechte | klein |
| 2.2 | Sponsoren mit Logo und Link, sortierbar | klein |
| 2.3 | Kompanieliste mit optionaler Zuordnung zu mehreren Kompanien | klein bis mittel |

**Phase 3: Königshaus**

| Schritt | Inhalt | Aufwand |
| --- | --- | --- |
| 3.1 | Grundlage: öffentliche Auslieferung von `rich_images` je Besitzer und Sehrecht, Verkleinern großer Fotos | klein bis mittel |
| 3.2 | Gemeinsame Amtszeiten einer Gruppe mit Gruppenbild | mittel |
| 3.3 | Fotos für Personen ohne Konto | klein |

**Phase 4: Galerie** (entschieden am 05.11.2026: vor den eigenen Seiten)

| Schritt | Inhalt | Aufwand |
| --- | --- | --- |
| 4.1 | Schnittstellen `GallerySource` und `MediaStore` einziehen, Drive als erste Umsetzung, ohne sichtbare Änderung | mittel |
| 4.2 | Quelle „eigener Server“: App-Alben, Galerie ohne Drive, Uploads in der Sicherung | mittel |
| 4.3 | Bilder einreichen und freigeben, Rechte, Benachrichtigungen | mittel bis groß |

**Phase 5: Eigene Seiten**

| Schritt | Inhalt | Aufwand |
| --- | --- | --- |
| 5.1 | Eigene Seiten in der App (nutzt 3.1 für Bilder auf öffentlichen Seiten) | mittel |

Warum diese Reihenfolge: Die Navigation kommt zuerst, damit jeder neue Bereich gleich an der gewünschten Stelle eingehängt werden kann; ein vorläufiges Menü „Mehr“ entfällt. Kompanien und Sponsoren sind klein und sofort sichtbar. Das Königshaus baut auf vorhandenem Code auf und liefert die öffentliche Bildauslieferung, die auch die eigenen Seiten brauchen. Die Galerie kommt auf Wunsch vor den eigenen Seiten. Sie ist am größten und berührt die Drive-Galerie, die bestehende Installationen heute nutzen; 4.1 ändert deshalb zuerst nur den Aufbau.

Jeder Schritt mit Tests, Handbuch (`docs/handbuch/`), `api/openapi.yaml`, Changelog und `check-neutral.sh`. „Kompanien“ und „Sponsoren“ sind über `labels.sections` umbenennbar; „Königshaus“ ist nur der Name einer Gruppe. Die rechtliche Prüfung kommt laut Entscheidung nicht vor dem Start. Alle Fragen sind beantwortet (05.11.2026).

## Quellen

- Code und Doku: Repo Kompanie-App-Template, Branch `develop` (7c4fdbc), Issue #273 und PR #271 (Stand 05.11.2026)
- [Google Search Central: Ausgehende Links kennzeichnen](https://developers.google.com/search/docs/crawling-indexing/qualify-outbound-links) (zu `rel="sponsored"`, Seite aktualisiert am 10.12.2025)
