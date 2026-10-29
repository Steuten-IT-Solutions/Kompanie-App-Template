<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests\Support;

use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Domain\PersonName;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Port\AuthContext;
use KompanieApp\Core\Port\Authenticator;
use KompanieApp\Core\Port\UserDirectory;

/**
 * Benutzerverwaltung im Speicher, zugleich Anmeldung und aktueller Benutzer.
 * Rollenmodell wie in der eigenständigen Version (Admins in der App ernennbar).
 */
final class FakeUsers implements UserDirectory, AuthContext, Authenticator
{
    /** @var array<int, array{name: string, first?: string, last?: string, login: string, email: string, level: string, password: string, disabled?: bool}> Ohne first/last: aus name geteilt (wie Konten von früher); disabled: stillgelegt (deactivate) */
    public array $users = [];
    public ?int $current = null;
    public int $passwordChanges = 0;
    /** Zahl der Einzelabfragen (find) und Sammelabfragen (findMany, list): wie Datenbankabfragen der Adapter. */
    public int $lookups = 0;
    private int $next = 1;

    /** @var (\Closure(string, int): array<int, \KompanieApp\Core\Auth\Capability>)|null Rechte je Grundrolle und ID (setzt TestApp: die der App) */
    public ?\Closure $capabilities = null;

    public function __construct(public bool $adminsAssignable = true)
    {
    }

    public function add(string $login, string $level, string $password = 'geheim123'): int
    {
        $id = $this->next++;
        $this->users[$id] = ['name' => ucfirst($login), 'login' => $login, 'email' => '', 'level' => $level, 'password' => password_hash($password, PASSWORD_DEFAULT)];
        return $id;
    }

    public function find(int $id): ?User
    {
        $this->lookups++;
        return $this->user($id);
    }

    public function findMany(array $ids): array
    {
        $this->lookups++;
        $out = [];
        foreach ($ids as $id) {
            $u = $this->user((int) $id);
            if ($u !== null) {
                $out[$u->id] = $u;
            }
        }
        return $out;
    }

