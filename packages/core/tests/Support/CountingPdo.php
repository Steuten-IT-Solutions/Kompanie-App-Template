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

    /** @var string[] Beginn von Transaktionen (BEGIN IMMEDIATE über exec bzw. beginTransaction), getrennt von $log */
    public array $transactions = [];

    public function exec(string $statement): int|false
    {
        if (preg_match('/^\s*BEGIN\b/i', $statement)) {
            $this->transactions[] = trim($statement);
        }
        return parent::exec($statement);
    }

    public function beginTransaction(): bool
    {
        $this->transactions[] = 'BEGIN';
        return parent::beginTransaction();
    }

    /** @return string[] Abfragen, die schreiben */
    public function writes(): array
    {
        return array_values(array_filter($this->log, static fn (string $q): bool => (bool) preg_match('/^(INSERT|UPDATE|DELETE|REPLACE)\b/i', $q)));
    }
}
