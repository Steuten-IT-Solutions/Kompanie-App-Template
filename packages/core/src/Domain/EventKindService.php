<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Storage\EventKindRepository;

/**
 * Terminarten (z. B. Versammlung, Arbeitsdienst), etwa als Voraussetzung für die Aufnahme von Anwärtern.
 *
 * - Welche Arten es gibt, legt der Admin fest (Einstellungen → Kalender, OPTIONS_SETTING, Vorgabe leer). Gespeichert wird
 *   die Bezeichnung; wird eine Art aus der Liste entfernt, behalten Termine und Listen ihren Eintrag.
 * - Die Art steht am Termin des internen Kalenders, bei jeder Quelle (App, Google, iCal). Die App speichert sie selbst
 *   (Tabelle event_kinds je stabiler Kennung wie bei den Zu- und Absagen), nichts geht zurück an Google. Setzen darf sie,
 *   wer Termine pflegen darf (manage_calendar); sehen jeder, der den Termin sieht.
 * - Ohne gesetzte Art schlägt die App eine vor, wenn ihr Name als eigenes Wort im Titel steht (suggest; „Versammlung März“
 *   → Versammlung). Zählt erst, wenn sie gespeichert ist.
 * - Je Art legt der Admin fest, ob sie als Schild (Tag) neben dem Titel des Termins steht (HIDDEN_TAGS_SETTING: Arten
 *   ohne Schild, Vorgabe leer = alle mit Schild). Das betrifft nur die Anzeige; die Art bleibt gespeichert und zählt.
 * - Jede Anwesenheitsliste hat die Art als eigene Momentaufnahme (AttendanceService): Beim Anlegen übernimmt sie die des
 *   Termins (sonst den Vorschlag aus dem Titel), ändern lässt sie sich an der Liste. Wer die Art eines Termins ändert,
 *   ändert sie auch an dessen Liste. So zählt eine Teilnahme auch, wenn der Termin aus dem Kalender verschwunden ist.
 */
final class EventKindService
{
    public const OPTIONS_SETTING = 'event_kind_options';
    public const HIDDEN_TAGS_SETTING = 'event_kind_hidden_tags';
    public const NAME_MAX = 40;
    public const OPTIONS_MAX = 30;

    private ?EventKindRepository $repo = null;

    public function __construct(private readonly App $app)
    {
    }

    public function repository(): EventKindRepository
    {
        return $this->repo ??= new EventKindRepository($this->app->db);
    }

    /** @return list<string> die einrichtbaren Arten in der gewählten Reihenfolge */
    public function options(): array
    {
        $raw = $this->app->settings->get(self::OPTIONS_SETTING);
        if (!is_array($raw)) {
            return [];
        }
        return array_values(array_filter(array_map(static fn (mixed $x): string => is_string($x) ? $x : '', $raw), static fn (string $x): bool => $x !== ''));
    }

    /**
     * Arten festlegen (Einstellungen → Kalender): Liste von Bezeichnungen, leer erlaubt.
     *
     * @return list<string>
     */
    public function setOptions(mixed $in): array
    {
        if (!is_array($in)) {
            throw ApiError::invalid('Bitte eine Liste von Terminarten schicken.');
        }
        $out = [];
        foreach ($in as $raw) {
            $name = is_string($raw) ? Text::line($raw, self::NAME_MAX + 1) : '';
            if ($name === '') {
                continue;
            }
            if (mb_strlen($name) > self::NAME_MAX) {
                throw ApiError::invalid(sprintf('Eine Terminart darf höchstens %d Zeichen lang sein.', self::NAME_MAX));
            }
            if (in_array(mb_strtolower($name), array_map('mb_strtolower', $out), true)) {
                throw ApiError::invalid(sprintf('Die Terminart „%s“ steht schon in der Liste.', $name));
            }
            $out[] = $name;
        }
        if (count($out) > self::OPTIONS_MAX) {
            throw ApiError::invalid(sprintf('Es gehen höchstens %d Terminarten.', self::OPTIONS_MAX));
        }
        $this->app->settings->set(self::OPTIONS_SETTING, $out);
        // Arten ohne Schild: nur die, die es noch gibt (eine entfernte und neu angelegte Art hat wieder ein Schild)
        $this->app->settings->set(self::HIDDEN_TAGS_SETTING, array_values(array_intersect($this->hiddenTags(), $out)));
        return $out;
    }

    /** @return list<string> Arten, die nicht als Schild neben dem Titel des Termins stehen (Vorgabe: keine) */
    public function hiddenTags(): array
    {
        $raw = $this->app->settings->get(self::HIDDEN_TAGS_SETTING);
        if (!is_array($raw)) {
            return [];
        }
        $options = $this->options();
        return array_values(array_filter($options, static fn (string $kind): bool => in_array($kind, $raw, true)));
    }

    /**
     * Festlegen, welche Arten kein Schild am Termin haben: Liste von Bezeichnungen aus options(), andere werden ignoriert.
     *
     * @return list<string>
     */
    public function setHiddenTags(mixed $in): array
    {
        if (!is_array($in)) {
            throw ApiError::invalid('Bitte eine Liste von Terminarten ohne Schild schicken.');
        }
        $names = array_map(static fn (mixed $x): string => is_string($x) ? Text::line($x, self::NAME_MAX + 1) : '', $in);
        $hidden = array_values(array_filter($this->options(), static fn (string $kind): bool => in_array($kind, $names, true)));
        $this->app->settings->set(self::HIDDEN_TAGS_SETTING, $hidden);
        return $hidden;
    }

