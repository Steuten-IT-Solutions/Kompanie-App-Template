<?php

declare(strict_types=1);

namespace KompanieApp\WordPress;

use KompanieApp\Core\Port\UpdateTarget;

/**
 * Spielt ein geprüftes Paket mit dem Plugin-Upgrader von WordPress ein.
 *
 * Der Upgrader wird direkt mit den Optionen eines Plugin-Updates aufgerufen (wie
 * Plugin_Upgrader::upgrade), aber ohne dessen Deaktivieren: Außerhalb von Cron würde WordPress
 * das Plugin vor dem Update stillschweigend deaktivieren und erst eine Browser-Seite wieder
 * einschalten. Ab WordPress 6.3 legt der Upgrader den alten Plugin-Ordner beiseite
 * („temp_backup“) und stellt ihn wieder her, wenn das Einspielen scheitert.
 */
final class WpUpdateTarget implements UpdateTarget
{
    public const SLUG = 'kompanie-app';

    public function variant(): string
    {
        return 'wordpress';
    }

    public function problems(array $entry): array
    {
        global $wp_version;
        $problems = [];
        $min = (string) ($entry['minWordPress'] ?? '');
        if ($min !== '' && version_compare((string) $wp_version, $min, '<')) {
            $problems[] = "Die neue Version braucht WordPress $min; installiert ist $wp_version. Bitte zuerst WordPress aktualisieren.";
        } elseif (version_compare((string) $wp_version, '6.3', '<')) {
            // Erst ab 6.3 stellt der Upgrader den alten Stand wieder her, wenn etwas schiefgeht.
            $problems[] = "Automatische Updates brauchen WordPress 6.3 oder neuer; installiert ist $wp_version. Bitte zuerst WordPress aktualisieren.";
        }
        if (dirname(self::plugin()) !== self::SLUG) {
            $problems[] = 'Das Plugin liegt im Ordner „' . dirname(self::plugin()) . '“ statt „' . self::SLUG . '“. Bitte einmal von Hand aktualisieren (Plugins → Installieren → Plugin hochladen).';
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        if (get_filesystem_method([], WP_PLUGIN_DIR) !== 'direct') {
            $problems[] = 'WordPress darf die Plugin-Dateien nicht selbst schreiben (es fragt nach FTP-Zugangsdaten). Bitte die Dateirechte beim Hoster prüfen oder das Update von Hand hochladen.';
        }
        return $problems;
    }

    public function install(string $zipFile, string $version): void
    {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        if (!WP_Filesystem()) {
            throw new \RuntimeException('WordPress darf die Plugin-Dateien nicht schreiben.');
        }
        $plugin = self::plugin();
        $skin = new \Automatic_Upgrader_Skin();
        $upgrader = new \Plugin_Upgrader($skin);
        $upgrader->init();
        $upgrader->upgrade_strings();
        $check = static function ($source) use ($version) {
            if (is_wp_error($source)) {
                return $source;
            }
            // Ordnername und Version prüfen, bevor der alte Ordner ersetzt wird.
            $main = trailingslashit((string) $source) . self::SLUG . '.php';
            if (basename(untrailingslashit((string) $source)) !== self::SLUG || !is_file($main)
                || get_file_data($main, ['v' => 'Version'])['v'] !== $version) {
                return new \WP_Error('kompanie_package', 'Das Paket ist unvollständig oder hat eine andere Version.');
            }
            return $source;
        };
        add_filter('upgrader_source_selection', $check, 10, 1);
        $upgrader->maintenance_mode(true);
        try {
            $upgrader->run([
                // Eine lokale Datei lädt WordPress nicht herunter, sondern entpackt sie direkt.
                'package' => $zipFile,
                'destination' => WP_PLUGIN_DIR,
                'clear_destination' => true,
                'clear_working' => true,
                'hook_extra' => [
                    'plugin' => $plugin,
                    'type' => 'plugin',
                    'action' => 'update',
                    'temp_backup' => ['slug' => self::SLUG, 'src' => WP_PLUGIN_DIR, 'dir' => 'plugins'],
                ],
            ]);
        } finally {
            remove_filter('upgrader_source_selection', $check, 10);
            $upgrader->maintenance_mode(false);
        }
        $result = $upgrader->result;
        if (!$result || is_wp_error($result)) {
            $messages = array_filter(array_map('strval', (array) $skin->get_upgrade_messages()));
            $detail = is_wp_error($result) ? $result->get_error_message() : (string) end($messages);
            throw new \RuntimeException(trim('Das Plugin ließ sich nicht aktualisieren. ' . wp_strip_all_tags($detail)));
        }
        wp_clean_plugins_cache(true);
        if (function_exists('wp_opcache_invalidate_directory')) {
            wp_opcache_invalidate_directory(WP_PLUGIN_DIR . '/' . self::SLUG);
        }
    }

    /** Pfad des Plugins relativ zum Plugin-Ordner, z. B. kompanie-app/kompanie-app.php. */
    private static function plugin(): string
    {
        return plugin_basename(KOMPANIE_APP_FILE);
    }
}
