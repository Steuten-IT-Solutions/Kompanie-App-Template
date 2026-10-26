<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Config\KompanieConfig;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Port\Settings;
use KompanieApp\Core\Port\UserDirectory;
use KompanieApp\Core\Storage\MemberRoleRepository;

/**
 * Rollen der App: Bezeichnung, Rechte und Punktefaktor – die einzige Stelle, die das sagt (Issue #28).
 *
 * Der Admin legt Rollen in der App an (Einstellungen → Rollen) und ordnet sie per Ziehen, auch die festen. Fest sind
 * Gast, Mitglied und Admin (Bezeichnungen bei der Ersteinrichtung aus kompanie.json, danach in der App). Eigene Rollen lassen
 * sich beim Anlegen von Konten wählen (new_accounts) und, ohne Mitgliederbereich, von denen mit „Mitglieder anlegen“
 * zum Mitglied machen (promotable), z. B. Anwärter (Issue #23; früher eine feste Rolle). Schaltet der Admin einen
 * Bereich ein, der Rechte braucht, kommt dessen Standardrolle dazu (Domain\Features, z. B. „Punkte
 * verwalten“); schaltet er ihn aus, verschwindet sie wieder, wenn ihr niemand zugeordnet ist.
 * Gespeichert als eine Einstellung: Die Einstellungen lädt jede Anfrage ohnehin auf einmal, die Rechte
 * einer Rolle kosten so keine eigene Abfrage (die Zusatzrollen aller Personen zusammen eine, siehe capabilitiesFor).
 *
 * Eine Person hat genau eine Grundrolle (Standalone users.level, WordPress Benutzer-Option) und beliebig viele
 * Zusatzrollen (Issue #66, Tabelle member_roles, Storage\MemberRoleRepository). Ihre Rechte sind die Vereinigung
 * aller ihrer Rollen (capabilitiesFor, die einzige Stelle, die das ausrechnet). Zusatzrollen sind nur eigene Rollen
 * (nicht Gast, Mitglied, Admin) und wirken nur, solange die Grundrolle den Mitgliederbereich hat: Ob jemand
 * Mitglied, Anwärter oder Gast ist, entscheidet allein die Grundrolle. Der Punktefaktor (z. B. 0,5 für junge
 * Mitglieder) ist der kleinste aller Rollen der Person; er gilt beim Buchen und wird mit dem Eintrag gespeichert, eine
 * spätere Änderung ändert alte Einträge nicht.
 *
 * Einzelnen Personen lassen sich Rechte zusätzlich geben oder entziehen (Ausnahmen), ohne dafür eine eigene
 * Rolle anzulegen. Sie gelten zusätzlich zu ihren Rollen und bleiben bei einem Wechsel der Rollen bestehen; was die
 * Rollen dann schon regeln, fällt dabei weg. Admins haben immer alle Rechte, Gäste die ihrer Rolle: Für sie gibt es keine
 * Ausnahmen. Gespeichert wie die Rollen als eine Einstellung.
 *
 * Rechte gelten einzeln, keines schließt ein anderes ein; manche setzen eines voraus (Capability::requires, z. B.
 * Verwalten die Mitgliedschaft). Die Rolle Gast gilt auch ohne Anmeldung (guestCapabilities).
 */
final class RoleService
{
    public const SETTING = 'roles';
    /** Ausnahmen einzelner Personen: Liste von {user, grant, revoke}. */
    public const OVERRIDES_SETTING = 'capability_overrides';
    private const NO_OVERRIDES = ['grant' => [], 'revoke' => []];
    /** Zuletzt vergebene Nummer eigener Rollen (r1, r2, …); Schlüssel gelöschter Rollen werden nie wieder vergeben. */
    public const SEQ_SETTING = 'roles_seq';
    /** Gesetzt, sobald die frühere feste Rolle „Anwärter“ eine eigene Rolle ist (migrateCandidate). */
    public const CANDIDATE_SETTING = 'roles_candidate_own';
    /** Gesetzt, sobald die Rollen die Rechte zum Sehen einzeln haben (migrateViewRights). */
    public const VIEW_SETTING = 'roles_view_split';
    public const LABEL_MAX = 30;
    public const MAX_ROLES = 30;
    public const FACTOR_MAX = 10.0;

    /** @var array<string, array{key: string, label: string, capabilities: string[], points_factor: float, new_accounts: bool, promotable: bool}>|null */
    private ?array $roles = null;

    /** @var array<int, list<string>>|null Zusatzrollen je Person, einmal je Anfrage geladen */
    private ?array $extra = null;

    /** @var array<int, array{grant: string[], revoke: string[]}>|null Benutzer-ID => Ausnahmen */
    private ?array $overrides = null;

    public function __construct(
        private readonly Settings $settings,
        private readonly KompanieConfig $config,
        private readonly UserDirectory $users,
        private readonly ?MemberRoleRepository $memberRoles = null,
        private readonly ?Time $time = null,
    ) {
    }

    /**
     * Alle Rollen in der Reihenfolge der Anzeige (wie der Admin sie ordnet; anfangs guest zuerst, admin zuletzt).
     *
     * @return array<string, array{key: string, label: string, capabilities: string[], points_factor: float, new_accounts: bool, promotable: bool}>
     */
    public function all(): array
    {
        if ($this->roles === null) {
            $saved = $this->settings->get(self::SETTING);
            $this->roles = is_array($saved) && $saved !== [] ? self::normalize($saved) : $this->templates();
        }
        return $this->roles;
    }

