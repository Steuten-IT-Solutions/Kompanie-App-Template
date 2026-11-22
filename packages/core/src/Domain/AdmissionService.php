<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\I18n\I18n;
use KompanieApp\Core\Storage\CandidateSinceRepository;
use KompanieApp\Core\Storage\EventKindRepository;

/**
 * Aufnahmen: Wer als Anwärter die Voraussetzung erfüllt, als Mitglied aufgenommen zu werden (wie die Beförderungen).
 *
 * - Anwärter ist, wer eine Anwärter-Rolle als Grundrolle oder Zusatzrolle hat: eine Rolle mit „Als Mitglied aufnehmen“
 *   (RoleService::promotable, ohne Mitgliederbereich) oder eine eigene Rolle, an der der Admin „Anwärter-Rolle“
 *   angehakt hat (extra, auch mit Mitgliederbereich). Aufnehmen heißt dann: Grundrolle → Mitglied
 *   (MemberService::promote) bzw. diese Zusatzrolle entfernen. Beides darf, wer Mitglieder anlegen darf (create_users).
 *   Ist der Eintritt leer, wird er auf den Aufnahmetag gesetzt.
 * - Voraussetzung je Anwärter-Rolle (Einstellungen → Rollen, gespeichert in RULES_SETTING): ein Baum aus Bedingungen,
 *   verknüpft mit UND/ODER (Domain\AdmissionRule): Teilnahmen („anwesend“) an Anwesenheitslisten einer Terminart und
 *   Monate seit „Anwärter seit“. Ohne Bedingung steht die Person unter „ohne Voraussetzung“. Es zählen alle Teilnahmen,
 *   auch vor „Anwärter seit“. Gespeichert wird der Baum unter rule; kind/count/months tragen für ältere Versionen die
 *   frühere feste Form (Teilnahmen UND Dauer), wenn der Baum genau sie ausdrückt, sonst 0. Ohne rule entsteht der Baum
 *   beim Lesen aus kind/count/months.
 * - Den eigenen Stand sieht jeder Anwärter (GET /me/admission), den anderer nur, wer Konten sehen darf.
 * - Bald erfüllt („kurz vor der Aufnahme“, AdmissionRule::SOON_MISSING/SOON_DAYS): noch nicht erfüllt, aber je
 *   Bedingung fehlt höchstens eine Teilnahme bzw. die Mindestdauer ist in höchstens 30 Tagen erreicht. Die Kachel zeigt
 *   sie in einem zweiten Kreis, die Übersicht unter „Bald erfüllt“.
 * - Die Voraussetzung sperrt nichts: Aufnehmen geht jederzeit, die App weist in der Rückfrage darauf hin.
 * - „Anwärter seit“ (Tabelle candidate_since je Person und Rolle): gesetzt, wenn jemand die Grundrolle bekommt (in der
 *   App: anlegen, Rolle ändern, Anfrage freigeben); bei Zusatzrollen das Datum der Zuordnung, wenn sie nach dem Anhaken
 *   der Rolle als Anwärter-Rolle war. Sonst leer (nachtragen); ändern darf es, wer Mitglieder anlegen darf.
 *
 * @phpstan-type Rule array{kind: string, count: int, months: int, extra: bool, extra_since: string, rule: array<string, mixed>}
 */
final class AdmissionService
{
    public const RULES_SETTING = 'admission_rules';
    public const COUNT_MAX = 100;
    public const MONTHS_MAX = 120;
    /** Felder an PATCH /admin/roles/{key} bzw. POST /admin/roles */
    public const ROLE_FIELDS = ['admission_extra', 'admission_kind', 'admission_count', 'admission_months', 'admission_rule'];

    public const FULFILLED = 'fulfilled';
    public const OPEN = 'open';
    public const NONE = 'none';

    private ?CandidateSinceRepository $repo = null;

    public function __construct(private readonly App $app)
    {
    }

    public function repository(): CandidateSinceRepository
    {
        return $this->repo ??= new CandidateSinceRepository($this->app->db);
    }

