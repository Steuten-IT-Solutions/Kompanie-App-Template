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

    /**
     * Atomarer Zähler in der Options-Tabelle (Transients sind nicht atomar). Der Name enthält
     * das Ende des Zeitfensters (zehnstellig), damit Abgelaufenes per Vergleich gelöscht werden kann.
     */
    public function increment(string $key, int $windowSeconds): int
    {
        global $wpdb;
        $window = max(1, $windowSeconds);
        $expires = (intdiv(time(), $window) + 1) * $window;
        $prefix = WpSettings::PREFIX . 'ctr_';
        $name = $prefix . sprintf('%010d', $expires) . '_' . md5($key);
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no') ON DUPLICATE KEY UPDATE option_value = option_value + 1",
            $name,
        ));
        if (random_int(1, 50) === 1) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name < %s", $wpdb->esc_like($prefix) . '%', $prefix . sprintf('%010d', time())));
        }
        return (int) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
    }
}
