<?php
/**
 * Kern-Version und Code-Prüfsumme einer veröffentlichten Version, im Format von STABLE_CORE
 * (aus .github/workflows/version-freigeben.yml):
 *   php scripts/checked-core.php --release=release.json
 * Die release.json muss signiert sein (Schlüssel aus TrustedKeys; für Tests zusätzlich der aus
 * KOMPANIE_SIGNING_KEY). Ausgabe: „0.1.0 8c283abe…“.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/adapters/standalone/autoload.php';

use KompanieApp\Core\Update\SignedEnvelope;
use KompanieApp\Core\Update\TrustedKeys;

$opt = getopt('', ['release:']);
$fail = static function (string $msg): never {
    fwrite(STDERR, "Fehler: $msg\n");
    exit(1);
};
$file = (string) ($opt['release'] ?? '');
if ($file === '' || !is_file($file)) {
    $fail('--release fehlt oder die Datei gibt es nicht.');
}
try {
    $trusted = TrustedKeys::all();
    $key = (string) getenv('KOMPANIE_SIGNING_KEY');
    if ($key !== '') {
        [, $public] = SignedEnvelope::keyPairFromSecret($key);
        $trusted += [SignedEnvelope::keyId($public) => base64_encode($public)];
    }
    $release = SignedEnvelope::open((string) file_get_contents($file), $trusted);
} catch (RuntimeException $e) {
    $fail('release.json nicht gültig signiert: ' . $e->getMessage());
}
$core = (string) ($release['coreVersion'] ?? '');
$code = (string) ($release['coreCode'] ?? '');
if (($release['type'] ?? 'release') !== 'release' || !preg_match('/^\d{1,4}\.\d{1,4}\.\d{1,4}$/', $core) || !preg_match('/^[a-f0-9]{64}$/', $code)) {
    $fail('release.json enthält keine Kern-Version mit Code-Prüfsumme.');
}
echo $core, ' ', $code, "\n";