    /**
     * Gespeicherte Angaben je Rolle (nur geprüfte Werte).
     *
     * @return array<string, Rule>
     */
    public function rules(): array
    {
        $raw = $this->app->settings->get(self::RULES_SETTING);
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $key => $r) {
            if (!is_string($key) || !is_array($r)) {
                continue;
            }
            $kind = is_string($r['kind'] ?? null) ? $r['kind'] : '';
            $count = is_int($r['count'] ?? null) ? max(0, min(self::COUNT_MAX, $r['count'])) : 0;
            $months = is_int($r['months'] ?? null) ? max(0, min(self::MONTHS_MAX, $r['months'])) : 0;
            $out[$key] = [
                'kind' => $kind,
                'count' => $count,
                'months' => $months,
                'extra' => ($r['extra'] ?? false) === true,
                'extra_since' => is_string($r['extra_since'] ?? null) ? $r['extra_since'] : '',
                'rule' => AdmissionRule::fromStored($r['rule'] ?? null) ?? AdmissionRule::fromLegacy($kind, $count, $months),
            ];
        }
        return $out;
    }

    /** @return Rule */
    public function rule(string $key): array
    {
        return $this->rules()[$key] ?? self::emptyRule();
    }

    /** @return Rule */
    private static function emptyRule(): array
    {
        return ['kind' => '', 'count' => 0, 'months' => 0, 'extra' => false, 'extra_since' => '', 'rule' => AdmissionRule::none()];
    }

    /**
     * Für Einstellungen → Rollen (GET /admin/roles): Anwärter-Rolle (Grund- oder Zusatzrolle) und Voraussetzung.
     *
     * @return array{admission_extra: bool, admission_kind: string|null, admission_count: int, admission_months: int, admission_rule: array<string, mixed>}
     */
    public function describeRole(string $key): array
    {
        $r = $this->rule($key);
        return [
            'admission_extra' => $r['extra'] && RoleService::canBeExtra($key),
            'admission_kind' => $r['kind'] !== '' ? $r['kind'] : null,
            'admission_count' => $r['count'],
            'admission_months' => $r['months'],
            'admission_rule' => $r['rule'],
        ];
    }

    /**
     * Angaben einer Rolle ändern (PATCH /admin/roles/{key}): admission_extra, admission_rule (Baum, AdmissionRule) oder
     * wie früher admission_kind, admission_count, admission_months (ergeben Teilnahmen UND Dauer); nur mitgeschickte
     * Felder, admission_rule geht vor. Erst alles prüfen, dann speichern (zusammen mit RoleService::update bzw.
     * create). $key null: neue Rolle (POST /admin/roles), ihr Schlüssel kommt beim Speichern.
     *
     * @param array<string, mixed> $in
     * @return \Closure(string): void speichert die geprüften Angaben für die Rolle (tut nichts, wenn nichts mitgeschickt wurde)
     */
    public function prepareRole(?string $key, array $in): \Closure
    {
        if (array_intersect(self::ROLE_FIELDS, array_keys($in)) === []) {
            return static function (string $key): void {
            };
        }
        if ($key !== null && !$this->app->roles()->exists($key)) {
            throw ApiError::notFound(I18n::t('Rolle nicht gefunden.'));
        }
        $r = $key !== null ? $this->rule($key) : $this->rule('');
        $own = $key === null || RoleService::canBeExtra($key);
        if (array_key_exists('admission_extra', $in)) {
            $extra = Input::bool($in['admission_extra']);
            if ($extra && !$own) {
                throw ApiError::invalid(I18n::t('Anwärter-Rolle kann nur eine eigene Rolle sein.'));
            }
            if ($extra && !$r['extra']) {
                // Ab jetzt zählt die Zuordnung als „Anwärter seit“ (frühere sind unbekannt und werden nachgetragen).
                $r['extra_since'] = $this->app->time()->nowUtc();
            }
            $r['extra'] = $extra;
        }
        if (array_key_exists('admission_rule', $in)) {
            // Bisherige Arten bleiben erlaubt, auch wenn sie nicht mehr in der Liste stehen.
            $current = AdmissionRule::kinds($r['rule']);
            $kinds = $this->app->eventKinds();
            $r['rule'] = AdmissionRule::parse($in['admission_rule'], static fn (string $kind): string => $kinds->valid($kind, in_array($kind, $current, true) ? $kind : null));
            $legacy = AdmissionRule::legacy($r['rule']) ?? ['kind' => '', 'count' => 0, 'months' => 0];
            $r['kind'] = $legacy['kind'];
            $r['count'] = $legacy['count'];
            $r['months'] = $legacy['months'];
        } elseif (array_intersect(['admission_kind', 'admission_count', 'admission_months'], array_keys($in)) !== []) {
            if (array_key_exists('admission_kind', $in)) {
                $r['kind'] = $this->app->eventKinds()->valid($in['admission_kind'], $r['kind'] !== '' ? $r['kind'] : null);
            }
            foreach (['admission_count' => ['count', self::COUNT_MAX, I18n::t('Teilnahmen')], 'admission_months' => ['months', self::MONTHS_MAX, I18n::t('Monate')]] as $field => [$name, $max, $label]) {
                if (array_key_exists($field, $in)) {
                    $n = $in[$field] === null || $in[$field] === '' ? 0 : Input::integer($in[$field]);
                    if ($n === null || $n < 0 || $n > $max) {
                        throw ApiError::invalid(I18n::t('{label}: bitte eine Zahl von 0 bis {max}.', ['label' => $label, 'max' => $max]));
                    }
                    $r[$name] = $n;
                }
            }
            if ($r['count'] > 0 && $r['kind'] === '') {
                throw ApiError::invalid(I18n::t('Für die Teilnahmen bitte eine Terminart wählen.'));
            }
            $r['rule'] = AdmissionRule::fromLegacy($r['kind'], $r['count'], $r['months']);
        }
        return function (string $key) use ($r): void {
            $rules = $this->rules();
            $rules[$key] = $r;
            $this->app->settings->set(self::RULES_SETTING, $rules);
        };
    }

    /**
     * Anwärter-Rolle einer Person: Grundrolle mit „Als Mitglied aufnehmen“ oder die erste wirksame Zusatzrolle, die als
     * Anwärter-Rolle angehakt ist. null für alle anderen (auch Ehemalige).
     *
     * @return array{key: string, way: 'base'|'extra'}|null
     */
    public function candidateRole(User $u): ?array
    {
        $roles = $this->app->roles();
        $found = null;
        if ($roles->promotable($u->role) || $this->flagged($u->role)) {
            $found = ['key' => $u->role, 'way' => 'base'];
        } else {
            foreach ($roles->extraRoles($u->id, $u->role) as $key) {
                if ($this->flagged($key)) {
                    $found = ['key' => $key, 'way' => 'extra'];
                    break;
                }
            }
        }
        // Ehemalige erst danach prüfen (eine Abfrage), nur bei Anwärtern
        return $found !== null && $this->app->formerMembers()->previousRole($u) === null ? $found : null;
    }

    /** Eigene Rolle mit dem Haken „Anwärter-Rolle“ (als Grund- oder Zusatzrolle). */
    private function flagged(string $key): bool
    {
        return ($this->rules()[$key]['extra'] ?? false) === true && RoleService::canBeExtra($key);
    }

    /** Gibt es Anwärter-Rollen (Grund- oder Zusatzrolle)? Sonst keine Kachel. */
    public function hasCandidateRoles(): bool
    {
        foreach ($this->app->roles()->all() as $key => $r) {
            if ($r['promotable'] || $this->flagged($key)) {
                return true;
            }
        }
        return false;
    }

    /** Übersicht und Kachel: mit dem Bereich Anwesenheit und einer Anwärter-Rolle. */
    public function enabled(): bool
    {
        return $this->app->features()->enabled('attendance') && $this->hasCandidateRoles();
    }

    /**
     * Alle Anwärter mit Stand (GET /admissions): erfüllt (nach Datum, ältestes zuerst), bald erfüllt, noch nicht, ohne
     * Voraussetzung.
     * Je Person result: der ausgewertete Baum (AdmissionRule::evaluate); kind/count/attended/missing beschreiben die erste
     * Bedingung „Teilnahmen“, months/months_on die erste „Dauer“ (für ältere Oberflächen).
     *
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $out = $this->evaluate(null);
        $order = [self::FULFILLED => 0, self::OPEN => 2, self::NONE => 3];
        $rank = static fn (array $c): int => $c['soon'] ? 1 : $order[$c['status']];
        usort($out, static fn (array $a, array $b): int => [$rank($a), (string) ($a['fulfilled_on'] ?? ''), $a['name']]
            <=> [$rank($b), (string) ($b['fulfilled_on'] ?? ''), $b['name']]);
        return $out;
    }

    /** Zahl an der Kachel „Aufnahmen“: Anwärter, die die Voraussetzung erfüllen. */
    public function dueCount(): int
    {
        return $this->counts()['due'];
    }

    /**
     * Beide Zahlen an der Kachel „Aufnahmen“ mit einer Auswertung: due erfüllt (roter Kreis), soon bald erfüllt (zweiter
     * Kreis).
     *
     * @return array{due: int, soon: int}
     */
    public function counts(): array
    {
        $out = ['due' => 0, 'soon' => 0];
        foreach ($this->evaluate(null) as $c) {
            if ($c['status'] === self::FULFILLED) {
                $out['due']++;
            } elseif ($c['soon']) {
                $out['soon']++;
            }
        }
        return $out;
    }

    /** @return array<int, string> Benutzer-ID => Name aller, die die Voraussetzung erfüllen */
    public function fulfilledIds(): array
    {
        $out = [];
        foreach ($this->evaluate(null) as $c) {
            if ($c['status'] === self::FULFILLED) {
                $out[(int) $c['id']] = (string) $c['name'];
            }
        }
        return $out;
    }

    /** @return array<string, mixed>|null Stand einer Person (Mitgliedsseite), null, wenn sie kein Anwärter ist */
    public function statusOf(int $id): ?array
    {
        return $this->evaluate($id)[0] ?? null;
    }

    /**
     * Als Mitglied aufnehmen (POST /members/{id}/admit, create_users): Grundrolle → Mitglied bzw. Anwärter-Zusatzrolle
     * entfernen; Eintritt = heute, wenn er leer ist; „Anwärter seit“ fällt weg.
     *
     * @return array{id: int, level: string, extra_roles: list<string>, join_date: string|null}
     */
    public function admit(User $actor, int $id): array
    {
        $target = $this->app->users->find($id) ?? throw ApiError::notFound(I18n::t('Mitglied nicht gefunden.'));
        if ($actor->id === $id) {
            throw ApiError::invalid(I18n::t('Sich selbst kann man nicht aufnehmen.'));
        }
        if ($this->app->formerMembers()->previousRole($target) !== null) {
            throw ApiError::invalid(I18n::t('Diese Person ist ehemalig. Bitte zuerst wieder aufnehmen.'));
        }
        $candidate = $this->candidateRole($target) ?? throw ApiError::invalid(I18n::t('Diese Person ist kein Anwärter (mehr).'));
        $roles = $this->app->roles();
        if ($candidate['way'] === 'base') {
            $this->app->members()->promote($actor, $id, true);
        } else {
            $roles->setExtraRoles($id, array_values(array_diff($roles->extraRoles($id, $target->role), [$candidate['key']])));
            $roles->roleChanged($id, $target->role);
        }
        $joined = null;
        $joinYears = $this->app->joinYears()->repository();
        if ($joinYears->find($id) === null) {
            $joined = $this->app->time()->today()->format('Y-m-d');
            $joinYears->save($id, (int) substr($joined, 0, 4), $joined, null, $actor->id, $this->app->time()->nowUtc());
        }
        $this->repository()->deleteFor($id);
        $after = $this->app->users->find($id);
        return [
            'id' => $id,
            'level' => $after?->role ?? $target->role,
            'extra_roles' => $after !== null ? $roles->extraRoles($id, $after->role) : [],
            'join_date' => $joined,
        ];
    }

    /**
     * „Anwärter seit“ ändern (PUT /members/{id}/candidate-since, create_users): TT.MM.JJJJ oder JJJJ-MM-TT, leer =
     * unbekannt.
     *
     * @return array<string, mixed> Stand der Person wie in statusOf
     */
    public function setSince(User $actor, int $id, mixed $since): array
    {
        $target = $this->app->users->find($id) ?? throw ApiError::notFound(I18n::t('Mitglied nicht gefunden.'));
        $candidate = $this->candidateRole($target) ?? throw ApiError::invalid(I18n::t('Diese Person ist kein Anwärter (mehr).'));
        $date = FormerMemberService::date($since, I18n::t('Anwärter seit'), $this->app->time()->today());
        if (strlen($date) === 4) {
            throw ApiError::invalid(I18n::t('Anwärter seit: bitte das ganze Datum angeben (TT.MM.JJJJ).'));
        }
        $this->repository()->save($id, $candidate['key'], $date, $actor->id, $this->app->time()->nowUtc());
        return $this->statusOf($id) ?? throw new \LogicException('Anwärter ohne Stand');
    }

    /** Zeile von „Anwärter seit“ einer Person (Änderungsprotokoll), null ohne. */
    public function sinceRowId(int $id): ?int
    {
        $u = $this->app->users->find($id);
        $c = $u !== null ? $this->candidateRole($u) : null;
        return $c !== null ? $this->repository()->idOf($id, $c['key']) : null;
    }

    /**
     * Grundrolle in der App gesetzt (anlegen, Rolle ändern, Anfrage freigeben): Ist die neue Rolle eine Anwärter-Rolle,
     * gilt heute als „Anwärter seit“.
     */
    public function baseRoleSet(int $id, string $old, string $new): void
    {
        if ($old === $new || !($this->app->roles()->promotable($new) || $this->flagged($new))) {
            return;
        }
        try {
            $this->repository()->save($id, $new, $this->app->time()->today()->format('Y-m-d'), 0, $this->app->time()->nowUtc());
        } catch (\Throwable $e) {
            // Fehlt die Tabelle noch (Migration nicht gelaufen), bleibt das Datum leer: nachtragen.
            error_log('Kompanie-App: „Anwärter seit“ nicht gespeichert: ' . $e->getMessage());
        }
    }

    /**
     * Stand aller Anwärter (oder nur von $only).
     *
     * @return list<array<string, mixed>>
     */
    private function evaluate(?int $only): array
    {
        $roles = $this->app->roles();
        $rules = $this->rules();
        $candidates = [];
        $users = $only !== null ? array_filter([$this->app->users->find($only)]) : $this->app->users->list(false);
        foreach ($users as $u) {
            $c = $this->candidateRole($u);
            if ($c !== null) {
                $candidates[] = [$u, $c];
            }
        }
        if ($candidates === []) {
            return [];
        }
        $stored = $this->repository()->all();
        $assigned = $roles->extraAssignedAt();
        $kinds = [];
        foreach ($candidates as [, $c]) {
            foreach (AdmissionRule::kinds(($rules[$c['key']] ?? self::emptyRule())['rule']) as $kind) {
                $kinds[$kind] = true;
            }
        }
        $dates = $this->attendanceDates(array_map('strval', array_keys($kinds)), array_map(static fn (array $x): int => $x[0]->id, $candidates));
        $today = $this->app->time()->today();
        $out = [];
        foreach ($candidates as [$u, $c]) {
            $r = $rules[$c['key']] ?? self::emptyRule();
            $since = $stored[$u->id][$c['key']] ?? null;
            if ($since === null && $c['way'] === 'extra') {
                $at = $assigned[$u->id][$c['key']] ?? '';
                $since = $at !== '' && $r['extra_since'] !== '' && $at >= $r['extra_since'] ? $this->localDate($at) : '';
            }
            $since ??= '';
            $mine = $dates[$u->id] ?? [];
            $soonDay = $today->modify(sprintf('+%d days', AdmissionRule::SOON_DAYS))->format('Y-m-d');
            $result = AdmissionRule::evaluate($r['rule'], static function (array $leaf) use ($mine, $since, $today, $soonDay): array {
                if (($leaf['type'] ?? '') === AdmissionRule::ATTENDANCE) {
                    $need = is_int($leaf['count'] ?? null) ? $leaf['count'] : 0;
                    $attended = $mine[is_string($leaf['kind'] ?? null) ? $leaf['kind'] : ''] ?? [];
                    $ok = count($attended) >= $need;
                    return $leaf + [
                        'attended' => count($attended),
                        'missing' => max(0, $need - count($attended)),
                        'status' => $ok ? AdmissionRule::FULFILLED : AdmissionRule::OPEN,
                        'on' => $ok ? ($attended[$need - 1] ?? null) : null,
                        'soon' => $need - count($attended) <= AdmissionRule::SOON_MISSING,
                    ];
                }
                $months = is_int($leaf['months'] ?? null) ? $leaf['months'] : 0;
                if ($since === '') {
                    return $leaf + ['due' => null, 'status' => AdmissionRule::UNKNOWN, 'on' => null, 'soon' => false];
                }
                $due = self::addMonths($since, $months);
                $ok = $due <= $today->format('Y-m-d');
                return $leaf + ['due' => $due, 'status' => $ok ? AdmissionRule::FULFILLED : AdmissionRule::OPEN, 'on' => $ok ? $due : null, 'soon' => $due <= $soonDay];
            });
            $attendance = AdmissionRule::first($result, AdmissionRule::ATTENDANCE);
            $duration = AdmissionRule::first($result, AdmissionRule::DURATION);
            $empty = AdmissionRule::isEmpty($r['rule']);
            $entry = [
                'id' => $u->id,
                'name' => $u->name,
                'role' => $roles->label($c['key']),
                'role_key' => $c['key'],
                'way' => $c['way'],
                'since' => $since !== '' ? $since : null,
                'kind' => $attendance['kind'] ?? null,
                'count' => $attendance['count'] ?? 0,
                'months' => $duration['months'] ?? 0,
                'attended' => $attendance['attended'] ?? 0,
                'missing' => $attendance['missing'] ?? 0,
                // Tag, ab dem die (erste) Mindestdauer erfüllt ist (null ohne Mindestdauer oder ohne „Anwärter seit“)
                'months_on' => $duration['due'] ?? null,
                'fulfilled_on' => !$empty && $result['status'] === AdmissionRule::FULFILLED ? $result['on'] : null,
                'status' => $empty ? self::NONE : ($result['status'] === AdmissionRule::FULFILLED ? self::FULFILLED : self::OPEN),
                // Noch nicht erfüllt, aber kurz davor (AdmissionRule::SOON_MISSING, SOON_DAYS)
                'soon' => !$empty && $result['status'] !== AdmissionRule::FULFILLED && ($result['soon'] ?? false) === true,
                'result' => $empty ? null : $result,
            ];
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * Daten der Teilnahmen („anwesend“) je Person und Terminart, aufsteigend; nur Listen bis heute.
     *
     * @param list<string> $kinds
     * @param list<int> $userIds
     * @return array<int, array<string, list<string>>>
     */
    private function attendanceDates(array $kinds, array $userIds): array
    {
        if ($kinds === [] || $userIds === []) {
            return [];
        }
        $wanted = array_fill_keys($userIds, true);
        // Eine Liste kann mehrere Arten haben (kinds) und zählt für jede; kind ist ihre erste, leer ohne Art.
        $rows = $this->app->db->select(
            "SELECT e.user_id, l.kind, l.kinds, l.event_date FROM {attendance_entries} e JOIN {attendance_lists} l ON l.id = e.list_id
             WHERE e.status = ? AND l.kind <> '' AND l.event_date <= ? ORDER BY l.event_date, l.id",
            [AttendanceService::PRESENT, $this->app->time()->today()->format('Y-m-d')],
        );
        $out = [];
        foreach ($rows as $row) {
            $uid = (int) $row['user_id'];
            if (!isset($wanted[$uid])) {
                continue;
            }
            foreach (array_intersect(EventKindRepository::decode($row['kind'], $row['kinds']), $kinds) as $kind) {
                $out[$uid][$kind][] = substr((string) $row['event_date'], 0, 10);
            }
        }
        return $out;
    }

    /** JJJJ-MM-TT plus $months Monate; am Monatsende der letzte Tag (31.01. + 1 → 28./29.02.). */
    public static function addMonths(string $date, int $months): string
    {
        $d = new \DateTimeImmutable($date);
        $first = $d->modify('first day of this month')->modify(sprintf('+%d months', $months));
        $day = min((int) $d->format('j'), (int) $first->format('t'));
        return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), $day)->format('Y-m-d');
    }

    private function localDate(string $utc): string
    {
        $ts = strtotime($utc . ' UTC');
        return $ts === false ? substr($utc, 0, 10) : $this->app->time()->format($ts, 'Y-m-d');
    }
}
