<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\DriveClient;
use KompanieApp\Core\Domain\GalleryDriveService;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Tests\Support\DriveMock;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * Galerie mit Google Drive als Ablage (Wunsch vom 17.11.2026): Einreichungen und hochgeladene Bilder gehen mit vollem
 * Drive-Zugriff nach Drive, offene Einreichungen in einen privaten Ordner, Freigabe verschiebt, Ablehnen legt in den
 * Papierkorb; ältere Verbindungen (nur lesen) bleiben beim Server.
 */
final class GalleryDriveTest extends TestCase
{
    private string $dir;
    private DriveMock $drive;
    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $sub;
    private int $album;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kompanie-gdrive-' . bin2hex(random_bytes(4));
        $this->drive = new DriveMock();
        $this->users = new FakeUsers();
        $this->app = TestApp::create(TestApp::connection(), $this->users, [], $this->drive->http(), $this->dir);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->sub = $this->users->add('einreicher', Roles::MEMBER);
        $this->app->roles()->setOverrides($this->users->find($this->admin), $this->sub, ['submit_gallery'], []);
        $this->connect(DriveClient::oauthScopes());
        $this->users->current = $this->admin;
        $this->ok('PATCH', '/admin/settings', ['gallery_source' => 'drive', 'gallery_root' => 'ROOT', 'gallery_submissions' => '1']);
        $this->app->gallery()->sync();
        $this->album = $this->albumId('A1');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function connect(string $scope): void
    {
        $this->app->drive()->saveOAuth(['client_id' => 'CID', 'client_secret' => 'SECRET', 'refresh_token' => 'REFRESH1', 'email' => 'kompanie@example.org', 'scope' => $scope]);
    }

    private function albumId(string $driveId): int
    {
        return (int) $this->app->db->scalar('SELECT id FROM {albums} WHERE drive_id = ?', [$driveId]);
    }

    private function call(string $method, string $path, array $body = []): Response
    {
        return $this->app->api()->handle(new Request($method, $path, [], $body));
    }

    /** @return array<string, mixed> */
    private function ok(string $method, string $path, array $body = []): array
    {
        $res = $this->call($method, $path, $body);
        $this->assertLessThan(300, $res->status, $method . ' ' . $path . ': ' . json_encode($res->data));
        return (array) $res->data;
    }

    private function file(string $name = 'Fest.jpg'): array
    {
        return ['name' => $name, 'data' => base64_encode(DriveMock::jpeg(40, 30))];
    }

    private function submit(string $name = 'Fest.jpg', ?int $album = null): array
    {
        $this->users->current = $this->sub;
        $s = $this->ok('POST', '/gallery/submissions', ['album_id' => $album ?? $this->album, 'file' => $this->file($name), 'consent' => true]);
        $this->users->current = $this->admin;
        return $s;
    }

    /** @return list<string> Dateien auf dem Server (offene Einreichungen und hochgeladene Bilder) */
    private function onServer(): array
    {
        return array_keys($this->app->gallery()->media()->keys());
    }

    /** @return list<string> Namen der Dateien in einem Ordner von Drive */
    private function names(string $folder): array
    {
        return array_column($this->drive->tree[$folder] ?? [], 'name');
    }

    private function submissionsFolder(): string
    {
        foreach ($this->drive->tree['MYDRIVE'] ?? [] as $f) {
            if (str_starts_with((string) $f['name'], 'Einreichungen ')) {
                return (string) $f['id'];
            }
        }
        $this->fail('Kein Ordner „Einreichungen“ in Drive');
    }

