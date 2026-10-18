<?php

declare(strict_types=1);

namespace KompanieApp\Core\Update;

/**
 * Signierte JSON-Dateien (release.json, updates.json):
 *
 *   {"format": "kompanie-app-signed", "keyId": "k1", "payload": "<Base64 der JSON-Bytes>", "signature": "<Base64>"}
 *
 * Signiert werden genau die Bytes des Inhalts (Ed25519, libsodium). Dadurch gibt es keine
 * Mehrdeutigkeit bei der JSON-Darstellung; geprüft wird vor dem Auswerten.
 */
final class SignedEnvelope
{
    public const FORMAT = 'kompanie-app-signed';

    /**
     * @param array<string, mixed> $payload
     * @param string $secretKey 64 Byte (sodium_crypto_sign_secretkey)
     */
    public static function sign(array $payload, string $secretKey, string $keyId): string
    {
        self::requireSodium();
        $bytes = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return (string) json_encode([
            'format' => self::FORMAT,
            'keyId' => $keyId,
            'payload' => base64_encode($bytes),
            'signature' => base64_encode(sodium_crypto_sign_detached($bytes, $secretKey)),
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    }

    /**
     * Prüft die Signatur mit den vertrauenswürdigen Schlüsseln und gibt den Inhalt zurück.
     *
     * @param array<string, string> $trustedKeys keyId => öffentlicher Schlüssel (Base64, 32 Byte)
     * @return array<string, mixed>
     * @throws \RuntimeException bei fehlender oder falscher Signatur
     */
    public static function open(string $json, array $trustedKeys): array
    {
        self::requireSodium();
        $env = json_decode($json, true);
        if (!is_array($env) || ($env['format'] ?? '') !== self::FORMAT || !is_string($env['payload'] ?? null) || !is_string($env['signature'] ?? null)) {
            throw new \RuntimeException('Die Datei ist nicht signiert.');
        }
        $keyId = (string) ($env['keyId'] ?? '');
        $public = isset($trustedKeys[$keyId]) ? base64_decode($trustedKeys[$keyId], true) : false;
        if ($public === false || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new \RuntimeException('Die Datei ist mit einem unbekannten Schlüssel signiert.');
        }
        $bytes = base64_decode($env['payload'], true);
        $sig = base64_decode($env['signature'], true);
        if ($bytes === false || $sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached($sig, $bytes, $public)) {
            throw new \RuntimeException('Die Signatur ist ungültig. Die Datei wurde verändert oder stammt nicht von uns.');
        }
        $payload = json_decode($bytes, true);
        if (!is_array($payload)) {
            throw new \RuntimeException('Der signierte Inhalt ist kein gültiges JSON.');
        }
        return $payload;
    }

    /**
     * Schlüssel aus Base64 (64 Byte geheimer Schlüssel); gibt [geheim, öffentlich] zurück.
     *
     * @return array{0: string, 1: string}
     */
    public static function keyPairFromSecret(string $secretBase64): array
    {
        self::requireSodium();
        $secret = base64_decode(trim($secretBase64), true);
        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \RuntimeException('Der Signaturschlüssel hat nicht das erwartete Format (Base64, 64 Byte).');
        }
        return [$secret, sodium_crypto_sign_publickey_from_secretkey($secret)];
    }

    /** Kennung eines öffentlichen Schlüssels: die ersten 8 Zeichen seines SHA-256 (hex). */
    public static function keyId(string $publicKey): string
    {
        return substr(hash('sha256', $publicKey), 0, 8);
    }

    private static function requireSodium(): void
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new \RuntimeException('PHP kann keine Signaturen prüfen (Erweiterung sodium fehlt). Sie lässt sich meist im Kundenmenü des Hosters einschalten.');
        }
    }
}
