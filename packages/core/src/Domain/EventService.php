<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Storage\EventRepository;

/**
 * Öffentliche Termine, die Admins in der App pflegen.
 * Datum und Uhrzeit sind getrennt gespeichert, weil viele Termine ganztägig oder mehrtägig sind.
 */
final class EventService
{
    private const FIELDS = ['title', 'start_date', 'start_time', 'end_date', 'end_time', 'location', 'description', 'url'];

    public function __construct(
        private readonly EventRepository $events,
        private readonly Time $time,
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function upcoming(int $limit = 200): array
    {
        $rows = $this->events->upcoming($this->time->today()->format('Y-m-d'), $limit);
        return array_map([$this, 'format'], $rows);
    }

    /** @return array<string, mixed>|null */
    public function get(int $id): ?array
    {
        $row = $this->events->find($id);
        return $row ? $this->format($row) : null;
    }

    /** @param array<string, mixed> $input */
    public function create(array $input, int $userId): array
    {
        $data = $this->validate($input, null);
        $now = $this->time->nowUtc();
        $id = $this->events->insert($data + ['created_by' => $userId, 'created_at' => $now, 'updated_at' => $now]);
        return $this->get($id) ?? [];
    }

    /** @param array<string, mixed> $input */
    public function update(int $id, array $input): array
    {
        $current = $this->events->find($id);
        if (!$current) {
            throw ApiError::notFound('Termin nicht gefunden.');
        }
        $data = $this->validate($input, $current);
        $this->events->update($id, $data + ['updated_at' => $this->time->nowUtc()]);
        return $this->get($id) ?? [];
    }

    public function delete(int $id): void
    {
        if (!$this->events->delete($id)) {
            throw ApiError::notFound('Termin nicht gefunden.');
        }
    }

    /** Nur bekannte Felder aus einer Anfrage übernehmen. */
    public static function input(array $body): array
    {
        $out = [];
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
            throw ApiError::invalid('Bitte einen Titel angeben.');
        }
        if (mb_strlen($title) > 191) {
            throw ApiError::invalid('Der Titel ist zu lang.');
        }

        $startDate = $get('start_date');
        if (!Input::isDate($startDate)) {
            throw ApiError::invalid('Bitte ein gültiges Startdatum angeben.');
        }
        $endDate = $get('end_date');
        if ($endDate !== '' && !Input::isDate($endDate)) {
            throw ApiError::invalid('Das Enddatum ist ungültig.');
        }
        if ($endDate !== '' && $endDate < $startDate) {
            throw ApiError::invalid('Das Enddatum liegt vor dem Startdatum.');
        }

        $startTime = self::normTime($get('start_time'));
        $endTime = self::normTime($get('end_time'));
        if ($startTime === false || $endTime === false) {
            throw ApiError::invalid('Bitte Uhrzeiten im Format HH:MM angeben.');
        }
        if ($startTime === null && $endTime !== null) {
            throw ApiError::invalid('Eine Endzeit braucht auch eine Startzeit.');
        }
        if ($endTime !== null && ($endDate === '' || $endDate === $startDate) && $endTime < $startTime) {
            throw ApiError::invalid('Die Endzeit liegt vor der Startzeit.');
        }

        $url = Input::url($get('url'), 'Der Link muss mit http:// oder https:// beginnen.');

        return [
            'title' => $title,
            'start_date' => $startDate,
            'start_time' => $startTime,
            'end_date' => $endDate === '' || $endDate === $startDate ? null : $endDate,
            'end_time' => $endTime,
            'location' => Text::line($get('location'), 191),
            'description' => Text::multiline($get('description')),
            'url' => mb_substr($url, 0, 255),
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
        ];
    }
}
