<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\Port\Cache;
use KompanieApp\Core\Port\ExtendedHttpClient;
use KompanieApp\Core\Port\FileStorage;
use KompanieApp\Core\Port\HttpClient;
use KompanieApp\Core\Port\StreamingHttpClient;
use KompanieApp\Core\Port\Settings;

/**
 * Lesender Zugriff auf Google Drive und, über denselben Zugang, auf den Google Kalender. Schreibend nur für die
 * Datensicherung (BackupUploadService): mit drive.file sieht und ändert die App nur Dateien, die sie selbst angelegt hat.
 *
 * Zwei Wege, in dieser Reihenfolge:
 *  1. „Mit Google verbinden“ (OAuth mit dem eigenen OAuth-Client der Installation): Ein Admin trägt Client-ID und
 *     Client-Secret ein und meldet sich einmal bei Google an. Gespeichert wird das Refresh-Token; daraus holt die App
 *     Zugriffstokens. Braucht keinen Dienstkontoschlüssel.
 *  2. Dienstkonto (JSON-Schlüssel, liegt nur auf dem Server): Der Galerie-Ordner muss in Google Drive für die
 *     E-Mail-Adresse des Dienstkontos freigegeben sein. Das Zugriffstoken wird per signiertem JWT (RS256) geholt.
 * Ohne Bibliothek.
 *
 * Den Kalender liest CalendarService mit accessToken(), wenn calendarAccess() zutrifft:
 * bei einer OAuth-Verbindung nur, wenn Google dabei auch calendar.readonly erlaubt hat
 * (gespeichert in `scope`); beim Dienstkonto immer (der Kalender muss für dessen
 * E-Mail-Adresse freigegeben sein, sonst lehnt Google den Abruf ab).
 *
 * Fehler werden als \RuntimeException mit verständlicher Meldung geworfen.
 */
final class DriveClient
{
    public const SETTING = 'drive_service_account';
    /** uploadFile(): Größe der Stücke (Vielfaches von 256 KB). */
    public const UPLOAD_CHUNK = 8 * 1024 * 1024;
    /**
     * Verbindung über OAuth: {client_id, client_secret, refresh_token, email, scope}. Entsteht über „Mit Google
     * verbinden“ (saveClient, createState, authorizeUrl, exchangeCode) oder durch die Übernahme einer früheren App
     * (saveOAuth, gleiches Format). `scope` sind die bei Google erlaubten Berechtigungen, durch Leerzeichen getrennt
     * (wie Google sie beim Token-Austausch liefert). Ohne refresh_token ist nur der Client gespeichert (nicht verbunden).
     * Eine Verbindung geht dem Dienstkonto vor.
     */
    public const OAUTH_SETTING = 'drive_oauth';
    /** Offene Anmeldungen bei Google: Benutzer-ID => {state (Hash), session (Hash), expires}. */
    public const STATE_SETTING = 'drive_oauth_state';
    /** Kennzeichen eines state dieser App (der WordPress-Adapter erkennt daran Rückkehrer von Google). */
    public const STATE_PREFIX = 'kompanie_app_';
    /** So lange (Sekunden) darf die Anmeldung bei Google dauern. */
    public const STATE_TTL = 900;
    public const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
    public const ABOUT_URL = 'https://www.googleapis.com/drive/v3/about';
    public const SCOPE = 'https://www.googleapis.com/auth/drive.readonly';
    /** Lesezugriff auf den Google Kalender (für CalendarService, über denselben Zugang). */
    public const CALENDAR_SCOPE = 'https://www.googleapis.com/auth/calendar.readonly';
    /** Eigene Dateien anlegen und ändern (nur von der App angelegte), für die Datensicherung; nur über „Mit Google verbinden“. */
    public const BACKUP_SCOPE = 'https://www.googleapis.com/auth/drive.file';
    public const TOKEN_URI = 'https://oauth2.googleapis.com/token';
    public const FILES_URL = 'https://www.googleapis.com/drive/v3/files';
    public const UPLOAD_URL = 'https://www.googleapis.com/upload/drive/v3/files';
    public const FOLDER_MIME = 'application/vnd.google-apps.folder';
    private const TOKEN_CACHE = 'drive_token';

    public function __construct(
        private readonly Settings $settings,
        private readonly HttpClient $http,
        private readonly Cache $cache,
        private readonly FileStorage $files,
        private readonly Time $time,
    ) {
    }

    /** @return array{client_email: string, private_key: string, token_uri?: string}|null */
    public function serviceAccount(): ?array
    {
        $data = json_decode((string) $this->settings->get(self::SETTING, ''), true);
        return is_array($data) && !empty($data['client_email']) && !empty($data['private_key']) ? $data : null;
    }

    public function clientEmail(): string
    {
        return $this->serviceAccount()['client_email'] ?? '';
    }

    /** Angeforderte Berechtigungen (OAuth und Dienstkonto): Drive für die Galerie, Kalender für die Termine (beide nur Lesen). */
    public static function scopes(): string
    {
        return self::SCOPE . ' ' . self::CALENDAR_SCOPE;
    }

    /**
     * Berechtigungen beim „Mit Google verbinden“: zusätzlich eigene Dateien für die Datensicherung (ein Dienstkonto hat
     * in Google Drive keinen eigenen Speicherplatz und bekommt sie deshalb nicht).
     */
    public static function oauthScopes(): string
    {
        return self::scopes() . ' ' . self::BACKUP_SCOPE;
    }

