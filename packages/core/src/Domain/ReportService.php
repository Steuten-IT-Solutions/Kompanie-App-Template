<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Storage\ReportRepository;

/**
 * Bericht als PDF (Verwaltung → Bericht): die Daten mehrerer Bereiche für einen Zeitraum, je Bereich ein Abschnitt mit
 * fertigen Tabellen (Texte, wie sie im PDF stehen). Das PDF baut die App im Browser (reportPdf.ts); hier entstehen nur
 * die Tabellen.
 *
 * - Jeder Abschnitt nur mit dem Recht, das man auch in der App dafür braucht (SECTIONS, caps: eines davon), und nur,
 *   wenn sein Bereich an ist. Ein eigenes Recht für den Bericht gibt es nicht.
 * - Persönliche Angaben (E-Mail, Telefon, Adresse, Geburtsdatum) nur mit view_personal.
 * - Abschnitte mit dated = false haben keinen Zeitbezug und zeigen den Stand von heute.
 * - Wer einen Bericht abruft, steht im Änderungsprotokoll (Zeitraum und Abschnitte, ohne Inhalt). Das PDF entsteht erst
 *   im Browser: Festgehalten ist der Abruf der Daten.
 * - In der Vorschau („App als Person ansehen“) gibt es keinen Bericht, wie bei der Auskunft (PersonalDataExport).
 *
 * Antwort: {from, to, created, sections: [{key, title, dated, landscape, tables: [{title, columns: [{label, num, wrap}],
 * rows: [[Text]], empty}]}]}
 *
 * @phpstan-type Column array{label: string, num: bool, wrap: bool}
 * @phpstan-type Table array{title: string, columns: list<Column>, rows: list<list<string>>, empty: string}
 * @phpstan-type Section array{key: string, title: string, dated: bool, landscape: bool, tables: list<Table>}
 */
final class ReportService
{
    /** Längster Zeitraum in Tagen (gut zehn Jahre) */
    public const RANGE_MAX = 3700;

    /**
     * Abschnitte in ihrer Reihenfolge im Bericht: Bereich (null: immer da), Rechte (eines genügt), Zeitbezug, Name (ohne
     * Bereich; sonst der Name des Bereichs, wie die Kompanie ihn nennt).
     *
     * @var array<string, array{feature: string|null, caps: list<Capability>, dated: bool, label: string}>
     */
    private const SECTIONS = [
        'members' => ['feature' => null, 'caps' => [
            Capability::ManagePoints, Capability::ManagePenalties, Capability::ManageRanks, Capability::ManageTitles, Capability::ManageAttendance,
            Capability::ManageCompanies, Capability::ViewPersonal, Capability::CreateUsers, Capability::ManageFormer, Capability::PromoteUsers,
        ], 'dated' => false, 'label' => 'Mitglieder'],
        'passive' => ['feature' => 'passive', 'caps' => [Capability::ManagePassive], 'dated' => false, 'label' => 'Passive'],
        'points' => ['feature' => 'points', 'caps' => [Capability::ManagePoints], 'dated' => true, 'label' => 'Punkte'],
        'penalties' => ['feature' => 'penalties', 'caps' => [Capability::ManagePenalties], 'dated' => true, 'label' => 'Strafen'],
        'attendance' => ['feature' => 'attendance', 'caps' => [Capability::ManageAttendance], 'dated' => true, 'label' => 'Anwesenheit'],
        'events' => ['feature' => 'events', 'caps' => [Capability::ManageEvents], 'dated' => true, 'label' => 'Termine'],
        'calendar' => ['feature' => 'calendar', 'caps' => [Capability::ManageCalendar], 'dated' => true, 'label' => 'Kalender'],
        'ranks' => ['feature' => 'ranks', 'caps' => [Capability::ManageRanks], 'dated' => true, 'label' => 'Dienstgrade'],
        'titles' => ['feature' => 'titles', 'caps' => [Capability::ManageTitles], 'dated' => true, 'label' => 'Titel'],
        'offices' => ['feature' => 'offices', 'caps' => [Capability::ManageOffices], 'dated' => true, 'label' => 'Ämter'],
        'inventory' => ['feature' => 'inventory', 'caps' => [Capability::ManageInventory], 'dated' => true, 'label' => 'Inventar'],
    ];

