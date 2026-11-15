<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Zu- und Absagen bei Terminen des internen Kalenders (Domain\EventResponseService): eine Zeile je Person und Termin.
 * event_key ist die stabile Kennung des Termins in seiner Quelle (bei iCal response_key, sonst die Kennung des Termins).
 *
 * @phpstan-type EventResponse array{event_key: string, user_id: int, status: string, note: string, event_title: string,
 *     event_date: string, updated_by: int, created_at: string, updated_at: string}
 */
final class EventResponseRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Alle Rückmeldungen zu diesen Terminen, in einer Abfrage je 500 Termine.
     *
     * @param list<string> $keys
     * @return array<string, list<EventResponse>> event_key => Rückmeldungen
     */
    public function forEvents(array $keys): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($keys)), 500) as $chunk) {
            $marks = implode(', ', array_fill(0, count($chunk), '?'));
            foreach ($this->db->select("SELECT * FROM {event_responses} WHERE event_key IN ($marks) ORDER BY id", $chunk) as $r) {
                $row = self::row($r);
                $out[$row['event_key']][] = $row;
            }
        }
        return $out;
    }

    /**
     * Von diesen Terminen die, zu denen die Person geantwortet hat.
     *
     * @param list<string> $keys
     * @return list<string>
     */
    public function keysOfUser(int $userId, array $keys): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($keys)), 500) as $chunk) {
            $marks = implode(', ', array_fill(0, count($chunk), '?'));
            foreach ($this->db->select("SELECT event_key FROM {event_responses} WHERE user_id = ? AND event_key IN ($marks)", [$userId, ...$chunk]) as $r) {
                $out[] = (string) $r['event_key'];
            }
        }
        return $out;
    }

    /** @return EventResponse|null */
    public function find(string $key, int $userId): ?array
    {
        $r = $this->db->selectOne('SELECT * FROM {event_responses} WHERE event_key = ? AND user_id = ?', [$key, $userId]);
        return $r === null ? null : self::row($r);
    }

    /** Rückmeldung setzen (anlegen oder ändern). */
    public function save(string $key, int $userId, string $status, string $note, string $title, string $date, int $by, string $now): void
    {
        $values = ['status' => $status, 'note' => $note, 'event_title' => $title, 'event_date' => $date, 'updated_by' => $by, 'updated_at' => $now];
        $this->db->transaction(function () use ($key, $userId, $values, $now): void {
            if ($this->db->update('event_responses', $values, ['event_key' => $key, 'user_id' => $userId]) === 0
                && $this->find($key, $userId) === null) {
                $this->db->insert('event_responses', $values + ['event_key' => $key, 'user_id' => $userId, 'created_at' => $now]);
            }
        });
    }

    public function delete(string $key, int $userId): bool
    {
        return $this->db->execute('DELETE FROM {event_responses} WHERE event_key = ? AND user_id = ?', [$key, $userId]) > 0;
    }

    /** Rückmeldungen zu Terminen vor $date löschen (Aufräumen); Anzahl gelöschter Zeilen. */
    public function deleteBefore(string $date): int
    {
        return $this->db->execute('DELETE FROM {event_responses} WHERE event_date < ?', [$date]);
    }

    /**
     * Eigene Rückmeldungen einer Person (Auskunft nach DSGVO), neueste Termine zuerst.
     *
     * @return list<EventResponse>
     */
    public function ofUser(int $userId): array
    {
        return array_values(array_map(self::row(...), $this->db->select('SELECT * FROM {event_responses} WHERE user_id = ? ORDER BY event_date DESC, id DESC', [$userId])));
    }

    /**
     * @param array<string, mixed> $r
     * @return EventResponse
     */
    private static function row(array $r): array
    {
        return [
            'event_key' => (string) $r['event_key'],
            'user_id' => (int) $r['user_id'],
            'status' => (string) $r['status'],
            'note' => (string) $r['note'],
            'event_title' => (string) $r['event_title'],
            'event_date' => (string) $r['event_date'],
            'updated_by' => (int) $r['updated_by'],
            'created_at' => (string) $r['created_at'],
            'updated_at' => (string) $r['updated_at'],
        ];
    }
}
