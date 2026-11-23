<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Zugänge für KI-Assistenten (Domain\AccessTokenService): je Zeile ein Zugang einer Person mit den Hashes des
 * Zugriffstokens und des Tokens zum Erneuern. Zeiten als Unix-Zeit.
 *
 * @phpstan-type AccessTokenRow array{id: int, user_id: int, client: string, scope: string, expires_at: int, refresh_expires_at: int, created_at: int, last_used_at: int|null}
 */
final class AccessTokenRepository
{
    private const COLUMNS = 'id, user_id, client, scope, expires_at, refresh_expires_at, created_at, last_used_at';

    public function __construct(private readonly Connection $db)
    {
    }

    public function create(int $userId, string $client, string $scope, string $tokenHash, string $refreshHash, int $expiresAt, int $refreshExpiresAt, int $now): int
    {
        return $this->db->insert('access_tokens', [
            'user_id' => $userId,
            'client' => $client,
            'scope' => $scope,
            'token_hash' => $tokenHash,
            'refresh_hash' => $refreshHash,
            'expires_at' => $expiresAt,
            'refresh_expires_at' => $refreshExpiresAt,
            'created_at' => $now,
        ]);
    }

    /** @return AccessTokenRow|null */
    public function byTokenHash(string $hash): ?array
    {
        $r = $this->db->selectOne('SELECT ' . self::COLUMNS . ' FROM {access_tokens} WHERE token_hash = ?', [$hash]);
        return $r !== null ? self::row($r) : null;
    }

    /** @return AccessTokenRow|null */
    public function byRefreshHash(string $hash): ?array
    {
        $r = $this->db->selectOne('SELECT ' . self::COLUMNS . ' FROM {access_tokens} WHERE refresh_hash = ?', [$hash]);
        return $r !== null ? self::row($r) : null;
    }

    /** @return AccessTokenRow|null */
    public function find(int $id): ?array
    {
        $r = $this->db->selectOne('SELECT ' . self::COLUMNS . ' FROM {access_tokens} WHERE id = ?', [$id]);
        return $r !== null ? self::row($r) : null;
    }

    /**
     * Zugänge, neueste zuerst; null = alle Personen.
     *
     * @return list<AccessTokenRow>
     */
    public function list(?int $userId): array
    {
        $rows = $userId === null
            ? $this->db->select('SELECT ' . self::COLUMNS . ' FROM {access_tokens} ORDER BY created_at DESC, id DESC')
            : $this->db->select('SELECT ' . self::COLUMNS . ' FROM {access_tokens} WHERE user_id = ? ORDER BY created_at DESC, id DESC', [$userId]);
        return array_map(self::row(...), $rows);
    }

    /** Neues Tokenpaar für einen bestehenden Zugang (Erneuern); die alten Hashes gelten danach nicht mehr. */
    public function rotate(int $id, string $tokenHash, string $refreshHash, int $expiresAt, int $refreshExpiresAt): void
    {
        $this->db->update('access_tokens', [
            'token_hash' => $tokenHash,
            'refresh_hash' => $refreshHash,
            'expires_at' => $expiresAt,
            'refresh_expires_at' => $refreshExpiresAt,
        ], ['id' => $id]);
    }

    public function touch(int $id, int $at): void
    {
        $this->db->update('access_tokens', ['last_used_at' => $at], ['id' => $id]);
    }

    public function delete(int $id): int
    {
        return $this->db->execute('DELETE FROM {access_tokens} WHERE id = ?', [$id]);
    }

    public function deleteForUser(int $userId): int
    {
        return $this->db->execute('DELETE FROM {access_tokens} WHERE user_id = ?', [$userId]);
    }

    /** Zugänge, die sich nicht mehr erneuern lassen. */
    public function deleteExpired(int $now): int
    {
        return $this->db->execute('DELETE FROM {access_tokens} WHERE refresh_expires_at < ?', [$now]);
    }

    /**
     * @param array<string, mixed> $r
     * @return AccessTokenRow
     */
    private static function row(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'user_id' => (int) $r['user_id'],
            'client' => (string) $r['client'],
            'scope' => (string) $r['scope'],
            'expires_at' => (int) $r['expires_at'],
            'refresh_expires_at' => (int) $r['refresh_expires_at'],
            'created_at' => (int) $r['created_at'],
            'last_used_at' => $r['last_used_at'] !== null ? (int) $r['last_used_at'] : null,
        ];
    }
}
