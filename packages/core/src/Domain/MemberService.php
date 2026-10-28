<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Port\Authenticator;
use KompanieApp\Core\Port\Cache;
use KompanieApp\Core\Port\UserDirectory;
use KompanieApp\Core\Storage\MemberContactRepository;

/** Mitglieder, Rollen und Passwörter. */
final class MemberService
{
    /** WordPress speichert höchstens 100 Zeichen (user_email); vorab prüfen, damit Mehrere-Anlegen nicht mittendrin scheitert. */
    public const EMAIL_MAX = 100;
    /** Handynummer: so lang wie die Spalte in member_contacts */
    public const PHONE_MAX = 30;

    public function __construct(
        private readonly UserDirectory $users,
        private readonly ScoreService $scores,
        private readonly Authenticator $auth,
        private readonly ?Cache $cache = null,
        private readonly ?RoleService $roles = null,
        /** @var (\Closure(User): ?string)|null Grundrolle vor dem Ausscheiden, wenn die Person ehemalig ist (FormerMemberService) */
        private readonly ?\Closure $formerRole = null,
        private readonly ?MemberContactRepository $contacts = null,
        private readonly ?Time $time = null,
    ) {
    }

    /** Grundrolle vor dem Ausscheiden, wenn die Person ehemalig ist; sonst null. */
    private function formerRole(User $user): ?string
    {
        return $this->formerRole !== null ? ($this->formerRole)($user) : null;
    }

    private function denyFormer(User $user): void
    {
        if ($this->formerRole($user) !== null) {
            throw ApiError::invalid('Diese Person ist ehemalig. Bitte zuerst wieder aufnehmen.');
        }
    }

    /** Versuche beim Passwortwechsel (Prüfung des aktuellen Passworts): höchstens so viele in 15 Minuten. */
    public const PASSWORD_ATTEMPTS = 5;

