<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests\Support;

use KompanieApp\Core\Domain\DriveClient;

/**
 * Simuliertes Google Drive: Token-Endpunkt (mit Prüfung der JWT-Signatur),
 * files.list mit Paging und Bild-Download (erzeugtes JPEG).
 */
final class DriveMock
{
    /** @var array<string, array<int, array<string, mixed>>> */
    public array $tree;
    public int $tokens = 0;
    public bool $jwtValid = false;
    /** @var array<string, mixed>|null Formular der letzten Token-Anfrage per Refresh-Token (OAuth) */
    public ?array $refreshForm = null;
    /** Refresh-Token, das der Token-Endpunkt annimmt (sonst invalid_grant). */
    public string $validRefreshToken = 'REFRESH1';
    public bool $paged = false;
    public int $downloads = 0;
    /** Downloads scheitern (HTTP 500), z. B. bei einer Störung. */
    public bool $downloadFails = false;
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
            'A3' => [
                ['id' => 'IMG5', 'name' => 'e.png', 'mimeType' => 'image/png', 'md5Checksum' => 'm5'],
                ['id' => 'VID1', 'name' => 'film.mp4', 'mimeType' => 'video/mp4', 'md5Checksum' => 'v1'],
            ],
        ];
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
                if (($form['grant_type'] ?? '') === 'refresh_token') {
                    $this->refreshForm = $form;
                    return ($form['refresh_token'] ?? '') === $this->validRefreshToken
                        ? ['status' => 200, 'body' => (string) json_encode(['access_token' => 'TOKEN123', 'expires_in' => 3600])]
                        : ['status' => 400, 'body' => '{"error":"invalid_grant","error_description":"Token has been expired or revoked."}'];
                }
                $parts = explode('.', (string) ($form['assertion'] ?? ''));
                $sig = base64_decode(strtr($parts[2] ?? '', '-_', '+/'));
                $claim = json_decode((string) base64_decode(strtr($parts[1] ?? '', '-_', '+/')), true);
                $this->jwtValid = openssl_verify($parts[0] . '.' . $parts[1], $sig, $this->publicKey, OPENSSL_ALGO_SHA256) === 1
                    && ($claim['iss'] ?? '') === 'galerie@test.iam.gserviceaccount.com'
                    && ($claim['scope'] ?? '') === DriveClient::SCOPE;
                return ['status' => 200, 'body' => (string) json_encode(['access_token' => 'TOKEN123', 'expires_in' => 3600])];
            }
            if (!str_starts_with($url, DriveClient::FILES_URL)) {
                return ['status' => 404, 'body' => ''];
            }
            if (($headers['Authorization'] ?? '') !== 'Bearer TOKEN123') {
                return ['status' => 401, 'body' => '{"error":{"message":"Kein Token"}}'];
            }
            if (str_contains($url, 'alt=media')) {
                $this->downloads++;
                return $this->downloadFails ? ['status' => 500, 'body' => ''] : ['status' => 200, 'body' => self::jpeg()];
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
