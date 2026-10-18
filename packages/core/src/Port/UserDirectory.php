<?php

declare(strict_types=1);

namespace KompanieApp\Core\Port;

use KompanieApp\Core\Auth\Level;
use KompanieApp\Core\Auth\User;

/**
 * Benutzerkonten. WordPress: WP-Benutzer und -Rollen. Standalone: eigene Tabelle.
 *
 * Fehler (z. B. vergebener Benutzername) werden als \DomainException mit einer
 * für Menschen lesbaren Meldung geworfen.
 */
interface UserDirectory
{
    public function find(int $id): ?User;

    /**
     * @param bool $includeGuests auch Konten ohne Rolle in der App
     * @return User[]
     */
    public function list(bool $includeGuests): array;

    /** Legt ein Konto mit der Rolle Mitglied an und gibt seine ID zurück. */
    public function create(string $name, string $login, string $email, string $password): int;

    public function setLevel(int $id, Level $level): void;

    public function verifyPassword(int $id, string $password): bool;

    /** Setzt ein neues Passwort und meldet alle Sitzungen des Benutzers ab. */
    public function setPassword(int $id, string $password): void;

    public function loginExists(string $login): bool;

    public function emailExists(string $email): bool;
}
