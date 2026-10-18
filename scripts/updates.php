<?php
/**
 * Pflegt die signierte Update-Liste einer Kompanie (updates.json auf GitHub Pages).
 *
 *   php scripts/updates.php --list=updates.json --add=release.json --base-url=https://…/download/v1.2.3.4/ [--channel=early|stable|auto]
 *   php scripts/updates.php --list=updates.json --promote=1.2.3.4
 *   php scripts/updates.php --list=updates.json --withdraw=1.2.3.4
 *   php scripts/updates.php --list=updates.json --show
 *
 * --channel=auto (Standard): stable, wenn der Kern schon auf der Demo geprüft ist (--stable-core=Inhalt
 * von STABLE_CORE) oder sich nur das Aussehen ändert; sonst early.
 * --list wird gelesen (fehlt die Datei, entsteht eine neue Liste) und überschrieben.
 * Signiert und geprüft wird mit KOMPANIE_SIGNING_KEY; vorhandene Listen und Releases müssen mit
 * diesem Schlüssel oder einem aus TrustedKeys signiert sein.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/adapters/standalone/autoload.php';

use KompanieApp\Core\Update\SignedEnvelope;
use KompanieApp\Core\Update\TrustedKeys;
use KompanieApp\Core\Update\UpdateList;

$opt = getopt('', ['list:', 'add:', 'base-url:', 'channel:', 'promote:', 'withdraw:', 'show', 'kompanie:', 'stable-core:', 'dry-run']);
$fail = static function (string $msg): never {
    fwrite(STDERR, "Fehler: $msg\n");
    exit(1);
};
$file = (string) ($opt['list'] ?? '');
if ($file === '') {
    $fail('--list fehlt.');
}
$key = (string) getenv('KOMPANIE_SIGNING_KEY');
if ($key === '') {
    $fail('KOMPANIE_SIGNING_KEY fehlt.');
}
try {
    [$secret, $public] = SignedEnvelope::keyPairFromSecret($key);
    $keyId = SignedEnvelope::keyId($public);
    $trusted = TrustedKeys::all() + [$keyId => base64_encode($public)];

    if (is_file($file)) {
        $list = UpdateList::fromPayload(SignedEnvelope::open((string) file_get_contents($file), $trusted));
    } elseif (isset($opt['add'])) {
        $release = SignedEnvelope::open((string) file_get_contents((string) $opt['add']), $trusted);
        $list = UpdateList::create((string) ($opt['kompanie'] ?? $release['kompanie'] ?? ''), (string) ($release['name'] ?? ''));
    } else {
        $fail("$file gibt es nicht.");
    }

    if (isset($opt['add'])) {
        $release = SignedEnvelope::open((string) file_get_contents((string) $opt['add']), $trusted);
        $base = (string) ($opt['base-url'] ?? '');
        if (!preg_match('#^https://#', $base)) {
            $fail('--base-url muss mit https:// beginnen.');
        }
        $channel = (string) ($opt['channel'] ?? 'auto');
        $channel = $channel === 'auto' ? $list->channelFor($release, trim((string) ($opt['stable-core'] ?? ''))) : $channel;
        if ($list->entry((string) $release['version']) !== null) {
            echo "Version {$release['version']} ist schon in der Liste.\n";
            if (getenv('GITHUB_OUTPUT')) {
                file_put_contents((string) getenv('GITHUB_OUTPUT'), "channel=$channel\nknown=1\n", FILE_APPEND);
            }
            exit(0);
        }
        if (isset($opt['dry-run'])) {
            echo "Kanal: $channel\n";
            if (getenv('GITHUB_OUTPUT')) {
                file_put_contents((string) getenv('GITHUB_OUTPUT'), "channel=$channel\nknown=0\n", FILE_APPEND);
            }
            exit(0);
        }
        $list->addRelease($release, rtrim($base, '/') . '/', $channel);
        echo "Version {$release['version']} aufgenommen (Kanal $channel).\n";
    }
    if (isset($opt['promote'])) {
        $list->promote((string) $opt['promote']);
        echo "Version {$opt['promote']} ist jetzt stable.\n";
    }
    if (isset($opt['withdraw'])) {
        $list->withdraw((string) $opt['withdraw']);
        echo "Version {$opt['withdraw']} zurückgezogen. stable: " . ($list->channel('stable') ?? '–') . ", early: " . ($list->channel('early') ?? '–') . "\n";
    }
    if (isset($opt['show'])) {
        echo json_encode($list->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
        exit(0);
    }
    file_put_contents($file, SignedEnvelope::sign($list->toArray(), $secret, $keyId));
} catch (RuntimeException $e) {
    $fail($e->getMessage());
}
