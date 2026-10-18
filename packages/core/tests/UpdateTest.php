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

    private function release(string $v, string $core = '1.0.0', string $kompanie = 'org/kompanie-test'): array
    {
        $f = static fn (string $n) => ['name' => $n, 'sha256' => hash('sha256', $v . $n), 'size' => 100];
        return ['version' => $v, 'kompanie' => $kompanie, 'coreVersion' => $core, 'minPhp' => '8.1',
            'files' => ['wordpress' => $f('kompanie-app-wordpress.zip'), 'standalone' => $f('kompanie-app-standalone.zip')]];
    }

    public function testUpdateListChannelsPromoteWithdraw(): void
    {
        $list = UpdateList::create('org/kompanie-test', 'Testkompanie');
        $base = static fn (string $v) => "https://github.com/org/kompanie-test/releases/download/v$v/";
        $list->addRelease($this->release('1.0.0.1'), $base('1.0.0.1'));
        $this->assertSame('1.0.0.1', $list->channel('early'));
        $this->assertNull($list->channel('stable'), 'neue Versionen erst nach dem Test der Demo');
        $list->promote('1.0.0.1');
        $serial = $list->serial();
        $list->addRelease($this->release('1.0.0.2'), $base('1.0.0.2'));
        $this->assertGreaterThan($serial, $list->serial(), 'laufende Nummer steigt');
        $list->addRelease($this->release('1.1.0.3', '1.1.0'), $base('1.1.0.3'));
        $list->promote('1.1.0.3');
        $this->assertSame(['1.1.0.3', '1.0.0.2', '1.0.0.1'], array_column($list->available(), 'version'), 'neueste zuerst');
        $this->assertSame('https://github.com/org/kompanie-test/releases/download/v1.1.0.3/kompanie-app-standalone.zip', $list->entry('1.1.0.3')['files']['standalone']['url']);

        // Zurückziehen: stable nur auf eine schon einmal freigegebene Version (1.0.0.2 war nie stable)
        $list->withdraw('1.1.0.3');
        $this->assertSame('1.0.0.1', $list->channel('stable'));
        $this->assertSame('1.0.0.2', $list->channel('early'));
        $this->assertTrue($list->isWithdrawn('1.1.0.3'));
        $this->assertNotContains('1.1.0.3', array_column($list->available(), 'version'));
        $this->assertSame('early', $list->channelFor(['coreVersion' => '1.1.0', 'coreCode' => 'c11'], '1.1.0 c11'), 'Kern mit zurückgezogenem Release: nie mehr direkt stable');
        $this->assertThrows(fn () => $list->promote('1.1.0.3'), 'zurückgezogen');
        $this->assertThrows(fn () => $list->addRelease($this->release('1.1.0.3'), $base('x')), 'nie ersetzt');
        $this->assertThrows(fn () => $list->addRelease($this->release('1.0.0.9'), $base('x')), 'älter als');
        $this->assertThrows(fn () => $list->promote('3.0.0.0'), 'nicht in der Liste');
        $this->assertThrows(fn () => $list->addRelease($this->release('1.2.0.1', '1.2.0', 'org/andere'), $base('x')), 'anderen Kompanie');
        $list->addRelease($this->release('1.2.0.4', '1.2.0'), $base('1.2.0.4'));
        $list->promote('1.2.0.4');
        $this->assertThrows(fn () => $list->promote('1.0.0.2'), 'älter als die freigegebene');

        // Neue Hauptversion: Zurückziehen fällt nicht auf eine ältere Hauptversion zurück;
        // Installationen von 1.x bekommen weiter die neueste geprüfte 1.x.
        $list->addRelease($this->release('2.0.0.5', '2.0.0'), $base('2.0.0.5'), 'stable');
        $this->assertSame('1.2.0.4', $list->latestFor(1, 'stable'));
        $list->withdraw('2.0.0.5');
        $this->assertNull($list->channel('stable'));

        // Nur das Aussehen geändert (gleicher Kern und Programmcode wie stable): direkt stable.
        $list2 = UpdateList::create('org/k', 'K');
        $list2->addRelease(['kompanie' => 'org/k', 'coreCommit' => 'abc', 'coreCode' => 'c1'] + $this->release('1.0.0.1'), $base('1.0.0.1'), 'stable');
        $this->assertSame('stable', $list2->channelFor(['coreVersion' => '1.0.0', 'coreCommit' => 'xyz', 'coreCode' => 'c1']), 'anderer Commit, gleicher Code (z. B. nur Doku geändert)');
        $this->assertSame('early', $list2->channelFor(['coreVersion' => '1.0.0', 'coreCommit' => 'abc', 'coreCode' => 'c2']), 'gleiche Kern-Version, aber geänderter Code');
        $this->assertSame('early', $list2->channelFor(['coreVersion' => '1.1.0', 'coreCode' => 'c1']), 'neuer Kern erst auf der Demo');
        $this->assertSame('early', UpdateList::create('org/k', 'K')->channelFor(['coreVersion' => '1.0.0', 'coreCode' => 'c1']), 'erste Version');
        $this->assertSame('stable', UpdateList::create('org/k', 'K')->channelFor(['coreVersion' => '1.0.0', 'coreCode' => 'c1'], '1.0.0 c1'), 'erste Version mit geprüftem Kern');
        $this->assertSame('early', UpdateList::create('org/k', 'K')->channelFor(['coreVersion' => '1.0.0', 'coreCode' => 'c9'], '1.0.0 c1'), 'Kern-Version geprüft, aber Code seitdem geändert');
        $this->assertSame('early', UpdateList::create('org/k', 'K')->channelFor(['coreVersion' => '1.0.0', 'coreCode' => 'c1'], '1.0.0'), 'STABLE_CORE ohne Prüfsumme gilt nicht');
        $this->assertSame('early', $list2->channelFor(['coreVersion' => '1.0.0']), 'ohne Code und Commit nie direkt stable');

        // Versionsnummern: fortlaufend je Kern-Version; Serien-Nummer mindestens die aktuelle Zeit
        $this->assertSame('1.0.0.2', $list2->nextVersion('1.0.0'));
        $this->assertSame('1.1.0.1', $list2->nextVersion('1.1.0'));
        $this->assertThrows(fn () => $list2->nextVersion('0.9.0'), 'älter als');
        $this->assertGreaterThanOrEqual(time() - 5, $list2->serial());

        // Kürzung: stable bleibt in der Liste, auch nach vielen early-Versionen
        $list3 = UpdateList::create('org/kompanie-test', 'K');
        $list3->addRelease($this->release('1.0.0.1'), $base('1'), 'stable');
        for ($i = 2; $i <= 70; $i++) {
            $list3->addRelease($this->release("1.0.0.$i"), $base("$i"));
        }
        $this->assertNotNull($list3->entry('1.0.0.1'));

        // Rundreise über das Format
        $again = UpdateList::fromPayload(json_decode((string) json_encode($list->toArray()), true));
        $this->assertSame($list->toArray(), $again->toArray());
        $this->assertThrows(fn () => UpdateList::fromPayload(['format' => 99]), 'unbekanntes Format');
        $this->assertThrows(fn () => UpdateList::fromPayload(['format' => 1, 'type' => 'release', 'versions' => [], 'channels' => []]), 'unbekanntes Format');
        $bad = $this->release('3.0.0.1', '3.0.0');
        unset($bad['files']['wordpress']);
        $this->assertThrows(fn () => $list->addRelease($bad, $base('3.0.0.1')), 'beide Pakete');
        $this->assertFalse(\KompanieApp\Core\Update\Version::valid("1.2.3.4\n"));
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
