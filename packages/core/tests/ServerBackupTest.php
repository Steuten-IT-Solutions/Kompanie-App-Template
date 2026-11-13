<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Domain\ServerBackupService;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Port\BackupProvider;
use KompanieApp\Core\Storage\Backup;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\NullScheduler;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** Sicherungen automatisch auf dem eigenen Server, Liste mit Herunterladen, Wiederherstellen und Löschen. */
final class ServerBackupTest extends TestCase
{
    private FixedClock $clock;
    private NullScheduler $scheduler;
    private App $app;
    private string $dir;
    /** Was import() bekam */
    private \stdClass $holder;
    private FakeUsers $users;

    protected function setUp(): void
    {
        $users = new FakeUsers();
        $this->clock = new FixedClock(new \DateTimeImmutable('2026-11-11 12:00:00 UTC'));
        $this->scheduler = new NullScheduler();
        $this->dir = sys_get_temp_dir() . '/kompanie-test-' . bin2hex(random_bytes(4));
        $this->holder = new \stdClass();
        $this->holder->imported = null;
        $this->holder->fail = false;
        $backup = new class ($this->holder) implements BackupProvider {
            public function __construct(private readonly \stdClass $holder)
            {
            }

            public function export(): array
            {
                return ['format' => Backup::FORMAT, 'formatVersion' => Backup::FORMAT_VERSION];
            }

            public function exportTo($out): void
            {
                if ($this->holder->fail) {
                    fwrite($out, '{"halb');
                    throw new \RuntimeException('Die Datei dokumente/1.pdf ließ sich nicht lesen.');
                }
                fwrite($out, (string) json_encode($this->export()));
            }

            public function import(string $json): void
            {
                $this->holder->imported = $json;
            }

            public function canImport(): bool
            {
                return true;
            }
        };
        $this->app = TestApp::create(TestApp::connection(), $users, ['name' => 'Testkompanie'], filesDir: $this->dir, clock: $this->clock, scheduler: $this->scheduler, backup: $backup);
        $users->current = $users->add('anna', 'admin');
        $this->users = $users;
    }

    /** @return array{0: int, 1: mixed} */
    private function api(string $method, string $path, array $body = []): array
    {
        $res = $this->app->api()->handle(new Request($method, $path, [], $body));
        return [$res->status, json_decode((string) json_encode($res->data), true)];
    }

    private function folder(): string
    {
        return $this->dir . '/private/sicherungen';
    }

    /** @return string[] Namen der wöchentlichen Sicherungen */
    private function weekly(): array
    {
        $names = array_map('basename', glob($this->folder() . '/sicherung-*.json') ?: []);
        sort($names);
        return $names;
    }

    private function days(int $days): void
    {
        $this->clock->at = $this->clock->now()->modify('+' . $days . ' days');
    }

