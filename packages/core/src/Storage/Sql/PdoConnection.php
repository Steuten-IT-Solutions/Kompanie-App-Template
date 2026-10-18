<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage\Sql;

/** Verbindung über PDO zu MySQL/MariaDB oder SQLite. */
final class PdoConnection implements Connection
{
    private readonly string $driver;

    public function __construct(private readonly \PDO $pdo, private readonly string $prefix = '')
    {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->driver = (string) $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if (!in_array($this->driver, ['mysql', 'sqlite'], true)) {
            throw new \InvalidArgumentException('Nicht unterstützte Datenbank: ' . $this->driver);
        }
    }

    /** SQLite-Datei; wird angelegt, wenn sie fehlt. */
    public static function sqlite(string $file, string $prefix = ''): self
    {
        $pdo = new \PDO('sqlite:' . $file);
        $pdo->setAttribute(\PDO::ATTR_TIMEOUT, 10);
        // WAL erlaubt Lesen während eines Schreibvorgangs; bei Sperren bis 10 s warten.
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 10000');
        $pdo->exec('PRAGMA foreign_keys = ON');
        return new self($pdo, $prefix);
    }

    public static function mysql(string $host, string $database, string $user, string $password, string $prefix = '', int $port = 3306): self
    {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database);
        $pdo = new \PDO($dsn, $user, $password, [\PDO::ATTR_EMULATE_PREPARES => false]);
        $pdo->exec("SET time_zone = '+00:00'");
        return new self($pdo, $prefix);
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    public function select(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function selectOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function scalar(string $sql, array $params = []): mixed
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    public function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->table($table),
            implode(', ', $cols),
            implode(', ', array_fill(0, count($cols), '?')),
        );
        $this->run($sql, array_values($row), false);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(string $table, array $values, array $where): int
    {
        $set = implode(', ', array_map(static fn (string $c): string => $c . ' = ?', array_keys($values)));
        $cond = implode(' AND ', array_map(static fn (string $c): string => $c . ' = ?', array_keys($where)));
        $sql = sprintf('UPDATE %s SET %s WHERE %s', $this->table($table), $set, $cond);
        return $this->run($sql, [...array_values($values), ...array_values($where)], false)->rowCount();
    }

    public function table(string $name): string
    {
        return $this->prefix . $name;
    }

    public function transaction(callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $fn();
        }
        $this->pdo->beginTransaction();
        try {
            $result = $fn();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function tableOptions(): string
    {
        return $this->driver === 'mysql' ? 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    }

    /** Ersetzt {tabelle} durch den Namen mit Präfix. */
    private function expand(string $sql): string
    {
        return (string) preg_replace_callback('/\{([a-z_]+)\}/', fn (array $m): string => $this->table($m[1]), $sql);
    }

    private function run(string $sql, array $params, bool $expand = true): \PDOStatement
    {
        $stmt = $this->pdo->prepare($expand ? $this->expand($sql) : $sql);
        $i = 1;
        foreach ($params as $value) {
            $type = match (true) {
                is_int($value) => \PDO::PARAM_INT,
                is_bool($value) => \PDO::PARAM_INT,
                $value === null => \PDO::PARAM_NULL,
                default => \PDO::PARAM_STR,
            };
            $stmt->bindValue($i++, is_bool($value) ? (int) $value : $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }
}
