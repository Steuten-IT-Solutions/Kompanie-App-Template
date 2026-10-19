<?php

declare(strict_types=1);

namespace KompanieApp\WordPress;

/**
 * Rollen und Rechte der App in WordPress.
 *
 * - Admin     = WordPress-Administrator, darf alles.
 * - Leitung   = Rolle kompanie_manager (Punkte/Strafen verwalten + alles von Verwalter).
 * - Verwalter = Rolle kompanie_organizer (öffentliche Termine und Dokumente pflegen + Mitglied).
 * - Mitglied  = Rolle kompanie_member.
 * - Gast      = nicht angemeldet oder angemeldet ohne Mitgliedsrecht.
 * (Standardbezeichnungen; jede Kompanie kann sie in kompanie.json umbenennen.)
 *
 * Diese Rollen werden zusätzlich zur bestehenden WordPress-Rolle vergeben
 * (z. B. Redakteur + Mitglied), damit niemand seine Webseiten-Rechte verliert.
 */
final class Roles
{
    public const ROLE_MEMBER = 'kompanie_member';
    public const ROLE_ORGANIZER = 'kompanie_organizer';
    public const ROLE_MANAGER = 'kompanie_manager';
    public const CAP_MEMBER = 'kompanie_member';
    public const CAP_MANAGE = 'kompanie_manage';
    public const CAP_EVENTS = 'kompanie_manage_events';
    public const CAP_DOCUMENTS = 'kompanie_manage_documents';

    /**
     * Legt Rollen an, ergänzt fehlende Rechte und übernimmt die Bezeichnungen (idempotent).
     *
     * @param array{member: string, organizer: string, manager: string} $labels
     */
    public static function install(array $labels): void
    {
        $roles = [self::ROLE_MEMBER => $labels['member'], self::ROLE_ORGANIZER => $labels['organizer'], self::ROLE_MANAGER => $labels['manager']];
        foreach ($roles as $roleName => $label) {
            if (!get_role($roleName)) {
                add_role($roleName, $label, ['read' => true]);
            }
        }
        self::rename($roles);
        $caps = [
            self::ROLE_MEMBER => [self::CAP_MEMBER],
            self::ROLE_ORGANIZER => [self::CAP_MEMBER, self::CAP_EVENTS, self::CAP_DOCUMENTS],
            self::ROLE_MANAGER => [self::CAP_MEMBER, self::CAP_EVENTS, self::CAP_DOCUMENTS, self::CAP_MANAGE],
            'administrator' => [self::CAP_MEMBER, self::CAP_EVENTS, self::CAP_DOCUMENTS, self::CAP_MANAGE],
        ];
        foreach ($caps as $roleName => $roleCaps) {
            $role = get_role($roleName);
            if (!$role) {
                continue;
            }
            foreach ($roleCaps as $cap) {
                if (!$role->has_cap($cap)) {
                    $role->add_cap($cap);
                }
            }
        }
    }

    /**
     * Bezeichnungen bestehender Rollen angleichen, z. B. wenn kompanie.json sie ändert oder sich der
     * Standard ändert (kompanie_manager hieß früher „Verwalter“). WordPress hat dafür keine Funktion.
     *
     * @param array<string, string> $names Rolle => Bezeichnung
     */
    private static function rename(array $names): void
    {
        $wpRoles = wp_roles();
        $changed = false;
        foreach ($names as $roleName => $label) {
            if (isset($wpRoles->roles[$roleName]) && $wpRoles->roles[$roleName]['name'] !== $label) {
                $wpRoles->roles[$roleName]['name'] = $label;
                $wpRoles->role_names[$roleName] = $label;
                $changed = true;
            }
        }
        if ($changed && $wpRoles->use_db) {
            update_option($wpRoles->role_key, $wpRoles->roles);
        }
    }
}
