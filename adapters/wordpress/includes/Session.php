<?php

declare(strict_types=1);

namespace KompanieApp\WordPress;

/**
 * „Angemeldet bleiben“ praktisch ohne Ablauf.
 *
 * WordPress hält eine Anmeldung mit „Angemeldet bleiben“ nur 14 Tage. Für die App:
 * - Laufzeit 400 Tage (mehr lassen Chrome und Edge für Cookies nicht zu),
 * - automatische Verlängerung beim Öffnen der App, wenn die Anmeldung älter als RENEW_AFTER ist.
 * Wer die App mindestens einmal im Jahr öffnet, bleibt angemeldet.
 */
final class Session
{
    public const LIFETIME = 400 * DAY_IN_SECONDS;
    public const RENEW_AFTER = 30 * DAY_IN_SECONDS;

    public static function init(): void
    {
        add_filter('auth_cookie_expiration', [self::class, 'expiration'], 10, 3);
        add_action('login_footer', [self::class, 'checkRememberMe']);
    }

    /** Nur Anmeldungen mit „Angemeldet bleiben“ werden verlängert, sonst bleibt es bei 2 Tagen. */
    public static function expiration(int $length, int $userId, bool $remember): int
    {
        return $remember ? self::LIFETIME : $length;
    }

    /**
     * Führt $fn aus und macht ein dabei gesetztes Anmelde-Cookie schon für den Rest
     * dieser Anfrage gültig (der REST-Nonce hängt am Sitzungstoken).
     */
    public static function withCookieSync(callable $fn): mixed
    {
        $sync = static function (string $cookie): void {
            $_COOKIE[LOGGED_IN_COOKIE] = $cookie;
        };
        add_action('set_logged_in_cookie', $sync);
        try {
            return $fn();
        } finally {
            remove_action('set_logged_in_cookie', $sync);
        }
    }

    /** Verlängert die Anmeldung beim Öffnen der App (vor jeder Ausgabe aufrufen). */
    public static function maybeRenew(?int $now = null): bool
    {
        $now ??= time();
        if (!is_user_logged_in() || headers_sent()) {
            return false;
        }
        $cookie = wp_parse_auth_cookie('', 'logged_in');
        if (!$cookie) {
            return false;
        }
        $remaining = (int) $cookie['expiration'] - $now;
        // Ohne „Angemeldet bleiben“ gilt die Anmeldung nur 2 Tage – die bleibt unverändert.
        if ($remaining <= 3 * DAY_IN_SECONDS || $remaining > self::LIFETIME - self::RENEW_AFTER) {
            return false;
        }
        $userId = get_current_user_id();
        $oldToken = $cookie['token'];
        self::withCookieSync(static fn () => wp_set_auth_cookie($userId, true, is_ssl()));
        if ($oldToken) {
            \WP_Session_Tokens::get_instance($userId)->destroy($oldToken);
        }
        return true;
    }

    /** Auf der Anmeldeseite für die App ist „Angemeldet bleiben“ schon angehakt. */
    public static function checkRememberMe(): void
    {
        $redirect = isset($_REQUEST['redirect_to']) ? (string) wp_unslash($_REQUEST['redirect_to']) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        if (!str_contains($redirect, '/app/') && !str_contains($redirect, AppPage::QUERY_VAR . '=')) {
            return;
        }
        echo "<script>(function(){var r=document.getElementById('rememberme');if(r){r.checked=true;}})();</script>\n";
    }
}
