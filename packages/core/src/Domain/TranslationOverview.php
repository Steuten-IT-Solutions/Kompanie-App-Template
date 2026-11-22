<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\I18n\I18n;

/**
 * Alle Übersetzungen an einer Stelle (Einstellungen → Sprachen → Übersetzungen): je Art die Einträge mit dem Text der
 * Basissprache (heute immer Deutsch), dem eingetragenen Text der gewählten Sprache und dem, was Leser ohne Eintrag sehen
 * (fallback, z. B. die übersetzte Vorgabe „Guest“ für „Gast“). Jede Art erscheint nur mit dem Recht, das sie zum Pflegen
 * verlangt (TranslationService::KINDS), und nur, wenn ihr Bereich an ist. Gespeichert wird über PUT /admin/translations.
 */
final class TranslationOverview
{
    /** Feste Bereiche der Navigation und die Bereiche (Features), von denen einer an sein muss. */
    private const NAV_FEATURES = [
        'termine' => ['events', 'calendar', 'birthdays'],
        'news' => ['news'],
        'galerie' => ['gallery'],
        'kompanien' => ['companies'],
        'sponsoren' => ['sponsors'],
        'mitglieder' => ['directory', 'offices'],
        'dokumente' => ['documents'],
        'brett' => ['board'],
        'dienstgrade' => ['ranks'],
        'punktekatalog' => ['points'],
    ];

    /** Abschnitt in kompanie.json je festem Bereich (KompanieConfig::configuredSectionLabel). */
    private const NAV_SECTIONS = [
        'termine' => 'events', 'news' => 'news', 'galerie' => 'gallery', 'kompanien' => 'companies', 'sponsoren' => 'sponsors',
        'dokumente' => 'documents', 'brett' => 'board', 'dienstgrade' => 'ranks', 'punktekatalog' => 'catalog',
    ];

    public function __construct(private readonly App $app)
    {
    }

    /**
     * @return array{locale: string, source_locale: string, groups: list<array{kind: string, items: list<array{kind: string, ref: string, field: string, source: string, text: string, fallback: string, max: int}>}>}
     */
    public function describe(User $user, mixed $locale): array
    {
        $extra = array_values(array_diff($this->app->languages()->enabled(), [I18n::SOURCE_LOCALE]));
        if ($extra === []) {
            throw ApiError::invalid(I18n::t('Schalte zuerst eine weitere Sprache ein.'));
        }
        $locale = is_string($locale) && $locale !== '' ? $locale : $extra[0];
        if (!in_array($locale, $extra, true)) {
            throw ApiError::invalid(I18n::t('Diese Sprache ist in der App nicht eingeschaltet.'));
        }
        $saved = $this->app->translations()->repository()->all(array_keys(TranslationService::KINDS))[$locale] ?? [];
        $groups = [];
        foreach (TranslationService::KINDS as $kind => [$capability, , $max]) {
            if (!$user->can($capability)) {
                continue;
            }
            $entries = $this->entries($kind, $locale);
            if ($entries === null) {
                continue;
            }
            $items = [];
            foreach ($entries as [$ref, $source, $fallback]) {
                $items[] = [
                    'kind' => $kind,
                    'ref' => $ref,
                    'field' => 'label',
                    'source' => $source,
                    'text' => $saved[$kind][$ref]['label'] ?? '',
                    'fallback' => $fallback,
                    'max' => $max,
                ];
            }
            $groups[] = ['kind' => $kind, 'items' => $items];
        }
        return ['locale' => $locale, 'source_locale' => I18n::SOURCE_LOCALE, 'groups' => $groups];
    }

