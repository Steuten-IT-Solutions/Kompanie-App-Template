<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\Domain\Time;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Infra\LocalFileStorage;
use KompanieApp\Core\Port\BackupProvider;
use KompanieApp\Core\Port\UpdateTarget;
use KompanieApp\Core\Tests\Support\FakeHttpClient;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\MemorySettings;
use KompanieApp\Core\Update\SignedEnvelope;
use KompanieApp\Core\Update\UpdateList;
use KompanieApp\Core\Update\UpdateService;
use PHPUnit\Framework\TestCase;

final class UpdateServiceTest extends TestCase
{
    private string $secret;
    private string $keyId;
    /** @var array<string, string> */
    private array $trusted;
    /** @var array<string, string> Paketinhalt je Version */
    private array $zips = [];
    private string $listJson = '';
    private int $serial = 100;
    private FakeHttpClient $http;
    private MemorySettings $settings;
    /** @var array<int, array{0: string, 1: string}> */
    public array $installed = [];
    public bool $failInstall = false;
    public array $backups = [];

    protected function setUp(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $public = sodium_crypto_sign_publickey($pair);
        $this->secret = sodium_crypto_sign_secretkey($pair);
        $this->keyId = SignedEnvelope::keyId($public);
        $this->trusted = [$this->keyId => base64_encode($public)];
        $this->settings = new MemorySettings(['schema_version' => 2]);
        $this->http = new FakeHttpClient(function (string $m, string $url): array {
            if (str_ends_with($url, 'updates.json')) {
                return ['status' => 200, 'body' => $this->listJson];
            }
            $v = ltrim(basename(dirname($url)), 'v');
            return isset($this->zips[$v]) ? ['status' => 200, 'body' => $this->zips[$v]] : ['status' => 404, 'body' => ''];
        });
    }

    /**
     * @param string[] $stable nacheinander freigegebene Versionen
     */
    private function publish(array $versions, array $stable, array $withdrawn = [], array $extra = []): void
    {
        $list = UpdateList::create('org/k', 'K');
        foreach ($versions as $v) {
            $this->zips[$v] ??= 'ZIP-' . $v;
            $file = fn (string $n) => ['name' => $n, 'sha256' => hash('sha256', $this->zips[$v]), 'size' => strlen($this->zips[$v])];
            $list->addRelease(['version' => $v, 'kompanie' => 'org/k', 'coreVersion' => '1.0.0', 'schema' => ['core' => $extra[$v]['schema'] ?? 2], 'minPhp' => $extra[$v]['minPhp'] ?? '8.1',
                'files' => ['wordpress' => $file('w.zip'), 'standalone' => $file('kompanie-app-standalone.zip')]], "https://example.org/dl/v$v/");
        }
        foreach ($stable as $v) {
            $list->promote($v);
        }
        foreach ($withdrawn as $v) {
            $list->withdraw($v);
        }
        $payload = $list->toArray();
        $payload['serial'] = ++$this->serial; // jede veröffentlichte Liste ist neuer
        $this->listJson = SignedEnvelope::sign($payload, $this->secret, $this->keyId);
    }

    private function service(string $current): UpdateService
    {
        $test = $this;
        $target = new class ($test) implements UpdateTarget {
            public function __construct(private readonly UpdateServiceTest $t)
            {
            }

            public function variant(): string
            {
                return 'standalone';
            }

            public function problems(array $entry): array
            {
                return [];
            }

            public function install(string $zipFile, string $version): void
            {
                if ($this->t->failInstall) {
                    throw new \RuntimeException('Schreibrechte fehlen.');
                }
                $this->t->installed[] = [$version, (string) file_get_contents($zipFile)];
            }
        };
        $backup = new class ($test) implements BackupProvider {
            public function __construct(private readonly UpdateServiceTest $t)
            {
            }

            public function export(): array
            {
                $this->t->backups[] = 1;
                return ['format' => 'kompanie-app-backup'];
            }

            public function import(string $json): void
            {
            }

            public function canImport(): bool
            {
                return true;
            }
        };
        $dir = sys_get_temp_dir() . '/kompanie-upd-' . bin2hex(random_bytes(4));
        return new UpdateService($this->settings, $this->http, new LocalFileStorage("$dir/p", 'https://x/', "$dir/i", ''), new Time(new FixedClock()), $current, $target, $backup,
            ['updatesUrl' => 'https://org.github.io/k/updates.json', 'trustedKeys' => $this->trusted, 'kompanie' => 'org/k']);
    }

    public function testAutomaticUpdateWithinMajorOnly(): void
    {
        $this->publish(['1.0.0.1', '1.0.0.2'], ['1.0.0.2']);
        $s = $this->service('1.0.0.1');
        $s->runTask();
        $this->assertSame([['1.0.0.2', 'ZIP-1.0.0.2']], $this->installed, 'Paket aus der signierten Liste, SHA-256 geprüft');
        $this->assertTrue($this->settings->get('update_last')['ok']);

        // Neue Hauptversion: nie automatisch
        $this->installed = [];
        $this->publish(['1.0.0.2', '2.0.0.1'], ['2.0.0.1']);
        $s = $this->service('1.0.0.2');
        $s->runTask();
        $this->assertSame([], $this->installed);
        $this->assertTrue($s->status()['major']);
        $this->assertThrows(fn () => $s->install('2.0.0.1'), 'ausdrückliche Bestätigung');
        $s->install('2.0.0.1', 'manual', true);
        $this->assertSame('2.0.0.1', $this->installed[0][0]);
    }