    /** @return array{key: string, label: string, capabilities: string[], points_factor: float, new_accounts: bool, promotable: bool}|null */
    public function find(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    public function exists(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    /**
     * Rechte einer Rolle. admin: alle; unbekannte (z. B. gelöschte) Rollen: keine, wie Gast.
     *
     * @return Capability[]
     */
    public function capabilities(string $key): array
    {
        return $this->granted($key);
    }

    /**
     * Rechte ohne Anmeldung: die der Rolle Gast.
     *
     * @return Capability[]
     */
    public function guestCapabilities(): array
    {
        return $this->granted(Roles::GUEST);
    }

    /**
     * Die der Rolle gegebenen Rechte, wie sie Einstellungen → Rollen zeigt.
     *
     * @return Capability[]
     */
    private function granted(string $key): array
    {
        if ($key === Roles::ADMIN) {
            return Capability::cases();
        }
        $out = [];
        foreach ($this->find($key)['capabilities'] ?? [] as $c) {
            $cap = Capability::tryFrom($c);
            if ($cap !== null && in_array($cap, Roles::GRANTABLE, true) && !in_array($cap, $out, true)) {
                $out[] = $cap;
            }
        }
        return $out;
    }

    /**
     * Für die Seite eines Mitglieds: Rechte ihrer Rollen, Ausnahmen und welche Rechte $actor ändern darf.
     * null, wenn es für die Person keine Ausnahmen gibt (Admin, Gast) oder $actor sie nicht ändern darf.
     *
     * @return array{role: string[], grant: string[], revoke: string[], editable: string[]}|null
     */
    public function overridesView(User $actor, User $target): ?array
    {
        if (!$actor->can(Capability::PromoteUsers) || $actor->id === $target->id || !self::overridable($target->role)
            || !$this->canHandleUser($actor, $target)) {
            return null;
        }
        $base = $this->grantedFor($target->id, $target->role);
        return $this->view($actor, $base, self::normalized($base, $this->loadOverrides()[$target->id] ?? self::NO_OVERRIDES));
    }

    /**
     * Rechte einer Person übersteuern. $grant: Rechte zusätzlich zu ihren Rollen, $revoke: Rechte ihrer Rollen, die sie
     * nicht haben soll; was die Rollen ohnehin regeln, fällt weg. Admins ändern jedes Recht. Wer Rollen vergeben
     * darf, ändert nur Rechte, die er selbst hat, und nicht „Mitglieder anlegen“ oder „Rollen vergeben“ (wie
     * canHandle).
     *
     * @return array{role: string[], grant: string[], revoke: string[], editable: string[]}
     */
    public function setOverrides(User $actor, int $id, mixed $grant, mixed $revoke): array
    {
        if ($actor->id === $id) {
            throw ApiError::invalid('Die eigenen Rechte kann man nicht selbst ändern.');
        }
        $target = $this->users->find($id) ?? throw ApiError::notFound('Benutzer nicht gefunden.');
        if ($target->isAdmin()) {
            throw ApiError::invalid('Admins haben immer alle Rechte.');
        }
        if (!self::overridable($target->role)) {
            throw ApiError::invalid('Gäste haben keine Rechte in der App. Bitte zuerst eine Rolle geben.');
        }
        if (!$this->canHandleUser($actor, $target)) {
            throw ApiError::forbidden('Die Rechte dieser Person darfst du nicht ändern.');
        }
        $grant = self::validCapabilityList($grant);
        $revoke = self::validCapabilityList($revoke);
        if (array_intersect($grant, $revoke) !== []) {
            throw ApiError::invalid('Ein Recht lässt sich nicht zugleich geben und entziehen.');
        }
        $base = $this->grantedFor($id, $target->role);
        $next = self::normalized($base, ['grant' => $grant, 'revoke' => $revoke]);
        // Zusätzlich gegebene Rechte brauchen ihre Voraussetzung; was eine entzogene voraussetzt, fällt weg (effective).
        $caps = array_diff(array_merge(self::values($base), $next['grant']), $next['revoke']);
        foreach ($next['grant'] as $c) {
            $needed = Capability::from($c)->requires();
            if ($needed !== null && !in_array($needed->value, $caps, true)) {
                throw ApiError::invalid('Rechte zum Verwalten und die eigenen Punkte, Strafen, Dienstgrade und Anwesenheit gibt es nur mit „Mitglied der Kompanie“.');
            }
        }
        $old = self::normalized($base, $this->loadOverrides()[$id] ?? self::NO_OVERRIDES);
        $changed = array_merge(
            array_diff($next['grant'], $old['grant']),
            array_diff($old['grant'], $next['grant']),
            array_diff($next['revoke'], $old['revoke']),
            array_diff($old['revoke'], $next['revoke']),
        );
        $editable = self::values($this->editableBy($actor));
        if (array_diff($changed, $editable) !== []) {
            throw ApiError::forbidden('Rechte, die du selbst nicht hast, sowie „Mitglieder anlegen“ und „Rollen vergeben“ ändert nur ein Admin.');
        }
        $this->storeOverrides($id, $next);
        return $this->view($actor, $base, $next);
    }

    /**
     * Nach einem Wechsel der Grundrolle oder der Zusatzrollen: Was die Rollen jetzt schon regeln, fällt weg; Admins und
     * Gäste haben keine Ausnahmen.
     */
    public function roleChanged(int $id, string $baseRole): void
    {
        $o = $this->loadOverrides()[$id] ?? null;
        if ($o !== null) {
            $this->storeOverrides($id, self::overridable($baseRole) ? self::normalized($this->grantedFor($id, $baseRole), $o) : self::NO_OVERRIDES);
        }
    }

    /**
     * Ausnahmen einer Person, wie gespeichert (ehemalige Mitglieder merken sie sich für „Wieder aufnehmen“).
     *
     * @return array{grant: string[], revoke: string[]}
     */
    public function overridesOf(int $id): array
    {
        return $this->loadOverrides()[$id] ?? self::NO_OVERRIDES;
    }

    /**
     * Früher gemerkte Ausnahmen wieder einsetzen (ehemaliges Mitglied wieder aufgenommen). Unbekannte Rechte und was die
     * Rollen jetzt schon regeln, fallen weg.
     */
    public function restoreOverrides(int $id, mixed $saved, string $baseRole): void
    {
        if (!is_array($saved) || !self::overridable($baseRole)) {
            return;
        }
        $o = [
            'grant' => self::ordered(array_filter((array) ($saved['grant'] ?? []), 'is_string')),
            'revoke' => self::ordered(array_filter((array) ($saved['revoke'] ?? []), 'is_string')),
        ];
        if ($o['grant'] !== [] || $o['revoke'] !== []) {
            $this->storeOverrides($id, self::normalized($this->grantedFor($id, $baseRole), $o));
        }
    }

    /** Konto entfernt: Ausnahmen löschen (sonst bekäme sie eine spätere Person mit derselben ID). */
    public function forget(int $id): void
    {
        if (isset($this->loadOverrides()[$id])) {
            $this->storeOverrides($id, self::NO_OVERRIDES);
        }
    }

    private static function overridable(string $key): bool
    {
        return $key !== Roles::ADMIN && $key !== Roles::GUEST;
    }

    /**
     * Rechte der Rolle mit den Ausnahmen. Was seine Voraussetzung nicht hat (Capability::requires), fällt weg, auch wenn
     * ein Rollenwechsel die Ausnahmen so zusammenbringt.
     *
     * @param Capability[] $base Rechte der Rollen
     * @param array{grant: string[], revoke: string[]} $o
     * @return Capability[]
     */
    private static function effective(array $base, array $o): array
    {
        $caps = [];
        foreach (array_merge(self::values($base), $o['grant']) as $c) {
            $cap = Capability::from($c);
            if (!in_array($cap, $caps, true) && !in_array($c, $o['revoke'], true)) {
                $caps[] = $cap;
            }
        }
        return self::withRequired($caps);
    }

    /**
     * Ohne die Rechte, deren Voraussetzung fehlt.
     *
     * @param Capability[] $caps
     * @return Capability[]
     */
    private static function withRequired(array $caps): array
    {
        return array_values(array_filter($caps, static fn (Capability $c): bool => $c->requires() === null || in_array($c->requires(), $caps, true)));
    }

    /**
     * 400, wenn ein Recht seine Voraussetzung nicht hat (Capability::requires).
     *
     * @param string[] $values
     */
    private static function checkRequired(array $values): void
    {
        foreach ($values as $v) {
            $needed = Capability::from($v)->requires();
            if ($needed !== null && !in_array($needed->value, $values, true)) {
                throw ApiError::invalid('Rechte zum Verwalten und die eigenen Punkte, Strafen, Dienstgrade und Anwesenheit gibt es nur mit „Mitglied der Kompanie“.');
            }
        }
    }

    /**
     * Ausnahmen ohne das, was die Rolle schon regelt: zusätzlich nur Rechte, die sie nicht hat, entzogen nur
     * Rechte, die sie hat.
     *
     * @param Capability[] $base
     * @param array{grant: string[], revoke: string[]} $o
     * @return array{grant: string[], revoke: string[]}
     */
    private static function normalized(array $base, array $o): array
    {
        $values = self::values($base);
        $grant = array_diff($o['grant'], $values);
        $revoke = array_intersect($o['revoke'], $values);
        return ['grant' => self::ordered($grant), 'revoke' => self::ordered($revoke)];
    }

    /**
     * @param Capability[] $base
     * @param array{grant: string[], revoke: string[]} $o
     * @return array{role: string[], grant: string[], revoke: string[], editable: string[]}
     */
    private function view(User $actor, array $base, array $o): array
    {
        return [
            'role' => self::values($base),
            'grant' => $o['grant'],
            'revoke' => $o['revoke'],
            'editable' => self::values($this->editableBy($actor)),
        ];
    }

    /** @return Capability[] */
    private function editableBy(User $actor): array
    {
        if ($actor->isAdmin()) {
            return Roles::GRANTABLE;
        }
        return array_values(array_filter(
            Roles::GRANTABLE,
            static fn (Capability $c): bool => $actor->can($c) && $c !== Capability::PromoteUsers && $c !== Capability::CreateUsers,
        ));
    }

    /** @return array<int, array{grant: string[], revoke: string[]}> */
    private function loadOverrides(): array
    {
        if ($this->overrides === null) {
            $this->overrides = [];
            $saved = $this->settings->get(self::OVERRIDES_SETTING);
            foreach (is_array($saved) ? $saved : [] as $o) {
                if (!is_array($o) || !is_numeric($o['user'] ?? null)) {
                    continue;
                }
                $entry = [
                    'grant' => self::ordered(array_filter((array) ($o['grant'] ?? []), 'is_string')),
                    'revoke' => self::ordered(array_filter((array) ($o['revoke'] ?? []), 'is_string')),
                ];
                if ($entry['grant'] !== [] || $entry['revoke'] !== []) {
                    $this->overrides[(int) $o['user']] = $entry;
                }
            }
        }
        return $this->overrides;
    }

    /** @param array{grant: string[], revoke: string[]} $o leer: Ausnahmen der Person entfernen */
    private function storeOverrides(int $id, array $o): void
    {
        $all = $this->loadOverrides();
        if ($o['grant'] === [] && $o['revoke'] === []) {
            unset($all[$id]);
        } else {
            $all[$id] = $o;
        }
        $list = [];
        foreach ($all as $user => $entry) {
            $list[] = ['user' => $user] + $entry;
        }
        $this->settings->set(self::OVERRIDES_SETTING, $list);
        $this->overrides = $all;
    }

    /**
     * Vergebbare Rechte in fester Reihenfolge, ohne Doppelte und Unbekannte.
     *
     * @param string[] $values
     * @return string[]
     */
    private static function ordered(array $values): array
    {
        return array_values(array_filter(
            self::values(Roles::GRANTABLE),
            static fn (string $v): bool => in_array($v, $values, true),
        ));
    }

    /**
     * @param Capability[] $caps
     * @return string[]
     */
    private static function values(array $caps): array
    {
        return array_map(static fn (Capability $c): string => $c->value, $caps);
    }

    /** @return string[] */
    private static function validCapabilityList(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw)) {
            throw ApiError::invalid('Rechte als Liste angeben.');
        }
        foreach ($raw as $c) {
            $cap = is_string($c) ? Capability::tryFrom($c) : null;
            if ($cap === null || !in_array($cap, Roles::GRANTABLE, true)) {
                throw ApiError::invalid('Unbekanntes oder nicht vergebbares Recht: ' . (is_scalar($c) ? (string) $c : '?') . '.');
            }
        }
        return self::ordered($raw);
    }

