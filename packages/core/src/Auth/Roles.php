<?php

declare(strict_types=1);

namespace KompanieApp\Core\Auth;

/**
 * Feste Rollen und die Rollen älterer Installationen.
 *
 * Welche Rollen es gibt und welche Rechte sie haben, entscheidet der Admin der Kompanie in der App
 * (Domain\RoleService). Fest sind guest (keine Rechte), member (Mitgliederbereich, Rolle neuer Konten)
 * und admin (alle Rechte); sie lassen sich nur umbenennen. Weitere Standardrollen bringen die Bereiche
 * mit, wenn der Admin sie einschaltet (Domain\Features, z. B. „Punkte verwalten“).
 */
final class Roles
{
    public const GUEST = 'guest';
    public const MEMBER = 'member';
    public const ADMIN = 'admin';
    /** Vorlagen bis Issue #28; nur noch in Installationen, die sie schon hatten (LEGACY). */
    public const ORGANIZER = 'organizer';
    public const MANAGER = 'manager';

    /** Längster Schlüssel (Spalte users.level der eigenständigen Version: varchar(10)). */
    public const KEY_MAX = 10;

    /**
     * Feste Rollen in der Reihenfolge der Anzeige: Schlüssel => Rechte. Die Bezeichnungen kommen bei der
     * Ersteinrichtung aus kompanie.json (labels.roles).
     *
     * @var array<string, string[]>
     */
    public const TEMPLATES = [
        self::GUEST => [],
        self::MEMBER => ['member'],
        self::ADMIN => [],
    ];

    /**
     * Rollen, die Installationen vor Issue #28 fest hatten. Wer auf diese Version wechselt, behält sie
     * (mit ihren Bezeichnungen aus kompanie.json), damit niemand Rechte verliert.
     *
     * @var array<string, string[]>
     */
    public const LEGACY = [
        self::ORGANIZER => ['member', 'manage_events', 'manage_documents'],
        self::MANAGER => ['member', 'manage_events', 'manage_documents', 'manage_points', 'manage_penalties'],
    ];

    /**
     * Rechte, die der Admin einer Rolle geben kann. manage_settings (Einstellungen, Updates,
     * Datensicherung, also auch das Einspielen von Programmcode) bleibt den Admins vorbehalten.
     */
    public const GRANTABLE = [
        Capability::Member,
        Capability::ManagePoints,
        Capability::ManagePenalties,
        Capability::ManageEvents,
        Capability::ManageDocuments,
        Capability::CreateUsers,
        Capability::PromoteUsers,
    ];

    /**
     * Rechte nach den festen und früheren Rollen, ohne die Einstellungen der Installation (für Adapter,
     * solange die App noch nicht steht, und für Tests).
     *
     * @return Capability[]
     */
    public static function templateCapabilities(string $key): array
    {
        if ($key === self::ADMIN) {
            return Capability::cases();
        }
        $caps = self::TEMPLATES[$key] ?? self::LEGACY[$key] ?? [];
        return array_values(array_filter(array_map(static fn (string $c): ?Capability => Capability::tryFrom($c), $caps)));
    }

    /** Fest: Rechte nicht änderbar, nicht löschbar (umbenennen geht). */
    public static function isSystem(string $key): bool
    {
        return isset(self::TEMPLATES[$key]);
    }
}
