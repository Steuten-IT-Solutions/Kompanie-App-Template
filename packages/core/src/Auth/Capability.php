<?php

declare(strict_types=1);

namespace KompanieApp\Core\Auth;

/** Rechte in der App, unabhängig von WordPress. */
enum Capability: string
{
    /**
     * Gehört zur Kompanie: bekommt Punkte, Strafen, Dienstgrade und Titel, steht in Listen, Anwesenheit und mit Geburtstag
     * im Kalender. Was die Person sieht, regeln die Rechte view_* (früher schloss dieses Recht alles ein, Issue: Rechte
     * gruppieren); Rechte zum Verwalten setzen es voraus (requires()).
     */
    case Member = 'member';
    /** Interner Kalender (auch ohne Mitgliedschaft, z. B. für Anwärter, Issue #23). */
    case Calendar = 'calendar';
    /**
     * Bereich „Intern“: Die Knöpfe der Bereiche, die die Person sieht (view_directory, view_documents …), stehen in der
     * Navigation gesammelt unter einem Knopf statt einzeln. Nur die Navigation.
     */
    case ViewIntern = 'view_intern';
    /** Öffentliche Termine (Tab Termine). */
    case ViewEvents = 'view_events';
    /** News (Tab News). */
    case ViewNews = 'view_news';
    /** Öffentliche Alben der Galerie. */
    case ViewGallery = 'view_gallery';
    /** Liste der Kompanien (Bereich Kompanien, Issue #280). */
    case ViewCompanies = 'view_companies';
    /** Sponsoren (Bereich Sponsoren, Issue #279). */
    case ViewSponsors = 'view_sponsors';
    /** Interne Bilder und Videos der Galerie (Ordner „intern“). */
    case ViewGalleryInternal = 'view_gallery_internal';
    /** Eigene Punkte in „Meins“. */
    case ViewOwnPoints = 'view_own_points';
    /** Eigene Strafen in „Meins“. */
    case ViewOwnPenalties = 'view_own_penalties';
    /** Eigener Dienstgrad mit Verlauf und eigene Titel in „Meins“. */
    case ViewOwnRank = 'view_own_rank';
    /** Eigene Anwesenheit in „Meins“. */
    case ViewOwnAttendance = 'view_own_attendance';
    /** Geburtstage der Mitglieder sehen und am Tag benachrichtigt werden. */
    case ViewBirthdays = 'view_birthdays';
    /** An Umfragen teilnehmen (Umfragen sehen und abstimmen). */
    case VotePolls = 'vote_polls';
    /** Bei Terminen des internen Kalenders zu- und absagen (setzt den internen Kalender voraus, nicht member). */
    case RespondCalendar = 'respond_calendar';
    /** Intern: Liste der Mitglieder. */
    case ViewDirectory = 'view_directory';
    /** Intern: Ämter. */
    case ViewOffices = 'view_offices';
    /** Intern: ehemalige Mitglieder (in der Mitgliederliste). */
    case ViewFormer = 'view_former';
    /** Intern: Dokumente (die mit eingeschränkter Sichtbarkeit nur mit manage_documents). */
    case ViewDocuments = 'view_documents';
    /** Intern: Schwarzes Brett lesen und Benachrichtigungen dazu bekommen. */
    case ViewBoard = 'view_board';
    /** Intern: Liste der Dienstgrade. */
    case ViewRanks = 'view_ranks';
    /** Intern: Punktekatalog. */
    case ViewCatalog = 'view_catalog';
    /** Punkte aller Mitglieder sehen: in der Mitgliederliste, auf der Seite des Mitglieds, im Bericht und in Listen. */
    case ViewPoints = 'view_points';
    /** Punkte vergeben und stornieren, Punktekatalog pflegen (Bereich Punkte; setzt view_points voraus). */
    case ManagePoints = 'manage_points';
    /** Strafen aller Mitglieder sehen, auch die offenen, in der Mitgliederliste, im Bericht und in Listen. */
    case ViewPenalties = 'view_penalties';
    /** Strafen vergeben, als bezahlt markieren, stornieren (Bereich Strafen; setzt view_penalties voraus). */
    case ManagePenalties = 'manage_penalties';
    /** Dienstgrade der Mitglieder mit Verlauf und die anstehenden Beförderungen sehen (ohne zu befördern). */
    case ViewPromotions = 'view_promotions';
    /** Dienstgradliste pflegen, befördern und degradieren (Bereich Dienstgrade, Issue #9; setzt view_promotions voraus). */
    case ManageRanks = 'manage_ranks';
    /** Titelliste sehen und welche Titel die Mitglieder haben (in der Verwaltung). */
    case ViewTitles = 'view_titles';
    /** Titelliste pflegen und Titel zuweisen (Bereich Titel, Issue #22; setzt view_titles voraus). */
    case ManageTitles = 'manage_titles';
    /** Anwesenheitslisten je Termin und die Anwesenheit der Mitglieder sehen (ohne sie zu ändern). */
    case ViewAttendance = 'view_attendance';
    /** Anwesenheitslisten je Termin führen (Bereich Anwesenheit, Issue #21; setzt view_attendance voraus). */
    case ManageAttendance = 'manage_attendance';
    /** Öffentliche Termine pflegen (Bereich Termine). */
    case ManageEvents = 'manage_events';
    /** Bei internen Terminen sehen, wer zu- oder abgesagt hat, mit Hinweis (ohne Termine zu pflegen). */
    case ViewResponses = 'view_responses';
    /** Termine des internen Kalenders in der App pflegen (Bereich Kalender mit der Quelle „In der App“; setzt view_responses voraus). */
    case ManageCalendar = 'manage_calendar';
    /**
     * Dokumente pflegen und alle Dokumente sehen, auch die mit eingeschränkter Sichtbarkeit.
     * Die Dokumente selbst kommen mit Issue #2.
     */
    case ManageDocuments = 'manage_documents';
    /** Dringende Nachrichten ans Schwarze Brett schreiben, ändern und vorzeitig löschen (Bereich Schwarzes Brett, Issue #5). */
    case PostBoard = 'post_board';
    /** Umfragen anlegen, ändern, beenden und löschen, Ergebnisse immer sehen (Bereich Umfragen, Issue #4). */
    case ManagePolls = 'manage_polls';
    /** Inventar mit Lagerorten und Verlauf sehen (ohne zu ändern). */
    case ViewInventory = 'view_inventory';
    /** Inventar pflegen: Einträge anlegen, ändern, umlagern, löschen, Verlauf sehen (Bereich Inventar, Issue #6; setzt view_inventory voraus). */
    case ManageInventory = 'manage_inventory';
    /** Ämter, ihre Gruppen und Amtszeiten pflegen, auch frühere (Bereich Ämter, Issue #112). */
    case ManageOffices = 'manage_offices';
    /** Listen ansehen, drucken und als CSV laden (ohne sie zu ändern). */
    case ViewLists = 'view_lists';
    /** Listen zum Drucken und als CSV anlegen, bearbeiten und löschen (Bereich Listen, Issue #121; setzt view_lists voraus). */
    case ManageLists = 'manage_lists';
    /** Verlauf der E-Mails aus der Verwaltung mit Versandprotokoll sehen (ohne zu schreiben). */
    case ViewMail = 'view_mail';
    /** E-Mails aus der Verwaltung schreiben und versenden, Verlauf mit Versandprotokoll sehen (Bereich E-Mails; setzt view_mail voraus). */
    case SendMail = 'send_mail';
    /** Sponsoren pflegen: anlegen, ändern, Logo hochladen, sortieren, löschen (Bereich Sponsoren, Issue #279). */
    case ManageSponsors = 'manage_sponsors';
    /** Kompanieliste pflegen und Mitglieder zuordnen (Bereich Kompanien, Issue #280). */
    case ManageCompanies = 'manage_companies';
    /** Bilder für die Galerie einreichen, eigene Einreichungen sehen und zurückziehen (Issue #286). */
    case SubmitGallery = 'submit_gallery';
    /** Eingereichte Bilder und Videos zur Prüfung sehen, auch Videos vor der Freigabe (ohne freizugeben). */
    case ViewSubmissions = 'view_submissions';
    /** Einreichungen freigeben (öffentlich oder intern), ablehnen und freigegebene wieder entfernen (Issue #286; setzt view_submissions voraus). */
    case ReviewGallery = 'review_gallery';
    /**
     * Galerie verwalten: Alben der App anlegen, umbenennen, ins andere Jahr setzen und löschen, Bilder und Videos
     * hochladen, verschieben, löschen und intern oder öffentlich stellen, direkt in der Galerie. Quelle und Abgleich mit
     * Drive bleiben bei manage_settings.
     */
    case ManageGallery = 'manage_gallery';
    /** News in der App schreiben, bearbeiten und löschen (nur mit der Quelle „In der App“, NewsService). */
    case ManageNews = 'manage_news';
    /** Eigene Seiten anlegen, bearbeiten, sortieren und löschen (Bereich Seiten, Issue #287). */
    case ManagePages = 'manage_pages';
    /** Webseite pflegen: Startseite wählen, Einstiegsdatei anlegen (Bereich Webseite, Issue #273). */
    case ManageWebsite = 'manage_website';
    /**
     * Persönliche Angaben anderer sehen: Adresse, Handynummer, E-Mail-Adresse und das ganze Geburtsdatum (in der
     * Verwaltung auf der Seite der Person). Die eigenen sieht jede Person unter Meins → Konto.
     */
    case ViewPersonal = 'view_personal';
    /** Persönliche Angaben anderer ändern (setzt view_personal voraus). */
    case ManagePersonal = 'manage_personal';
    /**
     * Konten sehen (ohne zu ändern): wer ohne Zugang ist, Benutzername und E-Mail-Adresse des Kontos, Eintritt, Rollen,
     * Zusatzrollen und Ausnahmen bei den Rechten anderer, Anfragen auf einen Zugang und Aufnahmen. Ein Recht über Konten
     * (accountRights): Nur Admins geben es weiter (Entscheidung vom 17.11.2026).
     */
    case ViewAccounts = 'view_accounts';
    /** Mitglieder anlegen (auch mit ihren persönlichen Angaben), Startpasswörter erzeugen (setzt view_accounts voraus). */
    case CreateUsers = 'create_users';
    /**
     * Ehemalige verwalten: Liste der Ehemaligen in der Verwaltung, Ehemalige anlegen und importieren, Mitglieder als
     * ehemalig eintragen, die Angaben dazu ändern und wieder aufnehmen (FormerMemberService). Persönliche Angaben
     * Ehemaliger weiterhin nur mit view_personal bzw. manage_personal; die Aufbewahrungsfrist bleibt bei manage_settings.
     */
    case ManageFormer = 'manage_former';
    /** Liste der Passiven mit Betreuer und Beiträgen sehen (ohne zu ändern; persönliche Angaben nur mit view_personal). */
    case ViewPassive = 'view_passive';
    /**
     * Passive verwalten (Bereich passive, Domain\PassiveMemberService): Liste der Passiven in der Verwaltung, anlegen,
     * Namen und Betreuer ändern, löschen. Adresse, Telefon, E-Mail und Geburtsdatum der Passiven weiterhin nur mit
     * view_personal bzw. manage_personal. Passive sind keine Konten, darum kein Recht über Konten (accountRights). Setzt
     * view_passive voraus.
     */
    case ManagePassive = 'manage_passive';
    /** Rollen vergeben (setzt view_accounts voraus). */
    case PromoteUsers = 'promote_users';
    /** Fehlermeldungen der App mit Bildschirmfoto sehen (ohne sie zu schließen oder weiterzuleiten). */
    case ViewReports = 'view_reports';
    /**
     * Fehlermeldungen der App sehen (mit Namen angemeldeter Personen und Bildschirmfoto), mit Begründung schließen oder
     * an die Betreuer der App weiterleiten (BugReportService; setzt view_reports voraus).
     */
    case ReviewReports = 'review_reports';
    /**
     * Nutzungsauswertung ansehen: wer wann welchen Bereich genutzt hat (Issue #175). Nur Admins; nicht an Rollen vergebbar
     * (Roles::GRANTABLE), solange offen ist, wer außer dem Admin die Auswertung je Person sehen darf.
     */
    case ViewActivity = 'view_activity';
    /**
     * Änderungsprotokoll ansehen: wer wann welchen Eintrag angelegt, geändert oder gelöscht hat, mit alten und neuen Werten
     * (Domain\ChangeLogService). Werte persönlicher Angaben darin nur zusammen mit view_personal. An Rollen vergebbar.
     */
    case ViewChanges = 'view_changes';
    /** Einstellungen und Updates (WordPress: Recht manage_options; zusätzlich gibt es dort das Formular im WP-Admin). */
    case ManageSettings = 'manage_settings';

