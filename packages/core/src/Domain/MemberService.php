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
                'level' => $u->role,
                // Mit Mitgliederbereich (Punkte und Strafen möglich); sonst nur Kalender oder Gast.
                'member' => $u->isMember(),
                'points' => $points ? ($totals[$u->id]['points'] ?? 0) : null,
                'open_cents' => $penalties ? ($totals[$u->id]['open_cents'] ?? 0) : null,
            ];
        }
        return $out;
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
            'level' => $u->role,
            'member' => $u->isMember(),
            'points' => $points ? $this->scores->pointsTotal($u->id) : null,
            'open_cents' => $penalties ? $this->scores->openCents($u->id) : null,
            'point_list' => $points ? $this->scores->pointsFor($u->id) : [],
            'penalties' => $penalties ? $this->scores->penaltiesFor($u->id) : [],
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

    /** @return array{id: int, level: string} */
    public function setLevel(User $actor, int $id, mixed $level): array
    {
        if ($actor->id === $id) {
            throw ApiError::invalid('Die eigene Rolle kann man nicht selbst ändern.');
        }
        $target = $this->users->find($id);
        if (!$target) {
            throw ApiError::notFound('Benutzer nicht gefunden.');
        }
        $new = is_string($level) ? $level : '';
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
        try {
            $this->users->setRole($id, $new);
        } catch (\DomainException $e) {
            throw ApiError::invalid($e->getMessage());
        }
        $after = $this->users->find($id);
        return ['id' => $id, 'level' => $after?->role ?? $new];
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
        return ['id' => $id, 'level' => $this->users->find($id)?->role ?? Roles::MEMBER];
    }

    /**
     * Legt ein Konto mit zufälligem Startpasswort an, als Mitglied oder als Anwärter. Das Passwort steht nur
     * in dieser Antwort; es wird keine E-Mail verschickt.
     *
     * @param array<string, mixed> $in name, username, email, level (member oder candidate)
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
     * @param array<string, mixed> $in name, username, email, level
     * @param array<string, true> $taken
     * @return array{name: string, login: string, email: string, level: string}
     */
    public function prepare(array $in, array &$taken): array
    {
        $name = Text::line($in['name'] ?? '', 100);
        if ($name === '') {
            throw ApiError::invalid('Bitte einen Namen angeben.');
        }
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
        return ['name' => $name, 'login' => $login, 'email' => $email, 'level' => (string) $level];
    }

    /**
     * Konto mit zufälligem Startpasswort anlegen (geprüft mit prepare()).
     *
     * @param array{name: string, login: string, email: string, level: string} $p
     * @return array<string, mixed>
     */
    public function insert(array $p): array
    {
        ['name' => $name, 'login' => $login, 'email' => $email, 'level' => $level] = $p;
        $password = Passwords::readable(12);
        try {
            $id = $this->users->create($name, $login, $email, $password, $level);
        } catch (\DomainException $e) {
            throw ApiError::invalid($e->getMessage());
        }
        return ['id' => $id, 'name' => $name, 'username' => $login, 'email' => $email, 'level' => $level, 'password' => $password];
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
     */
    private function guardAccount(User $actor, User $target, string $message): void
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
     * @return array{name: string, login: string, email: string, can_change_login: bool}
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
     * Name, E-Mail und Benutzername ändern (Issue #24). Ein neuer Benutzername beendet alle Sitzungen des Kontos; ändert
     * jemand den eigenen, bleibt dieses Gerät angemeldet.
     *
     * @param array<string, mixed> $in name, email, username
     * @return array{name: string, login: string, email: string, can_change_login: bool}
     */
    public function updateProfile(User $actor, int $id, array $in): array
    {
        $target = $this->users->find($id) ?? throw ApiError::notFound('Mitglied nicht gefunden.');
        if ($actor->id !== $id) {
            $this->guardAccount($actor, $target, 'Die Angaben dieser Person darfst du nicht ändern.');
        }
        $current = $this->users->profile($id) ?? throw ApiError::notFound('Mitglied nicht gefunden.');
        $name = array_key_exists('name', $in) ? Text::line($in['name'] ?? '', 100) : $current['name'];
        if ($name === '') {
            throw ApiError::invalid('Bitte einen Namen angeben.');
        }
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
            $this->users->updateProfile($id, $name, $email, $login);
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
