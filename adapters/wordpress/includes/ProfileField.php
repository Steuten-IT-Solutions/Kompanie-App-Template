<?php

declare(strict_types=1);

namespace KompanieApp\WordPress;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;

/** „Rolle in der App“ im Benutzerprofil des WP-Admin. */
final class ProfileField
{
    public static function init(): void
    {
        add_action('show_user_profile', static fn (\WP_User $u) => self::render(Plugin::app(), $u));
        add_action('edit_user_profile', static fn (\WP_User $u) => self::render(Plugin::app(), $u));
        // Erst nach wp_update_user() speichern: edit_user() ruft set_role() auf
        // und würde eine vorher gesetzte Zusatzrolle wieder entfernen.
        add_action('profile_update', static fn (int $id) => self::save(Plugin::app(), $id), 20);
    }

    private static function render(App $app, \WP_User $user): void
    {
        // Die eigene Rolle ändert niemand selbst (wie in der App).
        if (!current_user_can('promote_users') || (int) $user->ID === get_current_user_id()) {
            return;
        }
        $c = $app->config;
        $level = WpUsers::role($user);
        echo '<h2>' . esc_html($c->shortName()) . ' – App</h2><table class="form-table" role="presentation"><tr>';
        echo '<th scope="row"><label for="kompanie_app_level">Rolle in der App</label></th><td>';
        if ($level === Roles::ADMIN) {
            echo '<strong>' . esc_html($app->roles()->label(Roles::ADMIN)) . '</strong> – Administratoren dürfen in der App alles.';
        } else {
            // Rollen der App (Einstellungen → Rollen), ohne Admin: Admins werden im WP-Backend vergeben.
            $options = [];
            foreach ($app->roles()->all() as $key => $role) {
                if ($key !== Roles::ADMIN) {
                    $options[$key] = $role['label'];
                }
            }
            echo '<select name="kompanie_app_level" id="kompanie_app_level">';
            foreach ($options as $value => $label) {
                printf('<option value="%s"%s>%s</option>', esc_attr($value), selected($level, $value, false), esc_html($label));
            }
            echo '</select>';
            wp_nonce_field('kompanie_app_level_' . $user->ID, 'kompanie_app_level_nonce');
        }
        echo '</td></tr></table>';
    }

    private static function save(App $app, int $userId): void
    {
        if (!current_user_can('promote_users') || !current_user_can('edit_user', $userId) || $userId === get_current_user_id()) {
            return;
        }
        $nonce = isset($_POST['kompanie_app_level_nonce']) ? sanitize_key(wp_unslash($_POST['kompanie_app_level_nonce'])) : '';
        if (!isset($_POST['kompanie_app_level']) || !wp_verify_nonce($nonce, 'kompanie_app_level_' . $userId)) {
            return;
        }
        $level = sanitize_key(wp_unslash($_POST['kompanie_app_level']));
        $actor = $app->authContext->currentUser();
        $target = $app->users->find($userId);
        // Wie in der App: keine Rechte weitergeben, die man selbst nicht hat (WP-Administratoren dürfen alles).
        if ($level !== Roles::ADMIN && $app->roles()->exists($level) && $actor !== null && $target !== null
            && $app->roles()->canHandle($actor, $level) && $app->roles()->canHandle($actor, $target->role)) {
            try {
                $app->users->setRole($userId, $level);
            } catch (\DomainException) {
                // Administratoren bleiben unverändert.
            }
        }
    }
}
