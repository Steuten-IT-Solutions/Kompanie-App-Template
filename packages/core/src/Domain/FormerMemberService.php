<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Storage\FormerMemberRepository;

/**
 * Ehemalige Mitglieder: ausgetreten, verstorben oder sonst ausgeschieden.
 *
 * - Das Konto bleibt mit allen Daten (Punkte, Strafen, Dienstgrad mit Verlauf, Titel, Ämter, Foto …), bekommt aber die
 *   Rolle Gast (Port\UserDirectory::deactivate): keine Rechte, nicht in Listen, Umfragen, Geburtstagen oder
 *   Benachrichtigungen; Standalone sperrt die Anmeldung. Zusatzrollen und Ausnahmen bei den Rechten fallen weg, ihr
 *   Stand wird gemerkt (restore), damit „Wieder aufnehmen“ ihn herstellt.
 * - Laufende Ämter enden mit dem Ausscheiden (Issue #181, OfficeService::endFor), im selben Vorgang wie das Eintragen:
 *   Endjahr ist das Jahr des Austritts (leer: dieses Jahr), nie vor dem Beginn. Ändert sich das Austrittsdatum, zieht
 *   die App das Ende nach, solange niemand die Amtszeit von Hand geändert hat; „Wieder aufnehmen“ öffnet nichts wieder.
 * - Verwalten darf, wer Konten anlegt (create_users), mit denselben Grenzen wie beim Löschen (MemberService::guardAccount,
 *   bei Ehemaligen mit ihrer Grundrolle von vorher).
 * - Import (Ersterfassung, z. B. aus einer Chronik): CSV vorname;nachname;eintritt;ausgeschieden;grund. Für jede Person
 *   entsteht ein Konto (zufälliges Passwort, das niemand erfährt), das gleich als ehemalig eingetragen wird. Wer schon
 *   ein Konto mit genau diesem Namen hat, wird übersprungen.
 * - Aufbewahrungsfrist (DSGVO, Standard aus): Ist sie eingestellt, löscht die tägliche Aufgabe RETENTION_TASK Konto und
 *   Daten aller Ehemaligen, deren Ausscheiden länger als so viele Jahre zurückliegt (wie „Konto löschen“: App::userDeleted;
 *   Amtszeiten bleiben als Chronik mit dem Namen). Gründungsmitglieder und Verstorbene nur, wenn eigens eingestellt.
 *   Stichtag ist das Datum des Ausscheidens (nur ein Jahr: der 31.12.), ohne Datum der Tag des Eintragens.
 */
final class FormerMemberService
{
    public const REASON_LEFT = 'left';
    public const REASON_DECEASED = 'deceased';
    public const REASON_OTHER = 'other';
    /** Grund => Bezeichnung (auch die Schreibweise im Import) */
    public const REASONS = [self::REASON_LEFT => 'ausgetreten', self::REASON_DECEASED => 'verstorben', self::REASON_OTHER => 'sonstiges'];
    public const YEAR_MIN = 1800;
    public const IMPORT_MAX_BYTES = 200000;
    public const IMPORT_MAX_ROWS = 500;
    /** Einstellung (gehört zu den Daten): {years: int (0 = aus), include_founders: bool, include_deceased: bool} */
    public const RETENTION_SETTING = 'former_retention';
    public const RETENTION_TASK = 'former_retention';
    public const RETENTION_MAX_YEARS = 50;

    /** @var array<int, array<string, mixed>>|null Einträge dieser Anfrage (eine Abfrage) */
    private ?array $all = null;

    public function __construct(private readonly App $app)
    {
    }

    public function repository(): FormerMemberRepository
    {
        return new FormerMemberRepository($this->app->db);
    }

    /** @return array<int, array<string, mixed>> Benutzer-ID => Eintrag */
    private function all(): array
    {
        return $this->all ??= $this->repository()->all();
    }

    /** @return list<int> IDs aller Ehemaligen */
    public function ids(): array
    {
        return array_keys($this->all());
    }

    /**
     * Grundrolle vor dem Ausscheiden, wenn die Person ehemalig ist; sonst null. Hat das Konto inzwischen wieder eine Rolle
     * (z. B. im WordPress-Backend vergeben), gilt es nicht mehr als ehemalig.
     */
    public function previousRole(User $user): ?string
    {
        $row = $this->all()[$user->id] ?? null;
        if ($row === null || $user->role !== Roles::GUEST) {
            return null;
        }
        $level = $row['restore']['level'] ?? null;
        return is_string($level) && $level !== '' ? $level : Roles::MEMBER;
    }

