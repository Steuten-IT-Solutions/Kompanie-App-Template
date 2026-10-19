<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\GalleryService;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Storage\GalleryRepository;
use KompanieApp\Core\Storage\Sql\PdoConnection;
use KompanieApp\Core\Tests\Support\CountingPdo;
use KompanieApp\Core\Tests\Support\DriveMock;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** Galerie mit simuliertem Google Drive. */
final class GalleryTest extends TestCase
{
    private string $dir;
    private DriveMock $drive;
    private FakeUsers $users;
    private App $app;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kompanie-gallery-' . bin2hex(random_bytes(4));
        $this->drive = new DriveMock();
        $this->users = new FakeUsers();
        $this->app = TestApp::create(TestApp::connection(), $this->users, [], $this->drive->http(), $this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** @return array<string, array<string, mixed>> Alben nach Titel */
    private function titles(bool $member): array
    {
        $out = [];
        foreach ($this->app->gallery()->overview($member) as $y) {
            foreach ($y['albums'] as $a) {
                $out[$a['title']] = $a;
            }
        }
        return $out;
    }

    public function testServiceAccountAndFolder(): void
    {
        $drive = $this->app->drive();
        try {
            $drive->saveServiceAccount('{"foo":1}');
            $this->fail('Ungültiges JSON wurde angenommen');
        } catch (\RuntimeException) {
        }
        $drive->saveServiceAccount($this->drive->serviceAccountJson());
        $this->assertSame('galerie@test.iam.gserviceaccount.com', $drive->clientEmail());
        $this->assertSame('abc_DEF-123', \KompanieApp\Core\Domain\DriveClient::parseFolderId('https://drive.google.com/drive/folders/abc_DEF-123?usp=sharing'));
        $this->assertSame('', \KompanieApp\Core\Domain\DriveClient::parseFolderId('kein link!'));
        $this->assertFalse($this->app->gallery()->isConfigured());
        $this->app->gallery()->setRoot('https://drive.google.com/drive/folders/ROOT');
        $this->assertTrue($this->app->gallery()->isConfigured());
    }

    public function testSyncVisibilityProcessingAndChanges(): void
    {
        $gallery = $this->app->gallery();
        $this->app->drive()->saveServiceAccount($this->drive->serviceAccountJson());
        $gallery->setRoot('ROOT');

        $stats = $gallery->sync();
        $this->assertTrue($this->drive->jwtValid, 'Token per signiertem JWT geholt');
        $this->assertSame(1, $this->drive->tokens);
        $this->assertTrue($this->drive->paged, 'Paging von files.list wird verfolgt');
        $this->assertSame(['albums' => 3, 'photos' => 5, 'new' => 5, 'pending' => 5], $stats, 'Lose Datei und Video ignoriert');

        $guest = $this->titles(false);
        $member = $this->titles(true);
        $this->assertArrayHasKey('Sommerfest', $guest);
        $this->assertArrayNotHasKey('Vereinsabend', $guest, 'Gäste sehen keine rein internen Alben');
        $this->assertSame(2, $guest['Sommerfest']['count']);
        $this->assertSame(3, $member['Sommerfest']['count']);
        $this->assertSame(1, $member['Sommerfest']['intern_count']);
        $this->assertArrayHasKey('Vereinsabend', $member);
        $years = $gallery->overview(false);
        $this->assertSame(['2026', '2025'], array_column($years, 'year'));

        $id = $guest['Sommerfest']['id'];
        $this->assertCount(2, $gallery->album($id, false)['photos']);
        $this->assertCount(3, $gallery->album($id, true)['photos']);
        $this->assertSame(['a.jpg', 'b.jpg'], array_slice(array_column($gallery->album($id, false)['photos'], 'name'), 0, 2), 'nach Aufnahmezeit sortiert');
        $this->assertNull($gallery->album($member['Vereinsabend']['id'], false));

        $this->assertSame(5, $gallery->processPending(60));
        $this->assertSame(0, $gallery->countPending());
        $this->assertSame(5, $this->drive->downloads);
        $album = $gallery->album($id, true);
        $public = $album['photos'][0];
        $intern = array_values(array_filter($album['photos'], static fn ($p) => $p['intern']))[0];
        $this->assertStringStartsWith('https://example.org/media/', $public['thumb'], 'Öffentliche Bilder als statische Datei');
        $this->assertStringStartsWith('https://example.org/app/foto/', $intern['thumb'], 'Interne Bilder nur über die App');
        $this->assertFileExists($this->dir . '/private/.htaccess');

        // Größen: 400er-Vorschau, Großansicht nicht hochskaliert.
        $local = fn (string $url): string => str_replace('https://example.org/media/', $this->dir . '/public/', $url);
        $thumb = getimagesize($local($public['thumb']));
        $large = getimagesize($local($public['large']));
        $this->assertSame(400, max($thumb[0], $thumb[1]));
        $this->assertSame(1200, $large[0]);
        $this->assertSame('image/jpeg', $thumb['mime']);

        // Auslieferung interner Bilder
        $this->assertSame(401, $gallery->photoForDelivery($intern['id'], 'thumb', false, false)['status']);
        $this->assertSame(403, $gallery->photoForDelivery($intern['id'], 'thumb', false, true)['status']);
        $ok = $gallery->photoForDelivery($intern['id'], 'thumb', true, true);
        $this->assertSame(200, $ok['status']);
        $this->assertSame("\xFF\xD8", substr((string) file_get_contents($ok['file']), 0, 2));
        $this->assertSame(404, $gallery->photoForDelivery(99999, 'thumb', true, true)['status']);
        $this->assertSame(404, $gallery->photoForDelivery($intern['id'], 'riesig', true, true)['status']);
        // Fehlgeschlagene Bilder lösen beim Abruf keinen neuen Download aus.
        $this->app->db->execute("UPDATE {photos} SET status = 'error' WHERE id = ?", [$intern['id']]);
        $downloads = $this->drive->downloads;
        $this->assertSame(404, $gallery->photoForDelivery($intern['id'], 'large', true, true)['status']);
        $this->assertSame($downloads, $this->drive->downloads);
        // Der nächste Abgleich versucht es erneut, höchstens fünfmal.
        $gallery->sync();
        $this->assertSame('pending', $this->app->db->scalar('SELECT status FROM {photos} WHERE id = ?', [$intern['id']]));
        $this->app->db->execute("UPDATE {photos} SET status = 'error', attempts = 5 WHERE id = ?", [$intern['id']]);
        $gallery->sync();
        $this->assertSame('error', $this->app->db->scalar('SELECT status FROM {photos} WHERE id = ?', [$intern['id']]));
        $this->app->db->execute("UPDATE {photos} SET status = 'ready', attempts = 0 WHERE id = ?", [$intern['id']]);

        // API: Gäste und Mitglieder
        $this->users->current = null;
        $res = $this->app->api()->handle(new Request('GET', '/gallery'));
        $this->assertSame(200, $res->status);
        $this->assertNotContains('Vereinsabend', array_column($res->data['years'][0]['albums'], 'title'));
        $this->users->current = $this->users->add('mitglied', Roles::MEMBER);
        $res = $this->app->api()->handle(new Request('GET', '/gallery/' . $member['Vereinsabend']['id']));
        $this->assertSame(200, $res->status);

        // Änderungen in Drive
        $this->drive->tree['A1'][1]['md5Checksum'] = 'm2-neu';
        unset($this->drive->tree['A3'][0]);
        $gone = $this->titles(true)['Maifest'];
        $stats = $gallery->sync();
        $this->assertSame(2, $stats['albums']);
        $this->assertSame(4, $stats['photos']);
        $this->assertArrayNotHasKey('Maifest', $this->titles(true), 'Leeres Album entfernt');
        $this->assertFileDoesNotExist($local((string) $gone['cover']));
        $this->assertSame(1, $gallery->countPending(), 'Geändertes Bild wird neu aufbereitet');
        $this->assertSame(1, $this->drive->tokens, 'Zugriffstoken wird zwischengespeichert');
    }

    public function testSyncErrorIsStoredAndTaskSchedules(): void
    {
        $gallery = $this->app->gallery();
        $this->app->drive()->saveServiceAccount($this->drive->serviceAccountJson());
        $gallery->setRoot('ROOT');
        $this->app->scheduleTasks();
        $this->assertArrayHasKey(GalleryService::TASK, $this->app->scheduler->scheduled);

        $this->drive->tree['ROOT'] = [];
        $broken = new \KompanieApp\Core\Tests\Support\FakeHttpClient(static fn () => ['status' => 500, 'body' => '{"error":{"message":"kaputt"}}']);
        $app = TestApp::create(TestApp::connection(), new FakeUsers(), [], $broken, $this->dir);
        $app->drive()->saveServiceAccount($this->drive->serviceAccountJson());
        $app->gallery()->setRoot('ROOT');
        try {
            $app->gallery()->sync();
            $this->fail('Fehler erwartet');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('kaputt', $e->getMessage());
        }
        $this->assertStringContainsString('kaputt', $app->gallery()->status()['error']);
        $app->runTask(GalleryService::TASK); // darf trotz Fehler nicht abbrechen
        $this->assertTrue(true);
    }

    public function testSyncWithoutChangesWritesNothing(): void
    {
        $pdo = new CountingPdo('sqlite::memory:');
        $app = TestApp::create(new PdoConnection($pdo, 'kompanie_'), $this->users, [], $this->drive->http(), $this->dir);
        $app->drive()->saveServiceAccount($this->drive->serviceAccountJson());
        $gallery = $app->gallery();
        $gallery->setRoot('ROOT');
        $gallery->sync();
        $gallery->processPending(60);

        $pdo->log = [];
        $stats = $gallery->sync();
        $this->assertSame(5, $stats['photos']);
        $this->assertSame([], $pdo->writes(), 'Abgleich ohne Änderungen schreibt nichts in die Datenbank');
        $this->assertSame(3, count(preg_grep('/FROM kompanie_photos WHERE drive_file_id IN/', $pdo->log)), 'eine Abfrage je Album für die vorhandenen Bilder');

        // Eine Änderung: genau ein UPDATE für dieses Bild.
        $this->drive->tree['A1'][0]['name'] = 'umbenannt.jpg';
        $pdo->log = [];
        $gallery->sync();
        $updates = preg_grep('/^UPDATE kompanie_photos/', $pdo->writes());
        $this->assertCount(1, $updates);
        $this->assertSame([], preg_grep('/^UPDATE kompanie_albums/', $pdo->writes()), 'Album unverändert');
        $this->assertSame(0, $gallery->countPending(), 'Umbenennen allein erzeugt die Bilder nicht neu');
    }

    public function testPhotoNotReadyIsPlaceholderAndProcessedInBackground(): void
    {
        $gallery = $this->app->gallery();
        $this->app->drive()->saveServiceAccount($this->drive->serviceAccountJson());
        $gallery->setRoot('ROOT');
        $this->app->scheduleTasks();
        $this->assertArrayHasKey(GalleryService::PROCESS_TASK, $this->app->scheduler->scheduled);
        $gallery->sync();
        $id = (int) $this->app->db->scalar("SELECT id FROM {photos} WHERE is_intern = 0 ORDER BY id LIMIT 1");

        $res = $gallery->photoForDelivery($id, 'thumb', false, false);
        $this->assertSame(202, $res['status'], 'noch nicht aufbereitet');
        $this->assertStringStartsWith('<svg', $res['placeholder']);
        $this->assertSame(GalleryService::RETRY_AFTER, $res['retryAfter']);
        $this->assertSame(0, $this->drive->downloads, 'kein Download während der Anfrage');
        $this->assertSame([GalleryService::PROCESS_TASK], $this->app->scheduler->soon, 'Aufbereitung im Hintergrund angestoßen');
        $gallery->photoForDelivery($id, 'large', false, false);
        $this->assertCount(1, $this->app->scheduler->soon, 'höchstens einmal pro Minute angestoßen');

        // Läuft gerade eine Aufbereitung (Sperre), bereitet ein zweiter Prozess nichts auf.
        $lock = fopen($this->app->files->path('.aufbereitung.lock', false), 'c');
        $this->assertTrue(flock($lock, LOCK_EX));
        $this->assertSame(0, $gallery->processPending(60));
        flock($lock, LOCK_UN);
        fclose($lock);

        $this->app->scheduler->soon = [];
        $this->app->runTask(GalleryService::PROCESS_TASK);
        $this->assertSame(0, $gallery->countPending());
        $this->assertSame([], $this->app->scheduler->soon, 'nichts mehr ausstehend: kein weiterer Lauf');
        $this->assertSame(200, $gallery->photoForDelivery($id, 'thumb', false, false)['status']);

        // Fertig, aber die Datei fehlt: Platzhalter und neu aufbereiten lassen (nicht in der Anfrage).
        $photo = $this->app->db->selectOne('SELECT * FROM {photos} WHERE id = ?', [$id]);
        unlink($gallery->path($photo, 'thumb'));
        $downloads = $this->drive->downloads;
        $this->assertSame(202, $gallery->photoForDelivery($id, 'thumb', false, false)['status']);
        $this->assertSame('pending', $this->app->db->scalar('SELECT status FROM {photos} WHERE id = ?', [$id]));
        $this->assertSame($downloads, $this->drive->downloads);
    }

    public function testBackgroundTaskContinuesWhilePhotosArePending(): void
    {
        $gallery = $this->app->gallery();
        $this->app->drive()->saveServiceAccount($this->drive->serviceAccountJson());
        $gallery->setRoot('ROOT');
        $this->app->scheduleTasks();
        $this->drive->tree['ROOT'] = [];
        $this->app->runTask(GalleryService::TASK);
        $this->assertSame([], $this->app->scheduler->soon, 'nichts ausstehend');

        $this->drive = new DriveMock();
        $app = TestApp::create(TestApp::connection(), $this->users, [], $this->drive->http(), $this->dir);
        $app->drive()->saveServiceAccount($this->drive->serviceAccountJson());
        $app->gallery()->setRoot('ROOT');
        $app->scheduleTasks();
        $app->gallery()->sync();
        $app->gallery()->runProcessTask(0); // Zeitbudget aufgebraucht, Bilder bleiben ausstehend
        $this->assertSame([GalleryService::PROCESS_TASK], $app->scheduler->soon, 'bei ausstehenden Bildern bald wieder');
    }

    public function testCrashedPhotoDoesNotBlockOthers(): void
    {
        $gallery = $this->app->gallery();
        $this->app->drive()->saveServiceAccount($this->drive->serviceAccountJson());
        $gallery->setRoot('ROOT');
        $gallery->sync();
        $db = $this->app->db;
        $repo = (new \ReflectionProperty($gallery, 'repo'))->getValue($gallery);
        $first = (int) $repo->nextPending()['id'];
        // So sieht es aus, wenn PHP beim Aufbereiten abstürzt (Speicher, Zeitlimit): Versuch vermerkt, Bild noch ausstehend.
        $db->update('photos', ['attempts' => 1], ['id' => $first]);
        $this->assertNotSame($first, (int) $repo->nextPending()['id'], 'ein abgestürztes Bild kommt nach den anderen dran');

        // Stürzt es immer wieder ab, gilt es nach MAX_ATTEMPTS als fehlgeschlagen; die anderen werden fertig.
        $db->update('photos', ['attempts' => GalleryService::MAX_ATTEMPTS], ['id' => $first]);
        $gallery->processPending(60);
        $this->assertSame(GalleryRepository::FAILED, $db->scalar('SELECT status FROM {photos} WHERE id = ?', [$first]));
        $this->assertSame(0, $gallery->countPending());
        $this->assertSame(0, (int) $db->scalar("SELECT COUNT(*) FROM {photos} WHERE status = 'ready' AND attempts <> 0"), 'fertige Bilder ohne offene Versuche');
    }

    public function testSameDriveFileTwiceInAlbumIsSyncedOnce(): void
    {
        $gallery = $this->app->gallery();
        $this->app->drive()->saveServiceAccount($this->drive->serviceAccountJson());
        $gallery->setRoot('ROOT');
        $before = $gallery->sync()['photos'];
        $this->drive->tree['A1'][] = $this->drive->tree['A1'][0];
        $stats = $gallery->sync();
        $this->assertSame($before, $stats['photos'], 'doppelte Datei nur einmal');
    }
}
