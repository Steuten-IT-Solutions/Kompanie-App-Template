<?php

declare(strict_types=1);

namespace KompanieApp\WordPress;

use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\Level;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Port\AuthContext;
use KompanieApp\Core\Port\UserDirectory;

/** WordPress-Benutzer als Benutzerverzeichnis der App. */
final class WpUsers implements UserDirectory, AuthContext
{
    public function currentUser(): ?User
    {
        $u = wp_get_current_user();
        return $u->exists() ? $this->toUser($u) : null;
    }

    public function find(int $id): ?User
    {
        $u = $id > 0 ? get_userdata($id) : false;
        return $u ? $this->toUser($u) : null;
    }

    public function list(bool $includeGuests): array
    {
        $args = ['orderby' => 'display_name', 'order' => 'ASC'];
        if (!$includeGuests) {
            $args['capability'] = Roles::CAP_MEMBER;
        }
        $args = apply_filters('kompanie_app_members_query_args', $args);
        return array_map(fn (\WP_User $u): User => $this->toUser($u), get_users($args));
    }

    public function create(string $name, string $login, string $email, string $password): int
    {
        $parts = preg_split('/\s+/', $name, 2) ?: [$name];
        $id = wp_insert_user([
            'user_login' => $login,
            'user_pass' => $password,
            'user_email' => $email,
            'display_name' => $name,
            'nickname' => $name,
            'first_name' => $parts[0],
            'last_name' => $parts[1] ?? '',
            'role' => Roles::ROLE_MEMBER,
        ]);
        if (is_wp_error($id)) {
            throw new \DomainException($id->get_error_message());
        }
        return (int) $id;
    }

    public function canAssignAdmin(): bool
    {
        return false;
    }

    public function setLevel(int $id, Level $level): void
    {
        $user = get_userdata($id);
        if (!$user) {
            throw new \DomainException('Benutzer nicht gefunden.');
        }
        if ($level === Level::Admin || in_array('administrator', (array) $user->roles, true)) {
            throw new \DomainException('Administratoren werden im WordPress-Backend verwaltet.');
        }
        $user->remove_role(Roles::ROLE_MANAGER);
        $user->remove_role(Roles::ROLE_MEMBER);
        if ($level === Level::Manager) {
            $user->add_role(Roles::ROLE_MANAGER);
        } elseif ($level === Level::Member) {
            $user->add_role(Roles::ROLE_MEMBER);
        }
        // Ein Konto ohne jede Rolle kann sich nicht sinnvoll anmelden.
        if (empty($user->roles)) {
            $user->add_role('subscriber');
        }
        clean_user_cache($id);
    }

    public function countAdmins(): int
    {
        return count(get_users(['role' => 'administrator', 'fields' => 'ID']));
    }

    public function verifyPassword(int $id, string $password): bool
    {
        $u = get_userdata($id);
        return $u && wp_check_password($password, $u->user_pass, $id);
    }

    public function setPassword(int $id, string $password): void
    {
        wp_set_password($password, $id);
        \WP_Session_Tokens::get_instance($id)->destroy_all();
        clean_user_cache($id);
    }

    public function loginExists(string $login): bool
    {
        return (bool) username_exists($login);
    }

    public function emailExists(string $email): bool
    {
        return (bool) email_exists($email);
    }

    public static function level(\WP_User $u): Level
    {
        if (in_array('administrator', (array) $u->roles, true)) {
            return Level::Admin;
        }
        if (user_can($u, Roles::CAP_MANAGE)) {
            return Level::Manager;
        }
        return user_can($u, Roles::CAP_MEMBER) ? Level::Member : Level::Guest;
    }

    private function toUser(\WP_User $u): User
    {
        $map = [
            Roles::CAP_MEMBER => Capability::Member,
            Roles::CAP_MANAGE => Capability::Manage,
            Roles::CAP_EVENTS => Capability::ManageEvents,
            'create_users' => Capability::CreateUsers,
            'promote_users' => Capability::PromoteUsers,
            'manage_options' => Capability::ManageSettings,
        ];
        $caps = [];
        foreach ($map as $wpCap => $cap) {
            if (user_can($u, $wpCap)) {
                $caps[] = $cap;
            }
        }
        return new User((int) $u->ID, (string) $u->display_name, (string) $u->user_login, self::level($u), $caps);
    }
}