    /** @return array<string, mixed> */
    public function me(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'level' => $user->role,
            'extra_roles' => $this->roles?->extraRolesOf($user) ?? [],
            'points' => $this->scores->pointsTotal($user->id),
            'open_cents' => $this->scores->openCents($user->id),
        ];
    }

    /**
     * Mitglieder mit Summen; mit $includeCandidates auch Anwärter (nur interner Kalender), mit $includeGuests
     * alle Konten. $alsoIds: Konten, die zusätzlich dazugehören, auch wenn sie keine Mitglieder (mehr) sind, z. B. weil
     * sie noch einen Dienstgrad oder Titel haben, der sich nur bei ihnen entfernen lässt.
     *
     * @param list<int> $alsoIds
     * @return array<int, array<string, mixed>>
     */
    public function list(bool $includeGuests, ?User $viewer = null, bool $includeCandidates = false, array $alsoIds = []): array
    {
        $totals = $this->scores->totalsByUser();
        [$points, $penalties] = self::visibleParts($viewer);
        $out = [];
        // Anwärter haben immer eine Rolle in der App: list(false) enthält sie schon (bei WordPress nicht alle Konten laden).
        $users = $this->users->list($includeGuests);
        $seen = array_map(static fn (User $u): int => $u->id, $users);
        $extra = $this->users->findMany(array_values(array_diff(array_unique($alsoIds), $seen)));
        if ($extra !== []) {
            array_push($users, ...array_values($extra));
            usort($users, static fn (User $a, User $b): int => strcasecmp($a->name, $b->name));
        }
        foreach ($users as $u) {
            if (!$includeGuests && !$u->isMember() && !($includeCandidates && self::calendarOnly($u)) && !in_array($u->id, $alsoIds, true)) {
                continue;
            }
            $out[] = [
                'id' => $u->id,
                'name' => $u->name,
                'first_name' => $u->firstName,
                'last_name' => $u->lastName,
                'level' => $u->role,
                // Zusatzrollen (Issue #66); level bleibt die Grundrolle
                'extra_roles' => $this->roles?->extraRolesOf($u) ?? [],
                // Mit Mitgliederbereich (Punkte und Strafen möglich); sonst nur Kalender oder Gast.
                'member' => $u->isMember(),
                'points' => $points ? ($totals[$u->id]['points'] ?? 0) : null,
                'open_cents' => $penalties ? ($totals[$u->id]['open_cents'] ?? 0) : null,
            ];
        }
        return $out;
    }

    /**
     * Mitgliederliste für Mitglieder und Anwärter (Bereich directory): alle mit Mitgliederbereich oder internem
     * Kalender, ohne Gäste und ohne Punkte oder Strafen. Von der Rolle nur ihr Name, solange die Person noch als Mitglied
     * aufzunehmen ist (candidate_role, Rolle mit „Als Mitglied aufnehmen“, z. B. Anwärter), sonst null.
     * Foto, Dienstgrad und Titel ergänzt die API.
     * Mit $membersOnly nur Mitglieder, ohne Anwärter (Shortcode [kompanie_mitglieder] der Webseite).
     *
     * @return list<array{id: int, name: string, first_name: string, last_name: string, candidate_role: string|null}>
     */
    public function directory(bool $membersOnly = false): array
    {
        $out = [];
        foreach ($this->users->list(false) as $u) {
            if ($membersOnly ? $u->isMember() : self::inDirectory($u)) {
                $role = $this->roles?->find($u->role);
                $out[] = [
                    'id' => $u->id,
                    'name' => $u->name,
                    'first_name' => $u->firstName,
                    'last_name' => $u->lastName,
                    'candidate_role' => ($role['promotable'] ?? false) ? $role['label'] : null,
                ];
            }
        }
        return $out;
    }

    /**
     * Zähler über der Mitgliederliste für Mitglieder und Anwärter: Anzahl je Gruppe wie in der Anwesenheit
     * (Einstellungen → Rollen), nur die Summen, keine Rolle je Person.
     *
     * @param array<string, string> $groups Rolle => Gruppe (AttendanceService::groupMap)
     * @return list<array{group: string, count: int}>
     */
    public function directoryCounts(array $groups): array
    {
        $n = [];
        foreach ($this->users->list(false) as $u) {
            $group = $groups[$u->role] ?? null;
            if ($group !== null && self::inDirectory($u)) {
                $n[$group] = ($n[$group] ?? 0) + 1;
            }
        }
        $out = [];
        foreach ($n as $group => $count) {
            $out[] = ['group' => (string) $group, 'count' => $count];
        }
        return $out;
    }

    /** Steht in der Mitgliederliste für Mitglieder und Anwärter (und darf sie sehen): Mitglied oder nur Kalender. */
    public static function inDirectory(User $u): bool
    {
        return $u->isMember() || self::calendarOnly($u);
    }

    /** @return array<string, mixed> */
    public function detail(int $id, ?User $viewer = null): array
    {
        $u = $this->users->find($id);
        if (!$u) {
            throw ApiError::notFound('Mitglied nicht gefunden.');
        }
        [$points, $penalties] = self::visibleParts($viewer);
        return [
            'id' => $u->id,
            'name' => $u->name,
            'first_name' => $u->firstName,
            'last_name' => $u->lastName,
            'level' => $u->role,
            'extra_roles' => $this->roles?->extraRolesOf($u) ?? [],
            'member' => $u->isMember(),
            'points' => $points ? $this->scores->pointsTotal($u->id) : null,
            'open_cents' => $penalties ? $this->scores->openCents($u->id) : null,
            'point_list' => $points ? $this->scores->pointsFor($u->id) : [],
            'penalties' => $penalties ? $this->scores->penaltiesFor($u->id) : [],
            // Ausnahmen bei den Rechten: nur für die, die sie ändern dürfen.
            'rights' => $viewer !== null ? $this->roles?->overridesView($viewer, $u) : null,
        ];
    }

    /** Interner Kalender ohne Mitgliederbereich: Anwärter oder eine eigene Rolle nur mit dem Kalender. */
    private static function calendarOnly(User $u): bool
    {
        return $u->can(Capability::Calendar) && !$u->isMember();
    }

    /**
     * Wer nur Punkte (bzw. nur Strafen) vergibt, sieht in der Verwaltung nur diese Daten der Mitglieder.
     * Ohne Betrachter (intern): alles.
     *
     * @return array{0: bool, 1: bool} Punkte, Strafen
     */
    private static function visibleParts(?User $viewer): array
    {
        return $viewer === null ? [true, true] : [$viewer->can(Capability::ManagePoints), $viewer->can(Capability::ManagePenalties)];
    }

    /**
     * Grundrolle (level) und/oder Zusatzrollen (extra_roles, Issue #66) ändern. Beides wird erst vollständig geprüft,
     * dann geschrieben. Zusatzrollen gelten dieselben Regeln wie der Grundrolle: Wer kein Admin ist, vergibt und
     * entfernt nur Rollen, deren Rechte er selbst hat und die weder Konten anlegen noch Rollen vergeben dürfen, und nur
     * bei Personen, die nicht mehr Rechte haben als er (RoleService::canHandle/canHandleUser).
     *
     * @param mixed $extraRoles Liste von Schlüsseln; null = unverändert
     * @return array{id: int, level: string, extra_roles: list<string>}
     */
    public function setLevel(User $actor, int $id, mixed $level, mixed $extraRoles = null): array
    {
        if ($actor->id === $id) {
            throw ApiError::invalid('Die eigene Rolle kann man nicht selbst ändern.');
        }
        $target = $this->users->find($id);
        if (!$target) {
            throw ApiError::notFound('Benutzer nicht gefunden.');
        }
        $this->denyFormer($target);
        if ($level === null && $extraRoles === null) {
            throw ApiError::invalid('Bitte eine Rolle angeben.');
        }
        $new = $level === null ? $target->role : (is_string($level) ? $level : '');
        if ($level !== null) {
            $known = $this->roles !== null ? $this->roles->exists($new) : isset(Roles::TEMPLATES[$new]);
            if (!$known) {
                throw ApiError::invalid('Ungültige Rolle.');
            }
            if (($new === Roles::ADMIN || $target->isAdmin()) && !$this->users->canAssignAdmin()) {
                throw ApiError::invalid('Administratoren werden im WordPress-Backend verwaltet.');
            }
            // Keine Rechte weitergeben, die man selbst nicht hat (nur Admins vergeben jede Rolle).
            if ($this->roles !== null && (!$this->roles->canHandle($actor, $new) || !$this->roles->canHandleUser($actor, $target))) {
                throw ApiError::forbidden('Diese Rolle darfst du nicht vergeben bzw. ändern.');
            }
            if ($target->isAdmin() && $new !== Roles::ADMIN && $this->users->countAdmins() <= 1) {
                throw ApiError::invalid('Der letzte Admin kann nicht herabgestuft werden.');
            }
        }
        $extra = $this->roles !== null ? $this->roles->extraRolesOf($target) : [];
        $nextExtra = $extra;
        if ($extraRoles !== null) {
            $nextExtra = $this->checkExtraRoles($actor, $target, $new, $extraRoles, $extra);
        } elseif ($this->roles !== null && !$this->roles->allowsExtraRoles($new)) {
            // Neue Grundrolle ohne Mitgliederbereich (z. B. Anwärter, Gast) oder Admin: Zusatzrollen fallen weg.
            $nextExtra = [];
        }
        if ($level !== null) {
            try {
                $this->users->setRole($id, $new);
            } catch (\DomainException $e) {
                throw ApiError::invalid($e->getMessage());
            }
        }
        if ($this->roles !== null && $nextExtra !== $extra) {
            $this->roles->setExtraRoles($id, $nextExtra);
        }
        $this->roles?->roleChanged($id, $new);
        $after = $this->users->find($id);
        return ['id' => $id, 'level' => $after?->role ?? $new, 'extra_roles' => $after !== null && $this->roles !== null ? $this->roles->extraRolesOf($after) : []];
    }

    /**
     * Gewünschte Zusatzrollen prüfen (Issue #66): nur eigene Rollen, nur zu einer Grundrolle mit Mitgliederbereich, nicht
     * für Admins; wer kein Admin ist, nur mit Rollen, die er auch als Grundrolle vergeben dürfte (hinzufügen wie
     * entfernen). Die Grundrolle selbst als Zusatzrolle fällt still weg.
     *
     * @param list<string> $current wirksame Zusatzrollen bisher
     * @return list<string> künftige Zusatzrollen, in der Reihenfolge der Rollen
     */
    private function checkExtraRoles(User $actor, User $target, string $base, mixed $raw, array $current): array
    {
        if ($this->roles === null) {
            throw ApiError::invalid('Zusatzrollen gibt es hier nicht.');
        }
        if (!is_array($raw) || !array_is_list($raw)) {
            throw ApiError::invalid('Zusatzrollen als Liste angeben.');
        }
        $wanted = [];
        foreach ($raw as $key) {
            if (!is_string($key) || !$this->roles->exists($key)) {
                throw ApiError::invalid('Ungültige Zusatzrolle.');
            }
            if (!RoleService::canBeExtra($key)) {
                throw ApiError::invalid(sprintf('„%s“ ist nur als Grundrolle möglich, nicht als Zusatzrolle.', $this->roles->label($key)));
            }
            if ($key !== $base) {
                $wanted[$key] = true;
            }
        }
        if ($wanted !== [] && ($target->isAdmin() || $base === Roles::ADMIN)) {
            throw ApiError::invalid('Admins haben schon alle Rechte und bekommen keine Zusatzrollen.');
        }
        if ($wanted !== [] && !$this->roles->allowsExtraRoles($base)) {
            throw ApiError::invalid(sprintf('Zusatzrollen gibt es nur zu einer Grundrolle, die zur Kompanie gehört, nicht zu „%s“.', $this->roles->label($base)));
        }
        $changed = array_merge(array_diff(array_keys($wanted), $current), array_diff($current, array_keys($wanted)));
        if ($changed !== [] && !$this->roles->canHandleUser($actor, $target)) {
            throw ApiError::forbidden('Die Rollen dieser Person darfst du nicht ändern.');
        }
        foreach ($changed as $key) {
            if (!$this->roles->canHandle($actor, $key)) {
                throw ApiError::forbidden(sprintf('Die Rolle „%s“ darfst du nicht vergeben bzw. entfernen.', $this->roles->label($key)));
            }
        }
        return array_values(array_filter(array_keys($this->roles->all()), static fn (string $k): bool => isset($wanted[$k])));
    }

    /**
     * Zum Mitglied machen (Issue #23, z. B. Anwärter): wer Konten anlegen darf, aber nur aus einer Rolle, die der Admin
     * dafür freigegeben hat (RoleService::promotable). Andere Rollenwechsel bleiben bei „Rollen vergeben“.
     *
     * @return array{id: int, level: string}
     */
    public function promote(User $actor, int $id): array
    {
        $target = $this->users->find($id);
        if (!$target) {
            throw ApiError::notFound('Benutzer nicht gefunden.');
        }
        if ($actor->id === $id) {
            throw ApiError::invalid('Die eigene Rolle kann man nicht selbst ändern.');
        }
        $this->denyFormer($target);
        if (!($this->roles?->promotable($target->role) ?? false)) {
            throw ApiError::invalid(sprintf('Aus der Rolle „%s“ lässt sich niemand so zum Mitglied machen.', $this->roles?->label($target->role) ?? $target->role));
        }
        try {
            $this->users->setRole($id, Roles::MEMBER);
        } catch (\DomainException $e) {
            throw ApiError::invalid($e->getMessage());
        }
        $this->roles?->roleChanged($id, Roles::MEMBER);
        return ['id' => $id, 'level' => $this->users->find($id)?->role ?? Roles::MEMBER];
    }

    /**
     * Legt ein Konto mit zufälligem Startpasswort an, als Mitglied oder mit einer Rolle für neue Konten (z. B. Anwärter).
     * Das Passwort steht nur in dieser Antwort; es wird keine E-Mail verschickt.
     *
     * @param array<string, mixed> $in first_name, last_name (oder name), username, email, level (Rolle)
     * @param User|null $actor wer anlegt (null: intern, z. B. Einrichtung)
     * @return array<string, mixed>
     */
    public function create(array $in, ?User $actor = null): array
    {
        $taken = [];
        return $this->insert($this->prepare($in, $taken, $actor));
    }

    /**
     * Angaben eines neuen Kontos prüfen; $taken sammelt Benutzernamen und E-Mail-Adressen, die schon für andere
     * Einträge derselben Anfrage vorgesehen sind (mehrere anlegen).
     *
     * Beim Anlegen lassen sich alle persönlichen Angaben eintragen (phone, address), auch ohne view_personal: Eingeben ist
     * nicht Sehen, danach zeigt sie nur noch view_personal.
     *
     * @param array<string, mixed> $in first_name, last_name (oder name), username, email, phone, address, level
     * @param array<string, true> $taken
     * @param User|null $actor wer anlegt (null: intern); bestimmt, welche Rollen neue Konten bekommen dürfen
     * @return array{name: string, first_name: string, last_name: string, login: string, email: string, level: string, phone: string, address: array<string, string>|null}
     */
    public function prepare(array $in, array &$taken, ?User $actor = null): array
    {
        [$first, $last] = self::names($in, ['', '']);
        $name = self::checkNames($first, $last);
        $level = $in['level'] ?? Roles::MEMBER;
        if ($level !== Roles::MEMBER) {
            $role = is_string($level) ? $this->roles?->find($level) : null;
            if ($role === null || !$role['new_accounts']) {
                throw ApiError::invalid('Diese Rolle lässt sich beim Anlegen nicht wählen; andere Rollen danach vergeben.');
            }
            if ($actor !== null && !$this->roles?->canCreateAs($actor, $level)) {
                throw ApiError::forbidden(sprintf('Konten mit der Rolle „%s“ darfst du nicht anlegen.', $role['label']));
            }
        }

        $login = trim(is_string($in['username'] ?? null) ? $in['username'] : '');
        $isTaken = fn (string $l): bool => isset($taken['login:' . mb_strtolower($l)]) || $this->users->loginExists($l);
        if ($login === '') {
            $base = Passwords::loginBase($name);
            $login = $base;
            for ($i = 2; $isTaken($login); $i++) {
                $login = $base . $i;
            }
        } else {
            if (!Passwords::validLogin($login)) {
                throw ApiError::invalid('Der Benutzername darf nur Buchstaben (a–z), Ziffern sowie . _ - @ enthalten.');
            }
            if ($isTaken($login)) {
                throw ApiError::invalid('Dieser Benutzername ist schon vergeben.');
            }
        }

        $email = trim(is_string($in['email'] ?? null) ? $in['email'] : '');
        if ($email !== '') {
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > self::EMAIL_MAX) {
                throw ApiError::invalid('Die E-Mail-Adresse ist ungültig.');
            }
            if (isset($taken['email:' . mb_strtolower($email)]) || $this->users->emailExists($email)) {
                throw ApiError::invalid('Diese E-Mail-Adresse wird schon verwendet.');
            }
            $taken['email:' . mb_strtolower($email)] = true;
        }
        $phone = self::phoneInput($in['phone'] ?? null);
        $address = Address::parse($in['address'] ?? null);
        $taken['login:' . mb_strtolower($login)] = true;
        return ['name' => $name, 'first_name' => $first, 'last_name' => $last, 'login' => $login, 'email' => $email, 'level' => (string) $level, 'phone' => $phone, 'address' => $address];
    }

    /**
     * Vor- und Nachname aus der Anfrage: first_name/last_name, sonst name (ein Feld wie früher, z. B. eine noch nicht
     * aktualisierte App; das letzte Wort ist der Nachname). Was fehlt, kommt aus $current.
     *
     * @param array<string, mixed> $in
     * @param array{0: string, 1: string} $current Vor- und Nachname bisher
     * @return array{0: string, 1: string}
     */
    private static function names(array $in, array $current): array
    {
        if (array_key_exists('first_name', $in) || array_key_exists('last_name', $in)) {
            return [
                array_key_exists('first_name', $in) ? Text::line($in['first_name'], PersonName::MAX) : $current[0],
                array_key_exists('last_name', $in) ? Text::line($in['last_name'], PersonName::MAX) : $current[1],
            ];
        }
        return array_key_exists('name', $in) ? PersonName::split(Text::line($in['name'], PersonName::MAX)) : $current;
    }

    /** Vorname ist Pflicht, der Nachname nicht. Gibt den Anzeigenamen zurück. */
    private static function checkNames(string $first, string $last): string
    {
        if ($first === '') {
            throw ApiError::invalid('Bitte einen Vornamen angeben.');
        }
        $name = PersonName::join($first, $last);
        if (mb_strlen($name) > PersonName::MAX) {
            throw ApiError::invalid(sprintf('Vor- und Nachname sind zusammen zu lang (höchstens %d Zeichen).', PersonName::MAX));
        }
        return $name;
    }

    /**
     * Konto mit zufälligem Startpasswort anlegen (geprüft mit prepare()), danach Handynummer und Adresse. Scheitern die,
     * ist das Konto trotzdem angelegt; dann steht ein Hinweis in warning.
     *
     * @param array{name: string, first_name: string, last_name: string, login: string, email: string, level: string, phone?: string, address?: array<string, string>|null} $p
     * @return array<string, mixed>
     */
    public function insert(array $p): array
    {
        ['name' => $name, 'first_name' => $first, 'last_name' => $last, 'login' => $login, 'email' => $email, 'level' => $level] = $p;
        $password = Passwords::readable(12);
        try {
            $id = $this->users->create($first, $last, $login, $email, $password, $level);
        } catch (\DomainException $e) {
            throw ApiError::invalid($e->getMessage());
        }
        $out = ['id' => $id, 'name' => $name, 'first_name' => $first, 'last_name' => $last, 'username' => $login, 'email' => $email, 'level' => $level, 'password' => $password];
        $phone = $p['phone'] ?? '';
        $address = $p['address'] ?? null;
        if ($phone !== '' || $address !== null) {
            try {
                $this->storeContacts($id, $phone !== '' ? $phone : null, $address ?? false);
            } catch (\Throwable $e) {
                error_log('Kompanie-App: Handynummer bzw. Adresse nicht übernommen: ' . $e->getMessage());
                $out['warning'] = 'Angelegt, aber Handynummer bzw. Adresse fehlen noch. Bitte in den Mitgliedsdetails nachtragen.';
            }
        }
        return $out;
    }

    /** Neues Startpasswort für ein Mitglied; meldet alle seine Geräte ab. */
    public function resetPassword(User $actor, int $id): array
    {
        if ($actor->id === $id) {
            throw ApiError::invalid('Das eigene Passwort änderst du unter Meins → Konto → Passwort ändern.');
        }
        $target = $this->users->find($id);
        if (!$target) {
            throw ApiError::notFound('Mitglied nicht gefunden.');
        }
        $this->guardAccount($actor, $target, 'Das Passwort dieser Person darfst du nicht zurücksetzen.');
        if ($this->formerRole($target) !== null) {
            throw ApiError::invalid('Ehemalige können sich nicht anmelden. Wer wieder aufgenommen wird, bekommt hier ein neues Startpasswort.');
        }
        $password = Passwords::readable(12);
        try {
            $this->users->setPassword($id, $password);
        } catch (\DomainException $e) {
            throw ApiError::forbidden($e->getMessage());
        }
        return ['password' => $password];
    }

    /**
     * Wer Konten verwalten darf (Passwort zurücksetzen, Angaben ändern, löschen): Admins alle; sonst nur Konten mit
     * Mitgliederbereich bzw. nur Kalender und ohne mehr Rechte als man selbst. Sonst könnte, wer Konten anlegen darf,
     * das Konto einer Person mit mehr Rechten übernehmen, oder (bei WordPress) das Konto einer Person, die gar nicht zur
     * Kompanie gehört (Gast). Personen nur mit dem Kalender (z. B. Anwärter) legt an, wer Konten anlegen darf; ihr Konto
     * darf er deshalb auch verwalten.
     * Gilt auch für das Foto (MemberPhotoService). Ehemalige (Rolle Gast) gelten mit ihrer Grundrolle von vorher: Wer sie
     * als ehemalig eingetragen durfte, darf ihr Konto weiter verwalten.
     */
    public function guardAccount(User $actor, User $target, string $message): void
    {
        $previous = $this->formerRole($target);
        if ($previous !== null) {
            if (!$actor->isAdmin() && ($previous === Roles::ADMIN || $this->roles === null || !$this->roles->canHandle($actor, $previous))) {
                throw ApiError::forbidden($previous === Roles::ADMIN ? 'Konten von Admins verwalten nur Admins.' : $message);
            }
            return;
        }
        if ($target->isAdmin() && !$actor->isAdmin()) {
            throw ApiError::forbidden('Konten von Admins verwalten nur Admins.');
        }
        if (!$actor->isAdmin() && !$target->isMember() && !self::calendarOnly($target)) {
            throw ApiError::forbidden('Konten von Personen, die nicht zur Kompanie gehören, verwalten nur Admins.');
        }
        if ($this->roles !== null && !$this->roles->canHandleUser($actor, $target)) {
            throw ApiError::forbidden($message);
        }
    }

    /**
     * Angaben eines Kontos zum Bearbeiten (für wer Konten anlegt): Name, Benutzername, E-Mail-Adresse. Die Handynummer
     * nur für die Person selbst, intern (ohne $actor) und wer persönliche Angaben sieht (view_personal); sonst ''.
     *
     * @return array{name: string, first_name: string, last_name: string, login: string, email: string, phone: string, can_change_login: bool}
     */
    public function profile(int $id, ?User $actor = null): array
    {
        $target = $this->users->find($id) ?? throw ApiError::notFound('Mitglied nicht gefunden.');
        if ($actor !== null && $actor->id !== $id) {
            $this->guardAccount($actor, $target, 'Die Angaben dieser Person darfst du nicht sehen.');
        }
        $p = $this->users->profile($id) ?? throw ApiError::notFound('Mitglied nicht gefunden.');
        $phone = $actor === null || $actor->id === $id || $actor->can(Capability::ViewPersonal) ? ($this->contacts?->phone($id) ?? '') : '';
        return $p + ['phone' => $phone, 'can_change_login' => $this->users->canChangeLogin()];
    }

    /**
     * Persönliche Angaben einer Person (view_personal): E-Mail-Adresse, Handynummer, Adresse. Für alle Konten, ohne die
     * Grenzen von guardAccount (auch die Adresse eines Admins pflegt etwa eine Schriftführerin).
     *
     * @return array{email: string, phone: string, address: array<string, string>|null}
     */
    public function personal(int $id): array
    {
        $p = $this->users->profile($id) ?? throw ApiError::notFound('Mitglied nicht gefunden.');
        $c = $this->contacts?->contact($id) ?? ['phone' => '', 'address' => null];
        return ['email' => $p['email'], 'phone' => $c['phone'], 'address' => $c['address']];
    }

    /**
     * Persönliche Angaben einer Person ändern (manage_personal): email, phone, address; was fehlt, bleibt. Eine andere
     * E-Mail-Adresse nur mit den Grenzen von guardAccount, denn über sie lässt sich (bei WordPress) das Passwort
     * zurücksetzen; die eigene nur mit current_password (wie updateProfile).
     *
     * @param array<string, mixed> $in
     * @return array{email: string, phone: string, address: array<string, string>|null}
     */
    public function updatePersonal(User $actor, int $id, array $in): array
    {
        $target = $this->users->find($id) ?? throw ApiError::notFound('Mitglied nicht gefunden.');
        $current = $this->users->profile($id) ?? throw ApiError::notFound('Mitglied nicht gefunden.');
        $phone = array_key_exists('phone', $in) ? self::phoneInput($in['phone']) : null;
        $address = array_key_exists('address', $in) ? Address::parse($in['address']) : false;
        if (array_key_exists('email', $in)) {
            $email = trim(is_string($in['email']) ? $in['email'] : '');
            if (mb_strtolower($email) !== mb_strtolower($current['email'])) {
                if ($actor->id !== $id) {
                    $this->guardAccount($actor, $target, 'Die E-Mail-Adresse dieser Person darfst du nicht ändern.');
                }
                $this->updateProfile($actor, $id, ['email' => $email, 'current_password' => $in['current_password'] ?? null]);
            }
        }
        $this->storeContacts($id, $phone, $address);
        return $this->personal($id);
    }

    /**
     * Handynummer und Adresse speichern (geprüft mit phoneInput bzw. Address::parse).
     *
     * @param string|null $phone null: unverändert
     * @param array<string, string>|null|false $address false: unverändert, null: entfernen
     */
    public function storeContacts(int $id, ?string $phone, array|null|false $address): void
    {
        if ($this->contacts === null) {
            return;
        }
        $now = $this->time?->nowUtc() ?? gmdate('Y-m-d H:i:s');
        if ($phone !== null) {
            $this->contacts->setPhone($id, $phone, $now);
        }
        if ($address !== false) {
            $this->contacts->setAddress($id, $address, $now);
        }
    }

    /**
     * Vor- und Nachname, E-Mail und Benutzername ändern (Issue #24). Ein neuer Benutzername beendet alle Sitzungen des
     * Kontos; ändert jemand den eigenen, bleibt dieses Gerät angemeldet. Die eigene E-Mail-Adresse nur mit current_password.
     *
     * @param array<string, mixed> $in first_name, last_name (oder name), email, phone, username, current_password
     * @return array{name: string, first_name: string, last_name: string, login: string, email: string, phone: string, can_change_login: bool}
     */
    public function updateProfile(User $actor, int $id, array $in): array
    {
        $target = $this->users->find($id) ?? throw ApiError::notFound('Mitglied nicht gefunden.');
        if ($actor->id !== $id) {
            $this->guardAccount($actor, $target, 'Die Angaben dieser Person darfst du nicht ändern.');
        }
        $current = $this->users->profile($id) ?? throw ApiError::notFound('Mitglied nicht gefunden.');
        [$first, $last] = self::names($in, [$current['first_name'], $current['last_name']]);
        self::checkNames($first, $last);
        $email = array_key_exists('email', $in) ? trim(is_string($in['email']) ? $in['email'] : '') : $current['email'];
        $emailChanged = mb_strtolower($email) !== mb_strtolower($current['email']);
        // Die eigene E-Mail-Adresse nur mit dem aktuellen Passwort, auch in der Verwaltung (siehe updateOwnAccount).
        if ($emailChanged && $actor->id === $id) {
            $this->checkPassword($actor, $in['current_password'] ?? null);
        }
        // Nur Groß-/Kleinschreibung geändert: nicht gegen sich selbst als „schon verwendet“ prüfen.
        if ($emailChanged && $email !== '') {
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > self::EMAIL_MAX) {
                throw ApiError::invalid('Die E-Mail-Adresse ist ungültig.');
            }
            if ($this->users->emailExists($email)) {
                throw ApiError::invalid('Diese E-Mail-Adresse wird schon verwendet.');
            }
        }
        $phone = array_key_exists('phone', $in) ? self::phoneInput($in['phone']) : null;
        if ($phone !== null && $actor->id !== $id && !$actor->can(Capability::ManagePersonal)) {
            throw ApiError::forbidden('Die Handynummer anderer ändert nur, wer persönliche Angaben pflegen darf.');
        }
        $login = null;
        if (array_key_exists('username', $in)) {
            $wanted = trim(is_string($in['username']) ? $in['username'] : '');
            if ($wanted !== $current['login']) {
                if (!$this->users->canChangeLogin()) {
                    throw ApiError::invalid('Benutzernamen lassen sich hier nicht ändern.');
                }
                if (!Passwords::validLogin($wanted)) {
                    throw ApiError::invalid('Der Benutzername darf nur Buchstaben (a–z), Ziffern sowie . _ - @ enthalten.');
                }
                if (mb_strtolower($wanted) !== mb_strtolower($current['login']) && $this->users->loginExists($wanted)) {
                    throw ApiError::invalid('Dieser Benutzername ist schon vergeben.');
                }
                $login = $wanted;
            }
        }
        try {
            $this->users->updateProfile($id, $first, $last, $email, $login);
        } catch (\DomainException $e) {
            throw ApiError::forbidden($e->getMessage());
        }
        $this->storeContacts($id, $phone, false);
        if ($login !== null && $actor->id === $id) {
            $this->auth->afterPasswordChange($id);
        }
        return $this->profile($id, $actor);
    }

    /**
     * Eigene E-Mail-Adresse, Handynummer und Adresse (Meins → Konto).
     *
     * @return array{email: string, phone: string, address: array<string, string>|null}
     */
    public function ownAccount(User $user): array
    {
        return $this->personal($user->id);
    }

    /**
     * Eigene E-Mail-Adresse, Handynummer und Adresse ändern. Eine neue E-Mail-Adresse nur mit dem aktuellen Passwort:
     * Über sie lässt sich (bei WordPress) das Passwort zurücksetzen, eine fremde Sitzung soll so nicht das Konto
     * übernehmen. Die Adresse ohne Passwort.
     *
     * @param array<string, mixed> $in email, phone, address, current_password
     * @return array{email: string, phone: string, address: array<string, string>|null}
     */
    public function updateOwnAccount(User $user, array $in): array
    {
        // Erst alles prüfen, dann schreiben; das Passwort prüft updateProfile. Name und Benutzername ändert hier niemand.
        $address = array_key_exists('address', $in) ? Address::parse($in['address']) : false;
        $this->updateProfile($user, $user->id, array_intersect_key($in, ['email' => true, 'phone' => true, 'current_password' => true]));
        $this->storeContacts($user->id, null, $address);
        return $this->ownAccount($user);
    }

    /** Handynummer prüfen und vereinheitlichen; leer heißt „keine“. */
    public static function phoneInput(mixed $raw): string
    {
        $phone = Text::line(is_string($raw) ? $raw : '');
        if ($phone === '') {
            return '';
        }
        $digits = strlen((string) preg_replace('/\D/', '', $phone));
        if (mb_strlen($phone) > self::PHONE_MAX || preg_match('#^\+?[0-9 ()/-]+$#', $phone) !== 1 || $digits < 5 || $digits > 20) {
            throw ApiError::invalid('Die Handynummer ist ungültig. Erlaubt sind Ziffern, Leerzeichen und + ( ) / -, z. B. +49 171 1234567.');
        }
        return $phone;
    }

    /**
     * Konto aus der App entfernen (Issue #24): Standalone wird es gelöscht, bei WordPress verliert es seine Rolle in der
     * App. Die eigenen Daten löscht danach App::userDeleted().
     */
    public function removeAccount(User $actor, int $id): void
    {
        if ($actor->id === $id) {
            throw ApiError::invalid('Das eigene Konto kannst du hier nicht löschen.');
        }
        $target = $this->users->find($id) ?? throw ApiError::notFound('Mitglied nicht gefunden.');
        $this->guardAccount($actor, $target, 'Dieses Konto darfst du nicht löschen.');
        if ($target->isAdmin() && $this->users->countAdmins() <= 1) {
            throw ApiError::invalid('Der letzte Admin kann nicht gelöscht werden.');
        }
        try {
            $this->users->removeAccount($id);
        } catch (\DomainException $e) {
            throw ApiError::forbidden($e->getMessage());
        }
    }

    public function changeOwnPassword(User $user, mixed $current, mixed $new): void
    {
        $current = is_string($current) ? $current : '';
        $new = is_string($new) ? $new : '';
        $this->checkPassword($user, $current);
        if (mb_strlen($new) < Passwords::MIN_LENGTH) {
            throw ApiError::invalid(sprintf('Das neue Passwort muss mindestens %d Zeichen lang sein.', Passwords::MIN_LENGTH));
        }
        if ($new !== trim($new)) {
            throw ApiError::invalid('Das neue Passwort darf nicht mit Leerzeichen beginnen oder enden.');
        }
        if ($new === $current) {
            throw ApiError::invalid('Das neue Passwort muss sich vom aktuellen unterscheiden.');
        }
        try {
            $this->users->setPassword($user->id, $new);
        } catch (\DomainException $e) {
            throw ApiError::forbidden($e->getMessage());
        }
        $this->auth->afterPasswordChange($user->id);
    }

    /**
     * Aktuelles Passwort prüfen (Passwort oder E-Mail-Adresse ändern). Wer nur eine fremde Sitzung hat (z. B. ein nicht
     * abgemeldetes Gerät), soll das Passwort nicht unbegrenzt raten und so das Konto übernehmen können. Jeder Versuch
     * wird vor der Prüfung atomar gezählt (auch bei gleichzeitigen Anfragen).
     */
    private function checkPassword(User $user, mixed $current): void
    {
        $current = is_string($current) ? $current : '';
        if ($this->cache !== null && $this->cache->increment('pwchange_' . $user->id, 900) > self::PASSWORD_ATTEMPTS) {
            throw new ApiError(429, 'too_many_attempts', 'Zu viele Versuche. Bitte in 15 Minuten erneut versuchen.');
        }
        if ($current === '' || !$this->users->verifyPassword($user->id, $current)) {
            throw ApiError::invalid('Das aktuelle Passwort ist falsch.');
        }
    }
}
