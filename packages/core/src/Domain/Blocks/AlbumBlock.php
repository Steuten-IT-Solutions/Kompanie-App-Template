<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain\Blocks;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Domain\Input;
use KompanieApp\Core\Http\ApiError;

/**
 * Album: einige Bilder eines Albums der Galerie mit dem Weg zum ganzen Album. Es gelten die Rechte der Galerie
 * (view_gallery, interne Bilder mit view_gallery_internal); sieht der Lesende kein Bild des Albums, ist die Galerie aus
 * oder das Album gelöscht, fällt der Block weg (die Pflege sieht ihn mit album null).
 */
final class AlbumBlock implements BlockType
{
    public const TYPE = 'album';
    public const MAX = 12;
    public const DEFAULT = 6;

    public function __construct(private readonly App $app)
    {
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function validate(array $in): array
    {
        $id = Input::integer($in['album_id'] ?? null);
        if ($id === null || $id <= 0 || $this->app->gallery()->album($id, true) === null) {
            throw ApiError::invalid('Bitte ein Album mit Bildern auswählen.');
        }
        return ['album_id' => $id, 'count' => Field::int($in['count'] ?? null, 1, self::MAX, self::DEFAULT, 'Wie viele Bilder')];
    }

    public function render(array $data, ?User $viewer, bool $manager): ?array
    {
        $album = $this->album($data, $viewer);
        if ($album === null && !$manager) {
            return null;
        }
        return ['album_id' => (int) ($data['album_id'] ?? 0), 'count' => (int) ($data['count'] ?? self::DEFAULT), 'album' => $album];
    }

    public function plain(array $data, ?User $viewer = null): string
    {
        $album = $this->album($data, $viewer);
        return $album === null ? '' : 'Album: ' . $album['title'] . ' – ' . $this->app->env->appUrl() . '#/galerie/' . $album['id'];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{id: int, title: string, year: string, total: int, photos: list<array{id: int, thumb: string, large: string, width: int, height: int}>}|null
     */
    private function album(array $data, ?User $viewer): ?array
    {
        $who = $viewer ?? $this->app->anonymous();
        $internal = $who->can(Capability::ViewGalleryInternal);
        if (!$this->app->features()->enabled('gallery') || !($internal || $who->can(Capability::ViewGallery))) {
            return null;
        }
        $album = $this->app->gallery()->album((int) ($data['album_id'] ?? 0), $internal);
        if ($album === null) {
            return null;
        }
        $images = array_values(array_filter($album['photos'], static fn (array $p): bool => $p['type'] === 'image'));
        $count = max(1, min(self::MAX, (int) ($data['count'] ?? self::DEFAULT)));
        return [
            'id' => (int) $album['id'],
            'title' => (string) $album['title'],
            'year' => (string) $album['year'],
            'total' => count($album['photos']),
            'photos' => array_map(static fn (array $p): array => ['id' => $p['id'], 'thumb' => $p['thumb'], 'large' => $p['large'], 'width' => $p['width'], 'height' => $p['height']], array_slice($images, 0, $count)),
        ];
    }
}
