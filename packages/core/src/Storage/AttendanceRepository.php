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
            // Die Punkte bleiben; ihre Verbindung zur Liste nicht. Sonst erbte eine neue Liste mit derselben ID
            // (MySQL kann IDs nach einem Neustart wieder vergeben) die Markierung „Punkte gebucht“.
            $this->db->execute('UPDATE {points} SET attendance_id = NULL WHERE attendance_id = ?', [$id]);
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
     * Gesammelt geschrieben (Issue #18): ein DELETE, ein INSERT und je Status ein UPDATE, nicht eine Abfrage je Person.
     *
     * @param array<int, string|null> $changes Benutzer-ID => Status
     */
    public function setEntries(int $listId, array $changes, int $by, string $now): void
    {
        $current = $this->entries($listId);
        $delete = [];
        $insert = [];
        $update = [];
        foreach ($changes as $userId => $status) {
            if ($status === null) {
                if (isset($current[$userId])) {
                    $delete[] = $userId;
                }
            } elseif (!isset($current[$userId])) {
                $insert[] = [$userId, $status];
            } elseif ($current[$userId]['status'] !== $status) {
                $update[$status][] = $userId;
            }
        }
        foreach (array_chunk($delete, 200) as $chunk) {
            $this->db->execute('DELETE FROM {attendance_entries} WHERE list_id = ? AND user_id IN (' . self::marks($chunk) . ')', [$listId, ...$chunk]);
        }
        foreach ($update as $status => $ids) {
            foreach (array_chunk($ids, 200) as $chunk) {
                $this->db->execute(
                    'UPDATE {attendance_entries} SET status = ?, updated_by = ?, updated_at = ? WHERE list_id = ? AND user_id IN (' . self::marks($chunk) . ')',
                    [$status, $by, $now, $listId, ...$chunk],
                );
            }
        }
        foreach (array_chunk($insert, 200) as $chunk) {
            $params = [];
            foreach ($chunk as [$userId, $status]) {
                array_push($params, $listId, $userId, $status, $by, $now);
            }
            $this->db->execute(
                'INSERT INTO {attendance_entries} (list_id, user_id, status, updated_by, updated_at) VALUES ' . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?)')),
                $params,
            );
        }
    }

    /** @param list<mixed> $values */
    private static function marks(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    /**
     * Anzahl je Status für mehrere Listen in einer Abfrage, nur für die genannten Personen (wer heute in den Listen steht).
     *
     * @param list<int> $listIds
     * @param array<int, mixed> $people Benutzer-ID => beliebig
     * @return array<int, array<string, int>> Liste => Status => Anzahl
     */
    public function counts(array $listIds, array $people): array
    {
        if ($listIds === []) {
            return [];
        }
        $marks = implode(', ', array_fill(0, count($listIds), '?'));
        $out = [];
        foreach ($this->db->select("SELECT list_id, user_id, status FROM {attendance_entries} WHERE list_id IN ($marks)", $listIds) as $r) {
            if (isset($people[(int) $r['user_id']])) {
                $l = (int) $r['list_id'];
                $s = (string) $r['status'];
                $out[$l][$s] = ($out[$l][$s] ?? 0) + 1;
            }
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

    /** @return list<array{list_id: int, title: string, event_date: string, status: string, updated_by: int}> neueste zuerst */
    public function forUser(int $userId): array
    {
        return array_values(array_map(static fn (array $r): array => [
            'list_id' => (int) $r['list_id'],
            'title' => (string) $r['title'],
            'event_date' => (string) $r['event_date'],
            'status' => (string) $r['status'],
            'updated_by' => (int) $r['updated_by'],
        ], $this->db->select(
            'SELECT e.list_id, l.title, l.event_date, e.status, e.updated_by FROM {attendance_entries} e JOIN {attendance_lists} l ON l.id = e.list_id'
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
