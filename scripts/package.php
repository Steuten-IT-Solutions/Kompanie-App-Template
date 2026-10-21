<?php
/**
 * Packt die gebaute App einer Kompanie in die beiden Pakete und schreibt release.json.
 *
 *   php scripts/package.php --config=pfad/kompanie.json --version=0.1.0.3 --out=dist/release [--kompanie=org/repo]
 *       [--kompanie-commit=sha] [--dist=ordner --brand=brand.json] [--updates-url=…] [--status-url=https://…]
 *       [--report-url=https://…] [--support="Text"] [--trust-signing-key]
 *       php scripts/package.php --code-hash     nur die Prüfsumme des Programmcodes ausgeben (für STABLE_CORE)
 *       php scripts/package.php --config-hash --kompanie=org/repo [--status-url=… --report-url=… --support=…]
 *                                               nur die Prüfsumme der Paket-Konfiguration (update.json)
 *
 * Vorher: scripts/build-frontend.sh mit KOMPANIE_CONFIG/KOMPANIE_ASSETS derselben Kompanie
 * (oder die gebaute App mit --dist und deren brand.json mit --brand angeben).
 * Signiert wird mit dem Schlüssel aus der Umgebungsvariablen KOMPANIE_SIGNING_KEY (Base64);
 * ohne Schlüssel entsteht release.json unsigniert (nur für Tests; Installer und Updater lehnen sie ab).
 *
 * Ergebnis in --out:
 *   kompanie-app-wordpress.zip   Plugin zum Hochladen in WordPress (Ordner kompanie-app/)
 *   kompanie-app-standalone.zip  eigenständige Version (Ordner kompanie-app/), lädt der Installer
 *   release.json                 Version, Voraussetzungen, SHA-256 der Pakete; signiert
 *   install.php                  Installer der eigenständigen Version für diese Kompanie
 *
 * In beide Pakete kommt update.json: Adresse der Update-Liste (Standard bei --kompanie=org/repo:
 * https://org.github.io/repo/updates.json), vertrauenswürdige Schlüssel (TrustedKeys; mit
 * --trust-signing-key zusätzlich der Signaturschlüssel – nur für Tests), Statusmeldungen, Fehlermeldungen, Hilfe.
 * Mit --require-signature muss der Signaturschlüssel in TrustedKeys stehen, sonst könnten die
 * Installationen keine Updates prüfen, und --kompanie ist Pflicht.
 *
 * release.json enthält coreCode (siehe kompanie_code_hash): Prüfsumme über alle versionierten Dateien,
 * die in die Pakete eingehen, außer den Daten der Kompanie. Gleicher Wert = gleicher Programmcode,
 * unabhängig vom Commit.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/adapters/standalone/autoload.php';

use KompanieApp\Core\Storage\Sql\Migrations as CoreMigrations;
use KompanieApp\Core\Update\SignedEnvelope;
use KompanieApp\Core\Update\TrustedKeys;
use KompanieApp\Core\Update\Version;
use KompanieApp\Standalone\Migrations as StandaloneMigrations;

$opt = getopt('', ['config:', 'version:', 'out:', 'kompanie::', 'kompanie-commit::', 'dist::', 'brand::', 'require-signature', 'updates-url::', 'status-url::', 'report-url::', 'support::', 'trust-signing-key', 'code-hash', 'config-hash']);
$config = (string) ($opt['config'] ?? '');
$version = (string) ($opt['version'] ?? '');
$out = rtrim((string) ($opt['out'] ?? ''), '/');
$fail = static function (string $msg): never {
    fwrite(STDERR, "Fehler: $msg\n");
    exit(1);
};
if (isset($opt['code-hash']) || isset($opt['config-hash'])) {
    $codeHashOnly = true;
} elseif (!is_file($config) || $out === '' || !Version::valid($version)) {
    $fail('Aufruf: php scripts/package.php --config=kompanie.json --version=1.2.3.4 --out=ordner');
}
if (!isset($codeHashOnly) && !class_exists(ZipArchive::class)) {
    $fail('PHP-Erweiterung zip fehlt.');
}
if (!isset($codeHashOnly)) {
    $kompanie = json_decode((string) file_get_contents($config), true);
    if (!is_array($kompanie) || !is_string($kompanie['name'] ?? null)) {
        $fail('kompanie.json ist nicht lesbar.');
    }
    $dist = rtrim((string) ($opt['dist'] ?? $root . '/packages/frontend/dist'), '/');
    if (!is_file($dist . '/index.html') || !is_file($dist . '/sw.js')) {
        $fail("Die App ist nicht gebaut ($dist). Erst scripts/build-frontend.sh ausführen.");
    }
    $brand = json_decode((string) @file_get_contents((string) ($opt['brand'] ?? $root . '/packages/frontend/src/generated/brand.json')), true);
    if (($brand['name'] ?? null) !== trim($kompanie['name'])) {
        $fail('Die gebaute App gehört zu einer anderen Kompanie („' . ($brand['name'] ?? '?') . '“). Bitte mit KOMPANIE_CONFIG=' . $config . ' neu bauen.');
    }
    @mkdir($out, 0775, true);
}

/**
 * Alle Dateien unter $dir (relativ), sortiert; ohne Ausnahmen. In einem Git-Arbeitsbaum nur
 * versionierte Dateien (keine zufällig herumliegenden lokalen Dateien im Paket).
 */
