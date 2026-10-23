<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Storage\Backup;
use KompanieApp\Core\Storage\Sql\Migrations;
use KompanieApp\Core\Storage\Sql\Migrator;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\MemorySettings;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** Fotos der Mitglieder: Hochladen (auch beim Anlegen), Prüfungen, Rechte, Auslieferung, photo_url, Löschen mit dem Konto. */
final class MemberPhotoTest extends TestCase
{
    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $m;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $dir = sys_get_temp_dir() . '/kompanie-mphoto-' . bin2hex(random_bytes(4));
        $this->app = TestApp::create(TestApp::connection(), $this->users, [], null, $dir, null, new FixedClock(new \DateTimeImmutable('2026-10-20 12:00:00')));
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->m = $this->users->add('mitglied', Roles::MEMBER);
        $this->users->current = $this->admin;
    }

    private function call(string $method, string $path, array $body = [], array $query = []): Response
    {
        return $this->app->api()->handle(new Request($method, $path, $query, $body));
    }

    private static function image(int $w, int $h, string $format = 'jpg'): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, (int) imagecolorallocate($im, 20, 120, 220));
        ob_start();
        match ($format) {
            'png' => imagepng($im),
            'webp' => imagewebp($im),
            default => imagejpeg($im),
        };
        return (string) ob_get_clean();
    }

    /** @return array{name: string, data: string} */
    private static function file(string $name, string $bytes): array
    {
        return ['name' => $name, 'data' => base64_encode($bytes)];
    }

    private function roleWith(string $label, array $caps): string
    {
        $res = $this->call('POST', '/admin/roles', ['label' => $label, 'capabilities' => $caps]);
        $this->assertLessThan(300, $res->status, (string) json_encode($res->data));
        return (string) array_column($this->call('GET', '/admin/roles')->data, 'key', 'label')[$label];
    }

    public function testUploadStoresThreeJpegSizesAndReplaces(): void
    {
        $res = $this->call('PUT', "/members/{$this->m}/photo", ['file' => self::file('Max.PNG', self::image(2000, 1000, 'png'))]);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $url = (string) $res->data['photo_url'];
        $this->assertMatchesRegularExpression('#^https://example\.org/app/mitgliedsfoto/' . $this->m . '\?v=[0-9a-f]{16}&s=128$#', $url);
        foreach ([128 => [128, 64], 480 => [480, 240], 1024 => [1024, 512]] as $size => $dims) {
            $bytes = $this->app->memberPhotos()->repository()->image($this->m, $size);
            $this->assertNotNull($bytes);
            $info = getimagesizefromstring($bytes);
            $this->assertNotFalse($info);
            $this->assertSame([$dims[0], $dims[1], 'image/jpeg'], [$info[0], $info[1], $info['mime']], "$size px: längere Kante, als JPEG");
        }
        // Kleine Fotos werden nie vergrößert.
        $this->call('PUT', "/members/{$this->m}/photo", ['file' => self::file('klein.webp', self::image(100, 50, 'webp'))]);
        $info = getimagesizefromstring((string) $this->app->memberPhotos()->repository()->image($this->m, 480));
        $this->assertNotFalse($info);
        $this->assertSame([100, 50], [$info[0], $info[1]]);
        $this->assertNotSame($url, $this->app->memberPhotos()->url($this->m), 'neue Fassung, neue Adresse');

        // In Liste, Details und (für die Person selbst) in /config
        $list = array_column($this->call('GET', '/members')->data, 'photo_url', 'id');
        $this->assertSame($this->app->memberPhotos()->url($this->m), $list[$this->m]);
        $this->assertNull($list[$this->admin]);
        $detail = $this->call('GET', "/members/{$this->m}")->data;
        $this->assertSame([$list[$this->m], true], [$detail['photo_url'], $detail['photo_editable']]);
        $this->users->current = $this->m;
        $this->assertSame($list[$this->m], $this->call('GET', '/config')->data['user']['photo_url']);
        $this->users->current = $this->admin;

        $res = $this->call('DELETE', "/members/{$this->m}/photo");
        $this->assertSame([200, null], [$res->status, $res->data['photo_url']]);
        $this->assertNull($this->app->memberPhotos()->repository()->find($this->m));
    }

    public function testRejectsInvalidFiles(): void
    {
        $cases = [
            'keine Datei' => [null, 'Es ist kein Foto angekommen.'],
            'falsche Endung' => [self::file('foto.gif', self::image(10, 10)), 'Erlaubt sind Fotos als JPG, PNG oder WebP.'],
            'Inhalt passt nicht zur Endung' => [self::file('foto.png', self::image(10, 10, 'jpg')), 'Die Datei ist keine gültige .png-Datei.'],
            'kein Bild' => [self::file('foto.jpg', "\xFF\xD8\xFF" . 'kein Bild'), 'Die Datei ist keine gültige .jpg-Datei.'],
            'zu groß in Pixeln' => [self::file('foto.png', self::image(4097, 10, 'png')), 'Das Foto darf höchstens 4096 × 4096 Pixel groß sein.'],
            'zu groß in Bytes' => [['name' => 'foto.jpg', 'data' => str_repeat('A', 7 * 1024 * 1024 + 8)], 'Das Foto darf höchstens 5 MB groß sein.'],
        ];
        foreach ($cases as $label => [$file, $message]) {
            $res = $this->call('PUT', "/members/{$this->m}/photo", ['file' => $file]);
            $this->assertSame([400, $message], [$res->status, $res->data['message'] ?? null], $label);
        }
        $this->assertNull($this->app->memberPhotos()->repository()->find($this->m), 'nichts gespeichert');
        $this->assertSame(404, $this->call('PUT', '/members/999/photo', ['file' => self::file('a.jpg', self::image(10, 10))])->status);
    }

    public function testOnlyAccountManagersChangePhotos(): void
    {
        $manager = $this->users->add('verwalter', $this->roleWith('Kassierer', ['member', 'manage_points', 'manage_penalties']));
        $creator = $this->users->add('anleger', $this->roleWith('Anleger', ['member', 'create_users']));
        $photo = ['file' => self::file('a.jpg', self::image(10, 10))];

        // Wer nur Punkte und Strafen verwaltet, sieht Fotos, ändert sie aber nicht (auch nicht das eigene).
        $this->users->current = $manager;
        $this->assertSame(403, $this->call('PUT', "/members/{$this->m}/photo", $photo)->status);
        $this->assertSame(403, $this->call('PUT', "/members/$manager/photo", $photo)->status);
        $this->assertFalse($this->call('GET', "/members/{$this->m}")->data['photo_editable']);
        // Mitglieder ändern ihr Foto nicht selbst.
        $this->users->current = $this->m;
        $this->assertSame(403, $this->call('PUT', "/members/{$this->m}/photo", $photo)->status);

        // Wer Konten anlegt, ändert Fotos von Mitgliedern, nicht aber von Admins.
        $this->users->current = $creator;
        $this->assertSame(200, $this->call('PUT', "/members/{$this->m}/photo", $photo)->status);
        $this->assertSame(403, $this->call('PUT', "/members/{$this->admin}/photo", $photo)->status);
        $this->assertSame(403, $this->call('DELETE', "/members/{$this->admin}/photo")->status);
        $this->assertFalse($this->call('GET', "/members/{$this->admin}")->data['photo_editable']);
    }

    public function testDeliveryChecksViewer(): void
    {
        $this->call('PUT', "/members/{$this->m}/photo", ['file' => self::file('a.jpg', self::image(600, 600))]);
        $info = $this->app->memberPhotos()->repository()->find($this->m);
        $this->assertNotNull($info);
        $other = $this->users->add('anderes', Roles::MEMBER);
        $manager = $this->users->add('verwalter', $this->roleWith('Kassierer', ['member', 'manage_points', 'manage_penalties']));
        $photos = $this->app->memberPhotos();
        $deliver = fn (?int $viewer, mixed $v = null, mixed $s = '480'): array => $photos->forDelivery($viewer === null ? null : $this->users->find($viewer), $this->m, $v ?? $info['version'], $s);

        // Mit der öffentlichen Mitgliederliste (Issue #112) auch ohne Anmeldung, dann öffentlich zwischenzuspeichern.
        $public = $deliver(null);
        $this->assertSame([200, 'public, max-age=86400'], [$public['status'], $public['headers']['Cache-Control']]);
        $this->assertSame('public, no-cache', $deliver(null, 'alt')['headers']['Cache-Control']);
        $this->app->features()->update(['directory' => false]);
        $this->assertSame(401, $deliver(null)['status'], 'ohne Mitgliederliste nur mit Anmeldung');
        $this->assertSame(403, $deliver($other)['status'], 'ohne Mitgliederliste sehen andere Mitglieder das Foto nicht');
        $own = $deliver($this->m);
        $this->assertSame([200, 'image/jpeg', 'private, max-age=86400'], [$own['status'], $own['headers']['Content-Type'], $own['headers']['Cache-Control']]);
        $size = getimagesizefromstring($own['body'] ?? '');
        $this->assertNotFalse($size);
        $this->assertSame(480, $size[0]);
        $this->assertSame(200, $deliver($manager)['status'], 'wer die Mitglieder verwaltet, sieht es');
        $this->assertSame('private, no-cache', $deliver($manager, 'alt')['headers']['Cache-Control'], 'veraltete Fassung jedes Mal prüfen');
        $this->assertSame(404, $deliver($manager, null, '64')['status'], 'unbekannte Größe');
        $this->assertSame(404, $photos->forDelivery($this->users->find($manager), $other, 'x', '128')['status'], 'ohne Foto');
        $size = getimagesizefromstring($deliver($manager, null, '1024')['body'] ?? '');
        $this->assertNotFalse($size);
        $this->assertSame(600, $size[0], '1024: so groß wie hochgeladen, nie vergrößert');

        // Vor Datenbankversion 18 hochgeladen: ohne 1024er (die App nimmt dann die 480er), die anderen Größen wie bisher.
        $this->app->db->execute('UPDATE {member_photos} SET xlarge = NULL WHERE user_id = ?', [$this->m]);
        $this->assertSame(404, $deliver($manager, null, '1024')['status']);
        $this->assertSame(200, $deliver($manager, null, '480')['status']);
    }

    public function testOriginalResolutionForTheLargeView(): void
    {
        $exif = JpegMetadataTest::jpegWithMetadata(3000, 2000);
        $this->assertSame(200, $this->call('PUT', "/members/{$this->m}/photo", ['file' => self::file('gross.jpg', $exif)])->status);
        $repo = $this->app->memberPhotos()->repository();
        $original = (string) $repo->original($this->m);
        $info = getimagesizefromstring($original);
        $this->assertNotFalse($info);
        $this->assertSame([3000, 2000, 'image/jpeg'], [$info[0], $info[1], $info['mime']], 'volle Auflösung');
        $this->assertStringNotContainsString('GPSLatitude', $original, 'ohne Metadaten');
        foreach ([128, 480, 1024] as $size) {
            $this->assertStringNotContainsString('GPSLatitude', (string) $repo->image($this->m, $size));
        }
        $version = (string) $repo->find($this->m)['version'];
        $photos = $this->app->memberPhotos();
        $res = $photos->forDelivery(null, $this->m, $version, '4096');
        $this->assertSame([200, $original], [$res['status'], $res['body'] ?? null]);
        $this->assertStringEndsWith('&s=4096', $photos->urls(4096)[$this->m]);

        // Kleine Fotos: so groß wie hochgeladen, nie vergrößert.
        $this->call('PUT', "/members/{$this->m}/photo", ['file' => self::file('klein.png', self::image(300, 200, 'png'))]);
        $this->assertSame([300, 200], array_slice((array) getimagesizefromstring((string) $repo->original($this->m)), 0, 2));

        // Ohne Original (vor Datenbankversion 24 hochgeladen): 404, die Großansicht bleibt bei der 1024er.
        $this->app->db->execute('UPDATE {member_photos} SET original = NULL WHERE user_id = ?', [$this->m]);
        $version = (string) $repo->find($this->m)['version'];
        $this->assertSame(404, $photos->forDelivery(null, $this->m, $version, '4096')['status']);
        $this->assertSame(200, $photos->forDelivery(null, $this->m, $version, '1024')['status']);
    }

    /** Update einer bestehenden Datenbank (SQLite und MySQL): vorhandene Fotos bleiben, ohne Original; neue bekommen es. */
    public function testMigrationAddsOriginalToExistingPhotos(): void
    {
        foreach (['sqlite', getenv('KOMPANIE_TEST_MYSQL') ? 'mysql' : null] as $driver) {
            if ($driver === null) {
                continue;
            }
            $db = TestApp::connection($driver);
            try {
                $settings = new MemorySettings();
                $all = Migrations::all();
                (new Migrator($db, $settings, array_filter($all, static fn (int $v): bool => $v < 24, ARRAY_FILTER_USE_KEY)))->migrate();
                $this->assertNotContains('original', \KompanieApp\Core\Storage\Sql\Schema::columns($db, 'member_photos'), $driver);
                $old = base64_encode(self::image(64, 64));
                $db->insert('member_photos', ['user_id' => 2, 'version' => 'alt', 'small' => $old, 'large' => $old, 'xlarge' => $old, 'updated_by' => 1, 'updated_at' => '2026-01-01 00:00:00']);

                $users = new FakeUsers();
                $app = TestApp::create($db, $users, [], null, null, $settings);
                $this->assertSame(Migrations::latest(), (new Migrator($db, $settings))->current(), $driver);
                $this->assertContains('original', \KompanieApp\Core\Storage\Sql\Schema::columns($db, 'member_photos'), $driver);
                $repo = $app->memberPhotos()->repository();
                $this->assertSame('alt', $repo->find(2)['version'] ?? null, $driver);
                $this->assertSame(self::image(64, 64), $repo->image(2, 1024), $driver);
                $this->assertNull($repo->original(2), "$driver: vorhandenes Foto ohne Original");

                $users->current = $users->add('admin', Roles::ADMIN);
                $m = $users->add('mitglied', Roles::MEMBER);
                $res = $app->api()->handle(new Request('PUT', "/members/{$m}/photo", [], ['file' => self::file('neu.jpg', self::image(2000, 1500))]));
                $this->assertSame(200, $res->status, $driver);
                $this->assertSame([2000, 1500], array_slice((array) getimagesizefromstring((string) $repo->original($m)), 0, 2), $driver);
            } finally {
                TestApp::dropTables($db);
            }
        }
    }

    public function testDirectoryShowsPhotosOfMembersAndCandidatesToEveryone(): void
    {
        $candidate = $this->users->add('anwaerter', TestApp::candidateRole($this->app));
        $guest = $this->users->add('gast', Roles::GUEST);
        foreach ([$this->m, $guest] as $id) {
            $this->call('PUT', "/members/$id/photo", ['file' => self::file('a.jpg', self::image(300, 300))]);
        }
        $photos = $this->app->memberPhotos();
        $deliver = function (?int $viewer, int $owner) use ($photos): int {
            $version = $photos->repository()->find($owner)['version'] ?? '';
            return $photos->forDelivery($viewer === null ? null : $this->users->find($viewer), $owner, $version, '128')['status'];
        };

        $this->assertSame(200, $deliver($candidate, $this->m), 'Anwärter sieht das Foto eines Mitglieds');
        $this->assertSame(200, $deliver($guest, $this->m), 'Gäste sehen die Liste (Issue #112)');
        $this->assertSame(200, $deliver(null, $this->m), 'ohne Anmeldung ebenso');
        $this->assertSame(403, $deliver($candidate, $guest), 'Gäste stehen nicht in der Liste: Foto bleibt verborgen');
        $this->assertSame(401, $deliver(null, $guest));
        // Ehemalige stehen im Tab neben der Liste: ihr Foto bleibt öffentlich, auch als Gast-Konto.
        $former = $this->users->add('ehemalig', Roles::MEMBER);
        $this->call('PUT', "/members/$former/photo", ['file' => self::file('a.jpg', self::image(300, 300))]);
        $this->call('PUT', "/members/$former/former", ['reason' => 'left', 'joined_on' => '', 'left_on' => '2020']);
        $this->assertSame(200, $deliver(null, $former), 'Ehemalige: Foto öffentlich');
        $this->app->features()->update(['directory' => false]);
        $this->assertSame(403, $deliver($candidate, $this->m), 'Bereich aus');
        $this->assertSame(401, $deliver(null, $this->m), 'Bereich aus');
    }

    public function testPhotoWhenCreatingAccounts(): void
    {
        $jpg = self::file('neu.jpg', self::image(800, 600));
        $res = $this->call('POST', '/users', ['first_name' => 'Erika', 'last_name' => 'Muster', 'photo' => $jpg]);
        $this->assertSame(201, $res->status, (string) json_encode($res->data));
        $this->assertSame($this->app->memberPhotos()->url((int) $res->data['id']), $res->data['photo_url']);
        $this->assertNotNull($res->data['photo_url']);

        // Ungültiges Foto: Das Konto wird gar nicht erst angelegt.
        $before = count($this->users->list(true));
        $res = $this->call('POST', '/users', ['first_name' => 'Kaputt', 'photo' => self::file('x.png', 'kein Bild')]);
        $this->assertSame(400, $res->status);
        $this->assertCount($before, $this->users->list(true));

        // Mehrere: vorab geprüft, mit Zeile; sonst je Person mit Foto.
        $res = $this->call('POST', '/users/bulk', ['people' => [['first_name' => 'A'], ['first_name' => 'B', 'photo' => self::file('x.png', 'kein Bild')]]]);
        $this->assertSame(400, $res->status);
        $this->assertStringStartsWith('Zeile 2 (B): ', (string) $res->data['message']);
        $this->assertCount($before, $this->users->list(true));
        $res = $this->call('POST', '/users/bulk', ['people' => [['first_name' => 'A'], ['first_name' => 'B', 'photo' => $jpg]]]);
        $this->assertSame(201, $res->status, (string) json_encode($res->data));
        $this->assertNull($res->data[0]['photo_url']);
        $this->assertNotNull($res->data[1]['photo_url']);
    }

    public function testDeletedWithAccountAndIncludedInBackup(): void
    {
        $this->call('PUT', "/members/{$this->m}/photo", ['file' => self::file('a.jpg', self::image(10, 10))]);
        $this->assertContains('member_photos', Backup::CORE_TABLES);
        $this->assertSame(200, $this->call('DELETE', "/members/{$this->m}")->status);
        $this->assertNull($this->app->memberPhotos()->repository()->find($this->m));
    }
}
