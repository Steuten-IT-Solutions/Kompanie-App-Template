<?php

declare(strict_types=1);

namespace KompanieApp\Standalone;

use KompanieApp\Core\Port\Settings;
use KompanieApp\Core\Storage\Sql\Connection;

/** Einstellungen in der Tabelle settings (JSON-kodiert), mit Zwischenspeicher für die Anfrage. */
final class SqlSettings implements Settings
{
    /** @var array<string, mixed>|null */
    private ?array $all = null;

    public function __construct(private readonly Connection $db)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->all ??= $this->load();
        return array_key_exists($key, $this->all) ? $this->all[$key] : $default;
    }

    public function fresh(string $key, mixed $default = null): mixed
    {
        $raw = $this->db->scalar('SELECT value FROM {settings} WHERE name = ?', [$key]);
        $this->all ??= $this->load();
        if ($raw === null || $raw === false) {
            unset($this->all[$key]);
            return $default;
        }
        return $this->all[$key] = json_decode((string) $raw, true);
    }

    public function set(string $key, mixed $value): void
    {
        $json = (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->db->transaction(function () use ($key, $json): void {
            if ($this->db->update('settings', ['value' => $json], ['name' => $key]) === 0
                && $this->db->scalar('SELECT COUNT(*) FROM {settings} WHERE name = ?', [$key]) == 0) {
                $this->db->insert('settings', ['name' => $key, 'value' => $json]);
            }
        });
        $this->all ??= $this->load();
        $this->all[$key] = $value;
    }

    public function delete(string $key): void
    {
        $this->db->execute('DELETE FROM {settings} WHERE name = ?', [$key]);
        unset($this->all[$key]);
    }

    /** @return array<string, mixed> */
    private function load(): array
    {
        try {
            $rows = $this->db->select('SELECT name, value FROM {settings}');
        } catch (\PDOException) {
            return []; // vor der ersten Migration gibt es die Tabelle noch nicht
        }
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['name']] = json_decode((string) $r['value'], true);
        }
        return $out;
    }
}
