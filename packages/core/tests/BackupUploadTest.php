<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Domain\BackupUploadService;
use KompanieApp\Core\Domain\DriveClient;
use KompanieApp\Core\Domain\StatusService;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Port\BackupProvider;
use KompanieApp\Core\Storage\Backup;
use KompanieApp\Core\Storage\BackupCrypto;
use KompanieApp\Core\Tests\Support\Crypt;
use KompanieApp\Core\Tests\Support\FakeHttpClient;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\NullScheduler;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** Sicherungen automatisch in Google Drive (Google simuliert: Ordner, fortsetzbares Hochladen, Liste, Löschen). */
final class BackupUploadTest extends TestCase
{
    private const PASS = 'passwort-fuer-sicherungen';
    /** Schlüssel mit den kleinsten Parametern (schnell), einmal je Lauf */
    private static ?array $key = null;

    private FixedClock $clock;
    private NullScheduler $scheduler;
    private App $app;
    /** @var array<string, array{name: string, parent: string, content: string}> Dateien in Drive */
    private array $drive = [];
    /** @var string[] angelegte Ordner */
    private array $folders = [];
    /** Antwort beim Hochladen der Daten (z. B. 507, wenn der Speicher voll ist). */
    private int $putStatus = 200;
    private int $ids = 0;
    /** Content-Range je Hochladen der Daten (null: in einem Stück) */
    private array $puts = [];

    protected function setUp(): void
    {
        $users = new FakeUsers();
        $this->clock = new FixedClock(new \DateTimeImmutable('2026-10-20 12:00:00 UTC'));
        $this->scheduler = new NullScheduler();
        $holder = new \stdClass();
        $backup = new class ($holder) implements BackupProvider {
            public function __construct(private readonly \stdClass $holder)
            {
            }

            public function export(): array
            {
                return ['format' => Backup::FORMAT, 'formatVersion' => Backup::FORMAT_VERSION, 'tables' => (new Backup($this->holder->app->db))->dump(Backup::CORE_TABLES)];
            }

            public function exportTo($out): void
            {
                (new Backup($this->holder->app->db))->write($out, Backup::CORE_TABLES, null, ['format' => Backup::FORMAT, 'formatVersion' => Backup::FORMAT_VERSION], []);
            }

            public function import(string $json): void
            {
            }

            public function canImport(): bool
            {
                return false;
            }
        };
        $this->app = TestApp::create(TestApp::connection(), $users, ['name' => 'Testkompanie'], $this->http(), clock: $this->clock, scheduler: $this->scheduler, backup: $backup);
        $holder->app = $this->app;
        $users->current = $users->add('anna', 'admin');
        // Nach Drive nur verschlüsselt: Passwort für Sicherungen ist eingerichtet und geprüft (ohne: testNeedsPassphrase,
        // ungeprüft: testPlainBackupsStayUntilPassphraseVerified).
        self::$key ??= BackupCrypto::newKey(self::PASS, 1, 8192);
        $this->app->settings->set(BackupCrypto::SETTING, self::$key + ['verified' => true]);
    }

    private static function decrypt(string $content): string
    {
        return Crypt::string($content, self::PASS);
    }

