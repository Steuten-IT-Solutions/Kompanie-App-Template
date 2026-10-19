<?php

declare(strict_types=1);

namespace KompanieApp\Standalone;

use KompanieApp\Core\Port\Scheduler;
use KompanieApp\Core\Port\Settings;
use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Wiederkehrende Aufgaben ohne Cronjob: Nach einer Anfrage prüft der Server, ob etwas fällig ist,
 * und erledigt es, nachdem die Antwort verschickt ist (siehe Kernel). Ein Cronjob des Hosters
 * (bin/kompanie.php cron oder die Cron-Adresse) macht es zuverlässiger, ist aber nicht nötig.
 *
 * Der nächste Fälligkeitszeitpunkt steht zusätzlich in den Einstellungen (NEXT_SETTING). Die liest
 * jede Anfrage ohnehin auf einmal; eine normale Anfrage braucht für den Pseudo-Cron deshalb keine
 * eigene Abfrage und schreibt nichts. Die Tabelle tasks wird nur gelesen, wenn etwas fällig ist.
 */
final class PseudoScheduler implements Scheduler
{
    /** Einstellung: ['at' => nächster Lauf (Unix-Zeit) oder null ohne geplante Aufgaben] */
    public const NEXT_SETTING = 'tasks_next_run';

    public function __construct(private readonly Connection $db, private readonly Settings $settings)
    {
    }

    public function schedule(string $task, int $intervalSeconds): void
    {
        $row = $this->db->selectOne('SELECT interval_seconds FROM {tasks} WHERE name = ?', [$task]);
        if (!$row) {
            $this->db->insert('tasks', ['name' => $task, 'interval_seconds' => $intervalSeconds, 'next_run' => time() + 60]);
            $this->refresh();
        } elseif ((int) $row['interval_seconds'] !== $intervalSeconds) {
            $this->db->update('tasks', ['interval_seconds' => $intervalSeconds], ['name' => $task]);
        }
    }

    public function unschedule(string $task): void
    {
        // Erst lesen: Ein DELETE ohne Treffer bräuchte in SQLite trotzdem die Schreibsperre.
        if ($this->db->scalar('SELECT COUNT(*) FROM {tasks} WHERE name = ?', [$task]) > 0) {
            $this->db->execute('DELETE FROM {tasks} WHERE name = ?', [$task]);
            $this->refresh();
        }
    }

    public function runSoon(string $task): void
    {
        $now = time();
        if ($this->db->execute('UPDATE {tasks} SET next_run = ? WHERE name = ? AND next_run > ?', [$now, $task, $now]) > 0) {
            $this->refresh();
        }
    }

    /** Ohne Datenbankabfrage: aus der Einstellung mit dem nächsten Lauf. */
    public function anyDue(): bool
    {
        $next = $this->nextRun();
        return $next !== null && $next <= time();
    }

    /** Nächster Lauf (Unix-Zeit) oder null, wenn keine Aufgabe geplant ist. */
    public function nextRun(): ?int
    {
        $stored = $this->settings->get(self::NEXT_SETTING);
        if (!is_array($stored) || !array_key_exists('at', $stored)) {
            return $this->refresh();
        }
        return is_numeric($stored['at']) ? (int) $stored['at'] : null;
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
        $this->refresh();
        return $claimed;
    }

    /**
     * Liest den nächsten Lauf aus der Tabelle und merkt ihn in den Einstellungen
     * (geschrieben wird nur, wenn er sich geändert hat).
     */
    public function refresh(): ?int
    {
        $min = $this->db->scalar('SELECT MIN(next_run) FROM {tasks}');
        $next = $min === null ? null : (int) $min;
        $stored = $this->settings->get(self::NEXT_SETTING);
        if (!is_array($stored) || !array_key_exists('at', $stored) || $stored['at'] !== $next) {
            $this->settings->set(self::NEXT_SETTING, ['at' => $next]);
        }
        return $next;
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
