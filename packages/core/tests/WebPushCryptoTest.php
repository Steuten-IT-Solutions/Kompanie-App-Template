<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\Push\WebPushCrypto as C;
use PHPUnit\Framework\TestCase;

/** Web-Push-Kryptografie (Issue #19): RFC 8291 mit dem offiziellen Testvektor, VAPID nach RFC 8292. */
final class WebPushCryptoTest extends TestCase
{
    /** Privater EC-Schlüssel aus rohem d und öffentlichem Punkt (für den festen Absender des Testvektors). */
    private static function privateKey(string $d, string $public): \OpenSSLAsymmetricKey
    {
        $der = hex2bin('30770201010420') . $d . hex2bin('a00a06082a8648ce3d030107a144034200') . $public;
        $key = openssl_pkey_get_private("-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n");
        self::assertNotFalse($key);
        return $key;
    }

    private static function b64(string $s): string
    {
        $out = C::b64uDecode($s);
        self::assertNotNull($out);
        return $out;
    }

    /** Entschlüsselt als Gerät (Gegenstück zu encrypt). */
    private static function decrypt(string $body, \OpenSSLAsymmetricKey $device, string $auth): string|false
    {
        $salt = substr($body, 0, 16);
        $idlen = ord($body[20]);
        $asPublic = substr($body, 21, $idlen);
        $data = substr($body, 21 + $idlen);
        $uaPublic = C::rawPublic($device);
        $asKey = C::publicKeyFromRaw($asPublic);
        self::assertNotNull($asKey);
        $ecdh = (string) openssl_pkey_derive($asKey, $device, 32);
        $prkKey = hash_hmac('sha256', $ecdh, $auth, true);
        $ikm = hash_hmac('sha256', "WebPush: info\0" . $uaPublic . $asPublic . "\x01", $prkKey, true);
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);
        $plain = openssl_decrypt(substr($data, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($data, -16));
        return $plain === false ? false : rtrim($plain, "\x02");
    }

    public function testRequirementsAreMetHere(): void
    {
        $this->assertSame([], C::requirements());
    }

    /** RFC 8291, Anhang A (Werte gegen den Text des RFC geprüft). */
    public function testOfficialTestVector(): void
    {
        $asPublic = self::b64('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8');
        $asKey = self::privateKey(self::b64('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw'), $asPublic);
        $uaPublic = self::b64('BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4');
        $uaKey = C::publicKeyFromRaw($uaPublic);
        $this->assertNotNull($uaKey);
        $this->assertSame('kyrL1jIIOHEzg3sM2ZWRHDRB62YACZhhSlknJ672kSs', C::b64uEncode((string) openssl_pkey_derive($uaKey, $asKey, 32)), 'ECDH wie im RFC');

        $body = C::encrypt(
            self::b64('V2hlbiBJIGdyb3cgdXAsIEkgd2FudCB0byBiZSBhIHdhdGVybWVsb24'),
            $uaPublic,
            self::b64('BTBZMqHH6r4Tts7J_aSIgg'),
            self::b64('DGv6ra1nlYgDCS1FRnbzlw'),
            $asKey,
        );
        // Header (86 Byte) und Chiffrat stehen im RFC getrennt.
        $this->assertSame('DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8', C::b64uEncode(substr($body, 0, 86)));
        $this->assertSame('8pfeW0KbunFT06SuDKoJH9Ql87S1QUrdirN6GcG7sFz1y1sqLgVi1VhjVkHsUoEsbI_0LpXMuGvnzQ', C::b64uEncode(substr($body, 86)));
    }

    public function testRoundTripWithRandomKeys(): void
    {
        $device = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->assertNotFalse($device);
        $auth = random_bytes(16);
        $plain = '{"title":"Größe ẞ äöü"}';
        $body = C::encrypt($plain, C::rawPublic($device), $auth);
        $this->assertSame($plain, self::decrypt($body, $device, $auth));
        $this->assertSame(86 + strlen($plain) + 1 + 16, strlen($body), '86 Byte Header + Inhalt + Trennbyte + Tag');
    }

    public function testInvalidDeviceKeyIsRefused(): void
    {
        $this->assertNull(C::publicKeyFromRaw(str_repeat("\x04", 65)), 'kein Punkt auf der Kurve');
        $this->expectException(\RuntimeException::class);
        C::encrypt('x', 'kurz', random_bytes(16));
    }

    public function testVapidTokenIsAValidEs256Jwt(): void
    {
        $keys = C::newKeyPair();
        $this->assertSame(65, strlen((string) C::b64uDecode($keys['public'])));
        $now = 1792368000;
        $pub = C::publicKeyFromRaw((string) C::b64uDecode($keys['public']));
        $this->assertNotNull($pub);
        for ($i = 0; $i < 20; $i++) {
            $jwt = C::vapidToken('https://push.example.test:8443/send/abc', $keys['private'], 'mailto:admin@example.org', $now);
            [$h, $c, $s] = explode('.', $jwt) + ['', '', ''];
            $this->assertSame(['typ' => 'JWT', 'alg' => 'ES256'], json_decode((string) C::b64uDecode($h), true));
            $this->assertSame(['aud' => 'https://push.example.test:8443', 'exp' => $now + 43200, 'sub' => 'mailto:admin@example.org'], json_decode((string) C::b64uDecode($c), true));
            $raw = (string) C::b64uDecode($s);
            $this->assertSame(64, strlen($raw));
            $this->assertSame(1, openssl_verify($h . '.' . $c, C::rawToDer($raw), $pub, OPENSSL_ALGO_SHA256), 'Signatur gültig (auch bei führenden Nullen)');
        }
    }
}
