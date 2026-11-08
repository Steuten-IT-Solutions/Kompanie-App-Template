<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain\Blocks;

use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;

/** Zahlen/Fakten: kurze Kennzahlen nebeneinander, je Wert (z. B. „1848“, „250+“) und Bezeichnung („gegründet“). */
final class FactsBlock implements BlockType
{
    public const TYPE = 'facts';
    public const TITLE_MAX = 100;
    public const VALUE_MAX = 20;
    public const LABEL_MAX = 60;
    public const MAX = 12;

    public function type(): string
    {
        return self::TYPE;
    }

    public function validate(array $in): array
    {
        $items = $in['items'] ?? null;
        if (!is_array($items) || !array_is_list($items) || $items === []) {
            throw ApiError::invalid('Bitte mindestens eine Zahl eintragen.');
        }
        if (count($items) > self::MAX) {
            throw ApiError::invalid(sprintf('Höchstens %d Zahlen je Block.', self::MAX));
        }
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw ApiError::invalid('Ungültige Zahl.');
            }
            $value = Field::line($item['value'] ?? '', self::VALUE_MAX, 'Ein Wert');
            $label = Field::line($item['label'] ?? '', self::LABEL_MAX, 'Eine Bezeichnung');
            if ($value === '' || $label === '') {
                throw ApiError::invalid('Bitte zu jeder Zahl Wert und Bezeichnung eingeben.');
            }
            $out[] = ['value' => $value, 'label' => $label];
        }
        return ['title' => Field::line($in['title'] ?? '', self::TITLE_MAX, 'Die Überschrift der Zahlen'), 'items' => $out];
    }

    public function render(array $data, ?User $viewer, bool $manager): ?array
    {
        return ['title' => (string) ($data['title'] ?? ''), 'items' => self::items($data)];
    }

    public function plain(array $data, ?User $viewer = null): string
    {
        $lines = array_map(static fn (array $i): string => $i['value'] . ' ' . $i['label'], self::items($data));
        $title = (string) ($data['title'] ?? '');
        return ($title !== '' ? $title . "\n" : '') . implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array{value: string, label: string}>
     */
    private static function items(array $data): array
    {
        $out = [];
        foreach (is_array($data['items'] ?? null) ? $data['items'] : [] as $i) {
            if (is_array($i)) {
                $out[] = ['value' => (string) ($i['value'] ?? ''), 'label' => (string) ($i['label'] ?? '')];
            }
        }
        return $out;
    }
}
