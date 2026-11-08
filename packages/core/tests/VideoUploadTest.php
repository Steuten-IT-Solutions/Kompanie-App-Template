<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\GalleryService;
use KompanieApp\Core\Domain\VideoUploadService;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Storage\Backup;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * Videos in die Galerie (Issue #350): in Stücken hochladen (MP4, WebM bis 1 GB), unverändert ablegen, Standbild als
 * Vorschau, direkt ins Album (manage_settings) oder als Einreichung (submit_gallery), Abspielen aus der eigenen Ablage,
 * Ansehen vor der Freigabe, Abbrechen, Aufräumen, Sicherung ohne Videos.
 */
final class VideoUploadTest extends TestCase
{
    private string $dir;
    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $sub;
    private int $rev;
    private int $album;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kompanie-video-' . bin2hex(random_bytes(4));
        $this->users = new FakeUsers();
        $this->app = TestApp::create(TestApp::connection(), $this->users, [], null, $this->dir);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->sub = $this->users->add('einreicher', Roles::MEMBER);
        $this->rev = $this->users->add('pruefer', Roles::MEMBER);
        $this->app->roles()->setOverrides($this->users->find($this->admin), $this->sub, ['submit_gallery'], []);
        $this->app->roles()->setOverrides($this->users->find($this->admin), $this->rev, ['review_gallery'], []);
        $this->users->current = $this->admin;
        $this->ok('PATCH', '/admin/settings', ['gallery_source' => 'server']);
        $this->album = (int) $this->ok('POST', '/admin/gallery/albums', ['title' => 'Sommerfest', 'year' => 2026])['id'];
        $this->app->gallery()->waitSeconds = 0;
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

    /** Anfang einer MP4-Datei (Kasten ftyp), danach Füllbytes. */
    private function mp4(int $bytes = 5000): string
    {
        $head = "\0\0\0\x18ftypmp42\0\0\0\0mp42isom";
        return $head . substr(str_repeat(implode('', range('a', 'z')), (int) ceil($bytes / 26)), 0, $bytes - strlen($head));
    }

    private function poster(int $w = 64, int $h = 36): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 200, 30, 30));
        ob_start();
        imagejpeg($img);
        return 'data:image/jpeg;base64,' . base64_encode((string) ob_get_clean());
    }

    /**
     * Video in Stücken hochladen, ohne abzuschließen.
     *
     * @return string Kennung der Sitzung
     */
    private function send(string $video, string $purpose, string $name = 'Umzug.mp4', int $chunk = 1500): string
    {
        $start = $this->ok('POST', '/gallery/video-uploads', ['album_id' => $this->album, 'name' => $name, 'size' => strlen($video), 'purpose' => $purpose]);
        $this->assertSame(VideoUploadService::MAX_BYTES, $start['max_bytes']);
        $this->assertGreaterThan(0, $start['chunk_size']);
        for ($o = 0; $o < strlen($video); $o += $chunk) {
            $res = $this->ok('POST', '/gallery/video-uploads/chunk', ['upload' => $start['id'], 'offset' => $o, 'data' => base64_encode(substr($video, $o, $chunk))]);
            $this->assertSame(min(strlen($video), $o + $chunk), $res['received']);
        }
        return (string) $start['id'];
    }

    /** @return list<string> Dateien in der Ablage */
    private function stored(): array
    {
        return array_map('basename', glob($this->dir . '/private/einreichungen/*') ?: []);
    }

    public function testAdminUploadsVideoIntoAlbumInChunksAndItPlaysFromTheServer(): void
    {
        // Rechte: ohne manage_settings nicht ins Album, ohne beide Rechte gar nicht
        $this->users->current = $this->rev;
        $this->assertSame(403, $this->call('POST', '/gallery/video-uploads', ['album_id' => $this->album, 'name' => 'a.mp4', 'size' => 10])->status);
        $this->users->current = $this->sub;
        $this->assertSame(403, $this->call('POST', '/gallery/video-uploads', ['album_id' => $this->album, 'name' => 'a.mp4', 'size' => 10, 'purpose' => 'album'])->status);
        $this->users->current = $this->admin;

        // Format und Größe
        $start = fn (array $in): int => $this->call('POST', '/gallery/video-uploads', $in + ['album_id' => $this->album, 'purpose' => 'album'])->status;
        $this->assertSame(400, $start(['name' => 'a.mov', 'size' => 10]), 'nur MP4 und WebM');
        $this->assertSame(400, $start(['name' => 'a.mp4', 'size' => VideoUploadService::MAX_BYTES + 1]), 'höchstens 1 GB');
        $this->assertSame(400, $start(['name' => 'a.mp4', 'size' => 0]));
        $this->assertSame(404, $start(['name' => 'a.mp4', 'size' => 10, 'album_id' => 999]));

        // Erstes Stück muss wie MP4 beginnen (QuickTime nicht)
        $id = (string) $this->ok('POST', '/gallery/video-uploads', ['album_id' => $this->album, 'name' => 'a.mp4', 'size' => 100, 'purpose' => 'album'])['id'];
        $qt = "\0\0\0\x14ftypqt  " . str_repeat('x', 88);
        $this->assertSame(400, $this->call('POST', '/gallery/video-uploads/chunk', ['upload' => $id, 'offset' => 0, 'data' => base64_encode($qt)])->status);
        $this->ok('POST', '/gallery/video-uploads/cancel', ['upload' => $id]);
        $this->assertSame(404, $this->call('POST', '/gallery/video-uploads/chunk', ['upload' => $id, 'offset' => 0, 'data' => base64_encode('x')])->status, 'abgebrochen');

        $video = $this->mp4(5000);
        $id = $this->send($video, 'album');
        // Das letzte Stück noch einmal (Antwort verloren) schadet nicht; ein falscher Anfang schon
        $this->assertSame(5000, $this->ok('POST', '/gallery/video-uploads/chunk', ['upload' => $id, 'offset' => 4500, 'data' => base64_encode(substr($video, 4500))])['received']);
        $this->assertSame(400, $this->call('POST', '/gallery/video-uploads/chunk', ['upload' => $id, 'offset' => 10, 'data' => base64_encode('abc')])->status);
        // Nur die Person, die hochlädt, kommt an die Sitzung
        $this->users->current = $this->sub;
        $this->assertSame(404, $this->call('POST', '/gallery/video-uploads/finish', ['upload' => $id, 'consent' => true])->status);
        $this->users->current = $this->admin;

        $album = $this->ok('POST', '/gallery/video-uploads/finish', ['upload' => $id, 'poster' => $this->poster(), 'width' => 1920, 'height' => 1080, 'duration' => 12.4, 'intern' => false]);
        $this->assertCount(1, $album['uploads']);
        $this->assertSame('video', $album['uploads'][0]['type']);
        $this->assertSame('Umzug.mp4', $album['uploads'][0]['name']);
        $files = $this->stored();
        $this->assertCount(2, $files, 'Video und Standbild');
        $mp4 = array_values(array_filter($files, static fn (string $f): bool => str_ends_with($f, '.mp4')))[0];
        $this->assertSame($video, file_get_contents($this->dir . '/private/einreichungen/' . $mp4), 'unverändert abgelegt');
        $this->assertSame([], glob($this->dir . '/private/' . VideoUploadService::DIR . '/*') ?: [], 'Sitzung aufgeräumt');

        $photoId = (int) $album['uploads'][0]['id'];
        $row = $this->app->db->selectOne('SELECT * FROM {photos} WHERE id = ?', [$photoId]);
        $this->assertSame(['video', 'video/mp4', 12400, 5000, 1920, 1080], [$row['media'], $row['mime'], (int) $row['duration_ms'], (int) $row['bytes'], (int) $row['width'], (int) $row['height']]);

        // Vorschau aus dem Standbild, Maße des Videos bleiben
        $gallery = $this->app->gallery();
        $gallery->processPending(30);
        $row = $this->app->db->selectOne('SELECT * FROM {photos} WHERE id = ?', [$photoId]);
        $this->assertSame('ready', $row['status']);
        $this->assertSame([1920, 1080], [(int) $row['width'], (int) $row['height']]);
        $this->assertFileExists($gallery->path($row, 'thumb'));
        $public = $gallery->album($this->album, false);
        $this->assertSame('video', $public['photos'][0]['type']);
        $this->assertSame(12, $public['photos'][0]['duration']);
        $this->assertSame('https://example.org/app/foto/' . $photoId . '/video', $public['photos'][0]['video']);
        $this->assertStringContainsString('-thumb.jpg', $public['photos'][0]['thumb']);

        // Abspielen in Ausschnitten aus der eigenen Ablage
        $guest = static fn (): array => [false, false];
        $res = $gallery->deliverVideo($photoId, 'bytes=0-9', $guest);
        $this->assertSame([206, 'bytes 0-9/5000', substr($video, 0, 10), 'video/mp4'], [$res['status'], $res['contentRange'], $res['body'], $res['mime']]);
        $res = $gallery->deliverVideo($photoId, 'bytes=4990-', $guest);
        $this->assertSame(substr($video, 4990), $res['body']);
        $this->assertSame(416, $gallery->deliverVideo($photoId, 'bytes=5000-', $guest)['status']);
        $sent = '';
        $head = null;
        $res = $gallery->deliverVideo($photoId, null, $guest, static function (array $h) use (&$head): void {
            $head = $h;
        }, static function (string $chunk) use (&$sent): bool {
            $sent .= $chunk;
            return true;
        });
        $this->assertTrue($res['sent']);
        $this->assertSame($video, $sent);
        $this->assertSame(5000, $head['length']);

        // Intern stellen: Vorschau neu aus dem Standbild, Gäste spielen es nicht mehr ab
        $this->ok('PATCH', '/gallery/photos/' . $photoId, ['intern' => true]);
        $gallery->processPending(30);
        $row = $this->app->db->selectOne('SELECT * FROM {photos} WHERE id = ?', [$photoId]);
        $this->assertSame('ready', $row['status']);
        $this->assertFileExists($gallery->path($row, 'thumb'));
        $this->assertSame(401, $gallery->deliverVideo($photoId, null, $guest)['status']);
        $this->assertSame(206, $gallery->deliverVideo($photoId, null, static fn (): array => [true, true])['status']);

        // Sicherung ohne Videos (zu groß), Löschen nimmt das Standbild mit
        $tables = (new Backup($this->app->db))->dump(['albums', 'photos']);
        $this->assertSame([], array_keys(Backup::fileList($this->app->files, $tables)));
        $this->ok('DELETE', '/admin/gallery/photos/' . $photoId);
        $this->assertSame([], $this->stored());
    }

    public function testVideoWithoutPosterIsReadyWithoutThumbnail(): void
    {
        $video = "\x1A\x45\xDF\xA3" . str_repeat('w', 996);
        $id = $this->send($video, 'album', 'Clip.webm', 1000);
        $this->ok('POST', '/gallery/video-uploads/finish', ['upload' => $id]);
        $this->app->gallery()->processPending(30);
        $photo = $this->app->gallery()->album($this->album, false)['photos'][0];
        $this->assertSame(['video', 'video/webm', '', true], [$photo['type'], $photo['mime'], $photo['thumb'], $photo['ready']]);
        $this->assertSame('', $this->ok('GET', '/admin/gallery/albums/' . $this->album)['uploads'][0]['thumb']);
        $this->assertSame(400, $this->call('POST', '/gallery/video-uploads/finish', ['upload' => $this->send($video, 'album', 'b.webm', 1000), 'poster' => base64_encode('kein Bild')])->status);
    }

    public function testSubmitVideoReviewThenApprove(): void
    {
        $video = $this->mp4(3000);
        $this->users->current = $this->sub;
        $id = $this->send($video, 'submission');
        $this->assertSame(400, $this->call('POST', '/gallery/video-uploads/finish', ['upload' => $id, 'poster' => $this->poster()])->status, 'ohne Einverständnis');
        $s = $this->ok('POST', '/gallery/video-uploads/finish', ['upload' => $id, 'poster' => $this->poster(), 'width' => 1280, 'height' => 720, 'duration' => 3, 'consent' => true]);
        $this->assertSame(['pending', 'video', 'Umzug.mp4'], [$s['status'], $s['type'], $s['name']]);
        $this->assertStringStartsWith('data:image/jpeg;base64,', (string) $s['thumb']);
        $this->assertArrayNotHasKey('video', $s, 'nur beim Prüfen');

        // Prüfen: Video ansehen, nur mit review_gallery
        $this->users->current = $this->rev;
        $pending = $this->ok('GET', '/gallery/review')['pending'];
        $this->assertSame('https://example.org/app/foto/' . $s['id'] . '/einreichung', $pending[0]['video']);
        $subs = $this->app->gallerySubmissions();
        $this->assertSame(403, $subs->deliverVideo((int) $s['id'], null, static fn (): array => [false, true])['status']);
        $res = $subs->deliverVideo((int) $s['id'], 'bytes=0-99', static fn (): array => [true, true]);
        $this->assertSame([206, substr($video, 0, 100), true], [$res['status'], $res['body'], $res['intern']]);

        $this->ok('POST', '/gallery/review/' . $s['id'] . '/approve', ['intern' => false]);
        $this->assertSame(404, $subs->deliverVideo((int) $s['id'], null, static fn (): array => [true, true])['status'], 'nur offene');
        $this->app->gallery()->processPending(30);
        $photo = $this->app->gallery()->album($this->album, false)['photos'][0];
        $this->assertSame(['video', 3, 1280, 720, true], [$photo['type'], $photo['duration'], $photo['width'], $photo['height'], $photo['ready']]);
        $res = $this->app->gallery()->deliverVideo($photo['id'], null, static fn (): array => [false, false]);
        $this->assertSame($video, $res['body']);
    }

    public function testStaleSessionsArePruned(): void
    {
        $id = $this->send($this->mp4(2000), 'album');
        $this->assertSame(0, $this->app->videoUploads()->prune());
        foreach (glob($this->dir . '/private/' . VideoUploadService::DIR . '/' . $id . '.*') ?: [] as $f) {
            touch($f, time() - VideoUploadService::STALE - 10);
        }
        $this->assertSame(2, $this->app->videoUploads()->prune());
        $this->assertSame(404, $this->call('POST', '/gallery/video-uploads/finish', ['upload' => $id])->status);
        $this->assertTrue(VideoUploadService::signatureOk('webm', "\x1A\x45\xDF\xA3"));
        $this->assertFalse(VideoUploadService::signatureOk('mp4', "\x1A\x45\xDF\xA3abcdefgh"));
        $this->assertSame(GalleryService::VIDEO_CHUNK, 4 * 1024 * 1024);
    }
}
