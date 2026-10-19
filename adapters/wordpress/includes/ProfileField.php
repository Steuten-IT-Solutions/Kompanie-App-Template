<?php

declare(strict_types=1);

namespace KompanieApp\WordPress;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Level;

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
        if (!current_user_can('promote_users')) {
            return;
        }
        $c = $app->config;
        $level = WpUsers::level($user);
        echo '<h2>' . esc_html($c->shortName()) . ' – App</h2><table class="form-table" role="presentation"><tr>';
        echo '<th scope="row"><label for="kompanie_app_level">Rolle in der App</label></th><td>';
        if ($level === Level::Admin) {
            echo '<strong>' . esc_html($c->roleLabel('admin')) . '</strong> – Administratoren dürfen in der App alles.';
        } else {
            $options = [
                'guest' => $c->roleLabel('guest') . ' – nur öffentliche Inhalte',
                'member' => $c->roleLabel('member') . ' – eigene Punkte und Strafen',
                'organizer' => $c->roleLabel('organizer') . ' – Termine und Dokumente pflegen',
                'manager' => $c->roleLabel('manager') . ' – zusätzlich Punkte und Strafen verwalten',
            ];
            echo '<select name="kompanie_app_level" id="kompanie_app_level">';
            foreach ($options as $value => $label) {
                printf('<option value="%s"%s>%s</option>', esc_attr($value), selected($level->value, $value, false), esc_html($label));
            }
            echo '</select>';
            wp_nonce_field('kompanie_app_level_' . $user->ID, 'kompanie_app_level_nonce');
        }
        echo '</td></tr></table>';
    }

    private static function save(App $app, int $userId): void
    {
        if (!current_user_can('promote_users') || !current_user_can('edit_user', $userId)) {
            return;
        }
        $nonce = isset($_POST['kompanie_app_level_nonce']) ? sanitize_key(wp_unslash($_POST['kompanie_app_level_nonce'])) : '';
        if (!isset($_POST['kompanie_app_level']) || !wp_verify_nonce($nonce, 'kompanie_app_level_' . $userId)) {
            return;
        }
        $level = Level::tryFrom(sanitize_key(wp_unslash($_POST['kompanie_app_level'])));
        if ($level !== null && $level !== Level::Admin) {
            try {
                $app->users->setLevel($userId, $level);
            } catch (\DomainException) {
                // Administratoren bleiben unverändert.
            }
        }
    }
}
