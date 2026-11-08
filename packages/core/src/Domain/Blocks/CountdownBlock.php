<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain\Blocks;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Domain\Input;
use KompanieApp\Core\Domain\Text;
use KompanieApp\Core\Http\ApiError;

/**
 * Countdown bis zu einem Zeitpunkt: freies Datum (source date) oder ein Termin aus dem Bereich Termine (source event).
 *
 * - Datum und Uhrzeit gelten in der Zeitzone der Installation (Clock::timezone()); ausgegeben werden starts_at und
 *   ends_at in UTC, damit jedes Gerät gleich zählt. Ohne Uhrzeit: 0:00 Uhr; Ende nur mit Datum: Ende dieses Tages.
 * - Zwischen Beginn und Ende steht running_text („läuft“), danach after_text oder (after hide) nichts mehr.
 * - Bei einem Termin kommen Beginn und Ende aus dem Termin; verschoben zählt der Countdown mit. Gelöscht oder für den
 *   Lesenden nicht sichtbar (Bereich events, view_events): wie nach dem Ende.
 */
final class CountdownBlock implements BlockType
{
    public const TYPE = 'countdown';
    public const TITLE_MAX = 100;
    public const TEXT_MAX = 200;
    public const SOURCES = ['date', 'event'];
    public const AFTER = ['text', 'hide'];

