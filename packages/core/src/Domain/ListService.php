<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Storage\ListRepository;

/**
 * Listen (Issue #121): gespeicherte Listen zum Drucken und als CSV, z. B. Unterschriften- oder Teilnehmerlisten.
 *
 * - Eine Liste hat einen Titel, optional Untertitel und Datum, eine Ausrichtung für den Druck, Spalten und Zeilen.
 *   Alle mit manage_lists sehen und bearbeiten dieselben Listen.
 * - Spalten: Datenspalten (field) mit Werten aus der App oder leere Spalten (blank) zum Ausfüllen auf Papier, auch als
 *   Kästchen. Die Werte werden beim Lesen ermittelt, nie gespeichert, und nur, soweit die ansehende Person sie in der
 *   App ohnehin sehen darf (available).
 * - Zeilen: Mitgliedszeilen (Konto) oder freie Zeilen (freier Name oder leer). Mitgliedszeilen legt nur syncMembers()
 *   an („Mitglieder aktualisieren“); es löscht auch die Zeilen von Konten, die es nicht mehr gibt oder die kein Mitglied
 *   mehr sind. Mitglied heißt: Recht member (wie MemberService::list(false)), also ohne Gäste und Anwärter.
 * - Wird ein Konto gelöscht, bleibt seine Zeile ohne Verknüpfung stehen (UserData::purge), bis die Liste aktualisiert wird.
 *
 * @phpstan-import-type ListRow from ListRepository
 * @phpstan-import-type Column from ListRepository
 * @phpstan-import-type Row from ListRepository
 */
final class ListService
{
    public const TITLE_MAX = 120;
    public const SUBTITLE_MAX = 200;
    public const LABEL_MAX = 60;
    public const NAME_MAX = 100;
    public const COLUMNS_MAX = 20;
    public const ROWS_MAX = 500;
    public const KIND_FIELD = 'field';
    public const KIND_BLANK = 'blank';
    public const ROW_MEMBER = 'member';
    public const ROW_FREE = 'free';
    /** Datenspalten in der Reihenfolge der Auswahl. nr zählt die Zeilen (macht die App). */
    public const FIELDS = ['nr', 'name', 'first_name', 'last_name', 'role', 'rank', 'titles', 'birthday', 'points', 'penalties'];
    public const WIDTHS = ['narrow', 'normal', 'wide'];
    public const ORIENTATIONS = ['portrait', 'landscape'];

    private ?ListRepository $repo = null;

    public function __construct(private readonly App $app)
    {
    }

    public function repository(): ListRepository
    {
        return $this->repo ??= new ListRepository($this->app->db);
    }

    /** @return list<array<string, mixed>> zuletzt geänderte zuerst */
    public function overview(): array
    {
        $lists = $this->repository()->all();
        $counts = $this->repository()->rowCounts();
        $names = (new UserNames($this->app->users))->prime(array_column($lists, 'updated_by'));
        return array_map(static fn (array $l): array => [
            'id' => $l['id'],
            'title' => $l['title'],
            'subtitle' => $l['subtitle'] === '' ? null : $l['subtitle'],
            'date' => $l['list_date'],
            'rows' => $counts[$l['id']] ?? 0,
            'updated_by_name' => $names->name($l['updated_by']),
            'updated_at' => Time::utcToIso($l['updated_at']),
        ], $lists);
    }