    /** @return array{client_id: string, client_secret: string, refresh_token: string, email: string, scope: string}|null */
    public function oauth(): ?array
    {
        $o = $this->settings->get(self::OAUTH_SETTING);
        if (!is_array($o)) {
            return null;
        }
        foreach (['client_id', 'client_secret', 'refresh_token'] as $k) {
            if (!is_string($o[$k] ?? null) || $o[$k] === '') {
                return null;
            }
        }
        return [
            'client_id' => $o['client_id'],
            'client_secret' => $o['client_secret'],
            'refresh_token' => $o['refresh_token'],
            'email' => is_string($o['email'] ?? null) ? $o['email'] : '',
            'scope' => is_string($o['scope'] ?? null) ? $o['scope'] : '',
        ];
    }

    /** Google-Konto der OAuth-Verbindung ('' ohne Verbindung oder wenn es unbekannt ist). */
    public function oauthEmail(): string
    {
        return $this->oauth()['email'] ?? '';
    }

    /**
     * Darf der aktive Zugang den Kalender lesen? Bei einer OAuth-Verbindung nur, wenn Google dabei
     * calendar.readonly erlaubt hat; Verbindungen ohne diese Berechtigung (oder ohne gespeicherte
     * Berechtigungen) müssen neu verbunden werden. Ein Dienstkonto fragt den Kalender immer mit an.
     */
    public function calendarAccess(): bool
    {
        $oauth = $this->oauth();
        if ($oauth !== null) {
            return in_array(self::CALENDAR_SCOPE, preg_split('/\s+/', trim($oauth['scope'])) ?: [], true);
        }
        return $this->serviceAccount() !== null;
    }

    /** Darf die App Sicherungen in Google Drive ablegen? Nur mit einer OAuth-Verbindung, bei der Google drive.file erlaubt hat. */
    public function backupAccess(): bool
    {
        $oauth = $this->oauth();
        return $oauth !== null && in_array(self::BACKUP_SCOPE, preg_split('/\s+/', trim($oauth['scope'])) ?: [], true);
    }

    /** Ist ein Google-Zugang hinterlegt (OAuth-Verbindung oder Dienstkonto)? */
    public function hasCredentials(): bool
    {
        return $this->oauth() !== null || $this->serviceAccount() !== null;
    }

    /**
     * Speichert eine bestehende OAuth-Verbindung.
     *
     * @param array<string, mixed> $o client_id, client_secret, refresh_token, email, scope (die beiden letzten optional)
     * @throws \RuntimeException wenn ein Pflichtfeld fehlt
     */
    public function saveOAuth(array $o): void
    {
        $clean = [];
        foreach (['client_id', 'client_secret', 'refresh_token', 'email', 'scope'] as $k) {
            $clean[$k] = is_string($o[$k] ?? null) ? trim($o[$k]) : '';
            if ($clean[$k] === '' && !in_array($k, ['email', 'scope'], true)) {
                throw new \RuntimeException('Für die Verbindung zu Google fehlt ' . $k . '.');
            }
        }
        $this->settings->set(self::OAUTH_SETTING, $clean);
        $this->cache->delete(self::TOKEN_CACHE);
    }

    /** Entfernt die OAuth-Verbindung samt Client ohne Widerruf bei Google (das Dienstkonto bleibt, falls hinterlegt). */
    public function removeOAuth(): void
    {
        $this->settings->delete(self::OAUTH_SETTING);
        $this->cache->delete(self::TOKEN_CACHE);
    }

    /* ------------------------------------------------------------------ */
    /* „Mit Google verbinden“                                              */
    /* ------------------------------------------------------------------ */

    /** @return array<string, string> gespeicherter Stand von drive_oauth, auch ohne Verbindung (nur Client) */
    private function storedOAuth(): array
    {
        $o = $this->settings->get(self::OAUTH_SETTING);
        $out = [];
        foreach (['client_id', 'client_secret', 'refresh_token', 'email', 'scope'] as $k) {
            $out[$k] = is_array($o) && is_string($o[$k] ?? null) ? $o[$k] : '';
        }
        return $out;
    }

    /** @return array{client_id: string, client_secret_set: bool} gespeicherter OAuth-Client (auch ohne Verbindung) */
    public function oauthClient(): array
    {
        $o = $this->storedOAuth();
        return ['client_id' => $o['client_id'], 'client_secret_set' => $o['client_secret'] !== ''];
    }

