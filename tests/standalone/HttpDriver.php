<?php

declare(strict_types=1);

namespace KompanieApp\Contract;

use KompanieApp\Core\Auth\Roles;
use KompanieApp\Standalone\Kernel;
use KompanieApp\Standalone\Sessions;

/**
 * Führt das API-Szenario per HTTP gegen einen laufenden Server der eigenständigen Version aus,
 * so wie die App: Sitzungs-Cookie, CSRF-Token aus /auth/csrf, JSON.
 * Benutzer und Sitzungen legt der Treiber direkt in der Datenbank an (Testaufbau).
 */
final class HttpDriver implements ApiDriver
{
    private ?int $as = null;
    /** @var array<int, string> Benutzer => Sitzungsschlüssel (Cookie) */
    private array $tokens = [];
    /** @var array<int, string> Benutzer => CSRF-Token */
    private array $csrf = [];
    /** @var array<string, string> zusätzliche Header für die nächsten Anfragen */
    public array $extraHeaders = [];

    public function __construct(private readonly Kernel $kernel, private readonly string $apiBase)
    {
    }

    public function request(string $method, string $path, array $body = [], array $query = []): array
    {
        $res = $this->raw($method, $path, $body, $query);
        if ($res[0] === 403 && ($res[1]['code'] ?? '') === 'csrf_invalid' && $this->as !== null) {
            unset($this->csrf[$this->as]);
            $res = $this->raw($method, $path, $body, $query);
        }
        return $res;
    }

    /**
     * Anfrage ohne automatische CSRF-Wiederholung.
     *
     * @param array<string, string>|null $headers ersetzt die üblichen Header (Cookie bleibt)
     * @return array{0: int, 1: mixed, 2: array<string, string>}
     */
    public function raw(string $method, string $path, array $body = [], array $query = [], ?array $headers = null, ?string $rawBody = null): array
    {
        $url = $this->apiBase . ltrim($path, '/');
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
        $h = ['Accept' => 'application/json'];
        if ($this->as !== null) {
            $h['Cookie'] = Sessions::COOKIE . '=' . $this->token($this->as);
            if (!in_array($method, ['GET', 'HEAD'], true)) {
                $h['X-CSRF-Token'] = $this->csrfToken($this->as);
            }
        }
        $payload = $rawBody;
        if ($payload === null && $body !== []) {
            $payload = (string) json_encode($body);
            $h['Content-Type'] = 'application/json';
        }
        if ($headers !== null) {
            $h = $headers + (isset($h['Cookie']) ? ['Cookie' => $h['Cookie']] : []);
        }
        $h = $this->extraHeaders + $h;
        return $this->send($method, $url, $h, $payload);
    }

    /** @return array{0: int, 1: mixed, 2: array<string, string>} */
    public function send(string $method, string $url, array $headers, ?string $payload): array
    {
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = "$k: $v";
        }
        $respHeaders = [];
        $cookies = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders, &$cookies): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $name = strtolower(trim($parts[0]));
                    if ($name === 'set-cookie') {
                        $cookies[] = trim($parts[1]);
                    }
                    $respHeaders[$name] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw === false) {
            throw new \RuntimeException('Server nicht erreichbar: ' . $url);
        }
        // Neue Sitzung (z. B. nach Passwortänderung) wie ein Browser übernehmen.
        foreach ($cookies as $c) {
            if ($this->as !== null && preg_match('/^' . Sessions::COOKIE . '=([a-f0-9]{64})/', $c, $m)) {
                $this->tokens[$this->as] = $m[1];
                unset($this->csrf[$this->as]);
            }
        }
        $respHeaders['set-cookie'] = implode("\n", $cookies);
        return [$status, json_decode((string) $raw, true), $respHeaders];
    }

    public function actAs(?int $userId): void
    {
        $this->as = $userId;
    }

    /** Nach dem Wiederherstellen einer Sicherung gibt es keine Sitzungen mehr. */
    public function forgetSessions(): void
    {
        $this->tokens = [];
    }

    /** Sitzungsschlüssel eines Benutzers (legt bei Bedarf eine Sitzung an). */
    public function token(int $userId): string
    {
        if (!isset($this->tokens[$userId])) {
            $token = bin2hex(random_bytes(32));
            $now = time();
            $this->kernel->db()->insert('sessions', [
                'user_id' => $userId,
                'token_hash' => hash('sha256', $token),
                'remember' => 1,
                'created_at' => gmdate('Y-m-d H:i:s', $now),
                'renewed_at' => gmdate('Y-m-d H:i:s', $now),
                'expires_at' => gmdate('Y-m-d H:i:s', $now + Sessions::LIFETIME),
            ]);
            $this->tokens[$userId] = $token;
        }
        return $this->tokens[$userId];
    }

    private function csrfToken(int $userId): string
    {
        if (!isset($this->csrf[$userId])) {
            [$s, $d] = $this->raw('GET', 'auth/csrf');
            $this->csrf[$userId] = $s === 200 ? (string) $d['token'] : '';
        }
        return $this->csrf[$userId];
    }

    public function createUser(string $login, string $level): int
    {
        $db = $this->kernel->db();
        $existing = $db->scalar('SELECT id FROM {users} WHERE login = ?', [$login]);
        if ($existing) {
            $this->deleteUser((int) $existing);
        }
        return $this->kernel->users()->add(ucfirst($login), $login, '', bin2hex(random_bytes(8)), $level);
    }

    public function deleteUser(int $id): void
    {
        $db = $this->kernel->db();
        $db->execute('DELETE FROM {points} WHERE user_id = ? OR created_by = ?', [$id, $id]);
        $db->execute('DELETE FROM {penalties} WHERE user_id = ? OR created_by = ?', [$id, $id]);
        $db->execute('DELETE FROM {events} WHERE created_by = ?', [$id]);
        $db->execute("DELETE FROM {point_catalog} WHERE title LIKE 'Teilnahme Sommerfest'");
        $db->execute('DELETE FROM {sessions} WHERE user_id = ?', [$id]);
        $db->execute('DELETE FROM {member_roles} WHERE user_id = ?', [$id]);
        $db->execute('DELETE FROM {users} WHERE id = ?', [$id]);
        unset($this->tokens[$id], $this->csrf[$id]);
    }

    public function setPassword(int $id, string $password): void
    {
        $this->kernel->users()->setPassword($id, $password);
        unset($this->tokens[$id], $this->csrf[$id]); // setPassword beendet alle Sitzungen
    }

    public function checkPassword(int $id, string $password): bool
    {
        return $this->kernel->users()->verifyPassword($id, $password);
    }

    public function canAssignAdmin(): bool
    {
        return true;
    }
}
