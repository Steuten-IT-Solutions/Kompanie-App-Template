<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Ehemalige Mitglieder (Tabelle former_members): Grund, Eintritt, Ausscheiden und der Stand der Rollen von vorher. Das
 * Konto selbst steht beim Adapter (Port\UserDirectory) und hat die Rolle Gast.
 *
 * @phpstan-type Former array{user_id: int, reason: string, joined_on: string, founder: bool, left_on: string, restore: array<string, mixed>, created_by: int, created_at: string, updated_at: string}
 */
final class FormerMemberRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Alle in einer Abfrage. Fehlt die Tabelle noch (neuer Programmcode, Migration noch nicht gelaufen), gibt es eben keine.
     *
     * @return array<int, Former> Benutzer-ID => Eintrag
     */
    public function all(): array
    {
        try {
            $rows = $this->db->select('SELECT * FROM {former_members}');
        } catch (\RuntimeException) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['user_id']] = self::row($r);
        }
        return $out;
    }

    /** @return Former|null */
    public function find(int $userId): ?array
    {
        try {
            $r = $this->db->selectOne('SELECT * FROM {former_members} WHERE user_id = ?', [$userId]);
        } catch (\RuntimeException) {
            return null;
        }
        return $r === null ? null : self::row($r);
    }

    /** @param array<string, mixed> $restore */
    public function insert(int $userId, string $reason, string $joinedOn, bool $founder, string $leftOn, array $restore, int $by, string $now): void
    {
        $this->db->insert('former_members', [
            'user_id' => $userId,
            'reason' => $reason,
            'joined_on' => $joinedOn,
            'founder' => $founder ? 1 : 0,
            'left_on' => $leftOn,
            'restore' => (string) json_encode($restore),
            'created_by' => $by,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function update(int $userId, string $reason, string $joinedOn, bool $founder, string $leftOn, string $now): void
    {
        $this->db->update('former_members', [
            'reason' => $reason,
            'joined_on' => $joinedOn,
            'founder' => $founder ? 1 : 0,
            'left_on' => $leftOn,
            'updated_at' => $now,
        ], ['user_id' => $userId]);
    }

    public function delete(int $userId): void
    {
        $this->db->execute('DELETE FROM {former_members} WHERE user_id = ?', [$userId]);
    }

    /**
     * @param array<string, mixed> $r
     * @return Former
     */
    private static function row(array $r): array
    {
        $restore = json_decode((string) $r['restore'], true);
        return [
            'user_id' => (int) $r['user_id'],
            'reason' => (string) $r['reason'],
            'joined_on' => (string) $r['joined_on'],
            'founder' => (bool) (int) $r['founder'],
            'left_on' => (string) $r['left_on'],
            'restore' => is_array($restore) ? $restore : [],
            'created_by' => (int) $r['created_by'],
            'created_at' => (string) $r['created_at'],
            'updated_at' => (string) $r['updated_at'],
        ];
    }
}
