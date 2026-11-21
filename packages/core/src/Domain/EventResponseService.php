<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\I18n\I18n;
use KompanieApp\Core\Storage\EventResponseRepository;

/**
 * Zu- und Absagen bei Terminen des internen Kalenders, bei jeder Quelle (App, Google, iCal).
 *
 * - Erst nach dem Einschalten (Einstellungen → Kalender, ENABLED_SETTING, Vorgabe aus). Ausgeschaltet gibt es keine
 *   Rückmeldungen am Termin, keine Erinnerung, keinen Vorschlag „entschuldigt“ in der Anwesenheit; schon gespeicherte
 *   Rückmeldungen bleiben für ein erneutes Einschalten erhalten.
 * - Zusagen oder absagen darf, wer respond_calendar hat (setzt den internen Kalender voraus), mit einem kurzen Hinweis.
 *   Ändern und zurücknehmen bis zum Beginn des Termins; bei Terminen der App bis zum Ende des Anmeldeschlusses
 *   (respond_until), wenn der früher ist. Ganztägige Termine beginnen um 0 Uhr (Zeitzone der Kompanie).
 * - Jeder mit dem internen Kalender sieht bei jedem Termin die eigene Rückmeldung und wie viele zu- und abgesagt haben.
 *   Namen und Hinweise sieht nur, wer Zu- und Absagen sehen darf (view_responses; Admins haben alle Rechte).
 * - Gezählt werden alle, die zusagen dürfen; die Rückmeldungen anderer (z. B. von Personen, denen das Recht inzwischen
 *   fehlt) bleiben gespeichert, zählen aber nicht.
 * - Offline vorgemerkt (die App sendet später, decided_at = wann getippt): Wurde vor dem Ende der Frist getippt, gilt die
 *   Antwort auch, wenn sie erst danach ankommt, ohne zeitliche Grenze (Entscheidung 18.11.2026). Die Zeit kommt vom
 *   Gerät; liegt sie mehr als CLOCK_SKEW Sekunden in der Zukunft, gilt sie nicht. Solche Antworten sind als
 *   „nachträglich übertragen“ gekennzeichnet (Spalte decided_at, sonst leer).
 * - Gespeichert wird je Person und stabiler Kennung des Termins (response_key, sonst id), mit Titel und Datum als
 *   Momentaufnahme. Rückmeldungen zu Terminen, die länger als CalendarService::PAST_DAYS vorbei sind, löscht cleanup().
 */
final class EventResponseService
{
    public const YES = 'yes';
    public const NO = 'no';
    public const STATUSES = [self::YES, self::NO];
    public const NOTE_MAX = 191;
    /** So weit darf die Uhr des Geräts vorgehen (Sekunden), damit eine Zeit „wann getippt“ noch gilt */
    public const CLOCK_SKEW = 300;
    /** Zu- und Absagen eingeschaltet (Einstellungen → Kalender); fehlt der Wert, sind sie aus */
    public const ENABLED_SETTING = 'event_responses_enabled';
    /** Erinnerung an alle ohne Rückmeldung (Einstellungen → Kalender): an/aus und Vorlauf in Tagen vor Beginn */
    public const REMINDER_SETTING = 'event_response_reminder';
    public const REMINDER_DAYS_SETTING = 'event_response_reminder_days';
    public const REMINDER_DAYS_DEFAULT = 3;
    public const REMINDER_DAYS_MAX = 30;

    private ?EventResponseRepository $repo = null;

    public function __construct(private readonly App $app)
    {
    }

    public function repository(): EventResponseRepository
    {
        return $this->repo ??= new EventResponseRepository($this->app->db);
    }

    /** Sind Zu- und Absagen eingeschaltet (Einstellungen → Kalender)? Vorgabe: aus. */
    public function enabled(): bool
    {
        return (bool) $this->app->settings->get(self::ENABLED_SETTING, false);
    }