    private function http(): FakeHttpClient
    {
        return new FakeHttpClient(function (string $method, string $url, array $headers, ?string $body): array {
            if (str_starts_with($url, DriveClient::TOKEN_URI)) {
                return ['status' => 200, 'body' => (string) json_encode(['access_token' => 'TOKEN', 'expires_in' => 3600])];
            }
            if (str_starts_with($url, 'https://upload.example/session/')) {
                if ($this->putStatus !== 200) {
                    return ['status' => $this->putStatus, 'body' => '{"error":{"message":"The user\'s Drive storage quota has been exceeded."}}'];
                }
                $id = substr($url, strlen('https://upload.example/session/'));
                $this->puts[] = $headers['Content-Range'] ?? null;
                // In Stücken (Content-Range): anhängen, 308 bis zum letzten Stück
                if (isset($headers['Content-Range']) && preg_match('#^bytes (\d+)-(\d+)/(\d+)$#', $headers['Content-Range'], $m) === 1) {
                    if ((int) $m[1] !== strlen($this->drive[$id]['content']) || (int) $m[2] - (int) $m[1] + 1 !== strlen((string) $body)) {
                        return ['status' => 400, 'body' => '{"error":{"message":"Falscher Bereich"}}'];
                    }
                    $this->drive[$id]['content'] .= (string) $body;
                    return (int) $m[2] + 1 < (int) $m[3]
                        ? ['status' => 308, 'headers' => ['range' => 'bytes=0-' . $m[2]], 'body' => '']
                        : ['status' => 200, 'body' => (string) json_encode(['id' => $id])];
                }
                $this->drive[$id]['content'] = (string) $body;
                return ['status' => 200, 'body' => (string) json_encode(['id' => $id])];
            }
            if (($headers['Authorization'] ?? '') !== 'Bearer TOKEN') {
                return ['status' => 401, 'body' => '{"error":{"message":"Kein Token"}}'];
            }
            if (str_starts_with($url, DriveClient::UPLOAD_URL) && $method === 'POST') {
                $meta = json_decode((string) $body, true);
                $id = 'F' . ++$this->ids;
                $this->drive[$id] = ['name' => $meta['name'], 'parent' => $meta['parents'][0], 'content' => ''];
                return ['status' => 200, 'headers' => ['location' => 'https://upload.example/session/' . $id], 'body' => ''];
            }
            if ($method === 'POST' && str_starts_with($url, DriveClient::FILES_URL . '?')) {
                $id = 'D' . ++$this->ids;
                $this->folders[] = $id;
                return ['status' => 200, 'body' => (string) json_encode(['id' => $id])];
            }
            $id = rawurldecode((string) preg_replace('#^.*/files/([^?]+)\?.*$#', '$1', $url));
            if ($method === 'DELETE') {
                unset($this->drive[$id]);
                return ['status' => 204, 'body' => ''];
            }
            if ($method === 'GET' && str_contains($url, 'fields=id,trashed')) {
                return in_array($id, $this->folders, true)
                    ? ['status' => 200, 'body' => (string) json_encode(['id' => $id, 'trashed' => false])]
                    : ['status' => 404, 'body' => '{"error":{"message":"File not found"}}'];
            }
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            preg_match("/'([^']+)' in parents/", (string) ($q['q'] ?? ''), $m);
            $files = [];
            foreach ($this->drive as $fid => $f) {
                if ($f['parent'] === ($m[1] ?? '')) {
                    $files[] = ['id' => $fid, 'name' => $f['name'], 'mimeType' => 'application/json'];
                }
            }
            return ['status' => 200, 'body' => (string) json_encode(['files' => $files])];
        });
    }

    /** Verbindung über „Mit Google verbinden“, mit oder ohne drive.file. */
    private function connect(bool $withFiles = true): void
    {
        $this->app->drive()->saveOAuth([
            'client_id' => 'c.apps.googleusercontent.com',
            'client_secret' => 'GOCSPX-x',
            'refresh_token' => 'R',
            'email' => 'kompanie@example.org',
            'scope' => $withFiles ? DriveClient::oauthScopes() : DriveClient::scopes(),
        ]);
    }

    /** @return array{0: int, 1: mixed} */
    private function api(string $method, string $path, array $body = []): array
    {
        $res = $this->app->api()->handle(new Request($method, $path, [], $body));
        return [$res->status, json_decode((string) json_encode($res->data), true)];
    }

    private function backupItem(): array
    {
        return array_values(array_filter($this->app->status()->report()['items'], static fn (array $i): bool => $i['key'] === 'backup'))[0];
    }

