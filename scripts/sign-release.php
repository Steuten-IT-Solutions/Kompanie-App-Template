<?php
/**
 * Signiert ein gebautes Release (im Workflow ein eigener Schritt ohne npm, nur mit diesem Skript):
 *   php scripts/sign-release.php --dir=ordner [--trust-signing-key]
 *
 * Erwartet in --dir die von scripts/package.php ohne Schlüssel erzeugte release.json und die
 * darin genannten Pakete. Prüft jedes Paket gegen seinen SHA-256 und seine Größe und schreibt
 * release.json signiert (Ed25519) zurück. Der Schlüssel kommt aus KOMPANIE_SIGNING_KEY und muss
 * in TrustedKeys stehen (sonst könnten die Installationen die Signatur nicht prüfen).
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/packages/core/src/Update/SignedEnvelope.php';
require $root . '/packages/core/src/Update/TrustedKeys.php';
require $root . '/packages/core/src/Update/Version.php';

use KompanieApp\Core\Update\SignedEnvelope;
use KompanieApp\Core\Update\TrustedKeys;
use KompanieApp\Core\Update\Version;

$opt = getopt('', ['dir:', 'trust-signing-key']);
$fail = static function (string $msg): never {
    fwrite(STDERR, "Fehler: $msg\n");
    exit(1);
};
$dir = rtrim((string) ($opt['dir'] ?? ''), '/');
$release = json_decode((string) @file_get_contents("$dir/release.json"), true, 32);
if (!is_array($release) || isset($release['format']) || ($release['type'] ?? '') !== 'release' || !Version::valid((string) ($release['version'] ?? ''))) {
    $fail("$dir/release.json fehlt, ist schon signiert oder ungültig.");
}
foreach ((array) ($release['files'] ?? []) as $variant => $f) {
    $file = $dir . '/' . basename((string) ($f['name'] ?? ''));
    if (!is_file($file) || hash_file('sha256', $file) !== ($f['sha256'] ?? '') || filesize($file) !== (int) ($f['size'] ?? -1)) {
        $fail("Paket $variant passt nicht zu release.json.");
    }
}
if (count((array) ($release['files'] ?? [])) !== 2) {
    $fail('release.json muss genau zwei Pakete nennen.');
}
$key = (string) getenv('KOMPANIE_SIGNING_KEY');
if ($key === '') {
    $fail('KOMPANIE_SIGNING_KEY fehlt.');
}
try {
    [$secret, $public] = SignedEnvelope::keyPairFromSecret($key);
} catch (RuntimeException $e) {
    $fail($e->getMessage());
}
$id = SignedEnvelope::keyId($public);
if (!isset(TrustedKeys::all()[$id]) && !isset($opt['trust-signing-key'])) {
    $fail("Der Signaturschlüssel ($id) steht nicht in packages/core/src/Update/TrustedKeys.php.");
}
file_put_contents("$dir/release.json", SignedEnvelope::sign($release, $secret, $id));
sodium_memzero($secret);
echo "release.json für Version {$release['version']} signiert (Schlüssel $id).\n";
