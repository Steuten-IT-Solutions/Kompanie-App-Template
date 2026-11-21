<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\I18n\I18n;
use KompanieApp\Core\Storage\EventRepository;

/**
 * Termine, die in der App gepflegt werden: die öffentlichen (App::events, Recht manage_events) und, getrennt davon in
 * einer eigenen Tabelle, die des internen Kalenders mit der Quelle „app“ (App::internalEvents, Recht manage_calendar).
 * Datum und Uhrzeit sind getrennt gespeichert, weil viele Termine ganztägig oder mehrtägig sind.
 *
 * Termine des internen Kalenders ($deadline) haben zusätzlich einen optionalen Anmeldeschluss (respond_until, Datum):
 * Zu- und Absagen gehen bis zum Ende dieses Tages, höchstens bis zum Beginn (EventResponseService).
 *
 * Inhalte je Sprache (Plan „Mehrsprachigkeit“, PR 9d, ContentTranslations): je weitere eingeschaltete Sprache ein eigener
 * Titel, Ort und eine eigene Beschreibung (Art $kind, über translations beim Anlegen und Ändern). Gelesen wird in der
 * Sprache der Anfrage, Fehlendes deutsch mit untranslated; zum Bearbeiten ($source) die deutsche Fassung mit translations.
 */
final class EventService
{
    private const FIELDS = ['title', 'start_date', 'start_time', 'end_date', 'end_time', 'location', 'description', 'url', 'respond_until'];
    public const MAX_DESCRIPTION = 10000;
    /** Felder, die es je Sprache gibt */
    public const TRANSLATABLE = ['title', 'location', 'description'];
    /** Art in translations: öffentliche Termine bzw. die des internen Kalenders */
    public const KIND = 'event';
    public const KIND_INTERNAL = 'calendar_entry';

    /** @param (\Closure(): ContentTranslations)|null $content Inhalte je Sprache (ohne: nur Deutsch) */
    public function __construct(
        private readonly EventRepository $events,
        private readonly Time $time,
        private readonly bool $deadline = false,
        private readonly ?\Closure $content = null,
    ) {
    }

    private function kind(): string
    {
        return $this->deadline ? self::KIND_INTERNAL : self::KIND;
    }

    private function content(): ?ContentTranslations
    {
        return $this->content === null ? null : ($this->content)();
    }

    /**
     * Fassungen der Zeilen (ID => Sprache => Feld => Text); leer ohne weitere Sprache.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<int, array<string, array<string, string>>>
     */
    private function translationsOf(array $rows): array
    {
        $content = $this->content();
        if ($content === null || $rows === [] || $content->locales() === []) {
            return [];
        }
        return $content->ofMany($this->kind(), array_map(static fn (array $r): int => (int) $r['id'], $rows));
    }

    /**
     * Zeilen für die Antwort: in der Sprache der Anfrage, mit $source deutsch samt translations.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function formatAll(array $rows, bool $source = false): array
    {
        $tr = $this->translationsOf($rows);
        return array_map(fn (array $r): array => $this->formatIn($r, $tr[(int) $r['id']] ?? [], $source), $rows);
    }

    /** @return array<int, array<string, mixed>> */
    public function upcoming(int $limit = 200, bool $source = false): array
    {
        return $this->formatAll($this->events->upcoming($this->time->today()->format('Y-m-d'), $limit), $source);
    }

    /**
     * Termine, die zwischen zwei Tagen (je einschließlich) liegen oder laufen.
     *
     * @return array<int, array<string, mixed>>
     */
    public function between(string $from, string $to): array
    {
        return $this->formatAll($this->events->between($from, $to));
    }

    /** @return array<string, mixed>|null */
    public function get(int $id, bool $source = false): ?array
    {
        $row = $this->events->find($id);
        return $row ? $this->formatAll([$row], $source)[0] : null;
    }

    /**
     * @param array<string, mixed> $input Felder (FIELDS), dazu translations (Sprache => title, location, description)
     */
    public function create(array $input, int $userId): array
    {
        $data = $this->validate($input, null);
        $translations = $this->translationInput($input['translations'] ?? null);
        $now = $this->time->nowUtc();
        $id = $this->events->insert($data + ['created_by' => $userId, 'created_at' => $now, 'updated_at' => $now]);
        $this->saveTranslations($id, $translations, $userId);
        return $this->get($id, true) ?? [];
    }

    /** @param array<string, mixed> $input wie create(), jeweils optional */
    public function update(int $id, array $input, int $userId = 0): array
    {
        $current = $this->events->find($id);
        if (!$current) {
            throw ApiError::notFound(I18n::t('Termin nicht gefunden.'));
        }
        $data = $this->validate($input, $current);
        $translations = $this->translationInput($input['translations'] ?? null);
        $this->events->update($id, $data + ['updated_at' => $this->time->nowUtc()]);
        $this->saveTranslations($id, $translations, $userId);
        return $this->get($id, true) ?? [];
    }

