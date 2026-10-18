<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Level;
use KompanieApp\Core\Config\KompanieConfig;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Infra\ArrayCache;
use KompanieApp\Core\Infra\GdImageResizer;
use KompanieApp\Core\Infra\LocalFileStorage;
use KompanieApp\Core\Infra\RemoteNewsSource;
use KompanieApp\Core\Tests\Support\FakeEnvironment;
use KompanieApp\Core\Tests\Support\FakeHttpClient;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\MemorySettings;
use KompanieApp\Core\Tests\Support\NullScheduler;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

final class NewsAndSettingsTest extends TestCase
{
    private function app(FakeHttpClient $http, MemorySettings $settings, FakeUsers $users): App
    {
        $dir = sys_get_temp_dir() . '/kompanie-news-' . bin2hex(random_bytes(4));
        $cache = new ArrayCache();
        $app = new App(
            db: TestApp::connection(),
            settings: $settings,
            cache: $cache,
            http: $http,
            files: new LocalFileStorage($dir . '/p', 'https://example.org/media', $dir . '/i', ''),
            images: new GdImageResizer(),
            scheduler: new NullScheduler(),
            news: new RemoteNewsSource($settings, $http, $cache),
            clock: new FixedClock(),
            env: new FakeEnvironment(),
            authContext: $users,
            authenticator: $users,
            users: $users,
            config: new KompanieConfig([]),
        );
        $app->upgrade();
        return $app;
    }

    public function testWordPressSource(): void
    {
        $http = new FakeHttpClient(static function (string $m, string $url): array {
            if (str_contains($url, rawurlencode('/wp/v2/posts/7'))) {
                return ['status' => 200, 'body' => (string) json_encode(['id' => 7, 'title' => ['rendered' => 'Fest &amp; Feier'], 'date_gmt' => '2026-05-01T10:00:00', 'excerpt' => ['rendered' => '<p>Kurz</p>'], 'content' => ['rendered' => '<p>Lang</p>'], 'link' => 'https://verein.example/fest']), 'headers' => []];
            }
            if (str_contains($url, rawurlencode('/wp/v2/posts/8'))) {
                return ['status' => 404, 'body' => '{}'];
            }
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            if (($q['page'] ?? '') === '9') {
                return ['status' => 400, 'body' => '{"code":"rest_post_invalid_page_number"}', 'headers' => ['X-WP-TotalPages' => '2']];
            }
            $post = ['id' => 7, 'title' => ['rendered' => 'Fest &amp; Feier'], 'date_gmt' => '2026-05-01T10:00:00', 'excerpt' => ['rendered' => '<p>Kurz [&hellip;]</p>'], 'link' => 'javascript:alert(1)',
                '_embedded' => ['wp:featuredmedia' => [['source_url' => 'https://verein.example/bild.jpg']]]];
            return ['status' => 200, 'body' => (string) json_encode([$post]), 'headers' => ['X-WP-TotalPages' => '2']];
        });
        $users = new FakeUsers();
        $app = $this->app($http, new MemorySettings(['news_source' => 'wordpress', 'news_url' => 'https://verein.example/']), $users);

        $res = $app->api()->handle(new Request('GET', '/news', ['page' => '1']));
        $this->assertSame(200, $res->status);
        $this->assertSame('2', $res->headers['X-Total-Pages']);
        $item = $res->data[0];
        $this->assertSame(['7', 'Fest & Feier', '2026-05-01T10:00:00+00:00', 'Kurz …', 'https://verein.example/bild.jpg', ''], [$item['id'], $item['title'], $item['date'], $item['excerpt'], $item['image'], $item['link']], 'javascript:-Link verworfen');
        $this->assertStringContainsString('rest_route=' . rawurlencode('/wp/v2/posts'), $http->calls[0]['url'], 'funktioniert auch ohne schöne Permalinks');

        $calls = count($http->calls);
        $app->api()->handle(new Request('GET', '/news', ['page' => '1']));
        $this->assertCount($calls, $http->calls, 'Zwischenspeicher');

        $this->assertSame([], $app->api()->handle(new Request('GET', '/news', ['page' => '9']))->data, 'Seite hinter dem Ende');
        $one = $app->api()->handle(new Request('GET', '/news/7'));
        $this->assertSame('<p>Lang</p>', $one->data['content']);
        $this->assertSame(404, $app->api()->handle(new Request('GET', '/news/8'))->status);
        $this->assertSame(404, $app->api()->handle(new Request('GET', '/news/abc'))->status);

        $app->settings->set('news_url', 'https://kaputt.example');
        $http->handler = \Closure::fromCallable(static fn () => throw new \RuntimeException('Zeitüberschreitung'));
        $this->assertSame(502, $app->api()->handle(new Request('GET', '/news'))->status);
    }

