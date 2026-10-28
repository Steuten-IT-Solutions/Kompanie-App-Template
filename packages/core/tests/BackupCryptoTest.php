<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\Storage\BackupCrypto;
use PHPUnit\Framework\TestCase;

/** Verschlüsselte Sicherungen: Hin und zurück, falsches Passwort, beschädigte und abgeschnittene Dateien, Speicher. */
final class BackupCryptoTest extends TestCase
{
    private const PASS = 'richtig-langes-passwort';
    /** @var array{public_key: string, salt: string, ops: int, mem: int} */
    private static array $key;

    public static function setUpBeforeClass(): void
    {
        // Kleinste Parameter: schnell; die Vorgabe (INTERACTIVE) prüft testDefaultParameters.
        self::$key = BackupCrypto::newKey(self::PASS, 1, 8192);
    }

    private static function encrypt(string $plain, array $meta = []): string
    {
        $in = fopen('php://memory', 'w+b');
        $out = fopen('php://memory', 'w+b');
        fwrite($in, $plain);
        rewind($in);
        BackupCrypto::encryptStream($in, $out, self::$key, $meta);
        rewind($out);
        return (string) stream_get_contents($out);
    }

    public function testRoundTrip(): void
    {
        foreach ([0, 1, 65536, 65537, 3 * 65536 + 17] as $n) {
            $plain = $n > 0 ? random_bytes($n) : '';
            $enc = self::encrypt($plain);
            $this->assertTrue(BackupCrypto::isEncrypted($enc), "$n Bytes");
            $this->assertSame($plain, BackupCrypto::decryptToString($enc, self::PASS), "$n Bytes");
        }
        $json = '{"format":"kompanie-app-backup","tables":{"points":[{"reason":"Geheimnis"}]}}';
        $enc = self::encrypt($json, ['version' => '1.2.3']);
        $this->assertStringNotContainsString('Geheimnis', $enc, 'Inhalt nicht lesbar');
        $this->assertStringContainsString('"version":"1.2.3"', $enc, 'Angaben im Kopf lesbar');
        $this->assertFalse(BackupCrypto::isEncrypted($json));
    }

    public function testWrongPassphrase(): void
    {
        $this->expectExceptionMessage('Das Passwort für Sicherungen passt nicht');
        BackupCrypto::decryptToString(self::encrypt('{}'), 'falsches-passwort');
    }

    public function testDetectsTamperingAndTruncation(): void
    {
        $enc = self::encrypt(random_bytes(200000));
        $headerEnd = strlen(BackupCrypto::MAGIC) + 4 + unpack('N', substr($enc, strlen(BackupCrypto::MAGIC), 4))[1];
        $cases = [
            'abgeschnitten' => substr($enc, 0, -1),
            'ohne letzten Block' => substr($enc, 0, strlen($enc) - (4 + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES)),
            'angehängt' => $enc . 'x',
            'Bit im Block' => self::flip($enc, $headerEnd + 100),
            'Kopf geändert' => preg_replace('/"fingerprint":"(.)/', '"fingerprint":"' . (str_contains($enc, '"fingerprint":"0') ? '1' : '0'), $enc, 1),
            'nur der Kopf' => substr($enc, 0, $headerEnd),
        ];
        foreach ($cases as $label => $bad) {
            try {
                BackupCrypto::decryptToString($bad, self::PASS);
                $this->fail("$label: nicht erkannt");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('beschädigt', $e->getMessage(), $label);
            }
        }
    }

    public function testRejectsHugeParametersFromFile(): void
    {
        $enc = self::encrypt('{}');
        $bad = str_replace('"mem":8192', '"mem":99999999999', $enc);
        $bad = substr_replace($bad, pack('N', strlen($bad) - strlen($enc) + unpack('N', substr($enc, strlen(BackupCrypto::MAGIC), 4))[1]), strlen(BackupCrypto::MAGIC), 4);
        $this->expectExceptionMessage('beschädigt');
        BackupCrypto::decryptToString($bad, self::PASS);
    }

    public function testMatchesAndFingerprint(): void
    {
        $this->assertTrue(BackupCrypto::matches(self::$key, self::PASS));
        $this->assertFalse(BackupCrypto::matches(self::$key, self::PASS . 'x'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{4}( [0-9a-f]{4}){3}$/', BackupCrypto::fingerprint(self::$key['public_key']));
        $other = BackupCrypto::newKey(self::PASS, 1, 8192);
        $this->assertNotSame(self::$key['public_key'], $other['public_key'], 'neues Salz, neuer Schlüssel');
        $this->assertArrayNotHasKey('secret_key', $other, 'nur der öffentliche Schlüssel wird gespeichert');
    }

    public function testDefaultParameters(): void
    {
        $key = BackupCrypto::newKey(self::PASS);
        $this->assertSame([SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE], [$key['ops'], $key['mem']]);
        $this->assertTrue(BackupCrypto::matches($key, self::PASS));
    }

    /** Große Sicherungen gehen in Blöcken durch, nicht ganz in den Arbeitsspeicher. */
    public function testStreamsWithLittleMemory(): void
    {
        $dir = sys_get_temp_dir() . '/kompanie-crypt-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $plain = fopen("$dir/plain", 'w+b');
            $block = random_bytes(1024 * 1024);
            for ($i = 0; $i < 20; $i++) {
                fwrite($plain, $block);
            }
            rewind($plain);
            $enc = fopen("$dir/enc", 'w+b');
            // Den Spitzenwert zurücksetzen gibt es erst ab PHP 8.2; unter 8.1 nur Hin und zurück prüfen.
            $measure = function_exists('memory_reset_peak_usage');
            $before = memory_get_usage();
            if ($measure) {
                memory_reset_peak_usage();
            }
            BackupCrypto::encryptStream($plain, $enc, self::$key);
            rewind($enc);
            $back = fopen("$dir/back", 'w+b');
            BackupCrypto::decryptStream($enc, $back, self::PASS);
            if ($measure) {
                $this->assertLessThan(4 * 1024 * 1024, memory_get_peak_usage() - $before, '20 MB mit wenig Speicher');
            }
            $this->assertSame(hash_file('sha256', "$dir/plain"), hash_file('sha256', "$dir/back"));
            fclose($plain);
            fclose($enc);
            fclose($back);
        } finally {
            array_map('unlink', glob("$dir/*") ?: []);
            rmdir($dir);
        }
    }

    private static function flip(string $s, int $pos): string
    {
        $s[$pos] = chr(ord($s[$pos]) ^ 1);
        return $s;
    }
}
