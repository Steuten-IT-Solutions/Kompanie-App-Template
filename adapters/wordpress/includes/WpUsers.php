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
 * Die Rolle in der App steht in der Benutzer-Option ROLE_META (je Website, auch bei Multisite;
 * Schlüssel aus Domain\RoleService), die Rechte dazu kommen aus der App. Admin ist, wer WordPress-Administrator ist. Ältere Installationen hatten
 * die Rolle als WP-Rolle (kompanie_member/_organizer/_manager); solange das Meta fehlt, gilt diese.
 */
final class WpUsers implements UserDirectory, AuthContext
{
    public const ROLE_META = 'kompanie_app_role';

    /**
     * Markiert Konten der Kompanie (je Website): von der App angelegt, aus früheren WP-Rollen übernommen
     * oder von jemandem mit dem WP-Recht zugeordnet. Nur deren Passwort setzt auch zurück, wer das
     * Recht „Mitglieder anlegen“ nur über seine Rolle in der App hat; sonst könnte er ein beliebiges
     * Konto der Webseite erst zum Mitglied machen und dann übernehmen.
     */
    public const ACCOUNT_META = 'kompanie_app_account';

    /** Schlüssel in usermeta: mit dem Präfix der Website (wie update_user_option ohne global). */
    public static function metaKey(): string
    {
        global $wpdb;
        return $wpdb->get_blog_prefix() . self::ROLE_META;
    }

    /**
     * Frühere WP-Rolle zu einer Rolle der App: Wird der Programmcode auf eine Version vor Issue #28
     * zurückgerollt, behalten Mitglieder, Verwalter und Leitung so ihre Rechte.
     */
    /** Wer eines dieser WP-Rechte hat, gilt als Benutzer der Webseite (Passwort nur mit WP-Recht). */
    private const WEBSITE_CAPS = ['edit_posts', 'list_users', 'edit_users', 'create_users', 'promote_users', 'manage_options'];

    /** Frühere WP-Rechte der App (vor #28), auch über eigene WP-Rollen vergeben, z. B. mit einem Rollen-Plugin. */
    public const LEGACY_CAPS = [Roles::CAP_MEMBER, Roles::CAP_MANAGE, Roles::CAP_EVENTS];

    private const LEGACY_WP_ROLES = [AppRoles::MEMBER => Roles::ROLE_MEMBER, AppRoles::ORGANIZER => Roles::ROLE_ORGANIZER, AppRoles::MANAGER => Roles::ROLE_MANAGER];