    /**
     * Einträge einer Art: Kennung, Text der Basissprache, Text ohne Übersetzung in $locale. null, wenn es die Art hier
     * nicht gibt (Bereich aus).
     *
     * @return list<array{0: string, 1: string, 2: string}>|null
     */
    private function entries(string $kind, string $locale): ?array
    {
        $features = $this->app->features();
        return match ($kind) {
            'nav' => $this->navEntries($locale),
            'menu' => $this->menuEntries(),
            'role' => array_values(array_map(fn (array $r): array => [
                $r['key'],
                $r['label'],
                I18n::in($locale, fn (): string => $this->app->config->localized('roles', $r['key'], $r['label'])),
            ], $this->app->roles()->all())),
            'rank' => $features->enabled('ranks')
                ? array_map(static fn (array $r): array => [(string) $r['id'], (string) $r['title'], (string) $r['title']], $this->app->ranks()->list(true))
                : null,
            'office_group' => $features->enabled('offices')
                ? array_map(static fn (array $g): array => [(string) $g['id'], (string) $g['title'], (string) $g['title']], $this->app->offices()->repository()->groups())
                : null,
            'office' => $features->enabled('offices')
                ? array_map(static fn (array $o): array => [(string) $o['id'], (string) $o['title'], (string) $o['title']], $this->app->offices()->repository()->offices())
                : null,
            'event_kind' => array_map(static fn (string $k): array => [$k, $k, $k], $this->app->eventKinds()->options()),
            default => null,
        };
    }

    /**
     * Bereiche der Navigation, die an sind, eigene Seiten und „Intern“: eigener Name, sonst der Name aus kompanie.json
     * bzw. der Titel der Seite.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function navEntries(string $locale): array
    {
        $features = $this->app->features();
        $names = $this->app->navigation()->names();
        $config = $this->app->config;
        $out = [];
        $add = static function (string $key, string $standard, string $standardIn) use ($names, &$out): void {
            $own = $names[$key]['label'] ?? '';
            $out[] = [$key, $own !== '' ? $own : $standard, $own !== '' ? $own : $standardIn];
        };
        foreach (self::NAV_FEATURES as $key => $needs) {
            if (array_filter($needs, $features->enabled(...)) === []) {
                continue;
            }
            $section = $key === 'mitglieder'
                ? ($features->enabled('directory') || !$features->enabled('offices') ? 'directory' : 'offices')
                : self::NAV_SECTIONS[$key];
            $add(
                $key,
                I18n::in(I18n::SOURCE_LOCALE, static fn (): string => $config->configuredSectionLabel($section)),
                I18n::in($locale, static fn (): string => $config->configuredSectionLabel($section)),
            );
        }
        if ($features->enabled('pages')) {
            $tr = $this->app->contentTranslations();
            $rows = $this->app->db->select('SELECT id, title FROM {app_pages} ORDER BY sort ASC, id ASC');
            $pageTr = $tr->ofMany(PageService::KIND, array_map(static fn (array $r): int => (int) $r['id'], $rows));
            foreach ($rows as $r) {
                $title = (string) $r['title'];
                $titleIn = ($pageTr[(int) $r['id']][$locale]['title'] ?? '') !== '' ? $pageTr[(int) $r['id']][$locale]['title'] : $title;
                $add(NavigationService::PAGE_PREFIX . $r['id'], $title, $titleIn);
            }
        }
        $add(
            NavigationService::INTERN,
            I18n::in(I18n::SOURCE_LOCALE, static fn (): string => $config->configuredSectionLabel('intern')),
            I18n::in($locale, static fn (): string => $config->configuredSectionLabel('intern')),
        );
        return $out;
    }

    /**
     * Untermenüs der Leisten für Angemeldete und für Gäste (Kennung ist ihr Name), jedes einmal.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function menuEntries(): array
    {
        $nav = $this->app->navigation();
        $labels = [];
        foreach ([$nav->get(), $nav->guest()] as $setting) {
            foreach ($setting['items'] ?? [] as $item) {
                if (is_array($item) && trim($item['label']) !== '') {
                    $labels[trim($item['label'])] = true;
                }
            }
        }
        return array_map(static fn (string $l): array => [$l, $l, $l], array_map('strval', array_keys($labels)));
    }
}
