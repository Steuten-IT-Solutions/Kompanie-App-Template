<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\I18n\I18n;
use KompanieApp\Core\Storage\OAuthRepository;

/**
 * Anmeldung per OAuth 2.1 für KI-Assistenten (Plan „MCP-Server für die Kompanie-Apps“, Schritt 3). Die App ist zugleich
 * Anmeldeserver und geschützte Ressource (der MCP-Endpunkt, Mcp\McpServer); die Zugänge selbst stellt
 * AccessTokenService aus.
 *
 * Ablauf (Authorization Code mit PKCE S256, nur öffentliche Programme ohne Geheimnis):
 * 1. Das Programm findet über den 401 des MCP-Endpunkts (WWW-Authenticate resource_metadata) die Metadaten der Ressource
 *    (RFC 9728) und darüber die des Anmeldeservers (RFC 8414; Aussteller = Basisadresse der API).
 * 2. Es meldet sich an: mit der Adresse seines Metadaten-Dokuments als client_id (CIMD, empfohlen) oder per
 *    Registrierung (DCR, RFC 7591, veraltet, aber von manchen Programmen noch genutzt).
 * 3. GET /oauth/authorize prüft die Anfrage und leitet in die App weiter (#/ki-zugang/<Schlüssel>). Dort meldet sich die
 *    Person wie gewohnt an und stimmt zu (Umfang lesen oder lesen und ändern); die App leitet mit einem Code (60 Sekunden,
 *    einmal gültig) zum Programm zurück, mit iss (RFC 9207).
 * 4. POST /oauth/token tauscht Code und code_verifier gegen das Tokenpaar; refresh_token erneuert es (nur für dasselbe
 *    Programm).
 *
 * Geht nur, wenn die Adresse der API keinen Abfrageteil hat (WordPress mit Permalinks, eigenständig mit .htaccess): Ein
 * Aussteller mit ?… ist in OAuth nicht erlaubt.
 *
 * @phpstan-import-type OAuthClientRow from OAuthRepository
 */
final class OAuthService
{
    public const SCOPES = [AccessTokenService::READ, AccessTokenService::WRITE];
    /** Gültigkeit einer Anfrage bis zur Zustimmung (Sekunden) */
    public const REQUEST_TTL = 600;
    /** Gültigkeit des Codes nach der Zustimmung (Sekunden) */
    public const CODE_TTL = 60;
    /** Metadaten-Dokument eines Programms (CIMD) so lange nicht neu laden (Sekunden) */
    private const CIMD_TTL = 86400;
    private const CIMD_MAX_BYTES = 65536;
    /** Höchstzahl selbst registrierter Programme (DCR); unbenutzte fallen nach 30 Tagen weg */
    private const DCR_MAX = 200;
    private const DCR_UNUSED = 30 * 86400;
    private const NAME_MAX = 100;

    private ?OAuthRepository $repo = null;

    public function __construct(private readonly App $app)
    {
    }

    /** Ist Anmelden per OAuth möglich (Schalter an, Adresse der API ohne Abfrageteil)? */
    public function available(): bool
    {
        return $this->app->accessTokens()->enabled() && !str_contains($this->app->env->apiBase(), '?');
    }

    /** Aussteller: Basisadresse der API ohne abschließenden / */
    public function issuer(): string
    {
        return rtrim($this->app->env->apiBase(), '/');
    }

    /** Adresse des MCP-Endpunkts (Ressource nach RFC 8707) */
    public function resource(): string
    {
        return $this->app->env->apiBase() . 'mcp';
    }

    /** Adresse der Metadaten der Ressource (für WWW-Authenticate resource_metadata) */
    public function resourceMetadataUrl(): string
    {
        return $this->app->env->apiBase() . 'oauth/protected-resource';
    }

    /**
     * Route der API für eine Adresse unter /.well-known/ an der Wurzel der Domain (RFC 8414 und RFC 9728 hängen den Pfad
     * des Ausstellers bzw. der Ressource dort an), z. B. /.well-known/oauth-authorization-server/wp-json/kompanie-app/v1
     * → /.well-known/oauth-authorization-server. Die Adapter reichen solche Anfragen an die API weiter, soweit sie sie
     * bekommen (WordPress immer, eigenständig nur an der Wurzel der Domain). Null für andere Adressen.
     */
    public function wellKnownRoute(string $path): ?string
    {
        if (!str_starts_with($path, '/.well-known/')) {
            return null;
        }
        $issuerPath = rtrim((string) parse_url($this->issuer(), PHP_URL_PATH), '/');
        $resourcePath = (string) parse_url($this->resource(), PHP_URL_PATH);
        $map = [
            '/.well-known/oauth-authorization-server' . $issuerPath => '/.well-known/oauth-authorization-server',
            '/.well-known/openid-configuration' . $issuerPath => '/.well-known/openid-configuration',
            '/.well-known/oauth-protected-resource' . $resourcePath => '/.well-known/oauth-protected-resource',
        ];
        return $map[rtrim($path, '/')] ?? null;
    }

