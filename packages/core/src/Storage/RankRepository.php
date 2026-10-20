<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Dienstgrade (Issue #9): die Liste, der zugewiesene Dienstgrad je Mitglied und der Verlauf.
 *
 * @phpstan-type Rank array{id: int, title: string, sort: int, min_points: int|null, active: bool, badge_file: string, badge_ext: string,
 *     badge_version: string}
 * @phpstan-type HistoryEntry array{id: int, user_id: int, from_rank_id: int|null, to_rank_id: int|null, from_title: string,
 *     to_title: string, kind: string, points_at_change: float|null, reason: string, changed_by: int, changed_at: string}
 */
final class RankRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return list<Rank> nach Reihenfolge, der niedrigste zuerst */
    public function all(bool $onlyActive): array
    {
        $where = $onlyActive ? 'WHERE active = 1' : '';
        return array_values(array_map(self::rank(...), $this->db->select("SELECT * FROM {ranks} $where ORDER BY sort ASC, id ASC")));
    }

    /** @return Rank|null */
    public function find(int $id): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM {ranks} WHERE id = ?', [$id]);
        return $row === null ? null : self::rank($row);
    }

    public function insert(string $title, int $sort, ?int $minPoints): int
    {
        return $this->db->insert('ranks', ['title' => $title, 'sort' => $sort, 'min_points' => $minPoints, 'active' => 1]);
    }

    /** @param array{title?: string, sort?: int, min_points?: int|null, active?: bool, badge_file?: string, badge_ext?: string, badge_version?: string} $data */
    public function update(int $id, array $data): void
    {
        if (isset($data['active'])) {
            $data['active'] = $data['active'] ? 1 : 0;
        }
        if ($data !== []) {
            $this->db->update('ranks', $data, ['id' => $id]);
        }
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM {ranks} WHERE id = ?', [$id]);
    }

    public function maxSort(): int
    {
        return (int) $this->db->scalar('SELECT COALESCE(MAX(sort), 0) FROM {ranks}');
    }

    /** Wie viele Mitglieder haben diesen Dienstgrad? */
    public function assignedCount(int $rankId): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM {member_ranks} WHERE rank_id = ?', [$rankId]);
    }

    public function rankOf(int $userId): ?int
    {
        $id = $this->db->scalar('SELECT rank_id FROM {member_ranks} WHERE user_id = ?', [$userId]);
        return $id === null || $id === false ? null : (int) $id;
    }

    /** @return array<int, int> Benutzer-ID => Dienstgrad-ID */
    public function assignments(): array
    {
        $out = [];
        foreach ($this->db->select('SELECT user_id, rank_id FROM {member_ranks}') as $r) {
            $out[(int) $r['user_id']] = (int) $r['rank_id'];
        }
        return $out;
    }

    /**
     * Dienstgrad setzen (null = keiner) und den Verlauf schreiben, in einer Transaktion.
     *
     * @param array{from_rank_id: int|null, to_rank_id: int|null, from_title: string, to_title: string, kind: string,
     *     points_at_change: float|null, reason: string, changed_by: int} $entry
     */
    public function change(int $userId, array $entry, string $now): void
    {
        $this->db->transaction(function () use ($userId, $entry, $now): void {
            $this->db->execute('DELETE FROM {member_ranks} WHERE user_id = ?', [$userId]);
            if ($entry['to_rank_id'] !== null) {
                $this->db->insert('member_ranks', ['user_id' => $userId, 'rank_id' => $entry['to_rank_id'], 'assigned_at' => $now]);
            }
            $this->db->insert('rank_history', ['user_id' => $userId] + $entry + ['changed_at' => $now]);
        });
    }

    /** @return list<HistoryEntry> neueste zuerst */
    public function history(int $userId): array
    {
        return array_values(array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'user_id' => (int) $r['user_id'],
            'from_rank_id' => $r['from_rank_id'] === null ? null : (int) $r['from_rank_id'],
            'to_rank_id' => $r['to_rank_id'] === null ? null : (int) $r['to_rank_id'],
            'from_title' => (string) $r['from_title'],
            'to_title' => (string) $r['to_title'],
            'kind' => (string) $r['kind'],
            'points_at_change' => $r['points_at_change'] === null ? null : round((float) $r['points_at_change'], 2),
            'reason' => (string) $r['reason'],
            'changed_by' => (int) $r['changed_by'],
            'changed_at' => (string) $r['changed_at'],
        ], $this->db->select('SELECT * FROM {rank_history} WHERE user_id = ? ORDER BY changed_at DESC, id DESC', [$userId])));
    }

    /**
     * Alle Punktebuchungen (ohne stornierte) in der Reihenfolge, in der sie zählen: nach Datum, dann Eintrag.
     *
     * @return list<array{user_id: int, entry_date: string, value: float}>
     */
    public function pointEntries(?int $userId = null): array
    {
        $where = $userId === null ? '' : ' AND user_id = ' . $userId;
        return array_values(array_map(static fn (array $r): array => [
            'user_id' => (int) $r['user_id'],
            'entry_date' => (string) $r['entry_date'],
            'value' => round((float) $r['value'], 2),
        ], $this->db->select('SELECT user_id, entry_date, points * factor AS value FROM {points} WHERE deleted_at IS NULL' . $where . ' ORDER BY entry_date ASC, id ASC')));
    }

    /**
     * @param array<string, mixed> $r
     * @return Rank
     */
    private static function rank(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'title' => (string) $r['title'],
            'sort' => (int) $r['sort'],
            'min_points' => $r['min_points'] === null ? null : (int) $r['min_points'],
            'active' => (bool) $r['active'],
            'badge_file' => (string) ($r['badge_file'] ?? ''),
            'badge_ext' => (string) ($r['badge_ext'] ?? ''),
            'badge_version' => (string) ($r['badge_version'] ?? ''),
        ];
    }
}
