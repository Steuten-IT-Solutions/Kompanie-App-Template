<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Abos für Web Push (user_id 0 = Gast) und die Warteschlange für den Versand (Issue #19).
 *
 * @phpstan-type Subscription array{id: int, user_id: int, endpoint: string, p256dh: string, auth: string, types: list<string>, details: bool}
 * @phpstan-type QueueItem array{id: int, subscription_id: int, payload: string, urgency: string, ttl: int, attempts: int,
 *     endpoint: ?string, p256dh: ?string, auth: ?string}
 */
final class PushRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return Subscription|null */
    public function byEndpoint(string $endpoint): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM {push_subscriptions} WHERE endpoint_hash = ?', [hash('sha256', $endpoint)]);
        return $row === null ? null : self::format($row);
    }

    /** @return list<Subscription> älteste zuerst */
    public function forUser(int $userId): array
    {
        return array_map(self::format(...), array_values($this->db->select('SELECT * FROM {push_subscriptions} WHERE user_id = ? ORDER BY id ASC', [$userId])));
    }

    /** @return list<Subscription> */
    public function all(): array
    {
        return array_map(self::format(...), array_values($this->db->select('SELECT * FROM {push_subscriptions} ORDER BY id ASC')));
    }

    public function countGuests(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM {push_subscriptions} WHERE user_id = 0');
    }

    /** @return array{devices: int, accounts: int, guests: int, queued: int} */
    public function counts(): array
    {
        return [
            'devices' => (int) $this->db->scalar('SELECT COUNT(*) FROM {push_subscriptions}'),
            'accounts' => (int) $this->db->scalar('SELECT COUNT(DISTINCT user_id) FROM {push_subscriptions} WHERE user_id > 0'),
            'guests' => $this->countGuests(),
            'queued' => (int) $this->db->scalar('SELECT COUNT(*) FROM {push_queue}'),
        ];
    }

    /** @param list<string> $types */
    public function insert(int $userId, string $endpoint, string $p256dh, string $auth, array $types, bool $details, string $now): int
    {
        return $this->db->insert('push_subscriptions', [
            'user_id' => $userId,
            'endpoint' => $endpoint,
            'endpoint_hash' => hash('sha256', $endpoint),
            'p256dh' => $p256dh,
            'auth' => $auth,
            'types' => implode(',', $types),
            'details' => $details ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @param array{user_id?: int, p256dh?: string, auth?: string, types?: list<string>, details?: bool} $values */
    public function update(int $id, array $values, string $now): void
    {
        $row = ['updated_at' => $now];
        foreach ($values as $key => $value) {
            $row[$key] = match ($key) {
                'types' => implode(',', (array) $value),
                'details' => $value ? 1 : 0,
                default => $value,
            };
        }
        $this->db->update('push_subscriptions', $row, ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM {push_queue} WHERE subscription_id = ?', [$id]);
        $this->db->execute('DELETE FROM {push_subscriptions} WHERE id = ?', [$id]);
    }

    public function clearQueueFor(int $subscriptionId): void
    {
        $this->db->execute('DELETE FROM {push_queue} WHERE subscription_id = ?', [$subscriptionId]);
    }

    public function markSent(int $id, string $now): void
    {
        $this->db->execute('UPDATE {push_subscriptions} SET last_sent_at = ? WHERE id = ?', [$now, $id]);
    }

    /**
     * Mehrere Nachrichten auf einmal einreihen (eine Abfrage statt einer je Abo).
     *
     * @param list<array{subscription_id: int, payload: string}> $items
     */
    public function enqueue(array $items, string $urgency, int $ttl, string $now): void
    {
        foreach (array_chunk($items, 200) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $item) {
                $values[] = '(?, ?, ?, ?, 0, ?, ?)';
                array_push($params, $item['subscription_id'], $item['payload'], $urgency, $ttl, $now, $now);
            }
            $this->db->execute(
                'INSERT INTO {push_queue} (subscription_id, payload, urgency, ttl, attempts, next_try, created_at) VALUES ' . implode(', ', $values),
                $params,
            );
        }
    }

    /** @return list<QueueItem> fällige Nachrichten, älteste zuerst */
    public function due(string $now, int $limit): array
    {
        $rows = $this->db->select(
            'SELECT q.id, q.subscription_id, q.payload, q.urgency, q.ttl, q.attempts, s.endpoint, s.p256dh, s.auth
             FROM {push_queue} q LEFT JOIN {push_subscriptions} s ON s.id = q.subscription_id
             WHERE q.next_try <= ? ORDER BY q.id ASC LIMIT ' . max(1, $limit),
            [$now],
        );
        return array_values(array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'subscription_id' => (int) $r['subscription_id'],
            'payload' => (string) $r['payload'],
            'urgency' => (string) $r['urgency'],
            'ttl' => (int) $r['ttl'],
            'attempts' => (int) $r['attempts'],
            'endpoint' => isset($r['endpoint']) ? (string) $r['endpoint'] : null,
            'p256dh' => isset($r['p256dh']) ? (string) $r['p256dh'] : null,
            'auth' => isset($r['auth']) ? (string) $r['auth'] : null,
        ], $rows));
    }

    /** @param list<int> $ids */
    public function dequeue(array $ids): void
    {
        foreach (array_chunk($ids, 200) as $chunk) {
            $this->db->execute('DELETE FROM {push_queue} WHERE id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')', $chunk);
        }
    }

    public function retry(int $id, int $attempts, string $nextTry): void
    {
        $this->db->execute('UPDATE {push_queue} SET attempts = ?, next_try = ? WHERE id = ?', [$attempts, $nextTry, $id]);
    }

    public function nextDue(): ?string
    {
        $next = $this->db->scalar('SELECT MIN(next_try) FROM {push_queue}');
        return is_string($next) && $next !== '' ? $next : null;
    }

    /**
     * @param array<string, mixed> $r
     * @return Subscription
     */
    private static function format(array $r): array
    {
        $types = (string) ($r['types'] ?? '');
        return [
            'id' => (int) $r['id'],
            'user_id' => (int) $r['user_id'],
            'endpoint' => (string) $r['endpoint'],
            'p256dh' => (string) $r['p256dh'],
            'auth' => (string) $r['auth'],
            'types' => $types === '' ? [] : explode(',', $types),
            'details' => (bool) $r['details'],
        ];
    }
}