    /** Header WWW-Authenticate für eine Anfrage an den MCP-Endpunkt ohne (gültiges) Token */
    public function challenge(bool $invalidToken): string
    {
        $parts = $invalidToken ? ['error="invalid_token"'] : [];
        if ($this->available()) {
            $parts[] = 'resource_metadata="' . $this->resourceMetadataUrl() . '"';
        }
        return 'Bearer' . ($parts !== [] ? ' ' . implode(', ', $parts) : '');
    }

    /** Metadaten der Ressource (RFC 9728) */
    public function protectedResource(): Response
    {
        $this->requireAvailable();
        return self::json([
            'resource' => $this->resource(),
            'authorization_servers' => [$this->issuer()],
            'scopes_supported' => self::SCOPES,
            'bearer_methods_supported' => ['header'],
            'resource_name' => $this->app->config->name(),
        ]);
    }

    /** Metadaten des Anmeldeservers (RFC 8414; auch unter openid-configuration, wo Programme danach suchen) */
    public function serverMetadata(): Response
    {
        $this->requireAvailable();
        $base = $this->app->env->apiBase();
        return self::json([
            'issuer' => $this->issuer(),
            'authorization_endpoint' => $base . 'oauth/authorize',
            'token_endpoint' => $base . 'oauth/token',
            'registration_endpoint' => $base . 'oauth/register',
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => self::SCOPES,
            'client_id_metadata_document_supported' => true,
            'authorization_response_iss_parameter_supported' => true,
        ]);
    }

    /**
     * GET /oauth/authorize: prüft die Anfrage und leitet zur Zustimmung in die App. Fehler bei Programm oder
     * Rücksprung-Adresse zeigt der Server selbst an (nie zu einer ungeprüften Adresse weiterleiten), andere gehen per
     * Weiterleitung an das Programm.
     *
     * @param array<string, mixed> $q
     */
    public function authorize(array $q): Response
    {
        $this->requireAvailable();
        $clientId = self::param($q, 'client_id');
        $client = $clientId !== '' ? $this->client($clientId) : null;
        if ($client === null) {
            throw new ApiError(400, 'invalid_client', I18n::t('Unbekanntes Programm. Bitte die Verbindung im KI-Assistenten neu einrichten.'));
        }
        $redirect = self::param($q, 'redirect_uri');
        if ($redirect === '' && count($client['redirect_uris']) === 1) {
            $redirect = $client['redirect_uris'][0];
        }
        if (!in_array($redirect, $client['redirect_uris'], true)) {
            throw new ApiError(400, 'invalid_redirect_uri', I18n::t('Die Rücksprung-Adresse passt nicht zum Programm.'));
        }
        $state = self::param($q, 'state');
        $fail = fn (string $error, string $description): Response => $this->redirectTo($redirect, ['error' => $error, 'error_description' => $description, 'state' => $state]);
        if (self::param($q, 'response_type') !== 'code') {
            return $fail('unsupported_response_type', 'Only response_type=code is supported.');
        }
        $challenge = self::param($q, 'code_challenge');
        if (self::param($q, 'code_challenge_method') !== 'S256' || preg_match('/^[A-Za-z0-9_-]{43,128}$/', $challenge) !== 1) {
            return $fail('invalid_request', 'PKCE with code_challenge_method=S256 is required.');
        }
        if (!$this->resourceMatches(self::param($q, 'resource'))) {
            return $fail('invalid_target', 'Unknown resource.');
        }
        $key = self::random('kaq_');
        $now = $this->now();
        $repo = $this->repo();
        $repo->deleteExpiredGrants($now);
        $repo->createGrant(hash('sha256', $key), $client['client_id'], $redirect, $challenge, mb_substr($state, 0, 1000), self::scopeFrom(self::param($q, 'scope')), $now + self::REQUEST_TTL);
        // Zur Zustimmung in die App (Anmeldung dort wie gewohnt); der Schlüssel steht nur im Fragment, nicht im Protokoll
        // des Webservers.
        return new Response(null, 302, ['Location' => $this->app->env->appUrl() . '#/ki-zugang/' . $key, 'Cache-Control' => 'no-store']);
    }

