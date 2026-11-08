<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\GallerySubmissionService;
use KompanieApp\Core\Domain\PersonalDataExport;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Push\PushService;
use KompanieApp\Core\Push\WebPushCrypto;
use KompanieApp\Core\Storage\Backup;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * Bilder einreichen und freigeben (Issue #286): Rechte submit_gallery und review_gallery, Einverständnis, Prüfen und
 * Neukodieren wie beim Hochladen, freigeben (öffentlich oder intern), ablehnen mit Grund (Datei sofort weg),
 * zurückziehen, wieder entfernen, Benachrichtigungen, Konto löschen, Auskunft, Sicherung, Standardrolle.
 */
final class GallerySubmissionTest extends TestCase
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
        $this->dir = sys_get_temp_dir() . '/kompanie-submit-' . bin2hex(random_bytes(4));
        $this->users = new FakeUsers();
        $this->app = TestApp::create(TestApp::connection(), $this->users, [], null, $this->dir);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->sub = $this->users->add('einreicher', Roles::MEMBER);
        $this->rev = $this->users->add('pruefer', Roles::MEMBER);
        $this->grant($this->sub, ['submit_gallery']);
        $this->grant($this->rev, ['review_gallery']);
        $this->users->current = $this->admin;
        $this->ok('PATCH', '/admin/settings', ['gallery_source' => 'server']);
        $this->album = (int) $this->ok('POST', '/admin/gallery/albums', ['title' => 'Sommerfest', 'year' => 2026])['id'];
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

    private function jpeg(): string
    {
        $img = imagecreatetruecolor(40, 30);
        ob_start();
        imagejpeg($img);
        $jpeg = (string) ob_get_clean();
        $exif = "Exif\0\0" . 'MM' . "\0\x2A\0\0\0\x08" . 'GPSLatitude 51.0';
        return substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif . substr($jpeg, 2);
    }

    private function submit(string $name = 'Fest.jpg', int $user = 0): array
    {
        $this->users->current = $user ?: $this->sub;
        return $this->ok('POST', '/gallery/submissions', ['album_id' => $this->album, 'file' => ['name' => $name, 'data' => base64_encode($this->jpeg())], 'consent' => true]);
    }

    /** @return list<string> */
    private function stored(): array
    {
        return array_map('basename', glob($this->dir . '/private/einreichungen/*') ?: []);
    }

    /** @param list<string> $caps */
    private function grant(int $user, array $caps): void
    {
        $this->app->roles()->setOverrides($this->users->find($this->admin), $user, $caps, []);
    }

    /** @return list<string> Nachrichten in der Warteschlange an diese Person */
    private function queued(int $user): array
    {
        return array_column($this->app->db->select('SELECT q.payload FROM {push_queue} q JOIN {push_subscriptions} s ON s.id = q.subscription_id WHERE s.user_id = ? ORDER BY q.id', [$user]), 'payload');
    }

    private function subscribe(int $user, string $type): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->assertNotFalse($key);
        $keys = ['p256dh' => WebPushCrypto::b64uEncode(WebPushCrypto::rawPublic($key)), 'auth' => WebPushCrypto::b64uEncode(random_bytes(16))];
        $this->app->push()->subscribe($this->users->find($user), 'https://fcm.googleapis.com/fcm/send/' . $user, $keys, [$type], null, '1.2.3.4');
    }

    public function testSubmitReviewApproveRejectWithdraw(): void
    {
        // Rechte
        $this->users->current = $this->rev;
        $this->assertSame(403, $this->call('POST', '/gallery/submissions', ['album_id' => $this->album])->status, 'nur mit submit_gallery');
        $this->users->current = $this->sub;
        $this->assertSame(403, $this->call('GET', '/gallery/review')->status, 'nur mit review_gallery');
        $own = $this->ok('GET', '/gallery/submissions');
        $this->assertSame([['id' => $this->album, 'title' => 'Sommerfest', 'year' => '2026']], $own['albums']);

        // Einverständnis und Format
        $data = ['name' => 'a.jpg', 'data' => base64_encode($this->jpeg())];
        $this->assertSame(400, $this->call('POST', '/gallery/submissions', ['album_id' => $this->album, 'file' => $data])->status, 'ohne Einverständnis');
        $this->assertSame(400, $this->call('POST', '/gallery/submissions', ['album_id' => $this->album, 'file' => ['name' => 'a.gif', 'data' => $data['data']], 'consent' => true])->status);
        $this->assertSame(404, $this->call('POST', '/gallery/submissions', ['album_id' => 999, 'file' => $data, 'consent' => true])->status);

        $this->subscribe($this->rev, PushService::TYPE_GALLERY_REVIEW);
        $this->subscribe($this->sub, PushService::TYPE_GALLERY_SUBMISSIONS);
        $a = $this->submit('Erstes.jpeg');
        $this->assertSame('pending', $a['status']);
        $this->assertSame('Erstes.jpg', $a['name']);
        $this->assertStringStartsWith('data:image/jpeg;base64,', (string) $a['thumb']);
        $b = $this->submit('Zweites.jpg');
        $c = $this->submit('Drittes.jpg');
        $this->assertCount(3, $this->stored());
        foreach ($this->stored() as $f) {
            $this->assertStringNotContainsString('GPSLatitude', (string) file_get_contents($this->dir . '/private/einreichungen/' . $f));
        }
        $this->assertCount(1, $this->queued($this->rev), 'ein Schub, eine Benachrichtigung');
        $this->assertStringContainsString('Einreicher hat Bilder für „Sommerfest“ eingereicht.', json_decode($this->queued($this->rev)[0], true)['body']);

        // Zurückziehen: nur eigene, nur offene
        $this->users->current = $this->rev;
        $this->assertSame(403, $this->call('DELETE', "/gallery/submissions/{$c['id']}")->status);
        $this->users->current = $this->sub;
        $this->ok('DELETE', "/gallery/submissions/{$c['id']}");
        $this->assertCount(2, $this->stored());

        // Prüfen
        $this->users->current = $this->rev;
        $review = $this->ok('GET', '/gallery/review');
        $this->assertSame([(int) $a['id'], (int) $b['id']], array_column($review['pending'], 'id'));
        $this->assertSame('Einreicher', $review['pending'][0]['user_name']);
        $this->assertSame(400, $this->call('POST', "/gallery/review/{$b['id']}/reject", ['reason' => ''])->status, 'Grund nötig');

        $approved = $this->ok('POST', "/gallery/review/{$a['id']}/approve", ['intern' => true]);
        $this->assertSame('approved', $approved['status']);
        $this->ok('POST', "/gallery/review/{$b['id']}/reject", ['reason' => 'Unscharf']);
        $this->assertCount(1, $this->stored(), 'abgelehnte Datei sofort gelöscht');
        $this->assertSame(404, $this->call('POST', "/gallery/review/{$b['id']}/approve")->status, 'nicht mehr offen');

        $this->app->gallery()->processPending(30);
        $this->assertNull($this->app->gallery()->album($this->album, false), 'intern freigegeben: für Gäste kein Bild');
        $this->assertCount(1, $this->app->gallery()->album($this->album, true)['photos']);
        $msgs = $this->queued($this->sub);
        $this->assertCount(2, $msgs);
        $this->assertStringContainsString('Unscharf', implode(' ', $msgs));

        // Eigene Liste mit Status und Grund
        $this->users->current = $this->sub;
        $mine = array_column($this->ok('GET', '/gallery/submissions')['submissions'], null, 'id');
        $this->assertSame('approved', $mine[$a['id']]['status']);
        $this->assertSame(['rejected', 'Unscharf', null], [$mine[$b['id']]['status'], $mine[$b['id']]['reason'], $mine[$b['id']]['thumb']]);

        // Wieder entfernen
        $this->users->current = $this->rev;
        $this->ok('POST', "/gallery/review/{$a['id']}/remove");
        $this->assertSame([], $this->app->gallery()->album($this->album, true)['photos'] ?? []);
        $this->assertSame([], $this->stored());
        $this->users->current = $this->sub;
        $mine = array_column($this->ok('GET', '/gallery/submissions')['submissions'], null, 'id');
        $this->assertSame(['rejected', 'Wieder entfernt.'], [$mine[$a['id']]['status'], $mine[$a['id']]['reason']]);
    }

    public function testOwnApprovalNeedsBothRightsAndAdminDeleteMarksRemoved(): void
    {
        $both = $this->users->add('beides', Roles::MEMBER);
        $this->grant($both, ['submit_gallery', 'review_gallery']);
        $s = $this->submit('Selbst.jpg', $both);
        $this->users->current = $both;
        $this->ok('POST', "/gallery/review/{$s['id']}/approve", ['intern' => false]);
        $photoId = (int) $this->app->db->scalar('SELECT photo_id FROM {gallery_submissions} WHERE id = ?', [$s['id']]);

        // Löscht ein Admin das Bild unter Einstellungen → Galerie, gilt die Einreichung als entfernt.
        $this->users->current = $this->admin;
        $this->ok('DELETE', "/admin/gallery/photos/{$photoId}");
        $this->assertSame('rejected', $this->app->db->scalar('SELECT status FROM {gallery_submissions} WHERE id = ?', [$s['id']]));

        // Album der App löschen: offene Einreichungen darin verschwinden samt Datei.
        $this->submit('Offen.jpg');
        $this->users->current = $this->admin;
        $this->ok('DELETE', "/admin/gallery/albums/{$this->album}");
        $this->assertSame(0, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {gallery_submissions}'));
        $this->assertSame([], $this->stored());
    }

    public function testDeleteAccountExportBackupAndOrphans(): void
    {
        $a = $this->submit('Frei.jpg');
        $this->submit('Offen.jpg');
        $this->users->current = $this->rev;
        $this->ok('POST', "/gallery/review/{$a['id']}/approve");

        // Auskunft nennt die Einreichungen, ohne Ablageort
        $export = (new PersonalDataExport($this->app))->export($this->users->find($this->sub), $this->sub);
        $this->assertCount(2, $export['data']['gallery_submissions']);
        $this->assertArrayNotHasKey('file_key', $export['data']['gallery_submissions'][0]);

        // Sicherung: Bild und offene Einreichung
        $tables = (new Backup($this->app->db))->dump(['photos', 'gallery_submissions']);
        $this->assertCount(2, Backup::dumpFiles($this->app->files, $tables));

        // Konto löschen: offene Einreichung samt Datei weg, freigegebenes Bild bleibt
        $this->app->userDeleted($this->sub);
        $this->assertSame(0, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {gallery_submissions}'));
        $this->assertCount(1, $this->stored());
        $this->assertSame(1, (int) $this->app->db->scalar("SELECT COUNT(*) FROM {photos} WHERE source = 'upload'"));

        // Dateien ohne Verweis: erst nach einer Stunde aufgeräumt
        $orphan = $this->dir . '/private/einreichungen/' . $this->album . '-' . str_repeat('a', 24) . '.jpg';
        file_put_contents($orphan, 'x');
        $this->assertSame(0, $this->app->gallerySubmissions()->pruneOrphans());
        touch($orphan, time() - 7200);
        $this->assertSame(1, $this->app->gallerySubmissions()->pruneOrphans());
        $this->assertCount(1, $this->stored(), 'das freigegebene Bild bleibt');
    }

    public function testDefaultRoleOnceForExistingInstallationsWithGallery(): void
    {
        $this->app->settings->set(GallerySubmissionService::SETUP_SETTING, false);
        $this->app->gallerySubmissions()->initialize(true);
        $this->assertSame(TestApp::caps(['member', 'review_gallery']), $this->app->roles()->find('gallery')['capabilities'] ?? null);
        $this->assertSame('Galerie verwalten', $this->app->roles()->find('gallery')['label'] ?? null);
        $this->assertTrue($this->app->settings->get(GallerySubmissionService::SETUP_SETTING));
    }
}
