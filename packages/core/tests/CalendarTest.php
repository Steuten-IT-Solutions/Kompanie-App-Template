<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\Auth\Level;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Tests\Support\FakeHttpClient;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\MemorySettings;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

final class CalendarTest extends TestCase
{
    private function ics(): string
    {
        $d = static fn (int $days): string => gmdate('Ymd', time() + $days * 86400);
        return "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:a1\r\nDTSTART;VALUE=DATE:" . $d(10) . "\r\nDTEND;VALUE=DATE:" . $d(11) . "\r\nSUMMARY:Sommerfest\\, Musterstadt\r\nLOCATION:Festplatz\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:a2\r\nDTSTART;TZID=Europe/Berlin:" . $d(20) . "T190000\r\nDTEND;TZID=Europe/Berlin:" . $d(20) . "T220000\r\nSUMMARY:Vereins\r\n abend\r\nDESCRIPTION:Bitte <b>pünktlich</b>\\nsein\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:p1\r\nCLASS:PRIVATE\r\nDTSTART;VALUE=DATE:" . $d(12) . "\r\nSUMMARY:Arzttermin\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:c1\r\nSTATUS:CANCELLED\r\nDTSTART;VALUE=DATE:" . $d(13) . "\r\nSUMMARY:Abgesagt\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:old\r\nDTSTART:20200101T100000Z\r\nDTEND:20200101T110000Z\r\nSUMMARY:Alt\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    public function testIcsParser(): void
    {
        $app = TestApp::create(TestApp::connection());
        $events = $app->calendar()->parseIcs($this->ics());
        $this->assertCount(2, $events, 'vergangene Termine gefiltert');
        $this->assertSame('Sommerfest, Musterstadt', $events[0]['title']);
        $this->assertTrue($events[0]['all_day']);
        $this->assertSame('Vereinsabend', $events[1]['title'], 'Zeilenfaltung');
        $this->assertFalse($events[1]['all_day']);
        $expected = new \DateTimeImmutable(gmdate('Y-m-d', time() + 20 * 86400) . ' 19:00:00', new \DateTimeZone('Europe/Berlin'));
        $this->assertSame($expected->getTimestamp(), strtotime($events[1]['start']), 'TZID beachtet');
        $this->assertSame("Bitte pünktlich\nsein", $events[1]['description']);
    }

    public function testCalendarRouteWithIcsAndCache(): void
    {
        $http = new FakeHttpClient(fn () => ['status' => 200, 'body' => $this->ics()]);
        $users = new FakeUsers();
        $settings = new MemorySettings(['calendar_ics_url' => 'webcal://calendar.google.com/x/basic.ics']);
        $app = TestApp::create(TestApp::connection(), $users, [], $http, null, $settings);
        $users->current = $users->add('m', Level::Member);

        $res = $app->api()->handle(new Request('GET', '/calendar'));
        $this->assertSame(200, $res->status);
        $this->assertCount(2, $res->data);
        $this->assertSame('https://calendar.google.com/x/basic.ics', $http->calls[0]['url'], 'webcal:// wird über https abgerufen');
        $app->api()->handle(new Request('GET', '/calendar'));
        $this->assertCount(1, $http->calls, 'Zwischenspeicher');

        $users->current = $users->add('g', Level::Guest);
        $this->assertSame(403, $app->api()->handle(new Request('GET', '/calendar'))->status);
    }

    public function testCalendarErrors(): void
    {
        $users = new FakeUsers();
        $app = TestApp::create(TestApp::connection(), $users);
        $users->current = $users->add('m', Level::Member);
        $res = $app->api()->handle(new Request('GET', '/calendar'));
        $this->assertSame(503, $res->status);
        $this->assertSame('not_configured', $res->data['code']);

        $http = new FakeHttpClient(static fn () => throw new \RuntimeException('Zeitüberschreitung'));
        $app = TestApp::create(TestApp::connection(), $users, [], $http, null, new MemorySettings(['calendar_ics_url' => 'https://example.org/k.ics']));
        $res = $app->api()->handle(new Request('GET', '/calendar'));
        $this->assertSame(502, $res->status);
        $this->assertStringContainsString('Zeitüberschreitung', $res->data['message']);
    }

    public function testGoogleCalendarSource(): void
    {
        $http = new FakeHttpClient(static fn (string $m, string $url) => ['status' => 200, 'body' => (string) json_encode(['items' => [
            ['id' => 'e1', 'summary' => 'Probe', 'start' => ['dateTime' => '2030-05-01T19:00:00Z'], 'end' => ['dateTime' => '2030-05-01T21:00:00Z'], 'description' => '<p>Hallo</p>'],
            ['id' => 'e2', 'summary' => 'Geheim', 'visibility' => 'private', 'start' => ['date' => '2030-05-02'], 'end' => ['date' => '2030-05-03']],
            ['id' => 'e3', 'summary' => 'Fest', 'start' => ['date' => '2030-05-04'], 'end' => ['date' => '2030-05-05']],
        ]])]);
        $settings = new MemorySettings(['calendar_ids' => 'a@group.calendar.google.com', 'google_api_key' => 'KEY']);
        $app = TestApp::create(TestApp::connection(), new FakeUsers(), [], $http, null, $settings);
        $this->assertSame('google', $app->calendar()->source());
        $events = $app->calendar()->events();
        $this->assertSame(['Probe', 'Fest'], array_column($events, 'title'));
        $this->assertSame('2030-05-01T21:00:00+02:00', $events[0]['start'], 'in Ortszeit');
        $this->assertSame('Hallo', $events[0]['description']);
        $this->assertStringContainsString('a%40group.calendar.google.com/events', $http->calls[0]['url']);
        $this->assertStringContainsString('key=KEY', $http->calls[0]['url']);
    }
}