    /**
     * Anfrage zur Zustimmung (die App zeigt sie an): Programm, Rücksprung-Adresse, gewünschter Umfang.
     *
     * @return array{client: string, client_host: string, redirect_host: string, scope: string, installation: string}
     */
    public function pending(string $key, User $user): array
    {
        $this->requireAvailable();
        $this->requireMayUse($user);
        $grant = $this->openRequest($key);
        $client = $this->repo()->client($grant['client_id']);
        return [
            'client' => $client !== null ? $client['name'] : '',
            'client_host' => self::host($grant['client_id']),
            'redirect_host' => self::host($grant['redirect_uri']),
            'scope' => $grant['scope'] !== '' ? $grant['scope'] : AccessTokenService::WRITE,
            'installation' => $this->app->config->name(),
        ];
    }

    /**
     * Zustimmen oder ablehnen; die App leitet danach zur zurückgegebenen Adresse (zum Programm).
     *
     * @return array{redirect: string}
     */
    public function decide(string $key, User $user, mixed $approve, mixed $scope): array
    {
        $this->requireAvailable();
        if (!is_bool($approve)) {
            throw ApiError::invalid(I18n::t('Bitte zustimmen oder ablehnen.'));
        }
        $grant = $this->openRequest($key);
        if (!$approve) {
            $this->repo()->deleteGrant($grant['id']);
            return ['redirect' => $this->url($grant['redirect_uri'], ['error' => 'access_denied', 'error_description' => 'The user denied access.', 'state' => $grant['state']])];
        }
        $this->requireMayUse($user);
        if (!is_string($scope) || !in_array($scope, self::SCOPES, true)) {
            throw ApiError::invalid(I18n::t('Bitte wählen, ob der Zugang nur lesen oder auch ändern darf.'));
        }
        $code = self::random('kac_');
        if (!$this->repo()->approve($grant['id'], hash('sha256', $code), $user->id, $scope, $this->now() + self::CODE_TTL)) {
            throw ApiError::notFound(I18n::t('Diese Anfrage ist abgelaufen. Bitte im KI-Assistenten neu verbinden.'));
        }
        return ['redirect' => $this->url($grant['redirect_uri'], ['code' => $code, 'state' => $grant['state']])];
    }

    /**
     * POST /oauth/token (Formular): Code gegen Tokenpaar tauschen oder erneuern.
     *
     * @param array<string, mixed> $body
     */
    public function token(array $body): Response
    {
        if (!$this->available()) {
            return self::oauthError('invalid_grant', 'Access for AI assistants is switched off.');
        }
        $clientId = self::param($body, 'client_id');
        return match (self::param($body, 'grant_type')) {
            'authorization_code' => $this->exchange($body, $clientId),
            'refresh_token' => $this->refresh($body, $clientId),
            default => self::oauthError('unsupported_grant_type', 'Use authorization_code or refresh_token.'),
        };
    }

    /**
     * POST /oauth/register (RFC 7591, ohne Anmeldung): Programm mit Rücksprung-Adressen eintragen. Nur öffentliche
     * Programme (token_endpoint_auth_method none, so steht es in der Antwort).
     *
     * @param array<string, mixed> $body
     */
    public function register(array $body): Response
    {
        if (!$this->available()) {
            return self::oauthError('invalid_client_metadata', 'Access for AI assistants is switched off.');
        }
        $uris = self::redirectUris($body['redirect_uris'] ?? null);
        if ($uris === null) {
            return self::oauthError('invalid_redirect_uri', 'redirect_uris must list https addresses (http only for localhost).');
        }
        $now = $this->now();
        $repo = $this->repo();
        $repo->deleteUnusedClients('dcr', $now - self::DCR_UNUSED);
        if ($repo->countClients('dcr') >= self::DCR_MAX) {
            return self::oauthError('invalid_client_metadata', 'Too many registered clients.', 429);
        }
        $name = Text::line(is_string($body['client_name'] ?? null) ? $body['client_name'] : '', self::NAME_MAX);
        $clientId = self::random('kcl_');
        $repo->saveClient($clientId, 'dcr', $name !== '' ? $name : self::host($uris[0]), $uris, $now);
        return self::json([
            'client_id' => $clientId,
            'client_id_issued_at' => $now,
            'client_name' => $name,
            'redirect_uris' => $uris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ], 201);
    }