    public function label(string $key): string
    {
        return $this->find($key)['label'] ?? $this->config->roleLabel($key);
    }

    public function pointsFactor(string $key): float
    {
        return $this->find($key)['points_factor'] ?? 1.0;
    }

    /**
     * Rechte einer Person: die ihrer Grundrolle und aller Zusatzrollen zusammen (Issue #66). Die einzige Stelle, die die
     * Rechte einer Person ausrechnet (die Adapter rufen sie über ihre Closure auf). Die Vorschau „App als Rolle ansehen“
     * (Issue #63, Auth\Preview) ersetzt dagegen die angemeldete Person durch eine mit genau einer Rolle (capabilities()). Die Zusatzrollen kosten für alle Benutzer einer Anfrage zusammen eine
     * Abfrage, und nur, wenn die Grundrolle den Mitgliederbereich hat. Dazu kommen die Ausnahmen der Person.
     *
     * @return Capability[]
     */
    public function capabilitiesFor(int $userId, string $baseRole): array
    {
        $o = $this->loadOverrides()[$userId] ?? null;
        if ($o !== null && self::overridable($baseRole)) {
            return self::effective($this->grantedFor($userId, $baseRole), $o);
        }
        $caps = $this->capabilities($baseRole);
        foreach ($this->extraRoles($userId, $baseRole) as $key) {
            foreach ($this->capabilities($key) as $cap) {
                if (!in_array($cap, $caps, true)) {
                    $caps[] = $cap;
                }
            }
        }
        return $caps;
    }

    /**
     * Ausdrücklich gegebene Rechte aller Rollen einer Person (Grundrolle und Zusatzrollen), ohne Ausnahmen: die
     * Grundlage der Ausnahmen.
     *
     * @return Capability[]
     */
    private function grantedFor(int $userId, string $baseRole): array
    {
        $caps = $this->granted($baseRole);
        foreach ($this->extraRoles($userId, $baseRole) as $key) {
            foreach ($this->granted($key) as $cap) {
                if (!in_array($cap, $caps, true)) {
                    $caps[] = $cap;
                }
            }
        }
        return $caps;
    }

