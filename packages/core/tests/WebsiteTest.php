<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\Features;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FakeWebsiteHost;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\TestApp;
use KompanieApp\Core\Website\BlockHtml;
use KompanieApp\Core\Website\WebsiteRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Website (Issue #273), first step: area only with a WebsiteHost, right manage_website, home page from a public page as
 * a guest sees it, entry file, status page.
 */
final class WebsiteTest extends TestCase
{
    private FakeUsers $users;
    private FakeWebsiteHost $host;
    private App $app;
    private int $admin;
    private int $member;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->host = new FakeWebsiteHost();
        $this->app = TestApp::create(TestApp::connection(), $this->users, ['name' => 'Testkompanie'], clock: new FixedClock(new \DateTimeImmutable('2026-11-13 12:00:00')), websiteHost: $this->host);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->member = $this->users->add('anna', Roles::MEMBER);
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

    public function testOnlyWithHost(): void
    {
        $users = new FakeUsers();
        $app = TestApp::create(TestApp::connection(), $users);
        $users->current = $users->add('admin', Roles::ADMIN);
        $this->assertFalse($app->features()->enabled('website'), 'ohne Ort für die Webseite (WordPress) immer aus');
        $this->assertNotContains('website', array_column($app->features()->describe(), 'key'));
        $app->features()->update(['website' => true]);
        $this->assertFalse($app->features()->enabled('website'));
        $res = $app->api()->handle(new Request('GET', '/admin/website'));
        $this->assertSame(403, $res->status);
        $this->assertSame('feature_disabled', $res->data['code'] ?? null);
        $this->assertNull($app->website()->statusItem());

        $this->assertTrue($this->app->features()->enabled('website'));
        $this->assertContains('website', array_column($this->app->features()->describe(), 'key'));
    }

    public function testNeedsPagesAndRight(): void
    {
        $this->assertSame(400, $this->call('PUT', '/admin/features', ['pages' => false])->status, 'Webseite braucht Seiten');
        $this->ok('PUT', '/admin/features', ['pages' => false, 'website' => false]);
        $this->ok('PUT', '/admin/features', ['pages' => true, 'website' => true]);
        $roles = array_column($this->app->roles()->all(), 'capabilities', 'label');
        $this->assertContains('manage_website', $roles['Webseite verwalten'] ?? [], 'Standardrolle des Bereichs');

        $this->assertSame(403, $this->call('GET', '/admin/website', [], $this->member)->status);
        $this->assertSame(401, $this->call('GET', '/admin/website', [], null)->status);
        $this->assertSame(200, $this->call('GET', '/admin/website')->status);
    }

    public function testHomePageAsGuest(): void
    {
        $members = $this->ok('POST', '/pages', ['title' => 'Intern', 'body' => '<p>Nur intern</p>']);
        $page = $this->ok('POST', '/pages', ['title' => 'Willkommen', 'audience' => 'public', 'blocks' => [
            ['type' => 'text', 'html' => '<p>Hallo <b>Welt</b></p>'],
            ['type' => 'text', 'audience' => 'members', 'html' => '<p>Geheimer Treffpunkt</p>'],
            ['type' => 'notice', 'title' => 'Hinweis', 'text' => 'Bitte "pünktlich" & leise', 'tone' => 'warning'],
            ['type' => 'button', 'label' => 'Alle Termine', 'url' => '#/termine'],
            ['type' => 'countdown', 'title' => 'Schützenfest', 'source' => 'date', 'date' => '2026-11-15', 'time' => '14:30'],
        ]]);

        $html = $this->app->website()->renderHome();
        $this->assertStringContainsString('Hier entsteht unsere Webseite', $html['html'], 'ohne Startseite ein Hinweis');
        $this->assertStringContainsString('noindex', $html['html']);

        $this->assertSame(400, $this->call('PATCH', '/admin/website', ['home_page_id' => $members['id']])->status, 'nur für Mitglieder');
        $this->assertSame(400, $this->call('PATCH', '/admin/website', ['home_page_id' => 9999])->status);
        $this->assertSame(400, $this->call('PATCH', '/admin/website', ['home_page_id' => 'eins'])->status);
        $d = $this->ok('PATCH', '/admin/website', ['home_page_id' => $page['id']]);
        $this->assertSame($page['id'], $d['home_page_id']);
        $this->assertSame([['id' => $page['id'], 'title' => 'Willkommen']], $d['pages'], 'nur Seiten für alle');
        $this->assertFalse($d['home_page_missing']);

        // Auch angemeldet sieht die Webseite aus wie für Gäste.
        $html = $this->app->website()->renderHome()['html'];
        $this->assertStringContainsString('<title>Willkommen – Testkompanie</title>', $html);
        $this->assertStringContainsString('<p>Hallo <b>Welt</b></p>', $html);
        $this->assertStringNotContainsString('Geheimer Treffpunkt', $html);
        $this->assertStringContainsString('Bitte &quot;pünktlich&quot; &amp; leise', $html);
        $this->assertStringContainsString('href="https://example.org/app/#/termine"', $html, 'Seiten der App unter der Adresse der App');
        $this->assertStringContainsString('<span class="block-countdown-value">2</span><span class="block-countdown-unit">Tage</span>', $html);
        $this->assertStringContainsString('So., 15. November 2026, 14:30 Uhr', $html);
        $this->assertStringContainsString('webseite.css&amp;v=', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('noindex', $html);

        // Seite nur noch für Mitglieder: Platzhalter, die Verwaltung sagt es.
        $this->ok('PATCH', '/pages/' . $page['id'], ['audience' => 'members']);
        $this->assertNull($this->app->website()->homePage());
        $this->assertTrue($this->ok('GET', '/admin/website')['home_page_missing']);
        $this->ok('PATCH', '/admin/website', ['home_page_id' => null]);
        $this->assertNull($this->ok('GET', '/admin/website')['home_page_id']);
    }

    public function testPeopleAreNotIndexed(): void
    {
        $renderer = new WebsiteRenderer($this->app, $this->host);
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $renderer->page('Vorstand', [['id' => 'b1', 'type' => 'contacts', 'audience' => 'all', 'title' => 'Ansprechpartner', 'offices' => []]]));
        $this->assertStringNotContainsString('noindex', $renderer->page('Geschichte', [['id' => 'b1', 'type' => 'text', 'audience' => 'all', 'html' => '<p>Seit 1900</p>']]));
    }

    public function testEntryAndStatus(): void
    {
        $d = $this->ok('GET', '/admin/website');
        $this->assertSame('missing', $d['entry']['state']);
        $this->assertSame('warn', $this->app->website()->statusItem()['level'] ?? null);
        $this->assertSame('installed', $this->ok('POST', '/admin/website/entry')['entry']['state']);
        $this->assertSame(409, $this->call('POST', '/admin/website/entry')->status, 'nicht zweimal');
        $this->assertStringContainsString('noch keine Startseite', $this->app->website()->statusItem()['text'] ?? '');
        $page = $this->ok('POST', '/pages', ['title' => 'Start', 'audience' => 'public', 'body' => '<p>Hallo</p>']);
        $this->ok('PATCH', '/admin/website', ['home_page_id' => $page['id']]);
        $this->assertSame('ok', $this->app->website()->statusItem()['level'] ?? null);
        $this->assertContains('website', array_column($this->app->status()->report()['items'], 'key'));

        $this->host->state = 'occupied';
        $this->host->file = 'index.html';
        $this->assertStringContainsString('index.html', $this->ok('GET', '/admin/website')['entry']['message']);
        $this->assertSame(409, $this->call('POST', '/admin/website/entry')->status, 'fremde Startseite nie ersetzen');
        $this->host->state = 'root';
        $this->assertSame('error', $this->app->website()->statusItem()['level'] ?? null);

        $this->app->features()->update(['website' => false]);
        $this->assertNull($this->app->website()->statusItem(), 'Bereich aus: nichts auf der Statusseite');
    }

    public function testFormatting(): void
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $now = new \DateTimeImmutable('2026-11-13 12:00:00', $tz);
        $this->assertSame([[1, 'Tag'], [0, 'Stunden']], BlockHtml::remaining($now, new \DateTimeImmutable('2026-11-14 12:00:00', $tz)));
        $this->assertSame([[2, 'Stunden'], [1, 'Minute']], BlockHtml::remaining($now, new \DateTimeImmutable('2026-11-13 14:01:00', $tz)));
        $this->assertSame([[5, 'Minuten'], [30, 'Sekunden']], BlockHtml::remaining($now, new \DateTimeImmutable('2026-11-13 12:05:30', $tz)));
        $this->assertSame('Sa., 26. Juni 2027 · 19:00–23:00 Uhr', BlockHtml::eventWhen(['start_date' => '2027-06-26', 'start_time' => '19:00', 'end_date' => null, 'end_time' => '23:00', 'all_day' => false]));
        $this->assertSame('25.–28. Juni 2027', BlockHtml::eventWhen(['start_date' => '2027-06-25', 'start_time' => null, 'end_date' => '2027-06-28', 'end_time' => null, 'all_day' => true]));
        $this->assertSame('30. Juni–2. Juli 2027', BlockHtml::eventWhen(['start_date' => '2027-06-30', 'start_time' => null, 'end_date' => '2027-07-02', 'end_time' => null, 'all_day' => true]));
        $this->assertContains('website', Features::keys());
    }
}
