<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Storage\EventResponseRepository;

/**
 * Zu- und Absagen bei Terminen des internen Kalenders, bei jeder Quelle (App, Google, iCal).
 *
 * - Zusagen oder absagen darf, wer respond_calendar hat (setzt den internen Kalender voraus), mit einem kurzen Hinweis.
 *   Ändern und zurücknehmen bis zum Beginn des Termins; bei Terminen der App bis zum Ende des Anmeldeschlusses
 *   (respond_until), wenn der früher ist. Ganztägige Termine beginnen um 0 Uhr (Zeitzone der Kompanie).
 * - Jeder mit dem internen Kalender sieht bei jedem Termin die eigene Rückmeldung und wie viele zu- und abgesagt haben.
 *   Namen und Hinweise sieht nur, wer Termine pflegen darf (manage_calendar; Admins haben alle Rechte).
 * - Gezählt werden alle, die zusagen dürfen; die Rückmeldungen anderer (z. B. von Personen, denen das Recht inzwischen
 *   fehlt) bleiben gespeichert, zählen aber nicht.
 * - Gespeichert wird je Person und stabiler Kennung des Termins (response_key, sonst id), mit Titel und Datum als
 *   Momentaufnahme. Rückmeldungen zu Terminen, die länger als CalendarService::PAST_DAYS vorbei sind, löscht cleanup().
 */
final class EventResponseService
{
    public const YES = 'yes';
    public const NO = 'no';
    public const STATUSES = [self::YES, self::NO];
    public const NOTE_MAX = 191;
    /** Zahl an der Kachel: Termine, deren Frist in so vielen Tagen endet */
    public const BADGE_DAYS = 30;

    private ?EventResponseRepository $repo = null;

    public function __construct(private readonly App $app)
    {
    }

    public function repository(): EventResponseRepository
    {
        return $this->repo ??= new EventResponseRepository($this->app->db);
    }

    /**
     * Termine des Kalenders mit dem Feld responses für $viewer: eigene Rückmeldung, Zähler, Frist, ob noch änderbar. Ohne
     * Anmeldung (Gast mit dem internen Kalender) nur die Zähler.
     *
     * @param array<int, array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    public function annotate(?User $viewer, array $events): array
    {
        $byEvent = $this->repository()->forEvents(array_map(self::key(...), array_values($events)));
        $audience = $this->audience();
        $mayRespond = $viewer !== null && $viewer->id > 0 && $viewer->can(Capability::RespondCalendar);
        $mayList = $viewer !== null && $viewer->can(Capability::ManageCalendar);
        $now = $this->app->time()->timestamp();
        $out = [];
        foreach ($events as $e) {
            $rows = $byEvent[self::key($e)] ?? [];
            $mine = null;
            $counts = [self::YES => 0, self::NO => 0];
            foreach ($rows as $r) {
                if ($viewer !== null && $r['user_id'] === $viewer->id) {
                    $mine = $r;
                }
                if (isset($audience[$r['user_id']]) && isset($counts[$r['status']])) {
                    $counts[$r['status']]++;
                }
            }
            $deadline = $this->deadline($e);
            unset($e['response_key']);
            $e['responses'] = [
                'mine' => $mine['status'] ?? null,
                'note' => $mine['note'] ?? '',
                'yes' => $counts[self::YES],
                'no' => $counts[self::NO],
                'until' => $this->iso($deadline),
                'open' => $now < $deadline,
                'can_respond' => $mayRespond,
                'can_list' => $mayList,
            ];
            $out[] = $e;
        }
        return $out;
    }

    /**
     * Eigene Zu- oder Absage setzen; Antwort: der Termin mit responses wie in annotate().
     *
     * @return array<string, mixed>
     */
    public function respond(User $user, string $id, mixed $status, mixed $note): array
    {
        if (!is_string($status) || !in_array($status, self::STATUSES, true)) {
            throw ApiError::invalid('Bitte zusagen oder absagen.');
        }
        $note = is_string($note) ? Text::line($note) : '';
        if (mb_strlen($note) > self::NOTE_MAX) {
            throw ApiError::invalid('Der Hinweis ist zu lang (höchstens ' . self::NOTE_MAX . ' Zeichen).');
        }
        $event = $this->openEvent($id);
        $this->repository()->save(
            self::key($event),
            $user->id,
            $status,
            $note,
            mb_substr(Text::line((string) $event['title']), 0, 191),
            $this->localDate((string) $event['start']),
            $user->id,
            $this->app->time()->nowUtc(),
        );
        return $this->annotate($user, [$event])[0];
    }

