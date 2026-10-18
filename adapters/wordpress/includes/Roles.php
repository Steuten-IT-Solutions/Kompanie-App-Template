<?php

declare(strict_types=1);

namespace KompanieApp\WordPress;

/**
 * Rollen und Rechte der App in WordPress.
 *
 * - Admin     = WordPress-Administrator, darf alles.
 * - Verwalter = Rolle kompanie_manager (Punkte/Strafen verwalten + Mitglied).
 * - Mitglied  = Rolle kompanie_member.
 * - Gast      = nicht angemeldet oder angemeldet ohne Mitgliedsrecht.
 *
 * Verwalter und Mitglied werden zusätzlich zur bestehenden WordPress-Rolle vergeben
 * (z. B. Redakteur + Mitglied), damit niemand seine Webseiten-Rechte verliert.
 */
final class Roles
{
    public const ROLE_MEMBER = 'kompanie_member';
    public const ROLE_MANAGER = 'kompanie_manager';
    public const CAP_MEMBER = 'kompanie_member';
    public const CAP_MANAGE = 'kompanie_manage';
    public const CAP_EVENTS = 'kompanie_manage_events';

    /** Legt Rollen an bzw. ergänzt fehlende Rechte (idempotent). */
    public static function install(string $memberLabel, string $managerLabel): void
    {
        if (!get_role(self::ROLE_MEMBER)) {
            add_role(self::ROLE_MEMBER, $memberLabel, ['read' => true]);
        }
        if (!get_role(self::ROLE_MANAGER)) {
            add_role(self::ROLE_MANAGER, $managerLabel, ['read' => true]);
        }
        $caps = [
            self::ROLE_MEMBER => [self::CAP_MEMBER],
            self::ROLE_MANAGER => [self::CAP_MEMBER, self::CAP_MANAGE],
            'administrator' => [self::CAP_MEMBER, self::CAP_MANAGE, self::CAP_EVENTS],
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
}
