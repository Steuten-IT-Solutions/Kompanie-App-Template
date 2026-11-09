<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain\Blocks;

use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;

/**
 * Karte zur Anfahrt (Entscheidung vom 08.11.2026): keine eingebettete Karte eines fremden Anbieters, sondern wie beim Ort
 * eines Termins ein Ort als Text und ein Knopf, der ihn in der Karten-App des Geräts zeigt (die App bildet den Link je
 * Gerät, siehe mapSearchUrl). So lädt die Seite nichts von Dritten.
 */
final class MapBlock implements BlockType
{
    public const TYPE = 'map';
    public const TITLE_MAX = 100;
    public const PLACE_MAX = 200;

    public function type(): string
    {
        return self::TYPE;
    }

    public function validate(array $in): array
    {
        $place = Field::line($in['place'] ?? '', self::PLACE_MAX, 'Der Ort');
        if ($place === '') {
            throw ApiError::invalid('Bitte einen Ort oder eine Adresse eingeben.');
        }
        return ['title' => Field::line($in['title'] ?? '', self::TITLE_MAX, 'Die Überschrift der Karte'), 'place' => $place];
    }

    public function render(array $data, ?User $viewer, bool $manager): ?array
    {
        return ['title' => (string) ($data['title'] ?? ''), 'place' => (string) ($data['place'] ?? '')];
    }

    /** Überschrift, Ort und ein Link zur Karte (in Mails ohne Wissen über das Gerät: Google Maps, wie am PC in der App). */
    public function plain(array $data, ?User $viewer = null): string
    {
        $place = (string) ($data['place'] ?? '');
        if ($place === '') {
            return '';
        }
        $title = (string) ($data['title'] ?? '');
        return ($title !== '' ? $title . "\n" : '') . $place . "\nKarte: https://www.google.com/maps/search/?api=1&query=" . rawurlencode($place);
    }
}
