<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain\Blocks;

use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;

/** Trennlinie (line) oder nur Abstand (space) zwischen Blöcken; in Mails und Textauszügen nichts. */
final class DividerBlock implements BlockType
{
    public const TYPE = 'divider';
    public const STYLES = ['line', 'space'];

    public function type(): string
    {
        return self::TYPE;
    }

    public function validate(array $in): array
    {
        $style = $in['style'] ?? 'line';
        if (!in_array($style, self::STYLES, true)) {
            throw ApiError::invalid('Ungültige Trennung: erlaubt sind line (Linie) und space (Abstand).');
        }
        return ['style' => $style];
    }

    public function render(array $data, ?User $viewer, bool $manager): ?array
    {
        return ['style' => (string) ($data['style'] ?? 'line')];
    }

    public function plain(array $data, ?User $viewer = null): string
    {
        return '';
    }
}
