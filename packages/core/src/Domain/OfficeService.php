<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Storage\OfficeRepository;

/**
 * Ämter (Issue #112): wer welches Amt wann hatte, auch frühere Inhaber ohne Konto, für den Zeitstrahl.
 *
 * - Gruppen (z. B. Verein und Dachverband) ordnen die Ämter; ein Amt kann auch ohne Gruppe sein.
 * - Eine Amtszeit ist ein Zeitraum in Jahren (ohne Ende: läuft bis heute) oder ein einzelnes Jahr, mit einem oder mehreren
 *   Inhabern und einem kurzen Zusatz. Inhaber sind Konten oder freie Namen. Der Haken „Ehrung“ (honor, Issue #180) ist
 *   bei beiden Arten möglich und ändert nur die Darstellung; einen Titel vergibt nur eine laufende Amtszeit.
 * - Lesen ist öffentlich. Konto und Foto eines Inhabers stehen nur dabei, wenn er in der Mitgliederliste steht (Bereich
 *   directory); wer Ämter verwaltet, sieht die Konten immer.
 * - Ein Amt kann mit einem Titel verknüpft sein: Wer eine laufende Amtszeit hat (Konto, Mitglied), bekommt ihn, wer keine
 *   mehr hat, verliert ihn (syncTitles). Diese Titel lassen sich nicht mehr von Hand vergeben (TitleService::assign).
 * - Wird ein Konto gelöscht, bleiben seine Amtszeiten mit dem gespeicherten Namen stehen (UserData::purge).
 * - Scheidet jemand aus (FormerMemberService::mark), enden seine laufenden Amtszeiten mit dem Austrittsjahr (endFor,
 *   Issue #181). Teilt er die Amtszeit mit anderen, bekommt er eine eigene, beendete; die anderen laufen weiter.
 *
 * @phpstan-import-type Office from OfficeRepository
 * @phpstan-import-type Term from OfficeRepository
 * @phpstan-import-type Holder from OfficeRepository
 */
final class OfficeService
{
    public const TITLE_MAX = 60;
    public const NAME_MAX = 100;
    public const NOTE_MAX = 100;
    public const HOLDERS_MAX = 20;
    public const YEAR_MIN = 1800;
    public const KIND_PERIOD = 'period';
    public const KIND_POINT = 'point';
    public const IMPORT_MAX_BYTES = 200000;
    public const IMPORT_MAX_ROWS = 2000;

    private ?OfficeRepository $repo = null;

    public function __construct(private readonly App $app)
    {
    }

    public function repository(): OfficeRepository
    {
        return $this->repo ??= new OfficeRepository($this->app->db);
    }

    /**
     * Alle Gruppen, Ämter und Amtszeiten für den Zeitstrahl, mit einer festen Zahl von Abfragen.
     * Ämter in der Reihenfolge ihrer Gruppen (ohne Gruppe zuletzt), Amtszeiten: laufende zuerst, dann nach Beginn absteigend.
     *
     * @return array{groups: list<array{id: int, title: string}>, offices: list<array<string, mixed>>}
     */
    public function overview(?User $viewer): array
    {
        $repo = $this->repository();
        $manager = $viewer !== null && $viewer->can(Capability::ManageOffices);
        $groups = $repo->groups();
        $offices = $this->ordered($groups, $repo->offices());
        $holders = $repo->holders();
        $userIds = [];
        foreach ($holders as $list) {
            foreach ($list as $h) {
                if ($h['user_id'] !== null) {
                    $userIds[$h['user_id']] = $h['user_id'];
                }
            }
        }
        $users = $userIds === [] ? [] : $this->app->users->findMany(array_values($userIds));
        $directory = $this->app->features()->enabled('directory');
        $photos = $directory && $users !== [] ? $this->app->memberPhotos()->urls() : [];
        $former = $this->app->formerMembers();
        // Titelnamen für die Pflege (Verknüpfung) und, mit Bereich Titel, als Bezeichnung für eine Person (holder_title)
        $titles = [];
        $titlesShown = $this->app->features()->enabled('titles');
        if ($manager || $titlesShown) {
            foreach ($this->app->titles()->repository()->all(false) as $t) {
                $titles[$t['id']] = $t['title'];
            }
        }
        $termsByOffice = [];
        foreach ($repo->terms() as $t) {
            $termsByOffice[$t['office_id']][] = $t;
        }

        $out = [];
        foreach ($offices as $o) {
            $terms = self::sortTerms($termsByOffice[$o['id']] ?? []);
            $out[] = [
                'id' => $o['id'],
                'group_id' => $o['group_id'],
                'title' => $o['title'],
                'singular' => $o['singular'],
                // Einzahl für den Zeitstrahl einer Person: „Kompaniekönig“ statt „Kompaniekönige“; die eigene Einzahl des
                // Amts, sonst der verknüpfte Titel, null ohne beides (dann die Bezeichnung)
                'holder_title' => $o['singular'] !== '' ? $o['singular'] : ($titlesShown && $o['title_id'] !== null ? ($titles[$o['title_id']] ?? null) : null),
                'terms' => array_map(function (array $t) use ($holders, $users, $photos, $directory, $former, $manager, $viewer): array {
                    return self::termOut($t) + ['holders' => array_map(function (array $h) use ($users, $photos, $directory, $former, $manager, $viewer): array {
                        $user = $h['user_id'] !== null ? ($users[$h['user_id']] ?? null) : null;
                        $out = ['name' => $user !== null ? $user->name : $h['name']];
                        // Konto und Foto: öffentlich nur für Personen in der Mitgliederliste und Ehemalige (Tab daneben),
                        // sonst nur für die Pflege (und das eigene Konto, für „Meine Ämter“).
                        $public = $user !== null && $directory && (MemberService::inDirectory($user) || $former->previousRole($user) !== null);
                        if ($user !== null && ($public || $manager || $viewer?->id === $user->id)) {
                            $out['user_id'] = $user->id;
                        }
                        // Foto nur, wenn der Betrachter es abrufen darf (Regel von MemberPhotoService::forDelivery).
                        if ($public && $this->app->memberPhotos()->visibility($viewer, $user)['visible']) {
                            $out['photo_url'] = $photos[$user->id] ?? null;
                        }
                        return $out;
                    }, $holders[$t['id']] ?? [])];
                }, $terms),
            ] + ($manager ? ['title_id' => $o['title_id'], 'linked_title' => $o['title_id'] !== null ? ($titles[$o['title_id']] ?? null) : null] : []);
        }
        return [
            'groups' => array_map(static fn (array $g): array => ['id' => $g['id'], 'title' => $g['title']], $groups),
            'offices' => $out,
        ];
    }

