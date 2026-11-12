<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Änderungsprotokoll (Domain\ChangeLogService): eine Zeile je angelegtem, geändertem oder gelöschtem Eintrag. changes ist
 * JSON, eine Liste von {f: Feld, o?: alter Wert, n?: neuer Wert, p?: 1 (persönliche Angabe), s?: 1 (geheim, ohne Werte)}.
 *
 * @phpstan-type ChangeRow array{id: int, at: int, actor_id: int, actor_name: string, area: string, type: string, item: string, label: string, action: string, subject_user_id: int|null, personal: bool, changes: list<array<string, mixed>>}
 */
final class ChangeLogRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param array{at: int, actor_id: int, actor_name: string, area: string, type: string, item: string, label: string, action: string, subject_user_id: int|null, personal: bool, changes: list<array<string, mixed>>} $row
     */
    public function insert(array $row): int
    {
        return $this->db->insert('change_log', [
            'at' => $row['at'],
            'actor_id' => $row['actor_id'],
            'actor_name' => mb_substr($row['actor_name'], 0, 191),
            'area' => $row['area'],
            'type' => mb_substr($row['type'], 0, 40),
            'item' => mb_substr($row['item'], 0, 64),
            'label' => mb_substr($row['label'], 0, 191),
            'action' => $row['action'],
            'subject_user_id' => $row['subject_user_id'],
            'personal' => $row['personal'] ? 1 : 0,
            'changes' => self::encode($row['changes']),
        ]);
    }

    /**
     * Einträge von $from bis vor $to, neueste zuerst, höchstens $limit.
     *
     * @return list<ChangeRow>
     */
    public function entries(int $from, int $to, ?int $actorId, ?int $subjectId, ?string $area, int $limit): array
    {
        $where = ['at >= ?', 'at < ?'];
        $params = [$from, $to];
        if ($actorId !== null) {
            $where[] = 'actor_id = ?';
            $params[] = $actorId;
        }
        if ($subjectId !== null) {
            $where[] = 'subject_user_id = ?';
            $params[] = $subjectId;
        }
        if ($area !== null) {
            $where[] = 'area = ?';
            $params[] = $area;
        }
        return array_map(
            self::row(...),
            $this->db->select('SELECT * FROM {change_log} WHERE ' . implode(' AND ', $where) . ' ORDER BY at DESC, id DESC LIMIT ' . $limit, $params),
        );
    }

    /**
     * Einträge über eine Person (subject_user_id) oder von ihr (actor_id), älteste zuerst.
     *
     * @return list<ChangeRow>
     */
    public function of(string $column, int $userId): array
    {
        $column = $column === 'actor_id' ? 'actor_id' : 'subject_user_id';
        return array_map(self::row(...), $this->db->select('SELECT * FROM {change_log} WHERE ' . $column . ' = ? ORDER BY at, id', [$userId]));
    }

    /** @return list<array{area: string, n: int}> Bereiche mit Einträgen (für die Auswahl in der Ansicht) */
    public function areas(): array
    {
        return array_map(
            static fn (array $r): array => ['area' => (string) $r['area'], 'n' => (int) $r['n']],
            $this->db->select('SELECT area, COUNT(*) AS n FROM {change_log} GROUP BY area ORDER BY area'),
        );
    }

    /** @param list<array<string, mixed>> $changes */
    public function replaceChanges(int $id, array $changes, bool $personal): void
    {
        $this->db->update('change_log', ['changes' => self::encode($changes), 'personal' => $personal ? 1 : 0], ['id' => $id]);
    }

    public function prune(int $before): int
    {
        return $this->db->execute('DELETE FROM {change_log} WHERE at < ?', [$before]);
    }

    public function clear(): void
    {
        $this->db->execute('DELETE FROM {change_log}');
    }

    /** @param list<array<string, mixed>> $changes */
    private static function encode(array $changes): string
    {
        return (string) json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * @param array<string, mixed> $r
     * @return ChangeRow
     */
    private static function row(array $r): array
    {
        $changes = json_decode((string) $r['changes'], true);
        return [
            'id' => (int) $r['id'],
            'at' => (int) $r['at'],
            'actor_id' => (int) $r['actor_id'],
            'actor_name' => (string) $r['actor_name'],
            'area' => (string) $r['area'],
            'type' => (string) $r['type'],
            'item' => (string) $r['item'],
            'label' => (string) $r['label'],
            'action' => (string) $r['action'],
            'subject_user_id' => $r['subject_user_id'] === null ? null : (int) $r['subject_user_id'],
            'personal' => (bool) (int) $r['personal'],
            'changes' => is_array($changes) ? array_values(array_filter($changes, 'is_array')) : [],
        ];
    }
}