    public function testRssAndAtom(): void
    {
        $rss = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel>'
            . '<item><title>Älter</title><link>https://v.example/a</link><pubDate>Mon, 02 Mar 2026 10:00:00 +0100</pubDate><description>&lt;p&gt;A&lt;/p&gt;</description></item>'
            . '<item><title>Neu &amp;xxe;</title><link>https://v.example/b</link><pubDate>Tue, 10 Mar 2026 10:00:00 +0100</pubDate><description>B</description><content:encoded><![CDATA[<p>Inhalt B</p>]]></content:encoded><enclosure url="https://v.example/b.jpg" type="image/jpeg"/></item>'
            . '</channel></rss>';
        $atom = '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><entry><title>Atom-Eintrag</title><link rel="alternate" href="https://v.example/atom"/><updated>2026-03-05T10:00:00Z</updated><summary>S</summary><content type="html">&lt;p&gt;C&lt;/p&gt;</content></entry></feed>';
        $body = $rss;
        $http = new FakeHttpClient(static function () use (&$body): array {
            return ['status' => 200, 'body' => $body];
        });
        $app = $this->app($http, new MemorySettings(['news_source' => 'rss', 'news_url' => 'https://v.example/feed']), new FakeUsers());

        $list = $app->api()->handle(new Request('GET', '/news'))->data;
        $this->assertSame(['Neu', 'Älter'], array_map(static fn ($i) => trim(str_replace('&xxe;', '', $i['title'])), $list), 'neueste zuerst');
        $this->assertStringNotContainsString('root:', (string) json_encode($list), 'keine externen Entitäten (XXE)');
        $this->assertSame('https://v.example/b.jpg', $list[0]['image']);
        $this->assertArrayNotHasKey('content', $list[0], 'Liste ohne Inhalt');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $list[0]['id']);
        $one = $app->api()->handle(new Request('GET', '/news/' . $list[0]['id']))->data;
        $this->assertSame('<p>Inhalt B</p>', $one['content']);

        $body = $atom;
        $app->cache->delete('news_' . md5('https://v.example/feed'));
        $list = $app->api()->handle(new Request('GET', '/news'))->data;
        $this->assertSame('Atom-Eintrag', $list[0]['title']);
        $this->assertSame('https://v.example/atom', $list[0]['link']);

        $body = 'kein xml';
        $app->cache->delete('news_' . md5('https://v.example/feed'));
        $res = $app->api()->handle(new Request('GET', '/news'));
        $this->assertSame(502, $res->status);
        $this->assertStringContainsString('kein gültiger RSS', $res->data['message']);
    }

    public function testSettingsEndpoints(): void
    {
        $http = new FakeHttpClient(static fn (string $m, string $url) => str_contains($url, 'feed')
            ? ['status' => 200, 'body' => '<rss><channel><item><title>T</title><link>https://v.example/t</link></item></channel></rss>']
            : ['status' => 200, 'body' => "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:x\r\nDTSTART;VALUE=DATE:" . gmdate('Ymd', time() + 5 * 86400) . "\r\nSUMMARY:Fest\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"]);
        $users = new FakeUsers();
        $app = $this->app($http, new MemorySettings(), $users);
        $admin = $users->add('a', Level::Admin);
        $manager = $users->add('m', Level::Manager);
        $h = static fn (string $method, string $path, array $body = []) => $app->api()->handle(new Request($method, $path, [], $body));

        $users->current = $manager;
        $this->assertSame(403, $h('GET', '/admin/settings')->status);
        $this->assertSame(403, $h('PATCH', '/admin/settings', ['news_source' => 'rss'])->status);
        $users->current = null;
        $this->assertSame(401, $h('GET', '/admin/settings')->status);

        $users->current = $admin;
        $this->assertSame(503, $h('POST', '/admin/calendar/test')->status, 'ohne Kalender');
        $r = $h('PATCH', '/admin/settings', ['calendar_ics_url' => 'webcal://cal.example/geheim.ics', 'calendar_cache_minutes' => 30]);
        $this->assertSame(200, $r->status);
        $this->assertTrue($r->data['calendar']['ics_url_set']);
        $this->assertSame(30, $r->data['calendar']['cache_minutes']);
        $this->assertStringNotContainsString('geheim', (string) json_encode($r->data));
        $this->assertSame('https://cal.example/geheim.ics', $app->settings->get('calendar_ics_url'));
        $t = $h('POST', '/admin/calendar/test');
        $this->assertSame([1, 'Fest'], [$t->data['count'], $t->data['sample'][0]['title']]);

        foreach ([
            ['calendar_ics_url' => 'javascript:alert(1)'],
            ['calendar_cache_minutes' => 0],
            ['calendar_ids' => 'a b'],
            ['google_api_key' => 'x"y'],
            ['news_source' => 'ftp'],
            ['news_url' => 'ftp://x'],
            ['gallery_root' => 'https://drive.google.com/irgendwas?'],
            ['drive_service_account' => '{"client_email":"x"}'],
        ] as $bad) {
            $this->assertSame(400, $h('PATCH', '/admin/settings', $bad)->status, json_encode($bad));
        }

        $r = $h('PATCH', '/admin/settings', ['news_source' => 'rss', 'news_url' => 'https://v.example/feed']);
        $this->assertSame(['source' => 'rss', 'url' => 'https://v.example/feed'], $r->data['news']);
        $this->assertSame(['count' => 1], $h('POST', '/admin/news/test')->data);
        $this->assertTrue($app->api()->config()['features']['news']);

        $r = $h('PATCH', '/admin/settings', ['gallery_root' => 'https://drive.google.com/drive/folders/ABC_123']);
        $this->assertSame('ABC_123', $r->data['gallery']['root']);
        $this->assertFalse($r->data['gallery']['configured'], 'ohne Dienstkonto');
        $this->assertSame(503, $h('POST', '/admin/gallery/sync')->status);

        $r = $h('PATCH', '/admin/settings', ['calendar_ics_url' => '', 'news_source' => 'none']);
        $this->assertSame('none', $r->data['calendar']['source']);
        $this->assertFalse($app->api()->config()['features']['news']);
    }
}
