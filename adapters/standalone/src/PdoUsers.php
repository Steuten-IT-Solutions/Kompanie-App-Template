<?php

declare(strict_types=1);

namespace KompanieApp\Standalone;

use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Domain\PersonName;
use KompanieApp\Core\Port\UserDirectory;
use KompanieApp\Core\Storage\Sql\Connection;

/** Benutzer der eigenständigen Version in der Tabelle users. */
final class PdoUsers implements UserDirectory
{
    /**
     * @param (\Closure(string, int): array<int, \KompanieApp\Core\Auth\Capability>)|null $capabilities Rechte je Grundrolle
     *        und Benutzer-ID, mit den Zusatzrollen (Domain\RoleService::capabilitiesFor der App); ohne: die Vorlagen
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

    public function emails(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ($this->db->select('SELECT id, email FROM {users} WHERE id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')', $chunk) as $row) {
                $out[(int) $row['id']] = (string) $row['email'];
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

    public function create(string $firstName, string $lastName, string $login, string $email, string $password, string $role = Roles::MEMBER): int
    {
        return $this->insertUser($firstName, $lastName, $login, $email, $password, $role);
    }

    /** Mit dem ganzen Namen (Einrichtung, Kommandozeile): das letzte Wort ist der Nachname. */
    public function add(string $name, string $login, string $email, string $password, string $role): int
    {
        [$first, $last] = PersonName::split($name);
        return $this->insertUser($first, $last, $login, $email, $password, $role);
    }

    private function insertUser(string $firstName, string $lastName, string $login, string $email, string $password, string $role): int
    {
        $now = gmdate('Y-m-d H:i:s');
        return $this->db->insert('users', [
            'login' => $login,
            'email' => $email,
            'name' => PersonName::join($firstName, $lastName),
            'first_name' => $firstName,
            'last_name' => $lastName,
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
        $row = $id > 0 ? $this->db->selectOne('SELECT * FROM {users} WHERE id = ?', [$id]) : null;
        if (!$row) {
            return null;
        }
        [$first, $last] = self::names($row);
        return ['name' => (string) $row['name'], 'first_name' => $first, 'last_name' => $last, 'login' => (string) $row['login'], 'email' => (string) $row['email']];
    }

    public function canChangeLogin(): bool
    {
        return true;
    }

    public function updateProfile(int $id, string $firstName, string $lastName, string $email, ?string $login): void
    {
        $names = ['name' => PersonName::join($firstName, $lastName), 'first_name' => $firstName, 'last_name' => $lastName];
        $this->db->update('users', $names + ['email' => $email, 'updated_at' => gmdate('Y-m-d H:i:s')] + ($login !== null ? ['login' => $login] : []), ['id' => $id]);
        if ($login !== null) {
            $this->sessions?->destroyAllFor($id);
        }
    }

    /** Konto löschen und alle Sitzungen beenden. */
    public function removeAccount(int $id): void
    {
        $this->sessions?->destroyAllFor($id);
        $this->db->execute('DELETE FROM {users} WHERE id = ?', [$id]);
    }

    /** Rolle Gast, Anmeldung gesperrt, alle Sitzungen beendet; alle Daten bleiben (ehemaliges Mitglied). */
    public function deactivate(int $id): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->db->update('users', ['level' => Roles::GUEST, 'disabled_at' => $now, 'updated_at' => $now], ['id' => $id]);
        $this->sessions?->destroyAllFor($id);
    }

    public function accountData(int $id): array
    {
        $row = $this->db->selectOne('SELECT created_at, updated_at, last_login_at, disabled_at FROM {users} WHERE id = ?', [$id]) ?? [];
        try {
            // Sitzungen ohne token_hash (der Schlüssel der Anmeldung)
            $sessions = $this->db->select('SELECT created_at, renewed_at, expires_at, remember FROM {sessions} WHERE user_id = ? ORDER BY created_at', [$id]);
        } catch (\RuntimeException) {
            $sessions = [];
        }
        return $row + ['sessions' => $sessions];
    }

    public function forgetLogin(int $id): void
    {
        // Benutzername ohne Bezug zur Person (Zufall: nie gleich einem frei gewählten); als Passwort ein zufälliges, das
        // niemand kennt (ein echter Prüfwert: die Anmeldung dauert so lange wie bei anderen Konten).
        $this->db->update('users', [
            'login' => 'ehemalig-' . bin2hex(random_bytes(6)),
            'email' => '',
            'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
            'last_login_at' => null,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ], ['id' => $id]);
        $this->sessions?->destroyAllFor($id);
    }

    public function reactivate(int $id, string $role): void
    {
        $this->db->update('users', ['level' => $role, 'disabled_at' => null, 'updated_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
    }

    /**
     * Stillgelegt (deactivate)? Dann ist die Anmeldung gesperrt.
     *
     * @param array<string, mixed> $row Zeile aus findForLogin()
     */
    public static function isDisabled(array $row): bool
    {
        return ($row['disabled_at'] ?? null) !== null && $row['disabled_at'] !== '';
    }

    /** @param array<string, mixed> $row */
    public function toUser(array $row): User
    {
        $role = (string) $row['level'];
        $caps = $this->capabilities !== null ? ($this->capabilities)($role, (int) $row['id']) : Roles::templateCapabilities($role);
        [$first, $last] = self::names($row);
        return new User((int) $row['id'], (string) $row['name'], (string) $row['login'], $role, $caps, $first, $last);
    }

    /**
     * Vor- und Nachname; Konten aus der Zeit mit einem Namensfeld (beide leer) werden aus name geteilt.
     *
     * @param array<string, mixed> $row
     * @return array{0: string, 1: string}
     */
    private static function names(array $row): array
    {
        $first = (string) ($row['first_name'] ?? '');
        $last = (string) ($row['last_name'] ?? '');
        return $first === '' && $last === '' ? PersonName::split((string) $row['name']) : [$first, $last];
    }
}