    /** Liste mit Spalten, Zeilen und den Werten der Datenspalten, soweit $viewer sie sehen darf. @return array<string, mixed> */
    public function detail(User $viewer, int $id): array
    {
        $list = $this->find($id);
        $repo = $this->repository();
        $columns = $repo->columns($id);
        $rows = $repo->rows($id);
        $available = $this->availableFields($viewer);
        $wanted = [];
        foreach ($columns as $c) {
            if ($c['kind'] === self::KIND_FIELD && $c['field'] !== null && ($available[$c['field']] ?? false)) {
                $wanted[$c['field']] = true;
            }
        }
        $userIds = array_values(array_unique(array_filter(array_column($rows, 'user_id'), static fn (?int $id): bool => $id !== null)));
        $users = $userIds === [] ? [] : $this->app->users->findMany($userIds);
        $values = $this->values(array_keys($wanted), $users);
        $names = (new UserNames($this->app->users))->prime([$list['created_by'], $list['updated_by']]);

        return [
            'id' => $list['id'],
            'title' => $list['title'],
            'subtitle' => $list['subtitle'] === '' ? null : $list['subtitle'],
            'date' => $list['list_date'],
            'orientation' => $list['orientation'],
            'created_by_name' => $names->name($list['created_by']),
            'created_at' => Time::utcToIso($list['created_at']),
            'updated_by_name' => $names->name($list['updated_by']),
            'updated_at' => Time::utcToIso($list['updated_at']),
            'columns' => array_map(static fn (array $c): array => $c + ['available' => $c['kind'] === self::KIND_BLANK || ($available[(string) $c['field']] ?? false)], $columns),
            'rows' => array_map(static function (array $r) use ($users, $values): array {
                if ($r['kind'] === self::ROW_FREE) {
                    return ['kind' => self::ROW_FREE, 'name' => $r['name']];
                }
                $user = $r['user_id'] !== null ? ($users[$r['user_id']] ?? null) : null;
                // Konto gelöscht: Die Zeile bleibt bis „Mitglieder aktualisieren“ ohne Namen stehen.
                return $user === null
                    ? ['kind' => self::ROW_MEMBER, 'user_id' => null, 'name' => '', 'deleted' => true, 'values' => (object) []]
                    : [
                        'kind' => self::ROW_MEMBER, 'user_id' => $user->id, 'name' => $user->name,
                        // zum Sortieren nach Namen in der App
                        'first_name' => $user->firstName, 'last_name' => $user->lastName,
                        'deleted' => false, 'values' => (object) ($values[$user->id] ?? []),
                    ];
            }, $rows),
            'fields' => array_map(static fn (string $f): array => ['key' => $f, 'available' => $available[$f]], self::FIELDS),
        ];
    }

    /**
     * Neue Liste. Ohne Spalten wie die Tabellen der Kompanie: Name (Nachname), Vorname und eine breite leere Spalte
     * „Unterschrift“; ohne Zeilen leer.
     *
     * @param array<string, mixed> $in
     * @return array<string, mixed>
     */
    public function create(User $actor, array $in): array
    {
        $in += [
            'columns' => [
                ['kind' => self::KIND_FIELD, 'field' => 'last_name', 'label' => 'Name'],
                ['kind' => self::KIND_FIELD, 'field' => 'first_name'],
                ['kind' => self::KIND_BLANK, 'label' => 'Unterschrift', 'width' => 'wide'],
            ],
            'rows' => [],
        ];
        $data = $this->validate($in, []);
        $now = $this->app->time()->nowUtc();
        $id = $this->app->db->transaction(function () use ($data, $actor, $now): int {
            $repo = $this->repository();
            $id = $repo->insert($data['list'] + ['created_by' => $actor->id, 'created_at' => $now, 'updated_by' => $actor->id, 'updated_at' => $now]);
            $repo->replaceColumns($id, $data['columns']);
            $repo->replaceRows($id, $data['rows']);
            return $id;
        });
        return $this->detail($actor, $id);
    }

    /**
     * Liste ganz ersetzen: Titel, Untertitel, Datum, Ausrichtung, Spalten und Zeilen. Mit updated_at (Stand, den die
     * App geladen hat) nur, wenn niemand die Liste inzwischen geändert hat; sonst 409 conflict, statt dessen Änderungen
     * zu überschreiben. updated_at hat Sekunden: Zwei Änderungen in derselben Sekunde unterscheidet das nicht.
     *
     * @param array<string, mixed> $in
     * @return array<string, mixed>
     */
    public function save(User $actor, int $id, array $in): array
    {
        $list = $this->find($id);
        $loaded = $in['updated_at'] ?? null;
        if (is_string($loaded) && $loaded !== Time::utcToIso($list['updated_at'])) {
            $by = (new UserNames($this->app->users))->name($list['updated_by']) ?? 'Jemand';
            throw new ApiError(409, 'conflict', $by . ' hat die Liste inzwischen geändert. Bitte die Liste neu laden und deine Änderungen noch einmal eintragen.');
        }
        $repo = $this->repository();
        $data = $this->validate($in, $repo->rows($id));
        $now = $this->app->time()->nowUtc();
        $this->app->db->transaction(function () use ($repo, $id, $data, $actor, $now): void {
            $repo->update($id, $data['list'] + ['updated_by' => $actor->id, 'updated_at' => $now]);
            $repo->replaceColumns($id, $data['columns']);
            $repo->replaceRows($id, $data['rows']);
        });
        return $this->detail($actor, $id);
    }

