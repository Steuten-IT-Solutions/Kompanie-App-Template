<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests\Support;

use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Auth\User;
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
    /** @var array<int, array{name: string, login: string, email: string, level: string, password: string}> */
    public array $users = [];
    public ?int $current = null;
    public int $passwordChanges = 0;
    private int $next = 1;

    /** @var (\Closure(string): array<int, \KompanieApp\Core\Auth\Capability>)|null Rechte je Rolle (setzt TestApp: die der App) */
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
        $u = $this->users[$id] ?? null;
        $caps = $u === null ? [] : ($this->capabilities !== null ? ($this->capabilities)($u['level']) : Roles::templateCapabilities($u['level']));
        return $u ? new User($id, $u['name'], $u['login'], $u['level'], $caps) : null;
    }

    public function list(bool $includeGuests): array
    {
        $out = [];
        foreach (array_keys($this->users) as $id) {
            $u = $this->find($id);
            if ($includeGuests || $u->isMember()) {
                $out[] = $u;
            }
        }
        usort($out, static fn (User $a, User $b): int => strcmp($a->name, $b->name));
        return $out;
    }

    public function create(string $name, string $login, string $email, string $password, string $role = Roles::MEMBER): int
    {
        $id = $this->add($login, $role, $password);
        $this->users[$id]['name'] = $name;
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
        return $this->current ? $this->find($this->current) : null;
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

    public function csrfRefreshUrl(): string
    {
        return 'https://example.org/api/v1/auth/csrf';
    }

    public function login(string $username, string $password, bool $remember): User
    {
        foreach ($this->users as $id => $u) {
            if ($u['login'] === $username && $this->verifyPassword($id, $password)) {
                $this->current = $id;
                return $this->find($id);
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
}
