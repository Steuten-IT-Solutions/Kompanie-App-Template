<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Inventar (Issue #6): Einträge (ein Ort je Eintrag) und ihr Verlauf. Mengen in Tausendsteln (milli) als ganze Zahl,
 * damit Summen und Vergleiche auf beiden Datenbanken exakt sind; gespeichert als Dezimalzahl mit drei Nachkommastellen.
 * Zeiten in UTC ('Y-m-d H:i:s').
 *
 * @phpstan-type InventoryItem array{id: int, title: string, category: string, quantity: int, unit: string, location: string,
 *     note: string, created_by: int, created_at: string, updated_by: int, updated_at: string, deleted_at: string|null}
 * @phpstan-type InventoryLogRow array{id: int, item_id: int, action: string, changes: array<string, mixed>, reason: string,
 *     title: string, unit: string, location: string, user_id: int, created_at: string}
 */
final class InventoryRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function db(): Connection
    {
        return $this->db;
    }

    /** @return list<InventoryItem> nicht gelöschte Einträge, nach Bezeichnung und Ort */
    public function active(): array
    {
        return array_values(array_map(self::item(...), $this->db->select(
            'SELECT * FROM {inventory_items} WHERE deleted_at IS NULL ORDER BY title ASC, location ASC, id ASC',
        )));
    }

    /**
     * Ein Eintrag, auch gelöschte. Mit $lock bei MySQL gesperrt bis zum Ende der Transaktion (SQLite sperrt die ganze
     * Datenbank schon mit BEGIN IMMEDIATE).
     *
     * @return InventoryItem|null
     */
    public function find(int $id, bool $lock = false): ?array
    {
        $sql = 'SELECT * FROM {inventory_items} WHERE id = ?' . ($lock && $this->db->driver() === 'mysql' ? ' FOR UPDATE' : '');
        $row = $this->db->selectOne($sql, [$id]);
        return $row === null ? null : self::item($row);
    }

    /** @param array<string, mixed> $row Spalten wie in der Tabelle, quantity in Tausendsteln */
    public function insert(array $row): int
    {
        if (isset($row['quantity'])) {
            $row['quantity'] = self::toDecimal((int) $row['quantity']);
        }
        return $this->db->insert('inventory_items', $row);
    }

    /** @param array<string, mixed> $data quantity in Tausendsteln */
    public function update(int $id, array $data): void
    {
        if (array_key_exists('quantity', $data)) {
            $data['quantity'] = self::toDecimal((int) $data['quantity']);
        }
        if ($data !== []) {
            $this->db->update('inventory_items', $data, ['id' => $id]);
        }
    }

    /** @param array<string, mixed> $changes */
    public function log(int $itemId, string $action, array $changes, string $reason, string $title, string $unit, string $location, int $userId, string $now): void
    {
        $this->db->insert('inventory_log', [
            'item_id' => $itemId,
            'action' => $action,
            'changes' => (string) json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
            'reason' => $reason,
            'title' => $title,
            'unit' => $unit,
            'location' => $location,
            'user_id' => $userId,
            'created_at' => $now,
        ]);
    }

    /**
     * Verlauf, neueste zuerst; mit $itemId nur der eines Eintrags, mit $before nur ältere als diese ID (Blättern).
     *
     * @return list<InventoryLogRow>
     */
    public function history(?int $itemId, int $limit, ?int $before = null): array
    {
        $where = [];
        $params = [];
        if ($itemId !== null) {
            $where[] = 'item_id = ?';
            $params[] = $itemId;
        }
        if ($before !== null) {
            $where[] = 'id < ?';
            $params[] = $before;
        }
        $sql = 'SELECT * FROM {inventory_log}' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY id DESC LIMIT ' . max(1, $limit);
        return array_values(array_map(self::logRow(...), $this->db->select($sql, $params)));
    }

    /** Tausendstel → Dezimalzahl als Text, z. B. 12500 → '12.500'. */
    public static function toDecimal(int $milli): string
    {
        $sign = $milli < 0 ? '-' : '';
        $abs = abs($milli);
        return $sign . intdiv($abs, 1000) . '.' . str_pad((string) ($abs % 1000), 3, '0', STR_PAD_LEFT);
    }

    /** Wert aus der Datenbank (MySQL: '12.500', SQLite: 12.5 oder 12) → Tausendstel. */
    public static function toMilli(mixed $value): int
    {
        if (is_string($value) && preg_match('/^(-?)(\d+)(?:\.(\d{1,3})\d*)?$/', trim($value), $m)) {
            $milli = (int) $m[2] * 1000 + (int) str_pad($m[3] ?? '', 3, '0');
            return $m[1] === '-' ? -$milli : $milli;
        }
        return (int) round((float) $value * 1000);
    }

    /**
     * @param array<string, mixed> $r
     * @return InventoryItem
     */
    private static function item(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'title' => (string) $r['title'],
            'category' => (string) $r['category'],
            'quantity' => self::toMilli($r['quantity']),
            'unit' => (string) $r['unit'],
            'location' => (string) $r['location'],
            'note' => (string) $r['note'],
            'created_by' => (int) $r['created_by'],
            'created_at' => (string) $r['created_at'],
            'updated_by' => (int) $r['updated_by'],
            'updated_at' => (string) $r['updated_at'],
            'deleted_at' => $r['deleted_at'] === null ? null : (string) $r['deleted_at'],
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @return InventoryLogRow
     */
    private static function logRow(array $r): array
    {
        $changes = json_decode((string) $r['changes'], true);
        return [
            'id' => (int) $r['id'],
            'item_id' => (int) $r['item_id'],
            'action' => (string) $r['action'],
            'changes' => is_array($changes) ? $changes : [],
            'reason' => (string) $r['reason'],
            'title' => (string) $r['title'],
            'unit' => (string) $r['unit'],
            'location' => (string) $r['location'],
            'user_id' => (int) $r['user_id'],
            'created_at' => (string) $r['created_at'],
        ];
    }
}
