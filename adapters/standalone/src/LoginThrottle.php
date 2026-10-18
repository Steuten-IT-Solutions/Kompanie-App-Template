<?php

declare(strict_types=1);

namespace KompanieApp\Standalone;

use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Begrenzt fehlgeschlagene Anmeldungen innerhalb von 15 Minuten:
 * - 5 je Benutzername und IP-Adresse (Raten an einem Gerät),
 * - 20 je IP-Adresse (Durchprobieren vieler Namen),
 * - 50 je Benutzername (verteiltes Raten). Bewusst höher, damit Fremde ein Konto
 *   nicht einfach durch Fehlversuche sperren können.
 * Gespeichert werden nur Hashes, keine Namen oder Adressen.
 */
final class LoginThrottle
{
    public const WINDOW = 900;
    private const LIMITS = ['pair' => 5, 'ip' => 20, 'login' => 50];

    public function __construct(private readonly Connection $db, private readonly string $secret)
    {
    }

    public function blocked(string $login, string $ip): bool
    {
        foreach ($this->keys($login, $ip) as $kind => $key) {
            $row = $this->db->selectOne('SELECT failures, window_start FROM {login_attempts} WHERE name = ?', [$key]);
            if ($row && (int) $row['window_start'] > time() - self::WINDOW && (int) $row['failures'] >= self::LIMITS[$kind]) {
                return true;
            }
        }
        return false;
    }

    public function fail(string $login, string $ip): void
    {
        $now = time();
        foreach ($this->keys($login, $ip) as $key) {
            $this->db->transaction(function () use ($key, $now): void {
                $row = $this->db->selectOne('SELECT failures, window_start FROM {login_attempts} WHERE name = ?', [$key]);
                if (!$row) {
                    $this->db->insert('login_attempts', ['name' => $key, 'failures' => 1, 'window_start' => $now]);
                } elseif ((int) $row['window_start'] <= $now - self::WINDOW) {
                    $this->db->update('login_attempts', ['failures' => 1, 'window_start' => $now], ['name' => $key]);
                } else {
                    $this->db->execute('UPDATE {login_attempts} SET failures = failures + 1 WHERE name = ?', [$key]);
                }
            });
        }
        if (random_int(1, 50) === 1) {
            $this->db->execute('DELETE FROM {login_attempts} WHERE window_start < ?', [$now - self::WINDOW]);
        }
    }

    /** Nach erfolgreicher Anmeldung: Zähler für dieses Gerät zurücksetzen. */
    public function succeed(string $login, string $ip): void
    {
        $this->db->execute('DELETE FROM {login_attempts} WHERE name = ?', [$this->keys($login, $ip)['pair']]);
    }

    /** @return array{pair: string, ip: string, login: string} */
    private function keys(string $login, string $ip): array
    {
        $login = mb_strtolower(trim($login));
        $h = fn (string $s): string => hash_hmac('sha256', $s, $this->secret);
        return ['pair' => 'p:' . $h($login . "\0" . $ip), 'ip' => 'i:' . $h($ip), 'login' => 'l:' . $h($login)];
    }
}
