<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Abfragen für den Bericht (Domain\ReportService): Einträge eines Zeitraums über alle Mitglieder, je Bereich eine
 * Abfrage. Zeiträume als 'Y-m-d' (Datum der Kompanie), beide Grenzen eingeschlossen, oder für Zeitpunkte als UTC
 * 'Y-m-d H:i:s' mit ausgeschlossenem Ende; stornierte Einträge fehlen.
 */
final class ReportRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Punkte im Zeitraum, älteste zuerst.
     *
     * @return list<array{user_id: int, points: int, factor: float, reason: string, entry_date: string}>
     */
    public function points(string $from, string $to): array
    {
        return array_values(array_map(static fn (array $r): array => [
            'user_id' => (int) $r['user_id'],
            'points' => (int) $r['points'],
            'factor' => (float) ($r['factor'] ?? 1),
            'reason' => (string) $r['reason'],
            'entry_date' => (string) $r['entry_date'],
        ], $this->db->select(
            'SELECT user_id, points, factor, reason, entry_date FROM {points} WHERE deleted_at IS NULL AND entry_date >= ? AND entry_date <= ? ORDER BY entry_date, id',
            [$from, $to],
        )));
    }

    /**
     * Strafen im Zeitraum (nach dem Datum der Strafe), älteste zuerst.
     *
     * @return list<array{user_id: int, amount_cents: int, reason: string, entry_date: string, status: string, paid_at: string|null}>
     */
    public function penalties(string $from, string $to): array
    {
        return array_values(array_map(static fn (array $r): array => [
            'user_id' => (int) $r['user_id'],
            'amount_cents' => (int) $r['amount_cents'],
            'reason' => (string) $r['reason'],
            'entry_date' => (string) $r['entry_date'],
            'status' => (string) $r['status'],
            'paid_at' => $r['paid_at'] !== null ? (string) $r['paid_at'] : null,
        ], $this->db->select(
            'SELECT user_id, amount_cents, reason, entry_date, status, paid_at FROM {penalties} WHERE deleted_at IS NULL AND entry_date >= ? AND entry_date <= ? ORDER BY entry_date, id',
            [$from, $to],
        )));
    }

    /**
     * Anwesenheitslisten im Zeitraum mit der Zahl der Einträge je Status, älteste zuerst.
     *
     * @return list<array{id: int, title: string, event_date: string, counts: array<string, int>}>
     */
    public function attendanceLists(string $from, string $to): array
    {
        $lists = [];
        foreach ($this->db->select('SELECT id, title, event_date FROM {attendance_lists} WHERE event_date >= ? AND event_date <= ? ORDER BY event_date, id', [$from, $to]) as $r) {
            $lists[(int) $r['id']] = ['id' => (int) $r['id'], 'title' => (string) $r['title'], 'event_date' => (string) $r['event_date'], 'counts' => []];
        }
        if ($lists === []) {
            return [];
        }
        foreach ($this->db->select(
            'SELECT e.list_id, e.status, COUNT(*) AS n FROM {attendance_entries} e JOIN {attendance_lists} l ON l.id = e.list_id'
            . ' WHERE l.event_date >= ? AND l.event_date <= ? GROUP BY e.list_id, e.status',
            [$from, $to],
        ) as $r) {
            $id = (int) $r['list_id'];
            if (isset($lists[$id])) {
                $lists[$id]['counts'][(string) $r['status']] = (int) $r['n'];
            }
        }
        return array_values($lists);
    }

    /**
     * Anwesenheit je Person im Zeitraum: Zahl der Einträge je Status.
     *
     * @return array<int, array<string, int>> Benutzer-ID => Status => Anzahl
     */
    public function attendanceByUser(string $from, string $to): array
    {
        $out = [];
        foreach ($this->db->select(
            'SELECT e.user_id, e.status, COUNT(*) AS n FROM {attendance_entries} e JOIN {attendance_lists} l ON l.id = e.list_id'
            . ' WHERE l.event_date >= ? AND l.event_date <= ? GROUP BY e.user_id, e.status',
            [$from, $to],
        ) as $r) {
            $out[(int) $r['user_id']][(string) $r['status']] = (int) $r['n'];
        }
        return $out;
    }

    /**
     * Dienstgrad-Wechsel im Zeitraum, älteste zuerst. Grenzen als UTC-Zeitpunkte 'Y-m-d H:i:s', $to ausgeschlossen.
     *
     * @return list<array{user_id: int, from_title: string, to_title: string, kind: string, reason: string, changed_at: string}>
     */
    public function rankChanges(string $fromUtc, string $toUtc): array
    {
        return array_values(array_map(static fn (array $r): array => [
            'user_id' => (int) $r['user_id'],
            'from_title' => (string) $r['from_title'],
            'to_title' => (string) $r['to_title'],
            'kind' => (string) $r['kind'],
            'reason' => (string) $r['reason'],
            'changed_at' => (string) $r['changed_at'],
        ], $this->db->select(
            'SELECT user_id, from_title, to_title, kind, reason, changed_at FROM {rank_history} WHERE changed_at >= ? AND changed_at < ? ORDER BY changed_at, id',
            [$fromUtc, $toUtc],
        )));
    }

    /**
     * Vergebene Titel im Zeitraum (wer ihn noch trägt), älteste zuerst. Grenzen wie bei rankChanges().
     *
     * @return list<array{user_id: int, title: string, assigned_at: string}>
     */
    public function titleAssignments(string $fromUtc, string $toUtc): array
    {
        return array_values(array_map(static fn (array $r): array => [
            'user_id' => (int) $r['user_id'],
            'title' => (string) $r['title'],
            'assigned_at' => (string) $r['assigned_at'],
        ], $this->db->select(
            'SELECT m.user_id, t.title, m.assigned_at FROM {member_titles} m JOIN {titles} t ON t.id = m.title_id'
            . ' WHERE m.assigned_at >= ? AND m.assigned_at < ? ORDER BY m.assigned_at, t.sort, m.user_id',
            [$fromUtc, $toUtc],
        )));
    }

    /**
     * Zu- und Absagen für Termine im Zeitraum (Tag des Termins), nach Termin.
     *
     * @return list<array{event_key: string, event_title: string, event_date: string, user_id: int, status: string}>
     */
    public function responses(string $from, string $to): array
    {
        return array_values(array_map(static fn (array $r): array => [
            'event_key' => (string) $r['event_key'],
            'event_title' => (string) $r['event_title'],
            'event_date' => (string) $r['event_date'],
            'user_id' => (int) $r['user_id'],
            'status' => (string) $r['status'],
        ], $this->db->select(
            'SELECT event_key, event_title, event_date, user_id, status FROM {event_responses} WHERE event_date >= ? AND event_date <= ? ORDER BY event_date, event_title, event_key, id',
            [$from, $to],
        )));
    }

    /**
     * Verlauf des Inventars im Zeitraum, älteste zuerst. Grenzen wie bei rankChanges().
     *
     * @return list<array{action: string, reason: string, title: string, location: string, user_id: int, created_at: string}>
     */
    public function inventoryLog(string $fromUtc, string $toUtc): array
    {
        return array_values(array_map(static fn (array $r): array => [
            'action' => (string) $r['action'],
            'reason' => (string) $r['reason'],
            'title' => (string) $r['title'],
            'location' => (string) $r['location'],
            'user_id' => (int) $r['user_id'],
            'created_at' => (string) $r['created_at'],
        ], $this->db->select(
            'SELECT action, reason, title, location, user_id, created_at FROM {inventory_log} WHERE created_at >= ? AND created_at < ? ORDER BY created_at, id',
            [$fromUtc, $toUtc],
        )));
    }
}
