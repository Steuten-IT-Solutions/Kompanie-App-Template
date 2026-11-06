<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * Fotos für Amtsinhaber ohne Konto (Issue #283): je Eintrag einer Amtszeit, öffentlich wie die Ämter, dasselbe Foto in
 * mehreren Amtszeiten, Aufräumen, wenn es in keiner mehr steht; bei Konten bleibt das Foto des Kontos maßgeblich.
 */
final class OfficeHolderPhotoTest extends TestCase
{
    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $m;
    private int $office;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $features = ['events' => false, 'calendar' => false, 'news' => false, 'gallery' => false, 'points' => false, 'penalties' => false, 'ranks' => false, 'directory' => true, 'offices' => true];
        $this->app = TestApp::create(TestApp::connection(), $this->users, [], null, null, null, new FixedClock(new \DateTimeImmutable('2026-10-20 12:00:00')), $features);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->m = $this->users->add('mitglied', Roles::MEMBER);
        $this->users->current = $this->admin;
        $this->office = (int) $this->ok('POST', '/offices', ['title' => 'Hofdamen'])['id'];
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

    private function image(): string
    {
        $img = imagecreatetruecolor(6, 4);
        ob_start();
        imagepng($img);
        return (string) $this->ok('POST', '/rich-images', ['file' => ['name' => 'foto.png', 'data' => base64_encode((string) ob_get_clean())]])['uid'];
    }

    /** @return list<array<string, mixed>> Personen der Amtszeiten des Amts im Zeitstrahl, neueste zuerst */
    private function holders(): array
    {
        $out = [];
        foreach ($this->ok('GET', '/offices')['offices'] as $o) {
            if ($o['id'] === $this->office) {
                foreach ($o['terms'] as $t) {
                    $out[] = $t['holders'];
                }
            }
        }
        return $out;
    }

    /** @return list<string> */
    private function pool(): array
    {
        return array_map(static fn (array $r): string => (string) $r['uid'], $this->app->db->select("SELECT uid FROM {rich_images} WHERE owner_type = 'office_holder' ORDER BY uid"));
    }

    public function testPhotoForFreeNameIsPublicLikeTheOffices(): void
    {
        $uid = $this->image();
        $term = $this->ok('POST', "/offices/{$this->office}/terms", ['start_year' => 2024, 'holders' => [
            ['name' => 'Erika Muster', 'image_uid' => $uid],
            ['user_id' => $this->m, 'image_uid' => $this->image()],
        ]]);
        $this->assertSame([$uid], $this->pool(), 'Entwurf wird Foto der Ämter; beim Konto zählt kein eigenes Foto');
        $this->assertSame($uid, $term['holders'][0]['image_uid']);
        $this->assertArrayNotHasKey('image_uid', $term['holders'][1]);

        $this->assertSame('https://example.org/app/bild/' . $uid, $this->holders()[0][0]['photo_url']);
        $this->assertSame($uid, $this->holders()[0][0]['image_uid'], 'Pflege sieht die Kennung');
        $this->users->current = null;
        $this->assertSame('https://example.org/app/bild/' . $uid, $this->holders()[0][0]['photo_url']);
        $this->assertArrayNotHasKey('image_uid', $this->holders()[0][0]);
        $this->assertSame(200, $this->app->richImages()->forDelivery(null, $uid)['status']);
        $this->app->roles()->update(Roles::GUEST, ['capabilities' => ['view_news']]);
        $this->assertSame(401, $this->app->richImages()->forDelivery(null, $uid)['status'], 'ohne view_offices nicht öffentlich');

        $this->users->current = $this->admin;
        $this->assertSame(400, $this->call('POST', "/offices/{$this->office}/terms", ['start_year' => 2020, 'holders' => [['name' => 'X', 'image_uid' => str_repeat('a', 32)]]])->status, 'unbekanntes Foto');
        $this->assertSame(400, $this->call('POST', "/offices/{$this->office}/terms", ['start_year' => 2020, 'holders' => [['name' => 'X', 'image_uid' => '../x']]])->status, 'ungültige Kennung');
    }

    public function testSamePhotoInSeveralTermsAndCleanup(): void
    {
        $uid = $this->image();
        $a = (int) $this->ok('POST', "/offices/{$this->office}/terms", ['start_year' => 2020, 'end_year' => 2021, 'holders' => [['name' => 'Erika', 'image_uid' => $uid]]])['id'];
        // Übernahme in einer späteren Amtszeit: dasselbe Foto, keine Kopie
        $b = (int) $this->ok('POST', "/offices/{$this->office}/terms", ['start_year' => 2024, 'holders' => [['name' => 'Erika', 'image_uid' => $uid]]])['id'];
        $this->assertSame([$uid], $this->pool());

        // Ändern ohne holders behält das Foto, auch beim Ausscheiden eines Kontos aus einer gemeinsamen Amtszeit
        $this->ok('PATCH', "/office-terms/{$a}", ['note' => 'Zusatz']);
        $this->ok('PATCH', "/office-terms/{$b}", ['holders' => [['name' => 'Erika', 'image_uid' => $uid], ['user_id' => $this->m]]]);
        $this->ok('PUT', "/members/{$this->m}/former", ['reason' => 'left', 'left_on' => '2026-01-31']);
        $this->assertSame([$uid], array_values(array_unique(array_filter(array_map(static fn (array $hs): ?string => $hs[0]['image_uid'] ?? null, $this->holders())))));

        // Foto entfernen: steht noch in anderen Amtszeiten, bleibt also
        $this->ok('PATCH', "/office-terms/{$a}", ['holders' => [['name' => 'Erika']]]);
        $this->assertSame([$uid], $this->pool());
        // in keiner Amtszeit mehr: gelöscht
        foreach ($this->ok('GET', '/offices')['offices'] as $o) {
            foreach ($o['id'] === $this->office ? $o['terms'] : [] as $t) {
                $this->ok('DELETE', "/office-terms/{$t['id']}");
            }
        }
        $this->assertSame([], $this->pool());
        $this->assertSame(401, $this->app->richImages()->forDelivery(null, $uid)['status'], 'gelöscht');
    }

    public function testDeletingTheOfficeRemovesItsPhotos(): void
    {
        $this->ok('POST', "/offices/{$this->office}/terms", ['start_year' => 2024, 'holders' => [['name' => 'Erika', 'image_uid' => $this->image()]]]);
        $this->assertCount(1, $this->pool());
        $this->ok('DELETE', "/offices/{$this->office}");
        $this->assertSame([], $this->pool());
    }
}
