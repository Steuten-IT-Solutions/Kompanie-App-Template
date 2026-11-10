<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Termine, die in der App gepflegt werden. Datum und Uhrzeit getrennt, in Ortszeit der Kompanie.
 * Tabelle events: öffentliche Termine; internal_events: der interne Kalender in der App (gleicher Aufbau, getrennt).
 */
final class EventRepository
{
    public const PUBLIC = 'events';
    public const INTERNAL = 'internal_events';

    public function __construct(private readonly Connection $db, private readonly string $table = self::PUBLIC)
    {
    }

    /** @return array<int, array<string, mixed>> kommende und heute laufende Termine */
    public function upcoming(string $today, int $limit): array
    {
        return $this->db->select(
            'SELECT * FROM {' . $this->table . '} WHERE COALESCE(end_date, start_date) >= ? ORDER BY start_date ASC, start_time ASC, id ASC LIMIT ?',
            [$today, $limit],
        );
    }

    /** @return array<int, array<string, mixed>> Termine, die zwischen $from und $to (Datum, je einschließlich) liegen oder laufen */
    public function between(string $from, string $to): array
    {
        return $this->db->select(
            'SELECT * FROM {' . $this->table . '} WHERE COALESCE(end_date, start_date) >= ? AND start_date <= ? ORDER BY start_date ASC, start_time ASC, id ASC',
            [$from, $to],
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM {' . $this->table . '} WHERE id = ?', [$id]);
    }

    /** @param array<string, mixed> $data */
    public function insert(array $data): int
    {
        return $this->db->insert($this->table, $data);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->db->update($this->table, $data, ['id' => $id]);
    }

    public function delete(int $id): bool
    {
        return $this->db->execute('DELETE FROM {' . $this->table . '} WHERE id = ?', [$id]) > 0;
    }
}
