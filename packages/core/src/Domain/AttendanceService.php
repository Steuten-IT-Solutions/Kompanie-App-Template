<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Storage\AttendanceRepository;

/**
 * Anwesenheit (Issue #21).
 *
 * - Eine Liste je Termin: aus dem internen Kalender (source calendar, event_key = ID des Termins) oder frei angelegt
 *   (source free). Titel und Datum sind eine Momentaufnahme; die Liste bleibt, auch wenn der Termin verschwindet.
 * - In jeder Liste stehen alle mit einer Rolle (alle außer Gästen); Status anwesend, entschuldigt oder fehlt.
 * - Punkte für Anwesende und Strafen für Fehlende bucht man über die üblichen Formulare (Vorschlag). Punkte tragen
 *   die ID der Liste (points.attendance_id); daran erkennt die App, wer schon welche hat. Punkte und Strafen gibt es
 *   nur für Mitglieder.
 * - Der Zähler teilt nach Gruppen auf: Jede Rolle zählt als eigene Gruppe oder als eine andere Rolle (Einstellung
 *   attendance_groups, z. B. „Punkte verwalten“ zählt als Mitglied).
 *
 * @phpstan-import-type AttendanceList from AttendanceRepository
 */
final class AttendanceService
{
    public const PRESENT = 'present';
    public const EXCUSED = 'excused';
    public const ABSENT = 'absent';
    public const STATUSES = [self::PRESENT, self::EXCUSED, self::ABSENT];
    public const SOURCE_CALENDAR = 'calendar';
    public const SOURCE_FREE = 'free';
    public const GROUPS_SETTING = 'attendance_groups';
    public const TITLE_MAX = 191;
    public const RECENT = 50;

    private ?AttendanceRepository $repo = null;

    public function __construct(private readonly App $app)
    {
    }

    public function repository(): AttendanceRepository
    {
        return $this->repo ??= new AttendanceRepository($this->app->db);
    }

    /* ---------------------------------------------------------------- */
    /* Listen                                                            */
    /* ---------------------------------------------------------------- */

    /**
     * Die letzten Listen mit Zählern je Status.
     *
     * @return list<array<string, mixed>>
     */
    public function lists(): array
    {
        $lists = $this->repository()->recent(self::RECENT);
        $people = [];
        foreach ($this->people() as $u) {
            $people[$u->id] = true;
        }
        $counts = $this->repository()->counts(array_column($lists, 'id'), $people);
        return array_map(static fn (array $l): array => [
            'id' => $l['id'],
            'source' => $l['source'],
            'title' => $l['title'],
            'date' => $l['event_date'],
            'counts' => self::statusCounts($counts[$l['id']] ?? []),
        ], $lists);
    }

    /**
     * Termine aus dem internen Kalender der letzten Wochen bis heute zur Auswahl, die jüngsten zuerst, mit der ID einer
     * schon angelegten Liste. Ohne Bereich Kalender leer.
     *
     * @return list<array{key: string, title: string, date: string, start: string, end: string, all_day: bool, list_id: int|null}>
     */
    public function events(): array
    {
        if (!$this->app->features()->enabled('calendar') || !$this->app->calendar()->isConfigured()) {
            return [];
        }
        $today = $this->app->time()->today()->format('Y-m-d');
        $from = $this->app->time()->today()->modify('-' . CalendarService::PAST_DAYS . ' days')->format('Y-m-d');
        $lists = $this->repository()->calendarListIds();
        $out = [];
        foreach ($this->app->calendar()->events() as $e) {
            $date = $this->localDate((string) $e['start']);
            if ($date < $from || $date > $today) {
                continue;
            }
            $key = (string) $e['id'];
            $out[] = ['key' => $key, 'title' => (string) $e['title'], 'date' => $date, 'start' => (string) $e['start'], 'end' => (string) $e['end'], 'all_day' => (bool) ($e['all_day'] ?? false), 'list_id' => $lists[$key] ?? null];
        }
        return array_reverse($out);
    }

