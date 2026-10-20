<?php

declare(strict_types=1);

namespace KompanieApp\Standalone;

use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Port\UserDirectory;
use KompanieApp\Core\Storage\Sql\Connection;

/** Benutzer der eigenständigen Version in der Tabelle users. */
final class PdoUsers implements UserDirectory
{
    /**
     * @param (\Closure(string): array<int, \KompanieApp\Core\Auth\Capability>)|null $capabilities Rechte je Rolle
     *        (Domain\RoleService der App); ohne: die Vorlagen
     */
    public function __construct(
        private readonly Connection $db,
        private readonly ?Sessions $sessions = null,
        private readonly ?\Closure $capabilities = null,
    ) {
    }

    public function find(int $id): ?User
    {
        $row = $id > 0 ? $this->db->selectOne('SELECT * FROM {users} WHERE id = ?', [$id]) : null;
        return $row ? $this->toUser($row) : null;
    }

    public function findMany(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ($this->db->select('SELECT * FROM {users} WHERE id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')', $chunk) as $row) {
                $out[(int) $row['id']] = $this->toUser($row);
            }
        }
        return $out;
    }

    /** @return array<string, mixed>|null Zeile für die Anmeldung (Benutzername oder E-Mail). */
    public function findForLogin(string $loginOrEmail): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM {users} WHERE LOWER(login) = LOWER(?)', [$loginOrEmail]);
        if (!$row && str_contains($loginOrEmail, '@')) {
            $rows = $this->db->select('SELECT * FROM {users} WHERE LOWER(email) = LOWER(?)', [$loginOrEmail]);
            $row = count($rows) === 1 ? $rows[0] : null;
        }
        return $row;
    }

    public function list(bool $includeGuests): array
    {
        $where = $includeGuests ? '' : "WHERE level <> 'guest'";
        return array_map([$this, 'toUser'], $this->db->select("SELECT * FROM {users} $where ORDER BY name ASC, id ASC"));
    }

    public function create(string $name, string $login, string $email, string $password, string $role = Roles::MEMBER): int
    {
        return $this->add($name, $login, $email, $password, $role);
    }

    public function add(string $name, string $login, string $email, string $password, string $role): int
    {
        $now = gmdate('Y-m-d H:i:s');
        return $this->db->insert('users', [
            'login' => $login,
            'email' => $email,
            'name' => $name,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'level' => $role,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function canAssignAdmin(): bool
    {
        return true;
    }

    public function setRole(int $id, string $role): void
    {
        $this->db->update('users', ['level' => $role, 'updated_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
    }

    public function countAdmins(): int
    {
        return (int) $this->db->scalar("SELECT COUNT(*) FROM {users} WHERE level = 'admin'");
    }

    public function count(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM {users}');
    }

    public function verifyPassword(int $id, string $password): bool
    {
        $hash = $this->db->scalar('SELECT password_hash FROM {users} WHERE id = ?', [$id]);
        return is_string($hash) && password_verify($password, $hash);
    }

    public function setPassword(int $id, string $password): void
    {
        $this->db->update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'updated_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
        $this->sessions?->destroyAllFor($id);
    }

    /** Hash nach einer Änderung des Verfahrens (z. B. höhere Kosten) erneuern. */
    public function rehashIfNeeded(int $id, string $password, string $hash): void
    {
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $this->db->update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], ['id' => $id]);
        }
    }

    public function touchLogin(int $id): void
    {
        $this->db->update('users', ['last_login_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
    }

    public function loginExists(string $login): bool
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM {users} WHERE LOWER(login) = LOWER(?)', [$login]) > 0;
    }

    public function emailExists(string $email): bool
    {
        return (int) $this->db->scalar("SELECT COUNT(*) FROM {users} WHERE email <> '' AND LOWER(email) = LOWER(?)", [$email]) > 0;
    }

    public function profile(int $id): ?array
    {
        $row = $id > 0 ? $this->db->selectOne('SELECT name, login, email FROM {users} WHERE id = ?', [$id]) : null;
        return $row ? ['name' => (string) $row['name'], 'login' => (string) $row['login'], 'email' => (string) $row['email']] : null;
    }

    public function canChangeLogin(): bool
    {
        return true;
    }

    public function updateProfile(int $id, string $name, string $email, ?string $login): void
    {
        $this->db->update('users', ['name' => $name, 'email' => $email, 'updated_at' => gmdate('Y-m-d H:i:s')] + ($login !== null ? ['login' => $login] : []), ['id' => $id]);
    }

    /** Konto löschen und alle Sitzungen beenden. */
    public function removeAccount(int $id): void
    {
        $this->sessions?->destroyAllFor($id);
        $this->db->execute('DELETE FROM {users} WHERE id = ?', [$id]);
    }

    /** @param array<string, mixed> $row */
    public function toUser(array $row): User
    {
        $role = (string) $row['level'];
        $caps = $this->capabilities !== null ? ($this->capabilities)($role) : Roles::templateCapabilities($role);
        return new User((int) $row['id'], (string) $row['name'], (string) $row['login'], $role, $caps);
    }
}
