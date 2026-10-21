<?php

declare(strict_types=1);

namespace KompanieApp\Core\Auth;

/** Angemeldeter Benutzer bzw. Eintrag im Benutzerverzeichnis. */
final class User
{
    /** @param Capability[] $capabilities */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $login,
        /** Schlüssel der Rolle (Auth\Roles bzw. vom Admin angelegt) */
        public readonly string $role,
        public readonly array $capabilities,
        /** Vor- und Nachname (Domain\PersonName); $name ist der Anzeigename */
        public readonly string $firstName = '',
        public readonly string $lastName = '',
    ) {
    }

    public function can(Capability $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    public function isMember(): bool
    {
        return $this->can(Capability::Member);
    }

    public function isAdmin(): bool
    {
        return $this->role === Roles::ADMIN;
    }

    /** @return array{id: int, name: string, login: string, level: string, capabilities: string[]} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'login' => $this->login,
            'level' => $this->role,
            'capabilities' => array_map(static fn (Capability $c): string => $c->value, $this->capabilities),
        ];
    }
}