    /**
     * Eine Liste mit allen Personen (alle mit einer Rolle), ihrem Status, ob sie für diese Liste schon Punkte haben,
     * und den Zählern je Status und Gruppe.
     *
     * @return array<string, mixed>
     */
    public function detail(int $id): array
    {
        $list = $this->repository()->find($id) ?? throw ApiError::notFound('Anwesenheitsliste nicht gefunden.');
        $entries = $this->repository()->entries($id);
        $booked = array_flip($this->repository()->bookedUserIds($id));
        $groups = $this->groupMap();
        $roles = $this->app->roles();
        $people = [];
        $counts = [];
        foreach ($this->people() as $u) {
            $status = $entries[$u->id]['status'] ?? null;
            $group = $groups[$u->role] ?? $u->role;
            $people[] = [
                'id' => $u->id,
                'name' => $u->name,
                'role' => $u->role,
                'group' => $group,
                'member' => $u->isMember(),
                'status' => $status,
                'booked' => isset($booked[$u->id]),
            ];
            if ($status !== null) {
                $counts[$status][$group] = ($counts[$status][$group] ?? 0) + 1;
            }
        }
        // In umgekehrter Reihenfolge der Rollen (Einstellungen → Rollen, von unten nach oben; wie die Mitgliederliste)
        $order = array_flip(array_keys($roles->all()));
        $byGroup = [];
        foreach (self::STATUSES as $s) {
            $row = $counts[$s] ?? [];
            uksort($row, static fn (string $a, string $b): int => ($order[$b] ?? -1) <=> ($order[$a] ?? -1));
            $byGroup[$s] = array_map(static fn (string $g, int $n): array => ['group' => $g, 'label' => $roles->label($g), 'count' => $n], array_keys($row), array_values($row));
        }
        $names = new UserNames($this->app->users);
        return [
            'id' => $list['id'],
            'source' => $list['source'],
            'event_key' => $list['source'] === self::SOURCE_CALENDAR ? $list['event_key'] : null,
            'title' => $list['title'],
            'date' => $list['event_date'],
            'updated_by_name' => $names->name($list['updated_by']),
            'updated_at' => $list['updated_at'],
            'people' => $people,
            'counts' => self::statusCounts(array_map('array_sum', $counts)),
            'counts_by_group' => $byGroup,
        ];
    }

    /**
     * Liste anlegen (bzw. die vorhandene zum selben Kalendertermin nehmen) oder ändern und Status setzen.
     *
     * @param array<string, mixed> $in id | source, event_key (calendar) bzw. title, date (free); entries: {user_id: status|null}
     * @return array{0: array<string, mixed>, 1: bool} Liste wie detail(), neu angelegt?
     */
    public function save(User $actor, array $in): array
    {
        $repo = $this->repository();
        $now = $this->app->time()->nowUtc();
        $changes = $this->validEntries($in['entries'] ?? []);
        $created = false;
        $id = Input::integer($in['id'] ?? null);
        if ($id !== null) {
            $list = $repo->find($id) ?? throw ApiError::notFound('Anwesenheitsliste nicht gefunden.');
            $data = [];
            // Freie Listen: Titel und Datum lassen sich ändern; Kalenderlisten behalten ihre Momentaufnahme.
            if ($list['source'] === self::SOURCE_FREE) {
                if (array_key_exists('title', $in)) {
                    $data['title'] = self::validTitle($in['title']);
                }
                if (array_key_exists('date', $in)) {
                    $data['event_date'] = self::validDate($in['date']);
                }
            }
        } else {
            $source = $in['source'] ?? null;
            if ($source === self::SOURCE_CALENDAR) {
                $key = is_string($in['event_key'] ?? null) ? trim($in['event_key']) : '';
                $list = $key === '' ? null : $repo->findByEvent(self::SOURCE_CALENDAR, $key);
                if ($list === null) {
                    $event = $this->calendarEvent($key);
                    try {
                        $id = $repo->insert(self::SOURCE_CALENDAR, $key, mb_substr(Text::line($event['title'], self::TITLE_MAX + 1), 0, self::TITLE_MAX), $this->localDate((string) $event['start']), $actor->id, $now);
                        $created = true;
                    } catch (\RuntimeException $e) {
                        // Gleichzeitig angelegt (eindeutig je Termin): die andere Liste nehmen.
                        $id = ($repo->findByEvent(self::SOURCE_CALENDAR, $key) ?? throw $e)['id'];
                    }
                } else {
                    $id = $list['id'];
                }
            } elseif ($source === self::SOURCE_FREE) {
                $id = $repo->insert(self::SOURCE_FREE, bin2hex(random_bytes(8)), self::validTitle($in['title'] ?? null), self::validDate($in['date'] ?? null), $actor->id, $now);
                $created = true;
            } else {
                throw ApiError::invalid('Bitte einen Termin aus dem Kalender wählen oder einen freien Termin anlegen.');
            }
            $data = [];
        }
        $this->app->db->transaction(function () use ($repo, $id, $changes, $data, $actor, $now, $created): void {
            $repo->setEntries($id, $changes, $actor->id, $now);
            if (!$created && ($changes !== [] || $data !== [])) {
                $repo->touch($id, $data, $actor->id, $now);
            }
        });
        return [$this->detail($id), $created];
    }

