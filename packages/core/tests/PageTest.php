<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Storage\Backup;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * Eigene Seiten (Issue #287): Lesen nach audience, Pflege mit manage_pages, Konflikt beim Speichern, Reihenfolge,
 * Bilder wie die Seite sichtbar, Bereinigung des Textes, Standardrolle, Navigation, Sicherung.
 */
final class PageTest extends TestCase
{
    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $member;
    private int $guest;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->app = TestApp::create(TestApp::connection(), $this->users);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->member = $this->users->add('anna', Roles::MEMBER);
        $this->guest = $this->users->add('gast', Roles::GUEST);
        $this->users->current = $this->admin;
    }

    private function call(string $method, string $path, array $body = [], ?int $as = -1): Response
    {
        if ($as !== -1) {
            $this->users->current = $as;
        }
        $res = $this->app->api()->handle(new Request($method, $path, [], $body));
        $this->users->current = $this->admin;
        return $res;
    }

    /** @return array<string, mixed> */
    private function ok(string $method, string $path, array $body = []): array
    {
        $res = $this->call($method, $path, $body);
        $this->assertLessThan(300, $res->status, $method . ' ' . $path . ': ' . json_encode($res->data));
        return (array) $res->data;
    }

    public function testAudienceManageConflictOrder(): void
    {
        $pub = $this->ok('POST', '/pages', ['title' => 'Anfahrt', 'audience' => 'public', 'body' => '<p>Über die <b>Brücke</b></p><script>alert(1)</script><p style="color:red" onclick="x()">Hallo</p>']);
        $this->assertStringNotContainsString('script', $pub['body']);
        $this->assertStringNotContainsString('onclick', $pub['body']);
        $this->assertStringContainsString('<b>Brücke</b>', $pub['body']);
        $mem = $this->ok('POST', '/pages', ['title' => 'Uniform', 'body' => '<p>Nur intern</p>']);
        $this->assertSame('members', $mem['audience'], 'Vorgabe: nur Mitglieder');
        foreach ([['title' => ''], ['title' => str_repeat('x', 101)], ['title' => 'X', 'audience' => 'alle'], ['body' => '<p>ohne Titel</p>']] as $bad) {
            $this->assertSame(400, $this->call('POST', '/pages', $bad)->status, json_encode($bad));
        }

        // Lesen nach audience
        $this->assertSame(['Anfahrt'], array_column($this->call('GET', '/pages', [], null)->data['pages'], 'title'), 'ohne Anmeldung');
        $this->assertSame(['Anfahrt'], array_column($this->call('GET', '/pages', [], $this->guest)->data['pages'], 'title'), 'Gast');
        $this->assertSame(['Anfahrt', 'Uniform'], array_column($this->call('GET', '/pages', [], $this->member)->data['pages'], 'title'));
        $this->assertSame(404, $this->call('GET', "/pages/{$mem['id']}", [], null)->status);
        $this->assertSame(404, $this->call('GET', "/pages/{$mem['id']}", [], $this->guest)->status);
        $this->assertSame('<p>Nur intern</p>', $this->call('GET', "/pages/{$mem['id']}", [], $this->member)->data['body']);
        $this->assertArrayNotHasKey('updated_at', $this->call('GET', "/pages/{$mem['id']}", [], $this->member)->data, 'Stand nur für die Pflege');
        $this->assertSame(['Anfahrt'], array_column($this->call('GET', '/config', [], null)->data['pages'], 'title'), 'Kacheln in /config');
        $this->assertSame(['Anfahrt', 'Uniform'], array_column($this->call('GET', '/config', [], $this->member)->data['pages'], 'title'));

        // Pflegen nur mit manage_pages
        $this->assertSame(403, $this->call('POST', '/pages', ['title' => 'X'], $this->member)->status);
        $this->assertSame(403, $this->call('PATCH', "/pages/{$pub['id']}", ['title' => 'X'], $this->member)->status);

        // Konflikt: jemand hat dazwischen gespeichert
        $loaded = $this->ok('GET', "/pages/{$pub['id']}")['updated_at'];
        $this->app->db->update('app_pages', ['updated_at' => '2020-01-01 00:00:00'], ['id' => $pub['id']]);
        $res = $this->call('PATCH', "/pages/{$pub['id']}", ['title' => 'Neu', 'updated_at' => $loaded]);
        $this->assertSame(409, $res->status);
        $this->assertSame('conflict', $res->data['code'] ?? null);
        $fresh = $this->ok('GET', "/pages/{$pub['id']}")['updated_at'];
        $this->assertSame('Anfahrt und Parken', $this->ok('PATCH', "/pages/{$pub['id']}", ['title' => 'Anfahrt und Parken', 'updated_at' => $fresh])['title']);

        // Reihenfolge
        $this->assertSame(['Uniform', 'Anfahrt und Parken'], array_column($this->ok('PUT', '/pages/order', ['ids' => [$mem['id'], $pub['id']]])['pages'], 'title'));
        $this->assertSame(400, $this->call('PUT', '/pages/order', ['ids' => [$mem['id']]])->status);

        // Bereich aus: keine Seiten, auch nicht für Admins
        $this->app->features()->update(['pages' => false]);
        $this->assertSame(403, $this->call('GET', '/pages')->status);
        $this->assertSame([], $this->call('GET', '/config')->data['pages']);
        $this->app->features()->update(['pages' => true]);

        $this->ok('DELETE', "/pages/{$mem['id']}");
        $this->assertSame(404, $this->call('GET', "/pages/{$mem['id']}")->status);
    }

    public function testImagesFollowTheAudience(): void
    {
        $png = imagecreatetruecolor(4, 3);
        ob_start();
        imagepng($png);
        $bytes = (string) ob_get_clean();
        $uid = $this->call('POST', '/rich-images', ['file' => ['name' => 'bild.png', 'data' => base64_encode($bytes)]])->data['uid'];
        $d = fn (?int $user): int => $this->app->richImages()->forDelivery($user === null ? null : $this->users->find($user), $uid)['status'];

        $page = $this->ok('POST', '/pages', ['title' => 'Geschichte', 'audience' => 'members', 'body' => '<p>Bild:</p><img data-image="' . $uid . '">']);
        $this->assertStringContainsString('src="https://example.org/app/bild/' . $uid . '"', $page['body']);
        $this->assertSame(401, $d(null), 'nur Mitglieder: ohne Anmeldung nicht');
        $this->assertSame(200, $d($this->member));
        $this->assertSame(403, $d($this->guest));

        $this->ok('PATCH', "/pages/{$page['id']}", ['audience' => 'public']);
        $this->assertSame(200, $d(null), 'öffentlich: ohne Anmeldung');

        $this->ok('PATCH', "/pages/{$page['id']}", ['body' => '<p>ohne Bild</p>']);
        $this->assertNull($this->app->richImages()->repository()->find($uid), 'nicht mehr im Text: gelöscht');
    }

    public function testDefaultRoleNavigationAndBackup(): void
    {
        $this->assertSame(TestApp::caps(['member', 'manage_pages']), $this->app->roles()->find('pages')['capabilities'] ?? null);
        $this->assertSame('Seiten verwalten', $this->app->roles()->find('pages')['label'] ?? null);
        $this->ok('PUT', '/admin/navigation', ['navigation' => ['items' => ['termine', ['label' => 'Infos', 'icon' => 'seiten', 'items' => ['seiten']]], 'hidden' => []]]);
        $this->assertSame(['seiten'], $this->call('GET', '/config', [], $this->member)->data['navigation']['items'][1]['items']);

        $this->ok('POST', '/pages', ['title' => 'Festprogramm', 'body' => '<p>Samstag</p>']);
        $this->assertContains('app_pages', Backup::CORE_TABLES);
        $backup = new Backup($this->app->db);
        $tables = $backup->dump(['app_pages']);
        $this->app->db->execute('DELETE FROM {app_pages}');
        $backup->restore($tables, ['app_pages']);
        $this->assertSame(['Festprogramm'], array_column($this->ok('GET', '/pages')['pages'], 'title'));
    }
}
