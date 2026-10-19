<?php

declare(strict_types=1);

namespace KompanieApp\Core\Auth;

/** Rechte in der App, unabhängig von WordPress. */
enum Capability: string
{
    /** Eigene Punkte/Strafen, interner Kalender, interne Galerie. */
    case Member = 'member';
    /** Punkte und Strafen vergeben, Katalog pflegen (Rolle manager, Standard „Leitung“). */
    case Manage = 'manage';
    /** Öffentliche Termine pflegen (ab Rolle organizer, Standard „Verwalter“). */
    case ManageEvents = 'manage_events';
    /**
     * Dokumente pflegen und alle Dokumente sehen, auch die mit eingeschränkter Sichtbarkeit (ab Rolle organizer).
     * Die Dokumente selbst kommen mit Issue #2.
     */
    case ManageDocuments = 'manage_documents';
    /** Mitglieder anlegen, Startpasswörter erzeugen. */
    case CreateUsers = 'create_users';
    /** Rollen vergeben. */
    case PromoteUsers = 'promote_users';
    /** Einstellungen und Updates (WordPress: Recht manage_options; zusätzlich gibt es dort das Formular im WP-Admin). */
    case ManageSettings = 'manage_settings';
}
