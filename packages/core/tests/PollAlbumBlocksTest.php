<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Tests\Support\DriveMock;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** Blöcke „Umfrage“ (poll) und „Album“ (album): nur mit den Rechten des jeweiligen Bereichs. */
final class PollAlbumBlocksTest extends TestCase
{
    private string $dir;
    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $member;
    private int $guest;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kompanie-blocks-' . bin2hex(random_bytes(4));
        $this->users = new FakeUsers();
        $this->app = TestApp::create(TestApp::connection(), $this->users, [], (new DriveMock())->http(), $this->dir, null, null, ['pages' => true, 'board' => true, 'polls' => true, 'gallery' => true]);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->member = $this->users->add('anna', Roles::MEMBER);
        $this->guest = $this->users->add('gast', Roles::GUEST);
        $this->users->current = $this->admin;
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
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
    private function ok(string $method, string $path, array $body = [], ?int $as = -1): array
    {
        $res = $this->call($method, $path, $body, $as);
        $this->assertLessThan(300, $res->status, $method . ' ' . $path . ': ' . json_encode($res->data));
        return (array) $res->data;
    }

    public function testPoll(): void
    {
        $poll = $this->ok('POST', '/polls', ['title' => 'Grillfest am Samstag?', 'type' => 'single', 'options' => ['Ja', 'Nein']]);
        $page = $this->ok('POST', '/pages', ['title' => 'U', 'audience' => 'public', 'blocks' => [['type' => 'text', 'html' => '<p>x</p>'], ['type' => 'poll', 'poll_id' => $poll['id']]]]);
        $this->assertSame(['id' => $poll['id'], 'title' => 'Grillfest am Samstag?', 'status' => 'running', 'ends_at' => $poll['ends_at'], 'has_voted' => false], $page['blocks'][1]['poll']);
        $member = $this->ok('GET', "/pages/{$page['id']}", [], $this->member)['blocks'];
        $this->assertSame('Grillfest am Samstag?', $member[1]['poll']['title']);
        foreach ([null, $this->guest] as $who) {
            $this->assertSame(['text'], array_column($this->ok('GET', "/pages/{$page['id']}", [], $who)['blocks'], 'type'), 'ohne Teilnahme an Umfragen fällt der Block weg');
        }
        $this->assertStringStartsWith('Umfrage: Grillfest am Samstag? – https://example.org/app/#/umfragen/', $this->app->blocks()->plain([['type' => 'poll', 'poll_id' => $poll['id']]], true, $this->users->find($this->member)));
        $this->assertSame(400, $this->call('POST', '/pages', ['title' => 'X', 'blocks' => [['type' => 'poll', 'poll_id' => 99999]]])->status);
        // Gelöscht: für Mitglieder weg, für die Pflege mit poll null
        $this->ok('DELETE', "/polls/{$poll['id']}");
        $this->assertSame(['text'], array_column($this->ok('GET', "/pages/{$page['id']}", [], $this->member)['blocks'], 'type'));
        $this->assertNull($this->ok('GET', "/pages/{$page['id']}")['blocks'][1]['poll']);
    }

    public function testAlbum(): void
    {
        $this->ok('PATCH', '/admin/settings', ['gallery_source' => 'server', 'gallery_store' => 'server']);
        $album = $this->ok('POST', '/admin/gallery/albums', ['title' => 'Sommerfest', 'year' => 2026]);
        $jpeg = function (): array {
            $img = imagecreatetruecolor(40, 30);
            ob_start();
            imagejpeg($img);
            return ['name' => 'bild.jpg', 'data' => base64_encode((string) ob_get_clean())];
        };
        for ($i = 0; $i < 3; $i++) {
            $this->ok('POST', "/admin/gallery/albums/{$album['id']}/photos", ['file' => $jpeg()]);
        }
        $this->ok('POST', "/admin/gallery/albums/{$album['id']}/photos", ['file' => $jpeg(), 'intern' => true]);
        $page = $this->ok('POST', '/pages', ['title' => 'A', 'audience' => 'public', 'blocks' => [['type' => 'album', 'album_id' => $album['id'], 'count' => 2]]]);
        $b = $page['blocks'][0];
        $this->assertSame([$album['id'], 'Sommerfest', 4, 2], [$b['album']['id'], $b['album']['title'], $b['album']['total'], count($b['album']['photos'])]);
        $public = $this->ok('GET', "/pages/{$page['id']}", [], null)['blocks'][0]['album'];
        $this->assertSame(3, $public['total'], 'ohne Anmeldung ohne interne Bilder');
        $this->assertSame(400, $this->call('POST', '/pages', ['title' => 'X', 'blocks' => [['type' => 'album', 'album_id' => 99999]]])->status);
        $this->assertSame(400, $this->call('POST', '/pages', ['title' => 'X', 'blocks' => [['type' => 'album', 'album_id' => $album['id'], 'count' => 13]]])->status);
        $this->assertStringEndsWith('#/galerie/' . $album['id'], $this->app->blocks()->plain([['type' => 'album', 'album_id' => $album['id']]], true));
        $this->app->features()->update(['gallery' => false]);
        $this->assertSame([], $this->ok('GET', "/pages/{$page['id']}", [], null)['blocks']);
    }
}
