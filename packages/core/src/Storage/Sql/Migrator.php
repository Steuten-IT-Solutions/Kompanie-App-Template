<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage\Sql;

use KompanieApp\Core\Port\Settings;

/**
 * Führt ausstehende Migrationen aus. Die erreichte Version steht in den Einstellungen.
 */
final class Migrator
{
    public const SETTING = 'schema_version';

    /**
     * @param array<int, array{mysql: string[], sqlite: string[]}>|null $migrations
     */
    public function __construct(
        private readonly Connection $db,
        private readonly Settings $settings,
        private readonly ?array $migrations = null,
        private readonly string $setting = self::SETTING,
    ) {
    }

    public function current(): int
    {
        return (int) $this->settings->get($this->setting, 0);
    }

    public function pending(): bool
    {
        $all = $this->migrations ?? Migrations::all();
        return $all !== [] && $this->current() < max(array_keys($all));
    }

    /** @return int[] ausgeführte Versionen */
    public function migrate(): array
    {
        $all = $this->migrations ?? Migrations::all();
        ksort($all);
        $done = [];
        foreach ($all as $version => $statements) {
            if ($version <= $this->current()) {
                continue;
            }
            foreach ($statements[$this->db->driver()] as $sql) {
                // Spalten nur ergänzen, wenn sie fehlen: Migrationen müssen wiederholbar sein,
                // auch wenn der Versionsstand verloren ging (Tabellen mit IF NOT EXISTS).
                if (preg_match('/^ALTER TABLE \{([a-z_]+)\} ADD COLUMN ([a-z_]+)\b/i', $sql, $m)
                    && in_array($m[2], Schema::columns($this->db, $m[1]), true)) {
                    continue;
                }
                $this->db->execute(str_replace('{options}', $this->db->tableOptions(), $sql));
            }
            $this->settings->set($this->setting, $version);
            $done[] = $version;
        }
        return $done;
    }
}
