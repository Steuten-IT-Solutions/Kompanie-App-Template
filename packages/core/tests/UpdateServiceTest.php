<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\Domain\Time;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Infra\LocalFileStorage;
use KompanieApp\Core\Port\BackupProvider;
use KompanieApp\Core\Port\UpdateTarget;
use KompanieApp\Core\Telemetry\Code;
use KompanieApp\Core\Telemetry\Telemetry;
use KompanieApp\Core\Tests\Support\FakeHttpClient;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\MemorySettings;
use KompanieApp\Core\Tests\Support\NullScheduler;
use KompanieApp\Core\Update\OffsiteBackup;
use KompanieApp\Core\Update\SignedEnvelope;
use KompanieApp\Core\Update\StatusReporter;
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
    public bool $autoAllowed = true;
    public array $backups = [];
    /** Meldung, mit der die Sicherung vor dem Update abbricht (null: klappt) */
    public ?string $backupFails = null;
    /** Sicherungen in Google Drive eingeschaltet? */
    public bool $offsiteActive = false;
    /** Meldung, mit der das Hochladen nach Google Drive scheitert (null: klappt) */
    public ?string $offsiteFails = null;
    /** @var array<int, array{0: string, 1: string}> nach Google Drive: Inhalt der Sicherung, Version davor */
    public array $offsiteStored = [];
    /** @var string[] Fortschritt, wie ihn status() von $watch während des Updates meldet */
    public array $progressSeen = [];
    public ?UpdateService $watch = null;
    private string $dir = '';

    protected function setUp(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $public = sodium_crypto_sign_publickey($pair);
        $this->secret = sodium_crypto_sign_secretkey($pair);
        $this->keyId = SignedEnvelope::keyId($public);
        $this->trusted = [$this->keyId => base64_encode($public)];
        $this->settings = new MemorySettings(['schema_version' => 2]);
        $this->dir = sys_get_temp_dir() . '/kompanie-upd-' . bin2hex(random_bytes(4));
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

    public function noteProgress(): void
    {
        if ($this->watch === null) {
            return;
        }
        $st = $this->watch->status();
        $p = $st['progress'];
        $this->progressSeen[] = $st['running'] ? sprintf('%s %s/%s%s von %s', $p['step'], $p['done'] ?? '-', $p['total'] ?? '-', $p['estimate'] ? ' ca.' : '', implode(',', $p['steps'])) : 'läuft nicht';
    }

    private function service(string $current, ?Telemetry $telemetry = null): UpdateService
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

            public function autoUpdateAllowed(): bool
            {
                return $this->t->autoAllowed;
            }

            public function install(string $zipFile, string $version): void
            {
                if ($this->t->failInstall) {
                    throw new \RuntimeException('Schreibrechte fehlen.');
                }
                $this->t->noteProgress();
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

            public function exportTo($out): void
            {
                $this->t->backups[] = 1;
                if ($this->t->backupFails !== null) {
                    fwrite($out, '{"format":"kompanie-app-backup","tables":{');
                    throw new \RuntimeException($this->t->backupFails);
                }
                fwrite($out, (string) json_encode(['format' => 'kompanie-app-backup']));
                fflush($out);
                $this->t->noteProgress();
            }

            public function import(string $json): void
            {
            }

            public function canImport(): bool
            {
                return true;
            }
        };
        $offsite = new class ($test) implements OffsiteBackup {
            public function __construct(private readonly UpdateServiceTest $t)
            {
            }

            public function active(): bool
            {
                return $this->t->offsiteActive;
            }

            public function storeBeforeUpdate(string $path, string $version, ?callable $onProgress = null): void
            {
                if ($this->t->offsiteFails !== null) {
                    throw new \RuntimeException($this->t->offsiteFails);
                }
                $onProgress && $onProgress(4, 10);
                $this->t->noteProgress();
                $this->t->offsiteStored[] = [(string) file_get_contents($path), $version];
            }
        };
        $dir = $this->dir;
        return new UpdateService($this->settings, $this->http, new LocalFileStorage("$dir/p", 'https://x/', "$dir/i", ''), new Time(new FixedClock()), $current, $target, $backup,
            ['updatesUrl' => 'https://org.github.io/k/updates.json', 'trustedKeys' => $this->trusted, 'kompanie' => 'org/k'], $telemetry, $offsite);
    }

    /** Statusmeldungen (Issue #20): gescheitertes Update, Zurückrollen und Fehler der Liste als Codes. */
    public function testFailuresAreCountedForStatusReports(): void
    {
        $scheduler = new NullScheduler();
        $scheduler->schedule(StatusReporter::TASK, 3600);
        $t = new Telemetry($this->settings, $scheduler, new Time(new FixedClock()), static fn (): bool => true, StatusReporter::TASK);
        $this->publish(['1.0.0.1', '1.0.0.2'], ['1.0.0.2']);
        $s = $this->service('1.0.0.1', $t);
        $this->failInstall = true;
        $this->assertThrows(fn () => $s->install('1.0.0.2'), 'bisherige Version läuft weiter');
        $this->assertSame(['UPDATE_FAILED' => 1], $t->errors());
        $this->assertSame([Code::UpdateFailed], $t->pending());
        $this->assertSame(['UPDATE_FAILED' => 'install'], $t->pendingDetails(), 'Ursache: der Schritt');
        $this->assertSame([StatusReporter::TASK], $scheduler->soon, 'Versand vorgezogen');
        $this->assertStringContainsString('1.0.0.1 → 1.0.0.2: Schreibrechte fehlen.', $t->log()[0]['message']);

        $this->listJson = 'kaputt';
        $this->assertThrows(fn () => $s->check(), '');
        $this->assertSame(1, $t->errors()['UPDATE_CHECK'] ?? 0);
        $this->assertSame(['signature' => 1], $t->details()['UPDATE_CHECK'] ?? []);

        $this->failInstall = false;
        $this->publish(['1.0.0.1', '1.0.0.2'], ['1.0.0.1', '1.0.0.2'], ['1.0.0.2']);
        $this->service('1.0.0.2', $t)->runTask();
        $this->assertSame(1, $t->errors()['ROLLBACK'] ?? 0);
        $this->assertSame([Code::UpdateFailed, Code::Rollback], $t->pending());
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
        $this->assertSame([], $this->installed, 'early nur mit Vorabversionen');
        $this->assertThrows(fn () => $s->setChannel('beta'), 'Unbekannter Kanal');
        $s->setChannel('early');
        $this->assertSame('early', $s->status()['channel']);
        $s->setAuto(false);
        $s->runTask();
        $this->assertSame([], $this->installed, 'automatische Updates aus');
        $this->assertTrue($s->status()['available']);

        // Zurückrollen: die verlassene Version wird übersprungen, bis eine neuere kommt.
        $s->setAuto(true);
        $this->settings->set('update_channel', 'stable');
        $this->publish(['1.0.0.1', '1.0.0.2'], ['1.0.0.1', '1.0.0.2']);
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

    public function testBackupBeforeEveryUpdate(): void
    {
        $this->publish(['1.0.0.1', '1.0.0.2', '1.0.0.3'], ['1.0.0.1', '1.0.0.2', '1.0.0.3'], [], ['1.0.0.3' => ['schema' => 3]]);
        $s = $this->service('1.0.0.1');
        $s->install('1.0.0.2');
        $this->assertCount(1, $this->backups, 'auch ohne Änderung an der Datenbank');
        $s->install('1.0.0.3');
        $this->assertCount(2, $this->backups, 'neue Migration');
        $this->service('1.0.0.3')->rollback('1.0.0.2');
        $this->assertCount(3, $this->backups, 'auch vor dem Zurückrollen');
        $this->assertSame([], $this->offsiteStored, 'Google Drive aus: nur auf dem Server');
    }

    public function testBackupAlsoGoesToGoogleDrive(): void
    {
        $this->publish(['1.0.0.1', '1.0.0.2'], ['1.0.0.2']);
        $this->offsiteActive = true;
        $s = $this->watch = $this->service('1.0.0.1');
        $s->install('1.0.0.2');
        $size = strlen((string) json_encode(['format' => 'kompanie-app-backup']));
        $steps = 'download,backup,drive,install';
        $this->assertSame(["backup $size/- ca. von $steps", "drive 4/10 von $steps", "install -/- von $steps"], $this->progressSeen,
            'Fortschritt für die App: Sicherung (geschrieben, Größe noch unbekannt), Hochladen, Einspielen');
        $this->assertFalse($s->status()['running']);
        $this->assertNull($s->status()['progress'], 'nach dem Update kein Fortschritt mehr');
        $this->assertNull($this->settings->get('update_progress'));
        $this->assertSame([[(string) json_encode(['format' => 'kompanie-app-backup']), '1.0.0.1']], $this->offsiteStored, 'dieselbe Sicherung wie auf dem Server');
        $this->assertSame('1.0.0.2', $this->installed[0][0]);
        $this->assertCount(1, glob($this->dir . '/i/sicherungen/vor-update-*.json') ?: []);
    }

    /** Die Größe der Sicherung steht erst am Ende fest: Schätzung aus der letzten. */
    public function testProgressEstimatesTheBackupFromTheLastOne(): void
    {
        $this->publish(['1.0.0.1', '1.0.0.2', '1.0.0.3'], ['1.0.0.3']);
        $this->service('1.0.0.1')->install('1.0.0.2');
        $last = (int) filesize((string) (glob($this->dir . '/i/sicherungen/vor-update-*.json') ?: [''])[0]);
        $this->watch = $this->service('1.0.0.2');
        $this->watch->install('1.0.0.3');
        $size = strlen((string) json_encode(['format' => 'kompanie-app-backup']));
        $this->assertSame(["backup $size/$last ca. von download,backup,install", 'install -/- von download,backup,install'], $this->progressSeen);
    }

    /** Von Hand: Scheitert nur Google Drive, wird nachgefragt (409), mit without_drive dann trotzdem eingespielt. */
    public function testFailedDriveBackupAsksBeforeManualUpdate(): void
    {
        $scheduler = new NullScheduler();
        $t = new Telemetry($this->settings, $scheduler, new Time(new FixedClock()), static fn (): bool => true, StatusReporter::TASK);
        $this->publish(['1.0.0.1', '1.0.0.2'], ['1.0.0.1', '1.0.0.2']);
        $this->offsiteActive = true;
        $this->offsiteFails = 'Google Drive: Das Hochladen ist fehlgeschlagen: Speicher voll.';
        $s = $this->service('1.0.0.1', $t);
        try {
            $s->install('1.0.0.2');
            $this->fail('Rückfrage erwartet');
        } catch (ApiError $e) {
            $this->assertSame(409, $e->status);
            $this->assertSame(UpdateService::DRIVE_FAILED, $e->errorCode);
            $this->assertStringContainsString('Speicher voll. Das Update wurde nicht eingespielt; die Sicherung auf dem Server ist da.', $e->getMessage());
        }
        $this->assertSame([], $this->installed, 'nicht eingespielt');
        $this->assertCount(1, glob($this->dir . '/i/sicherungen/vor-update-*.json') ?: [], 'Sicherung auf dem Server bleibt');
        $this->assertNull($s->status()['held'], 'von Hand: nichts zurückgestellt');
        $this->assertSame(['UPDATE_FAILED' => 'drive'], $t->pendingDetails());

        $s->install('1.0.0.2', 'manual', false, true);
        $this->assertSame('1.0.0.2', $this->installed[0][0]);
        $this->assertSame([], $this->offsiteStored);
        $this->assertCount(2, $this->backups, 'auf dem Server wieder gesichert');
        $this->assertTrue($this->settings->get('update_last')['ok']);

        // Zurückrollen ebenso
        $s2 = $this->service('1.0.0.2');
        $this->assertThrows(fn () => $s2->rollback('1.0.0.1'), 'Sicherung in Google Drive');
        $this->assertCount(1, $this->installed);
        $s2->rollback('1.0.0.1', true);
        $this->assertSame('1.0.0.1', end($this->installed)[0]);
    }

    /** Automatisch: niemand kann gefragt werden, das Update wartet auf einen Admin oder den nächsten Tag. */
    public function testFailedDriveBackupHoldsAutomaticUpdate(): void
    {
        $this->publish(['1.0.0.1', '1.0.0.2'], ['1.0.0.2']);
        $this->offsiteActive = true;
        $this->offsiteFails = 'Speicher voll.';
        $s = $this->service('1.0.0.1');
        $this->assertThrows(fn () => $s->runTask(), 'Sicherung in Google Drive');
        $this->assertSame([], $this->installed, 'zurückgestellt');
        $held = $s->status()['held'];
        $this->assertSame(['1.0.0.2', 'auto'], [$held['version'], $held['kind']]);
        $this->assertStringContainsString('Speicher voll', $held['error']);

        // Abbrechen: der Hinweis geht, am nächsten Tag ein neuer Versuch (mit Google Drive)
        $s->dismissHeld();
        $this->assertNull($s->status()['held']);
        $this->assertThrows(fn () => $s->runTask(), 'Sicherung in Google Drive');
        $this->assertNotNull($s->status()['held'], 'wieder zurückgestellt');

        // Trotzdem aktualisieren: ohne Google Drive
        $s->installHeld();
        $this->assertSame([['1.0.0.2', 'ZIP-1.0.0.2']], $this->installed);
        $this->assertSame('auto', $this->settings->get('update_last')['kind']);
        $this->assertNull($s->status()['held'], 'erledigt');
        $this->assertThrows(fn () => $s->installHeld(), 'kein zurückgestelltes Update');

        // Klappt es am nächsten Tag, verschwindet der Hinweis ebenso.
        $this->installed = [];
        $this->publish(['1.0.0.2', '1.0.0.3'], ['1.0.0.3']);
        $s3 = $this->service('1.0.0.2');
        $this->assertThrows(fn () => $s3->runTask(), 'Sicherung in Google Drive');
        $this->offsiteFails = null;
        $s3->runTask();
        $this->assertSame('1.0.0.3', $this->installed[0][0]);
        $this->assertNull($s3->status()['held']);
        $this->assertCount(1, $this->offsiteStored);

        // Inzwischen anders eingespielt (z. B. von Hand hochgeladen): kein Hinweis mehr
        $this->settings->set(UpdateService::HELD, ['version' => '1.0.0.3', 'kind' => 'auto', 'at' => 1, 'error' => 'x']);
        $this->assertNull($this->service('1.0.0.3')->status()['held']);
    }

    public function testWithdrawnVersionIsHeldToo(): void
    {
        $this->publish(['1.0.0.1', '1.0.0.2'], ['1.0.0.1', '1.0.0.2'], ['1.0.0.2']);
        $this->offsiteActive = true;
        $this->offsiteFails = 'Speicher voll.';
        $s = $this->service('1.0.0.2');
        $this->assertThrows(fn () => $s->runTask(), 'Sicherung in Google Drive');
        $this->assertSame([], $this->installed);
        $this->assertSame('withdrawn', $s->status()['held']['kind']);
        $s->installHeld();
        $this->assertSame('1.0.0.1', $this->installed[0][0]);
        $this->assertSame('withdrawn', $this->settings->get('update_last')['kind']);
    }

    public function testFailedBackupStopsTheUpdateAndLeavesNoPartialFile(): void
    {
        $this->publish(['1.0.0.1', '1.0.0.2'], ['1.0.0.2']);
        $this->offsiteActive = true;
        @mkdir($this->dir . '/i/sicherungen', 0777, true);
        file_put_contents($this->dir . '/i/sicherungen/vor-update-000001-20260101-000000-1.0.0.0-abc.json.tmp', '{"halb');
        $this->backupFails = 'Die Sicherung ließ sich nicht schreiben (Speicherplatz?).';
        $s = $this->service('1.0.0.1');
        $this->assertThrows(fn () => $s->install('1.0.0.2'), 'Sicherung vor dem Update: Die Sicherung ließ sich nicht schreiben (Speicherplatz?). Das Update wurde nicht eingespielt.');
        $this->assertSame([], $this->installed, 'nicht eingespielt');
        $this->assertSame([], glob($this->dir . '/i/sicherungen/vor-update-*') ?: [], 'keine halbe Sicherung, alte Reste entfernt');
        $this->assertSame([], $this->offsiteStored, 'ohne Sicherung auf dem Server auch nichts nach Google Drive');
        $this->assertThrows(fn () => $s->install('1.0.0.2', 'manual', false, true), 'Das Update wurde nicht eingespielt.');
        $this->assertSame([], $this->installed, 'ohne Sicherung auf dem Server nie, auch nicht ohne Google Drive');

        $this->backupFails = null;
        $s->install('1.0.0.2');
        $files = glob($this->dir . '/i/sicherungen/vor-update-*') ?: [];
        $this->assertCount(1, $files);
        $this->assertStringEndsWith('.json', $files[0]);
        $this->assertSame(['format' => 'kompanie-app-backup'], json_decode((string) file_get_contents($files[0]), true), 'Stück für Stück geschrieben (exportTo)');
        $this->assertSame('600', substr(sprintf('%o', fileperms($files[0])), -3));
    }

    public function testBackupsKeepTheNewest(): void
    {
        // Versionen 1.0.0.5 … 1.0.0.12: als Text sortiert käme 1.0.0.10 vor 1.0.0.9.
        $versions = array_map(static fn (int $i): string => "1.0.0.$i", range(5, 12));
        $extra = [];
        foreach ($versions as $i => $v) {
            $extra[$v] = ['schema' => 3 + $i];
        }
        $this->publish($versions, $versions, [], $extra);
        // Eine Sicherung im früheren Namensformat (ohne Nummer) gilt als älteste.
        @mkdir($this->dir . '/i/sicherungen', 0777, true);
        file_put_contents($this->dir . '/i/sicherungen/vor-update-1.0.0.4-20260101-000000-abc.json', '{}');
        foreach (array_slice($versions, 0, -1) as $i => $from) {
            $s = $this->service($from);
            $this->settings->set('schema_version', 2 + $i);
            $s->install($versions[$i + 1]); // die Testuhr steht: alle in derselben Sekunde
        }
        $files = glob($this->dir . '/i/sicherungen/vor-update-*.json') ?: [];
        $this->assertCount(5, $files, 'die letzten 5');
        $names = implode(' ', array_map('basename', $files));
        $this->assertStringContainsString('-1.0.0.11-', $names, 'die neueste Sicherung bleibt');
        $this->assertStringContainsString('-1.0.0.10-', $names);
        $this->assertFileExists($this->dir . '/i/sicherungen/index.html');
        $this->assertStringNotContainsString('vor-update-1.0.0.4-', $names, 'alte Datei zuerst aufgeräumt');
    }

    public function testStableOnlyLockAndSkippedStatus(): void
    {
        $this->publish(['1.0.0.1', '1.0.0.2', '1.0.0.3'], ['1.0.0.1', '1.0.0.3']);
        $s = $this->service('1.0.0.3');
        $s->check();
        $this->assertSame(['1.0.0.1'], array_column($s->status()['versions'], 'version'), 'stable: nur schon freigegebene zum Zurückrollen');
        $this->assertThrows(fn () => $s->install('1.0.0.2'), 'noch nicht für alle freigegeben');

        // Nach dem Zurückrollen zeigt der Status die übersprungene Version an
        $s->rollback('1.0.0.1');
        $s1 = $this->service('1.0.0.1');
        $this->assertTrue($s1->status()['skipped']);

        // Automatische Updates gesperrt (z. B. in WordPress): nichts automatisch, auch kein Rückzug
        $this->autoAllowed = false;
        $this->settings->delete('update_skip');
        $n = count($this->installed);
        $s1->runTask();
        $this->assertCount($n, $this->installed);
        $this->assertFalse($s1->status()['autoAllowed']);

        // Ein Update zur selben Zeit: 409
        $lock = fopen($this->dir . '/i/sicherungen/update.lock', 'c');
        $this->assertFalse($s1->status()['running']);
        flock($lock, LOCK_EX);
        $this->assertTrue($s1->status()['running'], 'die App wartet, statt erneut einspielen zu lassen');
        $this->assertThrows(fn () => $s1->install('1.0.0.3'), 'schon ein Update');
        flock($lock, LOCK_UN);
        $this->assertFalse($s1->status()['running']);
        $s1->install('1.0.0.3');
        $this->assertSame('1.0.0.3', end($this->installed)[0]);
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
