<?php

declare(strict_types=1);

namespace KompanieApp\WordPress;

use KompanieApp\Core\Port\Cache;

/** Zwischenspeicher als WordPress-Transients. */
final class WpCache implements Cache
{
    public function get(string $key): mixed
    {
        $v = get_transient(WpSettings::PREFIX . $key);
        return $v === false ? null : $v;
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        set_transient(WpSettings::PREFIX . $key, $value, $ttlSeconds);
    }

    public function delete(string $key): void
    {
        delete_transient(WpSettings::PREFIX . $key);
    }
}
