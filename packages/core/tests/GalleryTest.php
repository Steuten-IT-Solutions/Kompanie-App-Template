<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\GalleryService;
use KompanieApp\Core\Http\FileCache;
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

    public function testExistingOAuthConnectionIsUsedBeforeServiceAccount(): void
    {
        $drive = $this->app->drive();
        $drive->saveServiceAccount($this->drive->serviceAccountJson());
        $drive->saveOAuth(['client_id' => 'CID', 'client_secret' => 'SECRET', 'refresh_token' => 'REFRESH1', 'email' => 'galerie@example.org']);
        $this->assertSame('galerie@example.org', $drive->oauthEmail());
        $this->app->gallery()->setRoot('ROOT');
        $this->assertSame(5, $this->app->gallery()->sync()['photos']);
        $this->assertSame(['grant_type' => 'refresh_token', 'refresh_token' => 'REFRESH1', 'client_id' => 'CID', 'client_secret' => 'SECRET'], $this->drive->refreshForm);
        $this->assertFalse($this->drive->jwtValid, 'Dienstkonto nicht genutzt');

        // Widerrufen: verständliche Meldung; nach dem Trennen übernimmt das Dienstkonto (eigener Zwischenspeicher).
        $this->drive->validRefreshToken = 'anderes';
        $drive->saveOAuth(['client_id' => 'CID', 'client_secret' => 'SECRET', 'refresh_token' => 'REFRESH2']);
        try {
            $drive->accessToken();
            $this->fail('Widerrufene Verbindung wurde angenommen');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('abgelaufen oder wurde widerrufen', $e->getMessage());
        }
        $settings = $this->app->settingsService()->update(['drive_oauth' => '']);
        $this->assertFalse($settings['gallery']['oauth_connected']);
        $this->assertSame('TOKEN123', $drive->accessToken());
        $this->assertTrue($this->drive->jwtValid);
        $this->assertTrue($this->app->gallery()->isConfigured());

        $drive->removeServiceAccount();
        $this->assertFalse($this->app->gallery()->isConfigured());
        try {
            $drive->saveOAuth(['client_id' => 'CID', 'refresh_token' => 'R']);
            $this->fail('Ohne client_secret angenommen');
        } catch (\RuntimeException) {
        }
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
        // ETag aus Schlüssel, Größe und Änderungszeit: gleich bei gleicher Datei, anders je Größe und nach Änderung.
        $etag = (string) ($ok['etag'] ?? '');
        $this->assertMatchesRegularExpression('/^"[a-f0-9]{32}"$/', $etag);
        $this->assertSame($etag, $gallery->photoForDelivery($intern['id'], 'thumb', true, true)['etag'] ?? null);
        $this->assertNotSame($etag, $gallery->photoForDelivery($intern['id'], 'large', true, true)['etag'] ?? null);
        $this->assertTrue(FileCache::matches($etag, $etag));
        $this->assertTrue(FileCache::matches($etag, 'W/' . $etag . ', "anders"'), 'Liste und schwacher Vergleich');
        $this->assertTrue(FileCache::matches($etag, '*'));
        $this->assertFalse(FileCache::matches($etag, '"anders"'));
        $this->assertFalse(FileCache::matches($etag, null));
        $this->assertFalse(FileCache::matches(null, $etag));
        touch((string) $ok['file'], time() + 5);
        clearstatcache();
        $this->assertNotSame($etag, $gallery->photoForDelivery($intern['id'], 'thumb', true, true)['etag'] ?? null, 'neue Datei, neuer ETag');
        // Wer anfragt, wird nur bei internen Bildern ermittelt (öffentliche brauchen weder Sitzung noch Konto).
        $asked = 0;
        $access = static function () use (&$asked): array {
            $asked++;
            return [false, false];
        };
        $publicPhoto = $this->app->db->selectOne('SELECT id FROM {photos} WHERE is_intern = 0 ORDER BY id LIMIT 1');
        $this->assertNotNull($publicPhoto);
        $this->assertSame(200, $gallery->deliverPhoto((int) $publicPhoto['id'], 'thumb', $access)['status']);
        $this->assertSame(0, $asked, 'öffentliches Bild: niemand ermittelt');
        $this->assertSame(401, $gallery->deliverPhoto($intern['id'], 'thumb', $access)['status']);
        $this->assertSame(1, $asked);
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

        // Liste für Admins (Einstellungen → Galerie): Datei, Album, Fehlermeldung, Link zu Drive; „Erneut versuchen“
        // reiht auch nach den fünf Versuchen wieder ein.
        $this->app->db->execute("UPDATE {photos} SET error = 'Datei ist kein unterstütztes Bild' WHERE id = ?", [$intern['id']]);
        $this->users->current = $this->users->add('mitglied-liste', Roles::MEMBER);
        $this->assertSame(403, $this->app->api()->handle(new Request('GET', '/admin/gallery/failed'))->status);
        $this->users->current = $this->users->add('admin-galerie', Roles::ADMIN);
        $res = $this->app->api()->handle(new Request('GET', '/admin/gallery/failed'));
        $this->assertSame(200, $res->status);
        $this->assertSame(1, $res->data['total']);
        $row = $this->app->db->selectOne('SELECT name, drive_file_id FROM {photos} WHERE id = ?', [$intern['id']]);
        $this->assertSame([[
            'id' => $intern['id'],
            'name' => $row['name'],
            'album' => '2026 · Sommerfest',
            'intern' => true,
            'error' => 'Datei ist kein unterstütztes Bild',
            'attempts' => 5,
            'drive_url' => 'https://drive.google.com/file/d/' . $row['drive_file_id'] . '/view',
        ]], $res->data['photos']);
        $this->assertSame(1, $this->app->settingsService()->get()['gallery']['status']['failed']);
        $res = $this->app->api()->handle(new Request('POST', '/admin/gallery/retry'));
        $this->assertSame(['retried' => 1, 'pending' => 1], $res->data);
        $this->assertSame(['pending', 0, ''], array_values($this->app->db->selectOne('SELECT status, attempts, error FROM {photos} WHERE id = ?', [$intern['id']])));
        $this->assertSame(['photos' => [], 'total' => 0], $this->app->api()->handle(new Request('GET', '/admin/gallery/failed'))->data);
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
        $pdo->transactions = [];
        $stats = $gallery->sync();
        $this->assertSame(5, $stats['photos']);
        $this->assertSame([], $pdo->writes(), 'Abgleich ohne Änderungen schreibt nichts in die Datenbank');
        $this->assertSame([], $pdo->transactions, 'und öffnet keine Transaktion (SQLite: keine Schreibsperre)');
        $this->assertSame(3, count(preg_grep('/FROM kompanie_photos WHERE drive_file_id IN/', $pdo->log)), 'eine Abfrage je Album für die vorhandenen Bilder');
        $this->assertSame(['SELECT * FROM kompanie_albums'], array_values(preg_grep('/FROM kompanie_albums/', $pdo->log)), 'alle Alben mit einer Abfrage');
        // gemessen: 3 Alben → 7 Abfragen (vorher 10 und 4 Transaktionen; bei 23 Alben 27 statt 50 und 24)
        $this->assertCount(7, $pdo->log);

        // Eine Änderung: genau ein UPDATE für dieses Bild.
        $this->drive->tree['A1'][0]['name'] = 'umbenannt.jpg';
        $pdo->log = [];
        $gallery->sync();
        $updates = preg_grep('/^UPDATE kompanie_photos/', $pdo->writes());
        $this->assertCount(1, $updates);
        $this->assertSame([], preg_grep('/^UPDATE kompanie_albums/', $pdo->writes()), 'Album unverändert');
        $this->assertSame(0, $gallery->countPending(), 'Umbenennen allein erzeugt die Bilder nicht neu');
        $this->assertCount(1, $pdo->transactions, 'nur das geänderte Album in einer Transaktion');
    }

    public function testOverviewWithCoversInOneQuery(): void
    {
        $pdo = new CountingPdo('sqlite::memory:');
        $app = TestApp::create(new PdoConnection($pdo, 'kompanie_'), $this->users, [], $this->drive->http(), $this->dir);
        $app->drive()->saveServiceAccount($this->drive->serviceAccountJson());
        $gallery = $app->gallery();
        $gallery->setRoot('ROOT');
        $gallery->sync();
        $cover = static function (array $years, string $title): ?string {
            foreach ($years as $y) {
                foreach ($y['albums'] as $a) {
                    if ($a['title'] === $title) {
                        return $a['cover'];
                    }
                }
            }
            return null;
        };
        $id = static fn (string $drive): int => (int) $app->db->scalar('SELECT id FROM {photos} WHERE drive_file_id = ?', [$drive]);

        $pdo->log = [];
        $member = $gallery->overview(true);
        $this->assertCount(1, $pdo->log, 'eine Abfrage für alle Alben samt Titelbild');
        $pdo->log = [];
        $guest = $gallery->overview(false);
        $this->assertCount(1, $pdo->log);

        // Noch nichts fertig: öffentliches Bild vor internem, sonst nach Aufnahmezeit (a.jpg vor b.jpg).
        $this->assertSame('https://example.org/app/foto/' . $id('IMG1') . '/thumb', $cover($member, 'Sommerfest'));
        $this->assertSame('https://example.org/app/foto/' . $id('IMG1') . '/thumb', $cover($guest, 'Sommerfest'));
        $this->assertSame('https://example.org/app/foto/' . $id('IMG4') . '/thumb', $cover($member, 'Vereinsabend'), 'rein internes Album: internes Titelbild');
        $this->assertNull($cover($guest, 'Vereinsabend'));

        // Fertige Bilder gehen vor; öffentliche fertige als statische Datei.
        $photo = $app->db->selectOne('SELECT * FROM {photos} WHERE drive_file_id = ?', ['IMG2']);
        $this->assertTrue($gallery->generate($photo));
        $url = (string) $cover($gallery->overview(false), 'Sommerfest');
        $this->assertStringStartsWith('https://example.org/media/' . $photo['album_id'] . '/' . $photo['file_key'] . '-thumb.jpg', $url);
        // Fehlgeschlagene Bilder sind kein Titelbild.
        $app->db->execute("UPDATE {photos} SET status = 'error' WHERE drive_file_id IN ('IMG1', 'IMG2')");
        $this->assertSame('https://example.org/app/foto/' . $id('IMG3') . '/thumb', $cover($gallery->overview(true), 'Sommerfest'));
        $this->assertNull($cover($gallery->overview(false), 'Sommerfest'), 'Gäste: Album ohne sichtbare Bilder fehlt');
    }

    public function testPhotoNotReadyIsGeneratedInRequestWithLocks(): void
    {
        $gallery = $this->app->gallery();
        $gallery->waitSeconds = 0;
        $this->app->drive()->saveServiceAccount($this->drive->serviceAccountJson());
        $gallery->setRoot('ROOT');
        $this->app->scheduleTasks();
        $this->assertArrayHasKey(GalleryService::PROCESS_TASK, $this->app->scheduler->scheduled);
        $gallery->sync();
        // das Bild, das auch die Aufbereitung im Hintergrund als nächstes nähme
        $repo = (new \ReflectionProperty($gallery, 'repo'))->getValue($gallery);
        $id = (int) $repo->nextPending()['id'];
        $lockFile = fn (string $name) => fopen($this->app->files->path($name, false), 'c');

        // Ein anderer Prozess erzeugt gerade dieses Bild (Sperre je Bild): nicht noch einmal laden.
        $imageLock = $lockFile('.bild-' . ($id % 64) . '.lock');
        $this->assertTrue(flock($imageLock, LOCK_EX));
        $res = $gallery->photoForDelivery($id, 'thumb', true, true);
        $this->assertSame(202, $res['status'], 'Bild wird gerade erzeugt');
        $this->assertStringStartsWith('<svg', $res['placeholder']);
        $this->assertSame(GalleryService::RETRY_AFTER, $res['retryAfter']);
        $this->assertSame(0, $this->drive->downloads, 'kein doppelter Download');
        $this->assertSame([GalleryService::PROCESS_TASK], $this->app->scheduler->soon, 'Aufbereitung im Hintergrund angestoßen');
        $this->assertSame(0, $gallery->processPending(60), 'auch der Hintergrund lädt das gesperrte Bild nicht');
        $this->assertSame(0, $this->drive->downloads);
        flock($imageLock, LOCK_UN);
        fclose($imageLock);

        // Alle Plätze belegt: sofort ein Platzhalter, kein Download.
        $slots = [];
        for ($i = 0; $i < GalleryService::REQUEST_SLOTS; $i++) {
            $slots[$i] = $lockFile('.erzeugen-' . $i . '.lock');
            $this->assertTrue(flock($slots[$i], LOCK_EX));
        }
        $this->assertSame(202, $gallery->photoForDelivery($id, 'large', true, true)['status'], 'kein Platz frei');
        $this->assertSame(0, $this->drive->downloads);
        $this->assertCount(1, $this->app->scheduler->soon, 'höchstens einmal pro Minute angestoßen');

        // Ein Platz frei: Das Bild entsteht während der Anfrage, beide Größen mit einem Download.
        flock($slots[0], LOCK_UN);
        $res = $gallery->photoForDelivery($id, 'large', true, true);
        $this->assertSame(200, $res['status'], 'sofort erzeugt');
        $this->assertSame("\xFF\xD8", substr((string) file_get_contents($res['file']), 0, 2));
        $this->assertSame(1, $this->drive->downloads);
        $this->assertSame('ready', $this->app->db->scalar('SELECT status FROM {photos} WHERE id = ?', [$id]));
        $this->assertSame(200, $gallery->photoForDelivery($id, 'thumb', true, true)['status']);
        $this->assertSame(1, $this->drive->downloads, 'Vorschau entstand mit der Großansicht');
        foreach ($slots as $slot) {
            flock($slot, LOCK_UN);
            fclose($slot);
        }

        // Läuft gerade eine Aufbereitung (Sperre), bereitet ein zweiter Prozess nichts auf.
        $lock = $lockFile('.aufbereitung.lock');
        $this->assertTrue(flock($lock, LOCK_EX));
        $this->assertSame(0, $gallery->processPending(60));
        flock($lock, LOCK_UN);
        fclose($lock);

        $this->app->scheduler->soon = [];
        $this->app->runTask(GalleryService::PROCESS_TASK);
        $this->assertSame(0, $gallery->countPending());
        $this->assertSame([], $this->app->scheduler->soon, 'nichts mehr ausstehend: kein weiterer Lauf');
        $this->assertSame(5, $this->drive->downloads, 'jedes Bild einmal geladen');

        // Fertig, aber die Datei fehlt: wird während der Anfrage neu erzeugt.
        $photo = $this->app->db->selectOne('SELECT * FROM {photos} WHERE id = ?', [$id]);
        unlink($gallery->path($photo, 'thumb'));
        $this->assertSame(200, $gallery->photoForDelivery($id, 'thumb', true, true)['status']);
        $this->assertSame(6, $this->drive->downloads);

        // Scheitert die Erzeugung, gibt es 404 (und beim nächsten Abruf keinen neuen Download).
        $this->app->db->execute("UPDATE {photos} SET status = 'pending' WHERE id = ?", [$id]);
        $this->drive->downloadFails = true;
        $this->assertSame(404, $gallery->photoForDelivery($id, 'thumb', true, true)['status']);
        $this->assertSame(GalleryRepository::FAILED, $this->app->db->scalar('SELECT status FROM {photos} WHERE id = ?', [$id]));
        $this->assertSame(404, $gallery->photoForDelivery($id, 'thumb', true, true)['status']);
        $this->assertSame(7, $this->drive->downloads);
    }

    public function testOriginalResolutionIsGeneratedWhenOpened(): void
    {
        $gallery = $this->app->gallery();
        $gallery->waitSeconds = 0;
        $this->app->drive()->saveServiceAccount($this->drive->serviceAccountJson());
        $gallery->setRoot('ROOT');
        $this->drive->contents['IMG1'] = JpegMetadataTest::jpegWithMetadata(3000, 2000);
        $gallery->sync();
        $gallery->processPending(60);
        $downloads = $this->drive->downloads;
        $id = (int) $this->app->db->scalar("SELECT id FROM {photos} WHERE drive_file_id = 'IMG1'");
        $album = (int) $this->app->db->scalar("SELECT album_id FROM {photos} WHERE id = ?", [$id]);
        $photo = fn (): array => array_values(array_filter($gallery->album($album, false)['photos'], static fn (array $p): bool => $p['id'] === $id))[0];

        // Noch nicht erzeugt: Adresse über die App, die Großansicht zeigt bis dahin large.
        $this->assertSame('https://example.org/app/foto/' . $id . '/original', $photo()['original']);
        $res = $gallery->photoForDelivery($id, 'original', false, false);
        $this->assertSame(200, $res['status']);
        $this->assertSame([3000, 2000], array_slice((array) getimagesize((string) $res['file']), 0, 2), 'volle Auflösung, nicht 1600');
        $this->assertStringNotContainsString('GPSLatitude', (string) file_get_contents((string) $res['file']), 'ohne Metadaten');
        $this->assertSame($downloads + 1, $this->drive->downloads, 'Original einmal aus Drive geladen');
        $this->assertSame(200, $gallery->photoForDelivery($id, 'original', false, false)['status']);
        $this->assertSame($downloads + 1, $this->drive->downloads, 'danach aus der Datei');
        $this->assertStringEndsWith('-original.jpg', $photo()['original'], 'öffentlich: jetzt direkt als Datei');

        // Interne Bilder: Rechte wie bei den anderen Größen.
        $intern = (int) $this->app->db->scalar("SELECT id FROM {photos} WHERE drive_file_id = 'IMG3'");
        $this->assertSame(401, $gallery->photoForDelivery($intern, 'original', false, false)['status']);

        // Scheitert das Original (z. B. Störung bei Google): 404, und nicht bei jedem Klick erneut aus Drive laden.
        $this->drive->downloadFails = true;
        $before = $this->drive->downloads;
        $this->assertSame(404, $gallery->photoForDelivery($intern, 'original', true, true)['status']);
        $this->assertSame(404, $gallery->photoForDelivery($intern, 'original', true, true)['status']);
        $this->assertSame($before + 1, $this->drive->downloads, 'Merker verhindert weitere Downloads');
        $this->drive->downloadFails = false;

        // Neuer Inhalt in Drive: alle Fassungen samt Original verschwinden.
        $file = (string) $res['file'];
        $row = (array) $this->app->db->selectOne('SELECT * FROM {photos} WHERE id = ?', [$id]);
        $gallery->deleteFiles($row);
        $this->assertFileDoesNotExist($file);
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

    /**
     * Nachgebaute RAW-Datei (wie NEF): IFD0 mit Ausrichtung und kleinem Vorschaubild, SubIFD mit großem JPEG.
     */
    private static function raw(int $orientation): string
    {
        $small = DriveMock::jpeg(160, 120);
        $large = DriveMock::jpeg(1200, 900);
        $entry = static fn (int $tag, int $type, int $value): string => pack('vvV', $tag, $type, 1) . ($type === 3 ? pack('vv', $value, 0) : pack('V', $value));
        $sub = 8 + 2 + 4 * 12 + 4;
        $data = $sub + 2 + 2 * 12 + 4;
        $ifd0 = pack('v', 4) . $entry(0x0112, 3, $orientation) . $entry(0x014A, 4, $sub)
            . $entry(0x0201, 4, $data) . $entry(0x0202, 4, strlen($small)) . pack('V', 0);
        $ifd1 = pack('v', 2) . $entry(0x0201, 4, $data + strlen($small)) . $entry(0x0202, 4, strlen($large)) . pack('V', 0);
        return "II*\x00" . pack('V', 8) . $ifd0 . $ifd1 . $small . $large . str_repeat("\x00", 4096);
    }

    public function testUnreadableFormatsUseRawPreviewOrGoogleThumbnail(): void
    {
        $gallery = $this->app->gallery();
        $this->app->drive()->saveServiceAccount($this->drive->serviceAccountJson());
        $gallery->setRoot('ROOT');
        $this->drive->contents = [
            'IMG1' => self::raw(6), // RAW hochkant: eingebettetes JPEG, gedreht
            'IMG2' => "II*\x00" . pack('V', 0) . str_repeat("\x00", 64), // z. B. TIFF ohne JPEG: Vorschaubild von Google
            'IMG3' => 'kaputt', // weder noch: fehlgeschlagen
        ];
        $this->drive->thumbnails = ['IMG2'];
        $gallery->sync();
        $gallery->processPending(60);
        $db = $this->app->db;
        $row = static fn (string $id): array => $db->selectOne('SELECT * FROM {photos} WHERE drive_file_id = ?', [$id]);

        $raw = $row('IMG1');
        $this->assertSame(GalleryRepository::READY, $raw['status']);
        $this->assertSame([900, 1200], [(int) $raw['width'], (int) $raw['height']], 'großes JPEG statt Daumennagel, nach der Ausrichtung der RAW-Datei gedreht');
        $this->assertSame([300, 400], array_slice(getimagesize($gallery->path($raw, 'thumb')), 0, 2));

        $tiff = $row('IMG2');
        $this->assertSame(GalleryRepository::READY, $tiff['status']);
        $this->assertSame([1600, 1200], [(int) $tiff['width'], (int) $tiff['height']]);
        $this->assertSame(['https://lh3.googleusercontent.com/drive-storage/IMG2=s1600'], $this->drive->thumbnailRequests);

        $broken = $row('IMG3');
        $this->assertSame(GalleryRepository::FAILED, $broken['status']);
        $this->assertSame('Das Bild kann nicht gelesen werden.', $broken['error'], 'Meldung des Originals, nicht der Ausweichwege');

        // Stürzt PHP am Original immer wieder ab, kommt das Bild nach MAX_ATTEMPTS aus Googles Vorschaubild.
        $this->drive->thumbnails[] = 'IMG3';
        $db->update('photos', ['status' => GalleryRepository::PENDING, 'attempts' => GalleryService::MAX_ATTEMPTS], ['id' => (int) $broken['id']]);
        $downloads = $this->drive->downloads;
        $gallery->processPending(60);
        $this->assertSame(GalleryRepository::READY, $row('IMG3')['status']);
        $this->assertSame($downloads, $this->drive->downloads, 'das Original wird nicht noch einmal geladen');
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
