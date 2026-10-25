<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Nachrichten am Schwarzen Brett (Issue #5, #172). Zeiten in UTC ('Y-m-d H:i:s'); eine Nachricht gilt bis expires_at.
 * Vorzeitig gelöschte Nachrichten bleiben mit deleted_at/deleted_by stehen (expires_at = Zeitpunkt des Löschens), bis
 * BoardService::cleanup sie nach der Aufbewahrung des Verlaufs endgültig entfernt. body ist bereinigter formatierter
 * Inhalt (RichText), title reiner Text (NULL nur bei Nachrichten, die eine ältere Version geschrieben hat).
 *
 * @phpstan-type BoardMessage array{id: int, title: string, body: string, author_id: int, created_at: string, updated_at: string, expires_at: string, deleted_at: string|null, deleted_by: int|null}
 * @phpstan-type BoardRevision array{id: int, message_id: int, user_id: int, created_at: string, action: string, before_value: string|null, after_value: string|null}
 */
final class BoardRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return list<BoardMessage> noch gültige Nachrichten, neueste zuerst */
    public function active(string $now): array
    {
        return array_values(array_map(self::message(...), $this->db->select(
            'SELECT * FROM {board_messages} WHERE expires_at > ? AND deleted_at IS NULL ORDER BY created_at DESC, id DESC',
            [$now],
        )));
    }

    /** @return BoardMessage|null nur, solange sie gilt */
    public function findActive(int $id, string $now): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM {board_messages} WHERE id = ? AND expires_at > ? AND deleted_at IS NULL', [$id, $now]);
        return $row === null ? null : self::message($row);
    }

    /** @return BoardMessage|null auch abgelaufen oder gelöscht (bis zum endgültigen Entfernen) */
    public function find(int $id): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM {board_messages} WHERE id = ?', [$id]);
        return $row === null ? null : self::message($row);
    }

    /** @return list<BoardMessage> abgelaufene und gelöschte Nachrichten mit Ende nach $since, zuletzt beendete zuerst */
    public function ended(string $now, string $since): array
    {
        return array_values(array_map(self::message(...), $this->db->select(
            'SELECT * FROM {board_messages} WHERE expires_at <= ? AND expires_at > ? ORDER BY expires_at DESC, id DESC',
            [$now, $since],
        )));
    }

    public function insert(string $title, string $body, int $authorId, string $now, string $expires): int
    {
        return $this->db->insert('board_messages', ['title' => $title, 'body' => $body, 'author_id' => $authorId, 'created_at' => $now, 'updated_at' => $now, 'expires_at' => $expires]);
    }

    /** @param array<string, string> $values title, body, updated_at, expires_at */
    public function update(int $id, array $values): void
    {
        if ($values !== []) {
            $this->db->update('board_messages', $values, ['id' => $id]);
        }
    }

    /** Vorzeitig löschen: bleibt für den Verlauf stehen, gilt aber ab sofort nicht mehr (auch für ältere Versionen). */
    public function markDeleted(int $id, string $now, int $userId): void
    {
        $this->db->update('board_messages', ['deleted_at' => $now, 'deleted_by' => $userId, 'expires_at' => $now], ['id' => $id]);
    }

    /** @return list<int> Nachrichten, die vor $before endeten (abgelaufen oder gelöscht) */
    public function endedBefore(string $before): array
    {
        return array_map(static fn (array $r): int => (int) $r['id'], $this->db->select('SELECT id FROM {board_messages} WHERE expires_at <= ?', [$before]));
    }

    /** @param list<int> $ids */
    public function deleteMany(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $this->db->execute('DELETE FROM {board_message_revisions} WHERE message_id IN (' . $in . ')', $ids);
        $this->db->execute('DELETE FROM {board_mails} WHERE message_id IN (' . $in . ')', $ids);
        return $this->db->execute('DELETE FROM {board_messages} WHERE id IN (' . $in . ')', $ids);
    }

    /**
     * Verlaufseinträge ohne Nachricht (z. B. von einer älteren Version gelöscht), älter als $before; gibt die
     * Nachrichten zurück, deren Einträge entfernt wurden (für ihre Bilder).
     *
     * @return list<int>
     */
    public function deleteOrphanRevisions(string $before): array
    {
        $ids = array_map(static fn (array $r): int => (int) $r['message_id'], $this->db->select(
            'SELECT DISTINCT r.message_id FROM {board_message_revisions} r LEFT JOIN {board_messages} m ON m.id = r.message_id
             WHERE m.id IS NULL GROUP BY r.message_id HAVING MAX(r.created_at) < ?',
            [$before],
        ));
        if ($ids !== []) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $this->db->execute('DELETE FROM {board_message_revisions} WHERE message_id IN (' . $in . ')', $ids);
            $this->db->execute('DELETE FROM {board_mails} WHERE message_id IN (' . $in . ')', $ids);
        }
        return $ids;
    }

    public function addRevision(int $messageId, int $userId, string $now, string $action, ?string $before, ?string $after): void
    {
        $this->db->insert('board_message_revisions', ['message_id' => $messageId, 'user_id' => $userId, 'created_at' => $now, 'action' => $action, 'before_value' => $before, 'after_value' => $after]);
    }

    /**
     * @param list<int> $messageIds
     * @return array<int, list<BoardRevision>> Nachricht => Einträge, älteste zuerst
     */
    public function revisions(array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }
        $out = [];
        $rows = $this->db->select(
            'SELECT * FROM {board_message_revisions} WHERE message_id IN (' . implode(',', array_fill(0, count($messageIds), '?')) . ') ORDER BY created_at, id',
            $messageIds,
        );
        foreach ($rows as $r) {
            $out[(int) $r['message_id']][] = [
                'id' => (int) $r['id'],
                'message_id' => (int) $r['message_id'],
                'user_id' => (int) $r['user_id'],
                'created_at' => (string) $r['created_at'],
                'action' => (string) $r['action'],
                'before_value' => $r['before_value'] === null ? null : (string) $r['before_value'],
                'after_value' => $r['after_value'] === null ? null : (string) $r['after_value'],
            ];
        }
        return $out;
    }

    /**
     * Letzte Änderung (nicht Erstellung, nicht Mailversand) je Nachricht.
     *
     * @param list<int> $messageIds
     * @return array<int, array{user_id: int, created_at: string}>
     */
    public function lastEdits(array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }
        $out = [];
        $rows = $this->db->select(
            "SELECT message_id, user_id, created_at FROM {board_message_revisions}
             WHERE action IN ('title', 'content', 'expires') AND message_id IN (" . implode(',', array_fill(0, count($messageIds), '?')) . ')
             ORDER BY created_at, id',
            $messageIds,
        );
        foreach ($rows as $r) {
            $out[(int) $r['message_id']] = ['user_id' => (int) $r['user_id'], 'created_at' => (string) $r['created_at']];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $r
     * @return BoardMessage
     */
    private static function message(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'title' => (string) ($r['title'] ?? ''),
            'body' => (string) $r['body'],
            'author_id' => (int) $r['author_id'],
            'created_at' => (string) $r['created_at'],
            'updated_at' => (string) $r['updated_at'],
            'expires_at' => (string) $r['expires_at'],
            'deleted_at' => isset($r['deleted_at']) && $r['deleted_at'] !== '' ? (string) $r['deleted_at'] : null,
            'deleted_by' => isset($r['deleted_by']) ? (int) $r['deleted_by'] : null,
        ];
    }
}
