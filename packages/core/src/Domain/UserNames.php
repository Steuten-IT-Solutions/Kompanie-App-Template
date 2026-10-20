<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\Port\UserDirectory;

/**
 * Anzeigenamen mit Zwischenspeicher, z. B. für „angelegt von“ (Issue #24: auch für Mitglieder sichtbar). Listen holen
 * die Namen vorab mit prime(): eine Abfrage für alle statt einer je Konto (Issue #18).
 */
final class UserNames
{
    /** @var array<int, string|null> */
    private array $cache = [];

    public const DELETED = 'gelöschtes Konto';

    public function __construct(private readonly UserDirectory $users)
    {
    }

    /** @param iterable<int|string|null> $ids Konten, deren Namen gleich gebraucht werden (Duplikate und 0 sind erlaubt) */
    public function prime(iterable $ids): self
    {
        $missing = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0 && !array_key_exists($id, $this->cache)) {
                $missing[$id] = $id;
            }
        }
        if ($missing !== []) {
            $found = $this->users->findMany(array_values($missing));
            foreach ($missing as $id) {
                $this->cache[$id] = isset($found[$id]) ? $found[$id]->name : self::DELETED;
            }
        }
        return $this;
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
