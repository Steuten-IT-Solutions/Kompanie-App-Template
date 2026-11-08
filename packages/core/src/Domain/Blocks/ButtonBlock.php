<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain\Blocks;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;

/**
 * Knopf mit Link: Beschriftung und Ziel (https://, mailto:, tel: oder eine Seite der App, #/…), als Hauptknopf
 * (primary) oder zurückhaltend (secondary).
 */
final class ButtonBlock implements BlockType
{
    public const TYPE = 'button';
    public const LABEL_MAX = 60;
    public const STYLES = ['primary', 'secondary'];

    public function __construct(private readonly App $app)
    {
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function validate(array $in): array
    {
        $label = Field::line($in['label'] ?? '', self::LABEL_MAX, 'Die Beschriftung des Knopfs');
        if ($label === '') {
            throw ApiError::invalid('Bitte eine Beschriftung für den Knopf eingeben.');
        }
        $style = $in['style'] ?? 'primary';
        if (!in_array($style, self::STYLES, true)) {
            throw ApiError::invalid('Ungültige Art des Knopfs: erlaubt sind primary und secondary.');
        }
        return ['label' => $label, 'url' => Field::link($in['url'] ?? null), 'style' => $style];
    }

    public function render(array $data, ?User $viewer, bool $manager): ?array
    {
        return ['label' => (string) ($data['label'] ?? ''), 'url' => (string) ($data['url'] ?? ''), 'style' => (string) ($data['style'] ?? 'primary')];
    }

    /** „Beschriftung: Ziel“; Seiten der App mit ihrer vollen Adresse (für Mails). */
    public function plain(array $data, ?User $viewer = null): string
    {
        $url = (string) ($data['url'] ?? '');
        if (str_starts_with($url, '#/')) {
            $url = $this->app->env->appUrl() . $url;
        } elseif (str_starts_with($url, 'mailto:') || str_starts_with($url, 'tel:')) {
            $url = substr($url, strpos($url, ':') + 1);
        }
        return (string) ($data['label'] ?? '') . ': ' . $url;
    }
}