    /**
     * Speichert Client-ID und Client-Secret des eigenen OAuth-Clients. Ein leeres Secret behält das bisherige (nur
     * bei gleicher Client-ID). Ein anderer Client macht eine bestehende Verbindung ungültig: Refresh-Token, E-Mail
     * und die erlaubten Berechtigungen werden dann geleert.
     *
     * @throws \RuntimeException mit verständlicher Meldung
     */
    public function saveClient(string $clientId, string $clientSecret): void
    {
        $clientId = trim($clientId);
        $clientSecret = trim($clientSecret);
        $old = $this->storedOAuth();
        if (!preg_match('/^[0-9A-Za-z_.-]+\.apps\.googleusercontent\.com$/', $clientId) || strlen($clientId) > 200) {
            throw new \RuntimeException('Die Client-ID sieht nicht richtig aus. Sie endet auf „.apps.googleusercontent.com“.');
        }
        if ($clientSecret === '') {
            if ($old['client_secret'] === '' || $old['client_id'] !== $clientId) {
                throw new \RuntimeException('Bitte auch den Clientschlüssel (Client-Secret) eintragen.');
            }
            $clientSecret = $old['client_secret'];
        }
        if (strlen($clientSecret) > 200 || !preg_match('/^[\x21-\x7E]+$/', $clientSecret)) {
            throw new \RuntimeException('Der Clientschlüssel (Client-Secret) enthält ungültige Zeichen.');
        }
        $new = ['client_id' => $clientId, 'client_secret' => $clientSecret] + $old;
        if ($old['client_id'] !== $clientId || $old['client_secret'] !== $clientSecret) {
            $new = ['refresh_token' => '', 'email' => '', 'scope' => ''] + $new;
        }
        $this->storeOAuth($new);
        $this->cache->delete(self::TOKEN_CACHE);
    }

    /** Speichert drive_oauth immer mit denselben Feldern in derselben Reihenfolge. @param array<string, string> $o */
    private function storeOAuth(array $o): void
    {
        $clean = [];
        foreach (['client_id', 'client_secret', 'refresh_token', 'email', 'scope'] as $k) {
            $clean[$k] = (string) ($o[$k] ?? '');
        }
        $this->settings->set(self::OAUTH_SETTING, $clean);
    }

    /**
     * Neues, einmaliges state für die Anmeldung bei Google (Schutz vor CSRF). Es gehört zum Benutzer und zu seiner
     * Sitzung (Kennung vom Adapter, siehe Authenticator::sessionKey) und gilt STATE_TTL Sekunden. Gespeichert wird nur
     * ein Hash; je Benutzer gilt die zuletzt begonnene Anmeldung.
     *
     * @param string $return wohin es nach der Rückkehr geht ('app' oder 'admin'), steht lesbar im state
     */
    public function createState(int $userId, string $sessionKey, string $return = 'app'): string
    {
        $return = preg_match('/^[a-z]{1,10}$/', $return) ? $return : 'app';
        $state = self::STATE_PREFIX . $return . '_' . bin2hex(random_bytes(24));
        $now = $this->time->timestamp();
        $all = array_filter($this->states(), static fn (array $s): bool => $s['expires'] > $now);
        $all[$userId] = ['state' => hash('sha256', $state), 'session' => hash('sha256', $sessionKey), 'expires' => $now + self::STATE_TTL];
        $this->settings->set(self::STATE_SETTING, $all);
        return $state;
    }

    /** Ziel nach der Rückkehr, wie es im state steht ('app', wenn es fehlt oder unbekannt ist). */
    public static function stateReturn(string $state): string
    {
        return preg_match('/^' . self::STATE_PREFIX . '([a-z]{1,10})_[0-9a-f]+$/', $state, $m) ? $m[1] : 'app';
    }

    /**
     * Prüft das state aus der Rückkehr von Google: gleicher Benutzer, gleiche Sitzung, nicht abgelaufen. Es gilt nur
     * einmal und wird dabei verbraucht (auch wenn die Prüfung scheitert).
     */
    public function checkState(string $state, int $userId, string $sessionKey): bool
    {
        $all = $this->states();
        $entry = $all[$userId] ?? null;
        if ($entry === null) {
            return false;
        }
        unset($all[$userId]);
        $all === [] ? $this->settings->delete(self::STATE_SETTING) : $this->settings->set(self::STATE_SETTING, $all);
        return $entry['expires'] > $this->time->timestamp()
            && hash_equals($entry['state'], hash('sha256', $state))
            && hash_equals($entry['session'], hash('sha256', $sessionKey));
    }

