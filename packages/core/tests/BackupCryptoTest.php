<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\Storage\BackupCrypto;
use KompanieApp\Core\Tests\Support\Crypt;
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
        Crypt::encrypt($in, $out, self::$key, $meta);
        rewind($out);
        return (string) stream_get_contents($out);
    }

    public function testRoundTrip(): void
    {
        foreach ([0, 1, 65536, 65537, 3 * 65536 + 17] as $n) {
            $plain = $n > 0 ? random_bytes($n) : '';
            $enc = self::encrypt($plain);
            $this->assertTrue(BackupCrypto::isEncrypted($enc), "$n Bytes");
            $this->assertSame($plain, Crypt::string($enc, self::PASS), "$n Bytes");
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
        Crypt::string(self::encrypt('{}'), 'falsches-passwort');
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
                Crypt::string($bad, self::PASS);
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
        Crypt::string($bad, self::PASS);
    }

    /** Fehler beim Verschlüsseln kommen als RuntimeException (Aufrufer vor einem Update fangen nur diese). */
    public function testErrorsAreRuntimeExceptions(): void
    {
        $this->expectException(\RuntimeException::class);
        self::encrypt('{}', ['kompanie' => "kaputt \xB1\x31"]); // kein gültiges UTF-8: json_encode wirft JsonException
    }

    /** Bricht das Schreiben ab, endet die Datei ohne Abschluss: Sie gilt nie als vollständige Sicherung. */
    public function testAbortedWriteIsNeverComplete(): void
    {
        $out = fopen('php://memory', 'w+b');
        try {
            BackupCrypto::encryptTo($out, self::$key, [], static function ($o): void {
                fwrite($o, str_repeat('x', 200000));
                throw new \RuntimeException('Datenbank weg');
            });
            $this->fail('Fehler erwartet');
        } catch (\RuntimeException $e) {
            $this->assertSame('Datenbank weg', $e->getMessage());
        }
        fclose($out); // auch beim Schließen kein Abschluss
        $out = fopen('php://memory', 'w+b');
        try {
            BackupCrypto::encryptTo($out, self::$key, [], static function ($o): void {
                fwrite($o, str_repeat('x', 200000));
                throw new \RuntimeException('Datenbank weg');
            });
        } catch (\RuntimeException) {
        }
        rewind($out);
        $this->expectExceptionMessage('beschädigt');
        BackupCrypto::decrypt($out, self::PASS);
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
            Crypt::encrypt($plain, $enc, self::$key);
            if ($measure) {
                $this->assertLessThan(4 * 1024 * 1024, memory_get_peak_usage() - $before, '20 MB verschlüsseln mit wenig Speicher');
            }
            rewind($enc);
            $this->assertSame(hash_file('sha256', "$dir/plain"), hash('sha256', BackupCrypto::decrypt($enc, self::PASS)));
            fclose($plain);
            fclose($enc);
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