    // ---- Gruppen ----

    /** @return array{id: int, title: string} */
    public function createGroup(mixed $title): array
    {
        $repo = $this->repository();
        $id = $repo->insertGroup($this->validGroupTitle($title, null), $repo->maxGroupSort() + 10);
        return $this->group($id);
    }

    /** @return array{id: int, title: string} */
    public function updateGroup(int $id, mixed $title): array
    {
        $this->repository()->findGroup($id) ?? throw ApiError::notFound('Gruppe nicht gefunden.');
        $this->repository()->updateGroup($id, ['title' => $this->validGroupTitle($title, $id)]);
        return $this->group($id);
    }

    /** Nur leere Gruppen. */
    public function deleteGroup(int $id): void
    {
        $repo = $this->repository();
        $repo->findGroup($id) ?? throw ApiError::notFound('Gruppe nicht gefunden.');
        $count = $repo->officeCountInGroup($id);
        if ($count > 0) {
            throw ApiError::invalid(sprintf('In der Gruppe %s. Bitte erst verschieben oder löschen.', $count === 1 ? 'steht noch ein Amt' : 'stehen noch ' . $count . ' Ämter'));
        }
        $repo->deleteGroup($id);
    }

    /** @return list<array{id: int, title: string}> */
    public function reorderGroups(mixed $ids): array
    {
        $repo = $this->repository();
        $groups = [];
        foreach ($repo->groups() as $g) {
            $groups[$g['id']] = $g;
        }
        foreach (self::order($ids, array_keys($groups)) as $i => $id) {
            $sort = ($i + 1) * 10;
            if ($groups[$id]['sort'] !== $sort) {
                $repo->updateGroup($id, ['sort' => $sort]);
            }
        }
        return array_map(static fn (array $g): array => ['id' => $g['id'], 'title' => $g['title']], $repo->groups());
    }

    // ---- Ämter ----

    /**
     * @param array<string, mixed> $in title, group_id
     * @return array<string, mixed>
     */
    public function createOffice(array $in): array
    {
        $repo = $this->repository();
        $groupId = $this->validGroupId($in['group_id'] ?? null);
        $id = $repo->insertOffice($groupId, $this->validOfficeTitle($in['title'] ?? null, $groupId, null), $repo->maxOfficeSort() + 10, $this->validSingular($in['singular'] ?? ''));
        return $this->office($id);
    }

    /**
     * Umbenennen, in eine andere Gruppe verschieben, mit einem Titel verknüpfen (title_id, null löst die Verknüpfung).
     *
     * @param array<string, mixed> $in title, singular, group_id, title_id
     * @return array<string, mixed>
     */
    public function updateOffice(int $id, array $in): array
    {
        $repo = $this->repository();
        $office = $repo->findOffice($id) ?? throw ApiError::notFound('Amt nicht gefunden.');
        $groupId = array_key_exists('group_id', $in) ? $this->validGroupId($in['group_id']) : $office['group_id'];
        $data = [];
        if (array_key_exists('group_id', $in)) {
            $data['group_id'] = $groupId;
        }
        if (array_key_exists('title', $in) || $groupId !== $office['group_id']) {
            $data['title'] = $this->validOfficeTitle(array_key_exists('title', $in) ? $in['title'] : $office['title'], $groupId, $id);
        }
        if (array_key_exists('singular', $in)) {
            $data['singular'] = $this->validSingular($in['singular']);
        }
        // Neu verknüpft: den Titel an die laufenden Amtszeiten anpassen. Ein bisher verknüpfter Titel bleibt bei seinen
        // Trägern und ist wieder von Hand zu vergeben.
        $affected = [];
        if (array_key_exists('title_id', $in)) {
            $titleId = $this->validTitleLink($in['title_id'], $id);
            if ($titleId !== $office['title_id']) {
                $data['title_id'] = $titleId;
                $affected = $titleId !== null ? [$titleId] : [];
            }
        }
        $repo->updateOffice($id, $data);
        $this->syncTitles($affected);
        return $this->office($id);
    }

    /** Löscht das Amt mit allen Amtszeiten; ein verknüpfter Titel geht dabei allen verloren, die ihn darüber hatten. */
    public function deleteOffice(int $id): void
    {
        $repo = $this->repository();
        $office = $repo->findOffice($id) ?? throw ApiError::notFound('Amt nicht gefunden.');
        $repo->deleteOffice($id);
        $this->syncTitles($office['title_id'] !== null ? [$office['title_id']] : []);
    }

    /**
     * Neue Reihenfolge der Ämter: alle IDs. Angezeigt werden sie innerhalb ihrer Gruppe in dieser Reihenfolge.
     *
     * @return list<array<string, mixed>>
     */
    public function reorderOffices(mixed $ids): array
    {
        $repo = $this->repository();
        $offices = [];
        foreach ($repo->offices() as $o) {
            $offices[$o['id']] = $o;
        }
        foreach (self::order($ids, array_keys($offices)) as $i => $id) {
            $sort = ($i + 1) * 10;
            if ($offices[$id]['sort'] !== $sort) {
                $repo->updateOffice($id, ['sort' => $sort]);
            }
        }
        return array_map(fn (array $o): array => $this->officeOut($o), $this->ordered($repo->groups(), $repo->offices()));
    }

