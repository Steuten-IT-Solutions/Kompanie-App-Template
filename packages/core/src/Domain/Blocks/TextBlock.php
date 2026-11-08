<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain\Blocks;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Domain\RichText;
use KompanieApp\Core\Http\ApiError;

/** Formatierter Text (RichText, Bilder über rich_images): der bisherige Text einer Seite. */
final class TextBlock implements BlockType
{
    public const TYPE = 'text';

    public function __construct(private readonly App $app)
    {
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function validate(array $in): array
    {
        $html = $in['html'] ?? '';
        if ($html !== null && !is_string($html)) {
            throw ApiError::invalid('Ungültiger Text.');
        }
        $html = RichText::sanitize((string) $html);
        return ['html' => RichText::isEmpty($html) ? '' : $html];
    }

    public function render(array $data, ?User $viewer, bool $manager): ?array
    {
        $html = (string) ($data['html'] ?? '');
        if ($html === '' && !$manager) {
            return null;
        }
        return ['html' => $html === '' ? '' : $this->app->richImages()->render($html)];
    }

    public function plain(array $data): string
    {
        return RichText::excerpt((string) ($data['html'] ?? ''));
    }
}