    /**
     * Recht, das dieses voraussetzt: Eigene Punkte, Strafen, Dienstgrad und Anwesenheit sowie alles zum Verwalten gibt es
     * nur für Mitglieder (sonst sähe die Person ihre Mitglieder nicht bzw. hätte nichts Eigenes). Persönliche Angaben
     * pflegt nur, wer sie sieht (manage_personal → view_personal → member). Die einzige Stelle für diese Regel; die App
     * hat sie in capabilityRules.ts (requires) noch einmal. Bei der Rolle Gast entfällt die Voraussetzung member
     * (RoleService::validCapabilities). Zu- und absagen setzt nur den internen Kalender voraus: So können auch Anwärter
     * und, wenn der Admin es will, Gäste mit Konto zusagen. Schreiben setzt das passende Lesen voraus (READ_OF,
     * Entscheidung vom 17.11.2026), das Lesen wiederum member.
     */
    public function requires(): ?self
    {
        if (in_array($this, self::ownData(), true)) {
            return self::Member;
        }
        if ($this === self::RespondCalendar) {
            return self::Calendar;
        }
        $read = self::READ_OF[$this->value] ?? null;
        if ($read !== null) {
            return self::from($read);
        }
        return $this->isView() || $this === self::Member ? null : self::Member;
    }

