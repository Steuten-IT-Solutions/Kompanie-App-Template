<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Punkte-Einträge. Stornierte Einträge bleiben erhalten (deleted_at).
 * Es zählt points × factor (Punktefaktor der Rolle beim Buchen, z. B. 0,5); Summen mit zwei Nachkommastellen.
 */
final class PointRepository
{
    private const SUM = 'COALESCE(SUM(points * factor), 0)';

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

    public function total(int $userId): float
    {
        return round((float) $this->db->scalar(
            'SELECT ' . self::SUM . ' FROM {points} WHERE user_id = ? AND deleted_at IS NULL',
            [$userId],
        ), 2);
    }

    /** @return array<string, mixed>|null auch stornierte */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM {points} WHERE id = ?', [$id]);
    }

    /**
     * @param array<int, float> $factors Benutzer-ID => Punktefaktor
     * @return int[] IDs der neuen Einträge
     */
    public function add(array $factors, int $points, string $reason, ?int $catalogId, string $date, int $by, string $now): array
    {
        return $this->db->transaction(function () use ($factors, $points, $reason, $catalogId, $date, $by, $now): array {
            $ids = [];
            foreach ($factors as $userId => $factor) {
                $ids[] = $this->db->insert('points', [
                    'user_id' => $userId,
                    'points' => $points,
                    'factor' => $factor,
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

    /** @return array<int, float> Summe je Benutzer */
    public function totals(): array
    {
        $out = [];
        foreach ($this->db->select('SELECT user_id, ' . self::SUM . ' AS total FROM {points} WHERE deleted_at IS NULL GROUP BY user_id') as $r) {
            $out[(int) $r['user_id']] = round((float) $r['total'], 2);
        }
        return $out;
    }
}