    /** Kopie mit allen Spalten und Zeilen, Titel mit „(Kopie)“. @return array<string, mixed> */
    public function copy(User $actor, int $id): array
    {
        $list = $this->find($id);
        $repo = $this->repository();
        $now = $this->app->time()->nowUtc();
        $title = mb_substr($list['title'], 0, self::TITLE_MAX - 8) . ' (Kopie)';
        $newId = $this->app->db->transaction(function () use ($repo, $list, $id, $title, $actor, $now): int {
            $newId = $repo->insert([
                'title' => $title,
                'subtitle' => $list['subtitle'],
                'list_date' => $list['list_date'],
                'orientation' => $list['orientation'],
                'created_by' => $actor->id, 'created_at' => $now, 'updated_by' => $actor->id, 'updated_at' => $now,
            ]);
            $repo->replaceColumns($newId, $repo->columns($id));
            $repo->replaceRows($newId, $repo->rows($id));
            return $newId;
        });
        return $this->detail($actor, $newId);
    }

    public function delete(int $id): void
    {
        $this->find($id);
        $this->repository()->delete($id);
    }

    /**
     * „Mitglieder aktualisieren“: Zeilen für alle Mitglieder ohne Zeile anlegen und die Zeilen von Konten löschen, die es
     * nicht mehr gibt oder die kein Mitglied mehr sind. Freie Zeilen bleiben. Neue Zeilen werden nach Nachname zwischen
     * die vorhandenen Mitgliedszeilen eingeordnet; gibt es keine, kommen sie an den Anfang. Ohne $apply nur die Übersicht.
     *
     * @return array{added: list<array{user_id: int, name: string}>, removed: list<array{user_id: int|null, name: string|null}>, applied: bool, list: array<string, mixed>|null}
     */
    public function syncMembers(User $actor, int $id, bool $apply): array
    {
        $this->find($id);
        $repo = $this->repository();
        $rows = $repo->rows($id);
        $members = [];
        foreach ($this->app->users->list(false) as $u) {
            if ($u->isMember()) {
                $members[$u->id] = $u;
            }
        }
        $inList = [];
        foreach ($rows as $r) {
            if ($r['kind'] === self::ROW_MEMBER && $r['user_id'] !== null) {
                $inList[$r['user_id']] = true;
            }
        }
        $others = array_diff_key($inList, $members);
        $known = $members + ($others === [] ? [] : $this->app->users->findMany(array_keys($others)));

        $removed = [];
        $kept = [];
        foreach ($rows as $r) {
            if ($r['kind'] === self::ROW_MEMBER && ($r['user_id'] === null || !isset($members[$r['user_id']]))) {
                $removed[] = ['user_id' => $r['user_id'], 'name' => $r['user_id'] !== null ? ($known[$r['user_id']] ?? null)?->name : null];
                continue;
            }
            $kept[] = $r;
        }
        $new = array_values(array_diff_key($members, $inList));
        usort($new, self::compareNames(...));
        $added = array_map(static fn (User $u): array => ['user_id' => $u->id, 'name' => $u->name], $new);

        if (!$apply || ($added === [] && $removed === [])) {
            return ['added' => $added, 'removed' => $removed, 'applied' => false, 'list' => null];
        }
        if (count($kept) + count($new) > self::ROWS_MAX) {
            throw ApiError::invalid(sprintf('Die Liste hätte dann mehr als %d Zeilen.', self::ROWS_MAX));
        }
        $kept = self::mergeSorted($kept, $new, $members);
        $now = $this->app->time()->nowUtc();
        $this->app->db->transaction(function () use ($repo, $id, $kept, $actor, $now): void {
            $repo->replaceRows($id, $kept);
            $repo->update($id, ['updated_by' => $actor->id, 'updated_at' => $now]);
        });
        return ['added' => $added, 'removed' => $removed, 'applied' => true, 'list' => $this->detail($actor, $id)];
    }

