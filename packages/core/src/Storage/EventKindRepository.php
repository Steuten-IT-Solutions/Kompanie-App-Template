<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Art eines Termins des internen Kalenders (Domain\EventKindService): eine Zeile je Termin. event_key ist die stabile
 * Kennung des Termins in seiner Quelle (wie bei den Zu- und Absagen), Titel und Datum sind eine Momentaufnahme.
 */
final class EventKindRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Arten dieser Termine, in einer Abfrage je 500 Termine.
     *
     * @param list<string> $keys
     * @return array<string, string> event_key => Art
     */
    public function forKeys(array $keys): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($keys)), 500) as $chunk) {
            $marks = implode(', ', array_fill(0, count($chunk), '?'));
            foreach ($this->db->select("SELECT event_key, kind FROM {event_kinds} WHERE event_key IN ($marks)", $chunk) as $r) {
                $out[(string) $r['event_key']] = (string) $r['kind'];
            }
        }
        return $out;
    }

    /** Art setzen (anlegen oder ändern). */
    public function save(string $key, string $kind, string $title, string $date, int $by, string $now): void
    {
        $values = ['kind' => $kind, 'event_title' => $title, 'event_date' => $date, 'updated_by' => $by, 'updated_at' => $now];
        $this->db->transaction(function () use ($key, $values): void {
            if ($this->db->update('event_kinds', $values, ['event_key' => $key]) === 0
                && $this->db->selectOne('SELECT id FROM {event_kinds} WHERE event_key = ?', [$key]) === null) {
                $this->db->insert('event_kinds', $values + ['event_key' => $key]);
            }
        });
    }

    public function delete(string $key): void
    {
        $this->db->execute('DELETE FROM {event_kinds} WHERE event_key = ?', [$key]);
    }

    /** ID der Zeile (für das Änderungsprotokoll), null ohne Art. */
    public function idOf(string $key): ?int
    {
        $id = $this->db->scalar('SELECT id FROM {event_kinds} WHERE event_key = ?', [$key]);
        return is_scalar($id) ? (int) $id : null;
    }
}
