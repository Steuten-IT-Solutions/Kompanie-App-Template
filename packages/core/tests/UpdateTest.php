<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\Update\SignedEnvelope;
use KompanieApp\Core\Update\UpdateList;
use KompanieApp\Core\Update\Version;
use PHPUnit\Framework\TestCase;

final class UpdateTest extends TestCase
{
    /** @return array{0: string, 1: array<string, string>, 2: string} geheim, vertrauenswürdige Schlüssel, keyId */
    private function key(): array
    {
        $pair = sodium_crypto_sign_keypair();
        $public = sodium_crypto_sign_publickey($pair);
        $id = SignedEnvelope::keyId($public);
        return [sodium_crypto_sign_secretkey($pair), [$id => base64_encode($public)], $id];
    }

    public function testSignedEnvelope(): void
    {
        [$secret, $trusted, $id] = $this->key();
        $json = SignedEnvelope::sign(['version' => '1.2.3.4', 'name' => 'Ä'], $secret, $id);
        $this->assertSame(['version' => '1.2.3.4', 'name' => 'Ä'], SignedEnvelope::open($json, $trusted));

        // Veränderter Inhalt, fremder Schlüssel, unsignierte Datei
        $env = json_decode($json, true);
        $env['payload'] = base64_encode((string) json_encode(['version' => '9.9.9.9']));
        $this->assertThrows(fn () => SignedEnvelope::open((string) json_encode($env), $trusted), 'Signatur ist ungültig');
        [, $other] = $this->key();
        $this->assertThrows(fn () => SignedEnvelope::open($json, $other), 'unbekannten Schlüssel');
        $this->assertThrows(fn () => SignedEnvelope::open('{"version":"1.2.3.4"}', $trusted), 'nicht signiert');
        $this->assertThrows(fn () => SignedEnvelope::open($json, []), 'unbekannten Schlüssel');

        [$s2, $p2] = SignedEnvelope::keyPairFromSecret(base64_encode($secret));
        $this->assertSame($secret, $s2);
        $this->assertSame($id, SignedEnvelope::keyId($p2));
        $this->assertThrows(fn () => SignedEnvelope::keyPairFromSecret('kurz'), 'Format');
    }

    public function testVersion(): void
    {
        $this->assertTrue(Version::valid('1.4.0.3'));
        $this->assertTrue(Version::valid('1.4.0'));
        $this->assertFalse(Version::valid('1.4'));
        $this->assertFalse(Version::valid('1.4.0.3-beta'));
        $this->assertSame(1, Version::compare('1.10.0.1', '1.9.9.9'));
        $this->assertSame(-1, Version::compare('1.4.0', '1.4.0.1'));
        $this->assertSame(0, Version::compare('1.4.0.0', '1.4.0'));
        $this->assertSame(2, Version::major('2.0.0.1'));
    }

    private function release(string $v): array
    {
        $f = static fn (string $n) => ['name' => $n, 'sha256' => hash('sha256', $v . $n), 'size' => 100];
        return ['version' => $v, 'minPhp' => '8.1', 'files' => ['wordpress' => $f('kompanie-app-wordpress.zip'), 'standalone' => $f('kompanie-app-standalone.zip')]];
    }

    public function testUpdateListChannelsPromoteWithdraw(): void
    {
        $list = UpdateList::create('org/kompanie-test', 'Testkompanie');
        $base = static fn (string $v) => "https://github.com/org/kompanie-test/releases/download/v$v/";
        $list->addRelease($this->release('1.0.0.1'), $base('1.0.0.1'));
        $this->assertSame('1.0.0.1', $list->channel('early'));
        $this->assertNull($list->channel('stable'), 'neue Versionen erst nach dem Test der Demo');
        $list->promote('1.0.0.1');
        $list->addRelease($this->release('1.0.0.2'), $base('1.0.0.2'));
        $list->addRelease($this->release('1.1.0.3'), $base('1.1.0.3'));
        $list->promote('1.1.0.3');
        $this->assertSame(['1.1.0.3', '1.0.0.2', '1.0.0.1'], array_column($list->available(), 'version'), 'neueste zuerst');
        $this->assertSame('https://github.com/org/kompanie-test/releases/download/v1.1.0.3/kompanie-app-standalone.zip', $list->entry('1.1.0.3')['files']['standalone']['url']);

        $list->withdraw('1.1.0.3');
        $this->assertSame('1.0.0.2', $list->channel('stable'), 'zurückgezogen: stable zeigt auf die vorige Version');
        $this->assertSame('1.0.0.2', $list->channel('early'));
        $this->assertTrue($list->isWithdrawn('1.1.0.3'));
        $this->assertNotContains('1.1.0.3', array_column($list->available(), 'version'));
        $this->assertThrows(fn () => $list->promote('1.1.0.3'), 'zurückgezogen');
        $this->assertThrows(fn () => $list->addRelease($this->release('1.1.0.3'), $base('x')), 'zurückgezogen');
        $this->assertThrows(fn () => $list->promote('3.0.0.0'), 'nicht in der Liste');

        // Neue Hauptversion: Zurückziehen fällt nicht auf eine ältere Hauptversion zurück.
        $list->addRelease($this->release('2.0.0.4'), $base('2.0.0.4'), 'stable');
        $list->withdraw('2.0.0.4');
        $this->assertNull($list->channel('stable'));

        // Nur das Aussehen geändert (gleiche Kern-Version wie stable): direkt stable.
        $list2 = UpdateList::create('org/k', 'K');
        $list2->addRelease($this->release('1.0.0.1') + ['coreVersion' => '1.0.0'], $base('1.0.0.1'), 'stable');
        $this->assertSame('stable', $list2->channelFor(['coreVersion' => '1.0.0']));
        $this->assertSame('early', $list2->channelFor(['coreVersion' => '1.1.0']), 'neuer Kern erst auf der Demo');
        $this->assertSame('early', UpdateList::create('org/k', 'K')->channelFor(['coreVersion' => '1.0.0']), 'erste Version');
        $this->assertSame('stable', UpdateList::create('org/k', 'K')->channelFor(['coreVersion' => '1.0.0'], '1.0.0'), 'erste Version mit geprüftem Kern');
        $this->assertSame('early', $list2->channelFor(['coreVersion' => '1.1.0'], '1.0.0'));

        // Rundreise über das Format
        $again = UpdateList::fromPayload(json_decode((string) json_encode($list->toArray()), true));
        $this->assertSame($list->toArray(), $again->toArray());
        $this->assertThrows(fn () => UpdateList::fromPayload(['format' => 99]), 'unbekanntes Format');
        $bad = $this->release('1.2.0.1');
        unset($bad['files']['wordpress']);
        $this->assertThrows(fn () => $list->addRelease($bad, $base('1.2.0.1')), 'beide Pakete');
    }

    private function assertThrows(callable $fn, string $message): void
    {
        try {
            $fn();
            $this->fail('Ausnahme erwartet: ' . $message);
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }
}