    public function testSubmissionGoesToPrivateFolderAndApprovalMovesIntoAlbum(): void
    {
        $this->assertSame('drive', $this->ok('GET', '/admin/settings')['gallery']['store']);
        $a = $this->submit('Erstes.jpg');
        $b = $this->submit('Zweites.jpg');
        $this->assertCount(2, $this->onServer(), 'angekommen wird wie bisher auf dem Server');
        $this->assertSame(2, $this->ok('GET', '/admin/settings')['gallery']['drive']['waiting']);

        $this->assertSame(2, $this->app->galleryDrive()->runTask());
        $folder = $this->submissionsFolder();
        $this->assertSame(['Erstes.jpg', 'Zweites.jpg'], $this->names($folder), 'privater Ordner außerhalb der Galerie');
        $this->assertSame([], $this->onServer(), 'Kopie auf dem Server gelöscht');
        $this->assertSame(['active' => true, 'needs_reconnect' => false, 'waiting' => 0, 'error' => ''], $this->ok('GET', '/admin/settings')['gallery']['drive']);
        $this->assertSame(0, $this->app->galleryDrive()->runTask(), 'nichts mehr zu tun');

        // Prüfende sehen die Vorschau weiter (aus der Tabelle)
        $review = $this->ok('GET', '/gallery/review');
        $this->assertStringStartsWith('data:image/jpeg;base64,', (string) $review['pending'][0]['thumb']);

        // Freigeben (intern): in den Ordner „Intern“ des Albums, als Bild aus Drive
        $this->ok('POST', "/gallery/review/{$a['id']}/approve", ['intern' => true]);
        $this->assertSame(['Zweites.jpg'], $this->names($folder));
        $this->assertContains('Erstes.jpg', $this->names('I1'));
        $photo = $this->app->db->selectOne("SELECT * FROM {photos} WHERE name = 'Erstes.jpg'");
        $this->assertNotNull($photo);
        $this->assertSame(['drive', 1], [(string) $photo['source'], (int) $photo['is_intern']]);
        $this->app->gallery()->processPending(30);
        $this->assertCount(1, array_filter($this->app->gallery()->album($this->album, true)['photos'], static fn (array $p): bool => $p['id'] === (int) $photo['id']));

        // Der Abgleich findet dieselbe Datei: dasselbe Bild, nichts doppelt
        $this->app->gallery()->sync();
        $this->assertSame(1, (int) $this->app->db->scalar("SELECT COUNT(*) FROM {photos} WHERE name = 'Erstes.jpg'"));
        $this->assertSame((int) $photo['id'], (int) $this->app->db->scalar("SELECT id FROM {photos} WHERE name = 'Erstes.jpg'"));

        // Ablehnen: in den Papierkorb von Drive
        $fileB = (string) $this->app->db->scalar('SELECT drive_file_id FROM {gallery_submissions} WHERE id = ?', [$b['id']]);
        $this->ok('POST', "/gallery/review/{$b['id']}/reject", ['reason' => 'Unscharf']);
        $this->assertSame([], $this->names($folder));
        $this->assertContains($fileB, $this->drive->trashed);

        // Wieder entfernen: Bild aus Drive in den Papierkorb, sonst käme es beim Abgleich zurück
        $this->ok('POST', "/gallery/review/{$a['id']}/remove");
        $this->assertContains((string) $photo['drive_file_id'], $this->drive->trashed);
        $this->app->gallery()->sync();
        $this->assertSame(0, (int) $this->app->db->scalar("SELECT COUNT(*) FROM {photos} WHERE name = 'Erstes.jpg'"));
    }

    public function testWithdrawAndPublicApprovalIntoAlbumWithoutInternFolder(): void
    {
        $a = $this->submit('Eins.jpg', $this->albumId('A3'));
        $b = $this->submit('Zwei.jpg', $this->albumId('A3'));
        $this->app->galleryDrive()->runTask();
        $fileA = (string) $this->app->db->scalar('SELECT drive_file_id FROM {gallery_submissions} WHERE id = ?', [$a['id']]);
        $this->users->current = $this->sub;
        $this->ok('DELETE', "/gallery/submissions/{$a['id']}");
        $this->assertContains($fileA, $this->drive->trashed, 'Zurückziehen: Papierkorb');

        $this->users->current = $this->admin;
        $this->ok('POST', "/gallery/review/{$b['id']}/approve", ['intern' => true]);
        $intern = array_values(array_filter($this->drive->tree['A3'], static fn (array $f): bool => $f['name'] === 'intern'));
        $this->assertCount(1, $intern, 'Ordner „intern“ angelegt');
        $this->assertSame(['Zwei.jpg'], $this->names((string) $intern[0]['id']));
        $this->app->gallery()->sync();
        $this->assertSame(1, (int) $this->app->db->scalar("SELECT is_intern FROM {photos} WHERE name = 'Zwei.jpg'"), 'Abgleich erkennt den neuen Ordner');
    }

