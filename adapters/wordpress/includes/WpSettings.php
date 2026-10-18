<?php

declare(strict_types=1);

namespace KompanieApp\WordPress;

use KompanieApp\Core\Port\Settings;

/** Einstellungen als WordPress-Options mit Präfix. */
final class WpSettings implements Settings
{
    public const PREFIX = 'kompanie_app_';

    public function get(string $key, mixed $default = null): mixed
    {
        return get_option(self::PREFIX . $key, $default);
    }

    public function set(string $key, mixed $value): void
    {
        // Kleine Einstellungen, die bei jedem Aufruf gebraucht werden: mit Autoload.
        update_option(self::PREFIX . $key, $value, true);
    }

    public function delete(string $key): void
    {
        delete_option(self::PREFIX . $key);
    }
}
