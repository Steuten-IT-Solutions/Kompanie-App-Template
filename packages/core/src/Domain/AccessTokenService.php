<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\I18n\I18n;
use KompanieApp\Core\Storage\AccessTokenRepository;

/**
 * Zugänge für KI-Assistenten (Plan „MCP-Server für die Kompanie-Apps“, Schritt 1): Eine Person gibt einem Programm (z. B.
 * Claude) Zugriff auf die App mit ihren eigenen Rechten. Je Zugang ein Zugriffstoken (gilt ACCESS_TTL Sekunden) und ein
 * Token zum Erneuern (gilt REFRESH_TTL Sekunden ab dem letzten Erneuern; Entscheidungen vom 22.11.2026: 1 Stunde und 30
 * Tage). Gespeichert werden nur SHA-256-Hashes; die Tokens selbst gibt es nur einmal beim Ausstellen.
 *
 * - Umfang read: nur lesen (Http\Api lehnt jede ändernde Anfrage ab, wie bei der Vorschau); write: lesen und ändern.
 *   Rechte sind immer die aktuellen der Person: Das Konto wird bei jeder Anfrage neu gelesen, nie aus dem Token.
 * - Schalter SETTING (Vorgabe aus): Ist er aus, gilt kein Zugang und es wird keiner ausgestellt; die Zugänge bleiben
 *   gespeichert und gelten nach dem Einschalten wieder.
 * - Kein Zugang für Konten ohne Zugang (Mitglied ohne Zugang) und für Ehemalige (sie können sich nicht anmelden).
 * - Ein neues Passwort (eigenes oder Startpasswort) beendet alle Zugänge der Person (revokeAllFor), wie ihre Sitzungen.
 * - Wer Zugänge bekommt (Entscheidung vom 22.11.2026): jede angemeldete Person, kein eigenes Recht.
 *
 * Ausgestellt werden Zugänge erst mit der Anmeldung per OAuth (späterer Schritt); bis dahin nur über issue().
 *
 * @phpstan-import-type AccessTokenRow from AccessTokenRepository
 */
final class AccessTokenService
{
    /** Einstellung: Zugang für KI-Assistenten an ('on') oder aus */
    public const SETTING = 'ai_access';
    public const READ = 'read';
    public const WRITE = 'write';
    public const SCOPES = [self::READ, self::WRITE];
    /** Gültigkeit des Zugriffstokens (Sekunden) */
    public const ACCESS_TTL = 3600;
    /** Gültigkeit des Tokens zum Erneuern ab dem letzten Erneuern (Sekunden) */
    public const REFRESH_TTL = 30 * 86400;
    /** Präfixe, damit ein Token in einem Protokoll oder einer Suche nach Geheimnissen erkennbar ist */
    public const ACCESS_PREFIX = 'kat_';
    public const REFRESH_PREFIX = 'kar_';
    /** „Zuletzt benutzt“ höchstens so oft schreiben (Sekunden) */
    private const TOUCH_EVERY = 60;
    private const CLIENT_MAX = 100;

    private ?AccessTokenRepository $repo = null;

    public function __construct(private readonly App $app)
    {
    }

    public function enabled(): bool
    {
        return $this->app->settings->get(self::SETTING, 'off') === 'on';
    }

    public function setEnabled(mixed $on): bool
    {
        if (!is_bool($on)) {
            throw ApiError::invalid(I18n::t('Bitte angeben, ob der Zugang für KI-Assistenten an sein soll.'));
        }
        $this->app->settings->set(self::SETTING, $on ? 'on' : 'off');
        return $on;
    }

    /**
     * Neuen Zugang ausstellen.
     *
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int, scope: string, id: int}
     */
    public function issue(int $userId, string $client, string $scope, string $clientId = ''): array
    {
        if (!$this->enabled()) {
            throw new ApiError(403, 'ai_access_disabled', I18n::t('Der Zugang für KI-Assistenten ist in dieser App ausgeschaltet.'));
        }
        if (!in_array($scope, self::SCOPES, true)) {
            throw ApiError::invalid(I18n::t('Unbekannter Umfang des Zugangs.'));
        }
        $user = $this->app->users->find($userId);
        if ($user === null || !$this->mayUse($user)) {
            throw ApiError::forbidden(I18n::t('Dieses Konto kann keinen Zugang für KI-Assistenten bekommen.'));
        }
        $now = $this->now();
        $this->repo()->deleteExpired($now);
        [$access, $refresh] = [self::token(self::ACCESS_PREFIX), self::token(self::REFRESH_PREFIX)];
        $id = $this->repo()->create($userId, Text::line($client, self::CLIENT_MAX), $scope, self::hash($access), self::hash($refresh), $now + self::ACCESS_TTL, $now + self::REFRESH_TTL, $now, $clientId);
        return self::grant($access, $refresh, $scope, $id);
    }

