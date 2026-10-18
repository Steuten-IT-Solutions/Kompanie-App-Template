<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Storage\Sql\Connection;

/** Öffentliche Termine. Datum und Uhrzeit getrennt, in Ortszeit der Kompanie. */
final class EventRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<int, array<string, mixed>> kommende und heute laufende Termine */
    public function upcoming(string $today, int $limit): array
    {
        return $this->db->select(
            'SELECT * FROM {events} WHERE COALESCE(end_date, start_date) >= ? ORDER BY start_date ASC, start_time ASC, id ASC LIMIT ?',
            [$today, $limit],
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM {events} WHERE id = ?', [$id]);
    }

    /** @param array<string, mixed> $data */
    public function insert(array $data): int
    {
        return $this->db->insert('events', $data);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->db->update('events', $data, ['id' => $id]);
    }

    public function delete(int $id): bool
    {
        return $this->db->execute('DELETE FROM {events} WHERE id = ?', [$id]) > 0;
    }
}
