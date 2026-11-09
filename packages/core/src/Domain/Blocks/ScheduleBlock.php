<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain\Blocks;

use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;

/** Ablauf/Zeitplan: Zeit (kurzer freier Text, z. B. „14:00“ oder „ab 20 Uhr“) und Programmpunkt untereinander. */
final class ScheduleBlock implements BlockType
{
    public const TYPE = 'schedule';
    public const TITLE_MAX = 100;
    public const TIME_MAX = 20;
    public const TEXT_MAX = 200;
    public const MAX = 50;

    public function type(): string
    {
        return self::TYPE;
    }

    public function validate(array $in): array
    {
        $items = $in['items'] ?? null;
        if (!is_array($items) || !array_is_list($items) || $items === []) {
            throw ApiError::invalid('Bitte mindestens einen Programmpunkt eintragen.');
        }
        if (count($items) > self::MAX) {
            throw ApiError::invalid(sprintf('Höchstens %d Programmpunkte.', self::MAX));
        }
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw ApiError::invalid('Ungültiger Programmpunkt.');
            }
            $text = Field::line($item['text'] ?? '', self::TEXT_MAX, 'Ein Programmpunkt');
            if ($text === '') {
                throw ApiError::invalid('Bitte zu jedem Programmpunkt einen Text eingeben.');
            }
            $out[] = ['time' => Field::line($item['time'] ?? '', self::TIME_MAX, 'Die Zeit eines Programmpunkts'), 'text' => $text];
        }
        return ['title' => Field::line($in['title'] ?? '', self::TITLE_MAX, 'Die Überschrift des Ablaufs'), 'items' => $out];
    }

    public function render(array $data, ?User $viewer, bool $manager): ?array
    {
        return ['title' => (string) ($data['title'] ?? ''), 'items' => self::items($data)];
    }

    public function plain(array $data, ?User $viewer = null): string
    {
        $lines = array_map(static fn (array $i): string => ($i['time'] !== '' ? $i['time'] . '  ' : '') . $i['text'], self::items($data));
        $title = (string) ($data['title'] ?? '');
        return ($title !== '' ? $title . "\n" : '') . implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array{time: string, text: string}>
     */
    private static function items(array $data): array
    {
        $out = [];
        foreach (is_array($data['items'] ?? null) ? $data['items'] : [] as $i) {
            if (is_array($i)) {
                $out[] = ['time' => (string) ($i['time'] ?? ''), 'text' => (string) ($i['text'] ?? '')];
            }
        }
        return $out;
    }
}