    /**
     * Welche Datenspalten $viewer sehen darf: nur, was die App ihm ohnehin zeigt.
     *
     * @return array<string, bool>
     */
    public function availableFields(User $viewer): array
    {
        $f = $this->app->features();
        $directory = $f->enabled('directory');
        return [
            'nr' => true,
            'name' => true,
            'first_name' => true,
            'last_name' => true,
            // Rollen stehen nur in der Mitgliederliste der Verwaltung.
            'role' => (bool) array_filter(Capability::memberList(), static fn (Capability $c): bool => $viewer->can($c)),
            // Dienstgrad und Titel: in der Mitgliederliste für alle oder für die, die sie pflegen.
            'rank' => $f->enabled('ranks') && ($directory || $viewer->can(Capability::ManageRanks)),
            'titles' => $f->enabled('titles') && ($directory || $viewer->can(Capability::ManageTitles)),
            // Geburtstage sehen nur Mitglieder, und nur die, die die Person freigegeben hat (Issue #1).
            'birthday' => $f->enabled('birthdays') && BirthdayService::canSee($viewer),
            'points' => $f->enabled('points') && $viewer->can(Capability::ManagePoints),
            'penalties' => $f->enabled('penalties') && $viewer->can(Capability::ManagePenalties),
        ];
    }

