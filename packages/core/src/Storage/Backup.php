<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Port\FileStorage;
use KompanieApp\Core\Storage\Sql\Connection;
use KompanieApp\Core\Storage\Sql\Schema;

/**
 * Datensicherung im gemeinsamen Export-Format (JSON), für beide Datenbanken und beide Adapter.
 *
 *   {format: "kompanie-app-backup", formatVersion: 1, created, variant, version,
 *    schema: {name: version}, tables: {tabelle: [zeilen]}, files: {pfad: base64}, settings: {name: wert}}
 *
 * Konten: in der eigenständigen Version als Tabelle users, im WordPress-Plugin als accounts (WordPress-Benutzer, s. WpBackup).
 *
 * Wiederherstellen ersetzt den Inhalt der Tabellen. Spalten, die die Datenbank nicht kennt
 * (Sicherung einer neueren Version), werden ausgelassen; fehlende bekommen ihren Standardwert.
 * Galeriebilder sind nicht enthalten; sie werden nach dem Wiederherstellen neu aus Drive erzeugt.
 * Die Dateien der Dokumente und die Abzeichen der Dienstgrade stehen unter files (Pfad in der geschützten Dateiablage,
 * Inhalt als Base64; nur Dateien, auf die eine Zeile verweist). Die Fotos der Mitglieder liegen in der Datenbank
 * (member_photos) und sind deshalb enthalten.
 * Die Push-Abos (push_subscriptions) und der Schlüssel dazu (Einstellung push_vapid) sind enthalten, damit Benachrichtigungen
 * nach einem Umzug weiter ankommen; die Warteschlange (push_queue) nicht.
 * Nicht enthalten sind wartende Fehlermeldungen (bug_reports, Issue #64): Sie gehören zur Installation, und Meldungen mit
 * Bildschirmfoto und ggf. Namen sollen nicht in Sicherungsdateien landen.
 */