function kompanie_files(string $dir, array $exclude = [], bool $tracked = true, bool $requireGit = false): array
{
    $files = [];
    $list = null;
    if ($tracked) {
        exec('git -C ' . escapeshellarg($dir) . ' ls-files -z . 2>/dev/null', $out, $code);
        $list = $code === 0 && $out !== [] ? array_filter(explode("\0", implode("\n", $out))) : null;
        if ($list === null && $requireGit) {
            throw new RuntimeException("git ls-files in $dir fehlgeschlagen; die Prüfsumme braucht einen Git-Arbeitsbaum.");
        }
    }
    if ($list !== null) {
        foreach ($list as $rel) {
            foreach ($exclude as $pattern) {
                if (preg_match($pattern, $rel)) {
                    continue 2;
                }
            }
            if (is_file("$dir/$rel")) {
                $files[$rel] = "$dir/$rel";
            }
        }
        ksort($files);
        return $files;
    }
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
        // Feste Zeit und feste Rechte (rw-r--r--): gleiche Eingaben ergeben gleiche Pakete, und
        // entpackte Dateien sind nie für alle beschreibbar.
        $zip->setMtimeName($name, 946684800);
        $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0100644 << 16);
    }
    $zip->close();
}

/**
 * Prüfsumme des Programmcodes: Pfad und SHA-256 jeder versionierten Datei, die in die Pakete eingeht
 * (Kern, Adapter, Installer, Oberfläche, Branding, Build- und Paket-Skripte, npm-Abhängigkeiten).
 * Nicht enthalten: Daten der Kompanie, Tests, Doku.
 */
function kompanie_code_hash(string $root): string
{
    $f = static fn (string $dir, array $exclude = []): array => kompanie_files($root . '/' . $dir, $exclude, true, true);
    $parts = [
        'core' => $f('packages/core/src'),
        'wordpress' => $f('adapters/wordpress', ['#^app/#', '#^kompanie\.json$#']),
        'standalone' => $f('adapters/standalone', ['#^(app|data|media)/#', '#^(sw\.js|kompanie\.json|VERSION|data-dir\.php)$#']),
        'frontend' => $f('packages/frontend', ['#^(e2e|test-results|dist)/#', '#^playwright\.config\.ts$#']),
        'branding' => $f('packages/branding', ['#^test/#']),
        'installer' => ['install.php' => $root . '/installer/install.php'],
        'build' => [
            'package.json' => $root . '/package.json',
            'package-lock.json' => $root . '/package-lock.json',
            'build-frontend.sh' => $root . '/scripts/build-frontend.sh',
            'package.php' => $root . '/scripts/package.php',
        ],
        'shared' => $f('adapters/shared'),
    ];
    $lines = [];
    foreach ($parts as $part => $files) {
        foreach ($files as $rel => $src) {
            $lines[] = "$part/$rel\t" . hash_file('sha256', $src);
        }
    }
    return hash('sha256', implode("\n", $lines));
}

