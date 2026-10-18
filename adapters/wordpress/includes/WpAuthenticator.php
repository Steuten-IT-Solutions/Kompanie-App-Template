<?php

declare(strict_types=1);

namespace KompanieApp\WordPress;

use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Port\Authenticator;

/**
 * Anmeldung über WordPress. Standard ist die Weiterleitung zu wp-login.php, damit
 * Login-Schutz- und Zwei-Faktor-Plugins der Webseite weiter greifen.
 * CSRF-Schutz ist der REST-Nonce von WordPress im Header X-WP-Nonce.
 */
final class WpAuthenticator implements Authenticator
{
    public function __construct(private readonly WpEnvironment $env)
    {
    }

    public function mode(): string
    {
        return 'redirect';
    }

    public function loginUrl(): ?string
    {
        return wp_login_url($this->env->appUrl());
    }

    public function logoutUrl(): ?string
    {
        // wp_logout_url() liefert eine HTML-maskierte Adresse (&amp;); für JSON und JavaScript entschlüsseln.
        return is_user_logged_in() ? html_entity_decode(wp_logout_url($this->env->appUrl()), ENT_QUOTES, 'UTF-8') : null;
    }

    public function csrfHeader(): string
    {
        return 'X-WP-Nonce';
    }

    public function csrfToken(): string
    {
        return is_user_logged_in() ? wp_create_nonce('wp_rest') : '';
    }

    /**
     * Die REST-API von WordPress behandelt eine Anfrage ohne gültigen Nonce als nicht
     * angemeldet. Ein neues Token gibt es deshalb über die App-Seite (nur Cookie).
     */
    public function csrfRefreshUrl(): string
    {
        return $this->env->appUrl('csrf');
    }

    /**
     * Bei WordPress meldet man sich über wp-login.php an. Eine Anmeldung über die API
     * würde Login-Schutz- und Zwei-Faktor-Plugins umgehen und wäre ohne Nonce von
     * fremden Seiten auslösbar (Login-CSRF).
     */
    public function login(string $username, string $password, bool $remember): User
    {
        throw new ApiError(400, 'use_login_page', 'Bitte über die Anmeldeseite der Webseite anmelden.');
    }

    public function logout(): void
    {
        wp_logout();
    }

    /** Ein neues Passwort beendet alle Sitzungen; dieses Gerät bleibt angemeldet. */
    public function afterPasswordChange(int $userId): void
    {
        if (!headers_sent()) {
            Session::withCookieSync(static fn () => wp_set_auth_cookie($userId, true, is_ssl()));
        }
    }
}
