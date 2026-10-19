<?php

declare(strict_types=1);

namespace KompanieApp\Core\Push;

/**
 * Web Push ohne Fremdbibliothek (Issue #19): Verschlüsselung der Nachricht nach RFC 8291
 * (Content-Encoding aes128gcm) und VAPID-Token nach RFC 8292 (ES256). Braucht OpenSSL mit der Kurve
 * P-256 (prime256v1), openssl_pkey_derive und aes-128-gcm; requirements() nennt, was fehlt.
 * Geprüft mit dem Testvektor aus RFC 8291, Anhang A (WebPushCryptoTest).
 */
final class WebPushCrypto
{
    /** Größe eines Datensatzes im Header (rs); die Nachricht passt in einen einzigen. */
    public const RECORD_SIZE = 4096;
    /** SubjectPublicKeyInfo-Kopf eines P-256-Schlüssels, davor kommt der rohe Punkt (65 Byte). */
    private const SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /**
     * Fehlende Voraussetzungen auf dem Server, leer = Push möglich.
     *
     * @return string[]
     */
    public static function requirements(): array
    {
        if (!extension_loaded('openssl')) {
            return ['PHP-Erweiterung OpenSSL'];
        }
        $missing = [];
        if (!function_exists('openssl_pkey_derive')) {
            $missing[] = 'openssl_pkey_derive';
        }
        if (!in_array('aes-128-gcm', array_map('strtolower', openssl_get_cipher_methods()), true)) {
            $missing[] = 'Verschlüsselung aes-128-gcm';
        }
        if (!in_array('prime256v1', (array) openssl_get_curve_names(), true)) {
            $missing[] = 'Kurve prime256v1 (P-256)';
        }
        return $missing;
    }

    /**
     * Neues Schlüsselpaar.
     *
     * @return array{public: string, private: string} public = roher Punkt base64url (65 Byte), private = PEM
     */
    public static function newKeyPair(): array
    {
        $key = self::newKey();
        if (!openssl_pkey_export($key, $pem)) {
            throw new \RuntimeException('Schlüssel ließ sich nicht exportieren.');
        }
        return ['public' => self::b64uEncode(self::rawPublic($key)), 'private' => (string) $pem];
    }

    /**
     * Verschlüsselt eine Nachricht für ein Gerät (RFC 8291, ein einziger Datensatz).
     *
     * @param string $uaPublic öffentlicher Schlüssel des Geräts, 65 Byte roh
     * @param string $auth Authentifizierungsgeheimnis des Geräts, 16 Byte roh
     * @param string|null $salt nur für Tests: fester Salt (16 Byte)
     * @param \OpenSSLAsymmetricKey|null $asKey nur für Tests: festes Absender-Schlüsselpaar
     * @return string Body der Push-Nachricht (Header + Chiffrat)
     */
    public static function encrypt(string $plaintext, string $uaPublic, string $auth, ?string $salt = null, ?\OpenSSLAsymmetricKey $asKey = null): string
    {
        $asKey ??= self::newKey();
        $uaKey = self::publicKeyFromRaw($uaPublic);
        $ecdh = $uaKey === null ? false : openssl_pkey_derive($uaKey, $asKey, 32);
        if ($ecdh === false) {
            throw new \RuntimeException('Ungültiger Schlüssel des Geräts.');
        }
        $asPublic = self::rawPublic($asKey);
        $salt ??= random_bytes(16);

        // RFC 8291, Abschnitt 3.4: HKDF mit je einem Ausgabeblock (≤ 32 Byte).
        $prkKey = self::hmac($auth, $ecdh);
        $ikm = self::hmac($prkKey, "WebPush: info\0" . $uaPublic . $asPublic . "\x01");
        $prk = self::hmac($salt, $ikm);
        $cek = substr(self::hmac($prk, "Content-Encoding: aes128gcm\0\x01"), 0, 16);
        $nonce = substr(self::hmac($prk, "Content-Encoding: nonce\0\x01"), 0, 12);

        // Letzter (einziger) Datensatz: Trennbyte 0x02, keine Auffüllung.
        $tag = '';
        $cipher = openssl_encrypt($plaintext . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Verschlüsselung fehlgeschlagen.');
        }
        return $salt . pack('N', self::RECORD_SIZE) . chr(strlen($asPublic)) . $asPublic . $cipher . $tag;
    }

