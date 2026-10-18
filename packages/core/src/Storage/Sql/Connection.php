<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage\Sql;

/**
 * Verbindung für die SQL-Konnektoren (MySQL/MariaDB und SQLite).
 * WordPress: $wpdb. Standalone: PDO.
 *
 * Der Kern spricht nicht direkt mit SQL, sondern mit Repositories. Nur deren
 * SQL-Umsetzung nutzt diese Verbindung.
 *
 * Abfragen nutzen Platzhalter `?` und Tabellennamen in geschweiften Klammern,
 * z. B. `SELECT * FROM {points} WHERE user_id = ?`. Die Verbindung ersetzt
 * `{points}` durch den Namen mit Präfix (z. B. `wp_kompanie_points`).
 */
interface Connection
{
    /** @return array<int, array<string, mixed>> */
    public function select(string $sql, array $params = []): array;

    /** @return array<string, mixed>|null */
    public function selectOne(string $sql, array $params = []): ?array;

    public function scalar(string $sql, array $params = []): mixed;

    /** INSERT, UPDATE, DELETE; gibt die Zahl der betroffenen Zeilen zurück. */
    public function execute(string $sql, array $params = []): int;

    /**
     * Fügt eine Zeile ein und gibt die neue ID zurück.
     *
     * @param array<string, mixed> $row
     */
    public function insert(string $table, array $row): int;

    public function table(string $name): string;

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed;

    /** 'mysql' oder 'sqlite'; bestimmt den Dialekt (Tabellenanlage). */
    public function driver(): string;

    /** Zusatz für CREATE TABLE, z. B. Zeichensatz bei MySQL; leer bei SQLite. */
    public function tableOptions(): string;

    /**
     * Ändert Zeilen; gibt die Zahl der betroffenen Zeilen zurück.
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed> $where Gleichheitsbedingungen, mit AND verknüpft
     */
    public function update(string $table, array $values, array $where): int;
}
