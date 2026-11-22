<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Domain\Blocks\Blocks;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\I18n\I18n;

/**
 * Alle Übersetzungen an einer Stelle (Einstellungen → Sprachen → Übersetzungen): je Art die Einträge mit dem Text der
 * Basissprache (heute immer Deutsch), dem eingetragenen Text der gewählten Sprache und dem, was Leser ohne Eintrag sehen
 * (fallback, z. B. die übersetzte Vorgabe „Guest“ für „Gast“). Jede Art erscheint nur mit dem Recht, das sie zum Pflegen
 * verlangt (TranslationService::KINDS), und nur, wenn ihr Bereich an ist. Gespeichert wird über PUT /admin/translations.
 *
 * Dazu Inhalte mit einfachen Feldern (CONTENT): kommende Termine (öffentlich und im internen Kalender in der App),
 * laufende Umfragen samt Antwortmöglichkeiten, Dokumente und ihre Kategorien, Alben der Galerie. Je Inhalt und Feld ein
 * Eintrag mit context (deutscher Titel, bei Terminen mit Datum), label (Name des Felds) und multiline. Gespeichert wird
 * über die Routen der Inhalte (translations im PATCH), damit Prüfung, Änderungsprotokoll und Benachrichtigungen dieselben
 * bleiben. Eigene Seiten und laufende Nachrichten am Brett (BLOCKS) je Inhalt mit Stand. News fehlen hier bewusst: Sie
 * werden beim Schreiben übersetzt.
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

    /** Inhalt => Rechte (eines reicht) */
    private const CONTENT = [
        EventService::KIND => [Capability::ManageEvents],
        EventService::KIND_INTERNAL => [Capability::ManageCalendar],
        PollService::KIND => [Capability::ManagePolls],
        DocumentService::KIND => [Capability::ManageDocuments],
        DocumentService::KIND_CATEGORY => [Capability::ManageDocuments],
        GalleryUploadService::KIND => [Capability::ManageSettings, Capability::ManageGallery],
    ];

    /**
     * Inhalte aus Blöcken => Rechte (alle nötig): je Inhalt ein Eintrag mit Stand (total, done); übersetzt wird Block für
     * Block in einem eigenen Formular (Route des Inhalts). Am Brett braucht es zum Lesen der Fassungen auch den Verlauf.
     */
    private const BLOCKS = [
        PageService::KIND => [Capability::ManagePages],
        BoardService::KIND => [Capability::PostBoard, Capability::ViewBoardHistory],
    ];

    /** Längste Texte der Felder (wie die Prüfung der Inhalte) */
    private const EVENT_TITLE_MAX = 191;
    private const EVENT_LOCATION_MAX = 191;

    public function __construct(private readonly App $app)
    {
    }

    /**
     * @return array{locale: string, source_locale: string, groups: list<array{kind: string, items: list<array<string, mixed>>}>}
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
        foreach (self::CONTENT as $kind => $capabilities) {
            if (array_filter($capabilities, $user->can(...)) === []) {
                continue;
            }
            $items = $this->contentItems($kind, $locale);
            if ($items !== null) {
                $groups[] = ['kind' => $kind, 'items' => $items];
            }
        }
        foreach (self::BLOCKS as $kind => $capabilities) {
            if (count(array_filter($capabilities, $user->can(...))) !== count($capabilities)) {
                continue;
            }
            $items = $this->blockItems($kind, $locale);
            if ($items !== null) {
                $groups[] = ['kind' => $kind, 'items' => $items];
            }
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
     * Einträge eines Inhalts mit einfachen Feldern; null, wenn der Bereich aus ist.
     *
     * @return list<array<string, mixed>>|null
     */
    private function contentItems(string $kind, string $locale): ?array
    {
        $features = $this->app->features();
        $today = $this->app->time()->today()->format('Y-m-d');
        $rows = match ($kind) {
            EventService::KIND => $features->enabled('events') ? $this->events('events', $today) : null,
            EventService::KIND_INTERNAL => $features->enabled('calendar') && $this->app->calendar()->inApp() ? $this->events('internal_events', $today) : null,
            PollService::KIND => $features->enabled('polls') ? $this->polls() : null,
            DocumentService::KIND => $features->enabled('documents')
                ? array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'context' => (string) $r['title'], 'fields' => ['title' => (string) $r['title']]], $this->app->db->select('SELECT id, title FROM {documents} ORDER BY title ASC, id ASC'))
                : null,
            DocumentService::KIND_CATEGORY => $features->enabled('documents')
                ? array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'context' => (string) $r['title'], 'fields' => ['title' => (string) $r['title']]], $this->app->db->select('SELECT id, title FROM {doc_categories} ORDER BY sort ASC, id ASC'))
                : null,
            GalleryUploadService::KIND => $features->enabled('gallery')
                ? array_values(array_map(static fn (array $a): array => ['id' => (int) $a['id'], 'context' => trim($a['title'] . ' ' . $a['year']), 'fields' => ['title' => (string) $a['title']]], array_filter($this->app->galleryUploads()->albums(), static fn (array $a): bool => (bool) $a['editable'] && $a['title'] !== '')))
                : null,
            default => null,
        };
        if ($rows === null) {
            return null;
        }
        $saved = $this->app->translations()->repository()->forRefs($kind, array_map(static fn (array $r): string => (string) $r['id'], $rows));
        $items = [];
        foreach ($rows as $r) {
            $tr = $saved[(string) $r['id']][$locale] ?? [];
            $options = isset($tr['options']) ? json_decode($tr['options'], true) : null;
            foreach ($r['fields'] as $field => $source) {
                if ($source === '') {
                    continue;
                }
                $option = str_starts_with($field, 'option:') ? (int) substr($field, 7) : null;
                $text = $option !== null
                    ? (is_array($options) && count($options) === $r['option_count'] && is_string($options[$option] ?? null) ? $options[$option] : '')
                    : ($tr[$field] ?? '');
                $items[] = [
                    'kind' => $kind,
                    'ref' => (string) $r['id'],
                    'field' => $field,
                    'source' => $source,
                    'text' => $text,
                    'fallback' => $source,
                    'max' => self::max($kind, $field),
                    'context' => $r['context'],
                    'label' => self::label($kind, $field),
                    'multiline' => $field === 'description',
                ];
            }
        }
        return $items;
    }

    /**
     * Eigene Seiten bzw. laufende Nachrichten am Brett: je Inhalt ein Eintrag (field content) mit deutschem Titel,
     * übersetztem Titel und dem Stand der Fassung (übersetzbare Felder samt Titel, davon übersetzt). null, wenn der
     * Bereich aus ist.
     *
     * @return list<array<string, mixed>>|null
     */
    private function blockItems(string $kind, string $locale): ?array
    {
        $features = $this->app->features();
        $rows = match ($kind) {
            PageService::KIND => $features->enabled('pages')
                ? $this->app->db->select('SELECT id, title, body, blocks FROM {app_pages} ORDER BY sort ASC, id ASC')
                : null,
            BoardService::KIND => $features->enabled('board')
                ? $this->app->board()->repository()->active($this->app->time()->nowUtc())
                : null,
            default => null,
        };
        if ($rows === null) {
            return null;
        }
        $saved = $this->app->translations()->repository()->forRefs($kind, array_map(static fn (array $r): string => (string) $r['id'], $rows));
        $items = [];
        foreach ($rows as $r) {
            $fields = $saved[(string) $r['id']][$locale] ?? [];
            $title = (string) $r['title'];
            $german = Blocks::decode($r['blocks'] ?? null, (string) ($r['body'] ?? ''));
            [, , $total, $done] = ContentTranslations::blocksIn($title, $german, $fields);
            $items[] = [
                'kind' => $kind,
                'ref' => (string) $r['id'],
                'field' => 'content',
                'source' => $title,
                'text' => $fields['title'] ?? '',
                'fallback' => $title,
                'max' => $kind === PageService::KIND ? PageService::TITLE_MAX : BoardService::TRANSLATION_TITLE_MAX,
                'context' => $title,
                'label' => I18n::t('Inhalt'),
                'multiline' => false,
                'total' => $total,
                'done' => $done,
            ];
        }
        return $items;
    }

    /**
     * Kommende Termine (Ende oder Beginn ab heute), nach Beginn.
     *
     * @return list<array{id: int, context: string, fields: array<string, string>}>
     */
    private function events(string $table, string $today): array
    {
        $rows = $this->app->db->select(
            'SELECT id, title, start_date, end_date, location, description FROM {' . $table . '} WHERE COALESCE(end_date, start_date) >= ? ORDER BY start_date ASC, id ASC',
            [$today],
        );
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'context' => trim($r['title'] . ' · ' . (string) $r['start_date']),
            'fields' => ['title' => (string) $r['title'], 'location' => (string) $r['location'], 'description' => (string) $r['description']],
        ], $rows);
    }

    /**
     * Laufende Umfragen (nicht beendet), dazu ihre Antwortmöglichkeiten als Felder option:<Nummer ab 0>.
     *
     * @return list<array{id: int, context: string, fields: array<string, string>, option_count: int}>
     */
    private function polls(): array
    {
        $now = $this->app->time()->nowUtc();
        $rows = $this->app->db->select('SELECT id, title, description FROM {polls} WHERE closed_at IS NULL AND (ends_at IS NULL OR ends_at > ?) ORDER BY id DESC', [$now]);
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $options = [];
        if ($ids !== []) {
            foreach ($this->app->db->select('SELECT poll_id, title FROM {poll_options} WHERE poll_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY sort, id', $ids) as $o) {
                $options[(int) $o['poll_id']][] = (string) $o['title'];
            }
        }
        return array_map(static function (array $r) use ($options): array {
            $fields = ['title' => (string) $r['title'], 'description' => (string) $r['description']];
            foreach ($options[(int) $r['id']] ?? [] as $i => $title) {
                $fields['option:' . $i] = $title;
            }
            return ['id' => (int) $r['id'], 'context' => (string) $r['title'], 'fields' => $fields, 'option_count' => count($options[(int) $r['id']] ?? [])];
        }, $rows);
    }

    private static function max(string $kind, string $field): int
    {
        return match (true) {
            $kind === PollService::KIND && $field === 'title' => PollService::TITLE_MAX,
            $kind === PollService::KIND && $field === 'description' => PollService::DESCRIPTION_MAX,
            $kind === PollService::KIND => PollService::OPTION_MAX,
            $field === 'description' => EventService::MAX_DESCRIPTION,
            $field === 'location' => self::EVENT_LOCATION_MAX,
            $kind === DocumentService::KIND || $kind === DocumentService::KIND_CATEGORY => DocumentService::TITLE_MAX,
            $kind === GalleryUploadService::KIND => GalleryUploadService::TITLE_MAX,
            default => self::EVENT_TITLE_MAX,
        };
    }

    /** Name des Felds in der Sprache der Anfrage. */
    private static function label(string $kind, string $field): string
    {
        if (str_starts_with($field, 'option:')) {
            return I18n::t('Antwort {n}', ['n' => (int) substr($field, 7) + 1]);
        }
        return match ($field) {
            'title' => $kind === PollService::KIND ? I18n::t('Frage') : ($kind === DocumentService::KIND_CATEGORY ? I18n::t('Bezeichnung') : I18n::t('Titel')),
            'location' => I18n::t('Ort'),
            default => I18n::t('Beschreibung'),
        };
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