    /** @param array<string, mixed> $body */
    private function exchange(array $body, string $clientId): Response
    {
        $code = self::param($body, 'code');
        $grant = $code !== '' ? $this->repo()->code(hash('sha256', $code)) : null;
        // Ein Code gilt nur einmal, auch wenn zwei Anfragen gleichzeitig kommen
        if ($grant === null || !$this->repo()->deleteGrant($grant['id']) || $grant['expires_at'] < $this->now() || $grant['user_id'] === null) {
            return self::oauthError('invalid_grant', 'The code is invalid or expired.');
        }
        if ($clientId === '' || !hash_equals($grant['client_id'], $clientId)) {
            return self::oauthError('invalid_grant', 'The code was issued to another client.');
        }
        if (self::param($body, 'redirect_uri') !== $grant['redirect_uri']) {
            return self::oauthError('invalid_grant', 'redirect_uri does not match.');
        }
        $verifier = self::param($body, 'code_verifier');
        if (preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier) !== 1 || !hash_equals($grant['code_challenge'], self::base64url(hash('sha256', $verifier, true)))) {
            return self::oauthError('invalid_grant', 'code_verifier does not match.');
        }
        if (!$this->resourceMatches(self::param($body, 'resource'))) {
            return self::oauthError('invalid_target', 'Unknown resource.');
        }
        $client = $this->repo()->client($clientId);
        try {
            $issued = $this->app->accessTokens()->issue($grant['user_id'], $client !== null ? $client['name'] : self::host($clientId), $grant['scope'], $clientId);
        } catch (ApiError $e) {
            return self::oauthError('invalid_grant', $e->getMessage());
        }
        $this->repo()->touchClient($clientId, $this->now());
        return self::tokens($issued);
    }

    /** @param array<string, mixed> $body */
    private function refresh(array $body, string $clientId): Response
    {
        if ($clientId === '') {
            return self::oauthError('invalid_request', 'client_id is required.');
        }
        try {
            $issued = $this->app->accessTokens()->refresh(self::param($body, 'refresh_token'), $clientId);
        } catch (ApiError $e) {
            return self::oauthError('invalid_grant', $e->getMessage());
        }
        $this->repo()->touchClient($clientId, $this->now());
        return self::tokens($issued);
    }

    /**
     * Programm zur client_id: selbst registriert (DCR) oder mit Metadaten-Dokument unter dieser Adresse (CIMD), das
     * höchstens einmal am Tag neu geladen wird. Null, wenn es keines gibt oder das Dokument nicht passt.
     *
     * @return OAuthClientRow|null
     */
    private function client(string $clientId): ?array
    {
        $known = $this->repo()->client($clientId);
        if ($known !== null && ($known['kind'] === 'dcr' || ($known['fetched_at'] ?? 0) > $this->now() - self::CIMD_TTL)) {
            return $known;
        }
        if (!self::isMetadataUrl($clientId)) {
            return null;
        }
        $doc = $this->fetchMetadata($clientId);
        if ($doc === null) {
            // Nicht erreichbar: das zuletzt geladene Dokument gilt weiter
            return $known;
        }
        [$name, $uris] = $doc;
        $this->repo()->saveClient($clientId, 'cimd', $name, $uris, $this->now());
        return $this->repo()->client($clientId);
    }

    /**
     * Metadaten-Dokument laden und prüfen (client_id gleich der Adresse, Rücksprung-Adressen). Nur https und keine
     * Adressen im eigenen Netz (Schutz vor Anfragen an interne Dienste).
     *
     * @return array{0: string, 1: list<string>}|null
     */
    private function fetchMetadata(string $url): ?array
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (!self::publicHost($host)) {
            return null;
        }
        try {
            $res = $this->app->http->request('GET', $url, ['Accept' => 'application/json'], null, 5);
        } catch (\RuntimeException) {
            return null;
        }
        if ($res['status'] !== 200 || strlen($res['body']) > self::CIMD_MAX_BYTES) {
            return null;
        }
        $doc = json_decode($res['body'], true);
        if (!is_array($doc) || ($doc['client_id'] ?? null) !== $url) {
            return null;
        }
        $uris = self::redirectUris($doc['redirect_uris'] ?? null);
        if ($uris === null) {
            return null;
        }
        $name = Text::line(is_string($doc['client_name'] ?? null) ? $doc['client_name'] : '', self::NAME_MAX);
        return [$name !== '' ? $name : $host, $uris];
    }

    /** Adresse eines Metadaten-Dokuments: https mit Pfad, ohne Fragment und Zugangsdaten */
    private static function isMetadataUrl(string $url): bool
    {
        $p = parse_url($url);
        return is_array($p) && ($p['scheme'] ?? '') === 'https' && isset($p['host']) && ($p['path'] ?? '') !== '' && ($p['path'] ?? '') !== '/'
            && !isset($p['fragment']) && !isset($p['user']) && !isset($p['pass']) && strlen($url) <= 255;
    }

    /** Löst der Name nur zu öffentlichen Adressen auf (keine privaten, lokalen oder reservierten)? */
    private static function publicHost(string $host): bool
    {
        if ($host === '') {
            return false;
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : (gethostbynamel($host) ?: []);
        if ($ips === []) {
            return false;
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }
        return true;
    }

    /**
     * Rücksprung-Adressen: https (http nur für localhost und 127.0.0.1), ohne Fragment; höchstens 10.
     *
     * @return list<string>|null
     */
    private static function redirectUris(mixed $value): ?array
    {
        if (!is_array($value) || $value === [] || count($value) > 10 || !array_is_list($value)) {
            return null;
        }
        $out = [];
        foreach ($value as $uri) {
            if (!is_string($uri) || strlen($uri) > 500) {
                return null;
            }
            $p = parse_url($uri);
            $scheme = is_array($p) ? strtolower((string) ($p['scheme'] ?? '')) : '';
            $host = is_array($p) ? strtolower((string) ($p['host'] ?? '')) : '';
            $local = in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
            if ($host === '' || isset($p['fragment']) || !($scheme === 'https' || ($scheme === 'http' && $local))) {
                return null;
            }
            $out[] = $uri;
        }
        return array_values(array_unique($out));
    }

    private function resourceMatches(string $resource): bool
    {
        return $resource === '' || rtrim($resource, '/') === rtrim($this->resource(), '/');
    }

    /** @return array{id: int, client_id: string, redirect_uri: string, code_challenge: string, state: string, scope: string, user_id: int|null, expires_at: int} */
    private function openRequest(string $key): array
    {
        $grant = preg_match('/^kaq_[A-Za-z0-9_-]{43}$/', $key) === 1 ? $this->repo()->request(hash('sha256', $key)) : null;
        if ($grant === null || $grant['expires_at'] < $this->now()) {
            throw ApiError::notFound(I18n::t('Diese Anfrage ist abgelaufen. Bitte im KI-Assistenten neu verbinden.'));
        }
        return $grant;
    }

    private function requireAvailable(): void
    {
        if (!$this->available()) {
            throw ApiError::notFound(I18n::t('Der Zugang für KI-Assistenten ist in dieser App ausgeschaltet.'));
        }
    }

    private function requireMayUse(User $user): void
    {
        if (!$this->app->accessTokens()->mayUse($user)) {
            throw ApiError::forbidden(I18n::t('Dieses Konto kann keinen Zugang für KI-Assistenten bekommen.'));
        }
    }

    /** Umfang aus der Anfrage: write, wenn gewünscht, sonst read; leer ohne Angabe (die Person wählt). */
    private static function scopeFrom(string $scope): string
    {
        $parts = preg_split('/\s+/', trim($scope)) ?: [];
        return in_array(AccessTokenService::WRITE, $parts, true) ? AccessTokenService::WRITE : (in_array(AccessTokenService::READ, $parts, true) ? AccessTokenService::READ : '');
    }

    /** @param array<string, string> $params */
    private function url(string $redirect, array $params): string
    {
        $params = array_filter($params, static fn (string $v): bool => $v !== '') + ['iss' => $this->issuer()];
        return $redirect . (str_contains($redirect, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /** @param array<string, string> $params */
    private function redirectTo(string $redirect, array $params): Response
    {
        return new Response(null, 302, ['Location' => $this->url($redirect, $params), 'Cache-Control' => 'no-store']);
    }

    /** @param array{access_token: string, refresh_token: string, token_type: string, expires_in: int, scope: string, id: int} $issued */
    private static function tokens(array $issued): Response
    {
        unset($issued['id']);
        return self::json($issued);
    }

    private static function oauthError(string $error, string $description, int $status = 400): Response
    {
        return self::json(['error' => $error, 'error_description' => $description], $status);
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data, int $status = 200): Response
    {
        return new Response($data, $status, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    /** @param array<string, mixed> $source */
    private static function param(array $source, string $key): string
    {
        $v = $source[$key] ?? null;
        return is_string($v) ? $v : '';
    }

    private static function host(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST));
    }

    private static function random(string $prefix): string
    {
        return $prefix . self::base64url(random_bytes(32));
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function repo(): OAuthRepository
    {
        return $this->repo ??= new OAuthRepository($this->app->db);
    }

    private function now(): int
    {
        return $this->app->clock->now()->getTimestamp();
    }
}
