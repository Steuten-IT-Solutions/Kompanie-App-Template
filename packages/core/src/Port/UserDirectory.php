<?php

declare(strict_types=1);

namespace KompanieApp\Core\Port;

use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Auth\User;

/**
 * Benutzerkonten. WordPress: WP-Benutzer und -Rollen. Standalone: eigene Tabelle.
 *
 * Fehler, die die App anzeigen soll (z. B. Admin in WordPress nicht änderbar),
 * werden als \DomainException mit einer für Menschen lesbaren Meldung geworfen.
 */
interface UserDirectory
{
    public function find(int $id): ?User;

    /**
     * Nach Anzeigename sortiert.
     *
     * @param bool $includeGuests auch Konten ohne Rolle in der App
     * @return User[]
     */
    public function list(bool $includeGuests): array;

    /** Legt ein Konto als Mitglied oder als Anwärter (Auth\Roles) an und gibt seine ID zurück. */
    public function create(string $name, string $login, string $email, string $password, string $role = Roles::MEMBER): int;

    /** Ob in dieser Umgebung Admins in der App ernannt werden können (nur Standalone). */
    public function canAssignAdmin(): bool;

    /**
     * Rolle setzen (Schlüssel aus Domain\RoleService; geprüft hat der Kern).
     *
     * @throws \DomainException wenn die Rolle so nicht gesetzt werden kann
     */
    public function setRole(int $id, string $role): void;

    public function countAdmins(): int;

    public function verifyPassword(int $id, string $password): bool;

    /** Setzt ein neues Passwort und meldet alle Sitzungen des Benutzers ab. */
    public function setPassword(int $id, string $password): void;

    public function loginExists(string $login): bool;

    public function emailExists(string $email): bool;
}
