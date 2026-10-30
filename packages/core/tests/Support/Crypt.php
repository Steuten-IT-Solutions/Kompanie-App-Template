<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests\Support;

use KompanieApp\Core\Storage\BackupCrypto;

/** Verschlüsseln und Entschlüsseln für Tests (die App nutzt BackupCrypto::encryptTo und ::decrypt direkt). */
final class Crypt
{
    /**
     * @param resource $in
     * @param resource $out
     * @param array<string, mixed> $key Einstellung BackupCrypto::SETTING
     * @param array<string, scalar> $meta
     */
    public static function encrypt($in, $out, array $key, array $meta = []): void
    {
        BackupCrypto::encryptTo($out, $key, $meta, static fn ($out) => BackupCrypto::copy($in, $out));
    }

    public static function string(string $data, string $passphrase): string
    {
        $in = fopen('php://memory', 'w+b');
        fwrite($in, $data);
        rewind($in);
        try {
            return BackupCrypto::decrypt($in, $passphrase);
        } finally {
            fclose($in);
        }
    }
}
