<?php

declare(strict_types=1);

namespace KompanieApp\Standalone;

use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Begrenzt Anmeldeversuche innerhalb von 15 Minuten:
 * - 5 je Konto und Gerät (IP-Adresse; bei IPv6 das /64-Netz),
 * - 20 je IP-Adresse bzw. /64-Netz (Durchprobieren vieler Namen),
 * - 50 je Konto (verteiltes Raten). Geräte, auf denen sich das Konto schon erfolgreich
 *   angemeldet hat, zählen hier nicht mit – so können Fremde den Besitzer nicht aussperren.
 *
 * „Konto“ ist die ID des gefundenen Benutzers (gleich, ob per Name, E-Mail oder anderer
 * Schreibweise angemeldet), sonst der eingegebene Name. Jeder Versuch wird vor der
 * Passwortprüfung atomar gezählt (auch bei gleichzeitigen Anfragen) und bei Erfolg zurückgenommen.
 * Gespeichert werden nur HMACs, keine Namen oder Adressen.
 */
final class LoginThrottle
{
    public const WINDOW = 900;
    private const LIMITS = ['pair' => 5, 'ip' => 20, 'account' => 50];

    public function __construct(private readonly Connection $db, private readonly string $secret)
    {
    }

    /**
     * Zählt einen Versuch. false, wenn eine Grenze überschritten ist.
     *
     * @param bool $knownDevice Das Gerät hat sich mit diesem Konto schon angemeldet.
     */
    public function attempt(string $account, string $ip, bool $knownDevice): bool
    {
        $keys = $this->keys($account, $ip, $knownDevice);
        // Erst Gerät und IP zählen. Das Konto-Limit nur, wenn diese noch nicht erreicht sind –
        // sonst könnte eine einzige IP den Konto-Zähler füllen und so den Besitzer aussperren.
        $ok = $this->increment($keys['pair']) <= self::LIMITS['pair'];
        $ok = $this->increment($keys['ip']) <= self::LIMITS['ip'] && $ok;
        if ($ok && isset($keys['account'])) {
            $ok = $this->increment($keys['account']) <= self::LIMITS['account'];
        }
        if (random_int(1, 50) === 1) {
            $this->db->execute('DELETE FROM {login_attempts} WHERE window_start < ?', [time() - self::WINDOW]);
        }
        return $ok;
    }

    /** Nach erfolgreicher Anmeldung: Versuch zurücknehmen, Zähler des Geräts löschen. */
    public function success(string $account, string $ip, bool $knownDevice): void
    {
        // Bei Erfolg wurden alle Zähler erhöht (sonst wäre attempt() false gewesen): zurücknehmen.
        $keys = $this->keys($account, $ip, $knownDevice);
        $this->db->execute('DELETE FROM {login_attempts} WHERE name = ?', [$keys['pair']]);
        unset($keys['pair']);
        foreach ($keys as $key) {
            $this->db->execute('UPDATE {login_attempts} SET failures = failures - 1 WHERE name = ? AND failures > 0', [$key]);
        }
    }

    /** Erhöht den Zähler in einer Anweisung (atomar) und gibt den neuen Stand zurück. */
    private function increment(string $key): int
    {
        $now = time();
        $expired = $now - self::WINDOW;
        if ($this->db->driver() === 'mysql') {
            // Zuweisungen werden von links nach rechts ausgewertet: failures nutzt noch das alte window_start.
            $this->db->execute(
                'INSERT INTO {login_attempts} (name, failures, window_start) VALUES (?, 1, ?)
                 ON DUPLICATE KEY UPDATE failures = IF(window_start <= ?, 1, failures + 1), window_start = IF(window_start <= ?, ?, window_start)',
                [$key, $now, $expired, $expired, $now],
            );
        } else {
            $this->db->execute(
                'INSERT INTO {login_attempts} (name, failures, window_start) VALUES (?, 1, ?)
                 ON CONFLICT(name) DO UPDATE SET failures = CASE WHEN window_start <= ? THEN 1 ELSE failures + 1 END,
                 window_start = CASE WHEN window_start <= ? THEN excluded.window_start ELSE window_start END',
                [$key, $now, $expired, $expired],
            );
        }
        return (int) $this->db->scalar('SELECT failures FROM {login_attempts} WHERE name = ?', [$key]);
    }

    /** @return array<string, string> */
    private function keys(string $account, string $ip, bool $knownDevice): array
    {
        $net = self::network($ip);
        $h = fn (string $s): string => hash_hmac('sha256', 'throttle|' . $s, $this->secret);
        $keys = ['pair' => 'p:' . $h("pair|$account|$net"), 'ip' => 'i:' . $h("ip|$net")];
        if (!$knownDevice) {
            $keys['account'] = 'a:' . $h("account|$account");
        }
        return $keys;
    }

    /** IPv4 unverändert, IPv6 als /64-Netz (ein Anschluss hat meist ein ganzes /64). */
    public static function network(string $ip): string
    {
        $bin = @inet_pton($ip);
        if ($bin === false || strlen($bin) !== 16) {
            return $ip;
        }
        return bin2hex(substr($bin, 0, 8)) . '::/64';
    }
}
