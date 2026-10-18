<?php

declare(strict_types=1);

namespace KompanieApp\Standalone;

use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Port\AuthContext;
use KompanieApp\Core\Port\Authenticator;

/**
 * Anmeldung der eigenständigen Version: Anmeldeformular in der App, eigene Sitzung.
 *
 * CSRF-Schutz: Das Token ist ein HMAC über den Hash des Sitzungsschlüssels. Es bleibt für die
 * ganze Sitzung gültig, kann nur mit dem Cookie neu geholt werden und ändert sich mit der Sitzung.
 * Zusätzlich (siehe verifyRequest): Änderungen nur mit JSON-Inhalt und nicht von fremden Seiten.
 */
final class SessionAuth implements Authenticator, AuthContext
{
    public const CSRF_HEADER = 'X-CSRF-Token';
    /** Kennzeichnet Geräte, auf denen sich ein Konto schon angemeldet hat (siehe LoginThrottle). */
    public const DEVICE_COOKIE = 'kompanie_device';
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    private User|null|false $user = false;

    public function __construct(
        private readonly Sessions $sessions,
        private readonly PdoUsers $users,
        private readonly LoginThrottle $throttle,
        private readonly HttpContext $http,
        private readonly StandaloneEnvironment $env,
        private readonly string $secret,
    ) {
    }

    public function currentUser(): ?User
    {
        if ($this->user === false) {
            $id = $this->sessions->userId();
            $this->user = $id !== null ? $this->users->find($id) : null;
        }
        return $this->user;
    }

    public function mode(): string
    {
        return 'form';
    }

    public function loginUrl(): ?string
    {
        return null;
    }

    public function logoutUrl(): ?string
    {
        return null;
    }

    public function csrfHeader(): string
    {
        return self::CSRF_HEADER;
    }

    public function csrfToken(): string
    {
        $hash = $this->currentUser() !== null ? $this->sessions->tokenHash() : null;
        return $hash === null ? '' : hash_hmac('sha256', 'csrf|' . $hash, $this->secret);
    }

    public function csrfRefreshUrl(): string
    {
        return $this->env->apiUrl('auth/csrf');
    }

    /**
     * Prüft eine API-Anfrage vor der Ausführung.
     *
     * @throws ApiError 415 ohne JSON, 403 csrf_invalid bei fremder Herkunft oder falschem Token
     */
    public function verifyRequest(): void
    {
        if (in_array($this->http->method(), self::SAFE_METHODS, true)) {
            return;
        }
        // HTML-Formulare fremder Seiten können kein application/json senden. Gäste (Anmeldung)
        // müssen es immer angeben; angemeldete Anfragen ohne Inhalt (z. B. Abmelden) dürfen ohne.
        $type = strtolower(trim(explode(';', (string) $this->http->header('Content-Type'))[0]));
        if (($type !== '' || $this->currentUser() === null) && $type !== 'application/json') {
            throw new ApiError(415, 'unsupported_media_type', 'Bitte Daten als JSON senden.');
        }
        // Browser melden, ob eine Anfrage von einer fremden Seite kommt.
        if (strtolower((string) $this->http->header('Sec-Fetch-Site')) === 'cross-site') {
            throw new ApiError(403, 'csrf_invalid', 'Anfrage von einer fremden Seite abgelehnt.');
        }
        $origin = $this->http->header('Origin');
        if ($origin !== null && $origin !== 'null' && !$this->sameOrigin($origin)) {
            throw new ApiError(403, 'csrf_invalid', 'Anfrage von einer fremden Seite abgelehnt.');
        }
        if ($origin === 'null') {
            throw new ApiError(403, 'csrf_invalid', 'Anfrage von einer fremden Seite abgelehnt.');
        }
        if ($this->currentUser() === null) {
            return; // Gäste ändern nichts außer der Anmeldung selbst.
        }
        $sent = (string) $this->http->header(self::CSRF_HEADER);
        if ($sent === '' || !hash_equals($this->csrfToken(), $sent)) {
            throw new ApiError(403, 'csrf_invalid', 'Die Sitzung wurde erneuert. Bitte noch einmal versuchen.');
        }
    }

    public function login(string $username, string $password, bool $remember): User
    {
        $ip = $this->http->clientIp();
        if ($username === '' || $password === '') {
            throw ApiError::invalid('Bitte Benutzername und Passwort eingeben.');
        }
        $row = $this->users->findForLogin($username);
        // Gezählt wird je Konto, gleich in welcher Schreibweise oder ob per Name oder E-Mail.
        $id = $row ? (int) $row['id'] : 0;
        $account = $row ? 'u' . $id : 'n' . mb_strtolower(trim($username));
        $knownDevice = $row !== null && $this->isKnownDevice($id);
        if (!$this->throttle->attempt($account, $ip, $knownDevice)) {
            throw new ApiError(429, 'too_many_attempts', 'Zu viele Fehlversuche. Bitte in 15 Minuten erneut versuchen.');
        }
        // Auch ohne Konto einen Hash gleicher Stärke prüfen, damit die Antwortzeit nichts verrät.
        $hash = $row ? (string) $row['password_hash'] : password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
        if (!password_verify($password, $hash) || !$row) {
            throw new ApiError(400, 'login_failed', 'Benutzername oder Passwort ist falsch.');
        }
        $this->throttle->success($account, $ip, $knownDevice);
        $this->users->rehashIfNeeded($id, $password, $hash);
        $this->users->touchLogin($id);
        // Nie eine bestehende Sitzung weiterverwenden (Session Fixation).
        $this->sessions->destroy();
        $this->sessions->start($id, $remember);
        $this->rememberDevice($id);
        $this->user = false;
        return $this->currentUser() ?? throw new \LogicException('Anmeldung fehlgeschlagen.');
    }

    private function isKnownDevice(int $userId): bool
    {
        $v = (string) $this->http->cookie(self::DEVICE_COOKIE);
        $parts = explode('.', $v);
        return count($parts) === 3 && $parts[0] === (string) $userId
            && hash_equals(hash_hmac('sha256', 'device|' . $parts[0] . '|' . $parts[1], $this->secret), $parts[2]);
    }

    private function rememberDevice(int $userId): void
    {
        if ($this->isKnownDevice($userId)) {
            return;
        }
        $nonce = bin2hex(random_bytes(8));
        $value = $userId . '.' . $nonce . '.' . hash_hmac('sha256', 'device|' . $userId . '|' . $nonce, $this->secret);
        $this->http->setCookie(self::DEVICE_COOKIE, $value, time() + Sessions::LIFETIME);
    }

    public function logout(): void
    {
        $this->sessions->destroy();
        $this->user = null;
    }

    /** setPassword() hat alle Sitzungen beendet; dieses Gerät bekommt eine neue. */
    public function afterPasswordChange(int $userId): void
    {
        $this->sessions->start($userId, $this->sessions->lastRemember() ?? true);
        $this->user = false;
    }

    private function sameOrigin(string $origin): bool
    {
        $o = parse_url($origin);
        $b = parse_url($this->env->appUrl());
        if (!is_array($o) || !is_array($b)) {
            return false;
        }
        $port = static fn (array $u): int => (int) ($u['port'] ?? (($u['scheme'] ?? '') === 'https' ? 443 : 80));
        return strtolower($o['scheme'] ?? '') === strtolower($b['scheme'] ?? '')
            && strtolower($o['host'] ?? '') === strtolower($b['host'] ?? '')
            && $port($o) === $port($b);
    }
}