    /**
     * VAPID-Token für den Push-Dienst: Audience = Ursprung des Endpoints, gültig 12 Stunden.
     *
     * @param string $subject Kontakt, „mailto:…“ oder „https://…“
     */
    public static function vapidToken(string $endpoint, string $privatePem, string $subject, int $now): string
    {
        $parts = parse_url($endpoint);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new \RuntimeException('Ungültige Push-Adresse.');
        }
        $aud = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $input = self::b64uEncode((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']))
            . '.' . self::b64uEncode((string) json_encode(['aud' => $aud, 'exp' => $now + 12 * 3600, 'sub' => $subject], JSON_UNESCAPED_SLASHES));
        $key = openssl_pkey_get_private($privatePem);
        if ($key === false || !openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('VAPID-Token ließ sich nicht signieren.');
        }
        return $input . '.' . self::b64uEncode(self::derToRaw((string) $der));
    }

    /** ECDSA-Signatur von DER (SEQUENCE aus r und s) nach roh r || s (je 32 Byte). */
    public static function derToRaw(string $der): string
    {
        $pos = 0;
        if (ord($der[$pos++] ?? "\0") !== 0x30) {
            throw new \RuntimeException('Unerwartetes Signaturformat.');
        }
        $len = ord($der[$pos++] ?? "\0");
        if ($len & 0x80) {
            $pos += $len & 0x7f;
        }
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            if (ord($der[$pos++] ?? "\0") !== 0x02) {
                throw new \RuntimeException('Unerwartetes Signaturformat.');
            }
            $n = ord($der[$pos++] ?? "\0");
            $int = ltrim(substr($der, $pos, $n), "\0");
            $pos += $n;
            if (strlen($int) > 32) {
                throw new \RuntimeException('Unerwartetes Signaturformat.');
            }
            $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
        }
        return $out;
    }

    /** Umkehrung von derToRaw() (für openssl_verify in Tests). */
    public static function rawToDer(string $raw): string
    {
        $der = '';
        foreach (str_split($raw, 32) as $int) {
            $int = ltrim($int, "\0");
            if ($int === '' || (ord($int[0]) & 0x80)) {
                $int = "\0" . $int;
            }
            $der .= "\x02" . chr(strlen($int)) . $int;
        }
        return "\x30" . chr(strlen($der)) . $der;
    }

    /** Öffentlicher Punkt als 65 Byte (0x04 || X || Y). */
    public static function rawPublic(\OpenSSLAsymmetricKey $key): string
    {
        $d = openssl_pkey_get_details($key);
        if ($d === false || !isset($d['ec']['x'], $d['ec']['y'])) {
            throw new \RuntimeException('Kein EC-Schlüssel.');
        }
        return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
    }

    /** OpenSSL-Schlüssel aus einem rohen öffentlichen P-256-Punkt; null, wenn er ungültig ist. */
    public static function publicKeyFromRaw(string $raw): ?\OpenSSLAsymmetricKey
    {
        if (strlen($raw) !== 65 || $raw[0] !== "\x04") {
            return null;
        }
        $der = (string) hex2bin(self::SPKI_PREFIX) . $raw;
        $key = @openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n");
        return $key === false ? null : $key;
    }

    public static function b64uEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /** null bei ungültiger Eingabe */
    public static function b64uDecode(string $str): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_\-]*={0,2}$/', $str)) {
            return null;
        }
        $out = base64_decode(strtr(rtrim($str, '='), '-_', '+/'), true);
        return $out === false ? null : $out;
    }

    private static function newKey(): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false) {
            throw new \RuntimeException('Schlüssel ließ sich nicht erzeugen.');
        }
        return $key;
    }

    private static function hmac(string $key, string $data): string
    {
        return hash_hmac('sha256', $data, $key, true);
    }
}