    /**
     * Was eine Verknüpfung mit einem Titel ändern würde (vor dem Speichern anzeigen): wer ihn bekommt, wer ihn verliert.
     * Lösen oder Wechseln ändert am bisherigen Titel nichts.
     *
     * @return array{add: list<array{name: string, title: string}>, remove: list<array{name: string, title: string}>}
     */
    public function linkPreview(int $id, mixed $titleId): array
    {
        $office = $this->repository()->findOffice($id) ?? throw ApiError::notFound('Amt nicht gefunden.');
        $new = $this->validTitleLink($titleId === '' ? null : $titleId, $office['id']);
        return $this->describePlan($this->plan([$new], [$office['id'] => $new]));
    }

    // ---- Amtszeiten ----

    /**
     * @param array<string, mixed> $in kind, start_year, end_year, note, honor, holders [{user_id} | {name}]
     * @return array<string, mixed>
     */
    public function createTerm(int $officeId, array $in): array
    {
        $office = $this->repository()->findOffice($officeId) ?? throw ApiError::notFound('Amt nicht gefunden.');
        [$term, $holders] = $this->validTerm($in);
        $id = $this->repository()->saveTerm(null, $officeId, $term, $holders);
        $this->syncTitles($office['title_id'] !== null ? [$office['title_id']] : []);
        return $this->term($id);
    }

    /**
     * Ersetzt Angaben und Inhaber einer Amtszeit (fehlende Felder bleiben, wie sie sind).
     *
     * @param array<string, mixed> $in
     * @return array<string, mixed>
     */
    public function updateTerm(int $id, array $in): array
    {
        $repo = $this->repository();
        $current = $repo->findTerm($id) ?? throw ApiError::notFound('Amtszeit nicht gefunden.');
        $merged = $in + [
            'kind' => $current['kind'],
            'start_year' => $current['start_year'],
            'end_year' => $current['end_year'],
            'note' => $current['note'],
            'honor' => $current['honor'],
            'holders' => array_map(static fn (array $h): array => $h['user_id'] !== null ? ['user_id' => $h['user_id'], 'name' => $h['name']] : ['name' => $h['name']], $repo->holdersOf($id)),
        ];
        [$term, $holders] = $this->validTerm($merged);
        $repo->saveTerm($id, $current['office_id'], $term, $holders);
        if ($current['ended_for'] !== null) {
            $repo->releaseEnd($id); // von Hand geändert: Ein neues Austrittsdatum zieht das Ende nicht mehr nach
        }
        $office = $repo->findOffice($current['office_id']);
        $this->syncTitles($office !== null && $office['title_id'] !== null ? [$office['title_id']] : []);
        return $this->term($id);
    }

    public function deleteTerm(int $id): void
    {
        $repo = $this->repository();
        $term = $repo->findTerm($id) ?? throw ApiError::notFound('Amtszeit nicht gefunden.');
        $repo->deleteTerm($id);
        $office = $repo->findOffice($term['office_id']);
        $this->syncTitles($office !== null && $office['title_id'] !== null ? [$office['title_id']] : []);
    }

    // ---- Ausscheiden (Issue #181) ----

    /**
     * Laufende Amtszeiten eines Kontos (Zeitraum ohne Ende, auch mit Haken „Ehrung“) beenden, wenn es als ehemalig
     * eingetragen wird; einzelne Jahre und beendete Amtszeiten bleiben. Ende ist $year, aber nie vor dem Beginn
     * (clamped). Allein: Die Amtszeit bekommt das Ende. Gemeinsam mit anderen: Für das Konto entsteht eine eigene,
     * beendete Amtszeit (gleiches Amt, gleicher Beginn, Zusatz und Haken), die anderen laufen unverändert weiter. Danach
     * gleicht syncTitles die verknüpften Titel ab. Ohne $apply nur die Vorschau.
     *
     * @return list<array{term_id: int, office_id: int, office: string, start_year: int, end_year: int, clamped: bool, shared: bool}>
     */
    public function endFor(int $userId, int $year, bool $apply): array
    {
        $repo = $this->repository();
        $offices = [];
        foreach ($this->ordered($repo->groups(), $repo->offices()) as $i => $o) {
            $offices[$o['id']] = $o + ['pos' => $i];
        }
        $holders = $repo->holders();
        $ending = [];
        foreach ($repo->terms() as $t) {
            if (!self::current($t) || !in_array($userId, array_column($holders[$t['id']] ?? [], 'user_id'), true)) {
                continue;
            }
            $ending[] = [$t, $holders[$t['id']]];
        }
        usort($ending, static fn (array $a, array $b): int => [$offices[$a[0]['office_id']]['pos'] ?? PHP_INT_MAX, $b[0]['start_year']] <=> [$offices[$b[0]['office_id']]['pos'] ?? PHP_INT_MAX, $a[0]['start_year']]);
        $out = array_map(static fn (array $e): array => [
            'term_id' => $e[0]['id'],
            'office_id' => $e[0]['office_id'],
            'office' => $offices[$e[0]['office_id']]['title'] ?? '',
            'start_year' => $e[0]['start_year'],
            'end_year' => max($e[0]['start_year'], $year),
            'clamped' => $year < $e[0]['start_year'],
            'shared' => count($e[1]) > 1,
        ], $ending);
        if (!$apply || $ending === []) {
            return $out;
        }
        $this->app->db->transaction(function () use ($repo, $ending, $userId, $year): void {
            foreach ($ending as [$t, $termHolders]) {
                $end = max($t['start_year'], $year);
                if (count($termHolders) === 1) {
                    $repo->setEnd($t['id'], $end, $userId);
                    continue;
                }
                $mine = [];
                $others = [];
                foreach ($termHolders as $h) {
                    if ($h['user_id'] === $userId) {
                        $mine[] = $h;
                    } else {
                        $others[] = $h;
                    }
                }
                $data = ['kind' => $t['kind'], 'start_year' => $t['start_year'], 'end_year' => null, 'note' => $t['note'], 'honor' => $t['honor']];
                $keep = static fn (array $list): array => array_map(static fn (array $h): array => ['user_id' => $h['user_id'], 'name' => $h['name']], $list);
                $repo->saveTerm($t['id'], $t['office_id'], $data, $keep($others));
                $repo->setEnd($repo->saveTerm(null, $t['office_id'], $data, $keep($mine)), $end, $userId);
            }
        });
        $titles = [];
        foreach ($ending as [$t]) {
            $titles[] = $offices[$t['office_id']]['title_id'] ?? null;
        }
        $this->syncTitles(array_values(array_filter(array_unique($titles), static fn (?int $id): bool => $id !== null)));
        return $out;
    }

