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
    /** Mitglieder anlegen, Startpasswörter erzeugen. */
    case CreateUsers = 'create_users';
    /** Rollen vergeben. */
    case PromoteUsers = 'promote_users';
    /** Einstellungen und Updates (WordPress: Recht manage_options; zusätzlich gibt es dort das Formular im WP-Admin). */
    case ManageSettings = 'manage_settings';
}
