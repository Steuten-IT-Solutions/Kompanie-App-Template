<?php

declare(strict_types=1);

namespace KompanieApp\WordPress;

use KompanieApp\Core\App;
use KompanieApp\Core\Config\KompanieConfig;
use KompanieApp\Core\Infra\LocalFileStorage;

/** Setzt den Kern mit den WordPress-Umsetzungen zusammen und meldet die Hooks an. */
final class Plugin
{
    private static ?App $app = null;

    /** Die App wird erst beim ersten Zugriff erzeugt, also wenn WordPress geladen ist. */
    public static function app(): App
    {
        if (self::$app === null) {
            global $wpdb;
            $env = new WpEnvironment();
            $users = new WpUsers();
            $settings = new WpSettings();
            $uploads = wp_upload_dir(null, false);
            self::$app = new App(
                db: new WpdbConnection($wpdb, $wpdb->prefix . 'kompanie_'),
                settings: $settings,
                cache: new WpCache(),
                http: new WpHttpClient(),
                files: new LocalFileStorage(
                    $uploads['basedir'] . '/kompanie-galerie',
                    $uploads['baseurl'] . '/kompanie-galerie',
                    $uploads['basedir'] . '/kompanie-galerie-intern',
                    get_temp_dir(),
                ),
                images: new WpImageResizer(),
                scheduler: new WpScheduler(),
                news: new WpNewsSource(),
                clock: new \KompanieApp\Core\Infra\SystemClock(wp_timezone()),
                env: $env,
                authContext: $users,
                authenticator: new WpAuthenticator($env),
                users: $users,
                config: self::config(),
            );
        }
        return self::$app;
    }

    /** kompanie.json im Plugin; während der Entwicklung das Beispiel aus dem Repo. */
    public static function config(): KompanieConfig
    {
        $file = KOMPANIE_APP_DIR . 'kompanie.json';
        if (!is_file($file)) {
            $file = dirname(KOMPANIE_APP_DIR, 2) . '/config/kompanie.example.json';
        }
        return KompanieConfig::fromFile($file);
    }

    public static function boot(): void
    {
        // Erst auf init: Dann hat auch das Theme seine Filter (z. B. upload_dir) gesetzt.
        add_action('init', [self::class, 'upgrade'], 1);
        add_action('rest_api_init', static fn () => RestBridge::register(self::app()));
        add_action(WpScheduler::HOOK, static fn (string $task) => self::app()->runTask($task));
        add_action('init', static fn () => self::app()->scheduleTasks(), 20);
        Session::init();
        AppPage::init();
        if (is_admin()) {
            SettingsPage::init();
            ProfileField::init();
        }
        add_action('init', static fn () => Shortcodes::init(self::app()));
    }

    /**
     * Läuft bei jedem Seitenaufruf, tut aber nur nach einem Update etwas. Ein Update
     * per ZIP-Upload löst keinen Aktivierungs-Hook aus, deshalb auch Rollen hier.
     */
    public static function upgrade(): void
    {
        $app = self::app();
        $app->upgrade();
        if (get_option(WpSettings::PREFIX . 'roles_version') !== KOMPANIE_APP_VERSION) {
            Roles::install($app->config->roleLabel('member'), $app->config->roleLabel('manager'));
            update_option(WpSettings::PREFIX . 'roles_version', KOMPANIE_APP_VERSION, true);
            self::autoloadOptions();
        }
    }

    /**
     * Ältere Versionen haben die Einstellungen ohne Autoload gespeichert. update_option()
     * ändert das nur zusammen mit dem Wert; deshalb einmal je Version direkt umstellen.
     * Große, selten gebrauchte Werte (Galerie-Status, Zugangsschlüssel) werden dabei ausgeschaltet.
     */
    private static function autoloadOptions(): void
    {
        global $wpdb;
        $names = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like(WpSettings::PREFIX) . '%',
        ));
        $off = array_map(static fn (string $k): string => WpSettings::PREFIX . $k, WpSettings::NO_AUTOLOAD);
        $values = [];
        foreach ($names as $name) {
            $values[$name] = !in_array($name, $off, true);
        }
        if ($values === []) {
            return;
        }
        if (function_exists('wp_set_option_autoload_values')) {
            wp_set_option_autoload_values($values);
            return;
        }
        foreach ($values as $name => $autoload) {
            $wpdb->update($wpdb->options, ['autoload' => $autoload ? 'yes' : 'no'], ['option_name' => $name]);
        }
        wp_cache_delete('alloptions', 'options');
    }

    public static function activate(): void
    {
        self::upgrade();
        AppPage::addRewriteRules();
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        foreach (array_keys(App::TASKS) as $task) {
            (new WpScheduler())->unschedule($task);
        }
        AppPage::removeRewriteRules();
        flush_rewrite_rules();
    }
}
