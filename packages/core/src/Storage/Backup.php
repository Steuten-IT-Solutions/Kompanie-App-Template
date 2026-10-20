<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Storage\Sql\Connection;
use KompanieApp\Core\Storage\Sql\Schema;

/**
 * Datensicherung im gemeinsamen Export-Format (JSON), für beide Datenbanken und beide Adapter.
 *
 *   {format: "kompanie-app-backup", formatVersion: 1, created, variant, version,
 *    schema: {name: version}, tables: {tabelle: [zeilen]}, settings: {name: wert}}
 *
 * Wiederherstellen ersetzt den Inhalt der Tabellen. Spalten, die die Datenbank nicht kennt
 * (Sicherung einer neueren Version), werden ausgelassen; fehlende bekommen ihren Standardwert.
 * Galeriebilder sind nicht enthalten; sie werden nach dem Wiederherstellen neu aus Drive erzeugt.
 */
final class Backup
{
    public const FORMAT = 'kompanie-app-backup';
    public const FORMAT_VERSION = 1;
    /**
     * Einstellungen, die zur Installation gehören, nicht zu den Daten: Sie werden weder gesichert noch
     * wiederhergestellt (Schema-Stand, Update-Zustand mit der laufenden Nummer der Liste, übersprungene
     * Version, Einrichtung abgeschlossen, Zeitpunkt der letzten Sicherung, Version der Aufgabenplanung,
     * Installations-ID und Geheimnis der Statusmeldungen samt ihrem Zustand, Fehlerzähler).
     */
    public const LOCAL_SETTINGS = [
        'schema_version', 'update_state', 'update_last', 'update_skip', 'setup_done', 'backup_last', 'running_version', 'tasks_planned', 'roles_seq',
        'tasks_last_run', 'installation_id', 'installation_secret', 'telemetry_registered', 'telemetry_last', 'telemetry_heartbeat', 'telemetry_checks',
        'telemetry_errors', 'telemetry_pending', 'telemetry_events',
        // Web Push: Schlüssel und Stand der Abgleiche gehören zu dieser Installation (Abos sind nicht in Sicherungen).
        'push_vapid', 'push_calendar_seen', 'push_news_seen', 'push_reminder_date',
    ];
    /** Tabellen des Kerns, in einer Reihenfolge, die beim Einfügen passt. */
    public const CORE_TABLES = ['point_catalog', 'points', 'penalties', 'events', 'albums', 'photos', 'ranks', 'member_ranks', 'rank_history', 'titles', 'member_titles', 'attendance_lists', 'attendance_entries', 'doc_categories', 'documents'];

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param string[] $tables
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function dump(array $tables): array
    {
        $out = [];
        foreach ($tables as $t) {
            $out[$t] = $this->db->select('SELECT * FROM {' . $t . '} ORDER BY 1');
        }
        return $out;
    }

    /**
     * Ersetzt den Inhalt der Tabellen in einer Transaktion.
     *
     * @param array<string, mixed> $tables aus dump()
     * @param string[] $allowed Tabellen, die wiederhergestellt werden dürfen
     */
    public function restore(array $tables, array $allowed): void
    {
        $this->db->transaction(function () use ($tables, $allowed): void {
            // Auch Tabellen leeren, die eine ältere Sicherung noch nicht kannte (z. B. Dienstgrade): Zurückspielen ersetzt
            // alle Daten durch den Stand der Sicherung. In der eigenständigen Version gehörten die Zeilen sonst womöglich
            // anderen Personen (gleiche IDs, andere Konten).
            foreach (array_reverse($allowed) as $t) {
                $this->db->execute('DELETE FROM {' . $t . '}');
            }
            foreach ($allowed as $t) {
                if (!isset($tables[$t]) || !is_array($tables[$t])) {
                    continue;
                }
                $columns = array_flip($this->columns($t));
                foreach ($tables[$t] as $row) {
                    if (!is_array($row)) {
                        throw new \RuntimeException('Die Sicherung ist beschädigt (Tabelle ' . $t . ').');
                    }
                    $row = array_intersect_key($row, $columns);
                    foreach ($row as $v) {
                        if (!is_scalar($v) && $v !== null) {
                            throw new \RuntimeException('Die Sicherung ist beschädigt (Tabelle ' . $t . ').');
                        }
                    }
                    if ($row !== []) {
                        $this->db->insert($t, $row);
                    }
                }
            }
        });
    }

    /** @return string[] Spaltennamen einer Tabelle */
    public function columns(string $table): array
    {
        return Schema::columns($this->db, $table);
    }

    /**
     * Prüft Aufbau und Herkunft einer Sicherung.
     *
     * @return array<string, mixed>
     */
    public static function parse(string $json): array
    {
        $data = json_decode($json, true, 64);
        if (!is_array($data) || ($data['format'] ?? '') !== self::FORMAT) {
            throw new \RuntimeException('Das ist keine Datensicherung der Kompanie-App.');
        }
        if ((int) ($data['formatVersion'] ?? 0) > self::FORMAT_VERSION) {
            throw new \RuntimeException('Die Sicherung stammt aus einer neueren Version. Bitte erst die App aktualisieren.');
        }
        if (!is_array($data['tables'] ?? null)) {
            throw new \RuntimeException('Die Sicherung enthält keine Daten.');
        }
        return $data;
    }
}