    private const RANK_KINDS = [RankService::KIND_PROMOTION => 'Beförderung', RankService::KIND_DEMOTION => 'Zurückgestuft', RankService::KIND_INITIAL => 'Erster Dienstgrad'];

    private const INVENTORY_ACTIONS = [
        InventoryService::ACTION_CREATE => 'Angelegt', InventoryService::ACTION_UPDATE => 'Geändert',
        InventoryService::ACTION_MOVE => 'Umgelagert', InventoryService::ACTION_DELETE => 'Gelöscht',
    ];

    private const STATUS_LABELS = [AttendanceService::PRESENT => 'Anwesend', AttendanceService::EXCUSED => 'Entschuldigt', AttendanceService::ABSENT => 'Fehlt'];

    private ?ReportRepository $repo = null;

    public function __construct(private readonly App $app)
    {
    }

    /**
     * Rechte, mit denen es mindestens einen Abschnitt geben kann (für die Route; ob der Bereich an ist, prüft sections()).
     *
     * @return list<Capability>
     */
    public static function access(): array
    {
        $caps = [];
        foreach (self::SECTIONS as $def) {
            foreach ($def['caps'] as $cap) {
                $caps[$cap->value] = $cap;
            }
        }
        return array_values($caps);
    }

    /**
     * Abschnitte, die $viewer in den Bericht nehmen kann.
     *
     * @return list<array{key: string, title: string, dated: bool}>
     */
    public function sections(User $viewer): array
    {
        $out = [];
        foreach (self::SECTIONS as $key => $def) {
            if ($this->allowed($viewer, $key)) {
                $out[] = ['key' => $key, 'title' => $this->title($key), 'dated' => $def['dated']];
            }
        }
        return $out;
    }

    /**
     * Daten des Berichts. $sections: Schlüssel als Liste oder mit Kommas; unbekannte oder nicht erlaubte lehnt der Server
     * ab, statt sie still wegzulassen (sonst fehlte im PDF unbemerkt ein Teil).
     *
     * @return array{from: string, to: string, created: string, sections: list<Section>}
     */
    public function build(User $viewer, mixed $from, mixed $to, mixed $sections): array
    {
        if ($viewer->preview) {
            throw ApiError::forbidden('In der Vorschau gibt es keinen Bericht.');
        }
        [$fromDay, $toDay] = $this->range($from, $to);
        $keys = $this->keys($sections);
        foreach ($keys as $key) {
            if (!$this->allowed($viewer, $key)) {
                throw ApiError::forbidden('Für „' . $this->title($key) . '“ fehlen dir die Rechte, oder der Bereich ist ausgeschaltet.');
            }
        }
        $out = [];
        foreach ($keys as $key) {
            $out[] = match ($key) {
                'members' => $this->members($viewer),
                'points' => $this->points($fromDay, $toDay),
                'penalties' => $this->penalties($fromDay, $toDay),
                'attendance' => $this->attendance($fromDay, $toDay),
                'passive' => $this->passive($viewer),
                'events' => $this->events($fromDay, $toDay),
                'calendar' => $this->calendar($fromDay, $toDay),
                'ranks' => $this->ranks($fromDay, $toDay),
                'titles' => $this->titles($fromDay, $toDay),
                'offices' => $this->offices($fromDay, $toDay),
                'inventory' => $this->inventory($fromDay, $toDay),
                default => throw new \LogicException('Abschnitt ohne Inhalt: ' . $key),
            };
        }
        $titles = implode(', ', array_map(fn (string $k): string => $this->title($k), $keys));
        $label = sprintf('Bericht als PDF: %s bis %s (%s)', self::day($fromDay), self::day($toDay), $titles);
        $this->app->changeLog()->event($viewer, mb_substr($label, 0, 191), 'app', 'Bericht');
        return [
            'from' => $fromDay,
            'to' => $toDay,
            'created' => $this->app->time()->format($this->app->time()->timestamp(), 'd.m.Y, H:i'),
            'sections' => $out,
        ];
    }

