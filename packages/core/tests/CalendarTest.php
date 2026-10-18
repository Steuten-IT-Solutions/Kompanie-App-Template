<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\Auth\Level;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Tests\Support\FakeHttpClient;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
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

    /** Kalender zum festen Zeitpunkt 1. März 2026 (vor der Umstellung auf Sommerzeit). */
    private function parseAt(string $events): array
    {
        $clock = new FixedClock(new \DateTimeImmutable('2026-03-01 12:00:00', new \DateTimeZone('Europe/Berlin')));
        $app = TestApp::create(TestApp::connection(), new FakeUsers(), [], null, null, null, $clock);
        return $app->calendar()->parseIcs("BEGIN:VCALENDAR\r\n" . $events . "END:VCALENDAR\r\n");
    }

    public function testValarmDoesNotOverwriteEvent(): void
    {
        $events = $this->parseAt("BEGIN:VEVENT\r\nUID:v1\r\nDTSTART:20260310T180000Z\r\nSUMMARY:Probe\r\nDESCRIPTION:Bitte pünktlich\r\n"
            . "BEGIN:VALARM\r\nACTION:EMAIL\r\nSUMMARY:Alarm\r\nDESCRIPTION:This is an event reminder\r\nEND:VALARM\r\nEND:VEVENT\r\n");
        $this->assertCount(1, $events);
        $this->assertSame('Probe', $events[0]['title']);
        $this->assertSame('Bitte pünktlich', $events[0]['description']);
    }

    public function testWeeklySeriesStartedLongAgo(): void
    {
        $events = $this->parseAt("BEGIN:VEVENT\r\nUID:w1\r\nDTSTART;TZID=Europe/Berlin:20240102T193000\r\nDTEND;TZID=Europe/Berlin:20240102T213000\r\n"
            . "RRULE:FREQ=WEEKLY;BYDAY=TU\r\nEXDATE;TZID=Europe/Berlin:20260310T193000\r\nSUMMARY:Übungsabend\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:w1\r\nRECURRENCE-ID;TZID=Europe/Berlin:20260317T193000\r\nDTSTART;TZID=Europe/Berlin:20260318T200000\r\nDTEND;TZID=Europe/Berlin:20260318T220000\r\nSUMMARY:Übungsabend (verlegt)\r\nEND:VEVENT\r\n");
        usort($events, static fn ($a, $b) => strcmp($a['start'], $b['start']));
        $starts = array_column(array_slice($events, 0, 4), 'start');
        $this->assertSame([
            '2026-03-03T19:30:00+01:00',
            '2026-03-18T20:00:00+01:00', // 10.3. ausgenommen, 17.3. verlegt
            '2026-03-24T19:30:00+01:00',
            '2026-03-31T19:30:00+02:00', // Uhrzeit bleibt über die Zeitumstellung gleich
        ], $starts);
        $this->assertSame('2026-03-31T21:30:00+02:00', $events[3]['end']);
        $this->assertSame('Übungsabend (verlegt)', $events[1]['title']);
        $this->assertCount(52 - 2 + 1, $events, 'ein Jahr Vorschau: 52 Dienstage vom 3.3.2026 bis 23.2.2027, ohne 10.3. und 17.3., plus Verlegung');
    }

    public function testMonthlyYearlyCountUntil(): void
    {
        $events = $this->parseAt(
            "BEGIN:VEVENT\r\nUID:m1\r\nDTSTART;VALUE=DATE:20250131\r\nRRULE:FREQ=MONTHLY;BYDAY=-1FR;COUNT=16\r\nSUMMARY:Stammtisch\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:y1\r\nDTSTART;VALUE=DATE:20200815\r\nDTEND;VALUE=DATE:20200817\r\nRRULE:FREQ=YEARLY;UNTIL=20260901\r\nSUMMARY:Schützenfest\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:x1\r\nDTSTART:20260305T100000Z\r\nRRULE:FREQ=MONTHLY;BYSETPOS=1;BYDAY=MO\r\nSUMMARY:Unbekannte Regel\r\nEND:VEVENT\r\n"
        );
        $by = [];
        foreach ($events as $e) {
            $by[$e['title']][] = $e['start'] . '/' . $e['end'];
        }
        // 16 Vorkommen ab Januar 2025: letztes ist der 24.4.2026 (letzter Freitag im April).
        $this->assertSame(['2026-03-27/2026-03-28', '2026-04-24/2026-04-25'], $by['Stammtisch']);
        $this->assertSame(['2026-08-15/2026-08-17'], $by['Schützenfest']);
        $this->assertCount(1, $by['Unbekannte Regel'], 'nicht unterstützte Regel: nur der erste Termin');
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
