<?php

declare(strict_types=1);

namespace KompanieApp\Core\Auth;

/**
 * Feste Rollen und die Rollen älterer Installationen.
 *
 * Welche Rollen es gibt und welche Rechte sie haben, entscheidet der Admin der Kompanie in der App
 * (Domain\RoleService). Fest sind guest (gilt auch ohne Anmeldung), member (Mitglied, Rolle neuer Konten) und admin
 * (alle Rechte); sie lassen sich umbenennen (Mitglied und Admin bekommen auch einen Punktefaktor), bei Gast und
 * Mitglied auch die Rechte zum Sehen. Weitere
 * Standardrollen bringen die Bereiche mit, wenn der Admin sie einschaltet (Domain\Features, z. B. „Punkte verwalten“).
 * Anwärter (Issue #23) legt der Admin als eigene Rolle an, z. B. nur mit dem internen Kalender.
 */
final class Roles
{
    public const GUEST = 'guest';
    /** Frühere feste Rolle „Anwärter“; Installationen, die sie hatten, behalten sie als eigene Rolle (RoleService). */
    public const CANDIDATE = 'candidate';
    public const MEMBER = 'member';
    public const ADMIN = 'admin';
    /** Vorlagen bis Issue #28; nur noch in Installationen, die sie schon hatten (LEGACY). */
    public const ORGANIZER = 'organizer';
    public const MANAGER = 'manager';

    /** Längster Schlüssel (Spalte users.level der eigenständigen Version: varchar(10)). */
    public const KEY_MAX = 10;

    /**
     * Was ohne Anmeldung zu sehen war, bevor sich das einstellen ließ: die Vorgabe für Gast (gilt auch ohne Anmeldung)
     * und für jede Rolle beim Update (RoleService::migrateViewRights).
     */
    public const PUBLIC_DEFAULTS = ['view_events', 'view_news', 'view_gallery', 'view_directory', 'view_offices', 'view_former'];

    /**
     * Was der frühere Mitgliederbereich (Recht member) alles einschloss, dazu das Öffentliche: die Vorgabe für Mitglied
     * und die Rollen, die die Bereiche mitbringen.
     */
    public const MEMBER_DEFAULTS = [
        'member', 'calendar', 'view_intern', ...self::PUBLIC_DEFAULTS, 'view_gallery_internal', 'view_own_points',
        'view_own_penalties', 'view_own_rank', 'view_own_attendance', 'view_birthdays', 'vote_polls', 'view_documents',
        'view_board', 'view_ranks', 'view_catalog',
    ];

    /**
     * Feste Rollen in der Reihenfolge der Anzeige: Schlüssel => Rechte, wie bei der Ersteinrichtung. Die Bezeichnungen
     * kommen dann aus kompanie.json (labels.roles). Admin hat immer alle Rechte; bei Gast und Mitglied lassen sich die
     * Rechte zum Sehen ändern (Capability::isView), Mitglied hat immer member, Gast nie.
     *
     * @var array<string, string[]>
     */
    public const TEMPLATES = [
        self::GUEST => self::PUBLIC_DEFAULTS,
        self::MEMBER => self::MEMBER_DEFAULTS,
        self::ADMIN => [],
    ];

    /**
     * Rollen, die Installationen vor Issue #28 fest hatten. Wer auf diese Version wechselt, behält sie
     * (mit ihren Bezeichnungen aus kompanie.json), damit niemand Rechte verliert.
     *
     * @var array<string, string[]>
     */
    public const LEGACY = [
        self::ORGANIZER => [...self::MEMBER_DEFAULTS, 'manage_events', 'manage_documents'],
        self::MANAGER => [...self::MEMBER_DEFAULTS, 'manage_events', 'manage_documents', 'manage_points', 'manage_penalties'],
    ];

    /** Frühere feste Rolle „Anwärter“: interner Kalender und das Brett (wie damals mit dem Kalender), dazu das Öffentliche. */
    public const CANDIDATE_DEFAULTS = ['calendar', 'view_board', ...self::PUBLIC_DEFAULTS];

    /**
     * Rechte, die der Admin einer Rolle geben kann, in der Reihenfolge der Anzeige (Gruppen in capabilities.ts).
     * manage_settings (Einstellungen, Updates, Datensicherung, also auch das Einspielen von Programmcode) bleibt den
     * Admins vorbehalten.
     */
    public const GRANTABLE = [
        // Tabs und Navigation
        Capability::ViewEvents,
        Capability::ViewNews,
        Capability::ViewGallery,
        Capability::ViewIntern,
        // Mitgliederbereich
        Capability::Member,
        Capability::ViewOwnPoints,
        Capability::ViewOwnPenalties,
        Capability::ViewOwnRank,
        Capability::ViewOwnAttendance,
        Capability::Calendar,
        Capability::ViewGalleryInternal,
        Capability::ViewBirthdays,
        Capability::VotePolls,
        // Intern
        Capability::ViewDirectory,
        Capability::ViewOffices,
        Capability::ViewFormer,
        Capability::ViewDocuments,
        Capability::ViewBoard,
        Capability::ViewRanks,
        Capability::ViewCatalog,
        // Inhalte verwalten
        Capability::ManageEvents,
        Capability::ManageDocuments,
        Capability::PostBoard,
        Capability::ManagePolls,
        Capability::ManageLists,
        Capability::SendMail,
        // Kompanie verwalten
        Capability::ManagePoints,
        Capability::ManagePenalties,
        Capability::ManageRanks,
        Capability::ManageTitles,
        Capability::ManageAttendance,
        Capability::ManageOffices,
        Capability::ManageInventory,
        // Mitglieder und Rollen
        Capability::CreateUsers,
        Capability::PromoteUsers,
    ];

    /**
     * Rechte nach den festen und früheren Rollen (auch Anwärter), ohne die Einstellungen der Installation (für
     * Adapter, solange die App noch nicht steht, und für Tests).
     *
     * @return Capability[]
     */
    public static function templateCapabilities(string $key): array
    {
        if ($key === self::ADMIN) {
            return Capability::cases();
        }
        $caps = self::TEMPLATES[$key] ?? self::LEGACY[$key] ?? ($key === self::CANDIDATE ? self::CANDIDATE_DEFAULTS : []);
        return self::from($caps);
    }

    /**
     * Rechte aus ihren Werten; unbekannte fallen weg, Doppelte auch.
     *
     * @param string[] $values
     * @return Capability[]
     */
    public static function from(array $values): array
    {
        $out = [];
        foreach ($values as $v) {
            $cap = Capability::tryFrom($v);
            if ($cap !== null && !in_array($cap, $out, true)) {
                $out[] = $cap;
            }
        }
        return $out;
    }

    /**
     * Rechte, wie sie vor dem Aufteilen gemeint waren (für die Migration und ältere Sicherungen): Jeder sah das
     * Öffentliche, der Mitgliederbereich (member) schloss alles ein, was MEMBER_DEFAULTS aufzählt, der Kalender ohne
     * ihn das Brett.
     *
     * @param string[] $values
     * @return string[]
     */
    public static function expandLegacy(array $values): array
    {
        $add = in_array('member', $values, true) ? self::MEMBER_DEFAULTS : (in_array('calendar', $values, true) ? ['view_board'] : []);
        return array_values(array_unique([...$values, ...self::PUBLIC_DEFAULTS, ...$add]));
    }

    /** Fest: nicht löschbar (umbenennen geht; bei Gast und Mitglied lassen sich die Rechte zum Sehen ändern). */
    public static function isSystem(string $key): bool
    {
        return isset(self::TEMPLATES[$key]);
    }
}
