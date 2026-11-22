<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Domain\Blocks\Blocks;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\I18n\I18n;

/**
 * Inhalte je Sprache (Plan „Mehrsprachigkeit“, „Inhalte je Sprache“, PR 9b): Deutsch ist die Hauptfassung und steht, wo
 * sie heute steht; jede weitere eingeschaltete Sprache kann eine eigene Fassung bekommen (Tabelle translations, Art z. B.
 * „page“, Kennung = ID, Feld z. B. title oder blocks). Fehlt sie, sieht der Leser die deutsche mit dem Hinweis „Nur auf
 * Deutsch verfügbar“ (untranslated in der Antwort).
 */
final class ContentTranslations
{
    /** Hinweis in der Antwort: nichts bzw. nur ein Teil ist in der Sprache der Anfrage vorhanden. */
    public const NONE = 'all';
    public const PARTLY = 'some';

    public function __construct(private readonly App $app)
    {
    }

    /**
     * Eingeschaltete weitere Sprachen (ohne Deutsch).
     *
     * @return list<string>
     */
    public function locales(): array
    {
        return array_values(array_diff($this->app->languages()->enabled(), [I18n::base()]));
    }

    /** Sprache der Anfrage, wenn sie eine weitere eingeschaltete ist; sonst null (dann gilt Deutsch). */
    public function current(): ?string
    {
        $locale = I18n::locale();
        return $locale !== I18n::base() && in_array($locale, $this->locales(), true) ? $locale : null;
    }

    /**
     * Fassungen eines Inhalts in den eingeschalteten weiteren Sprachen.
     *
     * @return array<string, array<string, string>> Sprache => Feld => Text
     */
    public function of(string $kind, int $id): array
    {
        return array_intersect_key($this->app->translations()->repository()->forRef($kind, (string) $id), array_flip($this->locales()));
    }

    /**
     * Fassungen mehrerer Inhalte (Listen).
     *
     * @param list<int> $ids
     * @return array<int, array<string, array<string, string>>> ID => Sprache => Feld => Text
     */
    public function ofMany(string $kind, array $ids): array
    {
        $locales = array_flip($this->locales());
        $out = [];
        foreach ($this->app->translations()->repository()->forRefs($kind, array_map('strval', $ids)) as $ref => $byLocale) {
            $out[(int) $ref] = array_intersect_key($byLocale, $locales);
        }
        return $out;
    }

    /**
     * Fassungen aus einer Anfrage (translations: {Sprache: {...}}): nur eingeschaltete weitere Sprachen.
     *
     * @return array<string, array<string, mixed>> Sprache => Felder wie gesendet
     */
    public function input(mixed $in): array
    {
        if ($in === null) {
            return [];
        }
        if (!is_array($in) || array_is_list($in) && $in !== []) {
            throw ApiError::invalid(I18n::t('Ungültige Übersetzungen.'));
        }
        $out = [];
        foreach ($in as $locale => $fields) {
            if (!is_string($locale) || !I18n::isLocale($locale) || $locale === I18n::base()) {
                throw ApiError::invalid(I18n::t('Übersetzungen gibt es nur für weitere Sprachen, nicht für die Basissprache.'));
            }
            if (!in_array($locale, $this->locales(), true)) {
                throw ApiError::invalid(I18n::t('Diese Sprache ist in der App nicht eingeschaltet.'));
            }
            if (!is_array($fields)) {
                throw ApiError::invalid(I18n::t('Ungültige Übersetzungen.'));
            }
            $out[$locale] = $fields;
        }
        return $out;
    }

    /**
     * Felder einer Fassung speichern ('' löscht das Feld).
     *
     * @param array<string, string> $fields
     */
    public function save(string $kind, int $id, string $locale, array $fields, int $by): void
    {
        $repo = $this->app->translations()->repository();
        $now = $this->app->time()->nowUtc();
        foreach ($fields as $field => $text) {
            $text === ''
                ? $repo->delete($kind, (string) $id, $field, $locale)
                : $repo->save($kind, (string) $id, $field, $locale, $text, $by, $now);
        }
    }