    public function testNeedsConnectionWithFileAccess(): void
    {
        [$s, $r] = $this->api('GET', '/admin/backup/drive');
        $this->assertSame(200, $s);
        $this->assertFalse($r['available']);
        [$s, $r] = $this->api('PUT', '/admin/backup/drive', ['enabled' => true]);
        $this->assertSame(400, $s);
        $this->assertStringContainsString('Mit Google verbinden', $r['message']);

        $this->connect(false);
        [$s, $r] = $this->api('PUT', '/admin/backup/drive', ['enabled' => true]);
        $this->assertSame(400, $s, 'ältere Verbindung ohne drive.file: neu verbinden');
        $this->assertStringContainsString('neu mit Google verbinden', $r['message']);
        $this->assertArrayNotHasKey(BackupUploadService::TASK, $this->scheduler->scheduled);
    }

    public function testWeeklyUploadIntoOwnFolderKeepsTheLastEight(): void
    {
        $this->connect();
        [$s, $r] = $this->api('PUT', '/admin/backup/drive', ['enabled' => true]);
        $this->assertSame(200, $s, (string) json_encode($r));
        $this->assertTrue($r['enabled'] && $r['access']);
        $this->assertSame(BackupUploadService::INTERVAL, $this->scheduler->scheduled[BackupUploadService::TASK] ?? null, 'Aufgabe eingeplant');

        $this->app->runTask(BackupUploadService::TASK);
        $this->assertCount(1, $this->folders, 'eigener Ordner angelegt');
        $file = array_values($this->drive)[0];
        $this->assertMatchesRegularExpression('/^sicherung-2026-10-20-\d{6}-k[0-9a-f]{8}\.json\.enc$/', $file['name'], 'Datum und Uhrzeit (Ortszeit) im Namen');
        $this->assertSame($this->folders[0], $file['parent']);
        $this->assertTrue(BackupCrypto::isEncrypted($file['content']), 'verschlüsselt');
        $this->assertSame(Backup::FORMAT, json_decode(self::decrypt($file['content']), true)['format'] ?? null, 'Inhalt ist die Sicherung');
        $status = $this->app->backupUpload()->status();
        $this->assertSame($this->clock->now()->getTimestamp(), $status['last_ok']);
        $this->assertSame('https://drive.google.com/drive/folders/' . $this->folders[0], $status['folder_url']);

        // Innerhalb der Woche nicht noch einmal.
        $this->clock->at = new \DateTimeImmutable('2026-10-26 12:00:00 UTC');
        $this->app->runTask(BackupUploadService::TASK);
        $this->assertCount(1, $this->drive);

        // Danach wöchentlich, in denselben Ordner; mehr als acht: die ältesten gehen.
        for ($week = 1; $week <= 9; $week++) {
            $this->clock->at = (new \DateTimeImmutable('2026-10-20 12:00:00 UTC'))->modify('+' . (7 * $week) . ' days');
            $this->app->runTask(BackupUploadService::TASK);
        }
        $this->assertCount(1, $this->folders);
        $names = array_column($this->drive, 'name');
        sort($names);
        $this->assertCount(BackupUploadService::KEEP, $names);
        $this->assertStringStartsWith('sicherung-2026-11-03-', $names[0]);
        $this->assertStringStartsWith('sicherung-2026-12-22-', $names[7]);
    }

    public function testErrorIsShownAndRetried(): void
    {
        $this->connect();
        $this->api('PUT', '/admin/backup/drive', ['enabled' => true]);
        $this->putStatus = 403;
        [$s, $r] = $this->api('POST', '/admin/backup/drive/upload');
        $this->assertSame(502, $s);
        $this->assertStringContainsString('storage quota', $r['message']);
        $status = $this->app->backupUpload()->status();
        $this->assertNull($status['last_ok']);
        $this->assertStringContainsString('storage quota', (string) $status['error']);
        $item = $this->backupItem();
        $this->assertSame('warn', $item['level']);
        $this->assertStringContainsString('fehlgeschlagen', $item['text']);

        // Am nächsten Tag klappt es; der Fehler ist weg, die Ampel grün.
        $this->putStatus = 200;
        $this->clock->at = new \DateTimeImmutable('2026-10-21 12:00:00 UTC');
        $this->app->runTask(BackupUploadService::TASK);
        $status = $this->app->backupUpload()->status();
        $this->assertNull($status['error']);
        $this->assertNotNull($status['last_ok']);
        $this->assertSame('ok', $this->backupItem()['level']);
    }

