<?php
/**
 * Packt die gebaute App einer Kompanie in die beiden Pakete und schreibt release.json.
 *
 *   php scripts/package.php --config=pfad/kompanie.json --version=0.1.0.3 --out=dist/release [--kompanie=org/repo]
 *
 * Vorher: scripts/build-frontend.sh mit KOMPANIE_CONFIG/KOMPANIE_ASSETS derselben Kompanie.
 * Signiert wird mit dem Schlüssel aus der Umgebungsvariablen KOMPANIE_SIGNING_KEY (Base64);
 * ohne Schlüssel entsteht release.json unsigniert (nur für Tests; Installer und Updater lehnen sie ab).
 *
 * Ergebnis in --out:
 *   kompanie-app-wordpress.zip   Plugin zum Hochladen in WordPress (Ordner kompanie-app/)
 *   kompanie-app-standalone.zip  eigenständige Version (Ordner kompanie-app/), lädt der Installer
 *   release.json                 Version, Voraussetzungen, SHA-256 der Pakete; signiert
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/adapters/standalone/autoload.php';

use KompanieApp\Core\Storage\Sql\Migrations as CoreMigrations;
use KompanieApp\Core\Update\SignedEnvelope;
use KompanieApp\Core\Update\Version;
use KompanieApp\Standalone\Migrations as StandaloneMigrations;

$opt = getopt('', ['config:', 'version:', 'out:', 'kompanie::', 'require-signature']);
$config = (string) ($opt['config'] ?? '');
$version = (string) ($opt['version'] ?? '');
$out = rtrim((string) ($opt['out'] ?? ''), '/');
$fail = static function (string $msg): never {
    fwrite(STDERR, "Fehler: $msg\n");
    exit(1);
};
if (!is_file($config) || $out === '' || !Version::valid($version)) {
    $fail('Aufruf: php scripts/package.php --config=kompanie.json --version=1.2.3.4 --out=ordner');
}
if (!class_exists(ZipArchive::class)) {
    $fail('PHP-Erweiterung zip fehlt.');
}
$kompanie = json_decode((string) file_get_contents($config), true);
if (!is_array($kompanie) || !is_string($kompanie['name'] ?? null)) {
    $fail('kompanie.json ist nicht lesbar.');
}
$dist = $root . '/packages/frontend/dist';
if (!is_file($dist . '/index.html') || !is_file($dist . '/sw.js')) {
    $fail('Die App ist nicht gebaut (packages/frontend/dist). Erst scripts/build-frontend.sh ausführen.');
}
$brand = json_decode((string) @file_get_contents($root . '/packages/frontend/src/generated/brand.json'), true);
if (($brand['name'] ?? null) !== trim($kompanie['name'])) {
    $fail('Die gebaute App gehört zu einer anderen Kompanie („' . ($brand['name'] ?? '?') . '“). Bitte mit KOMPANIE_CONFIG=' . $config . ' neu bauen.');
}
@mkdir($out, 0775, true);

/** Alle Dateien unter $dir (relativ), sortiert; ohne Ausnahmen. */
function kompanie_files(string $dir, array $exclude = []): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $rel = substr($f->getPathname(), strlen($dir) + 1);
        foreach ($exclude as $pattern) {
            if (preg_match($pattern, $rel)) {
                continue 2;
            }
        }
        $files[$rel] = $f->getPathname();
    }
    ksort($files);
    return $files;
}

/** @param array<string, string> $entries Pfad im ZIP => Quelldatei oder ['content' => …] */
function kompanie_zip(string $file, array $entries): void
{
    @unlink($file);
    $zip = new ZipArchive();
    if ($zip->open($file, ZipArchive::CREATE) !== true) {
        throw new RuntimeException("ZIP kann nicht angelegt werden: $file");
    }
    ksort($entries);
    foreach ($entries as $name => $src) {
        is_array($src) ? $zip->addFromString($name, $src['content']) : $zip->addFile($src, $name);
        // Feste Zeit: gleiche Eingaben ergeben gleiche Pakete.
        $zip->setMtimeName($name, 946684800);
    }
    $zip->close();
}

$core = kompanie_files($root . '/packages/core/src');
$app = kompanie_files($dist);
$kompanieJson = ['content' => (string) file_get_contents($config)];