    /** Punktefaktor einer Person: der kleinste ihrer Rollen (Grundrolle und Zusatzrollen, Entscheidung zu Issue #66). */
    public function pointsFactorFor(int $userId, string $baseRole): float
    {
        $factor = $this->pointsFactor($baseRole);
        foreach ($this->extraRoles($userId, $baseRole) as $key) {
            $factor = min($factor, $this->pointsFactor($key));
        }
        return $factor;
    }

    /**
     * Wirksame Zusatzrollen einer Person, in der Reihenfolge der Rollen: nur bestehende eigene Rollen und nur, wenn die
     * Grundrolle den Mitgliederbereich hat (Admins haben ohnehin alle Rechte). Gespeicherte, die nicht wirken (z. B. nach
     * einem Wechsel der Grundrolle im WP-Backend), bleiben unberücksichtigt.
     *
     * @return list<string>
     */
    public function extraRoles(int $userId, string $baseRole): array
    {
        if (!$this->allowsExtraRoles($baseRole)) {
            return [];
        }
        $saved = $this->assignments()[$userId] ?? [];
        if ($saved === []) {
            return [];
        }
        $out = [];
        foreach (array_keys($this->all()) as $key) {
            if ($key !== $baseRole && self::canBeExtra($key) && in_array($key, $saved, true)) {
                $out[] = $key;
            }
        }
        return $out;
    }

    /**
     * Wirksame Zusatzrollen einer Person (wie extraRoles).
     *
     * @return list<string>
     */
    public function extraRolesOf(User $user): array
    {
        // Vorschau (Issue #63): genau die gewählte Rolle, nicht die gespeicherten Zusatzrollen des Admin-Kontos.
        return $user->preview ? [] : $this->extraRoles($user->id, $user->role);
    }

    /** Kann eine Person mit dieser Grundrolle Zusatzrollen haben? Nur mit Mitgliederbereich und nicht als Admin. */
    public function allowsExtraRoles(string $baseRole): bool
    {
        return $baseRole !== Roles::ADMIN && in_array(Capability::Member, $this->capabilities($baseRole), true);
    }

    /**
     * Taugt die Rolle als Zusatzrolle? Nur eigene Rollen (auch die Standardrollen der Bereiche und frühere feste wie
     * Verwalter oder Anwärter): Gast, Mitglied und Admin bestimmen, wer überhaupt zur Kompanie gehört, und sind deshalb
     * nur Grundrolle. Eigene Rollen ohne Mitgliederbereich wirken als Zusatzrolle nicht (sie bringen höchstens den
     * Kalender, den der Mitgliederbereich schon enthält). Admin ginge auch sonst nicht: Er hat manage_settings, das keine andere Rolle vergeben kann.
     */
    public static function canBeExtra(string $key): bool
    {
        return !Roles::isSystem($key);
    }

    /**
     * Zusatzrollen einer Person ersetzen (geprüft hat Domain\MemberService). Leere Liste: keine mehr.
     *
     * @param list<string> $keys
     */
    public function setExtraRoles(int $userId, array $keys): void
    {
        if ($this->memberRoles === null) {
            throw ApiError::invalid('Zusatzrollen gibt es hier nicht.');
        }
        $current = $this->assignments()[$userId] ?? [];
        $keys = array_values(array_unique($keys));
        $this->memberRoles->replace($userId, $current, $keys, $this->time?->nowUtc() ?? gmdate('Y-m-d H:i:s'));
        if ($keys === []) {
            unset($this->extra[$userId]);
        } else {
            $this->extra[$userId] = $keys;
        }
    }

    /**
     * Gespeicherte Zusatzrollen aller Personen (eine Abfrage, danach aus dem Zwischenspeicher dieser Anfrage).
     *
     * @return array<int, list<string>>
     */
    private function assignments(): array
    {
        return $this->extra ??= $this->memberRoles?->all() ?? [];
    }

    /**
     * Für /config: Schlüssel, Bezeichnung, Punktefaktor (die Bezeichnungen braucht die App überall), ob die Rolle als
     * Zusatzrolle taugt (Issue #66), ob neue Konten sie bekommen können und ob sie sich zum Mitglied machen lässt.
     *
     * @return array<int, array{key: string, label: string, points_factor: float, extra: bool, new_accounts: bool, promotable: bool}>
     */
    public function publicList(): array
    {
        return array_values(array_map(
            static fn (array $r): array => [
                'key' => $r['key'],
                'label' => $r['label'],
                'points_factor' => $r['points_factor'],
                'extra' => self::canBeExtra($r['key']),
                'new_accounts' => $r['key'] === Roles::MEMBER || $r['new_accounts'],
                'promotable' => $r['promotable'],
            ],
            $this->all(),
        ));
    }

    /**
     * Für Einstellungen → Rollen: mit Rechten, Anzahl der Personen und was sich ändern lässt.
     *
     * @return array<int, array<string, mixed>>
     */
    public function describe(): array
    {
        // Personen je Rolle: als Grundrolle und als Zusatzrolle (wer beides nicht haben kann, zählt einmal).
        $counts = [];
        $extra = [];
        foreach ($this->users->list(true) as $u) {
            $counts[$u->role] = ($counts[$u->role] ?? 0) + 1;
            foreach ($this->extraRolesOf($u) as $key) {
                $extra[$key] = ($extra[$key] ?? 0) + 1;
            }
        }
        $out = [];
        foreach ($this->all() as $key => $r) {
            $out[] = [
                'key' => $key,
                'label' => $r['label'],
                'capabilities' => array_map(static fn (Capability $c): string => $c->value, $this->granted($key)),
                'points_factor' => $r['points_factor'],
                'new_accounts' => $key === Roles::MEMBER || $r['new_accounts'],
                'promotable' => $r['promotable'],
                'users' => ($counts[$key] ?? 0) + ($extra[$key] ?? 0),
                // Davon als Zusatzrolle (Issue #66)
                'extra_users' => $extra[$key] ?? 0,
                'system' => Roles::isSystem($key),
                'deletable' => !Roles::isSystem($key),
            ];
        }
        return $out;
    }

    /**
     * Einmalig: die festen Rollen speichern, damit spätere Änderungen an kompanie.json nichts mehr
     * verschieben. Installationen von vor dieser Version behalten zusätzlich ihre früheren festen Rollen
     * (Verwalter, Leitung), damit niemand Rechte verliert.
     */
    public function initialize(bool $existingInstallation): void
    {
        if (is_array($this->settings->get(self::SETTING))) {
            $this->migrateCandidate();
            $this->migrateViewRights();
            return;
        }
        $roles = $this->templates();
        if ($existingInstallation) {
            $admin = $roles[Roles::ADMIN];
            unset($roles[Roles::ADMIN]);
            foreach (Roles::LEGACY as $key => $caps) {
                $roles[$key] = self::role($key, $this->config->roleLabel($key), self::ordered($caps));
            }
            $roles[Roles::ADMIN] = $admin;
        }
        $this->save($roles);
        $this->settings->set(self::CANDIDATE_SETTING, true);
        $this->settings->set(self::VIEW_SETTING, true);
    }