    /** @return array<int, array{state: string, session: string, expires: int}> */
    private function states(): array
    {
        $raw = $this->settings->get(self::STATE_SETTING);
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $user => $s) {
            if (is_array($s) && is_string($s['state'] ?? null) && is_string($s['session'] ?? null) && is_numeric($s['expires'] ?? null)) {
                $out[(int) $user] = ['state' => $s['state'], 'session' => $s['session'], 'expires' => (int) $s['expires']];
            }
        }
        return $out;
    }

    /**
     * Adresse der Anmeldung bei Google für den gespeicherten Client. `prompt=consent` und `access_type=offline`, damit
     * Google auch bei einer erneuten Verbindung ein Refresh-Token liefert.
     *
     * @throws \RuntimeException wenn noch kein Client gespeichert ist
     */
    public function authorizeUrl(string $redirectUri, string $state): string
    {
        $o = $this->storedOAuth();
        if ($o['client_id'] === '') {
            throw new \RuntimeException('Es ist noch keine Client-ID gespeichert.');
        }
        return self::AUTH_URL . '?' . http_build_query([
            'client_id' => $o['client_id'],
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::oauthScopes(),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Tauscht den Code aus der Rückkehr von Google gegen ein Refresh-Token und speichert die Verbindung: Refresh-Token,
     * die erlaubten Berechtigungen (Häkchen lassen sich bei Google abwählen) und das Google-Konto (nur zur Anzeige).
     *
     * @throws \RuntimeException mit verständlicher Meldung
     */
    public function exchangeCode(string $code, string $redirectUri): void
    {
        $o = $this->storedOAuth();
        if ($o['client_id'] === '' || $o['client_secret'] === '') {
            throw new \RuntimeException('Es sind noch keine Client-ID und kein Clientschlüssel gespeichert.');
        }
        if ($code === '') {
            throw new \RuntimeException('Google hat keinen Code zurückgegeben.');
        }
        $res = $this->http->request('POST', self::TOKEN_URI, ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $o['client_id'],
            'client_secret' => $o['client_secret'],
            'redirect_uri' => $redirectUri,
        ]), 20);
        $body = json_decode($res['body'], true);
        if ($res['status'] !== 200 || !is_array($body) || !is_string($body['access_token'] ?? null) || $body['access_token'] === '') {
            $error = is_array($body) && is_string($body['error'] ?? null) ? $body['error'] : '';
            if ($error === 'invalid_client' || $error === 'unauthorized_client') {
                throw new \RuntimeException('Google kennt diesen Client nicht oder der Clientschlüssel stimmt nicht. Bitte Client-ID und Clientschlüssel prüfen.');
            }
            if ($error === 'redirect_uri_mismatch') {
                throw new \RuntimeException('Die Weiterleitungs-URI stimmt nicht mit der im OAuth-Client bei Google überein. Bitte genau die angezeigte Adresse eintragen.');
            }
            throw new \RuntimeException(self::errorMessage($body, $res['status']));
        }
        if (!is_string($body['refresh_token'] ?? null) || $body['refresh_token'] === '') {
            throw new \RuntimeException('Google hat kein Refresh-Token geliefert. Bitte die Verbindung erneut herstellen.');
        }
        // Bei Google lassen sich einzelne Berechtigungen abwählen; gemerkt wird, was erlaubt wurde.
        $scope = is_string($body['scope'] ?? null) ? trim($body['scope']) : self::scopes();
        if (!in_array(self::SCOPE, preg_split('/\s+/', $scope) ?: [], true)) {
            throw new \RuntimeException('Der Zugriff auf Google Drive wurde nicht erlaubt. Bitte beim Verbinden das Häkchen für Google Drive setzen.');
        }
        $this->storeOAuth(['refresh_token' => $body['refresh_token'], 'email' => '', 'scope' => $scope] + $o);
        $ttl = max(60, (int) ($body['expires_in'] ?? 3600) - 300);
        $this->cache->set(self::TOKEN_CACHE, ['email' => self::oauthOwner($body['refresh_token']), 'token' => $body['access_token']], $ttl);
        $email = $this->connectedEmail($body);
        if ($email !== '') {
            $this->storeOAuth(['email' => $email] + $this->storedOAuth());
        }
    }

    /**
     * Google-Konto der neuen Verbindung, nur zur Anzeige: aus dem id_token, falls Google eines liefert (nur mit
     * openid/email), sonst über Drive (about.user), wie die frühere App. Fehler sind hier egal.
     *
     * @param array<string, mixed> $token Antwort des Token-Endpunkts
     */
    private function connectedEmail(array $token): string
    {
        if (is_string($token['id_token'] ?? null)) {
            $claims = json_decode((string) base64_decode(strtr(explode('.', $token['id_token'])[1] ?? '', '-_', '+/')), true);
            if (is_array($claims) && is_string($claims['email'] ?? null) && filter_var($claims['email'], FILTER_VALIDATE_EMAIL)) {
                return $claims['email'];
            }
        }
        try {
            $res = $this->http->request('GET', self::ABOUT_URL . '?fields=user(emailAddress)', ['Authorization' => 'Bearer ' . $token['access_token']], null, 20);
        } catch (\RuntimeException) {
            return '';
        }
        $about = json_decode($res['body'], true);
        $email = $res['status'] === 200 && is_array($about) ? ($about['user']['emailAddress'] ?? '') : '';
        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    /**
     * Trennt die OAuth-Verbindung und widerruft das Refresh-Token bei Google (Fehler dabei sind egal). Client-ID und
     * Client-Secret bleiben für eine neue Verbindung gespeichert, das Dienstkonto ebenso.
     */
    public function disconnect(): void
    {
        $o = $this->storedOAuth();
        if ($o['refresh_token'] !== '') {
            try {
                $this->http->request('POST', self::REVOKE_URL, ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query(['token' => $o['refresh_token']]), 15);
            } catch (\RuntimeException $e) {
                error_log('Kompanie-App: Widerruf bei Google fehlgeschlagen: ' . $e->getMessage());
            }
        }
        if ($o['client_id'] === '' && $o['client_secret'] === '') {
            $this->settings->delete(self::OAUTH_SETTING);
        } else {
            $this->storeOAuth(['refresh_token' => '', 'email' => '', 'scope' => ''] + $o);
        }
        $this->cache->delete(self::TOKEN_CACHE);
    }

    /** Kennung des Token-Zwischenspeichers für eine OAuth-Verbindung. */
    private static function oauthOwner(string $refreshToken): string
    {
        return 'oauth:' . hash('sha256', $refreshToken);
    }

    /**
     * Prüft den JSON-Schlüssel eines Dienstkontos und gibt die benötigten Felder zurück.
     *
     * @return array<string, mixed>
     * @throws \RuntimeException mit verständlicher Meldung
     */
    public static function parseServiceAccount(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !is_string($data['client_email'] ?? null) || !is_string($data['private_key'] ?? null)
            || $data['client_email'] === '' || $data['private_key'] === '') {
            throw new \RuntimeException('Das ist kein gültiger Schlüssel eines Dienstkontos (client_email oder private_key fehlt).');
        }
        if (!openssl_pkey_get_private($data['private_key'])) {
            throw new \RuntimeException('Der private Schlüssel kann nicht gelesen werden.');
        }
        return array_intersect_key($data, array_flip(['type', 'project_id', 'private_key_id', 'private_key', 'client_email', 'client_id', 'token_uri']));
    }

    /** Prüft und speichert den JSON-Schlüssel eines Dienstkontos. */
    public function saveServiceAccount(string $json): void
    {
        $this->settings->set(self::SETTING, json_encode(self::parseServiceAccount($json)));
        $this->cache->delete(self::TOKEN_CACHE);
    }

    /** Entfernt den Schlüssel. */
    public function removeServiceAccount(): void
    {
        $this->settings->delete(self::SETTING);
        $this->cache->delete(self::TOKEN_CACHE);
    }

    private static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** Signiertes JWT für den Token-Austausch (RFC 7523). */
    public function buildJwt(array $sa): string
    {
        $now = $this->time->timestamp();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss' => $sa['client_email'],
            'scope' => self::scopes(),
            'aud' => $sa['token_uri'] ?? self::TOKEN_URI,
            'iat' => $now,
            'exp' => $now + 3600,
        ];
        $input = self::b64url((string) json_encode($header)) . '.' . self::b64url((string) json_encode($claims, JSON_UNESCAPED_SLASHES));
        $sig = '';
        if (!openssl_sign($input, $sig, $sa['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Die Anmeldung bei Google konnte nicht signiert werden.');
        }
        return $input . '.' . self::b64url($sig);
    }

    public function accessToken(): string
    {
        $oauth = $this->oauth();
        $sa = $oauth === null ? $this->serviceAccount() : null;
        if ($oauth === null && $sa === null) {
            throw new \RuntimeException('Es ist noch kein Google-Zugang hinterlegt.');
        }
        // Der Zwischenspeicher gehört zu genau diesem Zugang (nach einem Wechsel nicht weiterverwenden). Beim Dienstkonto
        // mit den Berechtigungen, damit ein Token von vor der Kalender-Erweiterung (nur Drive) nicht weiterverwendet wird.
        $owner = $oauth !== null ? self::oauthOwner($oauth['refresh_token']) : $sa['client_email'] . '|' . self::scopes();
        $cached = $this->cache->get(self::TOKEN_CACHE);
        if (is_array($cached) && ($cached['email'] ?? '') === $owner) {
            return (string) $cached['token'];
        }
        $form = $oauth !== null
            ? ['grant_type' => 'refresh_token', 'refresh_token' => $oauth['refresh_token'], 'client_id' => $oauth['client_id'], 'client_secret' => $oauth['client_secret']]
            : ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $this->buildJwt($sa)];
        $res = $this->http->request(
            'POST',
            $sa['token_uri'] ?? self::TOKEN_URI,
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query($form),
            20,
        );
        $body = json_decode($res['body'], true);
        if ($res['status'] !== 200 || empty($body['access_token'])) {
            if ($oauth !== null && is_array($body) && ($body['error'] ?? '') === 'invalid_grant') {
                throw new \RuntimeException('Die Verbindung zu Google ist abgelaufen oder wurde widerrufen. Bitte unter Einstellungen → Google-Zugang die Verbindung trennen und neu mit Google verbinden.');
            }
            throw new \RuntimeException('Die Anmeldung bei Google ist fehlgeschlagen: ' . self::errorMessage($body, $res['status']));
        }
        $ttl = max(60, (int) ($body['expires_in'] ?? 3600) - 300);
        $this->cache->set(self::TOKEN_CACHE, ['email' => $owner, 'token' => $body['access_token']], $ttl);
        return (string) $body['access_token'];
    }

    /** @return array<int, array<string, mixed>> alle Einträge eines Ordners (mit Paging) */
    public function listChildren(string $folderId): array
    {
        $token = $this->accessToken();
        $files = [];
        $pageToken = null;
        do {
            $params = [
                'q' => sprintf("'%s' in parents and trashed = false", str_replace("'", "\\'", $folderId)),
                'fields' => 'nextPageToken,files(id,name,mimeType,createdTime,modifiedTime,md5Checksum,size,imageMediaMetadata(width,height,time),videoMediaMetadata(width,height,durationMillis))',
                'pageSize' => 1000,
                'orderBy' => 'name',
                'supportsAllDrives' => 'true',
                'includeItemsFromAllDrives' => 'true',
            ];
            if ($pageToken) {
                $params['pageToken'] = $pageToken;
            }
            $res = $this->http->request('GET', self::FILES_URL . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986), ['Authorization' => 'Bearer ' . $token], null, 30);
            $body = json_decode($res['body'], true);
            if ($res['status'] !== 200 || !is_array($body)) {
                throw new \RuntimeException('Google Drive: ' . self::errorMessage($body, $res['status']));
            }
            foreach ($body['files'] ?? [] as $f) {
                $files[] = $f;
            }
            $pageToken = $body['nextPageToken'] ?? null;
        } while ($pageToken);
        return $files;
    }

    /**
     * Ordner, den die App angelegt hat: ob es ihn noch gibt (nicht im Papierkorb).
     *
     * @throws \RuntimeException bei anderen Fehlern als „nicht gefunden“
     */
    public function folderExists(string $folderId): bool
    {
        $res = $this->http->request('GET', self::FILES_URL . '/' . rawurlencode($folderId) . '?fields=id,trashed&supportsAllDrives=true', ['Authorization' => 'Bearer ' . $this->accessToken()], null, 20);
        if ($res['status'] === 404) {
            return false;
        }
        $body = json_decode($res['body'], true);
        if ($res['status'] !== 200 || !is_array($body)) {
            throw new \RuntimeException('Google Drive: ' . self::errorMessage($body, $res['status']));
        }
        return ($body['trashed'] ?? false) !== true;
    }

    /** Legt einen Ordner in „Meine Ablage“ an und gibt seine ID zurück. */
    public function createFolder(string $name): string
    {
        $res = $this->http->request('POST', self::FILES_URL . '?fields=id', [
            'Authorization' => 'Bearer ' . $this->accessToken(),
            'Content-Type' => 'application/json; charset=UTF-8',
        ], (string) json_encode(['name' => $name, 'mimeType' => self::FOLDER_MIME], JSON_UNESCAPED_UNICODE), 20);
        $body = json_decode($res['body'], true);
        if ($res['status'] !== 200 || !is_string($body['id'] ?? null)) {
            throw new \RuntimeException('Google Drive: Der Ordner ließ sich nicht anlegen: ' . self::errorMessage($body, $res['status']));
        }
        return $body['id'];
    }

    /**
     * Lädt eine Datei in einen Ordner hoch (fortsetzbares Hochladen) und gibt ihre ID zurück. Größere Dateien gehen in
     * Stücken von $chunk Bytes (Vielfaches von 256 KB, wie Google es verlangt): Die Datei muss nie ganz in den
     * Arbeitsspeicher (große Sicherungen).
     *
     * @param (callable(int, int): void)|null $onProgress nach jedem angenommenen Stück: übertragene Bytes, Größe
     * @throws \RuntimeException mit verständlicher Meldung
     */
    public function uploadFile(string $folderId, string $name, string $path, string $mime, int $chunk = self::UPLOAD_CHUNK, ?callable $onProgress = null): string
    {
        $size = @filesize($path);
        $in = $size === false ? false : @fopen($path, 'rb');
        if ($in === false) {
            throw new \RuntimeException('Google Drive: Die Datei zum Hochladen ließ sich nicht lesen.');
        }
        try {
            $location = $this->startUpload($folderId, $name, $mime, $size);
            $onProgress && $onProgress(0, $size);
            if ($size <= $chunk) {
                return self::uploadedId($this->http->request('PUT', $location, ['Content-Type' => $mime], (string) stream_get_contents($in), 300));
            }
            for ($start = 0; $start < $size; $start += $chunk) {
                $part = (string) fread($in, $chunk);
                $end = $start + strlen($part) - 1;
                $res = $this->http->request('PUT', $location, [
                    'Content-Type' => $mime,
                    'Content-Range' => sprintf('bytes %d-%d/%d', $start, $end, $size),
                ], $part, 300);
                if ($end + 1 < $size) {
                    // 308: Stück angenommen, weiter
                    if ($res['status'] !== 308) {
                        throw new \RuntimeException('Google Drive: Das Hochladen ist fehlgeschlagen: ' . self::errorMessage(json_decode($res['body'], true), $res['status']));
                    }
                    $onProgress && $onProgress($end + 1, $size);
                    continue;
                }
                return self::uploadedId($res);
            }
            throw new \RuntimeException('Google Drive: Das Hochladen ist fehlgeschlagen (unvollständig).');
        } finally {
            fclose($in);
        }
    }

    /** Beginnt ein fortsetzbares Hochladen; Rückgabe: Adresse der Sitzung. */
    private function startUpload(string $folderId, string $name, string $mime, int $length): string
    {
        $token = $this->accessToken();
        $start = $this->http->request('POST', self::UPLOAD_URL . '?uploadType=resumable&supportsAllDrives=true&fields=id', [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json; charset=UTF-8',
            'X-Upload-Content-Type' => $mime,
            'X-Upload-Content-Length' => (string) $length,
        ], (string) json_encode(['name' => $name, 'parents' => [$folderId], 'mimeType' => $mime], JSON_UNESCAPED_UNICODE), 30);
        $location = $start['headers']['location'] ?? '';
        if ($start['status'] !== 200 || !str_starts_with($location, 'https://')) {
            throw new \RuntimeException('Google Drive: Das Hochladen ließ sich nicht beginnen: ' . self::errorMessage(json_decode($start['body'], true), $start['status']));
        }
        return $location;
    }

    /** @param array{status: int, body: string} $res letzte Antwort beim Hochladen */
    private static function uploadedId(array $res): string
    {
        $body = json_decode($res['body'], true);
        if (!in_array($res['status'], [200, 201], true) || !is_string($body['id'] ?? null)) {
            throw new \RuntimeException('Google Drive: Das Hochladen ist fehlgeschlagen: ' . self::errorMessage($body, $res['status']));
        }
        return $body['id'];
    }

    /** Löscht eine Datei endgültig (nur eigene Dateien der App, s. BACKUP_SCOPE). */
    public function deleteFile(string $fileId): void
    {
        $res = $this->http->request('DELETE', self::FILES_URL . '/' . rawurlencode($fileId) . '?supportsAllDrives=true', ['Authorization' => 'Bearer ' . $this->accessToken()], null, 20);
        if (!in_array($res['status'], [200, 204, 404], true)) {
            throw new \RuntimeException('Google Drive: ' . self::errorMessage(json_decode($res['body'], true), $res['status']));
        }
    }

    /** Lädt eine Datei in eine temporäre Datei; der Aufrufer löscht sie. */
    public function download(string $fileId): string
    {
        $token = $this->accessToken();
        $url = self::FILES_URL . '/' . rawurlencode($fileId) . '?alt=media&supportsAllDrives=true';
        $headers = ['Authorization' => 'Bearer ' . $token];
        $tmp = $this->files->tempFile();
        try {
            if ($this->http instanceof ExtendedHttpClient) {
                // Direkt in die Datei: Fotos mit vielen MB nicht ganz im Arbeitsspeicher halten.
                $status = $this->http->download($url, $tmp, $headers, 90)['status'];
            } else {
                $res = $this->http->request('GET', $url, $headers, null, 90);
                $status = $res['status'];
                if ($status === 200) {
                    file_put_contents($tmp, $res['body']);
                }
            }
        } catch (\RuntimeException $e) {
            @unlink($tmp);
            throw $e;
        }
        if ($status !== 200) {
            @unlink($tmp);
            throw new \RuntimeException('Download fehlgeschlagen: HTTP ' . $status);
        }
        return $tmp;
    }

    /**
     * Lädt das Vorschaubild, das Google selbst für eine Datei erzeugt (thumbnailLink), in eine temporäre Datei;
     * der Aufrufer löscht sie. Für Formate, die der eigene Server nicht lesen kann (z. B. TIFF). Die Größe wird
     * über die Endung „=s<Pixel>“ der Adresse gewählt; das ist bei Google nicht dokumentiert, ohne die Endung
     * kommt das Bild in Googles Standardgröße.
     *
     * @throws \RuntimeException wenn Google kein Vorschaubild hat oder der Abruf scheitert
     */
    /**
     * Ausschnitt einer Datei (Bytes $start bis $end einschließlich) per Range-Anfrage, z. B. zum Abspielen eines Videos
     * aus Drive, ohne es zu speichern.
     *
     * @return array{body: string, start: int, end: int, total: int} wie Drive ihn liefert (Content-Range)
     * @throws \RuntimeException wenn Drive den Ausschnitt nicht liefert
     */
    public function downloadRange(string $fileId, int $start, int $end): array
    {
        $url = self::FILES_URL . '/' . rawurlencode($fileId) . '?alt=media&supportsAllDrives=true';
        $res = $this->http->request('GET', $url, ['Authorization' => 'Bearer ' . $this->accessToken(), 'Range' => 'bytes=' . $start . '-' . $end], null, 30);
        if ($res['status'] === 206 && preg_match('#^bytes (\d+)-(\d+)/(\d+)$#', trim((string) ($res['headers']['content-range'] ?? '')), $m)) {
            return ['body' => $res['body'], 'start' => (int) $m[1], 'end' => (int) $m[2], 'total' => (int) $m[3]];
        }
        if ($res['status'] === 200 && $start === 0) {
            // Ganze Datei statt Ausschnitt (kleine Datei): nur den angefragten Teil weitergeben.
            $total = strlen($res['body']);
            $end = min($end, $total - 1);
            return ['body' => substr($res['body'], 0, $end + 1), 'start' => 0, 'end' => $end, 'total' => $total];
        }
        throw new \RuntimeException('Download fehlgeschlagen: HTTP ' . $res['status']);
    }

    /**
     * Wie downloadRange, aber die Daten gehen weiter, während sie aus Drive kommen (StreamingHttpClient; sonst in
     * einem Stück). $onStart bekommt den Bereich, den Drive liefert (Anfang, Ende, Größe der Datei), bevor Daten an
     * $onData gehen; false von $onData bricht ab (z. B. der Browser hat aufgelegt).
     *
     * @param \Closure(int, int, int): void $onStart
     * @param \Closure(string): bool $onData
     * @return bool true, wenn der ganze Bereich weitergegeben wurde
     * @throws \RuntimeException wenn Drive den Ausschnitt nicht liefert ($onStart wurde dann nicht aufgerufen)
     */
    public function streamRange(string $fileId, int $start, int $end, \Closure $onStart, \Closure $onData): bool
    {
        if (!$this->http instanceof StreamingHttpClient) {
            $part = $this->downloadRange($fileId, $start, $end);
            $onStart($part['start'], $part['end'], $part['total']);
            return $part['body'] === '' || $onData($part['body']);
        }
        $url = self::FILES_URL . '/' . rawurlencode($fileId) . '?alt=media&supportsAllDrives=true';
        $status = 0;
        $started = false;
        $left = 0; // Bytes, die noch weitergegeben werden (bei 200 statt 206 nur der angefragte Teil)
        $onHead = static function (int $code, array $headers) use (&$status, &$started, &$left, $start, $end, $onStart): bool {
            $status = $code;
            $started = true;
            if ($code === 206 && preg_match('#^bytes (\d+)-(\d+)/(\d+)$#', trim((string) ($headers['content-range'] ?? '')), $m)) {
                $last = min((int) $m[2], max($end, (int) $m[1])); // nicht mehr als angefragt
                $left = $last - (int) $m[1] + 1;
                $onStart((int) $m[1], $last, (int) $m[3]);
                return true;
            }
            if ($code === 200 && $start === 0 && isset($headers['content-length']) && ctype_digit((string) $headers['content-length'])) {
                // Ganze Datei statt Ausschnitt (kleine Datei): nur den angefragten Teil weitergeben.
                $total = (int) $headers['content-length'];
                $last = min($end, $total - 1);
                if ($last >= 0) {
                    $left = $last + 1;
                    $onStart(0, $last, $total);
                    return true;
                }
            }
            $started = false;
            return false;
        };
        $sink = static function (string $chunk) use (&$left, $onData): bool {
            if ($left <= 0) {
                return false;
            }
            $piece = strlen($chunk) > $left ? substr($chunk, 0, $left) : $chunk;
            $left -= strlen($piece);
            return $onData($piece) && $left > 0;
        };
        $this->http->stream($url, ['Authorization' => 'Bearer ' . $this->accessToken(), 'Range' => 'bytes=' . $start . '-' . $end], $onHead, $sink);
        if (!$started) {
            throw new \RuntimeException('Download fehlgeschlagen: HTTP ' . $status);
        }
        return $left === 0;
    }

    public function thumbnail(string $fileId, int $maxEdge): string
    {
        $token = $this->accessToken();
        $headers = ['Authorization' => 'Bearer ' . $token];
        $res = $this->http->request('GET', self::FILES_URL . '/' . rawurlencode($fileId) . '?fields=thumbnailLink&supportsAllDrives=true', $headers, null, 20);
        $body = json_decode($res['body'], true);
        if ($res['status'] !== 200 || !is_array($body)) {
            throw new \RuntimeException('Google Drive: ' . self::errorMessage($body, $res['status']));
        }
        $link = (string) ($body['thumbnailLink'] ?? '');
        if (!str_starts_with($link, 'https://')) {
            throw new \RuntimeException('Google Drive hat für diese Datei kein Vorschaubild.');
        }
        $link = (string) preg_replace('/=s\d+$/', '=s' . $maxEdge, $link);
        // Nicht öffentlich geteilte Dateien: Abruf mit Anmeldung (laut Drive-API-Referenz zu thumbnailLink).
        $res = $this->http->request('GET', $link, $headers, null, 30);
        if ($res['status'] !== 200 || @getimagesizefromstring($res['body']) === false) {
            throw new \RuntimeException('Das Vorschaubild von Google ließ sich nicht laden: HTTP ' . $res['status']);
        }
        $tmp = $this->files->tempFile();
        file_put_contents($tmp, $res['body']);
        return $tmp;
    }

    /**
     * Fehlermeldung aus einer Google-Antwort ({error: {message}} oder {error, error_description}), immer mit
     * HTTP-Status (den braucht auch die Ursache in den Statusmeldungen, Telemetry\Detail).
     */
    private static function errorMessage(mixed $body, int $status): string
    {
        if (is_array($body)) {
            $error = $body['error'] ?? null;
            if (is_array($error) && is_string($error['message'] ?? null)) {
                return $error['message'] . ' (HTTP ' . $status . ')';
            }
            if (is_string($body['error_description'] ?? null)) {
                return $body['error_description'] . ' (HTTP ' . $status . ')';
            }
            if (is_string($error)) {
                return $error . ' (HTTP ' . $status . ')';
            }
        }
        return 'HTTP ' . $status;
    }

    public static function isFolder(array $f): bool
    {
        return ($f['mimeType'] ?? '') === self::FOLDER_MIME;
    }

    public static function isImage(array $f): bool
    {
        return str_starts_with((string) ($f['mimeType'] ?? ''), 'image/');
    }

    public static function isVideo(array $f): bool
    {
        return str_starts_with((string) ($f['mimeType'] ?? ''), 'video/');
    }

    /** Videos, die die Galerie abspielt; andere Formate spielen viele Browser nicht ab (Liste für Admins). */
    public const PLAYABLE_VIDEO = ['video/mp4', 'video/webm'];

    public static function isPlayableVideo(string $mime): bool
    {
        return in_array(strtolower($mime), self::PLAYABLE_VIDEO, true);
    }

    /** Ordner-ID aus einem Drive-Link oder direkt. */
    public static function parseFolderId(string $input): string
    {
        $input = trim($input);
        if (preg_match('#/folders/([A-Za-z0-9_-]+)#', $input, $m)) {
            return $m[1];
        }
        if (preg_match('#[?&]id=([A-Za-z0-9_-]+)#', $input, $m)) {
            return $m[1];
        }
        return preg_match('/^[A-Za-z0-9_-]+$/', $input) ? $input : '';
    }
}