    public function testWeeklyBackupOnTheServerKeepsTheLastFour(): void
    {
        [$status, $data] = $this->api('GET', '/admin/backup/server');
        $this->assertSame(200, $status);
        $this->assertFalse($data['enabled']);
        $this->assertTrue($data['can_restore']);
        $this->assertArrayNotHasKey(ServerBackupService::TASK, $this->scheduler->scheduled);

        [$status, $data] = $this->api('PUT', '/admin/backup/server', ['enabled' => true]);
        $this->assertSame(200, $status);
        $this->assertTrue($data['enabled']);
        $this->assertSame(ServerBackupService::INTERVAL, $this->scheduler->scheduled[ServerBackupService::TASK] ?? null, 'Aufgabe eingeplant');

        $this->app->runTask(ServerBackupService::TASK);
        $this->assertCount(1, $this->weekly());
        $this->assertMatchesRegularExpression('/^sicherung-20261111-120000-[0-9a-f]{32}\.json$/', $this->weekly()[0]);
        $this->assertSame(Backup::FORMAT, json_decode((string) file_get_contents($this->folder() . '/' . $this->weekly()[0]), true)['format']);
        $this->assertFileExists($this->folder() . '/index.html');
        $this->assertSame([], glob($this->folder() . '/*.tmp') ?: [], 'keine vorläufige Datei');

        $this->days(3);
        $this->app->runTask(ServerBackupService::TASK);
        $this->assertCount(1, $this->weekly(), 'erst nach einer Woche wieder');

        // Sicherung vor einem Update bleibt beim Aufräumen liegen.
        file_put_contents($this->folder() . '/vor-update-000001-20261101-100000-0.9.0.1-' . str_repeat('a', 32) . '.json', '{}');
        for ($i = 0; $i < 5; $i++) {
            $this->days(7);
            $this->app->runTask(ServerBackupService::TASK);
        }
        $this->assertCount(ServerBackupService::KEEP, $this->weekly(), 'die letzten vier bleiben');
        $this->assertStringStartsWith('sicherung-20261128-', $this->weekly()[0], 'die ältesten sind weg');
        $this->assertCount(1, glob($this->folder() . '/vor-update-*.json') ?: []);

        [, $data] = $this->api('GET', '/admin/backup/server');
        $this->assertSame($this->clock->now()->getTimestamp(), $data['last_ok']);
        $this->assertNull($data['error']);
        $this->assertCount(5, $data['files']);
        $this->assertSame('weekly', $data['files'][0]['kind'], 'neueste zuerst');
        $this->assertSame($this->clock->now()->getTimestamp(), $data['files'][0]['created']);
        $update = array_values(array_filter($data['files'], static fn (array $f): bool => $f['kind'] === 'update'))[0];
        $this->assertSame('0.9.0.1', $update['version']);
        $this->assertSame(2, $update['size']);

        // Ausschalten plant die Aufgabe aus; Sicherungen bleiben.
        $this->api('PUT', '/admin/backup/server', ['enabled' => false]);
        $this->assertArrayNotHasKey(ServerBackupService::TASK, $this->scheduler->scheduled);
        $this->assertCount(ServerBackupService::KEEP, $this->weekly());
    }

    public function testCreateNowAndStatusLight(): void
    {
        [$status, $data] = $this->api('POST', '/admin/backup/server/create');
        $this->assertSame(200, $status);
        $this->assertCount(1, $data['files']);
        $this->assertCount(1, $this->weekly());

        $this->api('PUT', '/admin/backup/server', ['enabled' => true]);
        $item = array_values(array_filter($this->app->status()->report()['items'], static fn (array $i): bool => $i['key'] === 'backup'))[0];
        $this->assertStringContainsString('Letzte Sicherung auf dem Server: 11.11.2026 13:00.', $item['text']);
        $this->assertSame('warn', $item['level'], 'zählt nicht als Sicherung außerhalb des Servers');
    }

    public function testErrorIsShownInStateAndStatus(): void
    {
        $this->api('PUT', '/admin/backup/server', ['enabled' => true]);
        $this->holder->fail = true;
        [$status, $data] = $this->api('POST', '/admin/backup/server/create');
        $this->assertSame(500, $status);
        $this->assertStringContainsString('dokumente/1.pdf', $data['message'] ?? '');
        [, $data] = $this->api('GET', '/admin/backup/server');
        $this->assertStringContainsString('dokumente/1.pdf', (string) $data['error']);
        $this->assertSame([], $data['files'], 'keine halbe Sicherung');
        $this->assertSame([], glob($this->folder() . '/*.tmp') ?: []);
        $item = array_values(array_filter($this->app->status()->report()['items'], static fn (array $i): bool => $i['key'] === 'backup'))[0];
        $this->assertSame('warn', $item['level']);
        $this->assertStringContainsString('Die letzte Sicherung auf dem Server ist fehlgeschlagen', $item['text']);

        // Die tägliche Prüfung versucht es wieder; danach ist der Fehler weg.
        $this->holder->fail = false;
        $this->app->runTask(ServerBackupService::TASK);
        [, $data] = $this->api('GET', '/admin/backup/server');
        $this->assertNull($data['error']);
        $this->assertCount(1, $data['files']);
    }

