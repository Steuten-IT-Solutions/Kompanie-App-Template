<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Infra\RemoteNewsSource;
use KompanieApp\Core\Push\PushService;
use KompanieApp\Core\Storage\Backup;
use KompanieApp\Core\Infra\ArrayCache;
use KompanieApp\Core\Tests\Support\FakeHttpClient;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\MemorySettings;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * News in der App (Quelle „app“, NewsService): Quelle wählen, schreiben, ändern, löschen mit manage_news, Lesen mit
 * view_news, Bilder, Standardrolle, Benachrichtigungen, Feed-Installationen unverändert, Sicherung.
 */
final class NewsInAppTest extends TestCase
{
    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $member;
    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->clock = new FixedClock(new \DateTimeImmutable('2026-11-06 12:00:00', new \DateTimeZone('UTC')));
        $this->app = TestApp::create(TestApp::connection(), $this->users, clock: $this->clock);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->member = $this->users->add('anna', Roles::MEMBER);
        $this->users->current = $this->admin;
    }

    private function call(string $method, string $path, array $body = [], ?int $as = -1, array $query = []): Response
    {
        if ($as !== -1) {
            $this->users->current = $as;
        }
        $res = $this->app->api()->handle(new Request($method, $path, $query, $body));
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

    private function useApp(): void
    {
        $this->ok('PATCH', '/admin/settings', ['news_source' => 'app']);
    }

    public function testWordPressKeepsSiteUntilSwitched(): void
    {
        // Ohne Einstellung wie bisher: Beiträge der eigenen Seite, nichts zu schreiben.
        $news = $this->ok('GET', '/admin/settings')['news'];
        $this->assertSame(['source' => 'site', 'url' => '', 'sources' => ['site', 'app']], $news);
        $this->assertFalse($this->call('GET', '/config')->data['newsInApp']);
        $res = $this->call('POST', '/news', ['title' => 'X']);
        $this->assertSame(409, $res->status, 'ohne Quelle app kein Schreiben');
        $this->assertSame('not_in_app', $res->data['code'] ?? null);
        $this->assertSame(400, $this->call('PATCH', '/admin/settings', ['news_source' => 'rss'])->status, 'Feed gibt es im Plugin nicht');
        $this->assertNull($this->app->roles()->find('news'), 'Rolle erst mit der Quelle app');

        $this->useApp();
        $this->assertSame('app', $this->ok('GET', '/admin/settings')['news']['source']);
        $this->assertTrue($this->call('GET', '/config')->data['newsInApp']);
        $this->assertSame(TestApp::caps(['member', 'manage_news']), $this->app->roles()->find('news')['capabilities'] ?? null);
        $this->assertSame('News verwalten', $this->app->roles()->find('news')['label'] ?? null);

        // Zurück: Rolle verschwindet (niemand hat sie), die Beiträge bleiben gespeichert.
        $this->ok('POST', '/news', ['title' => 'Bleibt']);
        $this->ok('PATCH', '/admin/settings', ['news_source' => 'site']);
        $this->assertNull($this->app->roles()->find('news'));
        $this->assertSame(1, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {app_news}'));
        $this->useApp();
        $this->assertSame(['Bleibt'], array_column($this->call('GET', '/news', [], null)->data, 'title'));
    }

    public function testWriteEditDeleteAndRead(): void
    {
        $this->useApp();
        $this->assertSame([], $this->call('GET', '/news', [], null)->data);
        $a = $this->ok('POST', '/news', ['title' => '  Schützenfest  ', 'body' => '<p>Am <b>Samstag</b> geht es los.</p><script>alert(1)</script>']);
        $this->assertSame('Schützenfest', $a['title']);
        $this->assertSame('', $a['link'], 'kein Link auf eine Webseite');
        $this->assertNull($a['image']);
        $this->assertSame('Am Samstag geht es los.', $a['excerpt']);
        $this->assertStringNotContainsString('script', $a['content']);
        $this->assertArrayHasKey('updated_at', $a);
        foreach ([['title' => ''], ['title' => str_repeat('x', 151)], ['body' => '<p>ohne Titel</p>'], ['title' => 'X', 'body' => ['nein']]] as $bad) {
            $this->assertSame(400, $this->call('POST', '/news', $bad)->status, json_encode($bad));
        }
        $this->clock->at = $this->clock->at?->modify('+1 minute');
        $b = $this->ok('POST', '/news', ['title' => 'Jahreshauptversammlung']);

        // Lesen wie bisher mit view_news, auch ohne Anmeldung; neueste zuerst; Stand nur für die Pflege
        $list = $this->call('GET', '/news', [], null);
        $this->assertSame(['Jahreshauptversammlung', 'Schützenfest'], array_column($list->data, 'title'));
        $this->assertSame('1', $list->headers['X-Total-Pages']);
        $this->assertSame('<p>Am <b>Samstag</b> geht es los.</p>', $this->call('GET', '/news/' . $a['id'], [], null)->data['content']);
        $this->assertArrayNotHasKey('updated_at', $this->call('GET', '/news/' . $a['id'], [], $this->member)->data);
        $this->assertSame(404, $this->call('GET', '/news/999', [], null)->status);
        $this->assertSame(404, $this->call('GET', '/news/abc', [], null)->status);

        // Schreiben nur mit manage_news
        $this->assertSame(403, $this->call('POST', '/news', ['title' => 'X'], $this->member)->status);
        $this->assertSame(403, $this->call('PATCH', '/news/' . $a['id'], ['title' => 'X'], $this->member)->status);
        $this->assertSame(403, $this->call('DELETE', '/news/' . $a['id'], [], $this->member)->status);
        $this->app->roles()->setExtraRoles($this->member, ['news']);
        $this->assertSame(201, $this->call('POST', '/news', ['title' => 'Von Anna'], $this->member)->status, 'mit der Rolle News verwalten');

        // Konflikt: jemand hat dazwischen gespeichert
        $loaded = $a['updated_at'];
        $this->app->db->update('app_news', ['updated_at' => '2020-01-01 00:00:00'], ['id' => (int) $a['id']]);
        $res = $this->call('PATCH', '/news/' . $a['id'], ['title' => 'Neu', 'updated_at' => $loaded]);
        $this->assertSame(409, $res->status);
        $this->assertSame('conflict', $res->data['code'] ?? null);
        $fresh = $this->ok('GET', '/news/' . $a['id'])['updated_at'];
        $edited = $this->ok('PATCH', '/news/' . $a['id'], ['title' => 'Schützenfest 2027', 'updated_at' => $fresh]);
        $this->assertSame('Schützenfest 2027', $edited['title']);
        $this->assertSame($a['date'], $edited['date'], 'Datum bleibt das der Veröffentlichung');

        $this->ok('DELETE', '/news/' . $b['id']);
        $this->assertSame(404, $this->call('GET', '/news/' . $b['id'])->status);

        // Bereich aus: keine News, auch kein Schreiben
        $this->app->features()->update(['news' => false]);
        $this->assertSame(403, $this->call('POST', '/news', ['title' => 'X'])->status);
        $this->assertFalse($this->call('GET', '/config')->data['newsInApp']);
    }

    public function testPagination(): void
    {
        $this->useApp();
        for ($i = 1; $i <= 12; $i++) {
            $this->clock->at = $this->clock->at?->modify('+1 minute');
            $this->ok('POST', '/news', ['title' => 'Beitrag ' . $i]);
        }
        $first = $this->call('GET', '/news', [], null);
        $this->assertCount(10, $first->data);
        $this->assertSame('2', $first->headers['X-Total-Pages']);
        $this->assertSame('Beitrag 12', $first->data[0]['title']);
        $second = $this->call('GET', '/news', [], null, ['page' => '2']);
        $this->assertSame(['Beitrag 2', 'Beitrag 1'], array_column($second->data, 'title'));
    }

    public function testImagesFollowViewNews(): void
    {
        $this->useApp();
        $png = imagecreatetruecolor(4, 3);
        ob_start();
        imagepng($png);
        $bytes = (string) ob_get_clean();
        $uid = $this->call('POST', '/rich-images', ['file' => ['name' => 'bild.png', 'data' => base64_encode($bytes)]])->data['uid'];
        $d = fn (?int $user): int => $this->app->richImages()->forDelivery($user === null ? null : $this->users->find($user), $uid)['status'];

        $item = $this->ok('POST', '/news', ['title' => 'Mit Bild', 'body' => '<p>Text</p><img data-image="' . $uid . '">']);
        $this->assertSame('https://example.org/app/bild/' . $uid, $item['image'], 'erstes Bild als Vorschau');
        $this->assertStringContainsString('src="https://example.org/app/bild/' . $uid . '"', $item['content']);
        $this->assertSame(200, $d(null), 'News sind öffentlich (view_news der Gäste)');
        $this->assertSame(200, $d($this->member));

        // Quelle gewechselt: Bilder nicht mehr ausgeliefert
        $this->ok('PATCH', '/admin/settings', ['news_source' => 'site']);
        $this->assertSame(401, $d(null));
        $this->useApp();

        $this->ok('PATCH', '/news/' . $item['id'], ['body' => '<p>ohne Bild</p>']);
        $this->assertNull($this->app->richImages()->repository()->find($uid), 'nicht mehr im Text: gelöscht');
    }

    public function testPushForNewPostsOnly(): void
    {
        $this->app->settings->set(PushService::NEWS_SETTING, ['ids' => ['5', '6'], 'newest' => '2030-01-01T00:00:00Z']);
        $this->useApp();
        $this->assertSame(['ids' => [], 'newest' => ''], $this->app->settings->get(PushService::NEWS_SETTING), 'Stand neu begonnen');
        $item = $this->ok('POST', '/news', ['title' => 'Neu']);
        $this->assertSame([(string) $item['id']], $this->app->settings->get(PushService::NEWS_SETTING)['ids'], 'gleich gemeldet und gemerkt');
    }

    public function testStandaloneFeedUnchanged(): void
    {
        $settings = new MemorySettings();
        $users = new FakeUsers();
        $app = TestApp::create(TestApp::connection(), $users, settings: $settings, news: new RemoteNewsSource($settings, new FakeHttpClient(), new ArrayCache()));
        $users->current = $users->add('admin', Roles::ADMIN);
        $get = fn (): array => $app->api()->handle(new Request('GET', '/admin/settings'))->data['news'];
        $this->assertSame(['source' => 'none', 'url' => '', 'sources' => ['none', 'wordpress', 'rss', 'app']], $get());
        $this->assertSame(400, $app->api()->handle(new Request('PATCH', '/admin/settings', [], ['news_source' => 'rss']))->status, 'Feed braucht eine Adresse');
        $res = $app->api()->handle(new Request('PATCH', '/admin/settings', [], ['news_source' => 'rss', 'news_url' => 'https://v.example/feed']));
        $this->assertSame(200, $res->status);
        $this->assertSame('rss', $get()['source']);
        // In der App: keine Adresse nötig; die des Feeds bleibt für den Rückweg
        $this->assertSame(200, $app->api()->handle(new Request('PATCH', '/admin/settings', [], ['news_source' => 'app']))->status);
        $this->assertSame(['source' => 'app', 'url' => 'https://v.example/feed', 'sources' => ['none', 'wordpress', 'rss', 'app']], $get());
        $this->assertTrue($app->features()->configured('news'));
    }

    public function testBackupContainsNews(): void
    {
        $this->assertContains('app_news', Backup::CORE_TABLES);
    }
}
