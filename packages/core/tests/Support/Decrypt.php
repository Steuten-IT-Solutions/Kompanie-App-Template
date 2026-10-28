<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests\Support;

use KompanieApp\Core\Storage\BackupCrypto;

/** Verschlüsselte Sicherung im Arbeitsspeicher entschlüsseln (nur für Tests; die App arbeitet mit Strömen). */
final class Decrypt
{
    public static function string(string $data, string $passphrase): string
    {
        $in = fopen('php://memory', 'w+b');
        $out = fopen('php://memory', 'w+b');
        fwrite($in, $data);
        rewind($in);
        try {
            BackupCrypto::decryptStream($in, $out, $passphrase);
            rewind($out);
            return (string) stream_get_contents($out);
        } finally {
            fclose($in);
            fclose($out);
        }
    }
}
