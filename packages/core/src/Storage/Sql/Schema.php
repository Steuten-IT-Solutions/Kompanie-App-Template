<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage\Sql;

/** Auskunft über vorhandene Tabellen und Spalten, für MySQL/MariaDB und SQLite. */
final class Schema
{
    /** @return string[] Spaltennamen; leer, wenn es die Tabelle nicht gibt */
    public static function columns(Connection $db, string $table): array
    {
        $name = $db->table($table);
        if ($db->driver() === 'sqlite') {
            return array_map(static fn (array $r): string => (string) $r['name'], $db->select('PRAGMA table_info(' . $name . ')'));
        }
        return array_map(
            static fn (array $r): string => (string) $r['COLUMN_NAME'],
            $db->select('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = LOWER(?) ORDER BY ORDINAL_POSITION', [$name]),
        );
    }
}
