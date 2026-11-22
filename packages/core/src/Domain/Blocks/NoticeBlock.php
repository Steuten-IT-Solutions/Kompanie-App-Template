<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain\Blocks;

use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Domain\Text;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\I18n\I18n;

/** Hinweis-Kasten: kurzer Text (reiner Text, Zeilenumbrüche bleiben) farbig hervorgehoben, als Info, Warnung oder Erfolg. */
final class NoticeBlock implements BlockType
{
    public const TYPE = 'notice';
    public const TITLE_MAX = 100;
    public const TEXT_MAX = 1000;
    public const TONES = ['info', 'warning', 'success'];

    public function type(): string
    {
        return self::TYPE;
    }

    public function validate(array $in): array
    {
        $tone = $in['tone'] ?? 'info';
        if (!in_array($tone, self::TONES, true)) {
            throw ApiError::invalid(I18n::t('Ungültige Art des Hinweises: erlaubt sind info, warning und success.'));
        }
        $text = is_string($in['text'] ?? null) ? Text::multiline($in['text']) : '';
        if ($text === '') {
            throw ApiError::invalid(I18n::t('Bitte einen Text für den Hinweis eingeben.'));
        }
        if (mb_strlen($text) > self::TEXT_MAX) {
            throw ApiError::invalid(I18n::t('Der Hinweis darf höchstens {max} Zeichen lang sein.', ['max' => self::TEXT_MAX]));
        }
        return ['title' => Field::line($in['title'] ?? '', self::TITLE_MAX, I18n::t('Die Überschrift des Hinweises')), 'text' => $text, 'tone' => $tone];
    }

    public function render(array $data, ?User $viewer, bool $manager): ?array
    {
        return ['title' => (string) ($data['title'] ?? ''), 'text' => (string) ($data['text'] ?? ''), 'tone' => (string) ($data['tone'] ?? 'info')];
    }

    public function plain(array $data, ?User $viewer = null): string
    {
        $title = (string) ($data['title'] ?? '');
        return ($title !== '' ? $title . "\n" : '') . (string) ($data['text'] ?? '');
    }
}