    /**
     * Schreibrecht => Leserecht, das es voraussetzt (Rechte in Lesen und Schreiben aufgeteilt, Entscheidung vom
     * 17.11.2026). Wer das Schreibrecht vor der Aufteilung hatte, bekommt das Leserecht beim Update einmal dazu
     * (RoleService::migrateReadRights); ein neues Paar kommt hier hinten dazu.
     */
    public const READ_OF = [
        'manage_personal' => 'view_personal',
        'manage_points' => 'view_points',
        'manage_penalties' => 'view_penalties',
        'manage_ranks' => 'view_promotions',
        'manage_titles' => 'view_titles',
        'manage_attendance' => 'view_attendance',
        'manage_calendar' => 'view_responses',
        'manage_inventory' => 'view_inventory',
        'manage_lists' => 'view_lists',
        'send_mail' => 'view_mail',
        'manage_passive' => 'view_passive',
        'review_gallery' => 'view_submissions',
        'review_reports' => 'view_reports',
        'create_users' => 'view_accounts',
        'promote_users' => 'view_accounts',
    ];

    /**
     * Rechte zum Sehen (Tabs, Knöpfe unter „Intern“, eigene Daten); Gast gilt dabei auch ohne Anmeldung.
     */
    public function isView(): bool
    {
        return in_array($this, [
            self::Calendar, self::ViewIntern, self::ViewEvents, self::ViewNews, self::ViewGallery, self::ViewCompanies,
            self::ViewSponsors, self::ViewGalleryInternal,
            self::ViewOwnPoints, self::ViewOwnPenalties, self::ViewOwnRank, self::ViewOwnAttendance, self::ViewBirthdays,
            self::VotePolls, self::ViewDirectory, self::ViewOffices, self::ViewFormer, self::ViewDocuments, self::ViewBoard,
            self::ViewRanks, self::ViewCatalog,
        ], true);
    }

