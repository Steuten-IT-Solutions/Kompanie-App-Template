<?php

declare(strict_types=1);

namespace KompanieApp\Contract;

/**
 * Verbindet das gemeinsame Test-Szenario mit einem Adapter (Kern im Speicher,
 * WordPress, eigenständige Version).
 */
interface ApiDriver
{
    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $query
     * @return array{0: int, 1: mixed, 2: array<string, string>} Status, Daten, Header (Namen klein)
     */
    public function request(string $method, string $path, array $body = [], array $query = []): array;

    /** null = nicht angemeldet */
    public function actAs(?int $userId): void;

    /** @param string $level guest|member|manager|admin */
    public function createUser(string $login, string $level): int;

    public function deleteUser(int $id): void;

    public function setPassword(int $id, string $password): void;

    public function checkPassword(int $id, string $password): bool;

    /** Ob Admins in der App ernannt werden können (Standalone ja, WordPress nein). */
    public function canAssignAdmin(): bool;
}
