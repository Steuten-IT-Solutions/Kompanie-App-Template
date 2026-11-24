<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\RequestLogService;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** Anfragen-Protokoll: Schalter mit Ablauf, Aufzeichnung ohne Parameterwerte, gekürzte IP, Übersicht, Löschfrist. */
final class RequestLogTest extends TestCase
{
    private FakeUsers $users;
    private FixedClock $clock;
    private App $app;
    private int $admin;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->clock = new FixedClock(new \DateTimeImmutable('2026-11-24 10:00:00 UTC'));
        $this->app = TestApp::create(TestApp::connection(), $this->users, clock: $this->clock);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->users->current = $this->admin;
    }

    private function call(string $method, string $path, array $body = []): Response
    {
        return $this->app->api()->handle(new Request($method, $path, [], $body));
    }

    /** @return array{at: float, kind: string, method: string, uri: string, ms: int, status: int, auth: bool, agent: string, ip: string} */
    private function entry(array $over = []): array
    {
        return $over + [
            'at' => (float) $this->clock->now()->getTimestamp(),
            'kind' => 'page',
            'method' => 'GET',
            'uri' => '/',
            'ms' => 100,
            'status' => 200,
            'auth' => false,
            'agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/131',
            'ip' => '203.0.113.57',
        ];
    }

    public function testOffByDefaultAndSwitchesItselfOffAfterSevenDays(): void
    {
        $log = $this->app->requestLog();
        $this->assertFalse($log->active());
        $log->record($this->entry());
        $this->assertSame(0, $log->summary()['total'], 'aus: nichts aufgezeichnet');

        $res = $this->call('PUT', '/admin/request-log', ['on' => true]);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertTrue($res->data['on']);
        $this->assertSame($this->clock->now()->getTimestamp() + 7 * 86400, $res->data['until']);

        $this->clock->at = new \DateTimeImmutable('2026-12-01 10:00:01 UTC');
        $this->assertFalse($log->active(), 'nach sieben Tagen von selbst aus');
        $this->assertFalse($this->call('GET', '/admin/request-log')->data['on']);
    }

    public function testRecordsWithoutParameterValuesAndWithShortenedIp(): void
    {
        $log = $this->app->requestLog();
        $log->set(true);
        $log->record($this->entry(['uri' => '/app/foto/123/thumb?v=geheim&s=Name', 'kind' => 'app', 'ms' => 50]));
        $log->record($this->entry(['uri' => '/app/foto/77/thumb?s=x&v=y', 'kind' => 'app', 'ms' => 150]));
        $log->record($this->entry(['uri' => '/wp-cron.php?doing_wp_cron=1732441200.1', 'kind' => 'cron', 'agent' => 'WordPress/6.8; https://example.org', 'ip' => '2001:db8:abcd:12::1']));
        $log->record($this->entry(['kind' => 'unbekannt', 'agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)']));

        $dir = dirname($this->app->files->path('anfragen/x', false));
        $raw = implode('', array_map('file_get_contents', glob($dir . '/*.log') ?: []));
        $this->assertStringNotContainsString('geheim', $raw);
        $this->assertStringNotContainsString('203.0.113.57', $raw);
        $this->assertStringContainsString('203.0.113.x', $raw);

        $s = $log->summary();
        $this->assertSame(4, $s['total']);
        $this->assertSame(['count' => 2, 'ms' => 200, 'avg' => 100], $s['kinds']['app']);
        $this->assertSame(1, $s['kinds']['other']['count'], 'unbekannte Art zählt als „other“');
        $this->assertSame('GET /app/foto/{n}/thumb?s&v', $s['paths'][0]['name']);
        $this->assertSame(2, $s['paths'][0]['count']);
        $this->assertSame([['agent' => 'WordPress (Server selbst)', 'ip' => '2001:db8:abcd::x', 'count' => 1]], $s['cron']);
        $this->assertContains('Googlebot', array_column($s['agents'], 'name'));
        $this->assertSame([['date' => '2026-11-24', 'app-api' => 0, 'app' => 2, 'cron' => 1, 'admin' => 0, 'login' => 0, 'rest' => 0, 'page' => 0, 'other' => 1]], $s['days']);
        $this->assertSame(4, $s['hours'][11], 'Stunde in der Zeitzone der Kompanie (10 Uhr UTC = 11 Uhr in Berlin)');
        $this->assertSame([], $s['slowest'], 'Langsamste erst ab drei Aufrufen');
    }

    public function testPruneAfterFourteenDaysAndClear(): void
    {
        $log = $this->app->requestLog();
        $log->set(true);
        $log->record($this->entry());
        $this->clock->at = new \DateTimeImmutable('2026-11-30 10:00:00 UTC');
        $log->record($this->entry(['at' => (float) $this->clock->now()->getTimestamp()]));
        $this->assertSame(2, $log->summary()['total']);

        $this->clock->at = new \DateTimeImmutable('2026-12-09 10:00:00 UTC');
        $log->prune();
        $this->assertSame(1, $log->summary()['total'], 'der Tag vor 15 Tagen ist weg');

        $res = $this->call('DELETE', '/admin/request-log');
        $this->assertSame(200, $res->status);
        $this->assertSame(0, $res->data['total']);
    }

    public function testOnlyForAdminsWithSettingsRight(): void
    {
        $this->users->current = $this->users->add('mitglied', Roles::MEMBER);
        $this->assertSame(403, $this->call('GET', '/admin/request-log')->status);
        $this->assertSame(403, $this->call('PUT', '/admin/request-log', ['on' => true])->status);
        $this->assertFalse($this->app->requestLog()->active());
    }

    public function testPath(): void
    {
        $this->assertSame('/wp-json/kompanie-app/v1/events/{n}', RequestLogService::path('/wp-json/kompanie-app/v1/events/42'));
        $this->assertSame('/index.php?rest_route=/kompanie-app/v1/news/{n}', RequestLogService::path('/index.php?rest_route=%2Fkompanie-app%2Fv1%2Fnews%2F5'));
        $this->assertSame('/app/push/{id}', RequestLogService::path('/app/push/0123456789abcdef0123'));
        $this->assertSame('/?p&s', RequestLogService::path('/?s=Suchwort&p=1&s=2'));
    }

    public function testShortenIp(): void
    {
        $this->assertSame('198.51.100.x', RequestLogService::shortenIp('198.51.100.200'));
        $this->assertSame('2001:db8:0::x', RequestLogService::shortenIp('2001:db8::1'));
        $this->assertSame('', RequestLogService::shortenIp('kein-ip'));
    }

    public function testAgentName(): void
    {
        $this->assertSame('Bingbot', RequestLogService::agentName('Mozilla/5.0 (compatible; bingbot/2.0)'));
        $this->assertSame('Browser', RequestLogService::agentName('Mozilla/5.0 (iPhone) Safari/604.1'));
        $this->assertSame('Anderer Crawler', RequestLogService::agentName('Mozilla/5.0 (compatible; SomeCrawler/1.0)'));
        $this->assertSame('Ohne User-Agent', RequestLogService::agentName(''));
        $this->assertSame('Unbekannt', RequestLogService::agentName('okhttp/4.12'));
    }
}