    public function testUploadIntoDriveAlbumMovesThereAfterProcessing(): void
    {
        $a2 = $this->albumId('A2');
        $this->ok('POST', "/admin/gallery/albums/$a2/photos", ['file' => $this->file('Neu.jpg'), 'intern' => false]);
        $this->assertSame(0, $this->app->galleryDrive()->runTask(), 'erst aufbereiten (Größen vom Server)');
        $this->app->gallery()->processPending(30);
        $before = $this->app->db->selectOne("SELECT * FROM {photos} WHERE name = 'Neu.jpg'");
        $this->assertSame('ready', (string) $before['status']);

        $this->assertSame(1, $this->app->galleryDrive()->runTask());
        $this->assertSame(['intern', 'Neu.jpg'], $this->names('A2'));
        $after = $this->app->db->selectOne("SELECT * FROM {photos} WHERE name = 'Neu.jpg'");
        $this->assertSame(['drive', 'ready', (string) $before['file_key']], [(string) $after['source'], (string) $after['status'], (string) $after['file_key']], 'nichts neu berechnet');
        $this->assertSame([], $this->onServer());

        $downloads = $this->drive->downloads;
        $this->app->gallery()->sync();
        $this->app->gallery()->processPending(30);
        $kept = $this->app->db->selectOne("SELECT * FROM {photos} WHERE name = 'Neu.jpg'");
        $this->assertSame([(int) $before['id'], 'ready'], [(int) $kept['id'], (string) $kept['status']], 'Abgleich behält das Bild');
        $this->assertSame($downloads, $this->drive->downloads, 'kein erneuter Download');
    }

    public function testSyncRunningMeanwhileKeepsPushedPhoto(): void
    {
        $a2 = $this->albumId('A2');
        $this->ok('POST', "/admin/gallery/albums/$a2/photos", ['file' => $this->file('Neu.jpg'), 'intern' => false]);
        $this->app->gallery()->processPending(30);
        // Ein Abgleich hat Drive vor dem Umzug gelesen und räumt danach auf: Der Merker schützt das Bild.
        $listedBefore = $this->drive->tree;
        $this->app->galleryDrive()->runTask();
        $id = (int) $this->app->db->scalar("SELECT id FROM {photos} WHERE name = 'Neu.jpg'");
        $now = $this->drive->tree;
        $this->drive->tree = $listedBefore;
        $this->app->gallery()->sync();
        $this->drive->tree = $now;
        $this->assertSame($id, (int) $this->app->db->scalar("SELECT id FROM {photos} WHERE name = 'Neu.jpg'"));
    }

    public function testLargeFileContinuesInNextRun(): void
    {
        // Hochladen in Stücken, über die Zeitgrenze hinweg fortgesetzt
        $drive = $this->app->drive();
        $path = $this->dir . '/gross.bin';
        file_put_contents($path, random_bytes(5000));
        $location = $drive->beginUpload('A1', 'gross.mp4', 'video/mp4', 5000);
        $this->assertSame(['id' => null, 'offset' => 0], $drive->continueUpload($location, $path, 'video/mp4', 0.0, 2048), 'Zeit schon um: nur Stand abgefragt');
        $deadline = hrtime(true) / 1e9 + 30;
        $done = $drive->continueUpload($location, $path, 'video/mp4', $deadline, 2048);
        $this->assertNotNull($done['id']);
        $this->assertSame(file_get_contents($path), $this->drive->contents[$done['id']]);
        $this->assertSame($done, $drive->continueUpload($location, $path, 'video/mp4', $deadline, 2048), 'schon fertig: dieselbe Datei');

        // Die Aufgabe setzt eine gespeicherte Sitzung fort, statt neu zu beginnen
        $s = $this->submit();
        $key = (string) $this->app->db->scalar('SELECT file_key FROM {gallery_submissions} WHERE id = ?', [$s['id']]);
        $saved = $drive->beginUpload($this->app->galleryDrive()->submissionsFolder(), 'Fest.jpg', 'image/jpeg', (int) filesize((string) $this->app->gallery()->media()->path($key)));
        $this->app->settings->set(GalleryDriveService::UPLOAD_SETTING, ['kind' => 'submission', 'id' => (int) $s['id'], 'location' => $saved]);
        $sessions = count($this->drive->sessions);
        $this->assertSame(1, $this->app->galleryDrive()->runTask());
        $this->assertCount($sessions, $this->drive->sessions, 'dieselbe Sitzung fortgesetzt');
        $this->assertNull($this->app->settings->get(GalleryDriveService::UPLOAD_SETTING));
        $this->assertSame(['Fest.jpg'], $this->names($this->submissionsFolder()));

        // Abgelaufene Sitzung: neu beginnen
        $t = $this->submit('Zwei.jpg');
        $this->app->settings->set(GalleryDriveService::UPLOAD_SETTING, ['kind' => 'submission', 'id' => (int) $t['id'], 'location' => DriveClient::UPLOAD_URL . '?uploadType=resumable&upload_id=999']);
        $this->assertSame(1, $this->app->galleryDrive()->runTask());
        $this->assertSame(['Fest.jpg', 'Zwei.jpg'], $this->names($this->submissionsFolder()));
    }