    /**
     * Austrittsjahr nachträglich geändert: Amtszeiten, die die App für das Konto beendet hat und die seither niemand von
     * Hand geändert hat (ended_for), enden mit dem neuen Jahr, nie vor ihrem Beginn.
     *
     * @return int Zahl der geänderten Amtszeiten
     */
    public function moveEndFor(int $userId, int $year): int
    {
        $repo = $this->repository();
        $n = 0;
        foreach ($repo->terms() as $t) {
            if ($t['ended_for'] === $userId && $t['end_year'] !== max($t['start_year'], $year)) {
                $repo->setEnd($t['id'], max($t['start_year'], $year), $userId);
                $n++;
            }
        }
        return $n;
    }

    /** Wieder aufgenommen: Die beendeten Amtszeiten bleiben beendet und gehören wieder der Pflege von Hand. */
    public function releaseEndsFor(int $userId): void
    {
        $this->repository()->releaseEndsFor($userId);
    }

    // ---- Titel ----

    /**
     * Verknüpfte Titel an die laufenden Amtszeiten anpassen: Wer (mit Konto, als Mitglied) eine laufende Amtszeit hat,
     * bekommt den Titel; wer keine mehr hat, verliert ihn. Deaktivierte Titel werden nicht neu vergeben. Neu verliehene
     * Titel lösen wie bei der Vergabe von Hand eine Benachrichtigung aus.
     *
     * @param list<int> $titleIds betroffene Titel
     */
    public function syncTitles(array $titleIds): void
    {
        if ($titleIds === []) {
            return;
        }
        $plan = $this->plan($titleIds, []);
        if ($plan['add'] === [] && $plan['remove'] === []) {
            return;
        }
        $titleRepo = $this->app->titles()->repository();
        $now = $this->app->time()->nowUtc();
        $this->app->db->transaction(function () use ($plan, $titleRepo, $now): void {
            foreach ($plan['remove'] as [$userId, $titleId]) {
                $titleRepo->unassign($userId, $titleId);
            }
            foreach ($plan['add'] as [$userId, $titleId]) {
                $titleRepo->assignOne($userId, $titleId, $now);
            }
        });
        $names = [];
        foreach ($titleRepo->all(false) as $t) {
            $names[$t['id']] = $t['title'];
        }
        $added = [];
        foreach ($plan['add'] as [$userId, $titleId]) {
            $added[$userId][] = $names[$titleId] ?? '';
        }
        foreach ($added as $userId => $titles) {
            try {
                $this->app->pushTriggers()->titlesAdded($userId, $titles);
            } catch (\Throwable $e) {
                error_log('Kompanie-App: Benachrichtigung zum Titel nicht eingereiht: ' . $e->getMessage());
            }
        }
    }

    /**
     * Titel, die mit einem Amt verknüpft sind (vergeben über die Amtszeiten, nicht von Hand).
     *
     * @return array<int, array{id: int, title: string}> Titel-ID => Amt
     */
    public function linkedTitles(): array
    {
        $out = [];
        foreach ($this->repository()->offices() as $o) {
            if ($o['title_id'] !== null) {
                $out[$o['title_id']] = ['id' => $o['id'], 'title' => $o['title']];
            }
        }
        return $out;
    }

    // ---- Import ----

