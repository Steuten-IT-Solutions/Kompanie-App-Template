<?php

declare(strict_types=1);

namespace KompanieApp\WordPress;

use KompanieApp\Core\App;
use KompanieApp\Core\Config\KompanieConfig;
use KompanieApp\Core\Domain\PollService;
use KompanieApp\Core\Infra\LocalFileStorage;
use KompanieApp\Core\Storage\UserData;

/** Setzt den Kern mit den WordPress-Umsetzungen zusammen und meldet die Hooks an. */
final class Plugin
{
    private static ?App $app = null;

    /** Die App wird erst beim ersten Zugriff erzeugt, also wenn WordPress geladen ist. */
    /** Daten einer Person in den Tabellen einer Seite des Netzwerks löschen (Multisite, Issue #24). */
    private static function purgeOnBlog(int $userId, int $blogId): void
    {
        try {
            global $wpdb;
            // Geheimnis der Umfragen dieser Seite: Damit lassen sich auch anonyme Antworten der Person löschen.
            $secret = get_blog_option($blogId, WpSettings::PREFIX . PollService::SECRET_SETTING);
            UserData::purge(new WpdbConnection($wpdb, $wpdb->get_blog_prefix($blogId) . 'kompanie_'), $userId, is_string($secret) && $secret !== '' ? $secret : null);
        } catch (\Throwable $e) {
            error_log('Kompanie-App: Daten des Kontos nicht entfernt: ' . $e->getMessage());
        }
    }

    public static function app(): App
    {
        if (self::$app === null) {
            global $wpdb;
            $env = new WpEnvironment();
            // Rechte je Rolle aus der App (Einstellungen → Rollen) mit den Ausnahmen der Person; erst beim ersten Benutzer abgefragt.
            $users = new WpUsers(
                static fn (string $role, int $id): array => self::app()->roles()->capabilitiesFor($role, $id),
                static fn (string $role): bool => self::app()->roles()->exists($role),
            );
            $settings = new WpSettings();
            $uploads = wp_upload_dir(null, false);
            $db = new WpdbConnection($wpdb, $wpdb->prefix . 'kompanie_');
            $config = self::config();
            self::$app = new App(
                db: $db,
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
                config: $config,
                updateTarget: new WpUpdateTarget(),
                backup: new WpBackup($db, KOMPANIE_APP_VERSION, $config->name()),
                packageConfig: self::packageConfig(),
            );
            self::$app->watchFatals();
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

    /**
     * update.json des Pakets: Adresse der Update-Liste, vertrauenswürdige Schlüssel, Statusmeldungen.
     * Fehlt sie (Entwicklung), gibt es keine Updates.
     *
     * @return array<string, mixed>
     */
    public static function packageConfig(): array
    {
        $data = json_decode((string) @file_get_contents(KOMPANIE_APP_DIR . 'update.json'), true);
        return is_array($data) ? $data : [];
    }

    public static function boot(): void
    {
        // Erst auf init: Dann hat auch das Theme seine Filter (z. B. upload_dir) gesetzt.
        add_action('init', [self::class, 'upgrade'], 1);
        add_action('rest_api_init', static fn () => RestBridge::register(self::app()));
        // Nach einem Update im selben Aufruf keine weiteren Aufgaben: Im Speicher ist noch der alte Code.
        add_filter('cron_schedules', [WpScheduler::class, 'schedules']);
        add_action(WpScheduler::HOOK, static fn (string $task) => WpUpdateTarget::$installed ? null : self::app()->runTask($task));
        // Multisite: aus einer Seite entfernt bzw. im Netzwerk gelöscht – die Daten jeder betroffenen Seite löschen
        // (die Tabellen der App gibt es je Seite).
        add_action('remove_user_from_blog', static fn (int $id, int $blogId) => self::purgeOnBlog($id, $blogId), 10, 2);
        add_action('wpmu_delete_user', static function (int $id): void {
            foreach (array_keys(get_blogs_of_user($id)) as $blogId) {
                self::purgeOnBlog($id, (int) $blogId);
            }
        });
        // Alle eigenen Daten eines gelöschten Kontos entfernen (Issue #24).
        add_action('deleted_user', static function (int $id): void {
            try {
                self::app()->userDeleted($id);
            } catch (\Throwable $e) {
                // Das Löschen des Benutzers darf daran nicht scheitern.
                error_log('Kompanie-App: Daten des gelöschten Kontos nicht entfernt: ' . $e->getMessage());
            }
        });
        // Aufgaben werden nicht bei jedem Seitenaufruf eingeplant, sondern nach einem Update (App::upgrade),
        // beim Aktivieren, beim Speichern von Einstellungen und beim Öffnen der Einstellungsseite.
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
        $updated = get_option(WpSettings::PREFIX . 'roles_version') !== KOMPANIE_APP_VERSION;
        if ($updated) {
            // Vor dem ersten Lesen: WpSettings::get() kennt nur Einstellungen mit Autoload (sonst z. B. kein Schema-Stand).
            self::autoloadOptions();
        }
        $app->upgrade();
        if ($updated) {
            // Frühere WP-Rollen Verwalter/Leitung nur, wo die App diese Rollen noch hat (ältere Installationen).
            Roles::install(
                ['member' => $app->roles()->label('member'), 'organizer' => $app->roles()->label('organizer'), 'manager' => $app->roles()->label('manager')],
                $app->roles()->exists('organizer'),
                $app->roles()->exists('manager'),
            );
            Roles::migrateUsers(static fn (string $role): bool => $app->roles()->exists($role));
            update_option(WpSettings::PREFIX . 'roles_version', KOMPANIE_APP_VERSION, true);
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
        // Beim Deaktivieren wurden die Aufgaben ausgeplant.
        self::app()->scheduleTasks();
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
