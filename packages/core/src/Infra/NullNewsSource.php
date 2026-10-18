<?php

declare(strict_types=1);

namespace KompanieApp\Core\Infra;

use KompanieApp\Core\Port\NewsSource;

/** Keine Neuigkeiten. */
final class NullNewsSource implements NewsSource
{
    public function available(): bool
    {
        return false;
    }

    public function page(int $page, int $perPage): array
    {
        return ['items' => [], 'totalPages' => 0];
    }

    public function item(string $id): ?array
    {
        return null;
    }
}