final class Backup
{
    public const FORMAT = 'kompanie-app-backup';
    public const FORMAT_VERSION = 1;
    /**
     * Einstellungen, die zur Installation gehören, nicht zu den Daten: Sie werden weder gesichert noch
     * wiederhergestellt (Schema-Stand, Update-Zustand mit der laufenden Nummer der Liste, übersprungene
     * Version, Einrichtung abgeschlossen, Zeitpunkt der letzten Sicherung, Version der Aufgabenplanung,
     * Installations-ID und Geheimnis der Statusmeldungen samt ihrem Zustand, Fehlerzähler, Fehlerprotokoll).
     */
    public const LOCAL_SETTINGS = [
        'schema_version', 'update_state', 'update_last', 'update_skip', 'setup_done', 'backup_last', 'running_version', 'tasks_planned', 'roles_seq',
        'tasks_last_run', 'installation_id', 'installation_secret', 'telemetry_registered', 'telemetry_last', 'telemetry_heartbeat', 'telemetry_checks',
        'telemetry_errors', 'telemetry_details', 'telemetry_pending', 'telemetry_events', 'error_log',
        // Fehlermeldungen (Issue #64): Bestätigung durch den Empfänger. Die Warteschlange (bug_reports) ist keine Kerntabelle der Sicherung.
        'bug_reports_registered',
        // Zustand der Sicherungen in Google Drive (BackupUploadService::STATE_SETTING): letzter Lauf, Fehler.
        'backup_drive_state',
        // Web Push: Stand der Abgleiche gehört zu dieser Installation (Schlüssel und Abos sind in der Sicherung).
        'push_calendar_seen', 'push_news_seen', 'push_reminder_date', 'push_board_offered', 'push_polls_offered', 'push_birthdays_offered',
    ];
    /** Tabellen des Kerns, in einer Reihenfolge, die beim Einfügen passt. */
    public const CORE_TABLES = ['point_catalog', 'points', 'penalties', 'events', 'albums', 'photos', 'ranks', 'member_ranks', 'rank_history', 'titles', 'member_titles', 'member_roles', 'attendance_lists', 'attendance_entries', 'doc_categories', 'documents', 'board_messages', 'polls', 'poll_options', 'poll_votes', 'poll_answers', 'birthdays', 'join_years', 'inventory_items', 'inventory_log', 'member_photos', 'office_groups', 'offices', 'office_terms', 'office_holders', 'former_members', 'lists', 'list_columns', 'list_rows', 'push_subscriptions'];
    /**
     * Tabellen, die beim Wiederherstellen nur geleert werden, wenn die Sicherung sie enthält: Push-Abos waren früher nicht
     * in Sicherungen; eine ältere Sicherung soll die Geräte nicht abmelden (der Schlüssel bleibt dann ebenfalls).
     */
    public const KEEP_IF_MISSING = ['push_subscriptions'];
    /** Ordner der geschützten Dateiablage mit Dateien, die zur Sicherung gehören: Dokumente und Abzeichen der Dienstgrade. */
    public const FILE_DIRS = ['dokumente', 'abzeichen'];

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
                if (!in_array($t, self::KEEP_IF_MISSING, true) || isset($tables[$t])) {
                    $this->db->execute('DELETE FROM {' . $t . '}');
                }
            }
            // Die Warteschlange verweist auf die IDs der Abos.
            if (isset($tables['push_subscriptions']) && in_array('push_subscriptions', $allowed, true)) {
                $this->db->execute('DELETE FROM {push_queue}');
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
                    $row = array_intersect_key(self::olderRow($t, $row), $columns);
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

    /**
     * Dateien der Dokumente und Abzeichen, auf die eine Zeile der Sicherung verweist.
     *
     * @param array<string, array<int, array<string, mixed>>> $tables aus dump()
     * @return array<string, string> Pfad => Inhalt als Base64
     */
    public static function dumpFiles(FileStorage $files, array $tables): array
    {
        $wanted = [
            'dokumente' => array_map('strval', array_column($tables['documents'] ?? [], 'file')),
            'abzeichen' => array_values(array_filter(array_map('strval', array_column($tables['ranks'] ?? [], 'badge_file')))),
        ];
        $out = [];
        foreach (self::FILE_DIRS as $dir) {
            $base = dirname($files->path($dir . '/x', false));
            foreach (glob($base . '/*') ?: [] as $file) {
                $name = basename($file);
                $referenced = $dir === 'dokumente'
                    ? in_array($name, $wanted['dokumente'], true)
                    : array_filter($wanted['abzeichen'], static fn (string $b): bool => str_starts_with($name, $b . '-') || str_starts_with($name, $b . '.')) !== [];
                if (!$referenced || !is_file($file) || !self::validFileName($name)) {
                    continue;
                }
                $data = file_get_contents($file);
                if ($data === false) {
                    throw new \RuntimeException('Die Datei ' . $dir . '/' . $name . ' ließ sich nicht lesen.');
                }
                $out[$dir . '/' . $name] = base64_encode($data);
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * Prüft die Dateien einer Sicherung (vor dem Wiederherstellen der Tabellen, damit eine beschädigte Sicherung nichts ändert).
     *
     * @return array<string, string> Pfad => Inhalt
     */
    public static function parseFiles(mixed $files): array
    {
        if ($files === null) {
            return [];
        }
        $broken = new \RuntimeException('Die Sicherung ist beschädigt (Dateien).');
        if (!is_array($files)) {
            throw $broken;
        }
        $out = [];
        foreach ($files as $path => $data) {
            $parts = explode('/', (string) $path);
            if (count($parts) !== 2 || !in_array($parts[0], self::FILE_DIRS, true) || !self::validFileName($parts[1]) || !is_string($data)) {
                throw $broken;
            }
            $bytes = base64_decode($data, true);
            if ($bytes === false) {
                throw $broken;
            }
            $out[$parts[0] . '/' . $parts[1]] = $bytes;
        }
        return $out;
    }

    /**
     * Schreibt die Dateien aus parseFiles() in die geschützte Dateiablage (vorhandene gleichen Namens werden ersetzt).
     *
     * @param array<string, string> $files
     */
    public static function restoreFiles(FileStorage $storage, array $files): void
    {
        foreach ($files as $path => $bytes) {
            $target = $storage->path($path, false);
            $tmp = $target . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($tmp, $bytes) !== strlen($bytes) || !@rename($tmp, $target)) {
                @unlink($tmp);
                throw new \RuntimeException('Die Daten sind wiederhergestellt, aber die Datei ' . $path . ' ließ sich nicht speichern (Speicherplatz, Schreibrechte?). Bitte die Sicherung erneut einspielen.');
            }
        }
    }

    /** Dateiname ohne Pfad, wie ihn die App vergibt (keine versteckten Dateien, kein index.php des Ordnerschutzes). */
    private static function validFileName(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,150}$/', $name) === 1 && !str_starts_with($name, 'index.') && !str_ends_with($name, '.tmp');
    }

    /**
     * Zeile einer älteren Sicherung, der eine später hinzugekommene Spalte fehlt, deren Wert sich aus der Zeile ergibt
     * (sonst gilt die Vorgabe der Spalte): Amtszeiten ohne honor (vor Issue #180) waren als einzelnes Jahr eine Ehrung.
     *
     * @param array<mixed> $row
     * @return array<mixed>
     */
    private static function olderRow(string $table, array $row): array
    {
        if ($table === 'office_terms' && !array_key_exists('honor', $row)) {
            $row['honor'] = ($row['kind'] ?? '') === 'point' ? 1 : 0;
        }
        return $row;
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
