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
        public readonly Level $level,
        public readonly array $capabilities,
    ) {
    }

    public function can(Capability $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }
}
