<?php

declare(strict_types=1);

namespace KompanieApp\Standalone;

use KompanieApp\Core\Port\Scheduler;
use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Wiederkehrende Aufgaben ohne Cronjob: Nach einer Anfrage prüft der Server, ob etwas fällig ist,
 * und erledigt es, nachdem die Antwort verschickt ist (siehe Kernel). Ein Cronjob des Hosters
 * (bin/kompanie.php cron oder die Cron-Adresse) macht es zuverlässiger, ist aber nicht nötig.
 */
final class PseudoScheduler implements Scheduler
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function schedule(string $task, int $intervalSeconds): void
    {
        $row = $this->db->selectOne('SELECT interval_seconds FROM {tasks} WHERE name = ?', [$task]);
        if (!$row) {
            $this->db->insert('tasks', ['name' => $task, 'interval_seconds' => $intervalSeconds, 'next_run' => time() + 60]);
        } elseif ((int) $row['interval_seconds'] !== $intervalSeconds) {
            $this->db->update('tasks', ['interval_seconds' => $intervalSeconds], ['name' => $task]);
        }
    }

    public function unschedule(string $task): void
    {
        $this->db->execute('DELETE FROM {tasks} WHERE name = ?', [$task]);
    }

    public function anyDue(): bool
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM {tasks} WHERE next_run <= ?', [time()]) > 0;
    }

    /**
     * Reserviert die fälligen Aufgaben und gibt ihre Namen zurück. Laufen zwei Anfragen
     * gleichzeitig, bekommt jede Aufgabe nur eine davon.
     *
     * @return string[]
     */
    public function claimDue(): array
    {
        $now = time();
        $claimed = [];
        foreach ($this->db->select('SELECT name, interval_seconds, next_run FROM {tasks} WHERE next_run <= ?', [$now]) as $t) {
            $n = $this->db->execute(
                'UPDATE {tasks} SET next_run = ? WHERE name = ? AND next_run = ?',
                [$now + (int) $t['interval_seconds'], $t['name'], (int) $t['next_run']],
            );
            if ($n === 1) {
                $claimed[] = (string) $t['name'];
            }
        }
        return $claimed;
    }

    /** @return array<string, int> Aufgabe => nächster Lauf (Unix-Zeit) */
    public function overview(): array
    {
        $out = [];
        foreach ($this->db->select('SELECT name, next_run FROM {tasks} ORDER BY name') as $t) {
            $out[(string) $t['name']] = (int) $t['next_run'];
        }
        return $out;
    }
}
