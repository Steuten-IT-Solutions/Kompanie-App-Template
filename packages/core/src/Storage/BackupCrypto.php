<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

/**
 * Verschlüsselte Sicherungen (libsodium), damit eine Sicherung außerhalb des Servers (Google Drive, heruntergeladene
 * Datei) ohne das Passwort für Sicherungen nicht lesbar ist.
 *
 * Schlüssel: Aus dem Passwort und einem zufälligen Salz entsteht mit Argon2id (sodium_crypto_pwhash) ein Schlüsselpaar
 * (X25519). Gespeichert wird nur der öffentliche Schlüssel mit Salz und Parametern (Einstellung SETTING): Damit kann
 * der Server Sicherungen jederzeit verschlüsseln (auch die wöchentliche nach Drive), lesen kann sie nur, wer das
 * Passwort kennt. Ohne das Passwort lässt sich eine verschlüsselte Sicherung nicht wiederherstellen, auch nicht von uns.
 *
 * Datei:
 *
 *   MAGIC | uint32 Länge des Kopfs | Kopf (JSON) | Blöcke: uint32 Länge | Block (secretstream xchacha20poly1305)
 *
 * Der Kopf nennt Salz und Parameter (so bleiben ältere Dateien nach einem neuen Passwort lesbar, mit dem alten), den
 * mit crypto_box_seal für den öffentlichen Schlüssel versiegelten Dateischlüssel und den Kopf des secretstream. Der
 * erste Block bindet den Kopf als zusätzliche Daten (AD), ein geänderter Kopf fällt also auf. Der letzte Block trägt
 * TAG_FINAL: Eine abgeschnittene Datei fällt ebenso auf. Die Daten gehen in Blöcken von CHUNK Bytes durch, die
 * Sicherung muss dafür nie ganz in den Arbeitsspeicher.
 */
final class BackupCrypto
{
    public const MAGIC = "KOMPANIE-BACKUP-ENC\n";
    public const FORMAT_VERSION = 1;
    /** Einstellung: {public_key, salt (Base64), ops, mem, set_at} */
    public const SETTING = 'backup_encryption';
    /** Mindestlänge des Passworts für Sicherungen */
    public const MIN_LENGTH = 12;
    /** Endung verschlüsselter Sicherungen */
    public const EXTENSION = '.json.enc';
    /** Code der RuntimeException, wenn das Passwort nicht passt (zählt als Fehlversuch, eine beschädigte Datei nicht) */
    public const WRONG_PASSPHRASE = 1;
    /** Klartext je Block */
    public const CHUNK = 65536;
    /** Obergrenzen für Parameter aus dem Kopf einer Datei (eine präparierte Datei soll den Server nicht lahmlegen). */
    private const MIN_OPS = 1;
    private const MIN_MEM = 8192;
    /**
     * Höchstens MODERATE (3 Durchgänge, 256 MiB): Die Werte stehen ungeprüft im Kopf, und libsodium holt den Speicher
     * außerhalb von memory_limit. Erzeugt werden Dateien mit INTERACTIVE (2, 64 MiB).
     */
    private const MAX_OPS = 3;
    private const MAX_MEM = 256 * 1024 * 1024;
    private const MAX_HEADER = 8192;

    /** Ob PHP alles Nötige mitbringt (libsodium ab PHP 7.2 eingebaut, aber abschaltbar). */
    public static function available(): bool
    {
        return function_exists('sodium_crypto_pwhash') && function_exists('sodium_crypto_box_seal')
            && function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push') && defined('SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13');
    }

    /**
     * Neuer Schlüssel aus dem Passwort: Wert für die Einstellung SETTING (ohne geheimen Schlüssel).
     * $ops/$mem nur für Tests kleiner als die Vorgabe (INTERACTIVE: 64 MiB, läuft auch auf kleinen Webspaces).
     *
     * @return array{public_key: string, salt: string, ops: int, mem: int}
     */
    public static function newKey(string $passphrase, ?int $ops = null, ?int $mem = null): array
    {
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $ops ??= SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE;
        $mem ??= SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE;
        $keypair = self::keypair($passphrase, $salt, $ops, $mem);
        $public = sodium_crypto_box_publickey($keypair);
        sodium_memzero($keypair);
        return ['public_key' => base64_encode($public), 'salt' => base64_encode($salt), 'ops' => $ops, 'mem' => $mem];
    }