    /** Liste löschen; gebuchte Punkte bleiben (mit der ID der Liste). */
    public function delete(int $id): void
    {
        $this->repository()->find($id) ?? throw ApiError::notFound('Anwesenheitsliste nicht gefunden.');
        $this->repository()->delete($id);
    }

    /**
     * Eigene Anwesenheit, die jüngsten Termine zuerst, mit Zählern.
     *
     * @return array{entries: list<array<string, mixed>>, counts: array<string, int>}
     */
    public function mine(int $userId): array
    {
        $rows = $this->repository()->forUser($userId);
        $counts = [];
        foreach ($rows as $r) {
            $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;
        }
        $names = (new UserNames($this->app->users))->prime(array_column($rows, 'updated_by'));
        return [
            'entries' => array_map(static fn (array $r): array => [
                'list_id' => $r['list_id'],
                'title' => $r['title'],
                'date' => $r['event_date'],
                'status' => $r['status'],
                'updated_by_name' => $names->name($r['updated_by']),
            ], $rows),
            'counts' => self::statusCounts($counts),
        ];
    }

    /**
     * Punkte aus dem Vorschlag einer Liste: Liste prüfen und nur, wer für sie noch keine Punkte hat.
     *
     * @return array{0: int, 1: list<int>} Liste, verbleibende Benutzer-IDs
     */
    public function forBooking(mixed $listId, mixed $userIds): array
    {
        if (!$this->app->features()->enabled('attendance')) {
            throw new ApiError(403, 'feature_disabled', sprintf('Der Bereich %s ist ausgeschaltet.', $this->app->config->sectionLabel('attendance', 'Anwesenheit')));
        }
        $id = Input::integer($listId);
        $list = $id === null ? null : $this->repository()->find($id);
        if ($list === null) {
            throw ApiError::invalid('Anwesenheitsliste nicht gefunden.');
        }
        $booked = $this->repository()->bookedUserIds($list['id']);
        $ids = is_array($userIds) ? array_values(array_filter(array_map(static fn (mixed $v): ?int => Input::integer($v), $userIds), static fn (?int $v): bool => $v !== null && !in_array($v, $booked, true))) : [];
        if (is_array($userIds) && $userIds !== [] && $ids === []) {
            throw ApiError::invalid('Alle Ausgewählten haben für diese Liste schon Punkte.');
        }
        return [$list['id'], $ids];
    }

    /* ---------------------------------------------------------------- */
    /* Gruppen für den Zähler                                            */
    /* ---------------------------------------------------------------- */

    /**
     * Für Einstellungen → Rollen: jede Rolle (ohne Gast) und als welche sie zählt (null = eigene Gruppe).
     *
     * @return list<array{key: string, label: string, counts_as: string|null}>
     */
    public function groups(): array
    {
        $map = $this->groupMap();
        $out = [];
        foreach ($this->app->roles()->all() as $key => $r) {
            if ($key === Roles::GUEST) {
                continue;
            }
            $out[] = ['key' => $key, 'label' => $r['label'], 'counts_as' => $map[$key] === $key ? null : $map[$key]];
        }
        return $out;
    }

