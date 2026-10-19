<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests\Support;

/** PDO, das alle vorbereiteten Abfragen mitschreibt (zum Zählen der Abfragen einer Anfrage). */
final class CountingPdo extends \PDO
{
    /** @var string[] */
    public array $log = [];

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        $this->log[] = (string) preg_replace('/\s+/', ' ', trim($query));
        return parent::prepare($query, $options);
    }

    /** @return string[] Abfragen, die schreiben */
    public function writes(): array
    {
        return array_values(array_filter($this->log, static fn (string $q): bool => (bool) preg_match('/^(INSERT|UPDATE|DELETE|REPLACE)\b/i', $q)));
    }
}
