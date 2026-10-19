<?php

declare(strict_types=1);

namespace KompanieApp\WordPress;

use KompanieApp\Core\Port\Settings;

/** Einstellungen als WordPress-Options mit Präfix. */
final class WpSettings implements Settings
{
    public const PREFIX = 'kompanie_app_';

    /** Große, selten gebrauchte Werte: nicht bei jedem Seitenaufruf laden. */
    public const NO_AUTOLOAD = ['drive_service_account', 'gallery_status'];

    public function get(string $key, mixed $default = null): mixed
    {
        return get_option(self::PREFIX . $key, $default);
    }

    public function fresh(string $key, mixed $default = null): mixed
    {
        global $wpdb;
        // Am Objekt-Cache vorbei direkt aus der Datenbank (get_option läse aus alloptions bzw. dem Cache).
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::PREFIX . $key));
        wp_cache_delete(self::PREFIX . $key, 'options');
        wp_cache_delete('alloptions', 'options');
        return $raw === null ? $default : maybe_unserialize($raw);
    }

    public function set(string $key, mixed $value): void
    {
        // Kleine Einstellungen, die bei jedem Aufruf gebraucht werden: mit Autoload.
        update_option(self::PREFIX . $key, $value, !in_array($key, self::NO_AUTOLOAD, true));
    }

    public function delete(string $key): void
    {
        delete_option(self::PREFIX . $key);
    }
}
