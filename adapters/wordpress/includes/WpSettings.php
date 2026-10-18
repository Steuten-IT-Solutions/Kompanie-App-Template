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