    /**
     * Eigene Daten in „Meins“: nur für Mitglieder.
     *
     * @return list<self>
     */
    public static function ownData(): array
    {
        return [self::ViewOwnPoints, self::ViewOwnPenalties, self::ViewOwnRank, self::ViewOwnAttendance];
    }

    /**
     * Wer die Mitgliederliste der Verwaltung sieht: wer Punkte, Strafen, Dienstgrade, Titel oder die Anwesenheit der
     * Mitglieder sieht (das Schreibrecht setzt jeweils das Leserecht voraus), Mitglieder Kompanien zuordnet (Issue #280), persönliche Angaben sieht, Konten
     * sieht (Mitglieder anlegen und Rollen vergeben setzen das voraus), Ehemalige verwaltet (Mitglieder als ehemalig
     * eintragen geht auf ihrer Seite); wie memberListAllowed() in adminNav.tsx. Punkte und Strafen der Mitglieder zeigt sie nur mit dem jeweiligen Recht
     * (MemberService::visibleParts).
     *
     * @return list<self>
     */
    public static function memberList(): array
    {
        return [self::ViewPoints, self::ViewPenalties, self::ViewPromotions, self::ViewTitles, self::ViewAttendance, self::ManageCompanies, self::ViewPersonal, self::ViewAccounts, self::ManageFormer];
    }

    /**
     * Rechte über Konten anderer: Konten sehen, Konten anlegen und Passwörter setzen, Mitglieder als ehemalig eintragen (das Konto
     * stilllegen) und wieder aufnehmen, Rollen vergeben. Wer nicht Admin ist, vergibt sie nicht weiter (weder mit einer
     * Rolle noch einzeln) und verwaltet keine Personen, die eines davon haben (RoleService::canHandle); so kann niemand
     * sich gegenseitig stilllegen oder sich über eine andere Person mehr Rechte verschaffen.
     *
     * @return list<self>
     */
    public static function accountRights(): array
    {
        return [self::ViewAccounts, self::CreateUsers, self::ManageFormer, self::PromoteUsers];
    }
}