    /**
     * Einmalig nach dem Update: Die frühere feste Rolle „Anwärter“ wird eine eigene Rolle mit demselben Schlüssel, damit
     * niemand Rechte verliert. Sie behält ihren Namen und den internen Kalender, ist beim Anlegen von Konten wählbar und
     * lässt sich zum Mitglied machen, wie bisher; der Admin kann sie danach ändern oder löschen. Gespeichert war sie nur,
     * wenn sich die Rollen seit ihrer Einführung geändert hatten; sonst nur anlegen, wenn jemand sie hat. Sie steht zwischen
     * Gast und Mitglied: Die Zähler zeigen die Rollen von unten nach oben, Anwärter also wie bisher nach den Mitgliedern.
     */
    private function migrateCandidate(): void
    {
        if ($this->settings->get(self::CANDIDATE_SETTING)) {
            return;
        }
        $roles = $this->all();
        $role = $roles[Roles::CANDIDATE] ?? null;
        if ($role === null) {
            foreach ($this->users->list(false) as $u) {
                if ($u->role === Roles::CANDIDATE) {
                    // Vor migrateViewRights mit den Rechten von damals (die Migration ergänzt sie).
                    $caps = $this->settings->get(self::VIEW_SETTING) ? self::ordered(Roles::CANDIDATE_DEFAULTS) : [Capability::Calendar->value];
                    $role = self::role(Roles::CANDIDATE, $this->freeLabel($this->config->roleLabel(Roles::CANDIDATE), $roles), $caps);
                    break;
                }
            }
        }
        if ($role !== null) {
            $role['new_accounts'] = true;
            $role['promotable'] = !in_array(Capability::Member->value, $role['capabilities'], true);
            unset($roles[Roles::CANDIDATE]);
            $next = [];
            foreach ($roles as $key => $r) {
                if ($key === Roles::MEMBER) {
                    $next[Roles::CANDIDATE] = $role;
                }
                $next[$key] = $r;
            }
            $this->save($next + [Roles::CANDIDATE => $role]);
        }
        $this->settings->set(self::CANDIDATE_SETTING, true);
    }

    /**
     * Einmalig nach dem Update: Früher schloss der Mitgliederbereich (member) alles Sehen ein, Öffentliches sah jeder,
     * und der Kalender brachte das Brett mit. Jetzt gilt jedes Recht einzeln: Jede Rolle bekommt ausdrücklich, was sie
     * bisher sah (Roles::expandLegacy), Gast das Öffentliche. Die Ausnahmen einzelner Personen werden so umgerechnet, dass
     * jede Person danach genau dasselbe sieht wie vorher.
     */
    private function migrateViewRights(): void
    {
        if ($this->settings->get(self::VIEW_SETTING)) {
            return;
        }
        $roles = $this->all();
        $before = static fn (string $key): array => $key === Roles::ADMIN ? [] : ($roles[$key]['capabilities'] ?? []);
        // Ausnahmen zuerst nach den alten Regeln (Rechte der Rollen und Zusatzrollen, wie sie gespeichert sind).
        $targets = [];
        foreach ($this->loadOverrides() as $id => $o) {
            $u = $this->users->find($id);
            if ($u === null || !self::overridable($u->role)) {
                continue;
            }
            $base = $before($u->role);
            if (in_array(Capability::Member->value, $base, true)) {
                foreach ($this->assignments()[$id] ?? [] as $extra) {
                    if ($extra !== $u->role && self::canBeExtra($extra) && isset($roles[$extra])) {
                        $base = [...$base, ...$before($extra)];
                    }
                }
            }
            $targets[$id] = [$u->role, self::legacyEffective(array_values(array_unique($base)), $o)];
        }
        foreach ($roles as $key => $r) {
            if ($key !== Roles::ADMIN) {
                $roles[$key]['capabilities'] = self::ordered(Roles::expandLegacy($r['capabilities']));
            }
        }
        $this->save($roles);
        foreach ($targets as $id => [$baseRole, $target]) {
            $base = self::values($this->grantedFor($id, $baseRole));
            $this->storeOverrides($id, ['grant' => self::ordered(array_diff($target, $base)), 'revoke' => self::ordered(array_diff($base, $target))]);
        }
        $this->settings->set(self::VIEW_SETTING, true);
    }

    /**
     * Rechte einer Person mit Ausnahmen nach den Regeln vor migrateViewRights, in heutigen Rechten.
     *
     * @param string[] $base
     * @param array{grant: string[], revoke: string[]} $o
     * @return string[]
     */
    private static function legacyEffective(array $base, array $o): array
    {
        $caps = array_values(array_diff(array_unique([...$base, ...$o['grant']]), $o['revoke']));
        $member = in_array(Capability::Member->value, $caps, true);
        // Ohne Mitgliederbereich blieben nur Kalender und „Intern“, mit ihm gehörten beide dazu.
        $kept = $member ? $caps : array_values(array_intersect($caps, [Capability::Calendar->value, Capability::ViewIntern->value]));
        return Roles::expandLegacy($member ? [...$kept, Capability::Calendar->value, Capability::ViewIntern->value] : $kept);
    }

    /**
     * Ein Bereich wurde ein- oder ausgeschaltet (Domain\Features). Eingeschaltet: Standardrolle anlegen,
     * wenn es sie noch nicht gibt. Ausgeschaltet: entfernen, wenn ihr niemand zugeordnet ist.
     *
     * @param string[] $capabilities
     */
    public function featureChanged(string $key, bool $on, string $label, array $capabilities): void
    {
        $label = mb_substr($label, 0, self::LABEL_MAX);
        $roles = $this->all();
        if ($on) {
            if (isset($roles[$key])) {
                return;
            }
            // Name schon vergeben (z. B. selbst angelegt): nicht doppelt anlegen.
            foreach ($roles as $r) {
                if (mb_strtolower($r['label']) === mb_strtolower($label)) {
                    return;
                }
            }
            // Die Standardrolle sieht, was Mitglieder anfangs sehen (Features nennt nur member und das Recht des Bereichs).
            $this->save(self::beforeAdmin($roles, self::role($key, $label, self::ordered(Roles::expandLegacy($capabilities)))));
            return;
        }
        if (!isset($roles[$key]) || Roles::isSystem($key)) {
            return;
        }
        foreach ($this->users->list(true) as $u) {
            if ($u->role === $key || in_array($key, $this->extraRolesOf($u), true)) {
                return;
            }
        }
        // Zuordnungen, die nicht wirken, mit entfernen: Der Schlüssel des Bereichs kommt beim Einschalten wieder.
        $this->memberRoles?->removeRole($key);
        $this->extra = null;
        unset($roles[$key]);
        $this->save($roles);
    }

