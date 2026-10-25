<?php

declare(strict_types=1);

namespace KompanieApp\Core\Auth;

/** Rechte in der App, unabhängig von WordPress. */
enum Capability: string
{
    /** Eigene Punkte/Strafen, interne Galerie; schließt den internen Kalender ein. */
    case Member = 'member';
    /** Interner Kalender ohne Mitgliederbereich, z. B. für Anwärter (Issue #23). */
    case Calendar = 'calendar';
    /**
     * Bereich „Intern“: Mitglieder und Dokumente stehen in der Navigation gesammelt unter einem Knopf statt einzeln. Nur die
     * Navigation; was die Person sieht, regeln die anderen Rechte (Dokumente z. B. der Mitgliederbereich). Wie der
     * Kalender auch ohne Mitgliederbereich vergebbar und in ihm enthalten.
     */
    case ViewIntern = 'view_intern';
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
     * Rechte, die es auch ohne Mitgliederbereich gibt (z. B. für Anwärter); der Mitgliederbereich enthält sie alle.
     *
     * @return list<self>
     */
    public static function withoutMember(): array
    {
        return [self::Calendar, self::ViewIntern];
    }

    /**
     * Wer die Mitgliederliste der Verwaltung sieht: wer Punkte oder Strafen vergibt, Dienstgrade oder Titel pflegt,
     * Mitglieder anlegt oder Rollen vergibt.
     *
     * @return list<self>
     */
    public static function memberList(): array
    {
        return [self::ManagePoints, self::ManagePenalties, self::ManageRanks, self::ManageTitles, self::CreateUsers, self::PromoteUsers];
    }
}
