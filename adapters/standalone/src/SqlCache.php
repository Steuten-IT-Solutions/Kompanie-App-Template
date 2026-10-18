<?php

declare(strict_types=1);

namespace KompanieApp\Standalone;

use KompanieApp\Core\Port\Cache;
use KompanieApp\Core\Storage\Sql\Connection;

/** Zwischenspeicher in der Tabelle cache. */
final class SqlCache implements Cache
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function get(string $key): mixed
    {
        $row = $this->db->selectOne('SELECT value, expires_at FROM {cache} WHERE name = ?', [$key]);
        if (!$row || (int) $row['expires_at'] < time()) {
            return null;
        }
        return unserialize((string) base64_decode((string) $row['value']), ['allowed_classes' => false]);
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        $data = ['value' => base64_encode(serialize($value)), 'expires_at' => time() + $ttlSeconds];
        $this->db->transaction(function () use ($key, $data): void {
            $this->db->execute('DELETE FROM {cache} WHERE name = ?', [$key]);
            $this->db->insert('cache', ['name' => $key] + $data);
        });
        // Abgelaufenes gelegentlich aufräumen.
        if (random_int(1, 50) === 1) {
            $this->db->execute('DELETE FROM {cache} WHERE expires_at < ?', [time()]);
        }
    }

    public function delete(string $key): void
    {
        $this->db->execute('DELETE FROM {cache} WHERE name = ?', [$key]);
    }

    public function increment(string $key, int $windowSeconds): int
    {
        $window = max(1, $windowSeconds);
        $bucket = intdiv(time(), $window);
        $name = 'ctr:' . substr(hash('sha256', $key), 0, 40) . ':' . $bucket;
        $expires = ($bucket + 1) * $window;
        $sql = $this->db->driver() === 'mysql'
            ? 'INSERT INTO {cache} (name, value, expires_at) VALUES (?, \'1\', ?) ON DUPLICATE KEY UPDATE value = value + 1'
            : 'INSERT INTO {cache} (name, value, expires_at) VALUES (?, \'1\', ?) ON CONFLICT(name) DO UPDATE SET value = CAST(value AS INTEGER) + 1';
        $this->db->execute($sql, [$name, $expires]);
        return (int) $this->db->scalar('SELECT value FROM {cache} WHERE name = ?', [$name]);
    }
}