    /** Passt das Passwort zum gespeicherten Schlüssel? */
    public static function matches(array $key, string $passphrase): bool
    {
        self::requireAvailable();
        $salt = base64_decode((string) ($key['salt'] ?? ''), true);
        $public = base64_decode((string) ($key['public_key'] ?? ''), true);
        if ($salt === false || $public === false || strlen($salt) !== SODIUM_CRYPTO_PWHASH_SALTBYTES || strlen($public) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new \RuntimeException('Das eingerichtete Passwort für Sicherungen ist beschädigt. Bitte neu einrichten.');
        }
        $keypair = self::keypair($passphrase, $salt, (int) ($key['ops'] ?? 0), (int) ($key['mem'] ?? 0));
        $ok = hash_equals($public, sodium_crypto_box_publickey($keypair));
        sodium_memzero($keypair);
        return $ok;
    }

    /** @throws \RuntimeException ohne sodium (z. B. später abgeschaltet), statt eines Fehlers über fehlende Konstanten */
    private static function requireAvailable(): void
    {
        if (!self::available()) {
            throw new \RuntimeException('Dafür fehlt PHP auf diesem Server die Erweiterung sodium; bitte beim Hoster nachfragen.');
        }
    }

    /** Kurzer Fingerabdruck des öffentlichen Schlüssels (Base64), z. B. „3f2a 9c41 07be d5e0“: zeigt, welches Passwort gilt. */
    public static function fingerprint(string $publicKeyBase64): string
    {
        return implode(' ', str_split(substr(hash('sha256', (string) base64_decode($publicKeyBase64, true)), 0, 16), 4));
    }

    /** Beginnt der Inhalt wie eine verschlüsselte Sicherung? */
    public static function isEncrypted(string $prefix): bool
    {
        return str_starts_with($prefix, self::MAGIC);
    }

    /**
     * Was $write nach $out schreibt, verschlüsselt schreiben (Kopf, Blöcke, Abschluss); der eine Weg für alle
     * verschlüsselten Sicherungen.
     *
     * @param resource $out
     * @param array{public_key: string} $key
     * @param array<string, scalar> $meta
     * @param \Closure(resource): void $write
     * @return int geschriebene Bytes (zum Prüfen einer Datei auf Vollständigkeit)
     * @throws \RuntimeException
     */
    public static function encryptTo($out, array $key, array $meta, \Closure $write): int
    {
        $enc = BackupEncryptor::start($key, $meta);
        try {
            $enc->attach($out);
            $write($out);
        } catch (\Throwable $e) {
            $enc->abort(); // ohne Abschluss: bleibt als abgeschnitten erkennbar
            // Scheiterte das Verschlüsseln im Filter, ist das die Ursache (das folgende Schreiben scheitert nur deshalb).
            throw $enc->error !== null ? new \RuntimeException('Die Sicherung ließ sich nicht verschlüsseln: ' . $enc->error->getMessage(), 0, $enc->error) : $e;
        }
        $enc->finish();
        if (!fflush($out)) {
            throw new \RuntimeException('Die verschlüsselte Sicherung ließ sich nicht schreiben (Speicherplatz?).');
        }
        return $enc->bytes;
    }

    /**
     * Verschlüsselte Sicherung lesen und den Klartext als Zeichenkette liefern (für den Import, der sie ohnehin ganz
     * braucht): ohne Zwischenspeicher, nur eine Kopie im Arbeitsspeicher.
     *
     * @param resource $in
     * @throws \RuntimeException mit verständlicher Meldung (falsches Passwort: Code WRONG_PASSPHRASE, beschädigte Datei)
     */
    public static function decrypt($in, string $passphrase): string
    {
        self::requireAvailable();
        try {
            return self::readPlain($in, $passphrase);
        } catch (\SodiumException $e) {
            // z. B. ein Schlüssel falscher Länge in einer beschädigten Datei: wie jede beschädigte Datei melden
            throw new \RuntimeException(self::damaged()->getMessage(), 0, $e);
        }
    }