    /**
     * Eigene Rückmeldung zurücknehmen (wieder „keine Rückmeldung“).
     *
     * @return array<string, mixed>
     */
    public function withdraw(User $user, string $id): array
    {
        $event = $this->openEvent($id);
        $this->repository()->delete(self::key($event), $user->id);
        return $this->annotate($user, [$event])[0];
    }

    /**
     * Wer zu- oder abgesagt hat und wer sich noch nicht gemeldet hat (für alle, die Termine pflegen dürfen).
     *
     * @return array{event: array<string, mixed>, yes: list<array{id: int, name: string, note: string}>, no: list<array{id: int, name: string, note: string}>, none: list<array{id: int, name: string}>}
     */
    public function overview(User $viewer, string $id): array
    {
        $event = $this->event($id);
        $rows = [];
        foreach ($this->repository()->forEvents([self::key($event)])[self::key($event)] ?? [] as $r) {
            $rows[$r['user_id']] = $r;
        }
        $out = ['event' => $this->annotate($viewer, [$event])[0], 'yes' => [], 'no' => [], 'none' => []];
        foreach ($this->audience() as $u) {
            $r = $rows[$u->id] ?? null;
            if ($r === null || !in_array($r['status'], self::STATUSES, true)) {
                $out['none'][] = ['id' => $u->id, 'name' => $u->name];
            } else {
                $out[$r['status']][] = ['id' => $u->id, 'name' => $u->name, 'note' => $r['note']];
            }
        }
        return $out;
    }

    /**
     * Für eine andere Person zu- oder absagen bzw. ihre Rückmeldung entfernen ($status null), z. B. nach einem Anruf (wer
     * die Anwesenheit erfasst). Es gilt dieselbe Frist; ein vorhandener Hinweis bleibt.
     *
     * @return array{user_id: int, status: string|null, note: string}
     */
    public function respondFor(User $actor, string $id, int $userId, mixed $status): array
    {
        if ($status !== null && (!is_string($status) || !in_array($status, self::STATUSES, true))) {
            throw ApiError::invalid('Bitte zusagen, absagen oder die Rückmeldung entfernen.');
        }
        if (!isset($this->audience()[$userId])) {
            throw ApiError::invalid('Diese Person kann bei Terminen nicht zu- oder absagen.');
        }
        $event = $this->openEvent($id);
        $key = self::key($event);
        if ($status === null) {
            $this->repository()->delete($key, $userId);
            return ['user_id' => $userId, 'status' => null, 'note' => ''];
        }
        $note = $this->repository()->find($key, $userId)['note'] ?? '';
        $this->repository()->save(
            $key,
            $userId,
            $status,
            $note,
            mb_substr(Text::line((string) $event['title']), 0, 191),
            $this->localDate((string) $event['start']),
            $actor->id,
            $this->app->time()->nowUtc(),
        );
        return ['user_id' => $userId, 'status' => $status, 'note' => $note];
    }

    /**
     * Rückmeldungen zu einem Termin für seine Anwesenheitsliste: ob es den Termin noch gibt, ob die Frist läuft, wer
     * zu- und absagen darf und wer wie geantwortet hat. Ohne den Termin (gelöscht, Kalender gestört) null.
     *
     * @return array{open: bool, audience: array<int, true>, rows: array<int, array{status: string, note: string}>}|null
     */
    public function forAttendance(string $id): ?array
    {
        try {
            $event = $this->event($id);
        } catch (ApiError) {
            return null;
        }
        $rows = [];
        foreach ($this->repository()->forEvents([self::key($event)])[self::key($event)] ?? [] as $r) {
            if (in_array($r['status'], self::STATUSES, true)) {
                $rows[$r['user_id']] = ['status' => $r['status'], 'note' => $r['note']];
            }
        }
        return [
            'open' => $this->app->time()->timestamp() < $this->deadline($event),
            'audience' => array_map(static fn (): bool => true, $this->audience()),
            'rows' => $rows,
        ];
    }