    /**
     * Werte der Datenspalten je Konto, mit einer festen Zahl von Abfragen.
     *
     * @param list<string> $fields
     * @param array<int, User> $users
     * @return array<int, array<string, mixed>>
     */
    private function values(array $fields, array $users): array
    {
        if ($users === []) {
            return [];
        }
        $want = array_fill_keys($fields, true);
        $ranks = isset($want['rank']) ? $this->app->ranks()->titles() : [];
        $titles = isset($want['titles']) ? $this->app->titles()->byUser() : [];
        $totals = isset($want['points']) || isset($want['penalties']) ? $this->app->scores()->totalsByUser() : [];
        $birthdays = [];
        if (isset($want['birthday'])) {
            foreach ($this->app->birthdays()->repository()->visible() as $b) {
                $birthdays[$b['user_id']] = sprintf('%02d.%02d.', $b['day'], $b['month']);
            }
        }
        $out = [];
        foreach ($users as $id => $u) {
            $v = [];
            foreach ($fields as $field) {
                $v[$field] = match ($field) {
                    'name' => $u->name,
                    'first_name' => $u->firstName,
                    'last_name' => $u->lastName,
                    // Grundrolle, dahinter die Zusatzrollen (Issue #66), wie roleLabels in der App
                    'role' => implode(', ', array_map($this->app->roles()->label(...), [$u->role, ...$this->app->roles()->extraRolesOf($u)])),
                    'rank' => $ranks[$id] ?? null,
                    'titles' => array_map(static fn (array $t): string => $t['title'], $titles[$id] ?? []),
                    // Geburtstage nur von Mitgliedern (BirthdayService::list)
                    'birthday' => BirthdayService::isMember($u) ? ($birthdays[$id] ?? null) : null,
                    'points' => $totals[$id]['points'] ?? 0,
                    'penalties' => $totals[$id]['open_cents'] ?? 0,
                    default => null,
                };
            }
            unset($v['nr']);
            $out[$id] = $v;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $in
     * @param list<Row> $current Zeilen der Liste vor dem Speichern
     * @return array{list: array{title: string, subtitle: string, list_date: string|null, orientation: string}, columns: list<Column>, rows: list<Row>}
     */
    private function validate(array $in, array $current): array
    {
        $title = Text::line($in['title'] ?? '', self::TITLE_MAX);
        if ($title === '') {
            throw ApiError::invalid('Bitte einen Titel angeben.');
        }
        $date = $in['date'] ?? null;
        if ($date === '') {
            $date = null;
        }
        if ($date !== null && !Input::isDate($date)) {
            throw ApiError::invalid('Ungültiges Datum.');
        }
        $orientation = $in['orientation'] ?? 'portrait';
        if (!in_array($orientation, self::ORIENTATIONS, true)) {
            throw ApiError::invalid('Ausrichtung: Hochformat (portrait) oder Querformat (landscape).');
        }
        return [
            'list' => ['title' => $title, 'subtitle' => Text::line($in['subtitle'] ?? '', self::SUBTITLE_MAX), 'list_date' => $date, 'orientation' => $orientation],
            'columns' => self::validColumns($in['columns'] ?? null),
            'rows' => $this->validRows($in['rows'] ?? null, $current),
        ];
    }

    /** @return list<Column> */
    private static function validColumns(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw) || $raw === []) {
            throw ApiError::invalid('Bitte mindestens eine Spalte angeben.');
        }
        if (count($raw) > self::COLUMNS_MAX) {
            throw ApiError::invalid(sprintf('Höchstens %d Spalten.', self::COLUMNS_MAX));
        }
        $out = [];
        foreach ($raw as $c) {
            if (!is_array($c)) {
                throw ApiError::invalid('Ungültige Spalte.');
            }
            $kind = $c['kind'] ?? null;
            if ($kind !== self::KIND_FIELD && $kind !== self::KIND_BLANK) {
                throw ApiError::invalid('Spalte: Art field (Wert aus der App) oder blank (leer).');
            }
            $field = null;
            if ($kind === self::KIND_FIELD) {
                $field = $c['field'] ?? null;
                if (!is_string($field) || !in_array($field, self::FIELDS, true)) {
                    throw ApiError::invalid('Unbekannte Datenspalte: ' . (is_scalar($field) ? (string) $field : '?') . '.');
                }
            }
            $width = $c['width'] ?? 'normal';
            if (!in_array($width, self::WIDTHS, true)) {
                throw ApiError::invalid('Breite einer Spalte: narrow, normal oder wide.');
            }
            $out[] = [
                'kind' => $kind,
                'field' => $field,
                'label' => Text::line($c['label'] ?? '', self::LABEL_MAX),
                'width' => $width,
                // Kästchen zum Abhaken nur in leeren Spalten
                'checkbox' => $kind === self::KIND_BLANK && Input::bool($c['checkbox'] ?? false),
            ];
        }
        return $out;
    }