    /** @param resource $in */
    private static function readPlain($in, string $passphrase): string
    {
        if (self::readFull($in, strlen(self::MAGIC)) !== self::MAGIC) {
            throw new \RuntimeException('Die Datei ist keine verschlüsselte Sicherung der App.');
        }
        $len = self::readFull($in, 4);
        $headerLength = strlen($len) === 4 ? (int) unpack('N', $len)[1] : 0;
        if ($headerLength < 2 || $headerLength > self::MAX_HEADER) {
            throw self::damaged();
        }
        $header = self::readFull($in, $headerLength);
        $h = json_decode($header, true);
        if (strlen($header) !== $headerLength || !is_array($h) || ($h['v'] ?? null) !== self::FORMAT_VERSION || !is_array($h['kdf'] ?? null)) {
            throw self::damaged();
        }
        $salt = base64_decode((string) ($h['kdf']['salt'] ?? ''), true);
        $sealed = base64_decode((string) ($h['sealed_key'] ?? ''), true);
        $streamHeader = base64_decode((string) ($h['stream'] ?? ''), true);
        $ops = (int) ($h['kdf']['ops'] ?? 0);
        $mem = (int) ($h['kdf']['mem'] ?? 0);
        $chunk = (int) ($h['chunk'] ?? 0);
        if ($salt === false || $sealed === false || $streamHeader === false || strlen($salt) !== SODIUM_CRYPTO_PWHASH_SALTBYTES
            || strlen($streamHeader) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES
            || $ops > self::MAX_OPS || $mem > self::MAX_MEM || $chunk < 1 || $chunk > 16 * self::CHUNK) {
            throw self::damaged();
        }
        $keypair = self::keypair($passphrase, $salt, $ops, $mem);
        $fileKey = sodium_crypto_box_seal_open($sealed, $keypair);
        sodium_memzero($keypair);
        if ($fileKey === false) {
            throw new \RuntimeException('Das Passwort für Sicherungen passt nicht zu dieser Datei (oder die Datei ist beschädigt).', self::WRONG_PASSPHRASE);
        }
        try {
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($streamHeader, $fileKey);
            $ad = $header;
            $out = '';
            $max = $chunk + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
            while (true) {
                $len = self::readFull($in, 4);
                if (strlen($len) !== 4) {
                    throw self::damaged(); // ohne Abschluss: abgeschnitten
                }
                $n = (int) unpack('N', $len)[1];
                if ($n < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $n > $max) {
                    throw self::damaged();
                }
                $cipher = self::readFull($in, $n);
                $res = strlen($cipher) === $n ? sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher, $ad) : false;
                if ($res === false) {
                    throw self::damaged();
                }
                $ad = '';
                [$plain, $tag] = $res;
                $out .= $plain;
                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    break;
                }
            }
            if (self::readFull($in, 1) !== '') {
                throw self::damaged(); // Daten nach dem Abschluss
            }
            return $out;
        } finally {
            sodium_memzero($fileKey);
        }
    }

    /** Schlüsselpaar aus Passwort und Salz (Argon2id). */
    private static function keypair(string $passphrase, string $salt, int $ops, int $mem): string
    {
        if ($ops < self::MIN_OPS || $mem < self::MIN_MEM) {
            throw self::damaged();
        }
        try {
            $seed = sodium_crypto_pwhash(SODIUM_CRYPTO_BOX_SEEDBYTES, $passphrase, $salt, $ops, $mem, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        } catch (\SodiumException $e) {
            throw new \RuntimeException('Der Server hat zu wenig Arbeitsspeicher, um das Passwort für Sicherungen zu verarbeiten.', 0, $e);
        }
        try {
            return sodium_crypto_box_seed_keypair($seed);
        } finally {
            sodium_memzero($seed);
        }
    }

    /** @param resource $in */
    private static function readFull($in, int $length): string
    {
        $data = '';
        while (strlen($data) < $length && !feof($in)) {
            $part = fread($in, $length - strlen($data));
            if ($part === false || $part === '') {
                break;
            }
            $data .= $part;
        }
        return $data;
    }

    private static function damaged(): \RuntimeException
    {
        return new \RuntimeException('Die verschlüsselte Sicherung ist unvollständig oder beschädigt.');
    }
}