    public function testReadOnlyConnectionStaysOnServer(): void
    {
        $this->connect(DriveClient::SCOPE . ' ' . DriveClient::CALENDAR_SCOPE . ' ' . DriveClient::BACKUP_SCOPE);
        $settings = $this->ok('GET', '/admin/settings');
        $this->assertSame('server', $settings['gallery']['store']);
        $this->assertFalse($settings['google']['write_access']);
        $this->assertTrue($settings['gallery']['drive']['needs_reconnect']);
        $this->assertTrue($this->app->drive()->backupAccess(), 'Sicherung geht weiter');
        $a = $this->submit();
        $this->assertSame(0, $this->app->galleryDrive()->runTask());
        $this->assertSame([], $this->drive->writes);
        $this->ok('POST', "/gallery/review/{$a['id']}/approve", ['intern' => false]);
        $this->assertSame('upload', (string) $this->app->db->scalar("SELECT source FROM {photos} WHERE name = 'Fest.jpg'"), 'wie bisher auf dem Server');
    }

    public function testWriteErrorIsShownAndFileStaysOnServer(): void
    {
        $this->submit();
        $this->drive->writeStatus = 403;
        $this->assertSame(0, $this->app->galleryDrive()->runTask());
        $status = $this->ok('GET', '/admin/settings')['gallery']['drive'];
        $this->assertStringContainsString('sufficient permissions', $status['error']);
        $this->assertSame(1, $status['waiting']);
        $this->assertCount(1, $this->onServer());
        $this->drive->writeStatus = 0;
        $this->assertSame(1, $this->app->galleryDrive()->runTask());
        $this->assertSame('', $this->ok('GET', '/admin/settings')['gallery']['drive']['error']);
    }

    public function testSubmissionIntoAppAlbumComesBackToServer(): void
    {
        // Album, das vor der Ablage in Drive nur in der App angelegt wurde
        $app = $this->app->db->insert('albums', ['drive_id' => 'app:alt', 'year' => '2026', 'title' => 'Nur App', 'sort_date' => '2026-01-01 00:00:00', 'synced_at' => '2026-01-01 00:00:00', 'source' => 'app']);
        $s = $this->submit('App.jpg', $app);
        $this->app->galleryDrive()->runTask();
        $fileId = (string) $this->app->db->scalar('SELECT drive_file_id FROM {gallery_submissions} WHERE id = ?', [$s['id']]);
        $this->assertNotSame('', $fileId);
        $this->ok('POST', "/gallery/review/{$s['id']}/approve", ['intern' => false]);
        $this->assertSame('upload', (string) $this->app->db->scalar("SELECT source FROM {photos} WHERE name = 'App.jpg'"));
        $this->assertCount(1, $this->onServer());
        $this->assertContains($fileId, $this->drive->trashed);
        $this->assertSame(0, $this->app->galleryDrive()->runTask(), 'Alben der App bleiben auf dem Server');
    }

    private function photoId(string $driveId): int
    {
        return (int) $this->app->db->scalar('SELECT id FROM {photos} WHERE drive_file_id = ?', [$driveId]);
    }

