<?php

declare(strict_types=1);

namespace KompanieApp\Core\Infra;

use KompanieApp\Core\Port\Cache;

/** Zwischenspeicher nur für die Dauer einer Anfrage (Tests, Ersatz). */
final class ArrayCache implements Cache
{
    /** @var array<string, array{0: mixed, 1: int}> */
    private array $items = [];

    public function get(string $key): mixed
    {
        $item = $this->items[$key] ?? null;
        if ($item === null || $item[1] < time()) {
            return null;
        }
        return $item[0];
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        $this->items[$key] = [$value, time() + $ttlSeconds];
    }

    public function delete(string $key): void
    {
        unset($this->items[$key]);
    }

    public function increment(string $key, int $windowSeconds): int
    {
        $bucket = 'ctr:' . $key . ':' . intdiv(time(), max(1, $windowSeconds));
        $n = (int) ($this->get($bucket) ?? 0) + 1;
        $this->set($bucket, $n, $windowSeconds);
        return $n;
    }
}