    /**
     * @param mixed $raw {Rolle: Rolle, als die sie zählt | null (eigene Gruppe)}; nicht genannte Rollen bleiben, wie sie sind
     * @return list<array{key: string, label: string, counts_as: string|null}>
     */
    public function setGroups(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw ApiError::invalid('Bitte die Gruppen als Zuordnung Rolle → Rolle angeben.');
        }
        $roles = $this->app->roles();
        $next = [];
        foreach ($this->groupMap() as $key => $as) {
            $next[$key] = $as === $key ? '' : $as;
        }
        foreach ($raw as $key => $as) {
            $key = (string) $key;
            if ($key === Roles::GUEST || !$roles->exists($key)) {
                throw ApiError::invalid('Unbekannte Rolle: ' . $key . '.');
            }
            if ($as !== null && $as !== '' && (!is_string($as) || $as === Roles::GUEST || !$roles->exists($as))) {
                throw ApiError::invalid(sprintf('„%s“: unbekannte Rolle als Gruppe.', $roles->label($key)));
            }
            $next[$key] = $as === null || $as === $key ? '' : (string) $as;
        }
        foreach ($next as $key => $as) {
            if ($as !== '' && ($next[$as] ?? '') !== '') {
                throw ApiError::invalid(sprintf(
                    '„%s“ soll als „%s“ zählen, „%s“ zählt aber selbst als „%s“. Bitte direkt „%s“ wählen.',
                    $roles->label($key),
                    $roles->label($as),
                    $roles->label($as),
                    $roles->label($next[$as]),
                    $roles->label($next[$as]),
                ));
            }
        }
        $this->app->settings->set(self::GROUPS_SETTING, $next);
        return $this->groups();
    }

    /**
     * Gruppe je Rolle (ohne Gast). Ohne Einstellung: Rollen mit Mitgliederbereich zählen als Mitglied, die übrigen
     * (z. B. Anwärter) als eigene Gruppe. Ungültige Einstellungen (Rolle gelöscht, Kette) fallen auf die eigene Gruppe.
     *
     * @return array<string, string> Rolle => Rolle der Gruppe
     */
    public function groupMap(): array
    {
        $saved = $this->app->settings->get(self::GROUPS_SETTING);
        $saved = is_array($saved) ? $saved : [];
        $roles = $this->app->roles();
        $keys = array_values(array_filter(array_keys($roles->all()), static fn (string $k): bool => $k !== Roles::GUEST));
        $wanted = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $saved) && is_string($saved[$key])) {
                $wanted[$key] = $saved[$key] === '' ? $key : $saved[$key];
            } else {
                $wanted[$key] = $key !== Roles::MEMBER && in_array(Capability::Member, $roles->capabilities($key), true) ? Roles::MEMBER : $key;
            }
        }
        $out = [];
        foreach ($wanted as $key => $as) {
            $out[$key] = isset($wanted[$as]) && $wanted[$as] === $as ? $as : $key;
        }
        return $out;
    }

    /* ---------------------------------------------------------------- */

    /** @return list<User> alle mit einer Rolle (ohne Gäste), nach Namen */
    private function people(): array
    {
        return array_values(array_filter($this->app->users->list(false), static fn (User $u): bool => $u->role !== Roles::GUEST));
    }

    /** @return array<int, string|null> */
    private function validEntries(mixed $raw): array
    {
        if ($raw === [] || $raw === null) {
            return [];
        }
        if (!is_array($raw)) {
            throw ApiError::invalid('Bitte die Einträge als Zuordnung Person → Status angeben.');
        }
        $people = [];
        foreach ($this->people() as $u) {
            $people[$u->id] = true;
        }
        $out = [];
        foreach ($raw as $userId => $status) {
            $id = Input::integer($userId);
            if ($id === null || !isset($people[$id])) {
                throw ApiError::invalid('Unbekannte Person in der Liste.');
            }
            if ($status !== null && !in_array($status, self::STATUSES, true)) {
                throw ApiError::invalid('Ungültiger Status (anwesend, entschuldigt oder fehlt).');
            }
            $out[$id] = $status;
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function calendarEvent(string $key): array
    {
        if ($key !== '' && $this->app->features()->enabled('calendar')) {
            foreach ($this->app->calendar()->events() as $e) {
                if ((string) $e['id'] === $key) {
                    return $e;
                }
            }
        }
        throw ApiError::invalid('Diesen Termin gibt es im Kalender nicht (mehr).');
    }

    /** Datum des Beginns in der Zeitzone der Kompanie. */
    private function localDate(string $start): string
    {
        if (Input::isDate($start)) {
            return $start;
        }
        $ts = strtotime($start);
        return $ts === false ? substr($start, 0, 10) : $this->app->time()->format($ts, 'Y-m-d');
    }

    private static function validTitle(mixed $raw): string
    {
        $title = Text::line($raw ?? '', self::TITLE_MAX + 1);
        if ($title === '') {
            throw ApiError::invalid('Bitte einen Titel angeben.');
        }
        if (mb_strlen($title) > self::TITLE_MAX) {
            throw ApiError::invalid(sprintf('Der Titel darf höchstens %d Zeichen lang sein.', self::TITLE_MAX));
        }
        return $title;
    }

    private static function validDate(mixed $raw): string
    {
        if (!Input::isDate($raw)) {
            throw ApiError::invalid('Bitte ein gültiges Datum angeben.');
        }
        return (string) $raw;
    }

    /**
     * @param array<string, int> $counts
     * @return array{present: int, excused: int, absent: int}
     */
    private static function statusCounts(array $counts): array
    {
        return ['present' => $counts[self::PRESENT] ?? 0, 'excused' => $counts[self::EXCUSED] ?? 0, 'absent' => $counts[self::ABSENT] ?? 0];
    }
}