    public function __construct(private readonly App $app)
    {
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function validate(array $in): array
    {
        $source = $in['source'] ?? 'date';
        if (!in_array($source, self::SOURCES, true)) {
            throw ApiError::invalid('Worauf soll der Countdown zählen? Erlaubt sind date (Datum) und event (Termin).');
        }
        $after = $in['after'] ?? 'text';
        if (!in_array($after, self::AFTER, true)) {
            throw ApiError::invalid('Was soll nach dem Countdown passieren? Erlaubt sind text und hide.');
        }
        $out = [
            'title' => $this->line($in['title'] ?? '', self::TITLE_MAX, 'Die Überschrift des Countdowns'),
            'source' => $source,
        ];
        if ($source === 'event') {
            $id = Input::integer($in['event_id'] ?? null);
            if ($id === null || $id <= 0 || $this->app->events()->get($id) === null) {
                throw ApiError::invalid('Bitte einen Termin für den Countdown auswählen.');
            }
            $out['event_id'] = $id;
        } else {
            $date = $in['date'] ?? null;
            if (!Input::isDate($date)) {
                throw ApiError::invalid('Bitte ein Datum für den Countdown angeben.');
            }
            $out['date'] = (string) $date;
            $out['time'] = $this->time($in['time'] ?? null);
            $endDate = $in['end_date'] ?? null;
            if ($endDate !== null && $endDate !== '' && !Input::isDate($endDate)) {
                throw ApiError::invalid('Ungültiges Enddatum.');
            }
            $out['end_date'] = $endDate === '' ? null : $endDate;
            $out['end_time'] = $this->time($in['end_time'] ?? null);
            [$start, $end] = $this->dateRange($out);
            if ($end !== null && $end <= $start) {
                throw ApiError::invalid('Das Ende des Countdowns muss nach dem Beginn liegen.');
            }
        }
        $out['running_text'] = $this->line($in['running_text'] ?? '', self::TEXT_MAX, 'Der Text, solange es läuft,');
        $out['after'] = $after;
        $out['after_text'] = $this->line($in['after_text'] ?? '', self::TEXT_MAX, 'Der Text nach dem Ende');
        return $out;
    }

    public function render(array $data, ?User $viewer, bool $manager): ?array
    {
        [$start, $end, $eventTitle] = $this->range($data, $viewer);
        $out = $data + ['starts_at' => self::iso($start), 'ends_at' => self::iso($end)];
        if (($data['source'] ?? '') === 'event') {
            $out['event_title'] = $eventTitle;
        }
        if (!$manager && ($data['after'] ?? 'text') === 'hide' && $this->phase($start, $end) === 'after') {
            return null;
        }
        return $out;
    }

    public function plain(array $data, ?User $viewer = null): string
    {
        [$start, $end, $eventTitle] = $this->range($data, $viewer);
        $title = (string) ($data['title'] ?? '') !== '' ? (string) $data['title'] : ($eventTitle ?? '');
        return match ($this->phase($start, $end)) {
            'before' => ($title !== '' ? $title . ': ' : 'Countdown bis ') . $this->when($data, $start),
            'running' => (string) ($data['running_text'] ?? '') !== '' ? (string) $data['running_text'] : $title,
            default => ($data['after'] ?? 'text') === 'text' ? (string) ($data['after_text'] ?? '') : '',
        };
    }

    /** before, running oder after (ohne Beginn: after). */
    private function phase(?\DateTimeImmutable $start, ?\DateTimeImmutable $end): string
    {
        if ($start === null) {
            return 'after';
        }
        $now = $this->app->time()->timestamp();
        if ($now < $start->getTimestamp()) {
            return 'before';
        }
        return $end !== null && $now < $end->getTimestamp() ? 'running' : 'after';
    }

    /**
     * Beginn, Ende und (bei einem Termin) dessen Titel für $viewer.
     *
     * @param array<string, mixed> $data
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable, 2: ?string}
     */
    private function range(array $data, ?User $viewer): array
    {
        if (($data['source'] ?? '') !== 'event') {
            [$start, $end] = $this->dateRange($data);
            return [$start, $end, null];
        }
        $who = $viewer ?? $this->app->anonymous();
        $visible = $this->app->features()->enabled('events') && ($who->can(Capability::ViewEvents) || $who->can(Capability::ManageEvents));
        $event = $visible ? $this->app->events()->get((int) ($data['event_id'] ?? 0)) : null;
        if ($event === null) {
            return [null, null, null];
        }
        $tz = $this->app->time()->timezone();
        $start = new \DateTimeImmutable((string) $event['start'], $tz);
        $end = new \DateTimeImmutable((string) $event['end'], $tz);
        return [$start, $end > $start ? $end : null, (string) $event['title']];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{0: \DateTimeImmutable, 1: ?\DateTimeImmutable}
     */
    private function dateRange(array $data): array
    {
        $tz = $this->app->time()->timezone();
        $start = new \DateTimeImmutable($data['date'] . ' ' . ($data['time'] ?? '00:00'), $tz);
        $endDate = $data['end_date'] ?? null;
        $endTime = $data['end_time'] ?? null;
        if ($endDate === null && $endTime === null) {
            return [$start, null];
        }
        if ($endTime === null) {
            // Nur ein Enddatum: bis zum Ende dieses Tages
            return [$start, (new \DateTimeImmutable((string) $endDate, $tz))->modify('+1 day')];
        }
        return [$start, new \DateTimeImmutable(($endDate ?? $data['date']) . ' ' . $endTime, $tz)];
    }

    /** @param array<string, mixed> $data */
    private function when(array $data, \DateTimeImmutable $start): string
    {
        $local = $start->setTimezone($this->app->time()->timezone());
        $withTime = ($data['source'] ?? '') === 'event' ? $local->format('H:i') !== '00:00' : ($data['time'] ?? null) !== null;
        return $local->format('d.m.Y') . ($withTime ? ', ' . $local->format('H:i') . ' Uhr' : '');
    }

    private function time(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) !== 1) {
            throw ApiError::invalid('Ungültige Uhrzeit (Format HH:MM).');
        }
        return $value;
    }

    private function line(mixed $value, int $max, string $what): string
    {
        $s = Text::line($value, $max + 1);
        if (mb_strlen($s) > $max) {
            throw ApiError::invalid(sprintf('%s darf höchstens %d Zeichen lang sein.', $what, $max));
        }
        return $s;
    }

    private static function iso(?\DateTimeImmutable $dt): ?string
    {
        return $dt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
