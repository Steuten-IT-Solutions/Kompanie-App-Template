<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\Auth\Level;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Port\Authenticator;
use KompanieApp\Core\Port\UserDirectory;

/** Mitglieder, Rollen und Passwörter. */
final class MemberService
{
    public function __construct(
        private readonly UserDirectory $users,
        private readonly ScoreService $scores,
        private readonly Authenticator $auth,
    ) {
    }

    /** @return array<string, mixed> */
    public function me(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'level' => $user->level->value,
            'points' => $this->scores->pointsTotal($user->id),
            'open_cents' => $this->scores->openCents($user->id),
        ];
    }

    /**
     * Mitglieder mit Summen; mit $includeGuests auch Konten ohne Rolle.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(bool $includeGuests): array
    {
        $totals = $this->scores->totalsByUser();
        $out = [];
        foreach ($this->users->list($includeGuests) as $u) {
            if (!$includeGuests && !$u->isMember()) {
                continue;
            }
            $out[] = [
                'id' => $u->id,
                'name' => $u->name,
                'level' => $u->level->value,
                'points' => $totals[$u->id]['points'] ?? 0,
                'open_cents' => $totals[$u->id]['open_cents'] ?? 0,
            ];
        }
        return $out;
    }

    /** @return array<string, mixed> */
    public function detail(int $id): array
    {
        $u = $this->users->find($id);
        if (!$u) {
            throw ApiError::notFound('Mitglied nicht gefunden.');
        }
        return [
            'id' => $u->id,
            'name' => $u->name,
            'level' => $u->level->value,
            'points' => $this->scores->pointsTotal($u->id),
            'open_cents' => $this->scores->openCents($u->id),
            'point_list' => $this->scores->pointsFor($u->id),
            'penalties' => $this->scores->penaltiesFor($u->id),
        ];
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
        $new = is_string($level) ? Level::tryFrom($level) : null;
        if ($new === null) {
            throw ApiError::invalid('Ungültige Rolle.');
        }
        if (($new === Level::Admin || $target->level === Level::Admin) && !$this->users->canAssignAdmin()) {
            throw ApiError::invalid('Administratoren werden im WordPress-Backend verwaltet.');
        }
        if ($target->level === Level::Admin && $new !== Level::Admin && $this->users->countAdmins() <= 1) {
            throw ApiError::invalid('Der letzte Admin kann nicht herabgestuft werden.');
        }
        try {
            $this->users->setLevel($id, $new);
        } catch (\DomainException $e) {
            throw ApiError::invalid($e->getMessage());
        }
        $after = $this->users->find($id);
        return ['id' => $id, 'level' => ($after?->level ?? $new)->value];
    }

    /**
     * Legt ein Mitglied mit zufälligem Startpasswort an. Das Passwort steht nur
     * in dieser Antwort; es wird keine E-Mail verschickt.
     *
     * @param array<string, mixed> $in name, username, email
     * @return array<string, mixed>
     */
    public function create(array $in): array
    {
        $name = Text::line($in['name'] ?? '', 100);
        if ($name === '') {
            throw ApiError::invalid('Bitte einen Namen angeben.');
        }

        $login = trim(is_string($in['username'] ?? null) ? $in['username'] : '');
        if ($login === '') {
            $base = Passwords::loginBase($name);
            $login = $base;
            for ($i = 2; $this->users->loginExists($login); $i++) {
                $login = $base . $i;
            }
        } else {
            if (!Passwords::validLogin($login)) {
                throw ApiError::invalid('Der Benutzername darf nur Buchstaben (a–z), Ziffern sowie . _ - @ enthalten.');
            }
            if ($this->users->loginExists($login)) {
                throw ApiError::invalid('Dieser Benutzername ist schon vergeben.');
            }
        }

        $email = trim(is_string($in['email'] ?? null) ? $in['email'] : '');
        if ($email !== '') {
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw ApiError::invalid('Die E-Mail-Adresse ist ungültig.');
            }
            if ($this->users->emailExists($email)) {
                throw ApiError::invalid('Diese E-Mail-Adresse wird schon verwendet.');
            }
        }

        $password = Passwords::readable(12);
        try {
            $id = $this->users->create($name, $login, $email, $password);
        } catch (\DomainException $e) {
            throw ApiError::invalid($e->getMessage());
        }
        return ['id' => $id, 'name' => $name, 'username' => $login, 'email' => $email, 'password' => $password];
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
        if ($target->level === Level::Admin && $actor->level !== Level::Admin) {
            throw ApiError::forbidden('Nur Admins dürfen Passwörter von Admins zurücksetzen.');
        }
        $password = Passwords::readable(12);
        try {
            $this->users->setPassword($id, $password);
        } catch (\DomainException $e) {
            throw ApiError::forbidden($e->getMessage());
        }
        return ['password' => $password];
    }

    public function changeOwnPassword(User $user, mixed $current, mixed $new): void
    {
        $current = is_string($current) ? $current : '';
        $new = is_string($new) ? $new : '';
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
