<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests\Support;

use KompanieApp\Core\Domain\DriveClient;

/**
 * Simuliertes Google Drive: Token-Endpunkt (mit Prüfung der JWT-Signatur),
 * files.list mit Paging und Bild-Download (erzeugtes JPEG). Dazu die Termine der
 * Google Calendar API (events.list) für den Kalender über denselben Zugang.
 */
final class DriveMock
{
    /** @var array<string, array<int, array<string, mixed>>> */
    public array $tree;
    public int $tokens = 0;
    public bool $jwtValid = false;
    /** Berechtigungen im JWT der letzten Token-Anfrage des Dienstkontos. */
    public string $jwtScope = '';
    /** @var array<int, array<string, mixed>> Termine, die events.list liefert */
    public array $calendarItems = [];
    /** Antwort von events.list (z. B. 404, wenn der Kalender nicht freigegeben ist). */
    public int $calendarStatus = 200;
    /** @var array<int, array{url: string, headers: array<string, string>}> Anfragen an die Calendar API */
    public array $calendarRequests = [];
    /** @var array<string, mixed>|null Formular der letzten Token-Anfrage per Refresh-Token (OAuth) */
    public ?array $refreshForm = null;
    /** Refresh-Token, das der Token-Endpunkt annimmt (sonst invalid_grant). */
    public string $validRefreshToken = 'REFRESH1';
    /** „Mit Google verbinden“: Code, den der Token-Endpunkt annimmt (sonst invalid_grant). */
    public string $validCode = 'CODE1';
    /** Erlaubte Berechtigungen in der Antwort auf den Code (Häkchen bei Google); null = Feld fehlt. */
    public ?string $grantedScope = DriveClient::SCOPE . ' ' . DriveClient::CALENDAR_SCOPE;
    /** Refresh-Token in der Antwort auf den Code; '' = keines. */
    public string $issuedRefreshToken = 'REFRESH1';
    /** Fehler des Token-Endpunkts beim Code (z. B. invalid_client); '' = keiner. */
    public string $codeError = '';
    /** @var array<string, mixed>|null Formular der letzten Token-Anfrage mit Code */
    public ?array $codeForm = null;
    /** Konto, das Drive (about.user) meldet. */
    public string $aboutEmail = 'kompanie@example.org';
    /** @var string[] beim Widerruf übergebene Tokens */
    public array $revoked = [];
    public bool $paged = false;
    public int $downloads = 0;
    /** Downloads scheitern (HTTP 500), z. B. bei einer Störung. */
    public bool $downloadFails = false;
    /** Drive-Datei-ID => Inhalt der Datei (statt des erzeugten JPEGs), z. B. eine RAW-Datei. */
    public array $contents = [];
    /** Drive-Datei-IDs, für die Google ein Vorschaubild (thumbnailLink) hat. */
    public array $thumbnails = [];
    /** @var string[] Range-Kopfzeilen der Abrufe von Ausschnitten */
    public array $ranges = [];
    /** @var string[] abgerufene Vorschaubild-Adressen */
    public array $thumbnailRequests = [];
    /** @var array<int, array{meta: array<string, mixed>, data: string, id: string|null}> fortsetzbares Hochladen */
    public array $sessions = [];
    /** @var string[] in den Papierkorb gelegte Datei-IDs */
    public array $trashed = [];
    /** @var array<int, array{method: string, url: string}> Schreibende Anfragen (Hochladen, Ordner, Verschieben) */
    public array $writes = [];
    /** Schreibende Anfragen scheitern mit diesem Status (z. B. 403 ohne Bearbeitungsrecht am Ordner); 0 = nicht. */
    public int $writeStatus = 0;
    private int $nextId = 1;
    public string $privateKey = '';
    private string $publicKey;

