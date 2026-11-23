<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Anmeldung per OAuth für KI-Assistenten (Domain\OAuthService): Programme (oauth_clients) und Freigaben im Gang
 * (oauth_grants: erst Anfrage mit request_hash, nach der Zustimmung dazu ein einmaliger Code mit code_hash). Nur Hashes,
 * Zeiten als Unix-Zeit.
 *
 * @phpstan-type OAuthClientRow array{id: int, client_id: string, kind: string, name: string, redirect_uris: list<string>, created_at: int, fetched_at: int|null, last_used_at: int|null}
 * @phpstan-type OAuthGrantRow array{id: int, client_id: string, redirect_uri: string, code_challenge: string, state: string, scope: string, user_id: int|null, expires_at: int}
 */
final class OAuthRepository
{
    private const GRANT_COLUMNS = 'id, client_id, redirect_uri, code_challenge, state, scope, user_id, expires_at';

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return OAuthClientRow|null */
    public function client(string $clientId): ?array
    {
        $r = $this->db->selectOne('SELECT * FROM {oauth_clients} WHERE client_id = ?', [$clientId]);
        return $r !== null ? self::clientRow($r) : null;
    }

    /** @param list<string> $redirectUris */
    public function saveClient(string $clientId, string $kind, string $name, array $redirectUris, int $now): void
    {
        $uris = (string) json_encode($redirectUris, JSON_UNESCAPED_SLASHES);
        $existing = $this->client($clientId);
        if ($existing === null) {
            $this->db->insert('oauth_clients', ['client_id' => $clientId, 'kind' => $kind, 'name' => $name, 'redirect_uris' => $uris, 'created_at' => $now, 'fetched_at' => $kind === 'cimd' ? $now : null]);
            return;
        }
        $this->db->execute('UPDATE {oauth_clients} SET name = ?, redirect_uris = ?, fetched_at = ? WHERE id = ?', [$name, $uris, $kind === 'cimd' ? $now : null, $existing['id']]);
    }

    public function touchClient(string $clientId, int $now): void
    {
        $this->db->execute('UPDATE {oauth_clients} SET last_used_at = ? WHERE client_id = ?', [$now, $clientId]);
    }

    public function countClients(string $kind): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM {oauth_clients} WHERE kind = ?', [$kind]);
    }

    /** Selbst registrierte Programme, die nie oder lange nicht benutzt wurden, entfernen. */
    public function deleteUnusedClients(string $kind, int $before): void
    {
        $this->db->execute('DELETE FROM {oauth_clients} WHERE kind = ? AND COALESCE(last_used_at, created_at) < ?', [$kind, $before]);
    }

    public function createGrant(string $requestHash, string $clientId, string $redirectUri, string $codeChallenge, string $state, string $scope, int $expiresAt): void
    {
        $this->db->insert('oauth_grants', [
            'request_hash' => $requestHash,
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'state' => $state,
            'scope' => $scope,
            'expires_at' => $expiresAt,
        ]);
    }

    /** @return OAuthGrantRow|null Anfrage, die noch keinen Code hat */
    public function request(string $requestHash): ?array
    {
        $r = $this->db->selectOne('SELECT ' . self::GRANT_COLUMNS . ' FROM {oauth_grants} WHERE request_hash = ? AND code_hash IS NULL', [$requestHash]);
        return $r !== null ? self::grantRow($r) : null;
    }

    public function approve(int $id, string $codeHash, int $userId, string $scope, int $expiresAt): bool
    {
        return $this->db->execute('UPDATE {oauth_grants} SET code_hash = ?, user_id = ?, scope = ?, expires_at = ? WHERE id = ? AND code_hash IS NULL', [$codeHash, $userId, $scope, $expiresAt, $id]) === 1;
    }

    /** @return OAuthGrantRow|null */
    public function code(string $codeHash): ?array
    {
        $r = $this->db->selectOne('SELECT ' . self::GRANT_COLUMNS . ' FROM {oauth_grants} WHERE code_hash = ?', [$codeHash]);
        return $r !== null ? self::grantRow($r) : null;
    }

    /** Eintrag löschen; true, wenn es ihn noch gab (ein Code gilt nur einmal, auch bei gleichzeitigen Anfragen). */
    public function deleteGrant(int $id): bool
    {
        return $this->db->execute('DELETE FROM {oauth_grants} WHERE id = ?', [$id]) === 1;
    }

    public function deleteExpiredGrants(int $now): void
    {
        $this->db->execute('DELETE FROM {oauth_grants} WHERE expires_at < ?', [$now]);
    }

    /**
     * @param array<string, mixed> $r
     * @return OAuthClientRow
     */
    private static function clientRow(array $r): array
    {
        $uris = json_decode((string) $r['redirect_uris'], true);
        return [
            'id' => (int) $r['id'],
            'client_id' => (string) $r['client_id'],
            'kind' => (string) $r['kind'],
            'name' => (string) $r['name'],
            'redirect_uris' => is_array($uris) ? array_values(array_filter($uris, 'is_string')) : [],
            'created_at' => (int) $r['created_at'],
            'fetched_at' => $r['fetched_at'] !== null ? (int) $r['fetched_at'] : null,
            'last_used_at' => $r['last_used_at'] !== null ? (int) $r['last_used_at'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @return OAuthGrantRow
     */
    private static function grantRow(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'client_id' => (string) $r['client_id'],
            'redirect_uri' => (string) $r['redirect_uri'],
            'code_challenge' => (string) $r['code_challenge'],
            'state' => (string) $r['state'],
            'scope' => (string) $r['scope'],
            'user_id' => $r['user_id'] !== null ? (int) $r['user_id'] : null,
            'expires_at' => (int) $r['expires_at'],
        ];
    }
}
