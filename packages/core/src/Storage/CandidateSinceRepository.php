<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Storage\Sql\Connection;

/**
 * „Anwärter seit“ je Person und Anwärter-Rolle (Domain\AdmissionService), Tabelle candidate_since. since ist ein Datum
 * (JJJJ-MM-TT) oder leer: dann ist es nicht bekannt und lässt sich nachtragen.
 */
final class CandidateSinceRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Alle Einträge in einer Abfrage. Fehlt die Tabelle noch (Migration noch nicht gelaufen), gibt es keine.
     *
     * @return array<int, array<string, string>> Benutzer-ID => Rolle => Datum ('' = unbekannt)
     */
    public function all(): array
    {
        try {
            $rows = $this->db->select('SELECT user_id, role_key, since FROM {candidate_since}');
        } catch (\RuntimeException) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['user_id']][(string) $r['role_key']] = (string) $r['since'];
        }
        return $out;
    }

    /** ID der Zeile (Änderungsprotokoll), null ohne. */
    public function idOf(int $userId, string $role): ?int
    {
        $id = $this->db->scalar('SELECT id FROM {candidate_since} WHERE user_id = ? AND role_key = ?', [$userId, $role]);
        return is_scalar($id) ? (int) $id : null;
    }

    /** Setzen (anlegen oder ändern). */
    public function save(int $userId, string $role, string $since, int $by, string $now): void
    {
        $values = ['since' => $since, 'updated_by' => $by, 'updated_at' => $now];
        $this->db->transaction(function () use ($userId, $role, $values): void {
            if ($this->db->update('candidate_since', $values, ['user_id' => $userId, 'role_key' => $role]) === 0
                && $this->idOf($userId, $role) === null) {
                $this->db->insert('candidate_since', $values + ['user_id' => $userId, 'role_key' => $role]);
            }
        });
    }

    /** Einträge einer Person entfernen (aufgenommen). */
    public function deleteFor(int $userId): void
    {
        $this->db->execute('DELETE FROM {candidate_since} WHERE user_id = ?', [$userId]);
    }
}
