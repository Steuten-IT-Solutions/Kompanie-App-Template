<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain\Blocks;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Domain\RichText;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\I18n\I18n;

/**
 * Eigenes Bild, nicht aus der Galerie: hochgeladen über den Bildspeicher der App (POST /rich-images, RichImageService)
 * und beim Speichern wie Bilder in Textblöcken vom Inhalt übernommen (Blocks::claim). Dazu eine Beschreibung für
 * Screenreader (alt), eine Bildunterschrift und die Größe (klein, mittel, volle Breite; nie größer als das Bild).
 * Wer das Bild abrufen darf, prüft der Besitzer (z. B. PageService::canSeeImage, Blocks::imageOnlyForMembers).
 * Fehlt das Bild (gelöscht), fällt der Block weg; die Pflege sieht ihn mit url null.
 */
final class ImageBlock implements BlockType
{
    public const TYPE = 'image';
    public const ALT_MAX = 200;
    public const CAPTION_MAX = 200;
    public const SIZES = ['small', 'medium', 'full'];

    public function __construct(private readonly App $app)
    {
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function validate(array $in): array
    {
        $uid = is_string($in['image'] ?? null) ? strtolower(trim($in['image'])) : '';
        if (preg_match(RichText::IMAGE_UID, $uid) !== 1 || $this->app->richImages()->repository()->find($uid) === null) {
            throw ApiError::invalid(I18n::t('Bitte ein Bild hochladen.'));
        }
        $size = $in['size'] ?? 'full';
        if (!in_array($size, self::SIZES, true)) {
            throw ApiError::invalid(I18n::t('Ungültige Größe des Bilds: erlaubt sind small, medium und full.'));
        }
        return [
            'image' => $uid,
            'alt' => Field::line($in['alt'] ?? '', self::ALT_MAX, I18n::t('Die Beschreibung des Bilds')),
            'caption' => Field::line($in['caption'] ?? '', self::CAPTION_MAX, I18n::t('Die Bildunterschrift')),
            'size' => $size,
        ];
    }

    public function render(array $data, ?User $viewer, bool $manager): ?array
    {
        $uid = (string) ($data['image'] ?? '');
        $img = preg_match(RichText::IMAGE_UID, $uid) === 1 ? $this->app->richImages()->repository()->find($uid) : null;
        if ($img === null && !$manager) {
            return null;
        }
        return [
            'image' => $uid,
            'alt' => (string) ($data['alt'] ?? ''),
            'caption' => (string) ($data['caption'] ?? ''),
            'size' => in_array($data['size'] ?? null, self::SIZES, true) ? $data['size'] : 'full',
            'url' => $img === null ? null : $this->app->env->richImageUrl($uid),
            'width' => $img['width'] ?? 0,
            'height' => $img['height'] ?? 0,
        ];
    }

    /** Bildunterschrift, sonst die Beschreibung (für reinen Text gibt es das Bild selbst nicht). */
    public function plain(array $data, ?User $viewer = null): string
    {
        $caption = (string) ($data['caption'] ?? '');
        return $caption !== '' ? $caption : (string) ($data['alt'] ?? '');
    }

    /**
     * Formatiert (E-Mail, Verlauf am Brett): das Bild als data-image wie in Textblöcken (die Mail bettet es ein,
     * RichImageService::render setzt die Adresse), höchstens 600 Pixel breit, darunter die Bildunterschrift.
     *
     * @param array<string, mixed> $data
     */
    public function html(array $data): string
    {
        $uid = (string) ($data['image'] ?? '');
        $img = preg_match(RichText::IMAGE_UID, $uid) === 1 ? $this->app->richImages()->repository()->find($uid) : null;
        if ($img === null) {
            return '';
        }
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $caption = (string) ($data['caption'] ?? '');
        return '<p><img data-image="' . $uid . '" alt="' . $e((string) ($data['alt'] ?? '')) . '" width="' . min($img['width'], 600) . '"></p>'
            . ($caption !== '' ? '<p><em>' . $e($caption) . '</em></p>' : '');
    }
}
