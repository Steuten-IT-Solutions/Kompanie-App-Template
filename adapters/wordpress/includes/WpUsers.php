<?php

declare(strict_types=1);

namespace KompanieApp\WordPress;

use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\Roles as AppRoles;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Port\AuthContext;
use KompanieApp\Core\Port\UserDirectory;

/**
 * WordPress-Benutzer als Benutzerverzeichnis der App.
 *
 * Die Rolle in der App steht im User-Meta ROLE_META (Schlüssel aus Domain\RoleService), die Rechte
 * dazu kommen aus der App. Admin ist, wer WordPress-Administrator ist. Ältere Installationen hatten
 * die Rolle als WP-Rolle (kompanie_member/_organizer/_manager); solange das Meta fehlt, gilt diese.
 */
final class WpUsers implements UserDirectory, AuthContext
{
    public const ROLE_META = 'kompanie_app_role';

    /**
     * @param (\Closure(string): array<int, Capability>)|null $capabilities Rechte je Rolle (Domain\RoleService)
     */
    public function __construct(private readonly ?\Closure $capabilities = null)
    {
    }

    public function currentUser(): ?User
    {
        $u = wp_get_current_user();
        return $u->exists() ? $this->toUser($u) : null;
    }

    public function find(int $id): ?User
    {
        $u = $id > 0 ? get_userdata($id) : false;
        // Multisite: nur Benutzer dieser Seite (sonst wären Konten des ganzen Netzwerks erreichbar).
        if (!$u || (is_multisite() && !is_user_member_of_blog($id))) {
            return null;
        }
        return $this->toUser($u);
    }

    public function list(bool $includeGuests): array
    {
        $args = ['orderby' => 'display_name', 'order' => 'ASC'];
        if (!$includeGuests) {
            // Nur Konten mit einer Rolle in der App: neues Meta, alte WP-Rollen (noch nicht übernommen)
            // oder Administratoren. Ob die Rolle Mitglied ist, prüft der Kern.
            $args['meta_query'] = [['key' => self::ROLE_META, 'compare' => 'EXISTS']];
            $ids = get_users(['meta_query' => $args['meta_query'], 'fields' => 'ID']);
            $legacy = get_users(['role__in' => [Roles::ROLE_MEMBER, Roles::ROLE_ORGANIZER, Roles::ROLE_MANAGER, 'administrator'], 'fields' => 'ID']);
            $ids = array_values(array_unique(array_map('intval', array_merge($ids, $legacy))));
            if ($ids === []) {
                return [];
            }
            unset($args['meta_query']);
            $args['include'] = $ids;
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
            'role' => 'subscriber',
        ]);
        if (is_wp_error($id)) {
            throw new \DomainException($id->get_error_message());
        }
        update_user_meta((int) $id, self::ROLE_META, AppRoles::MEMBER);
        return (int) $id;
    }

    public function canAssignAdmin(): bool
    {
        return false;
    }

    public function setRole(int $id, string $role): void
    {
        $user = get_userdata($id);
        if (!$user || $this->find($id) === null) {
            throw new \DomainException('Benutzer nicht gefunden.');
        }
        // Zusätzlich die Regeln von WordPress (z. B. Super-Admins, Multisite) für den, der
        // gerade handelt. Ohne angemeldeten Benutzer (WP-CLI, Einrichtung) gilt das nicht.
        if (get_current_user_id() !== 0 && !current_user_can('promote_user', $id)) {
            throw new \DomainException('Diesen Benutzer darfst du in WordPress nicht ändern.');
        }
        if ($role === AppRoles::ADMIN || in_array('administrator', (array) $user->roles, true)) {
            throw new \DomainException('Administratoren werden im WordPress-Backend verwaltet.');
        }
        update_user_meta($id, self::ROLE_META, $role);
        // Die früheren WP-Rollen der App gelten nicht mehr, sobald das Meta gesetzt ist.
        $user->remove_role(Roles::ROLE_MANAGER);
        $user->remove_role(Roles::ROLE_ORGANIZER);
        $user->remove_role(Roles::ROLE_MEMBER);
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
        $actor = get_current_user_id();
        if ($actor !== 0 && $actor !== $id && (!current_user_can('edit_user', $id) || $this->find($id) === null)) {
            throw new \DomainException('Das Passwort dieses Benutzers darfst du in WordPress nicht ändern.');
        }
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

    /** Rolle in der App: Administrator, sonst das Meta, sonst die frühere WP-Rolle. */
    public static function role(\WP_User $u): string
    {
        if (in_array('administrator', (array) $u->roles, true) || is_super_admin((int) $u->ID)) {
            return AppRoles::ADMIN;
        }
        $meta = get_user_meta((int) $u->ID, self::ROLE_META, true);
        if (is_string($meta) && $meta !== '') {
            return $meta;
        }
        return self::legacyRole($u);
    }

    /** Rolle nach den früheren WP-Rollen der App (vor Issue #28). */
    public static function legacyRole(\WP_User $u): string
    {
        $roles = (array) $u->roles;
        return match (true) {
            in_array(Roles::ROLE_MANAGER, $roles, true) => AppRoles::MANAGER,
            in_array(Roles::ROLE_ORGANIZER, $roles, true) => AppRoles::ORGANIZER,
            in_array(Roles::ROLE_MEMBER, $roles, true) => AppRoles::MEMBER,
            default => AppRoles::GUEST,
        };
    }

    private function toUser(\WP_User $u): User
    {
        $role = self::role($u);
        $caps = $this->capabilities !== null ? ($this->capabilities)($role) : AppRoles::templateCapabilities($role);
        // Rechte, die WordPress selbst vergibt (z. B. ein Redakteur mit „Benutzer anlegen“), gelten weiter.
        $wp = ['create_users' => Capability::CreateUsers, 'promote_users' => Capability::PromoteUsers, 'manage_options' => Capability::ManageSettings];
        foreach ($wp as $wpCap => $cap) {
            if (!in_array($cap, $caps, true) && user_can($u, $wpCap)) {
                $caps[] = $cap;
            }
        }
        return new User((int) $u->ID, (string) $u->display_name, (string) $u->user_login, $role, $caps);
    }
}
