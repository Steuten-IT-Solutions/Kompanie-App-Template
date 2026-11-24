<?php

declare(strict_types=1);

namespace KompanieApp\WordPress;

use KompanieApp\Core\App;
use KompanieApp\Core\Domain\RequestLogService;

/**
 * Anfragen-Protokoll unter WordPress (RequestLogService): am Ende jeder PHP-Anfrage, bei der das Plugin geladen ist, eine
 * Zeile, wenn ein Admin es eingeschaltet hat. Nicht dabei: was der Webserver ohne PHP ausliefert (Dateien, Seiten aus
 * einem Seitencache wie Jetpack Boost) und WP-CLI.
 */
final class WpRequestLog
{
    /** Anfang der Adressen der App-Schnittstelle (WpEnvironment::NAMESPACE ohne Version) */
    private const NS = 'kompanie-app/';

    /** Action shutdown, vor dem Verschicken nach der Antwort (WpScheduler::runAfterResponse), damit die Dauer stimmt. */
    public static function register(callable $app): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        add_action('shutdown', static function () use ($app): void {
            try {
                $a = $app();
                if ($a instanceof App && $a->requestLog()->active()) {
                    $a->requestLog()->record(self::entry());
                }
            } catch (\Throwable) {
                // Das Protokoll darf keine Anfrage stören.
            }
        }, PHP_INT_MAX - 1, 0);
    }

    /** @return array{at: float, kind: string, method: string, uri: string, ms: int, status: int, auth: bool, agent: string, ip: string} */
    private static function entry(): array
    {
        $server = $_SERVER;
        $uri = is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '/';
        $start = is_numeric($server['REQUEST_TIME_FLOAT'] ?? null) ? (float) $server['REQUEST_TIME_FLOAT'] : microtime(true);
        $status = http_response_code();
        return [
            'at' => $start,
            'kind' => self::kind(
                $uri,
                (string) wp_parse_url(home_url('/'), PHP_URL_PATH),
                function_exists('rest_get_url_prefix') ? rest_get_url_prefix() : 'wp-json',
                wp_doing_cron(),
                is_admin() && !wp_doing_ajax(),
            ),
            'method' => is_string($server['REQUEST_METHOD'] ?? null) ? $server['REQUEST_METHOD'] : 'GET',
            'uri' => $uri,
            'ms' => (int) round((microtime(true) - $start) * 1000),
            'status' => is_int($status) ? $status : 0,
            'auth' => is_user_logged_in(),
            'agent' => is_string($server['HTTP_USER_AGENT'] ?? null) ? $server['HTTP_USER_AGENT'] : '',
            'ip' => is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : '',
        ];
    }

    /**
     * Art der Anfrage aus der Adresse (ohne Nebenwirkungen, damit testbar): App-Schnittstelle, App-Seite und ihre Dateien,
     * WP-Cron, wp-admin (auch admin-ajax), Anmeldung, andere REST-Schnittstellen, Seite.
     */
    public static function kind(string $uri, string $home, string $restPrefix, bool $cron, bool $admin): string
    {
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');
        $query = (string) (parse_url($uri, PHP_URL_QUERY) ?? '');
        parse_str($query, $get);
        $home = rtrim($home, '/');
        $rel = $home !== '' && str_starts_with($path, $home) ? substr($path, strlen($home)) : $path;
        $route = is_string($get['rest_route'] ?? null) ? $get['rest_route'] : null;
        if ($cron || str_ends_with($rel, '/wp-cron.php')) {
            return 'cron';
        }
        if (str_starts_with($rel, '/' . $restPrefix . '/' . self::NS) || ($route !== null && str_starts_with($route, '/' . self::NS))) {
            return 'app-api';
        }
        if ($rel === '/app' || str_starts_with($rel, '/app/') || isset($get[AppPage::QUERY_VAR])) {
            return 'app';
        }
        if (str_ends_with($rel, '/wp-login.php')) {
            return 'login';
        }
        if ($admin || str_starts_with($rel, '/wp-admin/') || $rel === '/wp-admin') {
            return 'admin';
        }
        if (str_starts_with($rel, '/' . $restPrefix . '/') || $rel === '/' . $restPrefix || $route !== null) {
            return 'rest';
        }
        if (str_ends_with($rel, '.php') && $rel !== '/index.php') {
            return 'other';
        }
        return 'page';
    }
}
