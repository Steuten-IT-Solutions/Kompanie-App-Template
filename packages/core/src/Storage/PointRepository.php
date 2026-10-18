<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Storage\Sql\Connection;

/** Punkte-Einträge. Stornierte Einträge bleiben erhalten (deleted_at). */
final class PointRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function forUser(int $userId): array
    {
        return $this->db->select(
            'SELECT * FROM {points} WHERE user_id = ? AND deleted_at IS NULL ORDER BY entry_date DESC, id DESC',
            [$userId],
        );
    }

    public function total(int $userId): int
    {
        return (int) $this->db->scalar(
            'SELECT COALESCE(SUM(points), 0) FROM {points} WHERE user_id = ? AND deleted_at IS NULL',
            [$userId],
        );
    }

    /** @return array<string, mixed>|null auch stornierte */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM {points} WHERE id = ?', [$id]);
    }

    /**
     * @param int[] $userIds
     * @return int[] IDs der neuen Einträge
     */
    public function add(array $userIds, int $points, string $reason, ?int $catalogId, string $date, int $by, string $now): array
    {
        return $this->db->transaction(function () use ($userIds, $points, $reason, $catalogId, $date, $by, $now): array {
            $ids = [];
            foreach ($userIds as $userId) {
                $ids[] = $this->db->insert('points', [
                    'user_id' => $userId,
                    'points' => $points,
                    'reason' => $reason,
                    'catalog_id' => $catalogId,
                    'entry_date' => $date,
                    'created_by' => $by,
                    'created_at' => $now,
                ]);
            }
            return $ids;
        });
    }

    public function cancel(int $id, int $by, string $now): bool
    {
        return $this->db->update('points', ['deleted_at' => $now, 'deleted_by' => $by], ['id' => $id]) > 0;
    }

    /** @return array<int, int> Summe je Benutzer */
    public function totals(): array
    {
        $out = [];
        foreach ($this->db->select('SELECT user_id, SUM(points) AS total FROM {points} WHERE deleted_at IS NULL GROUP BY user_id') as $r) {
            $out[(int) $r['user_id']] = (int) $r['total'];
        }
        return $out;
    }
}
