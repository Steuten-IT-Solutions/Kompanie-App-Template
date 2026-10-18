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
    public function __construct(private readonly WpUsers $users, private readonly WpEnvironment $env)
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
        return is_user_logged_in() ? wp_logout_url($this->env->appUrl()) : null;
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
        return $this->env->appUrl() . 'csrf';
    }

    public function login(string $username, string $password, bool $remember): User
    {
        $user = Session::withCookieSync(static fn () => wp_signon([
            'user_login' => $username,
            'user_password' => $password,
            'remember' => $remember,
        ], is_ssl()));
        if (is_wp_error($user)) {
            throw ApiError::invalid('Benutzername oder Passwort ist falsch.');
        }
        wp_set_current_user($user->ID);
        return $this->users->currentUser() ?? throw ApiError::notLoggedIn();
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
