<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain\Blocks;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\User;

/**
 * Nächste Termine aus dem Bereich Termine: die nächsten count (1 bis MAX) ab heute, immer aktuell. Ist der Bereich aus,
 * fällt der Block weg (außer für die Pflege); wer die Termine nicht sehen darf (view_events), bekommt eine leere Liste.
 */
final class EventsBlock implements BlockType
{
    public const TYPE = 'events';
    public const TITLE_MAX = 100;
    public const MAX = 20;
    public const DEFAULT = 3;

    public function __construct(private readonly App $app)
    {
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function validate(array $in): array
    {
        return [
            'title' => Field::line($in['title'] ?? '', self::TITLE_MAX, 'Die Überschrift der Termine'),
            'count' => Field::int($in['count'] ?? null, 1, self::MAX, self::DEFAULT, 'Wie viele Termine'),
        ];
    }

    public function render(array $data, ?User $viewer, bool $manager): ?array
    {
        if (!$manager && !$this->app->features()->enabled('events')) {
            return null;
        }
        $events = array_map(static fn (array $e): array => [
            'id' => $e['id'],
            'title' => $e['title'],
            'start_date' => $e['start_date'],
            'start_time' => $e['start_time'],
            'end_date' => $e['end_date'],
            'end_time' => $e['end_time'],
            'all_day' => $e['all_day'],
            'start' => $e['start'],
            'end' => $e['end'],
            'location' => $e['location'],
        ], $this->events($data, $viewer));
        return ['title' => (string) ($data['title'] ?? ''), 'count' => (int) ($data['count'] ?? self::DEFAULT), 'events' => $events];
    }

    /** Je Termin eine Zeile „TT.MM.JJJJ, HH:MM Uhr: Titel (Ort)“, mit der Überschrift davor. */
    public function plain(array $data, ?User $viewer = null): string
    {
        $lines = [];
        foreach ($this->events($data, $viewer) as $e) {
            $when = implode('.', array_reverse(explode('-', (string) $e['start_date']))) . ($e['start_time'] !== null ? ', ' . $e['start_time'] . ' Uhr' : '');
            $lines[] = $when . ': ' . $e['title'] . ((string) $e['location'] !== '' ? ' (' . $e['location'] . ')' : '');
        }
        if ($lines === []) {
            return '';
        }
        $title = (string) ($data['title'] ?? '');
        return ($title !== '' ? $title . "\n" : '') . implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    private function events(array $data, ?User $viewer): array
    {
        $who = $viewer ?? $this->app->anonymous();
        if (!$this->app->features()->enabled('events') || !($who->can(Capability::ViewEvents) || $who->can(Capability::ManageEvents))) {
            return [];
        }
        return array_values($this->app->events()->upcoming(max(1, min(self::MAX, (int) ($data['count'] ?? self::DEFAULT)))));
    }
}