// WordPress-Plugin
$wp = [];
foreach (kompanie_files($root . '/adapters/wordpress', ['#^app/#', '#^kompanie\.json$#']) as $rel => $src) {
    $wp["kompanie-app/$rel"] = $src;
}
$main = (string) file_get_contents($root . '/adapters/wordpress/kompanie-app.php');
$main = (string) preg_replace('/^( \* Version:\s+)\S+/m', '${1}' . $version, $main, 1, $n1);
$main = (string) preg_replace("/define\\( 'KOMPANIE_APP_VERSION', '[^']*' \\);/", "define( 'KOMPANIE_APP_VERSION', '$version' );", $main, 1, $n2);
if ($n1 !== 1 || $n2 !== 1) {
    $fail('Version in kompanie-app.php nicht gefunden.');
}
$wp['kompanie-app/kompanie-app.php'] = ['content' => $main];
foreach ($core as $rel => $src) {
    $wp["kompanie-app/core/$rel"] = $src;
}
foreach ($app as $rel => $src) {
    $wp["kompanie-app/app/$rel"] = $src;
}
$wp['kompanie-app/kompanie.json'] = $kompanieJson;

// Eigenständige Version
$sa = [];
foreach (kompanie_files($root . '/adapters/standalone', ['#^(app|data|media)/#', '#^(sw\.js|kompanie\.json|VERSION|data-dir\.php)$#']) as $rel => $src) {
    $sa["kompanie-app/$rel"] = $src;
}
foreach ($core as $rel => $src) {
    $sa["kompanie-app/core/$rel"] = $src;
}
foreach ($app as $rel => $src) {
    $sa["kompanie-app/app/$rel"] = $src;
}
$sa['kompanie-app/core/.htaccess'] = ['content' => (string) file_get_contents($root . '/adapters/standalone/src/.htaccess')];
$sa['kompanie-app/sw.js'] = $dist . '/sw.js';
$sa['kompanie-app/kompanie.json'] = $kompanieJson;
$sa['kompanie-app/VERSION'] = ['content' => $version . "\n"];

$packages = ['wordpress' => $wp, 'standalone' => $sa];
$files = [];
foreach ($packages as $variant => $entries) {
    $name = "kompanie-app-$variant.zip";
    kompanie_zip("$out/$name", $entries);
    $files[$variant] = ['name' => $name, 'sha256' => hash_file('sha256', "$out/$name"), 'size' => filesize("$out/$name")];
}

$release = [
    'kompanie' => (string) ($opt['kompanie'] ?? ''),
    'name' => trim($kompanie['name']),
    'version' => $version,
    'major' => Version::major($version),
    'coreVersion' => trim((string) file_get_contents($root . '/VERSION')),
    'minPhp' => '8.1',
    'minWordPress' => '6.2',
    'extensions' => ['pdo', 'json', 'mbstring', 'openssl', 'curl', 'gd', 'sodium', 'zip', 'simplexml'],
    'schema' => ['core' => CoreMigrations::latest(), 'standalone' => max(array_keys(StandaloneMigrations::all()))],
    'published' => gmdate('Y-m-d'),
    'files' => $files,
];

$key = getenv('KOMPANIE_SIGNING_KEY');
if (is_string($key) && trim($key) !== '') {
    [$secret, $public] = SignedEnvelope::keyPairFromSecret($key);
    file_put_contents("$out/release.json", SignedEnvelope::sign($release, $secret, SignedEnvelope::keyId($public)));
    echo "Signiert mit Schlüssel " . SignedEnvelope::keyId($public) . ".\n";
} elseif (isset($opt['require-signature'])) {
    $fail('KOMPANIE_SIGNING_KEY fehlt; ohne Signatur wird nichts veröffentlicht.');
} else {
    file_put_contents("$out/release.json", json_encode($release, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    fwrite(STDERR, "Hinweis: ohne KOMPANIE_SIGNING_KEY – release.json ist nicht signiert (nur für Tests).\n");
}
foreach ($files as $variant => $f) {
    printf("%-11s %s  %6.1f KB  %s\n", $variant, $f['name'], $f['size'] / 1024, $f['sha256']);
}