    /**
     * @param array<string, mixed> $in label, capabilities, points_factor, new_accounts, promotable
     * @return array{key: string, label: string, capabilities: string[], points_factor: float, new_accounts: bool, promotable: bool}
     */
    public function create(array $in): array
    {
        $roles = $this->all();
        if (count($roles) >= self::MAX_ROLES) {
            throw ApiError::invalid(sprintf('Mehr als %d Rollen sind nicht möglich.', self::MAX_ROLES));
        }
        $label = $this->validLabel($in['label'] ?? null, null);
        $role = self::role('', $label, self::validCapabilities($in['capabilities'] ?? [], null));
        $role['points_factor'] = array_key_exists('points_factor', $in) ? self::validFactor($in['points_factor']) : 1.0;
        $role['new_accounts'] = array_key_exists('new_accounts', $in) && self::validFlag($in['new_accounts']);
        $role['promotable'] = array_key_exists('promotable', $in) && self::validFlag($in['promotable']);
        self::checkPromotable($role);
        // Schlüssel erst nach der Prüfung vergeben (sonst verbrauchten ungültige Anfragen Nummern).
        $role['key'] = $this->newKey();
        $this->save(self::beforeAdmin($roles, $role));
        return $role;
    }

    /**
     * Nur mitgeschickte Felder ändern. Gast: Bezeichnung und Rechte zum Sehen; Mitglied: dazu der Punktefaktor; Admin:
     * Bezeichnung und Punktefaktor.
     *
     * @param array<string, mixed> $in label, capabilities, points_factor, new_accounts, promotable
     * @return array{key: string, label: string, capabilities: string[], points_factor: float, new_accounts: bool, promotable: bool}
     */
    public function update(string $key, array $in): array
    {
        $roles = $this->all();
        if (!isset($roles[$key])) {
            throw ApiError::notFound('Rolle nicht gefunden.');
        }
        $role = $roles[$key];
        if (array_key_exists('label', $in)) {
            $role['label'] = $this->validLabel($in['label'], $key);
        }
        $fixed = Roles::isSystem($key);
        if (array_key_exists('capabilities', $in)) {
            if ($key === Roles::ADMIN) {
                throw ApiError::invalid('Admins haben immer alle Rechte.');
            }
            $role['capabilities'] = self::validCapabilities($in['capabilities'], $fixed ? $key : null);
        }
        if (array_key_exists('points_factor', $in)) {
            // Punkte bekommen nur Personen mit Mitgliederbereich (Admins haben ihn immer).
            if ($key === Roles::GUEST) {
                throw ApiError::invalid('Gäste haben keinen Punktefaktor.');
            }
            $role['points_factor'] = self::validFactor($in['points_factor']);
        }
        foreach (['new_accounts', 'promotable'] as $flag) {
            if (array_key_exists($flag, $in)) {
                if ($fixed) {
                    throw ApiError::invalid('Das lässt sich nur bei eigenen Rollen einstellen.');
                }
                $role[$flag] = self::validFlag($in[$flag]);
            }
        }
        self::checkPromotable($role);
        $roles[$key] = $role;
        $this->save($roles);
        return $role;
    }

    /**
     * Löscht eine Rolle. Sind ihr noch Personen zugeordnet (als Grundrolle oder als Zusatzrolle), bekommen sie
     * $replacement; ohne Ersatz → 400. Als Zusatzrolle wird sie durch den Ersatz ersetzt, wenn der als Zusatzrolle taugt
     * (eine eigene Rolle, die nicht schon die Grundrolle der Person ist); sonst fällt sie dort einfach weg.
     */
    public function delete(string $key, ?string $replacement): void
    {
        $roles = $this->all();
        if (!isset($roles[$key])) {
            throw ApiError::notFound('Rolle nicht gefunden.');
        }
        if (Roles::isSystem($key)) {
            throw ApiError::invalid('Diese Rolle lässt sich nicht löschen.');
        }
        $users = $this->users->list(true);
        $assigned = array_filter($users, static fn (User $u): bool => $u->role === $key);
        $extra = array_filter($users, fn (User $u): bool => in_array($key, $this->extraRolesOf($u), true));
        /** @var array<int, string> $changed Benutzer-ID => Grundrolle danach (für die Ausnahmen) */
        $changed = [];
        if ($assigned !== [] || $extra !== []) {
            if ($replacement === null || $replacement === '') {
                throw ApiError::invalid(sprintf('Dieser Rolle sind noch %d Personen zugeordnet. Bitte eine Rolle wählen, die sie stattdessen bekommen.', count($assigned) + count($extra)));
            }
            if ($replacement === $key || !isset($roles[$replacement]) || $replacement === Roles::ADMIN) {
                throw ApiError::invalid('Ungültige Ersatzrolle.');
            }
            foreach ($assigned as $u) {
                try {
                    $this->users->setRole($u->id, $replacement);
                    $changed[$u->id] = $replacement;
                } catch (\DomainException $e) {
                    // Nicht abbrechen: Der Schlüssel wird nie wieder vergeben, die Person hat danach keine Rechte.
                    error_log('Kompanie-App: Rolle von ' . $u->name . ' nicht geändert: ' . $e->getMessage());
                }
            }
            foreach ($extra as $u) {
                $next = array_values(array_diff($this->assignments()[$u->id] ?? [], [$key]));
                if (self::canBeExtra($replacement) && $replacement !== $u->role) {
                    $next[] = $replacement;
                }
                $this->setExtraRoles($u->id, $next);
                $changed[$u->id] ??= $u->role;
            }
        }
        // Auch Zuordnungen, die nicht wirken (z. B. bei Gästen), nicht stehen lassen.
        $this->memberRoles?->removeRole($key);
        $this->extra = null;
        unset($roles[$key]);
        $this->save($roles);
        foreach ($changed as $id => $baseRole) {
            $this->roleChanged($id, $baseRole);
        }
    }

    /**
     * Reihenfolge der Rollen (Einstellungen → Rollen, per Ziehen): $keys sind alle Rollen in der neuen Reihenfolge, auch
     * die festen (z. B. Anwärter zwischen Gast und Mitglied). Die Reihenfolge gilt überall, wo Rollen stehen (Auswahl,
     * Zusatzrollen; die Zähler der Mitgliederliste und der Anwesenheit von unten nach oben).
     */
    public function reorder(mixed $keys): void
    {
        $roles = $this->all();
        if (!is_array($keys) || !array_is_list($keys) || array_filter($keys, 'is_string') !== $keys
            || count(array_unique($keys)) !== count($keys) || count($keys) !== count($roles) || array_diff(array_keys($roles), $keys) !== []) {
            throw ApiError::invalid('Bitte alle Rollen in der neuen Reihenfolge angeben.');
        }
        $next = [];
        foreach ($keys as $k) {
            $next[$k] = $roles[$k];
        }
        $this->save($next);
    }