    /**
     * Einstellung prüfen (für SettingsService::update, nur wenn mitgeschickt).
     *
     * @return array<string, bool> Einstellung => Wert
     */
    public static function validEnabled(mixed $enabled): array
    {
        if ($enabled === null) {
            return [];
        }
        if (!in_array($enabled, ['0', '1'], true)) {
            throw ApiError::invalid(I18n::t('Ungültiger Wert für Zu- und Absagen (0 oder 1).'));
        }
        return [self::ENABLED_SETTING => $enabled === '1'];
    }

    private function assertEnabled(): void
    {
        if (!$this->enabled()) {
            throw ApiError::forbidden(I18n::t('Zu- und Absagen bei Terminen sind ausgeschaltet.'));
        }
    }

    /**
     * Termine des Kalenders mit dem Feld responses für $viewer: eigene Rückmeldung, Zähler, Frist, ob noch änderbar. Ohne
     * Anmeldung (Gast mit dem internen Kalender) nur die Zähler. Ausgeschaltet ohne das Feld responses.
     *
     * @param array<int, array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    public function annotate(?User $viewer, array $events): array
    {
        if (!$this->enabled()) {
            return array_values(array_map(static function (array $e): array {
                unset($e['response_key']);
                return $e;
            }, $events));
        }
        $byEvent = $this->repository()->forEvents(array_map(self::key(...), array_values($events)));
        $audience = $this->audience();
        $mayRespond = $viewer !== null && $viewer->id > 0 && $viewer->can(Capability::RespondCalendar);
        $mayList = $viewer !== null && $viewer->can(Capability::ViewResponses);
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
            $key = self::key($e);
            unset($e['response_key']);
            $e['responses'] = [
                // Stabile Kennung: Die App merkt Antworten darunter vor und sendet sie damit (gilt auch nach dem Verschieben)
                'key' => $key,
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
    public function respond(User $user, string $id, mixed $status, mixed $note, mixed $decidedAt = null): array
    {
        if (!is_string($status) || !in_array($status, self::STATUSES, true)) {
            throw ApiError::invalid(I18n::t('Bitte zusagen oder absagen.'));
        }
        $this->assertEnabled();
        $note = is_string($note) ? Text::line($note) : '';
        if (mb_strlen($note) > self::NOTE_MAX) {
            throw ApiError::invalid(I18n::t('Der Hinweis ist zu lang (höchstens {max} Zeichen).', ['max' => self::NOTE_MAX]));
        }
        [$event, $late] = $this->openOrDecidedInTime($id, $decidedAt);
        $this->repository()->save(
            self::key($event),
            $user->id,
            $status,
            $note,
            mb_substr(Text::line((string) $event['title']), 0, 191),
            $this->localDate((string) $event['start']),
            $user->id,
            $this->app->time()->nowUtc(),
            $late,
        );
        return $this->annotate($user, [$event])[0];
    }

    /**
     * Eigene Rückmeldung zurücknehmen (wieder „keine Rückmeldung“).
     *
     * @return array<string, mixed>
     */
    public function withdraw(User $user, string $id, mixed $decidedAt = null): array
    {
        $this->assertEnabled();
        [$event] = $this->openOrDecidedInTime($id, $decidedAt);
        $this->repository()->delete(self::key($event), $user->id);
        return $this->annotate($user, [$event])[0];
    }

