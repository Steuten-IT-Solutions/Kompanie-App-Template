<?php

declare(strict_types=1);

namespace KompanieApp\Core\Port;

/**
 * Datenbankzugriff. WordPress: $wpdb. Standalone: PDO.
 *
 * Abfragen nutzen Platzhalter `?` und Tabellennamen in geschweiften Klammern,
 * z. B. `SELECT * FROM {points} WHERE user_id = ?`. Der Adapter ersetzt
 * `{points}` durch den Namen mit Präfix (z. B. `wp_mkl_points`).
 * Das SQL muss auf MySQL/MariaDB und SQLite laufen.
 */
interface Database
{
    /** @return array<int, array<string, mixed>> */
    public function select(string $sql, array $params = []): array;

    /** @return array<string, mixed>|null */
    public function selectOne(string $sql, array $params = []): ?array;

    public function scalar(string $sql, array $params = []): mixed;

    /** INSERT, UPDATE, DELETE; gibt die Zahl der betroffenen Zeilen zurück. */
    public function execute(string $sql, array $params = []): int;

    /** Fügt eine Zeile ein und gibt die neue ID zurück. */
    public function insert(string $table, array $row): int;

    public function table(string $name): string;

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed;

    /** 'mysql' oder 'sqlite', für die wenigen Stellen, an denen sich das SQL unterscheidet. */
    public function driver(): string;
}