    /**
     * Rollen, die $actor neuen Konten geben darf (Mitglieder anlegen): Mitglied und die eigenen Rollen, die der Admin
     * dafür freigegeben hat. Wer kein Admin ist, nur solche mit höchstens dem Mitgliederbereich bzw. dem Kalender oder
     * mit Rechten, die er selbst weitergeben darf (canHandle): Konten anlegen soll keine weiteren Rechte verschaffen.
     */
    public function canCreateAs(User $actor, string $key): bool
    {
        $role = $this->find($key);
        if ($role === null || ($key !== Roles::MEMBER && !$role['new_accounts'])) {
            return false;
        }
        $basic = array_filter($this->capabilities($key), static fn (Capability $c): bool => !$c->isView() && $c !== Capability::Member) === [];
        return $actor->isAdmin() || $basic || $this->canHandle($actor, $key);
    }

    /** Lässt sich die Rolle von denen mit „Mitglieder anlegen“ zum Mitglied machen (z. B. Anwärter, Issue #23)? */
    public function promotable(string $key): bool
    {
        return $this->find($key)['promotable'] ?? false;
    }

    /**
     * Darf $actor einer Person diese Rolle geben bzw. ihre Rolle ändern (oder ihr Passwort zurücksetzen)?
     * Admins immer. Sonst nur Rollen, deren Rechte er selbst hat, und keine Rollen, die Konten anlegen
     * oder Rollen vergeben dürfen: Diese Rechte weitergeben bleibt den Admins vorbehalten.
     */
    public function canHandle(User $actor, string $key): bool
    {
        if ($actor->isAdmin()) {
            return true;
        }
        if ($key === Roles::ADMIN) {
            return false;
        }
        foreach ($this->capabilities($key) as $cap) {
            if (!$actor->can($cap) || $cap === Capability::PromoteUsers || $cap === Capability::CreateUsers) {
                return false;
            }
        }
        return true;
    }

