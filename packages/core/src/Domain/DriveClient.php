<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\Port\Cache;
use KompanieApp\Core\Port\ExtendedHttpClient;
use KompanieApp\Core\Port\FileStorage;
use KompanieApp\Core\Port\HttpClient;
use KompanieApp\Core\Port\Settings;

/**
 * Lesender Zugriff auf Google Drive über ein Dienstkonto.
 *
 * Der Schlüssel des Dienstkontos liegt nur auf dem Server. Der Galerie-Ordner
 * muss in Google Drive für die E-Mail-Adresse des Dienstkontos freigegeben sein.
 * Ohne Bibliothek: Das Zugriffstoken wird per signiertem JWT (RS256) geholt.
 *
 * Fehler werden als \RuntimeException mit verständlicher Meldung geworfen.
 */
final class DriveClient
{
    public const SETTING = 'drive_service_account';
    /**
     * Bestehende Verbindung über OAuth (client_id, client_secret, refresh_token, email), etwa aus der Übernahme
     * einer früheren App. Sie geht dem Dienstkonto vor. Neu verbinden lässt sich so (noch) nicht, siehe
     * OFFENE-PUNKTE.md, Abschnitt 5; nur trennen.
     */
    public const OAUTH_SETTING = 'drive_oauth';
    public const SCOPE = 'https://www.googleapis.com/auth/drive.readonly';
    public const TOKEN_URI = 'https://oauth2.googleapis.com/token';
    public const FILES_URL = 'https://www.googleapis.com/drive/v3/files';
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

    /** @return array{client_id: string, client_secret: string, refresh_token: string, email: string}|null */
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
        return ['client_id' => $o['client_id'], 'client_secret' => $o['client_secret'], 'refresh_token' => $o['refresh_token'], 'email' => is_string($o['email'] ?? null) ? $o['email'] : ''];
    }

    /** Google-Konto der OAuth-Verbindung ('' ohne Verbindung oder wenn es unbekannt ist). */
    public function oauthEmail(): string
    {
        return $this->oauth()['email'] ?? '';
    }

    /** Ist ein Google-Zugang hinterlegt (OAuth-Verbindung oder Dienstkonto)? */
    public function hasCredentials(): bool
    {
        return $this->oauth() !== null || $this->serviceAccount() !== null;
    }

    /**
     * Speichert eine bestehende OAuth-Verbindung.
     *
     * @param array<string, mixed> $o client_id, client_secret, refresh_token, email
     * @throws \RuntimeException wenn ein Pflichtfeld fehlt
     */
    public function saveOAuth(array $o): void
    {
        $clean = [];
        foreach (['client_id', 'client_secret', 'refresh_token', 'email'] as $k) {
            $clean[$k] = is_string($o[$k] ?? null) ? trim($o[$k]) : '';
            if ($clean[$k] === '' && $k !== 'email') {
                throw new \RuntimeException('Für die Verbindung zu Google fehlt ' . $k . '.');
            }
        }
        $this->settings->set(self::OAUTH_SETTING, $clean);
        $this->cache->delete(self::TOKEN_CACHE);
    }

    /** Trennt die OAuth-Verbindung (das Dienstkonto bleibt, falls hinterlegt). */
    public function removeOAuth(): void
    {
        $this->settings->delete(self::OAUTH_SETTING);
        $this->cache->delete(self::TOKEN_CACHE);
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
            'scope' => self::SCOPE,
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
        // Der Zwischenspeicher gehört zu genau diesem Zugang (nach einem Wechsel nicht weiterverwenden).
        $owner = $oauth !== null ? 'oauth:' . hash('sha256', $oauth['refresh_token']) : $sa['client_email'];
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
                throw new \RuntimeException('Die Verbindung zu Google ist abgelaufen oder wurde widerrufen. Trenne sie und hinterlege ein Dienstkonto.');
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
                'fields' => 'nextPageToken,files(id,name,mimeType,createdTime,modifiedTime,md5Checksum,imageMediaMetadata(width,height,time))',
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

    /** Fehlermeldung aus einer Google-Antwort ({error: {message}} oder {error, error_description}). */
    private static function errorMessage(mixed $body, int $status): string
    {
        if (is_array($body)) {
            $error = $body['error'] ?? null;
            if (is_array($error) && is_string($error['message'] ?? null)) {
                return $error['message'];
            }
            if (is_string($body['error_description'] ?? null)) {
                return $body['error_description'];
            }
            if (is_string($error)) {
                return $error;
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