    public function testBackupIsStreamedAndLargeFilesGoInChunks(): void
    {
        $this->connect();
        $this->api('PUT', '/admin/backup/drive', ['enabled' => true]);
        $this->app->backupUpload()->upload();
        $backup = array_values($this->drive)[0]['content'];
        $data = json_decode(self::decrypt($backup), true);
        $this->assertSame(Backup::FORMAT, $data['format'] ?? null, 'Sicherung Stück für Stück erzeugt, gültiges JSON');
        $this->assertSame((new Backup($this->app->db))->dump(Backup::CORE_TABLES), $data['tables']);
        $this->assertSame([null], $this->puts, 'klein: in einem Stück');

        // Große Datei: Stücke mit Content-Range, 308 dazwischen, zuletzt die ID
        $this->puts = [];
        $file = tempnam(sys_get_temp_dir(), 'gross');
        $content = random_bytes(600 * 1024);
        file_put_contents($file, $content);
        $seen = [];
        $id = $this->app->drive()->uploadFile('ORDNER', 'gross.bin', $file, 'application/octet-stream', 256 * 1024, function (int $done, int $total) use (&$seen): void {
            $seen[] = "$done/$total";
        });
        @unlink($file);
        $this->assertSame(['bytes 0-262143/614400', 'bytes 262144-524287/614400', 'bytes 524288-614399/614400'], $this->puts);
        $this->assertSame(['0/614400', '262144/614400', '524288/614400'], $seen, 'Fortschritt nach jedem angenommenen Stück');
        $this->assertSame($content, $this->drive[$id]['content']);
    }

    public function testDownloadAsFileOnlyForAdminsAndNotInPreview(): void
    {
        $download = $this->app->backupDownload();
        $admin = $this->app->authContext->currentUser();
        $this->assertNotNull($admin);
        $this->assertSame(401, $download->forDelivery(null)['status']);
        $preview = new \KompanieApp\Core\Auth\User($admin->id, $admin->name, $admin->login, $admin->role, $admin->capabilities, preview: true);
        $this->assertSame(403, $download->forDelivery($preview)['status'], 'nicht in der Vorschau');
        $member = new \KompanieApp\Core\Auth\User(99, 'Max', 'max', 'member', [\KompanieApp\Core\Auth\Capability::Member]);
        $this->assertSame(403, $download->forDelivery($member)['status']);

        $res = $download->forDelivery($admin);
        $this->assertSame(200, $res['status']);
        $this->assertSame('attachment; filename="sicherung-2026-10-20.json"', $res['headers']['Content-Disposition']);
        $this->assertNull($this->app->settings->get(StatusService::BACKUP_SETTING), 'erst nach dem Schreiben gezählt');
        $out = fopen('php://temp', 'w+b');
        ($res['write'])($out);
        rewind($out);
        $this->assertSame(Backup::FORMAT, json_decode((string) stream_get_contents($out), true)['format'] ?? null);
        $this->assertSame($this->clock->now()->getTimestamp(), $this->app->settings->get(StatusService::BACKUP_SETTING));
        $this->assertSame('https://example.org/app/sicherung', $this->app->api()->config()['backupUrl']);
    }