    /**
     * Wer zu- oder abgesagt hat und wer sich noch nicht gemeldet hat (für alle, die Termine pflegen dürfen).
     *
     * @return array{event: array<string, mixed>, yes: list<array{id: int, name: string, note: string, late: bool}>, no: list<array{id: int, name: string, note: string, late: bool}>, none: list<array{id: int, name: string}>}
     */
    public function overview(User $viewer, string $id): array
    {
        $this->assertEnabled();
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
                $out[$r['status']][] = ['id' => $u->id, 'name' => $u->name, 'note' => $r['note'], 'late' => $r['decided_at'] !== null];
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
            throw ApiError::invalid(I18n::t('Bitte zusagen, absagen oder die Rückmeldung entfernen.'));
        }
        $this->assertEnabled();
        if (!isset($this->audience()[$userId])) {
            throw ApiError::invalid(I18n::t('Diese Person kann bei Terminen nicht zu- oder absagen.'));
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
     * zu- und absagen darf und wer wie geantwortet hat. Ohne den Termin (gelöscht, Kalender gestört) oder ausgeschaltet null.
     *
     * @return array{open: bool, audience: array<int, true>, rows: array<int, array{status: string, note: string, late: bool}>}|null
     */
    public function forAttendance(string $id): ?array
    {
        if (!$this->enabled()) {
            return null;
        }
        try {
            $event = $this->event($id);
        } catch (ApiError) {
            return null;
        }
        $rows = [];
        foreach ($this->repository()->forEvents([self::key($event)])[self::key($event)] ?? [] as $r) {
            if (in_array($r['status'], self::STATUSES, true)) {
                $rows[$r['user_id']] = ['status' => $r['status'], 'note' => $r['note'], 'late' => $r['decided_at'] !== null];
            }
        }
        return [
            'open' => $this->app->time()->timestamp() < $this->deadline($event),
            'audience' => array_map(static fn (): bool => true, $this->audience()),
            'rows' => $rows,
        ];
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

    /** @return array{enabled: bool, days: int} Erinnerung an alle ohne Rückmeldung (Standard: an, 3 Tage vorher) */
    public function reminderSettings(): array
    {
        $s = $this->app->settings;
        $days = $s->get(self::REMINDER_DAYS_SETTING, self::REMINDER_DAYS_DEFAULT);
        return [
            'enabled' => (bool) $s->get(self::REMINDER_SETTING, true),
            'days' => is_numeric($days) && (int) $days >= 1 && (int) $days <= self::REMINDER_DAYS_MAX ? (int) $days : self::REMINDER_DAYS_DEFAULT,
        ];
    }

    /**
     * Einstellungen der Erinnerung ändern; nur die mitgeschickten Felder (für SettingsService::update, schon geprüft).
     *
     * @return array<string, bool|int> Einstellung => Wert
     */
    public static function validReminder(mixed $enabled, mixed $days): array
    {
        $out = [];
        if ($enabled !== null) {
            if (!in_array($enabled, ['0', '1', true, false], true)) {
                throw ApiError::invalid(I18n::t('Ungültiger Wert für die Erinnerung (0 oder 1).'));
            }
            $out[self::REMINDER_SETTING] = $enabled === '1' || $enabled === true;
        }
        if ($days !== null) {
            $n = Input::integer($days);
            if ($n === null || $n < 1 || $n > self::REMINDER_DAYS_MAX) {
                throw ApiError::invalid(I18n::t('Die Erinnerung geht 1 bis {max} Tage vor dem Termin.', ['max' => self::REMINDER_DAYS_MAX]));
            }
            $out[self::REMINDER_DAYS_SETTING] = $n;
        }
        return $out;
    }

    /**
     * Termine, an die heute ($today, Zeitzone der Kompanie) erinnert wird: mit Anmeldeschluss am Tag davor (rund 24
     * Stunden vor seinem Ende), sonst die Tage des Vorlaufs vor Beginn. Nur solange man noch zu- und absagen kann.
     *
     * @param array<int, array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    public function dueForReminder(array $events, string $today): array
    {
        $days = $this->reminderSettings()['days'];
        $tz = $this->app->time()->timezone();
        $tomorrow = (new \DateTimeImmutable($today, $tz))->modify('+1 day')->format('Y-m-d');
        $target = (new \DateTimeImmutable($today, $tz))->modify('+' . $days . ' days')->format('Y-m-d');
        $now = $this->app->time()->timestamp();
        $out = [];
        foreach ($events as $e) {
            if ($now >= $this->deadline($e)) {
                continue;
            }
            $until = $e['respond_until'] ?? null;
            $due = is_string($until) && Input::isDate($until) ? $until === $tomorrow : $this->localDate((string) $e['start']) === $target;
            if ($due) {
                $out[] = $e;
            }
        }
        return $out;
    }

    /**
     * Je Person (alle, die zu- und absagen dürfen) die Termine ohne ihre Rückmeldung; ausgeschaltet niemand.
     *
     * @param list<array<string, mixed>> $events
     * @return array<int, list<array<string, mixed>>> Benutzer-ID => Termine
     */
    public function withoutResponse(array $events): array
    {
        if (!$this->enabled()) {
            return [];
        }
        $rows = $this->repository()->forEvents(array_map(self::key(...), $events));
        $out = [];
        foreach ($this->audience() as $uid => $u) {
            foreach ($events as $e) {
                $answered = false;
                foreach ($rows[self::key($e)] ?? [] as $r) {
                    $answered = $answered || $r['user_id'] === $uid;
                }
                if (!$answered) {
                    $out[$uid][] = $e;
                }
            }
        }
        return $out;
    }

    /**
     * Wer bei welchen dieser Termine abgesagt hat (für die Erinnerung am Vortag); ausgeschaltet niemand.
     *
     * @param list<array<string, mixed>> $events
     * @return array<int, array<string, true>> Benutzer-ID => Kennungen der Termine (id)
     */
    public function declined(array $events): array
    {
        if (!$this->enabled()) {
            return [];
        }
        $byKey = [];
        foreach ($events as $e) {
            $byKey[self::key($e)][] = (string) $e['id'];
        }
        $out = [];
        foreach ($this->repository()->forEvents(array_map(strval(...), array_keys($byKey))) as $key => $rows) {
            foreach ($rows as $r) {
                if ($r['status'] === self::NO) {
                    foreach ($byKey[$key] ?? [] as $id) {
                        $out[$r['user_id']][$id] = true;
                    }
                }
            }
        }
        return $out;
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
     * Ein Termin des internen Kalenders nach seiner Kennung (wie in GET /calendar) oder seiner stabilen Kennung (responses.key).
     *
     * @return array<string, mixed>
     */
    private function event(string $id): array
    {
        $events = $this->app->calendar()->events();
        foreach ($events as $e) {
            if ((string) $e['id'] === $id) {
                return $e;
            }
        }
        // Auch unter der stabilen Kennung (responses.key): Bei iCal ändert sich die id beim Verschieben des Termins,
        // eine offline vorgemerkte Antwort soll trotzdem ankommen.
        foreach ($events as $e) {
            if (isset($e['response_key']) && (string) $e['response_key'] === $id) {
                return $e;
            }
        }
        throw ApiError::notFound(I18n::t('Diesen Termin gibt es im Kalender nicht (mehr).'));
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
            throw new ApiError(409, 'responses_closed', I18n::t('Für diesen Termin kannst du nicht mehr zu- oder absagen.'));
        }
        return $event;
    }

    /**
     * Ein Termin, bei dem man noch zu- oder absagen kann, oder bei dem die Antwort vor dem Ende der Frist getippt wurde
     * ($decidedAt, ISO 8601 vom Gerät; offline vorgemerkt). Zurück: der Termin und, wenn die Frist schon vorbei ist, die
     * Zeit des Tippens (UTC, Y-m-d H:i:s) zum Kennzeichnen „nachträglich übertragen“, sonst null.
     *
     * @return array{0: array<string, mixed>, 1: string|null}
     */
    private function openOrDecidedInTime(string $id, mixed $decidedAt): array
    {
        $event = $this->event($id);
        $now = $this->app->time()->timestamp();
        $deadline = $this->deadline($event);
        if ($now < $deadline) {
            return [$event, null];
        }
        $decided = self::decidedAt($decidedAt);
        if ($decided === null || $decided >= $deadline || $decided > $now + self::CLOCK_SKEW) {
            throw new ApiError(409, 'responses_closed', 'Für diesen Termin kannst du nicht mehr zu- oder absagen.');
        }
        return [$event, gmdate('Y-m-d H:i:s', $decided)];
    }

    /** Zeit „wann getippt“ als Unix-Zeit; null, wenn nicht angegeben oder ungültig. */
    private static function decidedAt(mixed $value): ?int
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2})$/', $value)) {
            return null;
        }
        $t = strtotime($value);
        return $t === false ? null : $t;
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