    /**
     * Ersterfassung aus einer Tabelle (CSV, Semikolon oder Tabulator): gruppe;amt;beginn;ende;art;personen;zusatz;ehrung.
     * Die Spalte ehrung ist optional (ja, nein oder leer); nur sie macht eine Zeile zur Ehrung.
     * - ende leer und art leer: ein Jahr (Zeitpunkt); „heute“: laufend; art „zeitraum“ oder „zeitpunkt“ erzwingt die Art.
     * - personen mit „&“ getrennt; wer genau so heißt wie genau ein Konto, wird damit verknüpft (link).
     * - Gruppen und Ämter werden nach Namen gefunden oder angelegt; eine Amtszeit, die es genau so schon gibt, wird
     *   übersprungen. Ohne apply nur die Vorschau; mit apply alles in einer Transaktion (bei einem Fehler nichts).
     *
     * @return array{rows: list<array<string, mixed>>, groups_new: int, offices_new: int, terms_new: int, applied: bool}
     */
    public function import(mixed $csv, bool $link, bool $apply): array
    {
        if (!is_string($csv) || trim($csv) === '') {
            throw ApiError::invalid('Bitte die Tabelle einfügen oder eine Datei wählen.');
        }
        if (strlen($csv) > self::IMPORT_MAX_BYTES) {
            throw ApiError::invalid('Die Tabelle ist zu groß (höchstens 200 KB).');
        }
        $csv = (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $lines = preg_split('/\r\n|\r|\n/', $csv) ?: [];
        $repo = $this->repository();
        $groups = [];
        foreach ($repo->groups() as $g) {
            $groups[mb_strtolower($g['title'])] = $g['id'];
        }
        $offices = [];
        foreach ($repo->offices() as $o) {
            $offices[($o['group_id'] ?? 0) . "\0" . mb_strtolower($o['title'])] = $o['id'];
        }
        $existing = [];
        $holdersByTerm = $repo->holders();
        foreach ($repo->terms() as $t) {
            $existing[self::termKey($t['office_id'], $t['kind'], $t['start_year'], $t['end_year'], array_column($holdersByTerm[$t['id']] ?? [], 'name'))] = true;
        }
        $accounts = [];
        if ($link) {
            foreach ($this->app->users->list(true) as $u) {
                $accounts[mb_strtolower($u->name)][] = $u;
            }
        }

        $rows = [];
        $count = 0;
        $newGroups = [];
        $newOffices = [];
        $plannedTerms = [];
        foreach ($lines as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = array_map('trim', str_getcsv($line, str_contains($line, ';') ? ';' : "\t", '"', ''));
            if ($count === 0 && mb_strtolower($cells[0] ?? '') === 'gruppe') {
                continue; // Kopfzeile
            }
            if (++$count > self::IMPORT_MAX_ROWS) {
                throw ApiError::invalid(sprintf('Höchstens %d Zeilen auf einmal.', self::IMPORT_MAX_ROWS));
            }
            $row = ['line' => $i + 1];
            try {
                [$groupTitle, $officeTitle, $term, $holders] = $this->importRow($cells, $accounts);
                $row += [
                    'group' => $groupTitle,
                    'office' => $officeTitle,
                    'kind' => $term['kind'],
                    'start_year' => $term['start_year'],
                    'end_year' => $term['end_year'],
                    'note' => $term['note'],
                    'honor' => $term['honor'],
                    'holders' => array_map(static fn (array $h): array => ['name' => $h['name'], 'user_id' => $h['user_id']], $holders),
                ];
                $groupKey = mb_strtolower($groupTitle);
                $groupRef = $groupTitle === '' ? 0 : ($groups[$groupKey] ?? 'new:' . $groupKey);
                if ($groupTitle !== '' && !isset($groups[$groupKey])) {
                    $newGroups[$groupKey] = $groupTitle;
                }
                $officeKey = $groupRef . "\0" . mb_strtolower($officeTitle);
                $officeRef = $offices[$officeKey] ?? 'new:' . $officeKey;
                if (!isset($offices[$officeKey])) {
                    $newOffices[$officeKey] = [$groupRef, $officeTitle];
                }
                $key = self::termKey($officeRef, $term['kind'], $term['start_year'], $term['end_year'], array_column($holders, 'name'));
                $row['duplicate'] = isset($existing[$key]);
                if (!$row['duplicate']) {
                    $existing[$key] = true;
                    $plannedTerms[] = [$officeKey, $term, $holders];
                }
            } catch (ApiError $e) {
                $row['error'] = $e->getMessage();
            }
            $rows[] = $row;
        }
        if ($rows === []) {
            throw ApiError::invalid('Die Tabelle enthält keine Zeilen.');
        }
        $errors = array_values(array_filter($rows, static fn (array $r): bool => isset($r['error'])));
        if ($apply && $errors !== []) {
            throw ApiError::invalid(sprintf('Zeile %d: %s', $errors[0]['line'], $errors[0]['error']) . (count($errors) > 1 ? sprintf(' (und %d weitere Fehler)', count($errors) - 1) : ''));
        }
        $result = ['rows' => $rows, 'groups_new' => count($newGroups), 'offices_new' => count($newOffices), 'terms_new' => count($plannedTerms), 'applied' => false];
        if (!$apply) {
            return $result;
        }

        $this->app->db->transaction(function () use ($repo, $newGroups, $newOffices, $plannedTerms, &$groups, &$offices): void {
            $sort = $repo->maxGroupSort();
            foreach ($newGroups as $key => $title) {
                $groups[$key] = $repo->insertGroup($title, $sort += 10);
            }
            $sort = $repo->maxOfficeSort();
            foreach ($newOffices as $key => [$groupRef, $title]) {
                $groupId = is_string($groupRef) ? $groups[substr($groupRef, 4)] : ($groupRef === 0 ? null : $groupRef);
                $offices[$key] = $repo->insertOffice($groupId, $title, $sort += 10);
            }
            foreach ($plannedTerms as [$officeKey, $term, $holders]) {
                $repo->saveTerm(null, $offices[$officeKey], $term, $holders);
            }
        });
        $this->syncTitles(array_keys($this->linkedTitles()));
        $result['applied'] = true;
        return $result;
    }

    /**
     * Freie Namen in Amtszeiten mit Konten verknüpfen (nach dem Import ehemaliger Mitglieder): Ein Inhaber ohne Konto,
     * dessen Name (ohne Unterschied in Groß- und Kleinschreibung und bei Leerzeichen) einem der Konten entspricht, bekommt
     * dieses Konto; der gespeicherte Name bleibt. Steht die Person in der Amtszeit schon, bleibt der Eintrag frei. Fehlt
     * die Tabelle (ältere Version), gibt es nichts zu verknüpfen. Ohne $apply nur zählen.
     *
     * @param array<string, int|null> $accounts Name (klein) => Benutzer-ID (null: noch nicht angelegt, nur zählen)
     * @return int verknüpfte bzw. zu verknüpfende Einträge
     */
    public function linkNames(array $accounts, bool $apply): int
    {
        if ($accounts === []) {
            return 0;
        }
        try {
            $holders = $this->repository()->holders();
        } catch (\RuntimeException) {
            return 0;
        }
        $count = 0;
        foreach ($holders as $termHolders) {
            $inTerm = array_filter(array_column($termHolders, 'user_id'));
            $named = [];
            foreach ($termHolders as $h) {
                $key = mb_strtolower(Text::line($h['name']));
                if ($h['user_id'] !== null || !array_key_exists($key, $accounts) || isset($named[$key])) {
                    continue;
                }
                $userId = $accounts[$key];
                if ($userId !== null && in_array($userId, $inTerm, true)) {
                    continue;
                }
                $named[$key] = true;
                $count++;
                if ($apply && $userId !== null) {
                    $this->app->db->update('office_holders', ['user_id' => $userId], ['id' => $h['id']]);
                    $inTerm[] = $userId;
                }
            }
        }
        return $count;
    }

    // ---- intern ----

    /**
     * @param list<string> $cells
     * @param array<string, list<User>> $accounts Name (klein) => Konten
     * @return array{0: string, 1: string, 2: array{kind: string, start_year: int, end_year: int|null, note: string, honor: bool}, 3: list<array{user_id: int|null, name: string}>}
     */
    private function importRow(array $cells, array $accounts): array
    {
        $groupTitle = Text::line($cells[0] ?? '', self::TITLE_MAX + 1);
        $officeTitle = Text::line($cells[1] ?? '', self::TITLE_MAX + 1);
        if ($officeTitle === '') {
            throw ApiError::invalid('Das Amt fehlt (2. Spalte).');
        }
        if (mb_strlen($groupTitle) > self::TITLE_MAX || mb_strlen($officeTitle) > self::TITLE_MAX) {
            throw ApiError::invalid(sprintf('Gruppe und Amt dürfen höchstens %d Zeichen lang sein.', self::TITLE_MAX));
        }
        $end = mb_strtolower(trim($cells[3] ?? ''));
        $kind = match (mb_strtolower(trim($cells[4] ?? ''))) {
            '' => $end === '' ? self::KIND_POINT : self::KIND_PERIOD,
            'zeitraum' => self::KIND_PERIOD,
            'zeitpunkt' => self::KIND_POINT,
            default => throw ApiError::invalid('Art „zeitraum“ oder „zeitpunkt“ (oder leer) erwartet.'),
        };
        // Ehrung nur mit der 8. Spalte; ältere Tabellen mit 7 Spalten ergeben keine Ehrungen (auch nicht bei zeitpunkt).
        $honor = match (mb_strtolower(trim($cells[7] ?? ''))) {
            '', 'nein' => false,
            'ja' => true,
            default => throw ApiError::invalid('Spalte „ehrung“: „ja“, „nein“ oder leer erwartet.'),
        };
        $names = array_values(array_filter(array_map('trim', explode('&', $cells[5] ?? '')), static fn (string $n): bool => $n !== ''));
        $holders = [];
        foreach ($names as $name) {
            $match = $accounts[mb_strtolower(Text::line($name))] ?? [];
            $holders[] = count($match) === 1 ? ['user_id' => $match[0]->id] : ['name' => $name];
        }
        [$term, $holders] = $this->validTerm([
            'kind' => $kind,
            'start_year' => trim($cells[2] ?? ''),
            'end_year' => in_array($end, ['', 'heute'], true) ? null : $end,
            'note' => $cells[6] ?? '',
            'honor' => $honor,
            'holders' => $holders,
        ]);
        return [$groupTitle, $officeTitle, $term, $holders];
    }

    /** @param list<string> $names */
    private static function termKey(int|string $office, string $kind, int $start, ?int $end, array $names): string
    {
        return implode("\0", [$office, $kind, $start, $end ?? '', mb_strtolower(implode('&', $names))]);
    }

    /**
     * Prüft Angaben und Inhaber einer Amtszeit. Konten werden mit ihrem aktuellen Namen gespeichert.
     *
     * @param array<string, mixed> $in
     * @return array{0: array{kind: string, start_year: int, end_year: int|null, note: string, honor: bool}, 1: list<array{user_id: int|null, name: string}>}
     */
    private function validTerm(array $in): array
    {
        $kind = $in['kind'] ?? self::KIND_PERIOD;
        if (!in_array($kind, [self::KIND_PERIOD, self::KIND_POINT], true)) {
            throw ApiError::invalid('Ungültige Art der Amtszeit.');
        }
        $maxYear = (int) $this->app->time()->today()->format('Y') + 10;
        $start = Input::integer($in['start_year'] ?? null);
        if ($start === null || $start < self::YEAR_MIN || $start > $maxYear) {
            throw ApiError::invalid(sprintf('Bitte ein Jahr zwischen %d und %d als Beginn angeben.', self::YEAR_MIN, $maxYear));
        }
        $endRaw = $in['end_year'] ?? null;
        $end = $endRaw === null || $endRaw === '' ? null : Input::integer($endRaw);
        if ($endRaw !== null && $endRaw !== '' && $end === null) {
            throw ApiError::invalid('Ungültiges Jahr als Ende.');
        }
        if ($kind === self::KIND_POINT && $end !== null) {
            throw ApiError::invalid('Ein einzelnes Jahr hat kein Ende.');
        }
        if ($end !== null && ($end < $start || $end > $maxYear)) {
            throw ApiError::invalid(sprintf('Das Ende muss zwischen %d und %d liegen.', $start, $maxYear));
        }
        $note = Text::line($in['note'] ?? '', self::NOTE_MAX + 1);
        if (mb_strlen($note) > self::NOTE_MAX) {
            throw ApiError::invalid(sprintf('Der Zusatz darf höchstens %d Zeichen lang sein.', self::NOTE_MAX));
        }
        $raw = $in['holders'] ?? null;
        if (!is_array($raw) || $raw === []) {
            throw ApiError::invalid('Bitte mindestens eine Person angeben.');
        }
        if (count($raw) > self::HOLDERS_MAX) {
            throw ApiError::invalid(sprintf('Höchstens %d Personen je Amtszeit.', self::HOLDERS_MAX));
        }
        $ids = [];
        foreach ($raw as $h) {
            $id = is_array($h) ? Input::integer($h['user_id'] ?? null) : null;
            if ($id !== null) {
                $ids[] = $id;
            }
        }
        $users = $ids === [] ? [] : $this->app->users->findMany($ids);
        $holders = [];
        $seen = [];
        foreach (array_values($raw) as $h) {
            if (!is_array($h)) {
                throw ApiError::invalid('Ungültige Angabe zu einer Person.');
            }
            $id = Input::integer($h['user_id'] ?? null);
            if ($id !== null) {
                $user = $users[$id] ?? throw ApiError::invalid('Unbekanntes Konto.');
                $holder = ['user_id' => $id, 'name' => mb_substr($user->name, 0, self::NAME_MAX)];
            } else {
                $name = Text::line($h['name'] ?? '', self::NAME_MAX + 1);
                if ($name === '') {
                    throw ApiError::invalid('Bitte für jede Person einen Namen angeben oder ein Konto wählen.');
                }
                if (mb_strlen($name) > self::NAME_MAX) {
                    throw ApiError::invalid(sprintf('Ein Name darf höchstens %d Zeichen lang sein.', self::NAME_MAX));
                }
                $holder = ['user_id' => null, 'name' => $name];
            }
            $key = $holder['user_id'] !== null ? 'u' . $holder['user_id'] : 'n' . mb_strtolower($holder['name']);
            if (isset($seen[$key])) {
                throw ApiError::invalid(sprintf('„%s“ steht doppelt in der Amtszeit.', $holder['name']));
            }
            $seen[$key] = true;
            $holders[] = $holder;
        }
        return [['kind' => $kind, 'start_year' => $start, 'end_year' => $end, 'note' => $note, 'honor' => Input::bool($in['honor'] ?? false)], $holders];
    }

    /**
     * Soll-Stand der verknüpften Titel gegen den Ist-Stand: welche Zuweisungen dazukommen bzw. wegfallen.
     *
     * @param list<int|null> $titleIds betroffene Titel (null wird übersprungen)
     * @param array<int, int|null> $override Amt-ID => Titel-ID, wie sie nach einer Änderung wäre (für die Vorschau)
     * @return array{add: list<array{0: int, 1: int}>, remove: list<array{0: int, 1: int}>}
     */
    private function plan(array $titleIds, array $override): array
    {
        $titleIds = array_values(array_unique(array_filter($titleIds, static fn (?int $t): bool => $t !== null)));
        if ($titleIds === []) {
            return ['add' => [], 'remove' => []];
        }
        $repo = $this->repository();
        $officeTitle = [];
        foreach ($repo->offices() as $o) {
            $officeTitle[$o['id']] = array_key_exists($o['id'], $override) ? $override[$o['id']] : $o['title_id'];
        }
        $holders = $repo->holders();
        $candidates = [];
        foreach ($repo->terms() as $t) {
            $titleId = $officeTitle[$t['office_id']] ?? null;
            if ($titleId === null || !in_array($titleId, $titleIds, true) || !self::current($t)) {
                continue;
            }
            foreach ($holders[$t['id']] ?? [] as $h) {
                if ($h['user_id'] !== null) {
                    $candidates[$titleId][$h['user_id']] = $h['user_id'];
                }
            }
        }
        $all = [];
        foreach ($candidates as $ids) {
            $all += $ids;
        }
        $users = $all === [] ? [] : $this->app->users->findMany(array_values($all));
        $active = [];
        foreach ($this->app->titles()->repository()->all(false) as $t) {
            $active[$t['id']] = $t['active'];
        }
        $have = [];
        foreach ($this->app->titles()->repository()->assignments() as $userId => $ids) {
            foreach ($ids as $id) {
                $have[$id][$userId] = true;
            }
        }
        $add = [];
        $remove = [];
        foreach ($titleIds as $titleId) {
            if (!isset($active[$titleId])) {
                continue;
            }
            $want = [];
            foreach ($candidates[$titleId] ?? [] as $userId) {
                $user = $users[$userId] ?? null;
                if ($user !== null && $user->isMember()) {
                    $want[$userId] = true;
                }
            }
            foreach (array_keys($want) as $userId) {
                if (!isset($have[$titleId][$userId]) && $active[$titleId]) {
                    $add[] = [$userId, $titleId];
                }
            }
            foreach (array_keys($have[$titleId] ?? []) as $userId) {
                if (!isset($want[$userId])) {
                    $remove[] = [$userId, $titleId];
                }
            }
        }
        return ['add' => $add, 'remove' => $remove];
    }

    /**
     * @param array{add: list<array{0: int, 1: int}>, remove: list<array{0: int, 1: int}>} $plan
     * @return array{add: list<array{name: string, title: string}>, remove: list<array{name: string, title: string}>}
     */
    private function describePlan(array $plan): array
    {
        $ids = [];
        foreach ([...$plan['add'], ...$plan['remove']] as [$userId]) {
            $ids[$userId] = $userId;
        }
        $names = (new UserNames($this->app->users))->prime($ids);
        $titles = [];
        foreach ($this->app->titles()->repository()->all(false) as $t) {
            $titles[$t['id']] = $t['title'];
        }
        $describe = static fn (array $list): array => array_map(static fn (array $p): array => ['name' => (string) $names->name($p[0]), 'title' => $titles[$p[1]] ?? ''], $list);
        return ['add' => $describe($plan['add']), 'remove' => $describe($plan['remove'])];
    }

    /** @param Term $t */
    private static function current(array $t): bool
    {
        return $t['kind'] === self::KIND_PERIOD && $t['end_year'] === null;
    }

    /**
     * Laufende zuerst, dann nach Beginn und Ende absteigend; gleiche Jahre in der Reihenfolge der Erfassung.
     *
     * @param list<Term> $terms
     * @return list<Term>
     */
    private static function sortTerms(array $terms): array
    {
        usort($terms, static fn (array $a, array $b): int => [self::current($b), $b['start_year'], $b['end_year'] ?? PHP_INT_MAX, $a['id']]
            <=> [self::current($a), $a['start_year'], $a['end_year'] ?? PHP_INT_MAX, $b['id']]);
        return $terms;
    }

    /**
     * @param Term $t
     * @return array{id: int, kind: string, start_year: int, end_year: int|null, note: string, honor: bool, current: bool}
     */
    private static function termOut(array $t): array
    {
        return ['id' => $t['id'], 'kind' => $t['kind'], 'start_year' => $t['start_year'], 'end_year' => $t['end_year'], 'note' => $t['note'], 'honor' => $t['honor'], 'current' => self::current($t)];
    }

    /**
     * Ämter in der Reihenfolge ihrer Gruppen, ohne Gruppe zuletzt.
     *
     * @param list<array{id: int, title: string, sort: int}> $groups
     * @param list<Office> $offices
     * @return list<Office>
     */
    private function ordered(array $groups, array $offices): array
    {
        $rank = [];
        foreach ($groups as $i => $g) {
            $rank[$g['id']] = $i;
        }
        $pos = array_flip(array_column($offices, 'id'));
        usort($offices, static fn (array $a, array $b): int => [$rank[$a['group_id']] ?? PHP_INT_MAX, $pos[$a['id']]] <=> [$rank[$b['group_id']] ?? PHP_INT_MAX, $pos[$b['id']]]);
        return $offices;
    }

    /**
     * @param mixed $ids neue Reihenfolge
     * @param list<int> $known alle vorhandenen IDs
     * @return list<int>
     */
    private static function order(mixed $ids, array $known): array
    {
        $order = is_array($ids) ? array_map(static fn (mixed $v): ?int => Input::integer($v), array_values($ids)) : [];
        $given = $order;
        sort($given);
        sort($known);
        if ($given !== $known) {
            throw ApiError::invalid('Bitte die Reihenfolge aller Einträge angeben.');
        }
        return array_map('intval', $order);
    }

    /** @return array{id: int, title: string} */
    private function group(int $id): array
    {
        $g = $this->repository()->findGroup($id) ?? throw ApiError::notFound('Gruppe nicht gefunden.');
        return ['id' => $g['id'], 'title' => $g['title']];
    }

    /** @return array<string, mixed> */
    private function office(int $id): array
    {
        return $this->officeOut($this->repository()->findOffice($id) ?? throw ApiError::notFound('Amt nicht gefunden.'));
    }

    /**
     * @param Office $o
     * @return array{id: int, group_id: int|null, title: string, title_id: int|null}
     */
    private function officeOut(array $o): array
    {
        return ['id' => $o['id'], 'group_id' => $o['group_id'], 'title' => $o['title'], 'singular' => $o['singular'], 'title_id' => $o['title_id']];
    }

    /** @return array<string, mixed> */
    private function term(int $id): array
    {
        $repo = $this->repository();
        $t = $repo->findTerm($id) ?? throw ApiError::notFound('Amtszeit nicht gefunden.');
        return self::termOut($t) + ['office_id' => $t['office_id'], 'holders' => array_map(static fn (array $h): array => ['name' => $h['name'], 'user_id' => $h['user_id']], $repo->holdersOf($id))];
    }

    private function validGroupTitle(mixed $raw, ?int $id): string
    {
        $title = $this->validName($raw, 'Bitte eine Bezeichnung für die Gruppe angeben.');
        foreach ($this->repository()->groups() as $g) {
            if ($g['id'] !== $id && mb_strtolower($g['title']) === mb_strtolower($title)) {
                throw ApiError::invalid(sprintf('Die Gruppe „%s“ gibt es schon.', $g['title']));
            }
        }
        return $title;
    }

    private function validOfficeTitle(mixed $raw, ?int $groupId, ?int $id): string
    {
        $title = $this->validName($raw, 'Bitte eine Bezeichnung für das Amt angeben.');
        foreach ($this->repository()->offices() as $o) {
            if ($o['id'] !== $id && $o['group_id'] === $groupId && mb_strtolower($o['title']) === mb_strtolower($title)) {
                throw ApiError::invalid(sprintf('Das Amt „%s“ gibt es in dieser Gruppe schon.', $o['title']));
            }
        }
        return $title;
    }

    /** Einzahl des Amts (optional, z. B. „Kapitän“ zu „Kapitäne“). */
    private function validSingular(mixed $raw): string
    {
        $singular = Text::line(is_string($raw) ? $raw : '', self::TITLE_MAX + 1);
        if (mb_strlen($singular) > self::TITLE_MAX) {
            throw ApiError::invalid(sprintf('Die Einzahl darf höchstens %d Zeichen lang sein.', self::TITLE_MAX));
        }
        return $singular;
    }

    private function validName(mixed $raw, string $missing): string
    {
        $title = Text::line($raw ?? '', self::TITLE_MAX + 1);
        if ($title === '') {
            throw ApiError::invalid($missing);
        }
        if (mb_strlen($title) > self::TITLE_MAX) {
            throw ApiError::invalid(sprintf('Die Bezeichnung darf höchstens %d Zeichen lang sein.', self::TITLE_MAX));
        }
        return $title;
    }

    private function validGroupId(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $id = Input::integer($raw);
        if ($id === null || $this->repository()->findGroup($id) === null) {
            throw ApiError::invalid('Unbekannte Gruppe.');
        }
        return $id;
    }

    /** Ein Titel gehört zu höchstens einem Amt. */
    private function validTitleLink(mixed $raw, int $officeId): ?int
    {
        if ($raw === null) {
            return null;
        }
        $id = Input::integer($raw);
        if ($id === null || $this->app->titles()->repository()->find($id) === null) {
            throw ApiError::invalid('Unbekannter Titel.');
        }
        foreach ($this->repository()->offices() as $o) {
            if ($o['title_id'] === $id && $o['id'] !== $officeId) {
                throw ApiError::invalid(sprintf('Der Titel ist schon mit dem Amt „%s“ verknüpft.', $o['title']));
            }
        }
        return $id;
    }
}