    public function delete(int $id): void
    {
        if (!$this->events->delete($id)) {
            throw ApiError::notFound(I18n::t('Termin nicht gefunden.'));
        }
        $this->content()?->delete($this->kind(), $id);
    }

    /**
     * Fassungen aus einer Anfrage prüfen: nur weitere eingeschaltete Sprachen, nur TRANSLATABLE, Längen wie auf Deutsch;
     * '' löscht ein Feld.
     *
     * @return array<string, array<string, string>> Sprache => Feld => Text
     */
    private function translationInput(mixed $in): array
    {
        $content = $this->content();
        if ($in === null || $content === null) {
            return [];
        }
        $out = [];
        foreach ($content->input($in) as $locale => $fields) {
            foreach (self::TRANSLATABLE as $field) {
                if (!array_key_exists($field, $fields)) {
                    continue;
                }
                $value = is_scalar($fields[$field]) ? trim((string) $fields[$field]) : '';
                $value = match ($field) {
                    'title' => Text::line($value, 192),
                    'location' => Text::line($value, 191),
                    default => Text::multiline($value, self::MAX_DESCRIPTION),
                };
                if ($field === 'title' && mb_strlen($value) > 191) {
                    throw ApiError::invalid(I18n::t('Der Titel ist zu lang.'));
                }
                $out[$locale][$field] = $value;
            }
        }
        return $out;
    }

    /** @param array<string, array<string, string>> $translations */
    private function saveTranslations(int $id, array $translations, int $userId): void
    {
        foreach ($translations as $locale => $fields) {
            $this->content()?->save($this->kind(), $id, $locale, $fields, $userId);
        }
    }

    /**
     * Termin (wie aus format) mit Titel, Ort und Beschreibung in $locale (Benachrichtigungen); fehlt etwas, bleibt es
     * deutsch.
     *
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    public function inLocale(array $event, string $locale): array
    {
        $content = $this->content();
        if ($content === null || $locale === I18n::SOURCE_LOCALE || !is_numeric($event['id'] ?? null)) {
            return $event;
        }
        foreach ($content->of($this->kind(), (int) $event['id'])[$locale] ?? [] as $field => $text) {
            if (in_array($field, self::TRANSLATABLE, true) && $text !== '' && (string) ($event[$field] ?? '') !== '') {
                $event[$field] = $text;
            }
        }
        return $event;
    }

    /** Nur bekannte Felder aus einer Anfrage übernehmen. */
    public static function input(array $body): array
    {
        // Fassungen je Sprache prüft create/update (translationInput).
        $out = array_key_exists('translations', $body) ? ['translations' => $body['translations']] : [];
        foreach (self::FIELDS as $key) {
            if (array_key_exists($key, $body) && $body[$key] !== null) {
                $out[$key] = is_scalar($body[$key]) ? (string) $body[$key] : '';
            }
        }
        return $out;
    }

    /**
     * Prüft und normalisiert Eingaben. Bei Änderungen werden fehlende Felder aus $current übernommen.
     *
     * @param array<string, mixed> $in
     * @param array<string, mixed>|null $current
     * @return array<string, mixed> Datenbankfelder
     */
    private function validate(array $in, ?array $current): array
    {
        $get = static function (string $key) use ($in, $current): string {
            if (array_key_exists($key, $in) && $in[$key] !== null) {
                return trim((string) $in[$key]);
            }
            return $current !== null && isset($current[$key]) ? (string) $current[$key] : '';
        };

        $title = Text::line($get('title'));
        if ($title === '') {
            throw ApiError::invalid(I18n::t('Bitte einen Titel angeben.'));
        }
        if (mb_strlen($title) > 191) {
            throw ApiError::invalid(I18n::t('Der Titel ist zu lang.'));
        }

        $startDate = $get('start_date');
        if (!Input::isDate($startDate)) {
            throw ApiError::invalid(I18n::t('Bitte ein gültiges Startdatum angeben.'));
        }
        $endDate = $get('end_date');
        if ($endDate !== '' && !Input::isDate($endDate)) {
            throw ApiError::invalid(I18n::t('Das Enddatum ist ungültig.'));
        }
        if ($endDate !== '' && $endDate < $startDate) {
            throw ApiError::invalid(I18n::t('Das Enddatum liegt vor dem Startdatum.'));
        }

        $startTime = self::normTime($get('start_time'));
        $endTime = self::normTime($get('end_time'));
        if ($startTime === false || $endTime === false) {
            throw ApiError::invalid(I18n::t('Bitte Uhrzeiten im Format HH:MM angeben.'));
        }
        if ($startTime === null && $endTime !== null) {
            throw ApiError::invalid(I18n::t('Eine Endzeit braucht auch eine Startzeit.'));
        }
        if ($endTime !== null && ($endDate === '' || $endDate === $startDate) && $endTime < $startTime) {
            throw ApiError::invalid(I18n::t('Die Endzeit liegt vor der Startzeit.'));
        }

        $url = Input::url($get('url'), I18n::t('Der Link muss mit http:// oder https:// beginnen.'));

        $deadline = [];
        if ($this->deadline) {
            $until = $get('respond_until');
            if ($until !== '' && !Input::isDate($until)) {
                throw ApiError::invalid(I18n::t('Der Anmeldeschluss ist ungültig.'));
            }
            if ($until !== '' && $until > $startDate) {
                throw ApiError::invalid(I18n::t('Der Anmeldeschluss liegt nach dem Beginn.'));
            }
            $deadline = ['respond_until' => $until === '' ? null : $until];
        }

        return $deadline + [
            'title' => $title,
            'start_date' => $startDate,
            'start_time' => $startTime,
            'end_date' => $endDate === '' || $endDate === $startDate ? null : $endDate,
            'end_time' => $endTime,
            'location' => Text::line($get('location'), 191),
            'description' => Text::multiline($get('description'), self::MAX_DESCRIPTION),
            'url' => $url,
        ];
    }

