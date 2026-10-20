<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Anwesenheit (Issue #21): Listen je Termin und der Status je Person. Jede Methode kommt mit einer festen Zahl von
 * Abfragen aus, unabhängig von der Zahl der Personen.
 *
 * @phpstan-type AttendanceList array{id: int, source: string, event_key: string, title: string, event_date: string,
 *     created_by: int, created_at: string, updated_by: int, updated_at: string}
 */
final class AttendanceRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return AttendanceList|null */
    public function find(int $id): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM {attendance_lists} WHERE id = ?', [$id]);
        return $row === null ? null : self::list($row);
    }

    /** @return AttendanceList|null */
    public function findByEvent(string $source, string $eventKey): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM {attendance_lists} WHERE source = ? AND event_key = ?', [$source, $eventKey]);
        return $row === null ? null : self::list($row);
    }

    /** @return list<AttendanceList> neueste Termine zuerst */
    public function recent(int $limit): array
    {
        return array_values(array_map(self::list(...), $this->db->select('SELECT * FROM {attendance_lists} ORDER BY event_date DESC, id DESC LIMIT ' . max(1, $limit))));
    }

    /** @return array<string, int> Termin-ID im Kalender => Liste */
    public function calendarListIds(): array
    {
        $out = [];
        foreach ($this->db->select("SELECT id, event_key FROM {attendance_lists} WHERE source = 'calendar'") as $r) {
            $out[(string) $r['event_key']] = (int) $r['id'];
        }
        return $out;
    }

    public function insert(string $source, string $eventKey, string $title, string $date, int $by, string $now): int
    {
        return $this->db->insert('attendance_lists', [
            'source' => $source,
            'event_key' => $eventKey,
            'title' => $title,
            'event_date' => $date,
            'created_by' => $by,
            'created_at' => $now,
            'updated_by' => $by,
            'updated_at' => $now,
        ]);
    }

    /** @param array{title?: string, event_date?: string} $data */
    public function touch(int $id, array $data, int $by, string $now): void
    {
        $this->db->update('attendance_lists', $data + ['updated_by' => $by, 'updated_at' => $now], ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->db->transaction(function () use ($id): void {
            $this->db->execute('DELETE FROM {attendance_entries} WHERE list_id = ?', [$id]);
            $this->db->execute('DELETE FROM {attendance_lists} WHERE id = ?', [$id]);
        });
    }

    /** @return array<int, array{status: string, updated_by: int}> Benutzer-ID => Eintrag */
    public function entries(int $listId): array
    {
        $out = [];
        foreach ($this->db->select('SELECT user_id, status, updated_by FROM {attendance_entries} WHERE list_id = ?', [$listId]) as $r) {
            $out[(int) $r['user_id']] = ['status' => (string) $r['status'], 'updated_by' => (int) $r['updated_by']];
        }
        return $out;
    }

    /**
     * Status setzen (null = Eintrag entfernen). Unveränderte Einträge behalten, wer sie eingetragen hat.
     *
     * @param array<int, string|null> $changes Benutzer-ID => Status
     */
    public function setEntries(int $listId, array $changes, int $by, string $now): void
    {
        $current = $this->entries($listId);
        foreach ($changes as $userId => $status) {
            if ($status === null) {
                if (isset($current[$userId])) {
                    $this->db->execute('DELETE FROM {attendance_entries} WHERE list_id = ? AND user_id = ?', [$listId, $userId]);
                }
            } elseif (!isset($current[$userId])) {
                $this->db->insert('attendance_entries', ['list_id' => $listId, 'user_id' => $userId, 'status' => $status, 'updated_by' => $by, 'updated_at' => $now]);
            } elseif ($current[$userId]['status'] !== $status) {
                $this->db->update('attendance_entries', ['status' => $status, 'updated_by' => $by, 'updated_at' => $now], ['list_id' => $listId, 'user_id' => $userId]);
            }
        }
    }

    /**
     * Anzahl je Status für mehrere Listen in einer Abfrage.
     *
     * @param list<int> $listIds
     * @return array<int, array<string, int>> Liste => Status => Anzahl
     */
    public function counts(array $listIds): array
    {
        if ($listIds === []) {
            return [];
        }
        $marks = implode(', ', array_fill(0, count($listIds), '?'));
        $out = [];
        foreach ($this->db->select("SELECT list_id, status, COUNT(*) AS n FROM {attendance_entries} WHERE list_id IN ($marks) GROUP BY list_id, status", $listIds) as $r) {
            $out[(int) $r['list_id']][(string) $r['status']] = (int) $r['n'];
        }
        return $out;
    }

    /** @return list<int> wer für diese Liste schon Punkte hat (ohne stornierte) */
    public function bookedUserIds(int $listId): array
    {
        return array_values(array_map(
            static fn (array $r): int => (int) $r['user_id'],
            $this->db->select('SELECT DISTINCT user_id FROM {points} WHERE attendance_id = ? AND deleted_at IS NULL', [$listId]),
        ));
    }

    /** @return list<array{list_id: int, title: string, event_date: string, status: string}> neueste zuerst */
    public function forUser(int $userId): array
    {
        return array_values(array_map(static fn (array $r): array => [
            'list_id' => (int) $r['list_id'],
            'title' => (string) $r['title'],
            'event_date' => (string) $r['event_date'],
            'status' => (string) $r['status'],
        ], $this->db->select(
            'SELECT e.list_id, l.title, l.event_date, e.status FROM {attendance_entries} e JOIN {attendance_lists} l ON l.id = e.list_id'
            . ' WHERE e.user_id = ? ORDER BY l.event_date DESC, l.id DESC',
            [$userId],
        )));
    }

    public function deleteForUser(int $userId): void
    {
        $this->db->execute('DELETE FROM {attendance_entries} WHERE user_id = ?', [$userId]);
    }

    /**
     * @param array<string, mixed> $r
     * @return AttendanceList
     */
    private static function list(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'source' => (string) $r['source'],
            'event_key' => (string) $r['event_key'],
            'title' => (string) $r['title'],
            'event_date' => substr((string) $r['event_date'], 0, 10),
            'created_by' => (int) $r['created_by'],
            'created_at' => (string) $r['created_at'],
            'updated_by' => (int) $r['updated_by'],
            'updated_at' => (string) $r['updated_at'],
        ];
    }
}
