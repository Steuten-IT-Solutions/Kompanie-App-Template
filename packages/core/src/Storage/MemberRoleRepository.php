<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Zusatzrollen je Person (Issue #66), Tabelle member_roles. Die Grundrolle steht beim Adapter (Port\UserDirectory);
 * hier nur die zusätzlichen Rollen, in beiden Varianten gleich.
 */
final class MemberRoleRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Alle Zuordnungen in einer Abfrage (für die Rechte jedes Benutzers einer Anfrage). Fehlt die Tabelle noch (neuer
     * Programmcode, Migration noch nicht gelaufen), gibt es eben keine Zusatzrollen.
     *
     * @return array<int, list<string>> Benutzer-ID => Schlüssel der Rollen
     */
    public function all(): array
    {
        try {
            $rows = $this->db->select('SELECT user_id, role FROM {member_roles}');
        } catch (\RuntimeException) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['user_id']][] = (string) $r['role'];
        }
        return $out;
    }

    /**
     * Zusatzrollen einer Person ersetzen, in einer Transaktion. Bestehende Zuordnungen behalten ihr Datum.
     *
     * @param list<string> $current bisher (aus all())
     * @param list<string> $roles künftig
     */
    public function replace(int $userId, array $current, array $roles, string $now): void
    {
        $this->db->transaction(function () use ($userId, $current, $roles, $now): void {
            foreach (array_diff($current, $roles) as $role) {
                $this->db->execute('DELETE FROM {member_roles} WHERE user_id = ? AND role = ?', [$userId, $role]);
            }
            foreach (array_diff($roles, $current) as $role) {
                $this->db->insert('member_roles', ['user_id' => $userId, 'role' => $role, 'assigned_at' => $now]);
            }
        });
    }

    /** Eine Rolle bei allen entfernen (Rolle gelöscht). */
    public function removeRole(string $role): void
    {
        $this->db->execute('DELETE FROM {member_roles} WHERE role = ?', [$role]);
    }
}