    public function __construct()
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $this->privateKey);
        $this->publicKey = openssl_pkey_get_details($key)['key'];
        $folder = DriveClient::FOLDER_MIME;
        $this->tree = [
            'ROOT' => [
                ['id' => 'Y2026', 'name' => '2026', 'mimeType' => $folder, 'createdTime' => '2026-01-01T00:00:00Z'],
                ['id' => 'Y2025', 'name' => '2025', 'mimeType' => $folder, 'createdTime' => '2025-01-01T00:00:00Z'],
                ['id' => 'STRAY', 'name' => 'lose.jpg', 'mimeType' => 'image/jpeg', 'md5Checksum' => 'x'],
            ],
            'Y2026' => [
                ['id' => 'A1', 'name' => 'Sommerfest', 'mimeType' => $folder, 'createdTime' => '2026-06-28T00:00:00Z'],
                ['id' => 'A2', 'name' => 'Vereinsabend', 'mimeType' => $folder, 'createdTime' => '2026-03-01T00:00:00Z'],
            ],
            'A1' => [
                ['id' => 'IMG1', 'name' => 'a.jpg', 'mimeType' => 'image/jpeg', 'md5Checksum' => 'm1', 'imageMediaMetadata' => ['width' => 1200, 'height' => 900, 'time' => '2026:06:27 18:00:00']],
                ['id' => 'IMG2', 'name' => 'b.jpg', 'mimeType' => 'image/jpeg', 'md5Checksum' => 'm2', 'imageMediaMetadata' => ['time' => '2026:06:27 19:00:00']],
                ['id' => 'I1', 'name' => 'Intern', 'mimeType' => $folder],
            ],
            'I1' => [['id' => 'IMG3', 'name' => 'c.jpg', 'mimeType' => 'image/jpeg', 'md5Checksum' => 'm3']],
            'A2' => [['id' => 'I2', 'name' => 'intern', 'mimeType' => $folder]],
            'I2' => [['id' => 'IMG4', 'name' => 'd.jpg', 'mimeType' => 'image/jpeg', 'md5Checksum' => 'm4']],
            'Y2025' => [['id' => 'A3', 'name' => 'Maifest', 'mimeType' => $folder, 'createdTime' => '2025-05-08T00:00:00Z']],
            'A3' => [['id' => 'IMG5', 'name' => 'e.png', 'mimeType' => 'image/png', 'md5Checksum' => 'm5']],
        ];
    }

    /** Ordner, in dem die Datei liegt, und ihr Eintrag; null, wenn es sie nicht gibt. */
    public function find(string $id): ?array
    {
        foreach ($this->tree as $parent => $files) {
            foreach ($files as $i => $f) {
                if ((string) $f['id'] === $id) {
                    return ['parent' => (string) $parent, 'index' => $i, 'file' => $f];
                }
            }
        }
        return null;
    }

    /** Neue Datei- oder Ordner-ID. */
    private function id(string $prefix): string
    {
        return $prefix . $this->nextId++;
    }

    /** @return array{status: int, headers?: array<string, string>, body: string} */
    private function write(string $method, string $url, string $body): array
    {
        $this->writes[] = ['method' => $method, 'url' => $url];
        if ($this->writeStatus !== 0) {
            return ['status' => $this->writeStatus, 'body' => '{"error":{"message":"The user does not have sufficient permissions for this file."}}'];
        }
        $meta = (array) json_decode($body, true);
        if ($method === 'POST') {
            // Ordner anlegen
            $id = $this->id('F');
            $parent = (string) ($meta['parents'][0] ?? 'MYDRIVE');
            $this->tree[$parent][] = ['id' => $id, 'name' => (string) ($meta['name'] ?? ''), 'mimeType' => (string) ($meta['mimeType'] ?? ''), 'createdTime' => '2026-11-17T12:00:00Z'];
            $this->tree[$id] = [];
            return ['status' => 200, 'body' => (string) json_encode(['id' => $id])];
        }
        $id = rawurldecode((string) preg_replace('#^.*/files/([^?]+)\\?.*$#', '$1', $url));
        $at = $this->find($id);
        if ($at === null) {
            return ['status' => 404, 'body' => '{"error":{"message":"File not found"}}'];
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $file = $at['file'];
        if (isset($meta['name'])) {
            $file['name'] = (string) $meta['name'];
        }
        unset($this->tree[$at['parent']][$at['index']]);
        $this->tree[$at['parent']] = array_values($this->tree[$at['parent']]);
        if (($meta['trashed'] ?? false) === true) {
            $this->trashed[] = $id;
            return ['status' => 200, 'body' => (string) json_encode(['id' => $id])];
        }
        if (isset($q['addParents']) && ($q['removeParents'] ?? '') !== $at['parent']) {
            return ['status' => 400, 'body' => '{"error":{"message":"removeParents falsch"}}'];
        }
        $this->tree[(string) ($q['addParents'] ?? $at['parent'])][] = $file;
        return ['status' => 200, 'body' => (string) json_encode(['id' => $id])];
    }

    /**
     * Fortsetzbares Hochladen wie bei Google: POST beginnt (Adresse in location), PUT sendet Stücke (308 mit Range) oder
     * fragt den Stand ab (Content-Range bytes * /Größe).
     *
     * @param array<string, string> $headers
     * @return array{status: int, headers?: array<string, string>, body: string}
     */
    private function upload(string $method, string $url, array $headers, string $body): array
    {
        if ($method === 'POST') {
            $this->writes[] = ['method' => $method, 'url' => $url];
            if ($this->writeStatus !== 0) {
                return ['status' => $this->writeStatus, 'body' => '{"error":{"message":"The user does not have sufficient permissions for this file."}}'];
            }
            $n = count($this->sessions) + 1;
            $this->sessions[$n] = ['meta' => (array) json_decode($body, true) + ['size' => (int) ($headers['X-Upload-Content-Length'] ?? 0)], 'data' => '', 'id' => null];
            return ['status' => 200, 'headers' => ['location' => DriveClient::UPLOAD_URL . '?uploadType=resumable&upload_id=' . $n], 'body' => ''];
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $n = (int) ($q['upload_id'] ?? 0);
        if (!isset($this->sessions[$n])) {
            return ['status' => 404, 'body' => ''];
        }
        $session = &$this->sessions[$n];
        $range = (string) ($headers['Content-Range'] ?? '');
        if (!str_starts_with($range, 'bytes */') && $session['id'] === null) {
            $session['data'] .= $body;
        }
        $size = (int) $session['meta']['size'];
        if ($session['id'] === null && strlen($session['data']) >= $size && ($body !== '' || $size === 0)) {
            $id = $this->id('UP');
            $parent = (string) ($session['meta']['parents'][0] ?? 'MYDRIVE');
            $this->tree[$parent][] = [
                'id' => $id,
                'name' => (string) ($session['meta']['name'] ?? ''),
                'mimeType' => (string) ($session['meta']['mimeType'] ?? ''),
                'md5Checksum' => md5($session['data']),
                'size' => (string) strlen($session['data']),
            ];
            $this->contents[$id] = $session['data'];
            $session['id'] = $id;
        }
        if ($session['id'] !== null) {
            return ['status' => 200, 'body' => (string) json_encode(['id' => $session['id']])];
        }
        return ['status' => 308, 'headers' => $session['data'] === '' ? [] : ['range' => 'bytes=0-' . (strlen($session['data']) - 1)], 'body' => ''];
    }

    public function serviceAccountJson(): string
    {
        return (string) json_encode([
            'type' => 'service_account',
            'client_email' => 'galerie@test.iam.gserviceaccount.com',
            'private_key' => $this->privateKey,
            'token_uri' => DriveClient::TOKEN_URI,
        ]);
    }

    public static function jpeg(int $w = 1200, int $h = 900): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, (int) imagecolorallocate($im, 47, 93, 138));
        ob_start();
        imagejpeg($im, null, 80);
        return (string) ob_get_clean();
    }

    public function http(): FakeHttpClient
    {
        return new FakeHttpClient(function (string $method, string $url, array $headers, ?string $body): array {
            if (str_starts_with($url, DriveClient::TOKEN_URI)) {
                $this->tokens++;
                parse_str((string) $body, $form);
                if (($form['grant_type'] ?? '') === 'authorization_code') {
                    $this->codeForm = $form;
                    if ($this->codeError !== '') {
                        return ['status' => 401, 'body' => (string) json_encode(['error' => $this->codeError, 'error_description' => 'Unauthorized'])];
                    }
                    if (($form['code'] ?? '') !== $this->validCode) {
                        return ['status' => 400, 'body' => '{"error":"invalid_grant","error_description":"Bad Request"}'];
                    }
                    $token = ['access_token' => 'TOKEN123', 'expires_in' => 3599, 'token_type' => 'Bearer'];
                    if ($this->issuedRefreshToken !== '') {
                        $token['refresh_token'] = $this->issuedRefreshToken;
                    }
                    if ($this->grantedScope !== null) {
                        $token['scope'] = $this->grantedScope;
                    }
                    return ['status' => 200, 'body' => (string) json_encode($token)];
                }
                if (($form['grant_type'] ?? '') === 'refresh_token') {
                    $this->refreshForm = $form;
                    return ($form['refresh_token'] ?? '') === $this->validRefreshToken
                        ? ['status' => 200, 'body' => (string) json_encode(['access_token' => 'TOKEN123', 'expires_in' => 3600])]
                        : ['status' => 400, 'body' => '{"error":"invalid_grant","error_description":"Token has been expired or revoked."}'];
                }
                $parts = explode('.', (string) ($form['assertion'] ?? ''));
                $sig = base64_decode(strtr($parts[2] ?? '', '-_', '+/'));
                $claim = json_decode((string) base64_decode(strtr($parts[1] ?? '', '-_', '+/')), true);
                $this->jwtScope = (string) ($claim['scope'] ?? '');
                $this->jwtValid = openssl_verify($parts[0] . '.' . $parts[1], $sig, $this->publicKey, OPENSSL_ALGO_SHA256) === 1
                    && ($claim['iss'] ?? '') === 'galerie@test.iam.gserviceaccount.com'
                    && $this->jwtScope === DriveClient::scopes();
                return ['status' => 200, 'body' => (string) json_encode(['access_token' => 'TOKEN123', 'expires_in' => 3600])];
            }
            if (str_starts_with($url, DriveClient::REVOKE_URL)) {
                parse_str((string) $body, $form);
                $this->revoked[] = (string) ($form['token'] ?? '');
                return ['status' => 200, 'body' => ''];
            }
            if (str_starts_with($url, DriveClient::ABOUT_URL)) {
                return ($headers['Authorization'] ?? '') === 'Bearer TOKEN123'
                    ? ['status' => 200, 'body' => (string) json_encode(['user' => ['emailAddress' => $this->aboutEmail]])]
                    : ['status' => 401, 'body' => '{"error":{"message":"Kein Token"}}'];
            }
            if (str_starts_with($url, 'https://www.googleapis.com/calendar/v3/')) {
                $this->calendarRequests[] = ['url' => $url, 'headers' => $headers];
                if (($headers['Authorization'] ?? '') !== 'Bearer TOKEN123') {
                    return ['status' => 401, 'body' => '{"error":{"message":"Request is missing required authentication credential."}}'];
                }
                return $this->calendarStatus === 200
                    ? ['status' => 200, 'body' => (string) json_encode(['items' => $this->calendarItems])]
                    : ['status' => $this->calendarStatus, 'body' => '{"error":{"message":"Not Found"}}'];
            }
            if (str_starts_with($url, 'https://lh3.googleusercontent.com/drive-storage/')) {
                $this->thumbnailRequests[] = $url;
                return ($headers['Authorization'] ?? '') === 'Bearer TOKEN123'
                    ? ['status' => 200, 'body' => self::jpeg(1600, 1200)]
                    : ['status' => 403, 'body' => ''];
            }
            if (str_starts_with($url, DriveClient::UPLOAD_URL)) {
                return $this->upload($method, $url, $headers, (string) $body);
            }
            if (!str_starts_with($url, DriveClient::FILES_URL)) {
                return ['status' => 404, 'body' => ''];
            }
            if (($headers['Authorization'] ?? '') !== 'Bearer TOKEN123') {
                return ['status' => 401, 'body' => '{"error":{"message":"Kein Token"}}'];
            }
            if ($method === 'POST' || $method === 'PATCH') {
                return $this->write($method, $url, (string) $body);
            }
            if (str_contains($url, 'fields=id,trashed')) {
                $fid = rawurldecode((string) preg_replace('#^.*/files/([^?]+)\\?.*$#', '$1', $url));
                return $this->find($fid) !== null || isset($this->tree[$fid])
                    ? ['status' => 200, 'body' => (string) json_encode(['id' => $fid, 'trashed' => in_array($fid, $this->trashed, true)])]
                    : ['status' => 404, 'body' => '{"error":{"message":"File not found"}}'];
            }
            $id = rawurldecode((string) preg_replace('#^.*/files/([^?]+)\\?.*$#', '$1', $url));
            if (str_contains($url, 'alt=media') && preg_match('/^bytes=(\d+)-(\d+)$/', (string) ($headers['Range'] ?? ''), $r)) {
                // Ausschnitt (Videos abspielen): 206 mit Content-Range wie bei Google
                $this->ranges[] = (string) $headers['Range'];
                $content = $this->contents[$id] ?? self::jpeg();
                $end = min((int) $r[2], strlen($content) - 1);
                return ['status' => 206, 'headers' => ['content-range' => 'bytes ' . $r[1] . '-' . $end . '/' . strlen($content)], 'body' => substr($content, (int) $r[1], $end - (int) $r[1] + 1)];
            }
            if (str_contains($url, 'alt=media')) {
                $this->downloads++;
                return $this->downloadFails ? ['status' => 500, 'body' => ''] : ['status' => 200, 'body' => $this->contents[$id] ?? self::jpeg()];
            }
            if (str_contains($url, 'fields=thumbnailLink')) {
                return ['status' => 200, 'body' => (string) json_encode(in_array($id, $this->thumbnails, true)
                    ? ['thumbnailLink' => 'https://lh3.googleusercontent.com/drive-storage/' . $id . '=s220']
                    : [])];
            }
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            preg_match("/'([^']+)' in parents/", (string) $q['q'], $m);
            $files = $this->tree[$m[1]] ?? [];
            if ($m[1] === 'A1') {
                if (empty($q['pageToken'])) {
                    return ['status' => 200, 'body' => (string) json_encode(['files' => array_slice($files, 0, 1), 'nextPageToken' => 'P2'])];
                }
                $this->paged = true;
                return ['status' => 200, 'body' => (string) json_encode(['files' => array_slice($files, 1)])];
            }
            return ['status' => 200, 'body' => (string) json_encode(['files' => array_values($files)])];
        });
    }
}
