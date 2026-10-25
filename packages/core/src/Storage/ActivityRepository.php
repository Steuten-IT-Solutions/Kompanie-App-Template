<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Nutzungsauswertung (Issue #175): Einträge je Person (activity, activity_last), zuletzt gesehen je Pseudonym und Bereich
 * (activity_seen) und Tageszahlen ohne Personenbezug (activity_daily). Zeiten als Unix-Zeit, Tage als JJJJ-MM-TT.
 *
 * Mehrere Zeilen werden mit einer Anweisung geschrieben (ON CONFLICT bzw. ON DUPLICATE KEY), damit das Erfassen bei jeder
 * Anfrage nur wenige Abfragen kostet.
 *
 * @phpstan-type ActivityEntry array{user_id: int, area: string, action: string, at: int}
 */
final class ActivityRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Zuletzt gesehen in diesen Bereichen.
     *
     * @param list<string> $areas
     * @return array<string, int> Bereich => Zeit
     */
    public function seen(string $token, array $areas): array
    {
        $rows = $this->db->select(
            'SELECT area, at FROM {activity_seen} WHERE token = ? AND area IN (' . implode(', ', array_fill(0, count($areas), '?')) . ')',
            [$token, ...$areas],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['area']] = (int) $r['at'];
        }
        return $out;
    }

    /** @param list<string> $areas zuletzt gesehen in diesen Bereichen auf $at setzen */
    public function touch(string $token, array $areas, int $at): void
    {
        $values = implode(', ', array_fill(0, count($areas), '(?, ?, ?)'));
        $params = [];
        foreach ($areas as $area) {
            array_push($params, $token, $area, $at);
        }
        $this->db->execute($this->db->driver() === 'mysql'
            ? 'INSERT INTO {activity_seen} (token, area, at) VALUES ' . $values . ' ON DUPLICATE KEY UPDATE at = VALUES(at)'
            : 'INSERT INTO {activity_seen} (token, area, at) VALUES ' . $values . ' ON CONFLICT(token, area) DO UPDATE SET at = excluded.at', $params);
    }

    /** @param array<string, array{0: int, 1: int}> $counts Bereich => [neue Personen, Aktionen] an diesem Tag dazuzählen */
    public function count(string $day, array $counts): void
    {
        $values = implode(', ', array_fill(0, count($counts), '(?, ?, ?, ?)'));
        $params = [];
        foreach ($counts as $area => [$users, $actions]) {
            array_push($params, $day, $area, $users, $actions);
        }
        $this->db->execute($this->db->driver() === 'mysql'
            ? 'INSERT INTO {activity_daily} (day, area, users, actions) VALUES ' . $values . ' ON DUPLICATE KEY UPDATE users = users + VALUES(users), actions = actions + VALUES(actions)'
            : 'INSERT INTO {activity_daily} (day, area, users, actions) VALUES ' . $values . ' ON CONFLICT(day, area) DO UPDATE SET users = users + excluded.users, actions = actions + excluded.actions', $params);
    }

    /** Eintrag je Person und „zuletzt aktiv“ (nur mit Erfassung je Person). */
    public function log(int $userId, string $area, string $action, int $at): void
    {
        $this->db->insert('activity', ['user_id' => $userId, 'area' => $area, 'action' => $action, 'at' => $at]);
        $this->db->execute($this->db->driver() === 'mysql'
            ? 'INSERT INTO {activity_last} (user_id, at) VALUES (?, ?) ON DUPLICATE KEY UPDATE at = GREATEST(at, VALUES(at))'
            : 'INSERT INTO {activity_last} (user_id, at) VALUES (?, ?) ON CONFLICT(user_id) DO UPDATE SET at = MAX(at, excluded.at)', [$userId, $at]);
    }

    /** Pseudonyme, die seit $since in diesem Bereich gesehen wurden. */
    public function activeSince(string $area, int $since): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM {activity_seen} WHERE area = ? AND at >= ?', [$area, $since]);
    }

    /** @return array<string, int> Bereich => Pseudonyme, die seit $since dort gesehen wurden (ohne „irgendein Bereich“) */
    public function areasSince(int $since, string $all): array
    {
        $out = [];
        foreach ($this->db->select('SELECT area, COUNT(*) AS n FROM {activity_seen} WHERE at >= ? AND area <> ? GROUP BY area ORDER BY area', [$since, $all]) as $r) {
            $out[(string) $r['area']] = (int) $r['n'];
        }
        return $out;
    }

    /** @return list<array{day: string, area: string, users: int, actions: int}> Tageszahlen von $from bis $to (einschließlich) */
    public function daily(string $from, string $to): array
    {
        return array_map(static fn (array $r): array => [
            'day' => (string) $r['day'],
            'area' => (string) $r['area'],
            'users' => (int) $r['users'],
            'actions' => (int) $r['actions'],
        ], $this->db->select('SELECT day, area, users, actions FROM {activity_daily} WHERE day >= ? AND day <= ? ORDER BY day, area', [$from, $to]));
    }

    /** @return array<int, int> Konto => zuletzt aktiv */
    public function lastActive(): array
    {
        $out = [];
        foreach ($this->db->select('SELECT user_id, at FROM {activity_last}') as $r) {
            $out[(int) $r['user_id']] = (int) $r['at'];
        }
        return $out;
    }

    /**
     * Einträge je Person von $from bis vor $to, neueste zuerst.
     *
     * @return list<ActivityEntry>
     */
    public function entries(int $from, int $to, ?int $userId, int $limit): array
    {
        $where = 'at >= ? AND at < ?' . ($userId !== null ? ' AND user_id = ?' : '');
        $params = $userId !== null ? [$from, $to, $userId] : [$from, $to];
        return array_map(static fn (array $r): array => [
            'user_id' => (int) $r['user_id'],
            'area' => (string) $r['area'],
            'action' => (string) $r['action'],
            'at' => (int) $r['at'],
        ], $this->db->select('SELECT user_id, area, action, at FROM {activity} WHERE ' . $where . ' ORDER BY at DESC, id DESC LIMIT ' . $limit, $params));
    }

    /** Einträge je Person vor $entriesBefore und Pseudonyme vor $seenBefore löschen. */
    public function prune(int $entriesBefore, int $seenBefore): void
    {
        $this->db->execute('DELETE FROM {activity} WHERE at < ?', [$entriesBefore]);
        $this->db->execute('DELETE FROM {activity_seen} WHERE at < ?', [$seenBefore]);
    }

    /** Alle Einträge je Person löschen (Erfassung je Person ausgeschaltet). */
    public function clearPersonal(): void
    {
        $this->db->execute('DELETE FROM {activity}');
        $this->db->execute('DELETE FROM {activity_last}');
    }

    /** Ein Konto wurde gelöscht: sein Pseudonym (activity und activity_last löscht UserData::purge). */
    public function forget(string $token): void
    {
        $this->db->execute('DELETE FROM {activity_seen} WHERE token = ?', [$token]);
    }
}