    /**
     * Mitgliedszeilen: nur Konten, die schon in der Liste stehen (z. B. beim Umsortieren), oder Mitglieder; jedes Konto
     * höchstens einmal. user_id null steht für ein gelöschtes Konto.
     *
     * @param list<Row> $current
     * @return list<Row>
     */
    private function validRows(mixed $raw, array $current): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            throw ApiError::invalid('Ungültige Zeilen.');
        }
        if (count($raw) > self::ROWS_MAX) {
            throw ApiError::invalid(sprintf('Höchstens %d Zeilen.', self::ROWS_MAX));
        }
        $existing = array_fill_keys(array_filter(array_column($current, 'user_id'), static fn (?int $id): bool => $id !== null), true);
        $ids = [];
        foreach ($raw as $r) {
            if (is_array($r) && ($r['kind'] ?? null) === self::ROW_MEMBER && ($r['user_id'] ?? null) !== null) {
                $id = Input::integer($r['user_id']);
                if ($id === null || $id <= 0) {
                    throw ApiError::invalid('Ungültiges Konto in einer Zeile.');
                }
                if (isset($ids[$id])) {
                    throw ApiError::invalid('Ein Mitglied steht doppelt in der Liste.');
                }
                $ids[$id] = $id;
            }
        }
        $new = array_values(array_diff_key($ids, $existing));
        if ($new !== []) {
            $found = $this->app->users->findMany($new);
            foreach ($new as $id) {
                if (!isset($found[$id]) || !$found[$id]->isMember()) {
                    throw ApiError::invalid('Mitglieder kommen über „Mitglieder aktualisieren“ in die Liste.');
                }
            }
        }
        $out = [];
        foreach ($raw as $r) {
            $kind = is_array($r) ? ($r['kind'] ?? null) : null;
            if ($kind === self::ROW_MEMBER) {
                $id = ($r['user_id'] ?? null) === null ? null : Input::integer($r['user_id']);
                $out[] = ['kind' => self::ROW_MEMBER, 'user_id' => $id, 'name' => ''];
            } elseif ($kind === self::ROW_FREE) {
                $out[] = ['kind' => self::ROW_FREE, 'user_id' => null, 'name' => Text::line($r['name'] ?? '', self::NAME_MAX)];
            } else {
                throw ApiError::invalid('Zeile: Art member (Mitglied) oder free (frei).');
            }
        }
        return $out;
    }

    /**
     * Neue Mitgliedszeilen (nach Namen sortiert) einordnen, in einem Durchgang: jede vor die erste vorhandene
     * Mitgliedszeile, die nach Namen später kommt, sonst hinter die letzte; ohne Mitgliedszeilen an den Anfang.
     *
     * @param list<Row> $rows
     * @param list<User> $new
     * @param array<int, User> $members
     * @return list<Row>
     */
    private static function mergeSorted(array $rows, array $new, array $members): array
    {
        $row = static fn (User $u): array => ['kind' => self::ROW_MEMBER, 'user_id' => $u->id, 'name' => ''];
        $lastMember = -1;
        foreach ($rows as $i => $r) {
            if ($r['kind'] === self::ROW_MEMBER && $r['user_id'] !== null && isset($members[$r['user_id']])) {
                $lastMember = $i;
            }
        }
        $out = [];
        foreach ($rows as $i => $r) {
            if ($r['kind'] === self::ROW_MEMBER && $r['user_id'] !== null && isset($members[$r['user_id']])) {
                while ($new !== [] && self::compareNames($new[0], $members[$r['user_id']]) < 0) {
                    $out[] = $row(array_shift($new));
                }
            }
            if ($i === 0 && $lastMember === -1) {
                array_push($out, ...array_map($row, $new));
                $new = [];
            }
            $out[] = $r;
            if ($i === $lastMember) {
                array_push($out, ...array_map($row, $new));
                $new = [];
            }
        }
        // Leere Liste
        array_push($out, ...array_map($row, $new));
        return $out;
    }

    /**
     * Nach Nachname, dann Vorname, wie die App (memberSort.ts: localeCompare 'de', Grundbuchstaben, Zahlen nach Wert):
     * ohne Nachnamen (z. B. Funktionskonto) zählt der Vorname.
     */
    private static function compareNames(User $a, User $b): int
    {
        $last = static fn (User $u): string => trim($u->lastName) !== '' ? $u->lastName : $u->firstName;
        return self::compareText($last($a), $last($b)) ?: self::compareText($a->firstName, $b->firstName) ?: $a->id <=> $b->id;
    }

    private static function compareText(string $a, string $b): int
    {
        static $collator = null;
        // Mit der Erweiterung intl wie die App; sonst Akzente und Umlaute als Grundbuchstabe, Zahlen nach Wert.
        if ($collator === null && class_exists(\Collator::class)) {
            $collator = new \Collator('de_DE');
            $collator->setStrength(\Collator::PRIMARY);
            $collator->setAttribute(\Collator::NUMERIC_COLLATION, \Collator::ON);
        }
        if ($collator instanceof \Collator) {
            return (int) $collator->compare(trim($a), trim($b));
        }
        return strnatcasecmp(self::fold($a), self::fold($b));
    }

    /** Kleinbuchstaben, Akzente und Umlaute als Grundbuchstabe (DIN 5007-1), ß als ss. */
    private static function fold(string $s): string
    {
        return strtr(mb_strtolower(trim($s)), [
            'ä' => 'a', 'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n', 'ö' => 'o', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'ß' => 'ss',
        ]);
    }

    /** @return ListRow */
    private function find(int $id): array
    {
        return $this->repository()->find($id) ?? throw ApiError::notFound('Liste nicht gefunden.');
    }
}
