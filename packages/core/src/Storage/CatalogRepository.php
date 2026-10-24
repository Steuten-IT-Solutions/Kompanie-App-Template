<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Storage\Sql\Connection;

/** Punktekatalog. */
final class CatalogRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(bool $onlyActive): array
    {
        $where = $onlyActive ? 'WHERE active = 1' : '';
        return $this->db->select("SELECT * FROM {point_catalog} $where ORDER BY sort ASC, title ASC");
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM {point_catalog} WHERE id = ?', [$id]);
    }

    public function insert(string $title, int $points, int $sort): int
    {
        return $this->db->insert('point_catalog', ['title' => $title, 'points' => $points, 'active' => 1, 'sort' => $sort]);
    }

    /** Größter sort-Wert (0 ohne Einträge): Neue Einträge kommen ans Ende. */
    public function maxSort(): int
    {
        return (int) ($this->db->scalar('SELECT MAX(sort) FROM {point_catalog}') ?? 0);
    }

    /**
     * Reihenfolge auf einmal setzen, in einer Transaktion (keine halbe Reihenfolge).
     *
     * @param array<int, int> $sortById ID => sort
     */
    public function reorder(array $sortById): void
    {
        $this->db->transaction(function () use ($sortById): void {
            foreach ($sortById as $id => $sort) {
                $this->db->update('point_catalog', ['sort' => $sort], ['id' => $id]);
            }
        });
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        if ($data !== []) {
            $this->db->update('point_catalog', $data, ['id' => $id]);
        }
    }
}