    private function repository(): ReportRepository
    {
        return $this->repo ??= new ReportRepository($this->app->db);
    }

    private function allowed(User $viewer, string $key): bool
    {
        $def = self::SECTIONS[$key] ?? null;
        if ($def === null || ($def['feature'] !== null && !$this->app->features()->enabled($def['feature']))) {
            return false;
        }
        // Kalender: Termine nur, wenn sie in der App gepflegt werden; sonst bleiben nur die Zu- und Absagen
        if ($key === 'calendar' && !$this->app->calendar()->inApp() && !$this->app->eventResponses()->enabled()) {
            return false;
        }
        foreach ($def['caps'] as $cap) {
            if ($viewer->can($cap)) {
                return true;
            }
        }
        return false;
    }

    private function title(string $key): string
    {
        $def = self::SECTIONS[$key];
        return $def['feature'] !== null ? $this->app->config->sectionLabel($def['feature'], $def['label']) : $def['label'];
    }

    /**
     * Zeitraum prüfen: zwei Daten (JJJJ-MM-TT), von nicht nach bis, höchstens RANGE_MAX Tage.
     *
     * @return array{0: string, 1: string}
     */
    private function range(mixed $from, mixed $to): array
    {
        if (!Input::isDate($from) || !Input::isDate($to)) {
            throw ApiError::invalid('Bitte einen Zeitraum mit zwei Daten angeben.');
        }
        /** @var string $from */
        /** @var string $to */
        if ($from > $to) {
            throw ApiError::invalid('Der Beginn liegt nach dem Ende des Zeitraums.');
        }
        $days = (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days;
        if ($days === false || $days >= self::RANGE_MAX) {
            throw ApiError::invalid('Der Zeitraum ist zu lang (höchstens zehn Jahre).');
        }
        return [$from, $to];
    }

    /**
     * Gewählte Abschnitte in der Reihenfolge des Berichts, jeder einmal.
     *
     * @return list<string>
     */
    private function keys(mixed $raw): array
    {
        $parts = is_string($raw) ? explode(',', $raw) : (is_array($raw) ? $raw : []);
        $wanted = [];
        foreach ($parts as $p) {
            if (!is_string($p) || !isset(self::SECTIONS[trim($p)])) {
                throw ApiError::invalid('Unbekannter Bereich im Bericht.');
            }
            $wanted[trim($p)] = true;
        }
        if ($wanted === []) {
            throw ApiError::invalid('Bitte mindestens einen Bereich wählen.');
        }
        return array_values(array_filter(array_keys(self::SECTIONS), static fn (string $k): bool => isset($wanted[$k])));
    }

    /* ---------------------------------------------------------------- */
    /* Abschnitte                                                        */
    /* ---------------------------------------------------------------- */

    /**
     * Mitglieder (Stand heute): wie die Mitgliederliste der Verwaltung, mit Rolle und Dienstgrad; E-Mail, Telefon,
     * Adresse und Geburtsdatum nur mit view_personal.
     *
     * @return Section
     */
    private function members(User $viewer): array
    {
        $a = $this->app;
        $list = $a->members()->list(false, $viewer);
        $personal = $viewer->can(Capability::ViewPersonal);
        $ranks = $a->features()->enabled('ranks') ? $a->ranks()->assigned() : null;
        $ids = array_map(static fn (array $m): int => (int) $m['id'], $list);
        $emails = $personal ? $a->users->emails($ids) : [];
        $contacts = $personal ? $a->memberContacts()->all() : [];
        $birthdays = [];
        if ($personal && $a->features()->enabled('birthdays')) {
            foreach ($a->birthdays()->repository()->all() as $b) {
                $birthdays[$b['user_id']] = $b;
            }
        }
        $columns = [self::col('Name'), self::col('Rolle')];
        if ($ranks !== null) {
            $columns[] = self::col($a->config->sectionLabel('ranks', 'Dienstgrad'));
        }
        if ($personal) {
            array_push($columns, self::col('E-Mail', wrap: true), self::col('Telefon'), self::col('Adresse', wrap: true));
            if ($a->features()->enabled('birthdays')) {
                $columns[] = self::col('Geburtsdatum');
            }
        }
        $rows = [];
        foreach ($list as $m) {
            $id = (int) $m['id'];
            $row = [(string) $m['name'], $a->roles()->label((string) $m['level'])];
            if ($ranks !== null) {
                $row[] = isset($ranks[$id]) ? (string) $ranks[$id]['title'] : '';
            }
            if ($personal) {
                $address = $contacts[$id]['address'] ?? null;
                array_push($row, $emails[$id] ?? '', $contacts[$id]['phone'] ?? '', $address !== null ? Address::oneLine($address) : '');
                if ($a->features()->enabled('birthdays')) {
                    $b = $birthdays[$id] ?? null;
                    $row[] = $b === null ? '' : sprintf('%02d.%02d.%s', $b['day'], $b['month'], $b['year'] !== null ? (string) $b['year'] : '');
                }
            }
            $rows[] = $row;
        }
        return self::section('members', 'Mitglieder', false, [self::table('', $columns, $rows, 'Keine Mitglieder.')], landscape: $personal);
    }

    /**
     * Punkte im Zeitraum: alle Einträge und die Summe je Mitglied (was zählt: Punkte × Faktor der Rolle).
     *
     * @return Section
     */
    private function points(string $from, string $to): array
    {
        $rows = $this->repository()->points($from, $to);
        $names = (new UserNames($this->app->users))->prime(array_column($rows, 'user_id'));
        $entries = [];
        $sums = [];
        foreach ($rows as $r) {
            $value = $r['points'] * $r['factor'];
            $name = (string) $names->name($r['user_id']);
            $entries[] = [self::day($r['entry_date']), $name, self::number($value), $r['reason']];
            $sums[$r['user_id']] ??= ['name' => $name, 'points' => 0.0, 'count' => 0];
            $sums[$r['user_id']]['points'] += $value;
            $sums[$r['user_id']]['count']++;
        }
        $title = $this->title('points');
        return self::section('points', $title, true, [
            self::table('Summe je Mitglied', [self::col('Name'), self::col($title, num: true), self::col('Einträge', num: true)], self::byName($sums, static fn (array $s): array => [
                $s['name'], self::number($s['points']), (string) $s['count'],
            ]), 'Im Zeitraum wurden keine ' . $title . ' vergeben.'),
            self::table('Alle Einträge', [self::col('Datum'), self::col('Name'), self::col($title, num: true), self::col('Grund', wrap: true)], $entries, 'Keine Einträge im Zeitraum.'),
        ]);
    }

    /**
     * Strafen im Zeitraum (Datum der Strafe): alle Einträge mit Stand (offen oder bezahlt am) und die Summen je Mitglied.
     *
     * @return Section
     */
    private function penalties(string $from, string $to): array
    {
        $rows = $this->repository()->penalties($from, $to);
        $names = (new UserNames($this->app->users))->prime(array_column($rows, 'user_id'));
        $entries = [];
        $sums = [];
        $total = 0;
        $open = 0;
        foreach ($rows as $r) {
            $name = (string) $names->name($r['user_id']);
            $paid = $r['status'] !== 'open';
            $status = $paid ? ($r['paid_at'] !== null ? 'bezahlt am ' . $this->utcDay($r['paid_at']) : 'bezahlt') : 'offen';
            $entries[] = [self::day($r['entry_date']), $name, self::money($r['amount_cents']), $r['reason'], $status];
            $sums[$r['user_id']] ??= ['name' => $name, 'total' => 0, 'open' => 0];
            $sums[$r['user_id']]['total'] += $r['amount_cents'];
            $total += $r['amount_cents'];
            if (!$paid) {
                $sums[$r['user_id']]['open'] += $r['amount_cents'];
                $open += $r['amount_cents'];
            }
        }
        $byName = self::byName($sums, static fn (array $s): array => [$s['name'], self::money($s['total']), self::money($s['open'])]);
        if ($byName !== []) {
            $byName[] = ['Gesamt', self::money($total), self::money($open)];
        }
        $title = $this->title('penalties');
        return self::section('penalties', $title, true, [
            self::table('Summe je Mitglied', [self::col('Name'), self::col('Gesamt', num: true), self::col('davon offen', num: true)], $byName, 'Im Zeitraum gab es keine ' . $title . '.'),
            self::table('Alle Einträge', [self::col('Datum'), self::col('Name'), self::col('Betrag', num: true), self::col('Grund', wrap: true), self::col('Stand')], $entries, 'Keine Einträge im Zeitraum.'),
        ]);
    }

    /**
     * Anwesenheit im Zeitraum: je Liste (Termin) und je Mitglied die Zahl der Einträge je Status.
     *
     * @return Section
     */
    private function attendance(string $from, string $to): array
    {
        $statuses = array_keys(self::STATUS_LABELS);
        $counts = static fn (array $c): array => array_map(static fn (string $s): string => (string) ($c[$s] ?? 0), $statuses);
        $lists = array_map(static fn (array $l): array => [self::day($l['event_date']), $l['title'], ...$counts($l['counts'])], $this->repository()->attendanceLists($from, $to));
        $byUser = $this->repository()->attendanceByUser($from, $to);
        $names = (new UserNames($this->app->users))->prime(array_keys($byUser));
        $people = [];
        foreach ($byUser as $userId => $c) {
            $people[$userId] = ['name' => (string) $names->name($userId), 'counts' => $c];
        }
        $statusCols = array_map(static fn (string $l): array => self::col($l, num: true), array_values(self::STATUS_LABELS));
        return self::section('attendance', $this->title('attendance'), true, [
            self::table('Je Mitglied', [self::col('Name'), ...$statusCols], self::byName($people, static fn (array $p): array => [$p['name'], ...$counts($p['counts'])]), 'Im Zeitraum gibt es keine Einträge.'),
            self::table('Je Termin', [self::col('Datum'), self::col('Termin', wrap: true), ...$statusCols], $lists, 'Im Zeitraum gibt es keine Listen.'),
        ]);
    }

    /**
     * Passive (Stand heute): Name und Betreuer; Adresse, Telefon, E-Mail und Geburtsdatum nur mit view_personal.
     *
     * @return Section
     */
    private function passive(User $viewer): array
    {
        $rows = $this->app->passives()->repository()->all();
        $personal = $viewer->can(Capability::ViewPersonal);
        $names = (new UserNames($this->app->users))->prime(array_values(array_filter(array_column($rows, 'caretaker_id'), 'is_int')));
        usort($rows, static fn (array $a, array $b): int => strcasecmp($a['last_name'], $b['last_name']) ?: strcasecmp($a['first_name'], $b['first_name']) ?: $a['id'] <=> $b['id']);
        $columns = [self::col('Name'), self::col('Betreuer')];
        if ($personal) {
            array_push($columns, self::col('Adresse', wrap: true), self::col('Telefon'), self::col('E-Mail', wrap: true), self::col('Geburtsdatum'));
        }
        $out = [];
        foreach ($rows as $r) {
            $row = [PersonName::join($r['first_name'], $r['last_name']), $r['caretaker_id'] !== null ? (string) $names->name($r['caretaker_id']) : ''];
            if ($personal) {
                array_push($row, $r['address'] !== null ? Address::oneLine($r['address']) : '', $r['phone'], $r['email'], $r['birth_date'] !== null ? self::day($r['birth_date']) : '');
            }
            $out[] = $row;
        }
        $title = $this->title('passive');
        return self::section('passive', $title, false, [self::table('', $columns, $out, 'Keine ' . $title . '.')], landscape: $personal);
    }

    /**
     * Öffentliche Termine, die im Zeitraum liegen oder laufen.
     *
     * @return Section
     */
    private function events(string $from, string $to): array
    {
        $title = $this->title('events');
        return self::section('events', $title, true, [self::eventTable('', $this->app->events()->between($from, $to))]);
    }

    /**
     * Interner Kalender: die in der App gepflegten Termine (nur mit dieser Quelle) und, wenn eingeschaltet, die Zu- und
     * Absagen je Termin und je Mitglied (nach dem Tag des Termins, gleich aus welcher Quelle).
     *
     * @return Section
     */
    private function calendar(string $from, string $to): array
    {
        $tables = [];
        if ($this->app->calendar()->inApp()) {
            $tables[] = self::eventTable('Termine', $this->app->internalEvents()->between($from, $to));
        }
        if ($this->app->eventResponses()->enabled()) {
            $rows = $this->repository()->responses($from, $to);
            $names = (new UserNames($this->app->users))->prime(array_column($rows, 'user_id'));
            $events = [];
            $people = [];
            foreach ($rows as $r) {
                $yes = $r['status'] === EventResponseService::YES;
                $events[$r['event_key']] ??= ['date' => self::day($r['event_date']), 'title' => $r['event_title'], 'yes' => 0, 'no' => 0];
                $events[$r['event_key']][$yes ? 'yes' : 'no']++;
                $people[$r['user_id']] ??= ['name' => (string) $names->name($r['user_id']), 'yes' => 0, 'no' => 0];
                $people[$r['user_id']][$yes ? 'yes' : 'no']++;
            }
            $counts = [self::col('Zusagen', num: true), self::col('Absagen', num: true)];
            $tables[] = self::table('Zu- und Absagen je Termin', [self::col('Datum'), self::col('Termin', wrap: true), ...$counts], array_values(array_map(
                static fn (array $e): array => [$e['date'], $e['title'], (string) $e['yes'], (string) $e['no']],
                $events,
            )), 'Im Zeitraum gibt es keine Zu- oder Absagen.');
            $tables[] = self::table('Zu- und Absagen je Mitglied', [self::col('Name'), ...$counts], self::byName($people, static fn (array $p): array => [
                $p['name'], (string) $p['yes'], (string) $p['no'],
            ]), 'Im Zeitraum gibt es keine Zu- oder Absagen.');
        }
        return self::section('calendar', $this->title('calendar'), true, $tables);
    }

    /**
     * Dienstgrad-Wechsel im Zeitraum (Beförderungen, Rückstufungen, erste Dienstgrade).
     *
     * @return Section
     */
    private function ranks(string $from, string $to): array
    {
        [$start, $end] = $this->utcBounds($from, $to);
        $rows = $this->repository()->rankChanges($start, $end);
        $names = (new UserNames($this->app->users))->prime(array_column($rows, 'user_id'));
        $out = array_map(fn (array $r): array => [
            $this->utcDay($r['changed_at']), (string) $names->name($r['user_id']), self::RANK_KINDS[$r['kind']] ?? $r['kind'], $r['from_title'], $r['to_title'], $r['reason'],
        ], $rows);
        return self::section('ranks', $this->title('ranks'), true, [self::table('Wechsel im Zeitraum', [
            self::col('Datum'), self::col('Name'), self::col('Art'), self::col('Vorher'), self::col('Nachher'), self::col('Grund', wrap: true),
        ], $out, 'Im Zeitraum gab es keine Wechsel.')]);
    }

    /**
     * Im Zeitraum vergebene Titel, die die Person noch trägt (abgenommene Titel stehen nicht mehr in der Datenbank).
     *
     * @return Section
     */
    private function titles(string $from, string $to): array
    {
        [$start, $end] = $this->utcBounds($from, $to);
        $rows = $this->repository()->titleAssignments($start, $end);
        $names = (new UserNames($this->app->users))->prime(array_column($rows, 'user_id'));
        $title = $this->title('titles');
        $out = array_map(fn (array $r): array => [$this->utcDay($r['assigned_at']), (string) $names->name($r['user_id']), $r['title']], $rows);
        return self::section('titles', $title, true, [self::table('Vergeben im Zeitraum', [self::col('Datum'), self::col('Name'), self::col($title)], $out, 'Im Zeitraum wurden keine ' . $title . ' vergeben.')]);
    }

    /**
     * Ämter: Amtszeiten, die die Jahre des Zeitraums berühren, je Amt (laufende Amtszeiten ohne Ende zählen bis heute).
     *
     * @return Section
     */
    private function offices(string $from, string $to): array
    {
        $repo = $this->app->offices()->repository();
        $fromYear = (int) substr($from, 0, 4);
        $toYear = (int) substr($to, 0, 4);
        $holders = $repo->holders();
        $ids = [];
        foreach ($holders as $list) {
            foreach ($list as $h) {
                foreach ([$h, ...$h['companions']] as $p) {
                    if ($p['user_id'] !== null) {
                        $ids[] = $p['user_id'];
                    }
                }
            }
        }
        $names = (new UserNames($this->app->users))->prime($ids);
        $name = static fn (array $p): string => $p['user_id'] !== null ? (string) ($names->name($p['user_id']) ?? $p['name']) : $p['name'];
        $terms = [];
        foreach ($repo->terms() as $t) {
            $last = $t['kind'] === OfficeService::KIND_POINT ? $t['start_year'] : ($t['end_year'] ?? PHP_INT_MAX);
            if ($t['start_year'] <= $toYear && $last >= $fromYear) {
                $terms[$t['office_id']][] = $t;
            }
        }
        $out = [];
        foreach ($repo->offices() as $o) {
            $list = $terms[$o['id']] ?? [];
            usort($list, static fn (array $a, array $b): int => [$a['start_year'], $a['id']] <=> [$b['start_year'], $b['id']]);
            foreach ($list as $t) {
                $people = array_map(static fn (array $h): string => $name($h) . ($h['companions'] !== [] ? ' (mit ' . implode(', ', array_map($name, $h['companions'])) . ')' : ''), $holders[$t['id']] ?? []);
                $years = match (true) {
                    $t['kind'] === OfficeService::KIND_POINT || $t['end_year'] === $t['start_year'] => (string) $t['start_year'],
                    $t['end_year'] === null => 'seit ' . $t['start_year'],
                    default => $t['start_year'] . '–' . $t['end_year'],
                };
                $out[] = [$o['title'], $years, implode(', ', $people), $t['note']];
            }
        }
        return self::section('offices', $this->title('offices'), true, [self::table(
            $fromYear === $toYear ? 'Amtszeiten im Jahr ' . $fromYear : 'Amtszeiten in den Jahren ' . $fromYear . ' bis ' . $toYear,
            [self::col('Amt', wrap: true), self::col('Jahre'), self::col('Inhaber', wrap: true), self::col('Hinweis', wrap: true)],
            $out,
            'In diesen Jahren gibt es keine Amtszeiten.',
        )]);
    }

    /**
     * Inventar: der Bestand heute und der Verlauf im Zeitraum (wer was angelegt, geändert, umgelagert oder gelöscht hat).
     *
     * @return Section
     */
    private function inventory(string $from, string $to): array
    {
        $items = $this->app->inventory()->repository()->active();
        $stock = array_map(static fn (array $i): array => [
            $i['title'], $i['category'], self::number(InventoryService::number($i['quantity'])) . ($i['unit'] !== '' ? ' ' . $i['unit'] : ''), $i['location'],
        ], $items);
        [$start, $end] = $this->utcBounds($from, $to);
        $log = $this->repository()->inventoryLog($start, $end);
        $names = (new UserNames($this->app->users))->prime(array_column($log, 'user_id'));
        $entries = array_map(fn (array $r): array => [
            $this->utcDay($r['created_at']), $r['title'], self::INVENTORY_ACTIONS[$r['action']] ?? $r['action'], $r['location'], (string) $names->name($r['user_id']), $r['reason'],
        ], $log);
        return self::section('inventory', $this->title('inventory'), true, [
            self::table('Bestand (Stand heute)', [self::col('Bezeichnung', wrap: true), self::col('Kategorie'), self::col('Menge', num: true), self::col('Ort', wrap: true)], $stock, 'Das Inventar ist leer.'),
            self::table('Verlauf im Zeitraum', [self::col('Datum'), self::col('Bezeichnung', wrap: true), self::col('Aktion'), self::col('Ort'), self::col('Von'), self::col('Grund', wrap: true)], $entries, 'Im Zeitraum gab es keine Änderungen.'),
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Hilfen                                                            */
    /* ---------------------------------------------------------------- */

    /** @return Column */
    private static function col(string $label, bool $num = false, bool $wrap = false): array
    {
        return ['label' => $label, 'num' => $num, 'wrap' => $wrap];
    }

    /**
     * @param list<Column> $columns
     * @param list<list<string>> $rows
     * @return Table
     */
    private static function table(string $title, array $columns, array $rows, string $empty): array
    {
        return ['title' => $title, 'columns' => $columns, 'rows' => $rows, 'empty' => $empty];
    }

    /**
     * @param list<Table> $tables
     * @return Section
     */
    private static function section(string $key, string $title, bool $dated, array $tables, bool $landscape = false): array
    {
        return ['key' => $key, 'title' => $title, 'dated' => $dated, 'landscape' => $landscape, 'tables' => $tables];
    }

    /**
     * Zeilen je Person, nach Namen sortiert.
     *
     * @template T of array{name: string}
     * @param array<int, T> $people
     * @param \Closure(T): list<string> $row
     * @return list<list<string>>
     */
    private static function byName(array $people, \Closure $row): array
    {
        uasort($people, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return array_values(array_map($row, $people));
    }

    /**
     * Termine als Tabelle (Datum, Zeit, Termin, Ort) aus EventService::between().
     *
     * @param array<int, array<string, mixed>> $events
     * @return Table
     */
    private static function eventTable(string $title, array $events): array
    {
        $rows = [];
        foreach ($events as $e) {
            $start = (string) $e['start_date'];
            $endDay = is_string($e['end_date'] ?? null) && $e['end_date'] !== $start ? $e['end_date'] : null;
            $startTime = is_string($e['start_time'] ?? null) ? $e['start_time'] : null;
            $endTime = is_string($e['end_time'] ?? null) ? $e['end_time'] : null;
            $rows[] = [
                self::day($start) . ($endDay !== null ? ' – ' . self::day($endDay) : ''),
                $startTime === null ? 'ganztägig' : $startTime . ($endTime !== null ? '–' . $endTime : '') . ' Uhr',
                (string) $e['title'],
                (string) $e['location'],
            ];
        }
        return self::table($title, [self::col('Datum'), self::col('Zeit'), self::col('Termin', wrap: true), self::col('Ort', wrap: true)], $rows, 'Im Zeitraum gibt es keine Termine.');
    }

    /**
     * Zeitraum (Tage der Kompanie, beide eingeschlossen) als UTC-Zeitpunkte für Spalten mit Zeitstempel, Ende ausgeschlossen.
     *
     * @return array{0: string, 1: string}
     */
    private function utcBounds(string $from, string $to): array
    {
        $tz = $this->app->time()->timezone();
        $utc = new \DateTimeZone('UTC');
        return [
            (new \DateTimeImmutable($from, $tz))->setTimezone($utc)->format('Y-m-d H:i:s'),
            (new \DateTimeImmutable($to, $tz))->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }

    /** 'Y-m-d' als TT.MM.JJJJ */
    private static function day(string $ymd): string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($ymd, 0, 10));
        return $d === false ? $ymd : $d->format('d.m.Y');
    }

    /** Zeitpunkt aus der Datenbank (UTC, 'Y-m-d H:i:s') als Datum der Kompanie */
    private function utcDay(string $utc): string
    {
        $t = strtotime($utc . ' UTC');
        return $t === false ? self::day($utc) : $this->app->time()->format($t, 'd.m.Y');
    }

    /** Zahl mit deutschem Komma, ganze Zahlen ohne Nachkommastellen */
    public static function number(float|int $v): string
    {
        $v = round((float) $v, 2);
        return number_format($v, floor($v) === $v ? 0 : 2, ',', '.');
    }

    public static function money(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.') . ' €';
    }
}
