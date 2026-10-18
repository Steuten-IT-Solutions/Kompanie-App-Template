<?php

declare(strict_types=1);

namespace KompanieApp\WordPress;

use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Datenbankzugriff über $wpdb. Platzhalter ? werden in wpdb-Formate übersetzt.
 * Fehler von $wpdb werden als \RuntimeException weitergegeben.
 */
final class WpdbConnection implements Connection
{
    public function __construct(private readonly \wpdb $wpdb, private readonly string $prefix)
    {
    }

    public function select(string $sql, array $params = []): array
    {
        $rows = $this->wpdb->get_results($this->prepare($sql, $params), ARRAY_A);
        $this->check();
        return is_array($rows) ? $rows : [];
    }

    public function selectOne(string $sql, array $params = []): ?array
    {
        $row = $this->wpdb->get_row($this->prepare($sql, $params), ARRAY_A);
        $this->check();
        return is_array($row) ? $row : null;
    }

    public function scalar(string $sql, array $params = []): mixed
    {
        $v = $this->wpdb->get_var($this->prepare($sql, $params));
        $this->check();
        return $v;
    }

    public function execute(string $sql, array $params = []): int
    {
        $result = $this->wpdb->query($this->prepare($sql, $params));
        $this->check();
        return is_int($result) ? $result : 0;
    }

    public function insert(string $table, array $row): int
    {
        $ok = $this->wpdb->insert($this->table($table), $this->scalarValues($row));
        $this->check();
        if ($ok === false) {
            throw new \RuntimeException('Datenbankfehler beim Speichern.');
        }
        return (int) $this->wpdb->insert_id;
    }

    public function update(string $table, array $values, array $where): int
    {
        $result = $this->wpdb->update($this->table($table), $this->scalarValues($values), $this->scalarValues($where));
        $this->check();
        return is_int($result) ? $result : 0;
    }

    public function table(string $name): string
    {
        return $this->prefix . $name;
    }

    public function transaction(callable $fn): mixed
    {
        $this->wpdb->query('START TRANSACTION');
        try {
            $result = $fn();
            $this->wpdb->query('COMMIT');
            return $result;
        } catch (\Throwable $e) {
            $this->wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    public function driver(): string
    {
        return 'mysql';
    }

    public function tableOptions(): string
    {
        return $this->wpdb->get_charset_collate();
    }

    /** {tabelle} ersetzen, ? in %d/%f/%s übersetzen, NULL direkt einsetzen. */
    private function prepare(string $sql, array $params): string
    {
        $sql = (string) preg_replace_callback('/\{([a-z_]+)\}/', fn (array $m): string => $this->table($m[1]), $sql);
        if ($params === []) {
            return $sql;
        }
        $sql = str_replace('%', '%%', $sql);
        $values = [];
        $i = 0;
        $params = array_values($params);
        $sql = (string) preg_replace_callback('/\?/', function () use (&$i, $params, &$values): string {
            $v = $params[$i++] ?? null;
            if ($v === null) {
                return 'NULL';
            }
            if (is_bool($v) || is_int($v)) {
                $values[] = (int) $v;
                return '%d';
            }
            if (is_float($v)) {
                $values[] = $v;
                return '%f';
            }
            $values[] = (string) $v;
            return '%s';
        }, $sql);
        return $values === [] ? str_replace('%%', '%', $sql) : (string) $this->wpdb->prepare($sql, ...$values);
    }

    /** @param array<string, mixed> $row */
    private function scalarValues(array $row): array
    {
        return array_map(static fn ($v) => is_bool($v) ? (int) $v : $v, $row);
    }

    private function check(): void
    {
        if ($this->wpdb->last_error !== '') {
            $error = $this->wpdb->last_error;
            $this->wpdb->last_error = '';
            throw new \RuntimeException('Datenbankfehler: ' . $error);
        }
    }
}
