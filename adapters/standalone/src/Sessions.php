<?php

declare(strict_types=1);

namespace KompanieApp\Standalone;

use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Sitzungen per Cookie. Im Cookie steht ein Zufallsschlüssel, in der Datenbank nur sein Hash.
 *
 * - Mit „Angemeldet bleiben“: 400 Tage (mehr erlauben Chrome und Edge für Cookies nicht),
 *   beim Öffnen der App nach 30 Tagen automatisch erneuert (mit neuem Schlüssel).
 * - Ohne: Sitzungs-Cookie, serverseitig höchstens 2 Tage.
 */
final class Sessions
{
    public const COOKIE = 'kompanie_session';
    public const LIFETIME = 400 * 86400;
    public const SHORT = 2 * 86400;
    public const RENEW_AFTER = 30 * 86400;

    /** @var array{id: int, user_id: int, token_hash: string, remember: int, renewed_at: string}|null|false */
    private array|null|false $current = false;

    /** „Angemeldet bleiben“ der zuletzt beendeten Sitzung dieses Geräts. */
    private ?bool $lastRemember = null;

    public function __construct(
        private readonly Connection $db,
        private readonly HttpContext $http,
    ) {
    }

    /** Aktuelle Sitzung aus dem Cookie, falls gültig. */
    public function current(): ?array
    {
        if ($this->current !== false) {
            return $this->current;
        }
        $token = $this->http->cookie(self::COOKIE);
        $this->current = null;
        if ($token === null || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $row = $this->db->selectOne('SELECT * FROM {sessions} WHERE token_hash = ? AND expires_at > ?', [hash('sha256', $token), gmdate('Y-m-d H:i:s')]);
        $this->current = $row ?: null;
        return $this->current;
    }

    public function userId(): ?int
    {
        return ($s = $this->current()) ? (int) $s['user_id'] : null;
    }

    /** Hash des Sitzungsschlüssels; Grundlage des CSRF-Tokens. */
    public function tokenHash(): ?string
    {
        return ($s = $this->current()) ? (string) $s['token_hash'] : null;
    }

    public function start(int $userId, bool $remember): void
    {
        $token = bin2hex(random_bytes(32));
        $now = time();
        $expires = $now + ($remember ? self::LIFETIME : self::SHORT);
        $id = $this->db->insert('sessions', [
            'user_id' => $userId,
            'token_hash' => hash('sha256', $token),
            'remember' => $remember ? 1 : 0,
            'created_at' => gmdate('Y-m-d H:i:s', $now),
            'renewed_at' => gmdate('Y-m-d H:i:s', $now),
            'expires_at' => gmdate('Y-m-d H:i:s', $expires),
        ]);
        $this->http->setCookie(self::COOKIE, $token, $remember ? $expires : 0);
        $this->current = ['id' => $id, 'user_id' => $userId, 'token_hash' => hash('sha256', $token), 'remember' => $remember ? 1 : 0, 'renewed_at' => gmdate('Y-m-d H:i:s', $now)];
        // Abgelaufene Sitzungen gelegentlich aufräumen.
        if (random_int(1, 20) === 1) {
            $this->db->execute('DELETE FROM {sessions} WHERE expires_at < ?', [gmdate('Y-m-d H:i:s')]);
        }
    }

    /** Verlängert eine dauerhafte Sitzung, wenn sie älter als RENEW_AFTER ist (neuer Schlüssel). */
    public function maybeRenew(): bool
    {
        $s = $this->current();
        if (!$s || !(int) $s['remember'] || strtotime($s['renewed_at'] . ' UTC') > time() - self::RENEW_AFTER) {
            return false;
        }
        $this->db->execute('DELETE FROM {sessions} WHERE id = ?', [(int) $s['id']]);
        $this->start((int) $s['user_id'], true);
        return true;
    }

    public function lastRemember(): ?bool
    {
        return $this->lastRemember;
    }

    public function destroy(): void
    {
        if ($s = $this->current()) {
            $this->lastRemember = (bool) (int) $s['remember'];
            $this->db->execute('DELETE FROM {sessions} WHERE id = ?', [(int) $s['id']]);
        }
        if ($this->http->cookie(self::COOKIE) !== null) {
            $this->http->setCookie(self::COOKIE, '', 1);
        }
        $this->current = null;
    }

    public function destroyAllFor(int $userId): void
    {
        $this->db->execute('DELETE FROM {sessions} WHERE user_id = ?', [$userId]);
        if ($this->userId() === $userId) {
            $this->lastRemember = (bool) (int) $this->current['remember'];
            $this->current = null;
        }
    }
}