    public function testDrivePhotosHaveTheSameControlsAsUploads(): void
    {
        $this->app->gallery()->processPending(60);
        $album = $this->app->gallery()->album($this->album, true);
        $this->assertTrue($album['editable']);
        $this->assertSame([true], array_values(array_unique(array_column($album['photos'], 'editable'))));

        // Intern stellen: in den Ordner „Intern“ des Albums und zurück
        $img1 = $this->photoId('IMG1');
        $this->ok('PATCH', "/gallery/photos/$img1", ['intern' => true]);
        $this->assertContains('a.jpg', $this->names('I1'));
        $this->assertNotContains('a.jpg', $this->names('A1'));
        $img3 = $this->photoId('IMG3');
        $this->ok('PATCH', "/gallery/photos/$img3", ['intern' => false]);
        $this->assertContains('c.jpg', $this->names('A1'));
        $this->app->gallery()->sync();
        $this->assertSame([1, 0], [(int) $this->app->db->scalar('SELECT is_intern FROM {photos} WHERE id = ?', [$img1]), (int) $this->app->db->scalar('SELECT is_intern FROM {photos} WHERE id = ?', [$img3])]);

        // Verschieben in ein anderes Album aus Drive (intern bleibt intern)
        $a3 = $this->albumId('A3');
        $this->ok('POST', '/gallery/photos/move', ['ids' => [$img1, $this->photoId('IMG2')], 'album_id' => $a3]);
        $this->assertSame(['e.png', 'intern', 'b.jpg'], $this->names('A3'), 'Ordner intern für a.jpg angelegt');
        $this->assertSame(['a.jpg'], $this->names((string) $this->drive->tree['A3'][1]['id']));
        $this->app->gallery()->sync();
        $this->assertSame($a3, (int) $this->app->db->scalar('SELECT album_id FROM {photos} WHERE id = ?', [$img1]));
        $this->assertSame(1, (int) $this->app->db->scalar('SELECT is_intern FROM {photos} WHERE id = ?', [$img1]));

        // Löschen: Papierkorb von Drive, der Abgleich bringt es nicht zurück
        $img5 = $this->photoId('IMG5');
        $this->ok('POST', '/gallery/photos/delete', ['ids' => [$img5]]);
        $this->assertContains('IMG5', $this->drive->trashed);
        $this->app->gallery()->sync();
        $this->assertSame(0, $this->photoId('IMG5'));
    }

    public function testAlbumsAreFoldersInDrive(): void
    {
        // Neues Album: Ordner im Jahresordner, auch leer nach dem Abgleich da
        $new = $this->ok('POST', '/admin/gallery/albums', ['title' => 'Kirmes', 'year' => 2026]);
        $this->assertSame(['drive', true], [$new['source'], $new['editable']]);
        $this->assertContains('Kirmes', $this->names('Y2026'));
        $this->app->gallery()->sync();
        $this->assertNotNull($this->app->db->selectOne('SELECT id FROM {albums} WHERE id = ?', [$new['id']]), 'leeres Album bleibt');

        // Neues Jahr: Ordner angelegt
        $old = $this->ok('POST', '/admin/gallery/albums', ['title' => 'Alt', 'year' => 2019]);
        $this->assertContains('2019', $this->names('ROOT'));

        // Umbenennen und ins andere Jahr
        $this->ok('PATCH', "/admin/gallery/albums/{$new['id']}", ['title' => 'Kirmes 2025']);
        $this->assertContains('Kirmes 2025', $this->names('Y2026'));
        $this->ok('PATCH', "/admin/gallery/albums/{$new['id']}", ['year' => 2025]);
        $this->assertContains('Kirmes 2025', $this->names('Y2025'));
        $this->assertNotContains('Kirmes 2025', $this->names('Y2026'));
        $this->app->gallery()->sync();
        $this->assertSame(['2025', 'Kirmes 2025'], array_values((array) $this->app->db->selectOne('SELECT year, title FROM {albums} WHERE id = ?', [$new['id']])));

        // Album aus Drive mit Bildern löschen: Ordner in den Papierkorb
        $a2 = $this->albumId('A2');
        $this->ok('DELETE', "/admin/gallery/albums/$a2");
        $this->assertContains('A2', $this->drive->trashed);
        $this->app->gallery()->sync();
        $this->assertSame(0, $this->albumId('A2'));
        $this->assertSame(0, $this->photoId('IMG4'));
        $this->ok('DELETE', "/admin/gallery/albums/{$old['id']}");
    }

    public function testDrivePhotosStayReadOnlyWithoutWriteAccess(): void
    {
        $this->connect(DriveClient::SCOPE . ' ' . DriveClient::CALENDAR_SCOPE);
        $this->app->gallery()->processPending(60);
        $album = $this->app->gallery()->album($this->album, true);
        $this->assertFalse($album['editable']);
        $this->assertSame(400, $this->call('PATCH', '/gallery/photos/' . $this->photoId('IMG1'), ['intern' => true])->status);
        $this->assertSame(400, $this->call('POST', '/gallery/photos/delete', ['ids' => [$this->photoId('IMG1')]])->status);
        $this->assertSame(400, $this->call('PATCH', "/admin/gallery/albums/{$this->album}", ['title' => 'Neu'])->status);
        $this->assertSame('app', $this->ok('POST', '/admin/gallery/albums', ['title' => 'Nur App', 'year' => 2026])['source']);
        $this->assertSame([], $this->drive->writes);
    }
}