    public function testEarlyChannelAutoOffAndSkip(): void
    {
        $this->publish(['1.0.0.1', '1.0.0.2'], ['1.0.0.1']);
        $s = $this->service('1.0.0.1');
        $s->runTask();
        $this->assertSame([], $this->installed, 'early ist nur für die Demo');
        $this->settings->set('update_channel', 'early');
        $s->setAuto(false);
        $s->runTask();
        $this->assertSame([], $this->installed, 'automatische Updates aus');
        $this->assertTrue($s->status()['available']);

        // Zurückrollen: die verlassene Version wird übersprungen, bis eine neuere kommt.
        $s->setAuto(true);
        $this->settings->set('update_channel', 'stable');
        $this->publish(['1.0.0.1', '1.0.0.2'], ['1.0.0.2']);
        $s2 = $this->service('1.0.0.2');
        $s2->check();
        $s2->rollback('1.0.0.1');
        $this->assertSame('1.0.0.1', end($this->installed)[0]);
        $s3 = $this->service('1.0.0.1');
        $n = count($this->installed);
        $s3->runTask();
        $this->assertCount($n, $this->installed, 'übersprungen');
        $this->publish(['1.0.0.1', '1.0.0.2', '1.0.0.3'], ['1.0.0.3']);
        $s3->runTask();
        $this->assertSame('1.0.0.3', end($this->installed)[0], 'neuere Version kommt wieder automatisch');
    }

    public function testWithdrawnVersionRollsBackAutomatically(): void
    {
        $this->publish(['1.0.0.1', '1.0.0.2'], ['1.0.0.1', '1.0.0.2'], ['1.0.0.2']);
        $s = $this->service('1.0.0.2');
        $s->setAuto(false); // auch ohne automatische Updates
        $s->runTask();
        $this->assertSame('1.0.0.1', $this->installed[0][0]);
        $this->assertSame('withdrawn', $this->settings->get('update_last')['kind']);
    }

    public function testTamperingAndFailures(): void
    {
        $this->publish(['1.0.0.1', '1.0.0.2'], ['1.0.0.2']);
        $s = $this->service('1.0.0.1');

        // Paket verändert (z. B. auf dem Weg): SHA-256 passt nicht
        $this->zips['1.0.0.2'] = 'BÖSE';
        $this->assertThrows(fn () => $s->install('1.0.0.2'), 'stimmt nicht');
        $this->assertSame([], $this->installed);
        $this->assertFalse($this->settings->get('update_last')['ok']);

        // Liste mit fremdem Schlüssel
        $other = sodium_crypto_sign_keypair();
        $this->listJson = SignedEnvelope::sign(['format' => 1], sodium_crypto_sign_secretkey($other), 'fremd');
        $this->assertThrows(fn () => $s->check(), 'unbekannten Schlüssel');
        $this->assertStringContainsString('unbekannten Schlüssel', $s->status()['error']);

        // Ältere (wiederverwendete) Liste wird nicht angenommen
        $this->zips['1.0.0.2'] = 'ZIP-1.0.0.2';
        $this->publish(['1.0.0.1', '1.0.0.2'], ['1.0.0.2']);
        $old = $this->listJson;
        $this->publish(['1.0.0.1', '1.0.0.2'], ['1.0.0.2']);
        $s->check();
        $this->listJson = $old;
        $this->assertThrows(fn () => $s->check(), 'älter als die zuletzt geladene');

        // Liste einer anderen Kompanie (gleicher Schlüssel der Organisation)
        $other = UpdateList::create('org/andere', 'A')->toArray();
        $other['serial'] = 999;
        $this->listJson = SignedEnvelope::sign($other, $this->secret, $this->keyId);
        $this->assertThrows(fn () => $s->check(), 'anderen Kompanie');

        // Einspielen scheitert: Meldung, alter Stand bleibt
        $this->publish(['1.0.0.1', '1.0.0.2'], ['1.0.0.2']);
        $this->failInstall = true;
        $this->assertThrows(fn () => $s->install('1.0.0.2'), 'bisherige Version läuft weiter');

        // Zu alte PHP-Version für die neue Version
        $this->failInstall = false;
        $this->publish(['1.0.0.1', '1.0.0.2'], ['1.0.0.2'], [], ['1.0.0.2' => ['minPhp' => '99.0']]);
        $s->runTask();
        $this->assertSame([], $this->installed);
        $this->assertStringContainsString('PHP 99.0', implode(' ', $s->status()['problems']));
    }

    public function testBackupOnlyBeforeSchemaChanges(): void
    {
        $this->publish(['1.0.0.1', '1.0.0.2', '1.0.0.3'], ['1.0.0.2'], [], ['1.0.0.3' => ['schema' => 3]]);
        $s = $this->service('1.0.0.1');
        $s->install('1.0.0.2');
        $this->assertSame([], $this->backups, 'gleiche Datenbank: keine Sicherung nötig');
        $s->install('1.0.0.3');
        $this->assertCount(1, $this->backups, 'neue Migration: vorher sichern');
    }

    private function assertThrows(callable $fn, string $message): void
    {
        try {
            $fn();
            $this->fail('Ausnahme erwartet: ' . $message);
        } catch (ApiError|\RuntimeException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }
}
