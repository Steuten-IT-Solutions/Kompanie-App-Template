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

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        if ($data !== []) {
            $this->db->update('point_catalog', $data, ['id' => $id]);
        }
    }
}
