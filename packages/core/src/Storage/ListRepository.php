<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Listen (Issue #121): Listen mit ihren Spalten und Zeilen. Spalten und Zeilen werden immer als Ganzes ersetzt.
 *
 * @phpstan-type ListRow array{id: int, title: string, subtitle: string, list_date: string|null, orientation: string, people: string, created_by: int, created_at: string, updated_by: int, updated_at: string}
 * @phpstan-type Column array{kind: string, field: string|null, label: string, width: string, checkbox: bool}
 * @phpstan-type Row array{kind: string, user_id: int|null, name: string}
 */
final class ListRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return list<ListRow> zuletzt geänderte zuerst */
    public function all(): array
    {
        return array_values(array_map(self::list(...), $this->db->select('SELECT * FROM {lists} ORDER BY updated_at DESC, id DESC')));
    }

    /** @return ListRow|null */
    public function find(int $id): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM {lists} WHERE id = ?', [$id]);
        return $row === null ? null : self::list($row);
    }

    /** @param array<string, mixed> $data */
    public function insert(array $data): int
    {
        return $this->db->insert('lists', $data);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        if ($data !== []) {
            $this->db->update('lists', $data, ['id' => $id]);
        }
    }

    public function delete(int $id): void
    {
        $this->db->transaction(function () use ($id): void {
            $this->db->execute('DELETE FROM {list_rows} WHERE list_id = ?', [$id]);
            $this->db->execute('DELETE FROM {list_columns} WHERE list_id = ?', [$id]);
            $this->db->execute('DELETE FROM {lists} WHERE id = ?', [$id]);
        });
    }

    /** @return list<Column> nach Reihenfolge */
    public function columns(int $listId): array
    {
        return array_values(array_map(self::column(...), $this->db->select('SELECT * FROM {list_columns} WHERE list_id = ? ORDER BY sort ASC, id ASC', [$listId])));
    }

    /** @return list<Row> nach Reihenfolge */
    public function rows(int $listId): array
    {
        return array_values(array_map(self::row(...), $this->db->select('SELECT * FROM {list_rows} WHERE list_id = ? ORDER BY sort ASC, id ASC', [$listId])));
    }

    /** @return array<int, int> Listen-ID => Zahl der Zeilen, in einer Abfrage für alle */
    public function rowCounts(): array
    {
        $out = [];
        foreach ($this->db->select('SELECT list_id, COUNT(*) AS n FROM {list_rows} GROUP BY list_id') as $r) {
            $out[(int) $r['list_id']] = (int) $r['n'];
        }
        return $out;
    }

    /** @param list<Column> $columns */
    public function replaceColumns(int $listId, array $columns): void
    {
        $this->db->execute('DELETE FROM {list_columns} WHERE list_id = ?', [$listId]);
        foreach (array_values($columns) as $i => $c) {
            $this->db->insert('list_columns', [
                'list_id' => $listId,
                'sort' => ($i + 1) * 10,
                'kind' => $c['kind'],
                'field' => $c['field'],
                'label' => $c['label'],
                'width' => $c['width'],
                'checkbox' => $c['checkbox'] ? 1 : 0,
            ]);
        }
    }

    /** @param list<Row> $rows */
    public function replaceRows(int $listId, array $rows): void
    {
        $this->db->execute('DELETE FROM {list_rows} WHERE list_id = ?', [$listId]);
        foreach (array_values($rows) as $i => $r) {
            $this->db->insert('list_rows', [
                'list_id' => $listId,
                'sort' => ($i + 1) * 10,
                'kind' => $r['kind'],
                'user_id' => $r['user_id'],
                'name' => $r['name'],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $r
     * @return ListRow
     */
    private static function list(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'title' => (string) $r['title'],
            'subtitle' => (string) $r['subtitle'],
            'list_date' => $r['list_date'] === null || $r['list_date'] === '' ? null : substr((string) $r['list_date'], 0, 10),
            'orientation' => (string) $r['orientation'],
            'people' => (string) ($r['people'] ?? ''),
            'created_by' => (int) $r['created_by'],
            'created_at' => (string) $r['created_at'],
            'updated_by' => (int) $r['updated_by'],
            'updated_at' => (string) $r['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @return Column
     */
    private static function column(array $r): array
    {
        return [
            'kind' => (string) $r['kind'],
            'field' => $r['field'] === null ? null : (string) $r['field'],
            'label' => (string) $r['label'],
            'width' => (string) $r['width'],
            'checkbox' => (bool) $r['checkbox'],
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @return Row
     */
    private static function row(array $r): array
    {
        return [
            'kind' => (string) $r['kind'],
            'user_id' => $r['user_id'] === null ? null : (int) $r['user_id'],
            'name' => (string) $r['name'],
        ];
    }
}
