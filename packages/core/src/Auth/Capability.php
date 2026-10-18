<?php

declare(strict_types=1);

namespace KompanieApp\Core\Auth;

/** Rechte in der App, unabhängig von WordPress. */
enum Capability: string
{
    /** Eigene Punkte/Strafen, interner Kalender, interne Galerie. */
    case Member = 'member';
    /** Punkte und Strafen vergeben, Katalog pflegen (Verwalter). */
    case Manage = 'manage';
    /** Öffentliche Termine pflegen. */
    case ManageEvents = 'manage_events';
    /** Mitglieder anlegen, Startpasswörter erzeugen. */
    case CreateUsers = 'create_users';
    /** Rollen vergeben. */
    case PromoteUsers = 'promote_users';
    /** Einstellungen und Updates (WordPress: Recht manage_options; zusätzlich gibt es dort das Formular im WP-Admin). */
    case ManageSettings = 'manage_settings';
}
