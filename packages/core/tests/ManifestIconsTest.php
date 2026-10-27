<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\Http\FileCache;
use KompanieApp\Core\Http\ManifestIcons;
use PHPUnit\Framework\TestCase;

final class ManifestIconsTest extends TestCase
{
    public function testAdresseWechseltNurMitDemInhalt(): void
    {
        $dir = sys_get_temp_dir() . '/kompanie-icons-' . bin2hex(random_bytes(4));
        mkdir($dir);
        foreach (['icon-192.png', 'icon-512.png', 'icon-maskable-512.png'] as $f) {
            file_put_contents($dir . '/' . $f, 'alt ' . $f);
        }
        $before = ManifestIcons::entries('https://example.org/app/icons/', $dir);
        $this->assertCount(3, $before);
        $this->assertMatchesRegularExpression('#^https://example\.org/app/icons/icon-192\.png\?v=[0-9a-f]{12}$#', $before[0]['src']);
        $this->assertSame('maskable', $before[2]['purpose']);
        $this->assertArrayNotHasKey('purpose', $before[0]);

        // Gleicher Inhalt, neue Änderungszeit (z. B. nach einem Update): Adresse bleibt.
        touch($dir . '/icon-192.png', time() + 60);
        $this->assertSame($before, ManifestIcons::entries('https://example.org/app/icons/', $dir));

        // Neues Bild: nur dessen Adresse ändert sich.
        file_put_contents($dir . '/icon-maskable-512.png', 'neu');
        $after = ManifestIcons::entries('https://example.org/app/icons/', $dir);
        $this->assertSame($before[0], $after[0]);
        $this->assertNotSame($before[2]['src'], $after[2]['src']);

        array_map('unlink', glob($dir . '/*'));
        rmdir($dir);
    }

    public function testEtagDesManifestsFolgtDemInhalt(): void
    {
        $etag = FileCache::contentEtag('{"orientation":"portrait"}');
        $this->assertMatchesRegularExpression('/^"[a-f0-9]{32}"$/', $etag);
        $this->assertSame($etag, FileCache::contentEtag('{"orientation":"portrait"}'));
        $this->assertNotSame($etag, FileCache::contentEtag('{"orientation":"any"}'));
        $this->assertTrue(FileCache::matches($etag, $etag));
    }

    public function testOhneDateiOhneVersion(): void
    {
        $entries = ManifestIcons::entries('/app/icons/', '/gibt/es/nicht');
        $this->assertSame('/app/icons/icon-512.png', $entries[1]['src']);
    }
}