    /**
     * Darf $actor diese Person verwalten (Rolle ändern, Passwort zurücksetzen)? Wie canHandle für ihre
     * Rolle, zusätzlich für die Rechte, die sie tatsächlich hat (bei WordPress auch aus WP-Rollen).
     */
    public function canHandleUser(User $actor, User $target): bool
    {
        if ($actor->isAdmin()) {
            return true;
        }
        if ($target->isAdmin() || !$this->canHandle($actor, $target->role)) {
            return false;
        }
        foreach ($target->capabilities as $cap) {
            if (!$actor->can($cap) || $cap === Capability::PromoteUsers || $cap === Capability::CreateUsers) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, array{key: string, label: string, capabilities: string[], points_factor: float, new_accounts: bool, promotable: bool}> $roles */
    private function save(array $roles): void
    {
        $this->settings->set(self::SETTING, array_values($roles));
        $this->roles = $roles;
    }

    /** @return array<string, array{key: string, label: string, capabilities: string[], points_factor: float, new_accounts: bool, promotable: bool}> */
    private function templates(): array
    {
        $out = [];
        foreach (Roles::TEMPLATES as $key => $caps) {
            $out[$key] = self::role($key, $this->config->roleLabel($key), self::ordered($caps));
        }
        return $out;
    }

    /**
     * Neue Rolle mit Punktefaktor 1, nicht beim Anlegen von Konten wählbar und nicht zum Mitglied zu machen.
     *
     * @param string[] $capabilities
     * @return array{key: string, label: string, capabilities: string[], points_factor: float, new_accounts: bool, promotable: bool}
     */
    private static function role(string $key, string $label, array $capabilities): array
    {
        return ['key' => $key, 'label' => $label, 'capabilities' => $capabilities, 'points_factor' => 1.0, 'new_accounts' => false, 'promotable' => false];
    }

    /**
     * Neue Rolle hinten anfügen; steht Admin am Ende (wie anfangs), davor.
     *
     * @param array<string, array{key: string, label: string, capabilities: string[], points_factor: float, new_accounts: bool, promotable: bool}> $roles
     * @param array{key: string, label: string, capabilities: string[], points_factor: float, new_accounts: bool, promotable: bool} $role
     * @return array<string, array{key: string, label: string, capabilities: string[], points_factor: float, new_accounts: bool, promotable: bool}>
     */
    private static function beforeAdmin(array $roles, array $role): array
    {
        $admin = array_key_last($roles) === Roles::ADMIN ? $roles[Roles::ADMIN] : null;
        if ($admin !== null) {
            unset($roles[Roles::ADMIN]);
        }
        $roles[$role['key']] = $role;
        if ($admin !== null) {
            $roles[Roles::ADMIN] = $admin;
        }
        return $roles;
    }

    /**
     * $base als Name, sonst mit Zusatz „(fest)“, „(fest 2)“ …, wenn schon eine Rolle so heißt (doppelte Namen ließen sich
     * sonst nicht mehr speichern).
     *
     * @param array<string, array{label: string}> $roles
     */
    private function freeLabel(string $base, array $roles): string
    {
        $taken = array_map(static fn (array $r): string => mb_strtolower($r['label']), $roles);
        $label = $base;
        for ($n = 1; in_array(mb_strtolower($label), $taken, true); $n++) {
            $suffix = $n === 1 ? ' (fest)' : " (fest $n)";
            $label = rtrim(mb_substr($base, 0, self::LABEL_MAX - mb_strlen($suffix))) . $suffix;
        }
        return $label;
    }

    /**
     * Gespeicherte Liste prüfen, in der gespeicherten Reihenfolge; guest, member und admin sind immer da (fehlen sie,
     * kommen guest und member nach vorn, admin ans Ende).
     *
     * @param array<int|string, mixed> $saved
     * @return array<string, array{key: string, label: string, capabilities: string[], points_factor: float, new_accounts: bool, promotable: bool}>
     */
    private function normalize(array $saved): array
    {
        $out = [];
        foreach ($saved as $r) {
            if (!is_array($r) || !is_string($r['key'] ?? null) || $r['key'] === '' || isset($out[$r['key']])) {
                continue;
            }
            $key = $r['key'];
            $caps = array_values(array_filter((array) ($r['capabilities'] ?? []), 'is_string'));
            // Früher ein Recht „manage“ für Punkte und Strafen zusammen.
            if (in_array('manage', $caps, true)) {
                $caps = array_values(array_unique(array_merge(array_diff($caps, ['manage']), ['manage_points', 'manage_penalties'])));
            }
            $system = Roles::isSystem($key);
            if ($system) {
                $caps = self::systemCapabilities($key, $caps);
            }
            $out[$key] = [
                'key' => $key,
                'label' => is_string($r['label'] ?? null) && $r['label'] !== '' ? $r['label'] : $this->config->roleLabel($key),
                'capabilities' => $caps,
                'points_factor' => $key === Roles::GUEST ? 1.0 : (is_numeric($r['points_factor'] ?? null) ? (float) $r['points_factor'] : 1.0),
                'new_accounts' => !$system && ($r['new_accounts'] ?? false) === true,
                'promotable' => !$system && ($r['promotable'] ?? false) === true && !in_array(Capability::Member->value, $caps, true),
            ];
        }
        $front = [];
        foreach (Roles::TEMPLATES as $key => $caps) {
            if (isset($out[$key])) {
                continue;
            }
            // Fehlende feste Rolle (z. B. nach einem Update): Heißt schon eine eigene Rolle so, bekommt die feste einen
            // anderen Namen.
            $role = self::role($key, $this->freeLabel($this->config->roleLabel($key), $front + $out), self::ordered($caps));
            if ($key === Roles::ADMIN) {
                $out[$key] = $role;
            } else {
                $front[$key] = $role;
            }
        }
        return $front + $out;
    }

    /**
     * Fester, kurzer Schlüssel für neue Rollen (r1, r2, …); die Bezeichnung lässt sich jederzeit ändern.
     * Nie den Schlüssel einer gelöschten Rolle: Wer ihn noch trägt (z. B. aus einer alten Sicherung),
     * bekäme sonst unbemerkt die Rechte der neuen Rolle.
     */
    private function newKey(): string
    {
        $n = max(0, (int) $this->settings->get(self::SEQ_SETTING, 0));
        foreach (array_keys($this->all()) as $key) {
            if (preg_match('/^r(\d+)$/', (string) $key, $m)) {
                $n = max($n, (int) $m[1]);
            }
        }
        $n++;
        $this->settings->set(self::SEQ_SETTING, $n);
        return 'r' . $n;
    }

    private function validLabel(mixed $raw, ?string $key): string
    {
        $label = is_string($raw) ? trim((string) preg_replace('/\s+/u', ' ', $raw)) : '';
        if ($label === '') {
            throw ApiError::invalid('Bitte einen Namen für die Rolle angeben.');
        }
        if (mb_strlen($label) > self::LABEL_MAX) {
            throw ApiError::invalid(sprintf('Der Name darf höchstens %d Zeichen lang sein.', self::LABEL_MAX));
        }
        // Unveränderter Name: nicht prüfen (sonst ließen sich Rechte einer Rolle mit doppeltem Namen nicht mehr ändern).
        if ($key !== null && ($this->find($key)['label'] ?? null) === $label) {
            return $label;
        }
        foreach ($this->all() as $k => $r) {
            if ($k !== $key && mb_strtolower($r['label']) === mb_strtolower($label)) {
                throw ApiError::invalid('Eine Rolle mit diesem Namen gibt es schon.');
            }
        }
        return $label;
    }

    /**
     * Gespeicherte Rechte einer festen Rolle: Admin keine (er hat ohnehin alle), Gast nur Rechte zum Sehen ohne
     * Voraussetzung, Mitglied immer member und sonst nur Rechte zum Sehen.
     *
     * @param string[] $caps
     * @return string[]
     */
    private static function systemCapabilities(string $key, array $caps): array
    {
        if ($key === Roles::ADMIN) {
            return [];
        }
        $out = array_filter(Roles::from($caps), static fn (Capability $c): bool => $c->isView() && ($key === Roles::MEMBER || $c->requires() === null));
        return self::ordered([...self::values($out), ...($key === Roles::MEMBER ? [Capability::Member->value] : [])]);
    }

    /**
     * Rechte einer Rolle aus der Anfrage; $system: Schlüssel einer festen Rolle (Gast, Mitglied), sonst null.
     *
     * @return string[]
     */
    private static function validCapabilities(mixed $raw, ?string $system): array
    {
        if (!is_array($raw)) {
            throw ApiError::invalid('Rechte als Liste angeben.');
        }
        $out = [];
        foreach ($raw as $c) {
            $cap = is_string($c) ? Capability::tryFrom($c) : null;
            if ($cap === null || !in_array($cap, Roles::GRANTABLE, true)) {
                throw ApiError::invalid('Unbekanntes oder nicht vergebbares Recht: ' . (is_scalar($c) ? (string) $c : '?') . '.');
            }
            $out[$cap->value] = $cap->value;
        }
        if ($system !== null) {
            if ($system === Roles::GUEST && isset($out[Capability::Member->value])) {
                throw ApiError::invalid('Gäste gehören nicht zur Kompanie; dafür gibt es die Rolle Mitglied.');
            }
            if ($system === Roles::MEMBER && !isset($out[Capability::Member->value])) {
                throw ApiError::invalid('Mitglieder gehören immer zur Kompanie.');
            }
            foreach ($out as $c) {
                if ($c !== Capability::Member->value && !Capability::from($c)->isView()) {
                    throw ApiError::invalid('Gast und Mitglied haben nur Rechte zum Sehen; zum Verwalten bitte eine eigene Rolle anlegen.');
                }
            }
        }
        self::checkRequired(array_keys($out));
        return self::ordered(array_keys($out));
    }

    private static function validFactor(mixed $raw): float
    {
        $v = is_string($raw) ? str_replace(',', '.', trim($raw)) : $raw;
        if (!is_numeric($v)) {
            throw ApiError::invalid('Der Punktefaktor muss eine Zahl sein, z. B. 0,5.');
        }
        $f = round((float) $v, 2);
        if ($f < 0 || $f > self::FACTOR_MAX) {
            throw ApiError::invalid(sprintf('Der Punktefaktor muss zwischen 0 und %s liegen.', str_replace('.', ',', (string) self::FACTOR_MAX)));
        }
        return $f;
    }

    private static function validFlag(mixed $raw): bool
    {
        if (!is_bool($raw)) {
            throw ApiError::invalid('Bitte ja oder nein (true/false) angeben.');
        }
        return $raw;
    }

    /**
     * Zum Mitglied machen ist ein Aufstieg: nur für Rollen ohne Mitgliederbereich (sonst nähme „Mitglieder anlegen“ einer
     * Person Rechte, die nur „Rollen vergeben“ ändern darf).
     *
     * @param array{capabilities: string[], promotable: bool} $role
     */
    private static function checkPromotable(array $role): void
    {
        if ($role['promotable'] && in_array(Capability::Member->value, $role['capabilities'], true)) {
            throw ApiError::invalid('Zum Mitglied machen lässt sich nur eine Rolle ohne Mitgliederbereich.');
        }
    }
}
