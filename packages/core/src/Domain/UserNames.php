<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\Port\UserDirectory;

/** Anzeigenamen mit Zwischenspeicher, z. B. für „angelegt von“ (Issue #24: auch für Mitglieder sichtbar). */
final class UserNames
{
    /** @var array<int, string|null> */
    private array $cache = [];

    public const DELETED = 'gelöschtes Konto';

    public function __construct(private readonly UserDirectory $users)
    {
    }

    public function name(int $id): ?string
    {
        if ($id <= 0) {
            return null;
        }
        if (!array_key_exists($id, $this->cache)) {
            // Konto gelöscht: Der Eintrag bleibt, wer ihn angelegt hat, nicht.
            $this->cache[$id] = $this->users->find($id)?->name ?? self::DELETED;
        }
        return $this->cache[$id];
    }
}
