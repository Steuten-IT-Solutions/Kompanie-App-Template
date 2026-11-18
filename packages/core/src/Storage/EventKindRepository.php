<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Arten eines Termins des internen Kalenders (Domain\EventKindService): eine Zeile je Termin. event_key ist die stabile
 * Kennung des Termins in seiner Quelle (wie bei den Zu- und Absagen), Titel und Datum sind eine Momentaufnahme.
 *
 * Ein Termin (und eine Anwesenheitsliste) kann mehrere Arten haben. Gespeichert werden sie in zwei Spalten (columns(),
 * decode()): kinds hält alle mit Zeilenumbruch getrennt (Namen sind einzeilig), kind die erste. So liest eine ältere
 * Version nach dem Zurückrollen weiter die erste Art; hat sie kind danach geändert, passt kinds nicht mehr dazu und
 * zählt nur noch kind.
 */
final class EventKindRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Spalten kind und kinds für diese Arten ('' ohne Art).
     *
     * @param list<string> $kinds
     * @return array{kind: string, kinds: string}
     */
    public static function columns(array $kinds): array
    {
        return ['kind' => $kinds[0] ?? '', 'kinds' => implode("\n", $kinds)];
    }

    /**
     * Arten aus den Spalten kind und kinds: kinds, wenn seine erste Art kind ist, sonst nur kind (Zeilen von vor den
     * mehreren Arten oder nach dem Zurückrollen geändert).
     *
     * @return list<string>
     */
    public static function decode(mixed $kind, mixed $kinds): array
    {
        $first = is_scalar($kind) ? (string) $kind : '';
        if ($first === '') {
            return [];
        }
        $all = is_scalar($kinds) && (string) $kinds !== '' ? explode("\n", (string) $kinds) : [];
        if (($all[0] ?? null) !== $first) {
            return [$first];
        }
        return array_values(array_unique(array_filter($all, static fn (string $k): bool => $k !== '')));
    }

    /**
     * Arten dieser Termine, in einer Abfrage je 500 Termine.
     *
     * @param list<string> $keys
     * @return array<string, list<string>> event_key => Arten (nur Termine mit mindestens einer)
     */
    public function forKeys(array $keys): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($keys)), 500) as $chunk) {
            $marks = implode(', ', array_fill(0, count($chunk), '?'));
            foreach ($this->db->select("SELECT event_key, kind, kinds FROM {event_kinds} WHERE event_key IN ($marks)", $chunk) as $r) {
                $kinds = self::decode($r['kind'], $r['kinds']);
                if ($kinds !== []) {
                    $out[(string) $r['event_key']] = $kinds;
                }
            }
        }
        return $out;
    }

    /**
     * Arten setzen (anlegen oder ändern), mindestens eine.
     *
     * @param non-empty-list<string> $kinds
     */
    public function save(string $key, array $kinds, string $title, string $date, int $by, string $now): void
    {
        $values = self::columns($kinds) + ['event_title' => $title, 'event_date' => $date, 'updated_by' => $by, 'updated_at' => $now];
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
