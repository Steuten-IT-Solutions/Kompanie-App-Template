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

/** Mitglieder, Rollen und Passwörter. */
final class MemberService
{
    /** WordPress speichert höchstens 100 Zeichen (user_email); vorab prüfen, damit Mehrere-Anlegen nicht mittendrin scheitert. */
    public const EMAIL_MAX = 100;

    public function __construct(
        private readonly UserDirectory $users,
        private readonly ScoreService $scores,
        private readonly Authenticator $auth,
        private readonly ?Cache $cache = null,
        private readonly ?RoleService $roles = null,
    ) {
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
     * Kalender, ohne Gäste und ohne Punkte, Strafen oder Rollen. Foto, Dienstgrad und Titel ergänzt die API.
     *
     * @return list<array{id: int, name: string, first_name: string, last_name: string}>
     */
    public function directory(): array
    {
        $out = [];
        foreach ($this->users->list(false) as $u) {
            if (self::inDirectory($u)) {
                $out[] = ['id' => $u->id, 'name' => $u->name, 'first_name' => $u->firstName, 'last_name' => $u->lastName];
            }
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
            throw ApiError::invalid(sprintf('Zusatzrollen gibt es nur zu einer Grundrolle mit Mitgliederbereich, nicht zu „%s“.', $this->roles->label($base)));
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
     * Anwärter zum Mitglied machen (Issue #23): wer Konten anlegen darf, aber nur von Anwärter zu Mitglied.
     * Andere Rollenwechsel bleiben bei „Rollen vergeben“.
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
        if ($target->role !== Roles::CANDIDATE) {
            throw ApiError::invalid('Nur Anwärter können so zum Mitglied gemacht werden.');
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
     * Legt ein Konto mit zufälligem Startpasswort an, als Mitglied oder als Anwärter. Das Passwort steht nur
     * in dieser Antwort; es wird keine E-Mail verschickt.
     *
     * @param array<string, mixed> $in first_name, last_name (oder name), username, email, level (member oder candidate)
     * @return array<string, mixed>
     */
    public function create(array $in): array
    {
        $taken = [];
        return $this->insert($this->prepare($in, $taken));
    }

    /**
     * Angaben eines neuen Kontos prüfen; $taken sammelt Benutzernamen und E-Mail-Adressen, die schon für andere
     * Einträge derselben Anfrage vorgesehen sind (mehrere anlegen).
     *
     * @param array<string, mixed> $in first_name, last_name (oder name), username, email, level
     * @param array<string, true> $taken
     * @return array{name: string, first_name: string, last_name: string, login: string, email: string, level: string}
     */
    public function prepare(array $in, array &$taken): array
    {
        [$first, $last] = self::names($in, ['', '']);
        $name = self::checkNames($first, $last);
        $level = $in['level'] ?? Roles::MEMBER;
        if ($level !== Roles::MEMBER && $level !== Roles::CANDIDATE) {
            throw ApiError::invalid('Neue Konten sind Mitglieder oder Anwärter; andere Rollen danach vergeben.');
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
        $taken['login:' . mb_strtolower($login)] = true;
        return ['name' => $name, 'first_name' => $first, 'last_name' => $last, 'login' => $login, 'email' => $email, 'level' => (string) $level];
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
     * Konto mit zufälligem Startpasswort anlegen (geprüft mit prepare()).
     *
     * @param array{name: string, first_name: string, last_name: string, login: string, email: string, level: string} $p
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
        return ['id' => $id, 'name' => $name, 'first_name' => $first, 'last_name' => $last, 'username' => $login, 'email' => $email, 'level' => $level, 'password' => $password];
    }

    /** Neues Startpasswort für ein Mitglied; meldet alle seine Geräte ab. */
    public function resetPassword(User $actor, int $id): array
    {
        if ($actor->id === $id) {
            throw ApiError::invalid('Das eigene Passwort änderst du unter Konto → Passwort ändern.');
        }
        $target = $this->users->find($id);
        if (!$target) {
            throw ApiError::notFound('Mitglied nicht gefunden.');
        }
        $this->guardAccount($actor, $target, 'Das Passwort dieser Person darfst du nicht zurücksetzen.');
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
     * Kompanie gehört (Gast). Anwärter legt an, wer Konten anlegen darf; ihr Konto darf er deshalb auch verwalten.
     * Gilt auch für das Foto (MemberPhotoService).
     */
    public function guardAccount(User $actor, User $target, string $message): void
    {
        if ($target->isAdmin() && !$actor->isAdmin()) {
            throw ApiError::forbidden('Konten von Admins verwalten nur Admins.');
        }
        if (!$actor->isAdmin() && !$target->isMember() && !self::calendarOnly($target)) {
            throw ApiError::forbidden('Konten von Personen ohne Mitgliederbereich verwalten nur Admins.');
        }
        if ($this->roles !== null && !$this->roles->canHandleUser($actor, $target)) {
            throw ApiError::forbidden($message);
        }
    }

    /**
     * Angaben eines Kontos zum Bearbeiten (für wer Konten anlegt).
     *
     * @return array{name: string, first_name: string, last_name: string, login: string, email: string, can_change_login: bool}
     */
    public function profile(int $id, ?User $actor = null): array
    {
        $target = $this->users->find($id) ?? throw ApiError::notFound('Mitglied nicht gefunden.');
        if ($actor !== null && $actor->id !== $id) {
            $this->guardAccount($actor, $target, 'Die Angaben dieser Person darfst du nicht sehen.');
        }
        $p = $this->users->profile($id) ?? throw ApiError::notFound('Mitglied nicht gefunden.');
        return $p + ['can_change_login' => $this->users->canChangeLogin()];
    }

    /**
     * Vor- und Nachname, E-Mail und Benutzername ändern (Issue #24). Ein neuer Benutzername beendet alle Sitzungen des
     * Kontos; ändert jemand den eigenen, bleibt dieses Gerät angemeldet.
     *
     * @param array<string, mixed> $in first_name, last_name (oder name), email, username
     * @return array{name: string, first_name: string, last_name: string, login: string, email: string, can_change_login: bool}
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
        // Nur Groß-/Kleinschreibung geändert: nicht gegen sich selbst als „schon verwendet“ prüfen.
        if (mb_strtolower($email) !== mb_strtolower($current['email']) && $email !== '') {
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > self::EMAIL_MAX) {
                throw ApiError::invalid('Die E-Mail-Adresse ist ungültig.');
            }
            if ($this->users->emailExists($email)) {
                throw ApiError::invalid('Diese E-Mail-Adresse wird schon verwendet.');
            }
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
        if ($login !== null && $actor->id === $id) {
            $this->auth->afterPasswordChange($id);
        }
        return $this->profile($id);
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
        // Wer nur eine fremde Sitzung hat (z. B. ein nicht abgemeldetes Gerät), soll das Passwort
        // nicht unbegrenzt raten und so das Konto übernehmen können.
        // Jeder Versuch wird vor der Prüfung atomar gezählt (auch bei gleichzeitigen Anfragen).
        if ($this->cache !== null && $this->cache->increment('pwchange_' . $user->id, 900) > self::PASSWORD_ATTEMPTS) {
            throw new ApiError(429, 'too_many_attempts', 'Zu viele Versuche. Bitte in 15 Minuten erneut versuchen.');
        }
        if ($current === '' || !$this->users->verifyPassword($user->id, $current)) {
            throw ApiError::invalid('Das aktuelle Passwort ist falsch.');
        }
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
}
