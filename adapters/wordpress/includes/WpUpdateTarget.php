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
 * („temp_backup“) und stellt ihn wieder her, wenn das Einspielen scheitert (am Ende des Aufrufs).
 * Ohne Wartungsmodus, wie Plugin_Upgrader außerhalb von Cron: Bricht der Aufruf ab, bliebe sonst die
 * ganze Website bis zu zehn Minuten gesperrt.
 *
 * Sperren von WordPress gelten: DISALLOW_FILE_MODS (gar keine Updates), AUTOMATIC_UPDATER_DISABLED und
 * der Filter auto_update_plugin (keine automatischen).
 */
final class WpUpdateTarget implements UpdateTarget
{
    public const SLUG = 'kompanie-app';

    /** Ob in diesem Aufruf ein Update eingespielt wurde (dann laufen im selben Cron-Aufruf keine weiteren Aufgaben). */
    public static bool $installed = false;

    public function autoUpdateAllowed(): bool
    {
        if ((defined('AUTOMATIC_UPDATER_DISABLED') && AUTOMATIC_UPDATER_DISABLED) || !wp_is_file_mod_allowed('automatic_updater')) {
            return false;
        }
        $item = (object) ['plugin' => self::plugin(), 'slug' => self::SLUG];
        return (bool) apply_filters('auto_update_plugin', true, $item);
    }

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
        if (!wp_is_file_mod_allowed('kompanie_app_update')) {
            $problems[] = 'In dieser WordPress-Installation sind Änderungen an Plugins gesperrt (DISALLOW_FILE_MODS). Bitte das Update von Hand einspielen lassen.';
        }
        // Von Hand (angemeldet): nur, wer in WordPress Plugins aktualisieren darf.
        if (is_user_logged_in() && !current_user_can('update_plugins')) {
            $problems[] = 'Für Updates braucht das Konto in WordPress das Recht, Plugins zu aktualisieren.';
        }
        if (dirname(self::plugin()) !== self::SLUG) {
            $problems[] = 'Das Plugin liegt im Ordner „' . dirname(self::plugin()) . '“ statt „' . self::SLUG . '“. Bitte einmal von Hand aktualisieren (Plugins → Installieren → Plugin hochladen).';
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        // Direkt schreiben, oder FTP mit Zugangsdaten in wp-config.php (dann fragt WordPress nicht nach).
        $ftpReady = defined('FTP_HOST') && defined('FTP_USER') && (defined('FTP_PASS') || defined('FTP_PRIKEY'));
        if (get_filesystem_method([], WP_PLUGIN_DIR) !== 'direct' && !$ftpReady) {
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
        try {
            $result = $upgrader->run([
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
        }
        // run() gibt den Fehler zurück; $upgrader->result ist nur bei Erfolg gefüllt.
        if (is_wp_error($result) || !$result) {
            $detail = is_wp_error($result) ? $result->get_error_message() : '';
            if (is_wp_error($result) && is_string($result->get_error_data()) && $result->get_error_data() !== '') {
                $detail .= ' (' . $result->get_error_data() . ')';
            }
            throw new \RuntimeException(trim('Das Plugin ließ sich nicht aktualisieren. ' . wp_strip_all_tags($detail)));
        }
        self::$installed = true;
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
