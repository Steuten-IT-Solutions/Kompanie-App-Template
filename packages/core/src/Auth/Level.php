<?php

declare(strict_types=1);

namespace KompanieApp\Core\Auth;

/** Rolle in der App. Die Anzeigenamen kommen aus kompanie.json (roleLabels). */
enum Level: string
{
    case Admin = 'admin';
    case Manager = 'manager';
    case Member = 'member';
    case Guest = 'guest';

    /** Rechte, die zu einer Rolle gehören. */
    public function capabilities(): array
    {
        return match ($this) {
            self::Admin => Capability::cases(),
            self::Manager => [Capability::Member, Capability::Manage],
            self::Member => [Capability::Member],
            self::Guest => [],
        };
    }
}