    /** Sicherung vor einem Update: in denselben Ordner, eigene Namen, die letzten fünf; zählt als Sicherung außerhalb. */
    public function testBackupBeforeUpdateKeepsTheLastFiveBesideTheWeekly(): void
    {
        $offsite = $this->app->backupUpload();
        $this->assertFalse($offsite->active());
        $this->connect();
        $this->api('PUT', '/admin/backup/drive', ['enabled' => true]);
        $this->assertTrue($offsite->active());
        $offsite->upload();
        $file = tempnam(sys_get_temp_dir(), 'vor');
        file_put_contents($file, '{"format":"kompanie-app-backup"}');
        for ($i = 1; $i <= 7; $i++) {
            $this->clock->at = (new \DateTimeImmutable('2026-10-20 12:00:00 UTC'))->modify("+$i days");
            $offsite->storeBeforeUpdate($file, "1.0.0.$i");
        }
        $names = array_column($this->drive, 'name');
        sort($names);
        $this->assertCount(1 + BackupUploadService::KEEP_BEFORE_UPDATE, $names, 'die wöchentliche bleibt, vor Updates die letzten fünf');
        $this->assertStringStartsWith('sicherung-2026-10-20-', $names[0]);
        $this->assertMatchesRegularExpression('/^vor-update-2026-10-23-\d{6}-k[0-9a-f]{8}-1\.0\.0\.3\.json\.enc$/', $names[1]);
        $this->assertStringEndsWith('-1.0.0.7.json.enc', $names[5]);
        $this->assertSame([$this->folders[0]], array_values(array_unique(array_column($this->drive, 'parent'))), 'derselbe Ordner');
        $before = array_filter($this->drive, static fn (array $f): bool => str_starts_with($f['name'], 'vor-update-'));
        $this->assertSame(['{"format":"kompanie-app-backup"}'], array_values(array_unique(array_map(self::decrypt(...), array_column($before, 'content')))), 'die Sicherung vom Server, verschlüsselt');
        $this->assertSame('{"format":"kompanie-app-backup"}', file_get_contents($file), 'auf dem Server unverändert');
        $this->assertSame($this->clock->now()->getTimestamp(), $offsite->status()['last_ok'], 'zählt wie die wöchentliche');

        $this->putStatus = 507;
        try {
            $offsite->storeBeforeUpdate($file, '1.0.0.8');
            $this->fail('Fehler erwartet');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('storage quota', $e->getMessage());
        }
        @unlink($file);
        $this->assertStringContainsString('storage quota', (string) $offsite->status()['error'], 'Grund steht bei den Sicherungen');
    }

    public function testDeletedFolderIsCreatedAgain(): void
    {
        $this->connect();
        $this->api('PUT', '/admin/backup/drive', ['enabled' => true]);
        $this->app->backupUpload()->upload();
        $this->folders = [];
        $this->app->backupUpload()->upload();
        $this->assertCount(1, $this->folders);
        $this->assertSame($this->folders[0], array_values($this->drive)[1]['parent']);
    }

    public function testReminderAfterThirtyDaysWithoutBackupOutsideTheServer(): void
    {
        $this->assertSame('warn', $this->backupItem()['level'], 'noch nie gesichert');
        $this->app->settings->set(StatusService::BACKUP_SETTING, $this->clock->now()->getTimestamp() - 29 * 86400);
        $this->assertSame('ok', $this->backupItem()['level']);
        $this->app->settings->set(StatusService::BACKUP_SETTING, $this->clock->now()->getTimestamp() - 31 * 86400);
        $this->assertSame('warn', $this->backupItem()['level'], 'nach 30 Tagen gelb (früher 90)');
        // Eine Sicherung in Google Drive zählt ebenso.
        $this->connect();
        $this->api('PUT', '/admin/backup/drive', ['enabled' => true]);
        $this->app->backupUpload()->upload();
        $item = $this->backupItem();
        $this->assertSame('ok', $item['level']);
        $this->assertStringContainsString('Google Drive', $item['text']);
    }