if (isset($opt['code-hash'])) {
    echo kompanie_code_hash($root), "\n";
    exit(0);
}

/** Leer oder eine https-Adresse; sonst Abbruch (ein Tippfehler soll nicht still nichts bewirken). */
function kompanie_https(string $url, string $name, callable $fail): string
{
    if ($url !== '' && (!preg_match('~^https://[^\s/?#]+(/\S*)?$~', $url) || filter_var($url, FILTER_VALIDATE_URL) === false)) {
        $fail("$name muss mit https:// beginnen.");
    }
    return $url;
}

// Signaturschlüssel und Update-Konfiguration
$key = getenv('KOMPANIE_SIGNING_KEY');
$signing = null;
if (is_string($key) && trim($key) !== '') {
    $signing = SignedEnvelope::keyPairFromSecret($key);
} elseif (isset($opt['require-signature'])) {
    $fail('KOMPANIE_SIGNING_KEY fehlt; ohne Signatur wird nichts veröffentlicht.');
}
$trusted = TrustedKeys::all();
if ($signing !== null && isset($opt['trust-signing-key'])) {
    $trusted[SignedEnvelope::keyId($signing[1])] = base64_encode($signing[1]);
}
if ($signing !== null && isset($opt['require-signature']) && !isset($trusted[SignedEnvelope::keyId($signing[1])])) {
    $fail('Der Signaturschlüssel steht nicht in packages/core/src/Update/TrustedKeys.php. Ohne diesen Eintrag könnten die Installationen keine Updates prüfen (siehe docs/RELEASE.md).');
}
$repo = (string) ($opt['kompanie'] ?? '');
if (isset($opt['require-signature']) && !preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) {
    $fail('--kompanie=Organisation/Repo fehlt; ohne sie würden die Installationen jede Liste annehmen.');
}
$updatesUrl = (string) ($opt['updates-url'] ?? '');
if ($updatesUrl === '' && preg_match('#^([^/]+)/([^/]+)$#', $repo, $m)) {
    $updatesUrl = 'https://' . strtolower($m[1]) . '.github.io/' . $m[2] . '/updates.json';
}
$downloadPage = $updatesUrl !== '' ? substr($updatesUrl, 0, (int) strrpos($updatesUrl, '/') + 1) : '';
$updateJson = [
    'kompanie' => $repo,
    'updatesUrl' => $updatesUrl,
    'downloadPage' => $downloadPage,
    'statusUrl' => kompanie_https((string) ($opt['status-url'] ?? ''), '--status-url', $fail),
    'support' => (string) ($opt['support'] ?? ''),
    'trustedKeys' => $trusted,
];
// Empfänger der Fehlermeldungen (Issue #64) nur, wenn gesetzt: So bleibt die Prüfsumme der Paket-Konfiguration ohne ihn
// unverändert, und keine Kompanie bekommt allein durch dieses Feld eine neue Version.
$reportUrl = kompanie_https((string) ($opt['report-url'] ?? ''), '--report-url', $fail);
if ($reportUrl !== '') {
    $updateJson['reportUrl'] = $reportUrl;
}
$updateEntry = ['content' => json_encode($updateJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"];
// Prüfsumme der Paket-Konfiguration (Update-Adresse, Schlüssel, Status- und Fehlermeldungen, Hilfe): Ändert sich etwas
// davon, braucht jede Kompanie eine neue Version (scripts/updates.php --find, scripts/find-pending.sh).
$configHash = hash('sha256', (string) json_encode($updateJson, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
if (isset($opt['config-hash'])) {
    echo $configHash, "\n";
    exit(0);
}

$core = kompanie_files($root . '/packages/core/src');
$app = kompanie_files($dist, [], false);
// Zwischenspeicher und Komprimierung der App-Dateien (Apache/LiteSpeed), in beide Pakete. Aus dem Repo,
// nicht aus dem Build: Dateien wie .htaccess nimmt scripts/import-build.php aus dem Build nie an.
$appHtaccess = [
    'app/.htaccess' => $root . '/adapters/shared/app.htaccess',
    'app/assets/.htaccess' => $root . '/adapters/shared/app-assets.htaccess',
];
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
foreach ($appHtaccess as $rel => $src) {
    $wp["kompanie-app/$rel"] = $src;
}
$wp['kompanie-app/kompanie.json'] = $kompanieJson;
$wp['kompanie-app/update.json'] = $updateEntry;

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
foreach ($appHtaccess as $rel => $src) {
    $sa["kompanie-app/$rel"] = $src;
}
$sa['kompanie-app/core/.htaccess'] = ['content' => (string) file_get_contents($root . '/adapters/standalone/src/.htaccess')];
$sa['kompanie-app/sw.js'] = $dist . '/sw.js';
$sa['kompanie-app/kompanie.json'] = $kompanieJson;
$sa['kompanie-app/VERSION'] = ['content' => $version . "\n"];
$sa['kompanie-app/update.json'] = $updateEntry;

$packages = ['wordpress' => $wp, 'standalone' => $sa];
$files = [];
foreach ($packages as $variant => $entries) {
    $name = "kompanie-app-$variant.zip";
    kompanie_zip("$out/$name", $entries);
    $files[$variant] = ['name' => $name, 'sha256' => hash_file('sha256', "$out/$name"), 'size' => filesize("$out/$name")];
}

exec('git -C ' . escapeshellarg($root) . ' rev-parse HEAD 2>/dev/null', $sha);
$release = [
    'type' => 'release',
    'kompanie' => (string) ($opt['kompanie'] ?? ''),
    'coreCommit' => (string) (getenv('KOMPANIE_CORE_COMMIT') ?: ($sha[0] ?? '')),
    'coreCode' => kompanie_code_hash($root),
    'kompanieCommit' => (string) ($opt['kompanie-commit'] ?? ''),
    'configHash' => $configHash,
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

// Installer für diese Kompanie: Update-Adresse und Schlüssel einsetzen.
$installer = (string) file_get_contents($root . '/installer/install.php');
$installerConfig = var_export([
    'name' => trim($kompanie['name']),
    'updatesUrl' => $updatesUrl,
    'kompanie' => $repo,
    'trustedKeys' => $trusted,
    'primary' => (string) ($kompanie['colors']['primary'] ?? '#2e6b3f'),
    'downloadPage' => $downloadPage,
    'support' => (string) ($opt['support'] ?? ''),
], true);
$installer = (string) preg_replace('#/\*KOMPANIE_INSTALLER_CONFIG\*/.*/\*KOMPANIE_INSTALLER_CONFIG_ENDE\*/#s', addcslashes($installerConfig, '\\$'), $installer, 1, $n);
if ($n !== 1) {
    $fail('Platzhalter im Installer nicht gefunden.');
}
file_put_contents("$out/install.php", $installer);
$release['installer'] = ['name' => 'install.php', 'sha256' => hash('sha256', $installer), 'size' => strlen($installer)];

if ($signing !== null) {
    [$secret, $public] = $signing;
    file_put_contents("$out/release.json", SignedEnvelope::sign($release, $secret, SignedEnvelope::keyId($public)));
    echo "Signiert mit Schlüssel " . SignedEnvelope::keyId($public) . ".\n";
} else {
    file_put_contents("$out/release.json", json_encode($release, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    fwrite(STDERR, "Hinweis: ohne KOMPANIE_SIGNING_KEY – release.json ist nicht signiert (nur für Tests).\n");
}
foreach ($files as $variant => $f) {
    printf("%-11s %s  %6.1f KB  %s\n", $variant, $f['name'], $f['size'] / 1024, $f['sha256']);
}