    /** '' → null, 'H:MM' oder 'HH:MM(:SS)' → 'HH:MM:00', sonst false. */
    private static function normTime(string $t): string|false|null
    {
        if ($t === '') {
            return null;
        }
        if (!preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $t, $m) || (int) $m[1] > 23 || (int) $m[2] > 59) {
            return false;
        }
        return sprintf('%02d:%02d:00', $m[1], $m[2]);
    }

    /**
     * API-Format. Enthält zusätzlich start/end/all_day im selben Format wie der
     * Kalender, damit die App dieselbe Darstellung nutzt. Ganztägig: Ende exklusiv.
     *
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    public function format(array $r): array
    {
        return $this->formatIn($r, $this->translationsOf([$r])[(int) $r['id']] ?? []);
    }

    /**
     * @param array<string, mixed> $r Zeile
     * @param array<string, array<string, string>> $tr Fassungen (Sprache => Feld => Text)
     * @return array<string, mixed>
     */
    private function formatIn(array $r, array $tr, bool $source = false): array
    {
        $current = $source ? null : $this->content()?->current();
        $untranslated = null;
        if ($current !== null) {
            $total = 0;
            $done = 0;
            foreach (self::TRANSLATABLE as $field) {
                if ((string) ($r[$field] ?? '') === '') {
                    continue;
                }
                $total++;
                $own = $tr[$current][$field] ?? '';
                if ($own !== '') {
                    $r[$field] = $own;
                    $done++;
                }
            }
            $untranslated = ContentTranslations::untranslated($total, $done);
        }
        $tz = $this->time->timezone();
        $allDay = empty($r['start_time']);
        $hm = static fn (?string $t): ?string => $t ? substr($t, 0, 5) : null;
        if ($allDay) {
            $last = $r['end_date'] ?: $r['start_date'];
            $start = (string) $r['start_date'];
            $end = (new \DateTimeImmutable((string) $last, $tz))->modify('+1 day')->format('Y-m-d');
        } else {
            $startDt = new \DateTimeImmutable($r['start_date'] . ' ' . $r['start_time'], $tz);
            $endDay = $r['end_date'] ?: $r['start_date'];
            $endDt = $r['end_time'] ? new \DateTimeImmutable($endDay . ' ' . $r['end_time'], $tz) : $startDt;
            $start = $startDt->format('c');
            $end = $endDt->format('c');
        }
        return [
            'id' => (int) $r['id'],
            'title' => (string) $r['title'],
            'start_date' => (string) $r['start_date'],
            'start_time' => $hm($r['start_time'] ?? null),
            'end_date' => $r['end_date'] ?: null,
            'end_time' => $hm($r['end_time'] ?? null),
            'location' => (string) $r['location'],
            'description' => (string) $r['description'],
            'url' => (string) $r['url'],
            'all_day' => $allDay,
            'start' => $start,
            'end' => $end,
        ] + ($this->deadline ? ['respond_until' => ($r['respond_until'] ?? null) ?: null] : [])
            + ($untranslated !== null ? ['untranslated' => $untranslated] : [])
            + ($source && ($this->content()?->locales() ?? []) !== [] ? ['translations' => self::sourceOf($tr)] : []);
    }

    /**
     * Fassungen zum Bearbeiten: Sprache => title, location, description (fehlende leer).
     *
     * @param array<string, array<string, string>> $tr
     */
    private static function sourceOf(array $tr): object
    {
        $out = [];
        foreach ($tr as $locale => $fields) {
            $out[$locale] = array_merge(array_fill_keys(self::TRANSLATABLE, ''), array_intersect_key($fields, array_flip(self::TRANSLATABLE)));
        }
        return (object) $out;
    }
}