    /** Alle Fassungen eines Inhalts löschen (der Inhalt wurde gelöscht). */
    public function delete(string $kind, int $id): void
    {
        $this->app->translations()->repository()->deleteRef($kind, (string) $id);
    }

    /**
     * Titel und Blöcke eines Inhalts aus Blöcken (Seite, News, Brett) in einer Fassung ($fields: title, blocks), dazu
     * übersetzbare und übersetzte Felder (der Titel zählt mit).
     *
     * @param list<array<string, mixed>> $german
     * @param array<string, string> $fields
     * @return array{0: string, 1: list<array<string, mixed>>, 2: int, 3: int}
     */
    public static function blocksIn(string $title, array $german, array $fields): array
    {
        [$blocks, $total, $done] = Blocks::translate($german, Blocks::decodeTranslation($fields['blocks'] ?? null));
        $total++;
        if (($fields['title'] ?? '') !== '') {
            $title = $fields['title'];
            $done++;
        }
        return [$title, $blocks, $total, $done];
    }

    /**
     * Fassung eines Inhalts aus Blöcken aus der App prüfen: title und blocks (Kennung => Feld => Wert), jeweils optional;
     * '' löscht. Bilder in den Textblöcken gehören danach $ownerType/$ownerId.
     *
     * @param array<string, mixed> $in
     * @param list<array<string, mixed>> $german die deutschen Blöcke
     * @return array<string, string> Feld => zu speichernder Text
     */
    public function blocksInput(array $in, array $german, User $actor, string $ownerType, int $ownerId, int $titleMax, int $textMax): array
    {
        $out = [];
        if (array_key_exists('title', $in)) {
            $title = Text::line(is_string($in['title']) ? $in['title'] : '', $titleMax + 1);
            if (mb_strlen($title) > $titleMax) {
                throw ApiError::invalid(I18n::t('Der Titel darf höchstens {max} Zeichen lang sein.', ['max' => $titleMax]));
            }
            $out['title'] = $title;
        }
        if (array_key_exists('blocks', $in)) {
            $blocks = $this->app->blocks();
            $tr = $blocks->claimTranslation($blocks->validateTranslation($german, $in['blocks'], $textMax), $actor, $ownerType, $ownerId);
            $out['blocks'] = Blocks::encodeTranslation($tr);
        }
        return $out;
    }

    /**
     * Bilder in den Textblöcken aller Fassungen eines Inhalts (damit das Aufräumen der deutschen sie behält).
     *
     * @return list<string>
     */
    public function blockImages(string $kind, int $id): array
    {
        $keep = [];
        foreach ($this->app->translations()->repository()->forRef($kind, (string) $id) as $fields) {
            array_push($keep, ...Blocks::translationImages(Blocks::decodeTranslation($fields['blocks'] ?? null)));
        }
        return $keep;
    }

    /**
     * Fassungen zum Bearbeiten (source): Sprache => title, blocks (Bilder als Adressen).
     *
     * @return object
     */
    public function blocksSource(string $kind, int $id): object
    {
        return $this->blocksSourceOf($this->of($kind, $id));
    }

    /**
     * Wie blocksSource aus schon gelesenen Fassungen (Listen).
     *
     * @param array<string, array<string, string>> $byLocale Sprache => Feld => Text
     */
    public function blocksSourceOf(array $byLocale): object
    {
        $out = [];
        foreach (array_intersect_key($byLocale, array_flip($this->locales())) as $locale => $fields) {
            $out[$locale] = [
                'title' => $fields['title'] ?? '',
                'blocks' => (object) $this->app->blocks()->renderTranslation(Blocks::decodeTranslation($fields['blocks'] ?? null)),
            ];
        }
        return (object) $out;
    }

    /**
     * Alle Blöcke eines Inhalts, auch die der Fassungen (für die Sichtbarkeit von Bildern, Blocks::imageOnlyForMembers).
     *
     * @param list<array<string, mixed>> $german
     * @return list<array<string, mixed>>
     */
    public function allBlocks(string $kind, int $id, array $german): array
    {
        $all = $german;
        foreach ($this->of($kind, $id) as $fields) {
            array_push($all, ...Blocks::translate($german, Blocks::decodeTranslation($fields['blocks'] ?? null))[0]);
        }
        return $all;
    }

