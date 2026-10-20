<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Nachrichten am Schwarzen Brett (Issue #5). Zeiten in UTC ('Y-m-d H:i:s'); eine Nachricht gilt bis expires_at.
 *
 * @phpstan-type BoardMessage array{id: int, body: string, author_id: int, created_at: string, updated_at: string, expires_at: string}
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
            'SELECT * FROM {board_messages} WHERE expires_at > ? ORDER BY created_at DESC, id DESC',
            [$now],
        )));
    }

    /** @return BoardMessage|null nur, solange sie gilt */
    public function findActive(int $id, string $now): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM {board_messages} WHERE id = ? AND expires_at > ?', [$id, $now]);
        return $row === null ? null : self::message($row);
    }

    public function insert(string $body, int $authorId, string $now, string $expires): int
    {
        return $this->db->insert('board_messages', ['body' => $body, 'author_id' => $authorId, 'created_at' => $now, 'updated_at' => $now, 'expires_at' => $expires]);
    }

    public function update(int $id, string $body, string $now): void
    {
        $this->db->update('board_messages', ['body' => $body, 'updated_at' => $now], ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM {board_messages} WHERE id = ?', [$id]);
    }

    /** Abgelaufene löschen (kein Verlauf); gibt die Zahl zurück. */
    public function deleteExpired(string $now): int
    {
        return $this->db->execute('DELETE FROM {board_messages} WHERE expires_at <= ?', [$now]);
    }

    /**
     * @param array<string, mixed> $r
     * @return BoardMessage
     */
    private static function message(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'body' => (string) $r['body'],
            'author_id' => (int) $r['author_id'],
            'created_at' => (string) $r['created_at'],
            'updated_at' => (string) $r['updated_at'],
            'expires_at' => (string) $r['expires_at'],
        ];
    }
}