    /**
     * PUT /admin/event-kinds: Liste der Arten und (wenn geschickt) die Arten ohne Schild. Ohne hidden bleiben die bisherigen
     * Arten ohne Schild so (ältere Oberflächen).
     *
     * @return array{kinds: list<string>, hidden: list<string>}
     */
    public function save(mixed $kinds, mixed $hidden): array
    {
        if ($hidden !== null && !is_array($hidden)) {
            throw ApiError::invalid('Bitte eine Liste von Terminarten ohne Schild schicken.');
        }
        $list = $this->setOptions($kinds);
        return ['kinds' => $list, 'hidden' => $hidden === null ? $this->hiddenTags() : $this->setHiddenTags($hidden)];
    }

    /**
     * Vorschlag aus dem Titel: die Art, deren Name als eigenes Wort (Groß- und Kleinschreibung egal) im Titel steht; passen
     * mehrere, die längste. null ohne Treffer.
     */
    public function suggest(string $title): ?string
    {
        $best = null;
        foreach ($this->options() as $kind) {
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($kind, '/') . '(?![\p{L}\p{N}])/iu', $title) === 1
                && ($best === null || mb_strlen($kind) > mb_strlen($best))) {
                $best = $kind;
            }
        }
        return $best;
    }

    /**
     * Prüft eine Art aus einer Eingabe: eine der eingerichteten, leer bzw. null für keine. $current (die bisherige) bleibt
     * erlaubt, auch wenn sie nicht mehr in der Liste steht.
     *
     * @return string '' für keine
     */
    public function valid(mixed $raw, ?string $current = null): string
    {
        if ($raw === null || $raw === '') {
            return '';
        }
        $name = is_string($raw) ? Text::line($raw, self::NAME_MAX + 1) : '';
        if ($name !== '' && ($name === $current || in_array($name, $this->options(), true))) {
            return $name;
        }
        $options = $this->options();
        throw ApiError::invalid($options === []
            ? 'Es sind noch keine Terminarten eingerichtet (Einstellungen → Kalender).'
            : 'Bitte eine der Terminarten wählen: ' . implode(', ', $options) . '.');
    }

    /**
     * Termine mit dem Feld kind (gespeicherte Art oder null); wer Termine pflegen darf, bekommt ohne gesetzte Art den
     * Vorschlag aus dem Titel (kind_suggestion). Die stabile Kennung (response_key) bleibt für EventResponseService.
     *
     * @param array<int, array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    public function annotate(?User $viewer, array $events): array
    {
        $events = array_values($events);
        $kinds = $this->repository()->forKeys(array_map(self::key(...), $events));
        $suggest = $viewer !== null && $viewer->can(Capability::ManageCalendar) && $this->options() !== [];
        $out = [];
        foreach ($events as $e) {
            $e['kind'] = $kinds[self::key($e)] ?? null;
            if ($suggest && $e['kind'] === null) {
                $e['kind_suggestion'] = $this->suggest((string) $e['title']);
            }
            $out[] = $e;
        }
        return $out;
    }

    /** Gespeicherte Art eines Termins (wie ihn CalendarService liefert), null ohne. */
    public function ofEvent(array $event): ?string
    {
        return $this->repository()->forKeys([self::key($event)])[self::key($event)] ?? null;
    }

    /**
     * Art eines Termins setzen ($kind leer bzw. null = keine); $id wie in GET /calendar. Eine Anwesenheitsliste zu dem
     * Termin übernimmt die neue Art.
     *
     * @return array{id: string, kind: string|null}
     */
    public function set(User $actor, string $id, mixed $kind): array
    {
        $event = $this->event($id);
        $key = self::key($event);
        $current = $this->ofEvent($event);
        $name = $this->valid($kind, $current);
        if ($name === '') {
            $this->repository()->delete($key);
        } else {
            $this->repository()->save(
                $key,
                $name,
                mb_substr(Text::line((string) $event['title']), 0, 191),
                $this->localDate((string) $event['start']),
                $actor->id,
                $this->app->time()->nowUtc(),
            );
        }
        $this->app->attendance()->kindOfEventChanged($actor, (string) $event['id'], $name);
        return ['id' => (string) $event['id'], 'kind' => $name === '' ? null : $name];
    }

    /** Kennung der Zeile zu einem Termin (Änderungsprotokoll), null ohne Art oder ohne den Termin. */
    public function rowIdOf(string $id): ?int
    {
        try {
            return $this->repository()->idOf(self::key($this->event($id)));
        } catch (ApiError) {
            return null;
        }
    }

    /** @param array<string, mixed> $e */
    public static function key(array $e): string
    {
        return (string) ($e['response_key'] ?? $e['id']);
    }

    /** @return array<string, mixed> Termin des internen Kalenders nach seiner Kennung (wie in GET /calendar) */
    private function event(string $id): array
    {
        foreach ($this->app->calendar()->events() as $e) {
            if ((string) $e['id'] === $id) {
                return $e;
            }
        }
        throw ApiError::notFound('Diesen Termin gibt es im Kalender nicht (mehr).');
    }

    private function localDate(string $start): string
    {
        if (Input::isDate($start)) {
            return $start;
        }
        $ts = strtotime($start);
        return $ts === false ? substr($start, 0, 10) : $this->app->time()->format($ts, 'Y-m-d');
    }
}
