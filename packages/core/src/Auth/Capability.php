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
    /** Punkte vergeben und stornieren, Punktekatalog pflegen (Bereich Punkte). */
    case ManagePoints = 'manage_points';
    /** Strafen vergeben, als bezahlt markieren, stornieren (Bereich Strafen). */
    case ManagePenalties = 'manage_penalties';
    /** Dienstgradliste pflegen, befördern und degradieren (Bereich Dienstgrade, Issue #9). */
    case ManageRanks = 'manage_ranks';
    /** Titelliste pflegen und Titel zuweisen (Bereich Titel, Issue #22). */
    case ManageTitles = 'manage_titles';
    /** Anwesenheitslisten je Termin führen (Bereich Anwesenheit, Issue #21). */
    case ManageAttendance = 'manage_attendance';
    /** Öffentliche Termine pflegen (Bereich Termine). */
    case ManageEvents = 'manage_events';
    /**
     * Dokumente pflegen und alle Dokumente sehen, auch die mit eingeschränkter Sichtbarkeit.
     * Die Dokumente selbst kommen mit Issue #2.
     */
    case ManageDocuments = 'manage_documents';
    /** Dringende Nachrichten ans Schwarze Brett schreiben, ändern und vorzeitig löschen (Bereich Schwarzes Brett, Issue #5). */
    case PostBoard = 'post_board';
    /** Umfragen anlegen, ändern, beenden und löschen, Ergebnisse immer sehen (Bereich Umfragen, Issue #4). */
    case ManagePolls = 'manage_polls';
    /** Inventar pflegen: Einträge anlegen, ändern, umlagern, löschen, Verlauf sehen (Bereich Inventar, Issue #6). */
    case ManageInventory = 'manage_inventory';
    /** Ämter, ihre Gruppen und Amtszeiten pflegen, auch frühere (Bereich Ämter, Issue #112). */
    case ManageOffices = 'manage_offices';
    /** Listen zum Drucken und als CSV anlegen, bearbeiten und löschen (Bereich Listen, Issue #121). */
    case ManageLists = 'manage_lists';
    /** E-Mails aus der Verwaltung schreiben und versenden, Verlauf mit Versandprotokoll sehen (Bereich E-Mails). */
    case SendMail = 'send_mail';
    /** Mitglieder anlegen, Startpasswörter erzeugen. */
    case CreateUsers = 'create_users';
    /** Rollen vergeben. */
    case PromoteUsers = 'promote_users';
    /**
     * Nutzungsauswertung ansehen: wer wann welchen Bereich genutzt hat (Issue #175). Nur Admins; nicht an Rollen vergebbar
     * (Roles::GRANTABLE), solange offen ist, wer außer dem Admin die Auswertung je Person sehen darf.
     */
    case ViewActivity = 'view_activity';
    /** Einstellungen und Updates (WordPress: Recht manage_options; zusätzlich gibt es dort das Formular im WP-Admin). */
    case ManageSettings = 'manage_settings';

    /**
     * Recht, das dieses voraussetzt: Eigene Punkte, Strafen, Dienstgrad und Anwesenheit sowie alles zum Verwalten gibt es
     * nur für Mitglieder (sonst sähe die Person ihre Mitglieder nicht bzw. hätte nichts Eigenes). Die einzige Stelle
     * für diese Regel; die App hat sie in capabilities.ts (REQUIRES) noch einmal.
     */
    public function requires(): ?self
    {
        if (in_array($this, self::ownData(), true)) {
            return self::Member;
        }
        return $this->isView() || $this === self::Member ? null : self::Member;
    }

    /**
     * Rechte zum Sehen (Tabs, Knöpfe unter „Intern“, eigene Daten): Diese lassen sich auch bei Gast und Mitglied
     * einstellen (Roles::VIEW); Gast gilt dabei auch ohne Anmeldung.
     */
    public function isView(): bool
    {
        return in_array($this, [
            self::Calendar, self::ViewIntern, self::ViewEvents, self::ViewNews, self::ViewGallery, self::ViewGalleryInternal,
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
     * Wer die Mitgliederliste der Verwaltung sieht: wer Punkte oder Strafen vergibt, Dienstgrade, Titel oder die
     * Anwesenheit pflegt, Mitglieder anlegt oder Rollen vergibt (wie memberListAllowed() in adminNav.tsx). Punkte und
     * Strafen der Mitglieder zeigt sie nur mit dem jeweiligen Recht (MemberService::visibleParts).
     *
     * @return list<self>
     */
    public static function memberList(): array
    {
        return [self::ManagePoints, self::ManagePenalties, self::ManageRanks, self::ManageTitles, self::ManageAttendance, self::CreateUsers, self::PromoteUsers];
    }
}
