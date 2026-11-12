<?php

declare(strict_types=1);

namespace KompanieApp\WordPress;

/**
 * „App schneller laden“: Bei Aufrufen der App lädt WordPress nur dieses Plugin und die ausgewählten anderen Plugins.
 *
 * Jeder Aufruf der App startet WordPress samt allen aktiven Plugins, auch solchen, die die App nie braucht (Seitenbaukästen,
 * Statistik, Formulare …). Eine kleine Datei in wp-content/mu-plugins/ (Must-Use-Plugin, lädt vor allen anderen Plugins)
 * bindet diese Klasse ein; sie nimmt bei App-Aufrufen die übrigen Plugins aus der Liste der aktiven Plugins (nur für diesen
 * Aufruf, in der Datenbank ändert sich nichts). Webseite, wp-admin, Anmeldung und WP-Cron laufen unverändert.
 *
 * Die Datei in mu-plugins schreibt das Plugin selbst (install()) und entfernt sie beim Deaktivieren (remove()). Sie enthält
 * keine Logik, sondern lädt diese Klasse aus dem Plugin-Ordner: So kommt jede Änderung mit dem nächsten Update, und fehlt der
 * Plugin-Ordner, tut sie nichts.
 *
 * Diese Datei läuft vor dem Autoloader und vor allen Plugins: nur Funktionen von WordPress selbst, keine anderen Klassen.
 */
final class FastLoad
{
    /**
     * Einstellung (mit Autoload): on, plugin (eigener Pfad, z. B. kompanie-app/kompanie-app.php), keep (Liste), theme (ob das
     * Theme geladen wird), key.
     */
    public const OPTION = 'kompanie_app_fast_load';

    /** Wie WpEnvironment::NAMESPACE und AppPage::QUERY_VAR; hier ausgeschrieben, weil der Autoloader noch nicht läuft. */
    public const NAMESPACE = 'kompanie-app/v1';
    public const QUERY_VAR = 'kompanie_app';

    /** Name der Datei in wp-content/mu-plugins/. */
    public const MU_FILE = 'kompanie-app-schnell.php';

    /** Notschalter in wp-config.php: define('KOMPANIE_APP_SCHNELL', false); */
    public const CONSTANT = 'KOMPANIE_APP_SCHNELL';

    /** ?kompanie_app_alle=1: dieser Aufruf mit allen Plugins (zum Vergleichen; das ist ohnehin der Normalfall). */
    public const ALL = 'kompanie_app_alle';

    /** ?kompanie_app_schnell=<key>: dieser Aufruf schnell, auch wenn der Schalter aus ist (Messung vor dem Einschalten). */
    public const FORCE = 'kompanie_app_schnell';

    /** Antwort-Header, wenn ein Aufruf schnell lief (zum Nachprüfen, z. B. in den Entwicklerwerkzeugen des Browsers). */
    public const HEADER = 'X-Kompanie-App-Schnell';

    /** Erkennungszeichen der eigenen Datei (fremde Dateien gleichen Namens werden nie überschrieben oder gelöscht). */
    private const MARKER = '@kompanie-app-fast-load';

    /** Version des Inhalts der Datei in mu-plugins; bei Änderungen erhöhen, dann schreibt install() sie neu. */
    private const STUB_VERSION = 1;

    /**
     * Routen der App, die trotzdem alle Plugins brauchen: Neuigkeiten aus den Beiträgen der Webseite laufen durch die
     * Filter des Inhalts (the_content), in denen Blöcke und Shortcodes anderer Plugins stecken.
     */
    private const NEEDS_ALL_PLUGINS = '#^/news(/|$)#';

    /** @var list<string> Plugins, die in diesem Aufruf ausgelassen wurden (für die Anzeige, nur zur Laufzeit). */
    public static array $skipped = [];