    /**
     * Neues Tokenpaar mit dem Token zum Erneuern; das alte Paar gilt danach nicht mehr.
     *
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int, scope: string, id: int}
     */
    public function refresh(string $refreshToken, ?string $clientId = null): array
    {
        $row = $this->enabled() && str_starts_with($refreshToken, self::REFRESH_PREFIX) ? $this->repo()->byRefreshHash(self::hash($refreshToken)) : null;
        $now = $this->now();
        // Mit $clientId (OAuth): nur das Programm, dem der Zugang ausgestellt wurde
        $wrongClient = $row !== null && $clientId !== null && $row['client_id'] !== '' && !hash_equals($row['client_id'], $clientId);
        if ($row === null || $wrongClient || $row['refresh_expires_at'] < $now || $this->userOf($row) === null) {
            throw new ApiError(400, 'invalid_grant', I18n::t('Der Zugang ist abgelaufen oder wurde widerrufen. Bitte neu verbinden.'));
        }
        [$access, $refresh] = [self::token(self::ACCESS_PREFIX), self::token(self::REFRESH_PREFIX)];
        $this->repo()->rotate($row['id'], self::hash($access), self::hash($refresh), $now + self::ACCESS_TTL, $now + self::REFRESH_TTL);
        return self::grant($access, $refresh, $row['scope'], $row['id']);
    }

    /**
     * Prüft ein Zugriffstoken: die Person (mit ihren aktuellen Rechten) und der Zugang; null, wenn es nicht (mehr) gilt.
     *
     * @return array{0: User, 1: AccessTokenRow}|null
     */
    public function authenticate(string $token): ?array
    {
        if (!$this->enabled() || !str_starts_with($token, self::ACCESS_PREFIX) || strlen($token) > 200) {
            return null;
        }
        $row = $this->repo()->byTokenHash(self::hash($token));
        $now = $this->now();
        if ($row === null || $row['expires_at'] < $now) {
            return null;
        }
        $user = $this->userOf($row);
        if ($user === null) {
            return null;
        }
        if ($row['last_used_at'] === null || $row['last_used_at'] <= $now - self::TOUCH_EVERY) {
            $this->repo()->touch($row['id'], $now);
        }
        return [$user, $row];
    }

    /**
     * Zugänge einer Person (Meins → Konto) oder aller (Verwaltung, mit Namen), neueste zuerst.
     *
     * @return list<array<string, mixed>>
     */
    public function list(?int $userId): array
    {
        $rows = $this->repo()->list($userId);
        $names = [];
        if ($userId === null) {
            foreach ($this->app->users->findMany(array_map(static fn (array $r): int => $r['user_id'], $rows)) as $id => $u) {
                $names[$id] = $u->name;
            }
        }
        $now = $this->now();
        return array_map(static fn (array $r): array => [
            'id' => $r['id'],
            'client' => $r['client'],
            'scope' => $r['scope'],
            'created_at' => $r['created_at'],
            'last_used_at' => $r['last_used_at'],
            // Bis dahin lässt sich der Zugang ohne neue Freigabe erneuern
            'valid_until' => $r['refresh_expires_at'],
            'expired' => $r['refresh_expires_at'] < $now,
        ] + ($userId === null ? ['user_id' => $r['user_id'], 'user_name' => $names[$r['user_id']] ?? ''] : []), $rows);
    }

    /** Zugang widerrufen; mit $ownerId nur einen eigenen. */
    public function revoke(int $id, ?int $ownerId = null): void
    {
        $row = $this->repo()->find($id);
        if ($row === null || ($ownerId !== null && $row['user_id'] !== $ownerId)) {
            throw ApiError::notFound(I18n::t('Diesen Zugang gibt es nicht.'));
        }
        $this->repo()->delete($id);
    }

    /** Alle Zugänge einer Person beenden (neues Passwort). */
    public function revokeAllFor(int $userId): void
    {
        $this->repo()->deleteForUser($userId);
    }

    /** Darf dieses Konto überhaupt einen Zugang haben? Nicht ohne Zugang, nicht als Ehemalige(r). */
    public function mayUse(User $user): bool
    {
        return !$this->app->members()->hasNoAccess($user->id) && $this->app->formerMembers()->previousRole($user) === null;
    }

    /** @param AccessTokenRow $row */
    private function userOf(array $row): ?User
    {
        $user = $this->app->users->find($row['user_id']);
        return $user !== null && $this->mayUse($user) ? $user : null;
    }

    /** @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int, scope: string, id: int} */
    private static function grant(string $access, string $refresh, string $scope, int $id): array
    {
        return ['access_token' => $access, 'refresh_token' => $refresh, 'token_type' => 'Bearer', 'expires_in' => self::ACCESS_TTL, 'scope' => $scope, 'id' => $id];
    }

    private static function token(string $prefix): string
    {
        return $prefix . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function now(): int
    {
        return $this->app->clock->now()->getTimestamp();
    }

    private function repo(): AccessTokenRepository
    {
        return $this->repo ??= new AccessTokenRepository($this->app->db);
    }
}
