<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Titel (Issue #22): die Titelliste und die zugewiesenen Titel je Mitglied (mehrere möglich).
 *
 * @phpstan-type Title array{id: int, title: string, sort: int, active: bool}
 */
final class TitleRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return list<Title> nach Reihenfolge */
    public function all(bool $onlyActive): array
    {
        $where = $onlyActive ? 'WHERE active = 1' : '';
        return array_values(array_map(self::title(...), $this->db->select("SELECT * FROM {titles} $where ORDER BY sort ASC, id ASC")));
    }

    /** @return Title|null */
    public function find(int $id): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM {titles} WHERE id = ?', [$id]);
        return $row === null ? null : self::title($row);
    }

    public function insert(string $title, int $sort): int
    {
        return $this->db->insert('titles', ['title' => $title, 'sort' => $sort, 'active' => 1]);
    }

    /** @param array{title?: string, sort?: int, active?: bool} $data */
    public function update(int $id, array $data): void
    {
        if (isset($data['active'])) {
            $data['active'] = $data['active'] ? 1 : 0;
        }
        if ($data !== []) {
            $this->db->update('titles', $data, ['id' => $id]);
        }
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM {titles} WHERE id = ?', [$id]);
    }

    public function maxSort(): int
    {
        return (int) $this->db->scalar('SELECT COALESCE(MAX(sort), 0) FROM {titles}');
    }

    /** @return array<int, int> Titel-ID => wie vielen Mitgliedern zugewiesen */
    public function assignedCounts(): array
    {
        $out = [];
        foreach ($this->db->select('SELECT title_id, COUNT(*) AS n FROM {member_titles} GROUP BY title_id') as $r) {
            $out[(int) $r['title_id']] = (int) $r['n'];
        }
        return $out;
    }

    public function assignedCount(int $titleId): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM {member_titles} WHERE title_id = ?', [$titleId]);
    }

    /** @return list<int> */
    public function titleIdsOf(int $userId): array
    {
        return array_values(array_map(static fn (array $r): int => (int) $r['title_id'], $this->db->select('SELECT title_id FROM {member_titles} WHERE user_id = ?', [$userId])));
    }

    /** @return array<int, list<int>> Benutzer-ID => Titel-IDs, in einer Abfrage für alle */
    public function assignments(): array
    {
        $out = [];
        foreach ($this->db->select('SELECT user_id, title_id FROM {member_titles}') as $r) {
            $out[(int) $r['user_id']][] = (int) $r['title_id'];
        }
        return $out;
    }

    /**
     * Titel eines Mitglieds ersetzen, in einer Transaktion. Bestehende Zuweisungen behalten ihr Datum.
     *
     * @param list<int> $titleIds
     */
    public function replace(int $userId, array $titleIds, string $now): void
    {
        $this->db->transaction(function () use ($userId, $titleIds, $now): void {
            $current = $this->titleIdsOf($userId);
            foreach (array_diff($current, $titleIds) as $id) {
                $this->db->execute('DELETE FROM {member_titles} WHERE user_id = ? AND title_id = ?', [$userId, $id]);
            }
            foreach (array_diff($titleIds, $current) as $id) {
                $this->db->insert('member_titles', ['user_id' => $userId, 'title_id' => $id, 'assigned_at' => $now]);
            }
        });
    }

    /**
     * @param array<string, mixed> $r
     * @return Title
     */
    private static function title(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'title' => (string) $r['title'],
            'sort' => (int) $r['sort'],
            'active' => (bool) $r['active'],
        ];
    }
}