    /**
     * @param (\Closure(string): array<int, Capability>)|null $capabilities Rechte je Rolle (Domain\RoleService)
     * @param (\Closure(string): bool)|null $known Gibt es die Rolle in der App? (für frühere WP-Rollen)
     */
    public function __construct(private readonly ?\Closure $capabilities = null, private readonly ?\Closure $known = null)
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
            $args['meta_query'] = [['key' => self::metaKey(), 'compare' => 'EXISTS']];
            $ids = get_users(['meta_query' => $args['meta_query'], 'fields' => 'ID']);
            $legacy = get_users(['role__in' => [Roles::ROLE_MEMBER, Roles::ROLE_ORGANIZER, Roles::ROLE_MANAGER, 'administrator'], 'fields' => 'ID']);
            $legacyCaps = get_users(['capability__in' => self::LEGACY_CAPS, 'fields' => 'ID']);
            $ids = array_values(array_unique(array_map('intval', array_merge($ids, $legacy, $legacyCaps))));
            if ($ids === []) {
                return [];
            }
            unset($args['meta_query']);
            $args['include'] = $ids;
        }
        $args = apply_filters('kompanie_app_members_query_args', $args);
        return array_map(fn (\WP_User $u): User => $this->toUser($u), get_users($args));
    }

    public function create(string $name, string $login, string $email, string $password, string $role = AppRoles::MEMBER): int
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
            // Die frühere WP-Rolle nur für Mitglieder (Rückfall bei einem Zurückrollen); Anwärter sind Abonnenten.
            'role' => $role === AppRoles::MEMBER ? Roles::ROLE_MEMBER : 'subscriber',
        ]);
        if (is_wp_error($id)) {
            throw new \DomainException($id->get_error_message());
        }
        update_user_option((int) $id, self::ROLE_META, $role, false);
        update_user_option((int) $id, self::ACCOUNT_META, '1', false);
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
        if ($role === AppRoles::ADMIN || in_array('administrator', (array) $user->roles, true) || is_super_admin($id)) {
            throw new \DomainException('Administratoren werden im WordPress-Backend verwaltet.');
        }
        // Wer handelt, braucht das Recht in WordPress oder über seine Rolle in der App (der Kern hat die
        // Regeln der App schon geprüft). Ohne angemeldeten Benutzer (WP-CLI, Einrichtung) gilt das nicht.
        // Anwärter zum Mitglied machen darf auch, wer Konten anlegen darf (Issue #23).
        $promotion = $role === AppRoles::MEMBER && $this->find($id)?->role === AppRoles::CANDIDATE && $this->currentUser()?->can(Capability::CreateUsers);
        if (!$promotion && !$this->actorMay('promote_user', $id, Capability::PromoteUsers)) {
            throw new \DomainException('Diesen Benutzer darfst du in WordPress nicht ändern.');
        }
        update_user_option($id, self::ROLE_META, $role, false);
        if (get_current_user_id() === 0 || current_user_can('promote_user', $id)) {
            update_user_option($id, self::ACCOUNT_META, '1', false);
        }
        $user->remove_role(Roles::ROLE_MANAGER);
        $user->remove_role(Roles::ROLE_ORGANIZER);
        $user->remove_role(Roles::ROLE_MEMBER);
        if (isset(self::LEGACY_WP_ROLES[$role])) {
            $user->add_role(self::LEGACY_WP_ROLES[$role]);
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
        $actor = get_current_user_id();
        $target = get_userdata($id);
        if ($actor !== 0 && $actor !== $id) {
            // Wer die Webseite bearbeiten oder Benutzer verwalten darf (Redakteure, Administratoren, eigene
            // WP-Rollen) oder kein Konto der Kompanie ist (ACCOUNT_META), dessen Passwort nur mit dem Recht
            // aus WordPress; sonst genügt das Recht „Mitglieder anlegen“ der Rolle in der App.
            $websiteUser = $target && (is_super_admin($id) || array_filter(self::WEBSITE_CAPS, static fn (string $cap): bool => user_can($target, $cap)) !== []);
            // Nur das Meta dieser Website (wie bei der Rolle), nicht das ohne Präfix.
            global $wpdb;
            $appAccount = get_user_meta($id, $wpdb->get_blog_prefix() . self::ACCOUNT_META, true) === '1';
            $may = $websiteUser || !$appAccount ? current_user_can('edit_user', $id) : $this->actorMay('edit_user', $id, Capability::CreateUsers);
            if (!$may || $this->find($id) === null) {
                throw new \DomainException('Das Passwort dieses Benutzers darfst du in WordPress nicht ändern.');
            }
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

    /** Darf der angemeldete Benutzer das: in WordPress (z. B. promote_user) oder über seine Rolle in der App? */
    private function actorMay(string $wpCap, int $id, Capability $appCap): bool
    {
        if (get_current_user_id() === 0 || current_user_can($wpCap, $id)) {
            return true;
        }
        return (bool) $this->currentUser()?->can($appCap);
    }

    /**
     * Rolle in der App: Administrator, sonst das Meta, sonst die frühere WP-Rolle. Bei Multisite nur
     * für Mitglieder dieser Website (wer entfernt wurde, behält sonst die gespeicherte Rolle).
     */
    public static function role(\WP_User $u): string
    {
        if (in_array('administrator', (array) $u->roles, true) || is_super_admin((int) $u->ID)) {
            return AppRoles::ADMIN;
        }
        if (is_multisite() && !is_user_member_of_blog((int) $u->ID)) {
            return AppRoles::GUEST;
        }
        $meta = get_user_meta((int) $u->ID, self::metaKey(), true);
        // admin nur über WordPress, nie über das Meta.
        if (is_string($meta) && $meta !== '' && $meta !== AppRoles::ADMIN) {
            return $meta;
        }
        return self::legacyRole($u);
    }

    private static function hasNoMeta(\WP_User $u): bool
    {
        $meta = get_user_meta((int) $u->ID, self::metaKey(), true);
        return !is_string($meta) || $meta === '';
    }

    /**
     * Rolle nach den früheren WP-Rollen und -Rechten der App (vor Issue #28), wie damals über die Rechte
     * bestimmt: Sie können auch über andere WP-Rollen vergeben sein (z. B. kompanie_member für Redakteure).
     */
    public static function legacyRole(\WP_User $u): string
    {
        $roles = (array) $u->roles;
        return match (true) {
            in_array(Roles::ROLE_MANAGER, $roles, true) || user_can($u, Roles::CAP_MANAGE) => AppRoles::MANAGER,
            in_array(Roles::ROLE_ORGANIZER, $roles, true) || user_can($u, Roles::CAP_EVENTS) => AppRoles::ORGANIZER,
            in_array(Roles::ROLE_MEMBER, $roles, true) || user_can($u, Roles::CAP_MEMBER) => AppRoles::MEMBER,
            default => AppRoles::GUEST,
        };
    }

    private function toUser(\WP_User $u): User
    {
        $role = self::role($u);
        // Frühere WP-Rolle (Verwalter/Leitung), die es in dieser Installation nicht gibt (neu eingerichtet):
        // wie Mitglied statt ganz ohne Rechte.
        if ($this->known !== null && !($this->known)($role) && self::hasNoMeta($u) && $role !== AppRoles::GUEST) {
            $role = AppRoles::MEMBER;
        }
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
