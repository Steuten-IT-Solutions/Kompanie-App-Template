<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\GalleryService;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Storage\Backup;
use KompanieApp\Core\Tests\Support\DriveMock;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * Galerie-Quelle „eigener Server“ (Issue #285): Alben der App, hochgeladene Bilder, Quelle und Ablageort in den
 * Einstellungen, Galerie ohne Drive, Abgleich mit Drive lässt hochgeladene Bilder in Ruhe, Sicherung.
 */
final class GalleryUploadTest extends TestCase
{
    private string $dir;
    private DriveMock $drive;
    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $m;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kompanie-upload-' . bin2hex(random_bytes(4));
        $this->drive = new DriveMock();
        $this->users = new FakeUsers();
        $this->app = TestApp::create(TestApp::connection(), $this->users, [], $this->drive->http(), $this->dir);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->m = $this->users->add('mitglied', Roles::MEMBER);
        $this->users->current = $this->admin;
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
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

    /** JPEG mit EXIF-Block (GPS soll nach dem Neukodieren fehlen). */
    private function jpeg(int $w = 40, int $h = 30): string
    {
        $img = imagecreatetruecolor($w, $h);
        ob_start();
        imagejpeg($img);
        $jpeg = (string) ob_get_clean();
        $exif = "Exif\0\0" . 'MM' . "\0\x2A\0\0\0\x08" . 'GPSLatitude 51.0';
        return substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif . substr($jpeg, 2);
    }

    private function file(string $name = 'Fest.jpg', ?string $bytes = null): array
    {
        return ['name' => $name, 'data' => base64_encode($bytes ?? $this->jpeg())];
    }

    /** @return list<string> Dateien in der Ablage */
    private function stored(): array
    {
        return array_map('basename', glob($this->dir . '/private/einreichungen/*-*.jpg') ?: []);
    }

    public function testGalleryWithoutDriveAppAlbumsAndUploads(): void
    {
        $this->assertFalse($this->app->gallery()->isConfigured(), 'ohne Drive und mit Quelle Drive: nicht eingerichtet');
        $settings = $this->ok('PATCH', '/admin/settings', ['gallery_source' => 'server', 'gallery_store' => 'server']);
        $this->assertSame('server', $settings['gallery']['source']);
        $this->assertSame('server', $settings['gallery']['store']);
        $this->assertTrue($settings['gallery']['configured'], 'eigener Server: ohne Drive nutzbar');
        $this->assertSame(400, $this->call('PATCH', '/admin/settings', ['gallery_source' => 'ftp'])->status);
        $this->assertSame(400, $this->call('PATCH', '/admin/settings', ['gallery_store' => 'drive'])->status, 'Drive als Ablageort noch nicht');

        $album = $this->ok('POST', '/admin/gallery/albums', ['title' => 'Schützenfest', 'year' => 2026]);
        $this->assertSame('app', $album['source']);
        foreach ([['title' => '', 'year' => 2026], ['title' => 'X', 'year' => 1700], ['title' => str_repeat('x', 121), 'year' => 2026]] as $bad) {
            $this->assertSame(400, $this->call('POST', '/admin/gallery/albums', $bad)->status);
        }
        $id = (int) $album['id'];
        $res = $this->ok('POST', "/admin/gallery/albums/{$id}/photos", ['file' => $this->file('IMG_0001.jpeg')]);
        $this->assertSame(['IMG_0001.jpg'], array_column($res['uploads'], 'name'));
        $this->ok('POST', "/admin/gallery/albums/{$id}/photos", ['file' => $this->file('Intern.jpg'), 'intern' => true]);
        $this->assertCount(2, $this->stored());
        foreach ($this->stored() as $f) {
            $this->assertStringNotContainsString('GPSLatitude', (string) file_get_contents($this->dir . '/private/einreichungen/' . $f), 'ohne Metadaten neu kodiert');
        }
        foreach ([
            'falsche Endung' => $this->file('bild.gif'),
            'kein Bild' => $this->file('bild.jpg', 'kein Bild'),
            'leer' => ['name' => 'x.jpg', 'data' => ''],
        ] as $case => $f) {
            $this->assertSame(400, $this->call('POST', "/admin/gallery/albums/{$id}/photos", ['file' => $f])->status, $case);
        }
        $this->assertSame(404, $this->call('POST', '/admin/gallery/albums/999/photos', ['file' => $this->file()])->status);

        // Aufbereitet wie Bilder aus Drive, sichtbar in der Galerie
        $this->app->gallery()->processPending(30);
        $this->assertSame(0, $this->drive->downloads, 'nichts aus Drive');
        $guest = $this->app->gallery()->album($id, false);
        $this->assertCount(1, $guest['photos']);
        $this->assertTrue($guest['photos'][0]['ready']);
        $this->assertCount(2, $this->app->gallery()->album($id, true)['photos'], 'intern nur für Mitglieder');
        $photoId = (int) $guest['photos'][0]['id'];
        $this->assertSame(200, $this->app->gallery()->photoForDelivery($photoId, GalleryService::ORIGINAL, false, false)['status'], 'Original aus der Ablage');

        $albums = $this->ok('GET', '/admin/gallery/albums')['albums'];
        $this->assertSame([['id' => $id, 'title' => 'Schützenfest', 'year' => '2026', 'source' => 'app', 'uploads' => 2, 'drive_photos' => 0]], $albums);
        $this->assertSame('Sommer', $this->ok('PATCH', "/admin/gallery/albums/{$id}", ['title' => 'Sommer'])['title']);
        $this->assertGreaterThan(0, $this->ok('GET', '/admin/settings')['gallery']['media_bytes']);
        $this->assertStringContainsString('Hochgeladene Bilder belegen', json_encode($this->ok('GET', '/admin/status'), JSON_UNESCAPED_UNICODE));

        // Rechte: nur manage_settings
        $this->users->current = $this->m;
        $this->assertSame(403, $this->call('GET', '/admin/gallery/albums')->status);
        $this->assertSame(403, $this->call('POST', "/admin/gallery/albums/{$id}/photos", ['file' => $this->file()])->status);
        $this->users->current = $this->admin;

        // Löschen: ein Bild, dann das Album mit allem
        $this->ok('DELETE', "/admin/gallery/photos/{$photoId}");
        $this->assertCount(1, $this->stored());
        $this->ok('DELETE', "/admin/gallery/albums/{$id}");
        $this->assertSame([], $this->stored());
        $this->assertSame([], $this->ok('GET', '/admin/gallery/albums')['albums']);
    }

    /** Knöpfe zum Herunterladen: Einstellung gallery_downloads, Vorgabe aus; das Album meldet sie der App. */
    public function testDownloadButtonsSetting(): void
    {
        $settings = $this->ok('PATCH', '/admin/settings', ['gallery_source' => 'server']);
        $this->assertFalse($settings['gallery']['downloads'], 'Vorgabe: keine Knöpfe');
        $id = (int) $this->ok('POST', '/admin/gallery/albums', ['title' => 'Fest', 'year' => 2026])['id'];
        $this->ok('POST', "/admin/gallery/albums/{$id}/photos", ['file' => $this->file()]);
        $this->app->gallery()->processPending(30);
        $this->users->current = $this->m;
        $this->assertFalse($this->ok('GET', "/gallery/{$id}")['downloads']);

        $this->users->current = $this->admin;
        $this->assertTrue($this->ok('PATCH', '/admin/settings', ['gallery_downloads' => '1'])['gallery']['downloads']);
        foreach (['ja', '2', ''] as $bad) {
            $this->assertSame(400, $this->call('PATCH', '/admin/settings', ['gallery_downloads' => $bad])->status, $bad);
        }
        $this->assertSame(400, $this->call('PATCH', '/admin/settings', ['gallery_downloads' => true])->status, 'nur Text wie alle Felder');
        $this->users->current = $this->m;
        $this->assertTrue($this->ok('GET', "/gallery/{$id}")['downloads']);
        $this->assertSame(403, $this->call('PATCH', '/admin/settings', ['gallery_downloads' => '0'])->status, 'nur mit manage_settings');

        $this->users->current = $this->admin;
        $this->assertFalse($this->ok('PATCH', '/admin/settings', ['gallery_downloads' => '0'])['gallery']['downloads']);
        $this->assertTrue($this->ok('PATCH', '/admin/settings', ['gallery_root' => ''])['gallery']['downloads'] === false, 'andere Felder ändern nichts daran');
    }

    public function testDriveSyncLeavesUploadsAndAppAlbumsAlone(): void
    {
        $this->app->drive()->saveServiceAccount($this->drive->serviceAccountJson());
        $gallery = $this->app->gallery();
        $gallery->setRoot('ROOT');
        $gallery->sync();
        $albums = $this->ok('GET', '/admin/gallery/albums')['albums'];
        $driveAlbum = $albums[0];
        $this->assertSame('drive', $driveAlbum['source']);
        $this->assertSame(400, $this->call('PATCH', "/admin/gallery/albums/{$driveAlbum['id']}", ['title' => 'X'])->status, 'Alben aus Drive ändert man in Drive');
        $this->assertSame(400, $this->call('DELETE', "/admin/gallery/albums/{$driveAlbum['id']}")->status);

        // Hochladen in ein Album aus Drive und in ein Album der App
        $this->ok('POST', "/admin/gallery/albums/{$driveAlbum['id']}/photos", ['file' => $this->file('Zusatz.jpg')]);
        $app = (int) $this->ok('POST', '/admin/gallery/albums', ['title' => 'Nur in der App', 'year' => 2025])['id'];
        $this->ok('POST', "/admin/gallery/albums/{$app}/photos", ['file' => $this->file('Eigen.jpg')]);
        $gallery->sync();
        $gallery->processPending(30);
        $this->assertCount(2, $this->stored(), 'Abgleich lässt hochgeladene Bilder in Ruhe');
        $this->assertContains('Nur in der App', array_column($this->ok('GET', '/admin/gallery/albums')['albums'], 'title'));

        // Das Album verschwindet aus Drive: Es bleibt wegen des hochgeladenen Bilds, die Bilder aus Drive gehen
        $this->drive->tree['ROOT'] = [];
        $gallery->sync();
        $left = array_column($this->ok('GET', '/admin/gallery/albums')['albums'], null, 'id');
        $this->assertSame(1, $left[$driveAlbum['id']]['uploads']);
        $this->assertSame(0, $left[$driveAlbum['id']]['drive_photos']);
        $this->assertArrayHasKey($app, $left);

        // Fehlgeschlagene hochgeladene Bilder: keine Adresse in Drive
        $this->app->db->execute("UPDATE {photos} SET status = 'error', error = 'kaputt' WHERE source = 'upload'");
        $this->assertSame(['', ''], array_column($gallery->failedPhotos(), 'drive_url'));
        $this->app->db->execute("UPDATE {photos} SET status = 'ready', error = '' WHERE source = 'upload'");

        // Wechsel zu „eigener Server“: Drive-Inhalte weg, hochgeladene bleiben; kein Abgleich mehr
        $this->ok('PATCH', '/admin/settings', ['gallery_source' => 'server']);
        $this->assertFalse($gallery->driveConfigured());
        $this->assertSame(0, (int) $this->app->db->scalar("SELECT COUNT(*) FROM {photos} WHERE source = 'drive'"));
        $this->assertSame(2, (int) $this->app->db->scalar("SELECT COUNT(*) FROM {photos} WHERE source = 'upload'"));
        $before = $this->drive->tokens;
        $this->ok('POST', '/admin/gallery/sync');
        $this->assertSame($before, $this->drive->tokens, 'kein Abruf bei Google');
    }

    public function testBackupContainsUploads(): void
    {
        $this->ok('PATCH', '/admin/settings', ['gallery_source' => 'server']);
        $id = (int) $this->ok('POST', '/admin/gallery/albums', ['title' => 'Fest', 'year' => 2026])['id'];
        $this->ok('POST', "/admin/gallery/albums/{$id}/photos", ['file' => $this->file()]);
        [$name] = $this->stored();
        $tables = (new Backup($this->app->db))->dump(['albums', 'photos']);
        $files = Backup::dumpFiles($this->app->files, $tables);
        $this->assertSame(['einreichungen/' . $name], array_keys($files));

        // Auch beim stückweisen Schreiben
        $out = fopen('php://memory', 'w+');
        (new Backup($this->app->db))->write($out, ['albums', 'photos'], $this->app->files, ['format' => 'test'], []);
        rewind($out);
        $json = json_decode((string) stream_get_contents($out), true);
        $this->assertSame(['einreichungen/' . $name], array_keys($json['files']));

        // Wiederherstellen: Datei kommt zurück
        unlink($this->dir . '/private/einreichungen/' . $name);
        Backup::restoreFiles($this->app->files, Backup::parseFiles($json['files']));
        $this->assertSame([$name], $this->stored());
    }
}
