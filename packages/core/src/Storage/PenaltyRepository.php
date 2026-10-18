<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Storage\Sql\Connection;

/** Strafen in ganzen Cent. Stornierte Einträge bleiben erhalten (deleted_at). */
final class PenaltyRepository
{
    public const OPEN = 'open';
    public const PAID = 'paid';

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function forUser(int $userId): array
    {
        return $this->db->select(
            'SELECT * FROM {penalties} WHERE user_id = ? AND deleted_at IS NULL ORDER BY entry_date DESC, id DESC',
            [$userId],
        );
    }

    public function openCents(int $userId): int
    {
        return (int) $this->db->scalar(
            'SELECT COALESCE(SUM(amount_cents), 0) FROM {penalties} WHERE user_id = ? AND status = ? AND deleted_at IS NULL',
            [$userId, self::OPEN],
        );
    }

    /** @return array<string, mixed>|null auch stornierte */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM {penalties} WHERE id = ?', [$id]);
    }

    /**
     * @param int[] $userIds
     * @return int[]
     */
    public function add(array $userIds, int $cents, string $reason, string $date, int $by, string $now): array
    {
        return $this->db->transaction(function () use ($userIds, $cents, $reason, $date, $by, $now): array {
            $ids = [];
            foreach ($userIds as $userId) {
                $ids[] = $this->db->insert('penalties', [
                    'user_id' => $userId,
                    'amount_cents' => $cents,
                    'reason' => $reason,
                    'entry_date' => $date,
                    'status' => self::OPEN,
                    'created_by' => $by,
                    'created_at' => $now,
                ]);
            }
            return $ids;
        });
    }

    public function setStatus(int $id, string $status, int $by, string $now): void
    {
        $paid = $status === self::PAID;
        $this->db->update('penalties', [
            'status' => $status,
            'paid_at' => $paid ? $now : null,
            'paid_marked_by' => $paid ? $by : null,
        ], ['id' => $id]);
    }

    public function cancel(int $id, int $by, string $now): bool
    {
        return $this->db->update('penalties', ['deleted_at' => $now, 'deleted_by' => $by], ['id' => $id]) > 0;
    }

    /** @return array<int, int> offene Cent je Benutzer */
    public function openTotals(): array
    {
        $out = [];
        $rows = $this->db->select(
            'SELECT user_id, SUM(amount_cents) AS total FROM {penalties} WHERE deleted_at IS NULL AND status = ? GROUP BY user_id',
            [self::OPEN],
        );
        foreach ($rows as $r) {
            $out[(int) $r['user_id']] = (int) $r['total'];
        }
        return $out;
    }
}