    public function emails(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            if (isset($this->users[(int) $id])) {
                $out[(int) $id] = $this->users[(int) $id]['email'];
            }
        }
        return $out;
    }

    private function user(int $id): ?User
    {
        $u = $this->users[$id] ?? null;
        $caps = $u === null ? [] : ($this->capabilities !== null ? ($this->capabilities)($u['level'], $id) : Roles::templateCapabilities($u['level']));
        if ($u === null) {
            return null;
        }
        [$first, $last] = $this->names($id);
        return new User($id, $u['name'], $u['login'], $u['level'], $caps, $first, $last);
    }

    /** @return array{0: string, 1: string} */
    private function names(int $id): array
    {
        $u = $this->users[$id];
        return isset($u['first'], $u['last']) ? [$u['first'], $u['last']] : PersonName::split($u['name']);
    }

    /** Wie WordPress: list(false) liefert auch Konten mit gespeicherter Rolle „guest“ (der Kern muss selbst prüfen). */
    public bool $listGuests = false;

    public function list(bool $includeGuests): array
    {
        $this->lookups++;
        $out = [];
        foreach (array_keys($this->users) as $id) {
            $u = $this->user($id);
            if ($includeGuests || $this->listGuests || $u->role !== Roles::GUEST) {
                $out[] = $u;
            }
        }
        usort($out, static fn (User $a, User $b): int => strcmp($a->name, $b->name));
        return $out;
    }

    public function create(string $firstName, string $lastName, string $login, string $email, string $password, string $role = Roles::MEMBER): int
    {
        $id = $this->add($login, $role, $password);
        $this->users[$id]['name'] = PersonName::join($firstName, $lastName);
        $this->users[$id]['first'] = $firstName;
        $this->users[$id]['last'] = $lastName;
        $this->users[$id]['email'] = $email;
        return $id;
    }

    public function canAssignAdmin(): bool
    {
        return $this->adminsAssignable;
    }

    public function setRole(int $id, string $role): void
    {
        $this->users[$id]['level'] = $role;
    }

    public function countAdmins(): int
    {
        return count(array_filter($this->users, static fn (array $u): bool => $u['level'] === Roles::ADMIN));
    }

    public function verifyPassword(int $id, string $password): bool
    {
        return isset($this->users[$id]) && password_verify($password, $this->users[$id]['password']);
    }

    public function setPassword(int $id, string $password): void
    {
        $this->users[$id]['password'] = password_hash($password, PASSWORD_DEFAULT);
    }

    public function loginExists(string $login): bool
    {
        foreach ($this->users as $u) {
            if (strcasecmp($u['login'], $login) === 0) {
                return true;
            }
        }
        return false;
    }

    public function emailExists(string $email): bool
    {
        foreach ($this->users as $u) {
            if ($u['email'] !== '' && strcasecmp($u['email'], $email) === 0) {
                return true;
            }
        }
        return false;
    }

    public function currentUser(): ?User
    {
        return $this->current ? $this->user($this->current) : null;
    }

    public function mode(): string
    {
        return 'form';
    }

    public function loginUrl(): ?string
    {
        return null;
    }

    public function logoutUrl(): ?string
    {
        return null;
    }

    public function csrfHeader(): string
    {
        return 'X-CSRF-Token';
    }

    public function csrfToken(): string
    {
        return $this->current ? 'token-' . $this->current : '';
    }

    /** Sitzung des aktuellen Benutzers; ein Test kann sie wechseln (z. B. Anmeldung auf einem anderen Gerät). */
    public string $session = 'sitzung-1';

    public function sessionKey(): string
    {
        return $this->current ? $this->session . '-' . $this->current : '';
    }

    public function csrfRefreshUrl(): string
    {
        return 'https://example.org/api/v1/auth/csrf';
    }

    public function login(string $username, string $password, bool $remember): User
    {
        foreach ($this->users as $id => $u) {
            if ($u['login'] === $username && $this->verifyPassword($id, $password) && empty($u['disabled'])) {
                $this->current = $id;
                return $this->user($id);
            }
        }
        throw ApiError::invalid('Benutzername oder Passwort ist falsch.');
    }

    public function logout(): void
    {
        $this->current = null;
    }

    public function afterPasswordChange(int $userId): void
    {
        $this->passwordChanges++;
    }

    public function profile(int $id): ?array
    {
        $u = $this->users[$id] ?? null;
        if ($u === null) {
            return null;
        }
        [$first, $last] = $this->names($id);
        return ['name' => $u['name'], 'first_name' => $first, 'last_name' => $last, 'login' => $u['login'], 'email' => $u['email']];
    }

    public function canChangeLogin(): bool
    {
        return $this->adminsAssignable;
    }

    public function updateProfile(int $id, string $firstName, string $lastName, string $email, ?string $login): void
    {
        $this->users[$id]['name'] = PersonName::join($firstName, $lastName);
        $this->users[$id]['first'] = $firstName;
        $this->users[$id]['last'] = $lastName;
        $this->users[$id]['email'] = $email;
        if ($login !== null) {
            $this->users[$id]['login'] = $login;
        }
    }

    public function removeAccount(int $id): void
    {
        unset($this->users[$id]);
        if ($this->current === $id) {
            $this->current = null;
        }
    }

    /** Wie Standalone: Rolle Gast, Anmeldung gesperrt, abgemeldet. */
    public function deactivate(int $id): void
    {
        $this->users[$id]['level'] = Roles::GUEST;
        $this->users[$id]['disabled'] = true;
        if ($this->current === $id) {
            $this->current = null;
        }
    }

    public function accountData(int $id): array
    {
        return isset($this->users[$id]) ? ['sessions' => []] : [];
    }

    public function forgetLogin(int $id): void
    {
        $this->users[$id]['login'] = 'ehemalig-' . bin2hex(random_bytes(6));
        $this->users[$id]['email'] = '';
        $this->users[$id]['password'] = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    }

    public function reactivate(int $id, string $role): void
    {
        $this->users[$id]['level'] = $role;
        unset($this->users[$id]['disabled']);
    }
}