    /**
     * Alle Ehemaligen für die Verwaltung, nach Namen. Punkte und offene Beträge nur mit dem jeweiligen Recht (wie in der
     * Mitgliederliste).
     *
     * @return list<array<string, mixed>>
     */
    public function list(User $viewer): array
    {
        $rows = $this->all();
        $users = $this->app->users->findMany(array_keys($rows));
        $totals = $this->app->scores()->totalsByUser();
        $out = [];
        foreach ($rows as $id => $row) {
            $user = $users[$id] ?? null;
            if ($user === null || $user->role !== Roles::GUEST) {
                continue; // Konto gelöscht bzw. anderswo wieder mit einer Rolle versehen
            }
            $out[] = self::out($user, $row) + [
                'points' => $viewer->can(Capability::ManagePoints) ? ($totals[$id]['points'] ?? 0) : null,
                'open_cents' => $viewer->can(Capability::ManagePenalties) ? ($totals[$id]['open_cents'] ?? 0) : null,
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return $out;
    }

    /**
     * Ehemalige für die öffentliche Mitgliederliste (Tab „Ehemalige“, Bereich directory): nur Name, Gründungsmitglied,
     * Eintritt und Ausscheiden (joined_on, left_on wie eingetragen: JJJJ-MM-TT, JJJJ oder leer; dazu die Jahre für ältere
     * Apps). Kein Grund, keine Punkte.
     *
     * @return list<array{id: int, name: string, first_name: string, last_name: string, founder: bool, joined_year: string, left_year: string, joined_on: string, left_on: string}>
     */
    public function directory(): array
    {
        $rows = $this->all();
        $users = $this->app->users->findMany(array_keys($rows));
        $out = [];
        foreach ($rows as $id => $row) {
            $user = $users[$id] ?? null;
            if ($user === null || $user->role !== Roles::GUEST) {
                continue; // wie in list()
            }
            $out[] = [
                'id' => $user->id,
                'name' => $user->name,
                'first_name' => $user->firstName,
                'last_name' => $user->lastName,
                'founder' => $row['founder'],
                'joined_year' => substr($row['joined_on'], 0, 4),
                'left_year' => substr($row['left_on'], 0, 4),
                'joined_on' => $row['joined_on'],
                'left_on' => $row['left_on'],
            ];
        }
        return $out;
    }

    /**
     * Angaben zum Ausscheiden einer Person für ihre Seite in der Verwaltung; null, wenn sie nicht ehemalig ist.
     *
     * @return array{reason: string, joined_on: string, founder: bool, left_on: string, previous_level: string}|null
     */
    public function of(int $id): ?array
    {
        $row = $this->all()[$id] ?? null;
        $user = $row !== null ? $this->app->users->find($id) : null;
        if ($row === null || $user === null || $this->previousRole($user) === null) {
            return null;
        }
        $out = self::out($user, $row);
        return ['reason' => $out['reason'], 'joined_on' => $out['joined_on'], 'founder' => $out['founder'], 'left_on' => $out['left_on'], 'previous_level' => $out['previous_level']];
    }

    /**
     * Person als ehemalig eintragen: Konto stilllegen, Daten behalten.
     *
     * @param array<string, mixed> $in reason, joined_on, founder, left_on
     * @return array<string, mixed> wie of(), dazu offices_ended (Zahl der beendeten Amtszeiten)
     */
    public function mark(User $actor, int $id, array $in): array
    {
        $target = $this->markable($actor, $id);
        $data = self::valid($in, $this->app->time()->today());
        $ended = $this->app->db->transaction(function () use ($actor, $target, $data): int {
            $this->deactivate($actor, $target, $data);
            return count($this->app->offices()->endFor($target->id, $this->leftYear($data['left_on']), true));
        });
        return ($this->of($id) ?? throw ApiError::notFound('Mitglied nicht gefunden.')) + ['offices_ended' => $ended];
    }

    /**
     * Vorschau für „Als ehemalig eintragen“: welche laufenden Ämter mit dem Ausscheiden enden, mit welchem Jahr.
     *
     * @return array{end_year: int, terms: list<array{office: string, start_year: int, end_year: int, clamped: bool, shared: bool}>}
     */
    public function officesPreview(User $actor, int $id, mixed $leftOn): array
    {
        $target = $this->markable($actor, $id);
        $year = $this->leftYear(self::date(is_string($leftOn) ? $leftOn : '', 'Ausgeschieden', $this->app->time()->today()));
        $terms = array_map(static fn (array $t): array => array_intersect_key($t, array_flip(['office', 'start_year', 'end_year', 'clamped', 'shared'])), $this->app->offices()->endFor($target->id, $year, false));
        return ['end_year' => $year, 'terms' => $terms];
    }

    /** Prüft, ob $actor die Person als ehemalig eintragen darf. */
    private function markable(User $actor, int $id): User
    {
        if ($actor->id === $id) {
            throw ApiError::invalid('Das eigene Konto kannst du nicht als ehemalig eintragen.');
        }
        $target = $this->app->users->find($id) ?? throw ApiError::notFound('Mitglied nicht gefunden.');
        if ($this->previousRole($target) !== null) {
            throw ApiError::invalid('Diese Person ist schon als ehemalig eingetragen.');
        }
        if ($target->role === Roles::GUEST) {
            throw ApiError::invalid('Gäste gehören nicht zur Kompanie und lassen sich nicht als ehemalig eintragen.');
        }
        $this->app->members()->guardAccount($actor, $target, 'Dieses Konto darfst du nicht als ehemalig eintragen.');
        if ($target->isAdmin() && $this->app->users->countAdmins() <= 1) {
            throw ApiError::invalid('Der letzte Admin kann nicht als ehemalig eingetragen werden.');
        }
        return $target;
    }

    /** Jahr, mit dem die Ämter enden: aus dem Austritt (JJJJ-MM-TT oder JJJJ), sonst dieses Jahr. */
    private function leftYear(string $leftOn): int
    {
        return $leftOn !== '' ? (int) substr($leftOn, 0, 4) : (int) $this->app->time()->today()->format('Y');
    }

    /**
     * Grund, Eintritt und Ausscheiden einer ehemaligen Person ändern.
     *
     * @param array<string, mixed> $in reason, joined_on, founder, left_on (fehlende bleiben)
     * @return array<string, mixed> wie of()
     */
    public function update(User $actor, int $id, array $in): array
    {
        [$target, $row] = $this->former($actor, $id, 'Die Angaben dieser Person darfst du nicht ändern.');
        $data = self::valid($in + ['reason' => $row['reason'], 'joined_on' => $row['joined_on'], 'founder' => $row['founder'], 'left_on' => $row['left_on']], $this->app->time()->today());
        $this->app->db->transaction(function () use ($target, $data, $row): void {
            $this->repository()->update($target->id, $data['reason'], $data['joined_on'], $data['founder'], $data['left_on'], $this->app->time()->nowUtc());
            // Neues Austrittsjahr: automatisch beendete Ämter enden mit ihm (von Hand geänderte nicht, OfficeService::moveEndFor).
            if ($data['left_on'] !== $row['left_on']) {
                $this->app->offices()->moveEndFor($target->id, $this->leftYear($data['left_on']));
            }
        });
        $this->all = null;
        return $this->of($id) ?? throw ApiError::notFound('Mitglied nicht gefunden.');
    }

    /**
     * Wieder aufnehmen: Grundrolle und Zusatzrollen von vorher (soweit es sie noch gibt und $actor sie vergeben darf),
     * Ausnahmen bei den Rechten nur durch Admins. Ein neues Startpasswort erzeugt man danach wie sonst.
     *
     * @return array{id: int, level: string, extra_roles: list<string>}
     */
    public function reactivate(User $actor, int $id): array
    {
        [$target, $row] = $this->former($actor, $id, 'Diese Person darfst du nicht wieder aufnehmen.');
        $roles = $this->app->roles();
        $level = (string) ($row['restore']['level'] ?? '');
        if (!$roles->exists($level) || $level === Roles::GUEST || ($level === Roles::ADMIN && !$this->app->users->canAssignAdmin())) {
            $level = Roles::MEMBER; // Rolle inzwischen gelöscht (bzw. Admin hier nicht in der App vergebbar)
        }
        if (!$roles->canHandle($actor, $level)) {
            throw ApiError::forbidden(sprintf('Die Rolle „%s“ darfst du nicht vergeben. Bitte einen Admin fragen.', $roles->label($level)));
        }
        try {
            $this->app->users->reactivate($id, $level);
        } catch (\DomainException $e) {
            throw ApiError::forbidden($e->getMessage());
        }
        $extra = [];
        if ($roles->allowsExtraRoles($level)) {
            foreach ((array) ($row['restore']['extra_roles'] ?? []) as $key) {
                if (is_string($key) && $key !== $level && $roles->exists($key) && RoleService::canBeExtra($key) && $roles->canHandle($actor, $key)) {
                    $extra[] = $key;
                }
            }
        }
        if ($extra !== []) {
            $roles->setExtraRoles($id, $extra);
        }
        if ($actor->isAdmin()) {
            $roles->restoreOverrides($id, $row['restore']['overrides'] ?? null, $level);
        }
        $this->repository()->delete($id);
        $this->app->offices()->releaseEndsFor($id);
        $this->all = null;
        $after = $this->app->users->find($id) ?? $target;
        return ['id' => $id, 'level' => $after->role, 'extra_roles' => $roles->extraRolesOf($after)];
    }

    /**
     * Eine ehemalige Person neu anlegen (Verwaltung → Ehemalige → „+ Ehemalige Person anlegen“): Konto ohne Anmeldung, gleich als
     * ehemalig eingetragen, wie eine Zeile des Imports. Erst alles prüfen, dann anlegen. Wer genau so heißt wie ein
     * vorhandenes Konto, wird nicht angelegt (wie beim Import, damit niemand doppelt entsteht). Steht die Person in
     * Amtszeiten nur als freier Name, wird der Eintrag mit ihrem Konto verknüpft.
     *
     * @param array<string, mixed> $in first_name, last_name, reason, joined_on, founder, left_on
     * @return array{id: int, name: string, offices_linked: int, reason: string, joined_on: string, founder: bool, left_on: string, previous_level: string}
     */
    public function create(User $actor, array $in): array
    {
        $data = self::valid($in, $this->app->time()->today());
        $taken = [];
        $prepared = $this->app->members()->prepare(['first_name' => $in['first_name'] ?? '', 'last_name' => $in['last_name'] ?? ''], $taken);
        $key = mb_strtolower($prepared['name']);
        foreach ($this->app->users->list(true) as $u) {
            if (mb_strtolower($u->name) === $key) {
                throw ApiError::invalid(sprintf(
                    'Es gibt schon %s „%s“. Gleichnamige bitte unterscheidbar machen (z. B. „I“ und „II“).',
                    $this->previousRole($u) !== null ? 'eine ehemalige Person' : 'ein Konto',
                    $u->name,
                ));
            }
        }
        $id = $this->createImported($actor, $prepared, $data);
        $linked = $this->app->offices()->linkNames([$key => $id], true);
        return ['id' => $id, 'name' => $prepared['name'], 'offices_linked' => $linked] + ($this->of($id) ?? throw ApiError::notFound('Das neue Konto wurde nicht gefunden.'));
    }

    /**
     * Ehemalige aus einer Tabelle anlegen (CSV mit Semikolon oder Tabulator): vorname;nachname;eintritt;ausgeschieden;grund.
     * - eintritt und ausgeschieden: TT.MM.JJJJ, JJJJ-MM-TT, nur das Jahr oder leer; eintritt „Gründer“ bzw.
     *   „Gründungsmitglied“ trägt ein Gründungsmitglied ohne Datum ein.
     * - grund: ausgetreten (auch leer), verstorben oder sonstiges.
     * - Wer genau so heißt wie ein vorhandenes Konto, wird übersprungen (existing); doppelte Namen in der Tabelle sind ein
     *   Fehler. Ohne apply nur die Vorschau; mit apply erst, wenn keine Zeile einen Fehler hat.
     * - Steht eine neu angelegte (oder schon früher als ehemalig importierte) Person in Amtszeiten nur als freier Name,
     *   wird der Eintrag mit ihrem Konto verknüpft (OfficeService::linkNames, offices_linked).
     *
     * @return array{rows: list<array<string, mixed>>, new: int, existing: int, offices_linked: int, applied: bool}
     */
    public function import(User $actor, mixed $csv, bool $apply): array
    {
        $lines = CsvTable::lines($csv, self::IMPORT_MAX_BYTES);
        $accounts = [];
        foreach ($this->app->users->list(true) as $u) {
            $accounts[mb_strtolower($u->name)][] = $u;
        }
        $today = $this->app->time()->today();
        $members = $this->app->members();
        $taken = [];
        $seen = [];
        $formerIds = [];
        $rows = [];
        $planned = [];
        $count = 0;
        $existing = 0;
        foreach ($lines as $i => $line) {
            if (trim(str_replace([';', "\t"], '', $line)) === '') {
                continue;
            }
            $cells = CsvTable::cells($line, str_contains($line, ';') ? ';' : "\t");
            if ($count === 0 && mb_strtolower($cells[0] ?? '') === 'vorname') {
                continue; // Kopfzeile
            }
            if (++$count > self::IMPORT_MAX_ROWS) {
                throw ApiError::invalid(sprintf('Höchstens %d Zeilen auf einmal.', self::IMPORT_MAX_ROWS));
            }
            $row = ['line' => $i + 1, 'first_name' => Text::line($cells[0] ?? '', PersonName::MAX), 'last_name' => Text::line($cells[1] ?? '', PersonName::MAX)];
            $row['name'] = PersonName::join($row['first_name'], $row['last_name']);
            try {
                $data = self::importRow($cells, $today);
                $row += $data;
                $key = mb_strtolower($row['name']);
                if (isset($seen[$key])) {
                    throw ApiError::invalid(sprintf('Der Name steht schon in Zeile %d. Gleichnamige bitte unterscheidbar machen (z. B. „I“ und „II“).', $seen[$key]));
                }
                $seen[$key] = $row['line'];
                $match = $accounts[$key] ?? [];
                if ($match !== []) {
                    $row['existing'] = ['id' => $match[0]->id, 'name' => $match[0]->name, 'former' => $this->previousRole($match[0]) !== null];
                    $existing++;
                    if ($row['existing']['former'] && count($match) === 1) {
                        $formerIds[$key] = $match[0]->id; // aus einem früheren Import: Amtszeiten auch jetzt verknüpfen
                    }
                } else {
                    $planned[] = [$members->prepare(['first_name' => $row['first_name'], 'last_name' => $row['last_name']], $taken), $data];
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
        $names = $formerIds;
        foreach ($planned as [$prepared]) {
            $names[mb_strtolower($prepared['name'])] = null;
        }
        $result = ['rows' => $rows, 'new' => count($planned), 'existing' => $existing, 'offices_linked' => $this->app->offices()->linkNames($names, false), 'applied' => false];
        if (!$apply) {
            return $result;
        }
        // Konten lassen sich bei WordPress nicht in einer Transaktion anlegen. Bricht es ab, findet ein zweiter Lauf die
        // schon angelegten als vorhanden und legt nur den Rest an.
        $done = 0;
        foreach ($planned as [$prepared, $data]) {
            try {
                $names[mb_strtolower($prepared['name'])] = $this->createImported($actor, $prepared, $data);
            } catch (ApiError $e) {
                $this->app->offices()->linkNames(array_filter($names), true);
                throw ApiError::invalid(sprintf('Nach %d von %d Personen abgebrochen bei „%s“: %s Ein neuer Import legt nur die übrigen an.', $done, count($planned), $prepared['name'], $e->getMessage()));
            }
            $done++;
        }
        $result['offices_linked'] = $this->app->offices()->linkNames($names, true);
        $result['applied'] = true;
        return $result;
    }

    /**
     * Neues Konto (aus MemberService::prepare) gleich als ehemalig eintragen, wie beim Import der Ehemaligen.
     *
     * @param array{name: string, first_name: string, last_name: string, login: string, email: string, level: string} $prepared
     * @param array{reason: string, joined_on: string, founder: bool, left_on: string} $data aus importData()
     * @return int ID des Kontos
     */
    public function createImported(User $actor, array $prepared, array $data): int
    {
        $created = $this->app->members()->insert($prepared);
        $target = $this->app->users->find((int) $created['id']) ?? throw ApiError::notFound('Das neue Konto wurde nicht gefunden.');
        $this->deactivate($actor, $target, $data);
        return $target->id;
    }

    /**
     * Konto stilllegen und eintragen. Zusatzrollen und Ausnahmen fallen weg (Gäste haben keine), Geräte bekommen keine
     * Benachrichtigungen mehr.
     *
     * @param array{reason: string, joined_on: string, founder: bool, left_on: string} $data
     */
    private function deactivate(User $actor, User $target, array $data): void
    {
        $roles = $this->app->roles();
        $restore = ['level' => $target->role, 'extra_roles' => $roles->extraRolesOf($target), 'overrides' => $roles->overridesOf($target->id)];
        try {
            $this->app->users->deactivate($target->id);
        } catch (\DomainException $e) {
            throw ApiError::forbidden($e->getMessage());
        }
        if ($restore['extra_roles'] !== []) {
            $roles->setExtraRoles($target->id, []);
        }
        $roles->roleChanged($target->id, Roles::GUEST);
        try {
            $this->app->db->execute('DELETE FROM {push_queue} WHERE subscription_id IN (SELECT id FROM {push_subscriptions} WHERE user_id = ?)', [$target->id]);
            $this->app->db->execute('DELETE FROM {push_subscriptions} WHERE user_id = ?', [$target->id]);
        } catch (\RuntimeException) {
            // Tabelle fehlt (ältere Version): dann gibt es auch keine Abos.
        }
        $now = $this->app->time()->nowUtc();
        $this->repository()->delete($target->id); // Rest eines früheren Eintrags (z. B. Rolle anderswo zurückgegeben)
        $this->repository()->insert($target->id, $data['reason'], $data['joined_on'], $data['founder'], $data['left_on'], $restore, $actor->id, $now);
        $this->all = null;
    }

    /**
     * Ehemalige Person mit Eintrag, geprüft wie beim Verwalten ihres Kontos.
     *
     * @return array{0: User, 1: array<string, mixed>}
     */
    private function former(User $actor, int $id, string $message): array
    {
        $target = $this->app->users->find($id);
        $row = $this->all()[$id] ?? null;
        if ($target === null || $row === null || $this->previousRole($target) === null) {
            throw ApiError::notFound('Diese Person ist nicht als ehemalig eingetragen.');
        }
        $this->app->members()->guardAccount($actor, $target, $message);
        return [$target, $row];
    }

    /** @return array{years: int, include_founders: bool, include_deceased: bool} */
    public function retention(): array
    {
        $c = $this->app->settings->get(self::RETENTION_SETTING);
        $c = is_array($c) ? $c : [];
        $years = is_numeric($c['years'] ?? null) ? max(0, min(self::RETENTION_MAX_YEARS, (int) $c['years'])) : 0;
        return ['years' => $years, 'include_founders' => ($c['include_founders'] ?? false) === true, 'include_deceased' => ($c['include_deceased'] ?? false) === true];
    }

    /**
     * Frist mit Vorschau: wer beim nächsten Lauf gelöscht würde (due) und wer in den nächsten 30 Tagen dazukommt (soon).
     *
     * @return array{years: int, include_founders: bool, include_deceased: bool, due: list<array{id: int, name: string, left_on: string}>, soon: list<array{id: int, name: string, left_on: string}>}
     */
    public function retentionStatus(): array
    {
        $config = $this->retention();
        $today = $this->app->time()->today();
        return $config + [
            'due' => $config['years'] > 0 ? $this->expired($config, $today) : [],
            'soon' => $config['years'] > 0
                ? array_values(array_udiff($this->expired($config, $today->modify('+30 days')), $this->expired($config, $today), static fn (array $a, array $b): int => $a['id'] <=> $b['id']))
                : [],
        ];
    }

    /**
     * Frist einstellen (nur manage_settings, s. Api).
     *
     * @param array<string, mixed> $in years, include_founders, include_deceased
     * @return array<string, mixed> retentionStatus()
     */
    public function setRetention(array $in): array
    {
        $years = $in['years'] ?? 0;
        if (!is_int($years) && !(is_string($years) && ctype_digit($years))) {
            throw ApiError::invalid('Bitte die Frist in ganzen Jahren angeben (0 = aus).');
        }
        $years = (int) $years;
        if ($years < 0 || $years > self::RETENTION_MAX_YEARS) {
            throw ApiError::invalid('Die Frist darf höchstens ' . self::RETENTION_MAX_YEARS . ' Jahre sein (0 = aus).');
        }
        $this->app->settings->set(self::RETENTION_SETTING, [
            'years' => $years,
            'include_founders' => Input::bool($in['include_founders'] ?? false),
            'include_deceased' => Input::bool($in['include_deceased'] ?? false),
        ]);
        $this->app->scheduleTasks();
        return $this->retentionStatus();
    }

    /**
     * Tägliche Aufgabe: Ehemalige nach Ablauf der Frist endgültig löschen. Ins Protokoll kommt nur die Anzahl.
     *
     * @return int Anzahl gelöschter Konten
     */
    public function purgeExpired(): int
    {
        $config = $this->retention();
        if ($config['years'] === 0) {
            return 0;
        }
        $deleted = 0;
        foreach ($this->expired($config, $this->app->time()->today()) as $person) {
            try {
                $this->app->users->removeAccount($person['id']);
            } catch (\DomainException) {
                continue; // z. B. Administrator der Webseite: dort verwaltet
            }
            $this->app->userDeleted($person['id']);
            $this->repository()->delete($person['id']);
            $deleted++;
        }
        $this->all = null;
        if ($deleted > 0) {
            error_log('Kompanie-App: ' . $deleted . ' ehemalige Mitglieder nach Ablauf der Aufbewahrungsfrist gelöscht.');
        }
        return $deleted;
    }

    /**
     * Ehemalige, deren Frist an $day abgelaufen ist.
     *
     * @param array{years: int, include_founders: bool, include_deceased: bool} $config
     * @return list<array{id: int, name: string, left_on: string}>
     */
    private function expired(array $config, \DateTimeImmutable $day): array
    {
        $rows = $this->all();
        $users = $this->app->users->findMany(array_keys($rows));
        $out = [];
        foreach ($rows as $id => $row) {
            $user = $users[$id] ?? null;
            if ($user === null || $user->role !== Roles::GUEST
                || ($row['founder'] && !$config['include_founders'])
                || ($row['reason'] === self::REASON_DECEASED && !$config['include_deceased'])) {
                continue;
            }
            $left = match (strlen($row['left_on'])) {
                10 => $row['left_on'],
                4 => $row['left_on'] . '-12-31',
                default => substr((string) $row['created_at'], 0, 10),
            };
            $until = \DateTimeImmutable::createFromFormat('!Y-m-d', $left, $day->getTimezone());
            if ($until !== false && $until->modify('+' . $config['years'] . ' years') <= $day) {
                $out[] = ['id' => $user->id, 'name' => $user->name, 'left_on' => $row['left_on']];
            }
        }
        usort($out, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return $out;
    }

    /**
     * @param array<string, mixed> $row aus dem Repository
     * @return array{id: int, name: string, first_name: string, last_name: string, reason: string, joined_on: string, founder: bool, left_on: string, previous_level: string}
     */
    private static function out(User $user, array $row): array
    {
        $level = $row['restore']['level'] ?? null;
        return [
            'id' => $user->id,
            'name' => $user->name,
            'first_name' => $user->firstName,
            'last_name' => $user->lastName,
            'reason' => $row['reason'],
            'joined_on' => $row['joined_on'],
            'founder' => $row['founder'],
            'left_on' => $row['left_on'],
            'previous_level' => is_string($level) && $level !== '' ? $level : Roles::MEMBER,
        ];
    }

    /**
     * @param array<string, mixed> $in
     * @return array{reason: string, joined_on: string, founder: bool, left_on: string}
     */
    private static function valid(array $in, \DateTimeImmutable $today): array
    {
        $reason = $in['reason'] ?? null;
        if (!is_string($reason) || !isset(self::REASONS[$reason])) {
            throw ApiError::invalid('Bitte einen Grund wählen: ausgetreten, verstorben oder sonstiges.');
        }
        $joined = self::date($in['joined_on'] ?? '', 'Eintritt', $today);
        $left = self::date($in['left_on'] ?? '', $reason === self::REASON_DECEASED ? 'Todestag' : 'Ausgeschieden', $today);
        self::checkOrder($joined, $left);
        return ['reason' => $reason, 'joined_on' => $joined, 'founder' => Input::bool($in['founder'] ?? false), 'left_on' => $left];
    }

    /**
     * Eine Zeile des Imports (ohne Namen, die prüft MemberService::prepare).
     *
     * @param list<string> $cells
     * @return array{reason: string, joined_on: string, founder: bool, left_on: string}
     */
    private static function importRow(array $cells, \DateTimeImmutable $today): array
    {
        if (($cells[0] ?? '') === '') {
            throw ApiError::invalid('Der Vorname fehlt (1. Spalte).');
        }
        return self::importData($cells[2] ?? '', $cells[3] ?? '', $cells[4] ?? '', $today, ' (5. Spalte)');
    }

    /**
     * Eintritt, Ausscheiden und Grund aus einer Tabelle (auch MemberImportService): Eintritt „Gründer“ bzw.
     * „Gründungsmitglied“ ist ein Gründungsmitglied ohne Datum, Grund leer heißt ausgetreten.
     *
     * @return array{reason: string, joined_on: string, founder: bool, left_on: string}
     */
    public static function importData(string $joinedRaw, string $leftRaw, string $reasonRaw, \DateTimeImmutable $today, string $where = ''): array
    {
        $founder = self::isFounder($joinedRaw);
        $reasonRaw = mb_strtolower($reasonRaw);
        $reason = $reasonRaw === '' ? self::REASON_LEFT : array_search($reasonRaw, self::REASONS, true);
        if ($reason === false) {
            $reason = isset(self::REASONS[$reasonRaw]) ? $reasonRaw : throw ApiError::invalid('Grund „ausgetreten“, „verstorben“ oder „sonstiges“ erwartet' . $where . '.');
        }
        $joined = $founder ? '' : self::date($joinedRaw, 'Eintritt', $today);
        $left = self::date($leftRaw, $reason === self::REASON_DECEASED ? 'Todestag' : 'Ausgeschieden', $today);
        self::checkOrder($joined, $left);
        return ['reason' => (string) $reason, 'joined_on' => $joined, 'founder' => $founder, 'left_on' => $left];
    }

    /** Eintritt „Gründer“ bzw. „Gründungsmitglied“ in einer Tabelle. */
    public static function isFounder(string $joinedRaw): bool
    {
        return in_array(mb_strtolower(trim($joinedRaw)), ['gründer', 'gründungsmitglied'], true);
    }

    /**
     * Datum als JJJJ-MM-TT oder nur das Jahr (JJJJ); leer bleibt leer. Angenommen werden TT.MM.JJJJ, JJJJ-MM-TT und JJJJ,
     * nicht in der Zukunft und nicht vor 1800.
     */
    public static function date(mixed $raw, string $what, \DateTimeImmutable $today): string
    {
        $value = trim(is_string($raw) || is_int($raw) ? (string) $raw : '');
        if ($value === '') {
            return '';
        }
        if (preg_match('/^\d{4}$/', $value) === 1) {
            $out = $value;
            $year = (int) $value;
        } else {
            if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m) === 1) {
                [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            } elseif (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $value, $m) === 1) {
                [$year, $month, $day] = [(int) $m[3], (int) $m[2], (int) $m[1]];
            } else {
                throw ApiError::invalid(sprintf('%s: bitte als TT.MM.JJJJ oder nur das Jahr angeben.', $what));
            }
            if (!checkdate($month, $day, $year)) {
                throw ApiError::invalid(sprintf('%s: Dieses Datum gibt es nicht.', $what));
            }
            $out = sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
        if ($year < self::YEAR_MIN || (strlen($out) === 4 ? $out > $today->format('Y') : $out > $today->format('Y-m-d'))) {
            throw ApiError::invalid(sprintf('%s: Das Datum liegt in der Zukunft oder vor %d.', $what, self::YEAR_MIN));
        }
        return $out;
    }

    /** Eintritt nicht nach dem Ausscheiden (mit nur einem Jahr: nach Jahren verglichen). */
    private static function checkOrder(string $joined, string $left): void
    {
        if ($joined === '' || $left === '') {
            return;
        }
        $later = strlen($joined) === 10 && strlen($left) === 10 ? $joined > $left : substr($joined, 0, 4) > substr($left, 0, 4);
        if ($later) {
            throw ApiError::invalid('Der Eintritt liegt nach dem Ausscheiden.');
        }
    }
}