    /**
     * Aus der Datei in mu-plugins aufgerufen, bevor WordPress die Plugins lädt.
     *
     * @param array<mixed> $option Inhalt von OPTION
     */
    public static function boot(array $option): void
    {
        $constant = defined(self::CONSTANT) ? (bool) constant(self::CONSTANT) : null;
        $context = defined('WP_ADMIN') && WP_ADMIN
            || defined('DOING_CRON') && DOING_CRON
            || defined('DOING_AJAX') && DOING_AJAX
            || defined('XMLRPC_REQUEST') && XMLRPC_REQUEST
            || defined('WP_INSTALLING') && WP_INSTALLING;
        $uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
        $home = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);
        $prefix = function_exists('rest_get_url_prefix') ? rest_get_url_prefix() : 'wp-json';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur lesend
        if (!self::applies($option, $uri, $_GET, $home, $prefix, $constant, $context)) {
            return;
        }
        $self = (string) $option['plugin'];
        $keep = self::keepList($option);
        add_filter('option_active_plugins', static function ($plugins) use ($self, $keep) {
            return is_array($plugins) ? self::filterPlugins($plugins, $self, $keep) : $plugins;
        }, 1);
        if (is_multisite()) {
            add_filter('site_option_active_sitewide_plugins', static function ($plugins) use ($self, $keep) {
                return is_array($plugins) ? self::filterNetworkPlugins($plugins, $self, $keep) : $plugins;
            }, 1);
        }
        if (!self::keepTheme($option)) {
            self::skipTheme();
        }
        if (!headers_sent()) {
            header(self::HEADER . ': 1');
        }
    }

    /** @param array<mixed> $option */
    public static function keepTheme(array $option): bool
    {
        // Ohne Angabe (ältere Einstellung): Theme laden.
        return !array_key_exists('theme', $option) || !empty($option['theme']);
    }

    /**
     * Das Theme (seine functions.php) nicht laden: WordPress lädt die functions.php aus den Pfaden, die es kurz nach
     * setup_theme festlegt (wp_templating_constants, wp_set_template_globals). Für diesen Moment zeigen die Pfade auf
     * einen Ordner, den es nicht gibt; danach gelten wieder die echten. Die App gibt keine Seiten des Themes aus.
     */
    private static function skipTheme(): void
    {
        $nowhere = static fn (): string => WP_CONTENT_DIR . '/kompanie-app-ohne-theme';
        add_action('setup_theme', static function () use ($nowhere): void {
            add_filter('template_directory', $nowhere, PHP_INT_MAX);
            add_filter('stylesheet_directory', $nowhere, PHP_INT_MAX);
        }, PHP_INT_MAX);
        add_action('after_setup_theme', static function () use ($nowhere): void {
            remove_filter('template_directory', $nowhere, PHP_INT_MAX);
            remove_filter('stylesheet_directory', $nowhere, PHP_INT_MAX);
            // Die Pfade für den Rest des Aufrufs wieder auf das echte Theme (nur die veralteten Konstanten TEMPLATEPATH und
            // STYLESHEETPATH bleiben auf dem Ordner, den es nicht gibt).
            $GLOBALS['wp_stylesheet_path'] = get_stylesheet_directory();
            $GLOBALS['wp_template_path'] = get_template_directory();
        }, PHP_INT_MIN);
    }

    /**
     * Ob dieser Aufruf schnell laufen soll (ohne Nebenwirkungen, damit testbar).
     *
     * @param array<mixed> $option   Inhalt von OPTION
     * @param array<mixed> $get      Parameter der Adresse ($_GET)
     * @param ?bool        $constant Wert von KOMPANIE_APP_SCHNELL, null wenn nicht gesetzt
     * @param bool         $context  wp-admin, WP-Cron, AJAX, XML-RPC oder Installation: nie
     */
    public static function applies(array $option, string $uri, array $get, string $home, string $restPrefix, ?bool $constant, bool $context): bool
    {
        if ($constant === false || $context || !isset($option['plugin']) || !is_string($option['plugin']) || $option['plugin'] === '') {
            return false;
        }
        if (isset($get[self::ALL]) || isset($get['doing_wp_cron'])) {
            return false;
        }
        $key = isset($option['key']) && is_string($option['key']) ? $option['key'] : '';
        $forced = $key !== '' && isset($get[self::FORCE]) && is_string($get[self::FORCE]) && hash_equals($key, $get[self::FORCE]);
        if (empty($option['on']) && !$forced) {
            return false;
        }
        return self::isAppRequest($uri, $get, $home, $restPrefix);
    }

    /**
     * Ob die Adresse zur App gehört: ihre Schnittstelle (/wp-json/kompanie-app/v1/… bzw. ?rest_route=/kompanie-app/v1/…)
     * oder die App-Seite mit ihren Dateien (/app/… bzw. ?kompanie_app=…). Neuigkeiten (NEEDS_ALL_PLUGINS) nicht.
     *
     * @param array<mixed> $get
     */
    public static function isAppRequest(string $uri, array $get, string $home, string $restPrefix = 'wp-json'): bool
    {
        $namespace = '#^/?' . preg_quote(self::NAMESPACE, '#') . '(/.*)?$#';
        if (isset($get['rest_route'])) {
            return is_string($get['rest_route']) && preg_match($namespace, $get['rest_route'], $m) === 1
                && !self::needsAllPlugins($m[1] ?? '');
        }
        if (isset($get[self::QUERY_VAR])) {
            return true;
        }
        $path = (string) wp_parse_url($uri, PHP_URL_PATH);
        $home = '/' . trim($home, '/');
        $home = $home === '/' ? '/' : $home . '/';
        if (!str_starts_with($path, $home)) {
            return false;
        }
        $rel = substr($path, strlen($home));
        // Ohne mod_rewrite: /index.php/wp-json/…
        if (str_starts_with($rel, 'index.php/')) {
            $rel = substr($rel, strlen('index.php/'));
        }
        $prefix = trim($restPrefix, '/');
        if ($prefix !== '' && str_starts_with($rel, $prefix . '/')) {
            return preg_match($namespace, substr($rel, strlen($prefix)), $m) === 1 && !self::needsAllPlugins($m[1] ?? '');
        }
        return $rel === 'app' || str_starts_with($rel, 'app/');
    }

    private static function needsAllPlugins(string $route): bool
    {
        return preg_match(self::NEEDS_ALL_PLUGINS, rawurldecode($route)) === 1;
    }

    /**
     * Aktive Plugins für einen App-Aufruf: dieses Plugin und die behaltenen. Ist dieses Plugin selbst nicht aktiv (etwa nach
     * dem Deaktivieren, falls die Datei in mu-plugins nicht entfernt werden konnte), bleibt alles, wie es ist.
     *
     * @param array<mixed>  $plugins Werte wie in active_plugins, z. B. akismet/akismet.php
     * @param list<string>  $keep
     * @return array<mixed>
     */
    public static function filterPlugins(array $plugins, string $self, array $keep): array
    {
        if (!in_array($self, $plugins, true) && !self::networkActive($self)) {
            return $plugins;
        }
        $out = [];
        foreach ($plugins as $plugin) {
            if ($plugin === $self || in_array($plugin, $keep, true)) {
                $out[] = $plugin;
            } elseif (is_string($plugin)) {
                self::$skipped[] = $plugin;
            }
        }
        return $out;
    }

    /**
     * Wie filterPlugins für die im Netzwerk aktivierten Plugins (Multisite; Schlüssel sind die Pfade).
     *
     * @param array<mixed> $plugins
     * @param list<string> $keep
     * @return array<mixed>
     */
    public static function filterNetworkPlugins(array $plugins, string $self, array $keep): array
    {
        if (!isset($plugins[$self]) && !in_array($self, (array) get_option('active_plugins', []), true)) {
            return $plugins;
        }
        foreach (array_keys($plugins) as $plugin) {
            if ($plugin !== $self && !in_array($plugin, $keep, true)) {
                unset($plugins[$plugin]);
                self::$skipped[] = (string) $plugin;
            }
        }
        return $plugins;
    }

    /** Ob das Plugin im Netzwerk aktiviert ist (Multisite), ohne den eigenen Filter. */
    private static function networkActive(string $plugin): bool
    {
        if (!is_multisite()) {
            return false;
        }
        $network = get_site_option('active_sitewide_plugins', []);
        return is_array($network) && isset($network[$plugin]);
    }

    /**
     * @param array<mixed> $option
     * @return list<string>
     */
    private static function keepList(array $option): array
    {
        $keep = isset($option['keep']) && is_array($option['keep']) ? $option['keep'] : [];
        return array_values(array_filter($keep, 'is_string'));
    }

    // --- Verwaltung (aus dem geladenen Plugin) ---

    /**
     * Gespeicherte Einstellung mit Vorgaben: aus, keine behaltenen Plugins, Theme laden, eigener Pfad, Schlüssel für die
     * Messung.
     *
     * @return array{on: bool, plugin: string, keep: list<string>, theme: bool, key: string}
     */
    public static function option(): array
    {
        $o = get_option(self::OPTION);
        $o = is_array($o) ? $o : [];
        return [
            'on' => !empty($o['on']),
            'plugin' => plugin_basename(KOMPANIE_APP_FILE),
            'keep' => self::keepList($o),
            'theme' => self::keepTheme($o),
            'key' => isset($o['key']) && is_string($o['key']) && strlen($o['key']) >= 32 ? $o['key'] : bin2hex(random_bytes(16)),
        ];
    }

    /**
     * Schalter, behaltene Plugins und Theme speichern (der eigene Pfad und der Schlüssel werden ergänzt).
     *
     * @param list<string> $keep
     */
    public static function save(bool $on, array $keep, bool $theme = true): void
    {
        $o = self::option();
        $o['on'] = $on;
        $o['theme'] = $theme;
        $o['keep'] = array_values(array_unique(array_filter($keep, static fn ($p): bool => is_string($p) && $p !== '' && $p !== $o['plugin'])));
        update_option(self::OPTION, $o, true);
    }

    /** Pfad der Datei in mu-plugins. */
    public static function muPath(): string
    {
        return WPMU_PLUGIN_DIR . '/' . self::MU_FILE;
    }

    /** Inhalt der Datei in mu-plugins. */
    public static function stub(): string
    {
        $option = var_export(self::OPTION, true);
        $marker = self::MARKER;
        $version = self::STUB_VERSION;
        return <<<PHP
<?php
/**
 * Plugin Name: Kompanie-App – App schneller laden
 * Description: Lädt bei Aufrufen der Kompanie-App nur die Plugins, die die App braucht. Wird vom Plugin Kompanie-App angelegt und beim Deaktivieren entfernt; einstellen unter Einstellungen → … – App → App schneller laden. Notschalter in wp-config.php: define('KOMPANIE_APP_SCHNELL', false);
 *
 * $marker $version
 */

defined('ABSPATH') || exit;

if (PHP_VERSION_ID >= 80100) {
	\$kompanie_app_fast = get_option($option);
	if (is_array(\$kompanie_app_fast) && isset(\$kompanie_app_fast['plugin']) && is_string(\$kompanie_app_fast['plugin'])
		&& preg_match('#^[A-Za-z0-9_-][A-Za-z0-9._-]*/[A-Za-z0-9._-]+\\.php\$#', \$kompanie_app_fast['plugin'])) {
		\$kompanie_app_file = WP_PLUGIN_DIR . '/' . dirname(\$kompanie_app_fast['plugin']) . '/includes/FastLoad.php';
		if (is_file(\$kompanie_app_file)) {
			require_once \$kompanie_app_file;
			\KompanieApp\WordPress\FastLoad::boot(\$kompanie_app_fast);
		}
	}
	unset(\$kompanie_app_fast, \$kompanie_app_file);
}

PHP;
    }

    /** Ob in mu-plugins die aktuelle eigene Datei liegt. */
    public static function installed(): bool
    {
        return is_file(self::muPath()) && (string) @file_get_contents(self::muPath()) === self::stub();
    }

    /** Ob die Datei in mu-plugins von diesem Plugin stammt (gleich welche Fassung). */
    private static function ours(): bool
    {
        return str_contains((string) @file_get_contents(self::muPath()), self::MARKER);
    }

    /**
     * Datei in mu-plugins anlegen bzw. auf den aktuellen Stand bringen und die Einstellung ergänzen (beim Aktivieren, nach
     * Updates, beim Öffnen und Speichern der Einstellungen). Eine fremde Datei gleichen Namens bleibt unangetastet.
     *
     * @return bool ob die aktuelle Datei jetzt dort liegt
     */
    public static function install(): bool
    {
        if (!is_array(get_option(self::OPTION))) {
            add_option(self::OPTION, self::option(), '', true);
        } elseif (get_option(self::OPTION) !== self::option()) {
            // Plugin-Ordner umbenannt oder Schlüssel fehlte.
            update_option(self::OPTION, self::option(), true);
        }
        if (self::installed()) {
            return true;
        }
        if (is_file(self::muPath()) && !self::ours()) {
            return false;
        }
        if (!wp_is_file_mod_allowed('kompanie_app_fast_load')) {
            return false;
        }
        if (!is_dir(WPMU_PLUGIN_DIR) && !wp_mkdir_p(WPMU_PLUGIN_DIR)) {
            return false;
        }
        // Erst vollständig in eine Nachbardatei, dann umbenennen: Ein gleichzeitiger Aufruf liest nie eine halbe Datei.
        $tmp = self::muPath() . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, self::stub()) === false) {
            return false;
        }
        if (!@rename($tmp, self::muPath())) {
            @unlink($tmp);
            return false;
        }
        if (function_exists('wp_opcache_invalidate')) {
            wp_opcache_invalidate(self::muPath(), true);
        }
        return self::installed();
    }

    /** Beim Deaktivieren: eigene Datei aus mu-plugins entfernen (die Einstellung bleibt für ein erneutes Aktivieren). */
    public static function remove(): void
    {
        if (is_file(self::muPath()) && self::ours()) {
            @unlink(self::muPath());
        }
    }

    /**
     * Andere aktive Plugins mit Namen (für die Auswahl), auch im Netzwerk aktivierte.
     *
     * @return array<string, string> Pfad => Name
     */
    public static function otherPlugins(): array
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $all = get_plugins();
        $self = plugin_basename(KOMPANIE_APP_FILE);
        $active = (array) get_option('active_plugins', []);
        if (is_multisite()) {
            $active = array_merge($active, array_keys((array) get_site_option('active_sitewide_plugins', [])));
        }
        $out = [];
        foreach (array_unique($active) as $plugin) {
            if (is_string($plugin) && $plugin !== $self) {
                $out[$plugin] = isset($all[$plugin]['Name']) && $all[$plugin]['Name'] !== '' ? (string) $all[$plugin]['Name'] : $plugin;
            }
        }
        asort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    /**
     * Misst Aufrufe der App-Schnittstelle (config) abwechselnd mit allen Plugins und schnell, über eine Anfrage an die
     * eigene Seite.
     *
     * @return array{all: list<float>, fast: list<float>, error: string, checked: bool} Sekunden je Aufruf; checked: ob die
     *         schnellen Aufrufe den Header trugen, also wirklich ohne die anderen Plugins liefen
     */
    public static function measure(int $rounds = 3): array
    {
        $o = self::option();
        $url = rest_url(self::NAMESPACE . '/config');
        $result = ['all' => [], 'fast' => [], 'error' => '', 'checked' => true];
        for ($i = 0; $i < $rounds; $i++) {
            foreach ($i % 2 === 0 ? ['all', 'fast'] : ['fast', 'all'] as $kind) {
                $args = [$kind === 'all' ? self::ALL : self::FORCE => $kind === 'all' ? '1' : $o['key'], '_' => (string) wp_rand()];
                $start = microtime(true);
                $res = wp_remote_get(add_query_arg($args, $url), ['timeout' => 20, 'redirection' => 0, 'headers' => ['Cache-Control' => 'no-cache']]);
                $seconds = microtime(true) - $start;
                if (is_wp_error($res)) {
                    $result['error'] = 'Die Seite ließ sich vom Server aus nicht aufrufen: ' . $res->get_error_message();
                    return $result;
                }
                if (wp_remote_retrieve_response_code($res) !== 200) {
                    $result['error'] = 'Die Schnittstelle der App antwortete mit Status ' . wp_remote_retrieve_response_code($res) . '.';
                    return $result;
                }
                if ($kind === 'fast' && wp_remote_retrieve_header($res, self::HEADER) !== '1') {
                    $result['checked'] = false;
                }
                $result[$kind][] = $seconds;
            }
        }
        return $result;
    }

    /**
     * Median von Messungen (unempfindlich gegen einzelne Ausreißer).
     *
     * @param list<float> $values
     */
    public static function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $n = count($values);
        return $n % 2 === 1 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    }
}