    /**
     * Fassungen für das Änderungsprotokoll: „Titel (en)“, „Inhalt (en)“ (reiner Text).
     *
     * @param list<array<string, mixed>> $german
     * @return array<string, string|null>
     */
    public function blocksLog(string $kind, int $id, array $german): array
    {
        $out = [];
        foreach ($this->app->translations()->repository()->forRef($kind, (string) $id) as $locale => $fields) {
            [, $blocks] = self::blocksIn('', $german, $fields);
            $text = trim((string) preg_replace('/\s+/u', ' ', $this->app->blocks()->plain($blocks, true)));
            $out["Titel ($locale)"] = ($fields['title'] ?? '') !== '' ? $fields['title'] : null;
            $out["Inhalt ($locale)"] = ($fields['blocks'] ?? '') !== '' && $text !== '' ? $text : null;
        }
        return $out;
    }

    /**
     * Fassungen einfacher Felder für das Änderungsprotokoll: „Titel (en)“ usw.
     *
     * @param array<string, string> $labels Feld => Bezeichnung
     * @return array<string, string|null>
     */
    public function fieldsLog(string $kind, int $id, array $labels): array
    {
        $out = [];
        foreach ($this->app->translations()->repository()->forRef($kind, (string) $id) as $locale => $fields) {
            foreach ($labels as $field => $label) {
                $out["$label ($locale)"] = ($fields[$field] ?? '') !== '' ? $fields[$field] : null;
            }
        }
        return $out;
    }

    /**
     * Titel je weitere Sprache aus einer Anfrage (translations: {Sprache: {title}}; Plan „Mehrsprachigkeit“, PR 9e:
     * Namen von Dokumenten, Kategorien, Alben). '' löscht, länger als $max → 400.
     *
     * @return array<string, array{title: string}>
     */
    public function titlesInput(mixed $in, int $max): array
    {
        $out = [];
        foreach ($this->input($in) as $locale => $fields) {
            if (!array_key_exists('title', $fields)) {
                continue;
            }
            $title = is_scalar($fields['title']) ? Text::line((string) $fields['title'], $max + 1) : '';
            if (mb_strlen($title) > $max) {
                throw ApiError::invalid(I18n::t('Der Titel darf höchstens {max} Zeichen lang sein.', ['max' => $max]));
            }
            $out[$locale] = ['title' => $title];
        }
        return $out;
    }

    /** @param array<string, array<string, string>> $byLocale Sprache => Feld => Text */
    public function saveAll(string $kind, int $id, array $byLocale, int $by): void
    {
        foreach ($byLocale as $locale => $fields) {
            $this->save($kind, $id, $locale, $fields, $by);
        }
    }

    /**
     * Titel in der Sprache der Anfrage (fehlt er dort, der deutsche).
     *
     * @param array<string, array<string, string>> $tr Fassungen des Inhalts
     */
    public function titleIn(array $tr, string $german): string
    {
        $locale = $this->current();
        return $locale !== null && ($tr[$locale]['title'] ?? '') !== '' ? $tr[$locale]['title'] : $german;
    }

    /**
     * Titel aller eingeschalteten weiteren Sprachen für die Pflege ('' = deutsch).
     *
     * @param array<string, array<string, string>> $tr
     */
    public function titlesSource(array $tr): object
    {
        $out = [];
        foreach ($this->locales() as $locale) {
            $out[$locale] = ['title' => $tr[$locale]['title'] ?? ''];
        }
        return (object) $out;
    }

    /** Hinweis für die Antwort aus übersetzbaren und übersetzten Feldern: null (alles da), NONE oder PARTLY. */
    public static function untranslated(int $total, int $done): ?string
    {
        return $total === 0 || $done >= $total ? null : ($done === 0 ? self::NONE : self::PARTLY);
    }
}