    public function testRestoreFromTheServer(): void
    {
        [, $data] = $this->api('POST', '/admin/backup/server/create');
        $id = $data['files'][0]['id'];

        [$status] = $this->api('POST', '/admin/backup/server/restore', ['id' => $id]);
        $this->assertSame(400, $status, 'nur bestätigt');
        $this->assertNull($this->holder->imported);

        [$status] = $this->api('POST', '/admin/backup/server/restore', ['id' => 'sicherung-x.json', 'confirm' => true]);
        $this->assertSame(404, $status);

        [$status, $data] = $this->api('POST', '/admin/backup/server/restore', ['id' => $id, 'confirm' => true]);
        $this->assertSame(200, $status);
        $this->assertTrue($data['restored']);
        $this->assertSame(Backup::FORMAT, json_decode((string) $this->holder->imported, true)['format']);
    }

    public function testOnlyBackupsOfTheFolderAreReachable(): void
    {
        $service = $this->app->serverBackup();
        $service->create();
        @mkdir($this->dir . '/private/geheim', 0777, true);
        file_put_contents($this->dir . '/private/geheim/x.json', '{}');
        file_put_contents($this->folder() . '/update.lock', '');
        foreach (['../geheim/x.json', 'update.lock', 'index.html', 'vor-update-../../geheim/x.json', 'vor-update-..json', '', 'sicherung-20261111-120000-abc.json'] as $id) {
            $this->assertNull($service->path($id), $id);
        }
        $this->assertCount(1, $service->files(), 'nur Sicherungen in der Liste');
    }

    public function testDownloadOnlyForAdmins(): void
    {
        $id = $this->app->serverBackup()->create()['files'][0]['id'];
        $download = $this->app->backupDownload();
        $admin = $this->app->authContext->currentUser();
        $this->assertNotNull($admin);
        $this->assertSame(401, $download->forDelivery(null, false, $id)['status']);
        $member = new User(99, 'Max', 'max', 'member', [Capability::Member]);
        $this->assertSame(403, $download->forDelivery($member, false, $id)['status']);
        $preview = new User($admin->id, $admin->name, $admin->login, $admin->role, $admin->capabilities, preview: true);
        $this->assertSame(403, $download->forDelivery($preview, false, $id)['status'], 'nicht in der Vorschau');
        $this->assertSame(404, $download->forDelivery($admin, false, '../geheim.json')['status']);

        $res = $download->forDelivery($admin, false, $id);
        $this->assertSame(200, $res['status']);
        $this->assertSame('attachment; filename="' . $id . '"', $res['headers']['Content-Disposition']);
        $out = fopen('php://temp', 'w+b');
        $this->assertNotFalse($out);
        $this->assertArrayHasKey('write', $res);
        ($res['write'])($out);
        rewind($out);
        $this->assertSame((string) file_get_contents($this->folder() . '/' . $id), stream_get_contents($out));
        $this->assertSame((string) filesize($this->folder() . '/' . $id), $res['headers']['Content-Length']);
    }

    public function testDelete(): void
    {
        $id = $this->app->serverBackup()->create()['files'][0]['id'];
        [$status, $data] = $this->api('POST', '/admin/backup/server/delete', ['id' => $id]);
        $this->assertSame(200, $status);
        $this->assertSame([], $data['files']);
        $this->assertSame([], $this->weekly());
        [$status] = $this->api('POST', '/admin/backup/server/delete', ['id' => $id]);
        $this->assertSame(404, $status);
    }

    public function testOnlyForAdmins(): void
    {
        $this->users->current = $this->users->add('max', 'member');
        foreach ([['GET', '/admin/backup/server'], ['PUT', '/admin/backup/server'], ['POST', '/admin/backup/server/create'], ['POST', '/admin/backup/server/restore'], ['POST', '/admin/backup/server/delete']] as [$method, $path]) {
            $this->assertSame(403, $this->api($method, $path, ['enabled' => true, 'confirm' => true])[0], $method . ' ' . $path);
        }
    }

    public function testSwitchAndStateStayWithTheInstallation(): void
    {
        $this->assertContains(ServerBackupService::SETTING, Backup::LOCAL_SETTINGS);
        $this->assertContains(ServerBackupService::STATE_SETTING, Backup::LOCAL_SETTINGS);
    }
}
