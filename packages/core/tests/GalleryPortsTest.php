<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Domain\DriveClient;
use KompanieApp\Core\Domain\FileMediaStore;
use KompanieApp\Core\Domain\GalleryService;
use KompanieApp\Core\Port\GallerySource;
use KompanieApp\Core\Port\MediaStore;
use KompanieApp\Core\Storage\GalleryRepository;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * Schnittstellen der Galerie (Issue #284): GallerySource (Quelle, heute Google Drive) und MediaStore (eigene Originale).
 * Die Galerie läuft mit jeder Quelle, die die Schnittstelle erfüllt; DriveClient ist eine davon.
 */
final class GalleryPortsTest extends TestCase
{
    private string $dir;
    private App $app;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kompanie-ports-' . bin2hex(random_bytes(4));
        $this->app = TestApp::create(TestApp::connection(), new FakeUsers(), [], null, $this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** Quelle im Speicher: Ordner-ID => Einträge; Dateien sind kleine PNG. */
    private function source(array $tree): GallerySource
    {
        return new class ($tree) implements GallerySource {
            /** @var list<string> */
            public array $downloads = [];

            public function __construct(private readonly array $tree)
            {
            }

            public function hasCredentials(): bool
            {
                return true;
            }

            public function listChildren(string $folderId): array
            {
                return $this->tree[$folderId] ?? [];
            }

            public function download(string $fileId): string
            {
                $this->downloads[] = $fileId;
                $img = imagecreatetruecolor(8, 6);
                $file = (string) tempnam(sys_get_temp_dir(), 'src-');
                imagepng($img, $file);
                return $file;
            }

            public function thumbnail(string $fileId, int $maxEdge): string
            {
                return $this->download($fileId);
            }

            public function streamRange(string $fileId, int $start, int $end, \Closure $onStart, \Closure $onData): bool
            {
                $onStart($start, $end, $end + 1);
                return $onData(str_repeat('x', $end - $start + 1));
            }

            public function fileUrl(string $fileId): string
            {
                return 'https://quelle.example/' . $fileId;
            }

            public static function parseFolderId(string $input): string
            {
                return trim($input);
            }

            public static function isFolder(array $f): bool
            {
                return ($f['mimeType'] ?? '') === 'folder';
            }

            public static function isImage(array $f): bool
            {
                return str_starts_with((string) ($f['mimeType'] ?? ''), 'image/');
            }

            public static function isVideo(array $f): bool
            {
                return str_starts_with((string) ($f['mimeType'] ?? ''), 'video/');
            }

            public static function isPlayableVideo(string $mime): bool
            {
                return $mime === 'video/mp4';
            }
        };
    }

    private function gallery(GallerySource $source): GalleryService
    {
        $a = $this->app;
        return new GalleryService(new GalleryRepository($a->db), $source, $a->files, $a->images, $a->settings, $a->env, $a->time(), $a->scheduler, $a->cache, $a->mediaStore());
    }

    public function testDriveClientIsTheFirstSourceAndAppWiresTheMediaStore(): void
    {
        $this->assertInstanceOf(GallerySource::class, $this->app->drive());
        $this->assertSame('https://drive.google.com/file/d/abc_1/view', $this->app->drive()->fileUrl('abc_1'));
        $this->assertInstanceOf(MediaStore::class, $this->app->mediaStore());
        $this->assertInstanceOf(FileMediaStore::class, $this->app->mediaStore());
    }

    public function testGalleryRunsOnAnySource(): void
    {
        $file = static fn (string $id, string $mime = 'image/png'): array => ['id' => $id, 'name' => $id . '.png', 'mimeType' => $mime, 'modifiedTime' => '2026-10-01T10:00:00Z'];
        $source = $this->source([
            'ROOT' => [['id' => 'Y', 'name' => '2026', 'mimeType' => 'folder']],
            'Y' => [['id' => 'A', 'name' => 'Sommerfest', 'mimeType' => 'folder']],
            'A' => [$file('p1'), $file('p2'), $file('v1', 'video/quicktime'), ['id' => 'I', 'name' => 'intern', 'mimeType' => 'folder'], ['id' => 'x', 'name' => 'liesmich.txt', 'mimeType' => 'text/plain']],
            'I' => [$file('p3')],
        ]);
        $gallery = $this->gallery($source);
        $gallery->setRoot('ROOT');
        $this->assertTrue($gallery->isConfigured());
        $stats = $gallery->sync();
        $this->assertSame(['albums' => 1, 'photos' => 4, 'new' => 4], array_intersect_key($stats, ['albums' => 0, 'photos' => 0, 'new' => 0]));
        $gallery->processPending(30);
        $this->assertContains('p1', $source->downloads, 'Bilder kommen aus der Quelle');
        $overview = $gallery->overview(true);
        $this->assertSame('Sommerfest', $overview[0]['albums'][0]['title']);
        $this->assertSame(1, $overview[0]['albums'][0]['intern_count']);
        // Nicht abspielbares Video: Liste für Admins mit Adresse der Quelle
        $failed = $gallery->failedPhotos();
        $this->assertSame(['https://quelle.example/v1'], array_column($failed, 'drive_url'));
    }

    public function testFileMediaStore(): void
    {
        $store = $this->app->mediaStore();
        $src = (string) tempnam(sys_get_temp_dir(), 'm-');
        file_put_contents($src, 'abc');
        $key = $store->put(7, $src, 'JPEG');
        $this->assertMatchesRegularExpression('#^galerie-medien/7-[a-f0-9]{24}\.jpg$#', $key);
        $this->assertSame('abc', file_get_contents((string) $store->path($key)));
        $this->assertSame(3, $store->bytes());

        $moved = $store->move($key, 9);
        $this->assertStringStartsWith('galerie-medien/9-', $moved);
        $this->assertNull($store->path($key), 'alter Schlüssel weg');
        $this->assertSame('abc', file_get_contents((string) $store->path($moved)));
        $this->assertSame($moved, $store->move($moved, 9), 'gleiches Album: unverändert');

        foreach (['../secret', 'galerie-medien/../x', 'dokumente/1-' . str_repeat('a', 24) . '.jpg'] as $bad) {
            $this->assertNull($store->path($bad), $bad);
        }
        $store->delete($moved);
        $this->assertNull($store->path($moved));
        $this->assertSame(0, $store->bytes());

        foreach ([[0, 'jpg'], [1, 'php'], [1, 'svg']] as [$album, $ext]) {
            try {
                $store->put($album, $src, $ext);
                $this->fail("$album/$ext angenommen");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        unlink($src);
    }

    public function testDriveClientHelpersStayStatic(): void
    {
        $this->assertTrue(DriveClient::isFolder(['mimeType' => 'application/vnd.google-apps.folder']));
        $this->assertTrue(DriveClient::isPlayableVideo('video/MP4'));
        $this->assertSame('ROOT1', DriveClient::parseFolderId('https://drive.google.com/drive/folders/ROOT1'));
    }
}