    /** Ohne Passwort für Sicherungen: nicht einschaltbar; eine früher eingeschaltete Sicherung pausiert (gelb). */
    public function testWithoutPassphraseUnencrypted(): void
    {
        $this->connect();
        $this->app->settings->delete(BackupCrypto::SETTING);
        // Ohne Passwort für Sicherungen: einschaltbar, unverschlüsselt wie bisher; die unverschlüsselten wechseln durch.
        $this->assertSame(200, $this->api('PUT', '/admin/backup/drive', ['enabled' => true])[0]);
        $offsite = $this->app->backupUpload();
        $this->assertTrue($offsite->active());
        $this->assertFalse($offsite->status()['encrypted']);
        $this->assertSame(BackupUploadService::INTERVAL, $this->scheduler->scheduled[BackupUploadService::TASK] ?? null);
        for ($i = 0; $i < 9; $i++) {
            $this->clock->at = new \DateTimeImmutable('2026-10-28 12:00:00 UTC +' . (7 * $i) . ' days');
            $offsite->upload();
        }
        $names = array_column($this->drive, 'name');
        $this->assertCount(8, $names, 'die letzten acht');
        foreach ($names as $n) {
            $this->assertMatchesRegularExpression('/^sicherung-[\d-]+\.json$/', $n);
        }
        $this->assertSame('ok', $this->backupItem()['level'], 'keine Warnung ohne Passwort');
        $local = $this->app->files->tempFile();
        file_put_contents($local, '{"format":"kompanie-app-backup"}');
        $offsite->storeBeforeUpdate($local, '1.2.3');
        @unlink($local);
        $this->assertContains('{"format":"kompanie-app-backup"}', array_column(array_filter($this->drive, static fn (array $f): bool => str_starts_with($f['name'], 'vor-update-') && str_ends_with($f['name'], '-1.2.3.json')), 'content'), 'vor dem Update unverschlüsselt');

        // Passwort einrichten (Mindestlänge, beide gleich), dann verschlüsselt.
        $this->assertSame(400, $this->api('PUT', '/admin/backup/encryption', ['passphrase' => 'kurz', 'passphrase_confirm' => 'kurz'])[0]);
        $this->assertSame(400, $this->api('PUT', '/admin/backup/encryption', ['passphrase' => self::PASS, 'passphrase_confirm' => self::PASS . 'x'])[0]);
        [$s, $r] = $this->api('PUT', '/admin/backup/encryption', ['passphrase' => self::PASS, 'passphrase_confirm' => self::PASS]);
        $this->assertSame(200, $s, (string) json_encode($r));
        $this->assertTrue($r['configured']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{4}( [0-9a-f]{4}){3}$/', $r['fingerprint']);
        $this->assertTrue($offsite->status()['encrypted']);
        $this->clock->at = new \DateTimeImmutable('2027-03-01 12:00:00 UTC');
        $offsite->upload();
        $this->assertCount(1, array_filter(array_column($this->drive, 'name'), static fn (string $n): bool => str_ends_with($n, BackupCrypto::EXTENSION)));
        $this->assertSame([200, ['ok' => true]], $this->api('POST', '/admin/backup/encryption/verify', ['passphrase' => self::PASS]));
        $this->assertSame([200, ['ok' => false]], $this->api('POST', '/admin/backup/encryption/verify', ['passphrase' => 'falsch']));
        // Nur Fehlversuche zählen (richtige Passwörter sperren kein Wiederherstellen im Notfall); nach zehn ist 15 Minuten Pause.
        for ($i = 0; $i < 20; $i++) {
            $this->assertSame([200, ['ok' => true]], $this->api('POST', '/admin/backup/encryption/verify', ['passphrase' => self::PASS]), "richtig $i");
        }
        for ($i = 2; $i <= 10; $i++) {
            $this->assertSame([200, ['ok' => false]], $this->api('POST', '/admin/backup/encryption/verify', ['passphrase' => 'falsch']), "Fehlversuch $i");
        }
        $this->assertSame(429, $this->api('POST', '/admin/backup/encryption/verify', ['passphrase' => self::PASS])[0], 'nach zehn Fehlversuchen gesperrt');
        // Gesperrtes Prüfen sperrt kein Wiederherstellen (eigener Zähler).
        $in = fopen('php://memory', 'w+b');
        $out = fopen('php://memory', 'w+b');
        fwrite($in, '{"format":"kompanie-app-backup"}');
        rewind($in);
        Crypt::encrypt($in, $out, (array) $this->app->settings->get(BackupCrypto::SETTING));
        rewind($out);
        $enc = (string) stream_get_contents($out);
        $this->assertSame('{"format":"kompanie-app-backup"}', $this->app->backupEncryption()->readUpload(base64_encode($enc), self::PASS));
        // Mit Zeilenumbrüchen wie von `base64` auf der Kommandozeile, größer als ein Stück (1 MiB Base64)
        $plain = random_bytes(900000);
        $in = fopen('php://memory', 'w+b');
        $out = fopen('php://memory', 'w+b');
        fwrite($in, $plain);
        rewind($in);
        Crypt::encrypt($in, $out, (array) $this->app->settings->get(BackupCrypto::SETTING));
        rewind($out);
        $this->assertSame($plain, $this->app->backupEncryption()->readUpload(chunk_split(base64_encode((string) stream_get_contents($out)), 76, "\n"), self::PASS));
    }

    /** Eine beschädigte Datei ist kein Fehlversuch: Sie sperrt das Wiederherstellen nicht. */
    public function testDamagedFileIsNoFailedAttempt(): void
    {
        $in = fopen('php://memory', 'w+b');
        $out = fopen('php://memory', 'w+b');
        fwrite($in, '{}');
        rewind($in);
        Crypt::encrypt($in, $out, self::$key);
        rewind($out);
        $damaged = substr((string) stream_get_contents($out), 0, -5);
        for ($i = 0; $i < 12; $i++) {
            try {
                $this->app->backupEncryption()->readUpload(base64_encode($damaged), self::PASS);
                $this->fail('beschädigt');
            } catch (\KompanieApp\Core\Http\ApiError $e) {
                $this->assertSame(400, $e->status, "Versuch $i");
            }
        }
        // Kaputt übertragen (kein gültiges Base64): so gemeldet, nicht als beschädigte Sicherung.
        try {
            $this->app->backupEncryption()->readUpload(base64_encode($damaged) . '!', self::PASS);
            $this->fail('kein Base64');
        } catch (\KompanieApp\Core\Http\ApiError $e) {
            $this->assertStringContainsString('Übertragung', $e->getMessage());
        }
        $this->assertSame([200, ['ok' => true]], $this->api('POST', '/admin/backup/encryption/verify', ['passphrase' => self::PASS]));
        $stored = (string) json_encode($this->app->settings->get(BackupCrypto::SETTING));
        $this->assertStringNotContainsString(self::PASS, $stored, 'Passwort nicht gespeichert');
    }

    /** Unverschlüsselte Sicherungen bleiben, bis das neue Passwort einmal geprüft ist (ein vertipptes Passwort). */
    public function testPlainBackupsStayUntilPassphraseVerified(): void
    {
        $this->connect();
        $this->assertSame(200, $this->api('PUT', '/admin/backup/encryption', ['passphrase' => self::PASS, 'passphrase_confirm' => self::PASS])[0]);
        $this->assertFalse($this->app->backupEncryption()->status()['verified']);
        $setting = (array) $this->app->settings->get(BackupCrypto::SETTING);
        $this->app->settings->set(BackupCrypto::SETTING, array_diff_key($setting, ['verified' => 1]));
        $this->assertFalse($this->app->backupEncryption()->status()['verified'], 'ohne Schalter: ungeprüft');
        $this->app->settings->set(BackupCrypto::SETTING, $setting);
        $this->api('PUT', '/admin/backup/drive', ['enabled' => true]);
        $this->app->backupUpload()->upload();
        $this->drive['alt'] = ['name' => 'sicherung-2026-01-01-120000.json', 'parent' => $this->folders[0], 'content' => '{}'];
        // Mit einem früheren Passwort: bleibt ebenso; die mit dem aktuellen wechseln durch (KEEP).
        $this->drive['frueher'] = ['name' => 'sicherung-2026-01-02-120000-k00000000.json.enc', 'parent' => $this->folders[0], 'content' => 'x'];
        for ($i = 0; $i < 9; $i++) {
            $this->clock->at = new \DateTimeImmutable('2026-10-28 12:00:00 UTC +' . (7 * $i) . ' days');
            $this->app->backupUpload()->upload();
        }
        $names = array_column($this->drive, 'name');
        $this->assertContains('sicherung-2026-01-01-120000.json', $names, 'noch nicht geprüft: bleibt');
        $this->assertContains('sicherung-2026-01-02-120000-k00000000.json.enc', $names, 'früheres Passwort: bleibt');
        $this->assertCount(8 + 2, $names, 'aktuelles Passwort: die letzten acht');
        $this->assertStringContainsString('noch nicht geprüft', $this->backupItem()['text'], 'Statusübersicht sagt es');
        // Wiederhergestellt mit dem eingerichteten Passwort: zählt wie „Passwort prüfen“.
        $in = fopen('php://memory', 'w+b');
        $out = fopen('php://memory', 'w+b');
        fwrite($in, '{}');
        rewind($in);
        Crypt::encrypt($in, $out, (array) $this->app->settings->get(BackupCrypto::SETTING));
        rewind($out);
        $this->app->backupEncryption()->readUpload(base64_encode((string) stream_get_contents($out)), self::PASS);
        $this->assertTrue($this->app->backupEncryption()->status()['verified']);
        $this->clock->at = new \DateTimeImmutable('2027-01-04 12:00:00 UTC');
        $this->app->backupUpload()->upload();
        $this->assertNotContains('sicherung-2026-01-01-120000.json', array_column($this->drive, 'name'), 'geprüft: gelöscht');
    }

    /** Ältere, unverschlüsselte Sicherungen (auch vor Updates) löscht die erste verschlüsselte; andere Dateien bleiben. */
    public function testOldPlainBackupsAreRemoved(): void
    {
        $this->connect();
        $this->api('PUT', '/admin/backup/drive', ['enabled' => true]);
        $this->app->backupUpload()->upload();
        $folder = $this->folders[0];
        $this->drive['alt1'] = ['name' => 'sicherung-2026-01-01-120000.json', 'parent' => $folder, 'content' => '{}'];
        $this->drive['alt2'] = ['name' => 'vor-update-2026-01-02-120000-1.0.0.json', 'parent' => $folder, 'content' => '{}'];
        $this->drive['fremd'] = ['name' => 'notizen.json', 'parent' => $folder, 'content' => '{}'];
        $this->clock->at = new \DateTimeImmutable('2026-10-28 12:00:00 UTC');
        $this->app->backupUpload()->upload();
        $names = array_column($this->drive, 'name');
        sort($names);
        $this->assertSame('notizen.json', $names[0], 'fremde Dateien bleiben');
        $this->assertCount(3, $names, 'zwei verschlüsselte und die fremde');
        foreach (array_slice($names, 1) as $n) {
            $this->assertStringEndsWith('.json.enc', $n);
        }
    }

    public function testEncryptedDownload(): void
    {
        $download = $this->app->backupDownload();
        $admin = $this->app->authContext->currentUser();
        $res = $download->forDelivery($admin, true);
        $this->assertSame(200, $res['status']);
        $this->assertSame('attachment; filename="sicherung-2026-10-20.json.enc"', $res['headers']['Content-Disposition']);
        $out = fopen('php://temp', 'w+b');
        ($res['write'])($out);
        rewind($out);
        $this->assertSame(Backup::FORMAT, json_decode(self::decrypt((string) stream_get_contents($out)), true)['format'] ?? null);
        $this->app->settings->delete(BackupCrypto::SETTING);
        $this->assertSame(409, $download->forDelivery($admin, true)['status'], 'ohne Passwort für Sicherungen');
    }

    public function testStateStaysWithTheInstallation(): void
    {
        $this->assertContains(BackupUploadService::STATE_SETTING, Backup::LOCAL_SETTINGS);
        $this->assertContains(BackupCrypto::SETTING, Backup::LOCAL_SETTINGS, 'ein älterer Stand bringt kein früheres Passwort zurück');
        $this->assertNotContains(BackupUploadService::SETTING, Backup::LOCAL_SETTINGS, 'Ein/aus und Ordner kommen mit der Sicherung zurück');
    }
}