    /**
     * Kommende Termine der nächsten BADGE_DAYS Tage, bei denen $user noch zu- oder absagen kann und es noch nicht getan
     * hat (Zahl an der Kachel des Kalenders). Wartet nicht auf den Kalender: ohne gespeicherte Fassung null.
     */
    public function awaitingCount(User $user): ?int
    {
        if ($user->id <= 0 || !$user->can(Capability::RespondCalendar)) {
            return null;
        }
        $events = $this->app->calendar()->cached();
        if ($events === null) {
            return null;
        }
        $now = $this->app->time()->timestamp();
        $until = $now + self::BADGE_DAYS * 86400;
        $keys = [];
        foreach ($events as $e) {
            $deadline = $this->deadline($e);
            if ($deadline > $now && $deadline <= $until) {
                $keys[] = self::key($e);
            }
        }
        if ($keys === []) {
            return 0;
        }
        $answered = array_flip($this->repository()->keysOfUser($user->id, $keys));
        return count(array_filter(array_unique($keys), static fn (string $k): bool => !isset($answered[$k])));
    }

    /** Stabile Kennung, unter der die Rückmeldungen zu diesem Termin gespeichert sind (für das Änderungsprotokoll). */
    public function keyOf(string $id): ?string
    {
        try {
            return self::key($this->event($id));
        } catch (ApiError) {
            return null;
        }
    }

    /** Rückmeldungen zu Terminen löschen, die länger als CalendarService::PAST_DAYS vorbei sind. */
    public function cleanup(): int
    {
        $before = $this->app->time()->today()->modify('-' . CalendarService::PAST_DAYS . ' days')->format('Y-m-d');
        return $this->repository()->deleteBefore($before);
    }

    /**
     * Ende der Frist als Unix-Zeit: der Beginn des Termins, bei einem früheren Anmeldeschluss das Ende dieses Tages.
     *
     * @param array<string, mixed> $e
     */
    public function deadline(array $e): int
    {
        $tz = $this->app->time()->timezone();
        $start = (string) $e['start'];
        $begin = strlen($start) === 10
            ? (new \DateTimeImmutable($start, $tz))->getTimestamp()
            : (int) strtotime($start);
        $until = $e['respond_until'] ?? null;
        if (is_string($until) && Input::isDate($until)) {
            $end = (new \DateTimeImmutable($until, $tz))->modify('+1 day')->getTimestamp();
            return min($begin, $end);
        }
        return $begin;
    }

    /**
     * Alle, die zusagen dürfen, nach ID.
     *
     * @return array<int, User>
     */
    private function audience(): array
    {
        $out = [];
        // Mit Gästen: Gibt der Admin Gästen das Recht, zählen ihre Rückmeldungen mit.
        foreach ($this->app->users->list(true) as $u) {
            if ($u->can(Capability::RespondCalendar)) {
                $out[$u->id] = $u;
            }
        }
        return $out;
    }

    /**
     * Ein Termin des internen Kalenders nach seiner Kennung (wie in GET /calendar).
     *
     * @return array<string, mixed>
     */
    private function event(string $id): array
    {
        foreach ($this->app->calendar()->events() as $e) {
            if ((string) $e['id'] === $id) {
                return $e;
            }
        }
        throw ApiError::notFound('Diesen Termin gibt es im Kalender nicht (mehr).');
    }

    /**
     * Ein Termin, bei dem man noch zu- oder absagen kann.
     *
     * @return array<string, mixed>
     */
    private function openEvent(string $id): array
    {
        $event = $this->event($id);
        if ($this->app->time()->timestamp() >= $this->deadline($event)) {
            throw new ApiError(409, 'responses_closed', 'Für diesen Termin kannst du nicht mehr zu- oder absagen.');
        }
        return $event;
    }

    /** @param array<string, mixed> $e */
    private static function key(array $e): string
    {
        return (string) ($e['response_key'] ?? $e['id']);
    }

    private function localDate(string $start): string
    {
        if (strlen($start) === 10) {
            return $start;
        }
        return (new \DateTimeImmutable($start))->setTimezone($this->app->time()->timezone())->format('Y-m-d');
    }

    private function iso(int $ts): string
    {
        return (new \DateTimeImmutable('@' . $ts))->setTimezone($this->app->time()->timezone())->format('c');
    }
}
