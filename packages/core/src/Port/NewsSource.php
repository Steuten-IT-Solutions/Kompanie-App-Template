<?php

declare(strict_types=1);

namespace KompanieApp\Core\Port;

/**
 * Herkunft der Neuigkeiten: eigene WordPress-Beiträge, eine fremde WordPress-Seite
 * über /wp-json/wp/v2/posts, ein RSS-Feed oder keine.
 *
 * Einträge: id (string), title, date (ISO 8601), excerpt, image, link
 * (siehe NewsItem in api/openapi.yaml).
 */
interface NewsSource
{
    public function available(): bool;

    /** @return array{items: array<int, array<string, mixed>>, totalPages: int} */
    public function page(int $page, int $perPage): array;

    /** Eintrag mit zusätzlichem Feld content (HTML); null, wenn nicht vorhanden. */
    public function item(string $id): ?array;
}
