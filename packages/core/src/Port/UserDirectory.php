<?php

declare(strict_types=1);

namespace KompanieApp\Core\Port;

use KompanieApp\Core\Auth\Level;
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

    /** Legt ein Konto mit der Rolle Mitglied an und gibt seine ID zurück. */
    public function create(string $name, string $login, string $email, string $password): int;

    /** Ob in dieser Umgebung Admins in der App ernannt werden können (nur Standalone). */
    public function canAssignAdmin(): bool;

    /** @throws \DomainException wenn die Rolle so nicht gesetzt werden kann */
    public function setLevel(int $id, Level $level): void;

    public function countAdmins(): int;

    public function verifyPassword(int $id, string $password): bool;

    /** Setzt ein neues Passwort und meldet alle Sitzungen des Benutzers ab. */
    public function setPassword(int $id, string $password): void;

    public function loginExists(string $login): bool;

    public function emailExists(string $email): bool;
}
