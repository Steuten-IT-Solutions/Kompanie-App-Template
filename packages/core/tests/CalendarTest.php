<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\App;
use KompanieApp\Core\Domain\CalendarService;
use KompanieApp\Core\Domain\DriveClient;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Port\ExtendedHttpClient;
use KompanieApp\Core\Tests\Support\DriveMock;
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
        // Wie die Terminansicht (CalendarService::upcoming): ab gestern. Der Kalender lädt zusätzlich die letzten
        // Wochen für Anwesenheitslisten (siehe testPastWeeksForAttendance).
        $from = $clock->now()->getTimestamp() - 86400;
        return array_values(array_filter(
            $app->calendar()->parseIcs("BEGIN:VCALENDAR\r\n" . $events . "END:VCALENDAR\r\n"),
            static fn (array $e): bool => strtotime($e['end']) >= $from,
        ));
    }

    public function testPastWeeksForAttendance(): void
    {
        $clock = new FixedClock(new \DateTimeImmutable('2026-03-01 12:00:00', new \DateTimeZone('Europe/Berlin')));
        $app = TestApp::create(TestApp::connection(), new FakeUsers(), [], null, null, null, $clock);
        $events = $app->calendar()->parseIcs("BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:w\r\nDTSTART:20260105T180000Z\r\nDTEND:20260105T200000Z\r\nRRULE:FREQ=WEEKLY\r\nSUMMARY:Probe\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:alt\r\nDTSTART:20251101T180000Z\r\nSUMMARY:Zu alt\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
        $this->assertSame('2026-01-05', substr($events[0]['start'], 0, 10), 'die letzten 90 Tage');
        $this->assertNotContains('Zu alt', array_column($events, 'title'));
    }

    public function testValarmDoesNotOverwriteEvent(): void
    {
        $events = $this->parseAt("BEGIN:VEVENT\r\nUID:v1\r\nDTSTART:20260310T180000Z\r\nSUMMARY:Probe\r\nDESCRIPTION:Bitte pünktlich\r\n"
            . "BEGIN:VALARM\r\nACTION:EMAIL\r\nSUMMARY:Alarm\r\nDESCRIPTION:This is an event reminder\r\nEND:VALARM\r\nEND:VEVENT\r\n");
        $this->assertCount(1, $events);
        $this->assertSame('Probe', $events[0]['title']);
        $this->assertSame('Bitte pünktlich', $events[0]['description']);
    }

    public function testUnclosedValarm(): void
    {
        $events = $this->parseAt("BEGIN:VEVENT\r\nUID:a\r\nDTSTART:20260310T180000Z\r\nSUMMARY:Erster\r\nBEGIN:VALARM\r\nDESCRIPTION:Alarm\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:b\r\nDTSTART:20260311T180000Z\r\nSUMMARY:Zweiter\r\nEND:VEVENT\r\n");
        $this->assertSame(['Erster', 'Zweiter'], array_column($events, 'title'));
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

    /** @return string[] Beginn der Vorkommen einer Serie */
    private function starts(string $props): array
    {
        $events = $this->parseAt("BEGIN:VEVENT\r\nUID:r\r\n" . $props . "SUMMARY:S\r\nEND:VEVENT\r\n");
        $starts = array_column($events, 'start');
        sort($starts);
        return $starts;
    }

    public function testRecurrenceRulesFromReview(): void
    {
        // WKST=SU mit INTERVAL=2 (Gegenstück zum Beispiel in RFC 5545, 3.8.5.3).
        $this->assertSame(['2026-03-03T19:00:00+01:00', '2026-03-15T19:00:00+01:00', '2026-03-17T19:00:00+01:00', '2026-03-29T19:00:00+02:00'],
            $this->starts("DTSTART;TZID=Europe/Berlin:20260303T190000\r\nRRULE:FREQ=WEEKLY;INTERVAL=2;COUNT=4;BYDAY=TU,SU;WKST=SU\r\n"));
        // BYDAY und BYMONTHDAY zusammen: nur Freitag, der 13.
        $this->assertSame(['2026-03-13', '2026-11-13'],
            $this->starts("DTSTART;VALUE=DATE:20260213\r\nRRULE:FREQ=MONTHLY;BYDAY=FR;BYMONTHDAY=13;COUNT=3\r\n"));
        // DAILY mit BYMONTHDAY schränkt ein: jeder Monatserste von März 2026 bis März 2027.
        $this->assertCount(13, $this->starts("DTSTART;VALUE=DATE:20260301\r\nRRULE:FREQ=DAILY;BYMONTHDAY=1\r\n"));
        // DTSTART zählt immer als erstes Vorkommen, auch wenn es nicht zur Regel passt (Mi-Start, BYDAY=MO).
        $this->assertSame(['2026-03-04', '2026-03-09'], $this->starts("DTSTART;VALUE=DATE:20260304\r\nRRULE:FREQ=WEEKLY;BYDAY=MO;COUNT=2\r\n"));
        // Ungültiges BYDAY oder riesiges INTERVAL: nur der erste Termin, kein Absturz.
        $this->assertSame(['2026-03-04'], $this->starts("DTSTART;VALUE=DATE:20260304\r\nRRULE:FREQ=WEEKLY;BYDAY=XX\r\n"));
        $this->assertSame(['2026-03-04'], $this->starts("DTSTART;VALUE=DATE:20260304\r\nRRULE:FREQ=MONTHLY;INTERVAL=99999999999999999999\r\n"));
        // Doppelte Angaben zählen einmal; YEARLY mit BYMONTHDAY ohne BYMONTH: nur der erste Termin.
        $this->assertSame(['2026-03-03', '2026-03-10', '2026-03-17', '2026-03-24'], $this->starts("DTSTART;VALUE=DATE:20260303\r\nRRULE:FREQ=WEEKLY;BYDAY=TU,TU;COUNT=4\r\n"));
        $this->assertSame(['2026-03-03'], $this->starts("DTSTART;VALUE=DATE:20260303\r\nRRULE:FREQ=YEARLY;BYMONTHDAY=15\r\n"));
        // EXDATE nur mit Datum bei einer Serie mit Uhrzeit.
        $this->assertCount(2, $this->starts("DTSTART;TZID=Europe/Berlin:20260303T190000\r\nRRULE:FREQ=WEEKLY;COUNT=3\r\nEXDATE;VALUE=DATE:20260310\r\n"));
    }

    public function testOldSeriesAreFast(): void
    {
        $ics = '';
        for ($i = 0; $i < 50; $i++) {
            $ics .= "BEGIN:VEVENT\r\nUID:old$i\r\nDTSTART;TZID=Europe/Berlin:19000101T080000\r\nRRULE:FREQ=DAILY\r\nSUMMARY:Alt\r\nEND:VEVENT\r\n";
        }
        $t = microtime(true);
        $events = $this->parseAt($ics);
        $this->assertLessThan(5.0, microtime(true) - $t, 'Serien seit 1900 ohne Aufwand für vergangene Jahre'); // Termin am 28.2. um 8 Uhr ist um 12 Uhr vorbei
        $this->assertGreaterThan(50 * 360, count($events), 'tägliche Termine im ganzen Vorschau-Jahr');
        $this->assertSame('2026-03-01T08:00:00+01:00', min(array_column($events, 'start')));
    }

    public function testCalendarRouteWithIcsAndCache(): void
    {
        $http = new FakeHttpClient(fn () => ['status' => 200, 'body' => $this->ics()]);
        $users = new FakeUsers();
        $settings = new MemorySettings(['calendar_ics_url' => 'webcal://calendar.google.com/x/basic.ics']);
        $app = TestApp::create(TestApp::connection(), $users, [], $http, null, $settings);
        $users->current = $users->add('m', Roles::MEMBER);

        $res = $app->api()->handle(new Request('GET', '/calendar'));
        $this->assertSame(200, $res->status);
        $this->assertCount(2, $res->data);
        $this->assertSame('https://calendar.google.com/x/basic.ics', $http->calls[0]['url'], 'webcal:// wird über https abgerufen');
        $app->api()->handle(new Request('GET', '/calendar'));
        $this->assertCount(1, $http->calls, 'Zwischenspeicher');

        $users->current = $users->add('g', Roles::GUEST);
        $this->assertSame(403, $app->api()->handle(new Request('GET', '/calendar'))->status);
    }

    public function testCalendarErrors(): void
    {
        $users = new FakeUsers();
        $app = TestApp::create(TestApp::connection(), $users);
        $users->current = $users->add('m', Roles::MEMBER);
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

    public function testGoogleCalendarPagesAreFollowed(): void
    {
        $http = new FakeHttpClient(static function (string $m, string $url): array {
            $page = str_contains($url, 'pageToken=') ? (int) substr($url, strrpos($url, '=') + 1) : 0;
            $item = ['id' => 'e' . $page, 'summary' => 'Termin ' . $page, 'start' => ['date' => '2030-05-0' . ($page + 1)], 'end' => ['date' => '2030-05-0' . ($page + 2)]];
            return ['status' => 200, 'body' => (string) json_encode(['items' => [$item]] + ($page < 9 ? ['nextPageToken' => (string) ($page + 1)] : []))];
        });
        $settings = new MemorySettings(['calendar_ids' => 'a@group.calendar.google.com', 'google_api_key' => 'KEY']);
        $app = TestApp::create(TestApp::connection(), new FakeUsers(), [], $http, null, $settings);
        $this->assertSame(['Termin 0', 'Termin 1', 'Termin 2', 'Termin 3'], array_column($app->calendar()->events(), 'title'), 'höchstens 4 Seiten');
        $this->assertStringContainsString('key=KEY', $http->calls[1]['url']);
    }

    public function testStaleCopyErrorCacheAndBackgroundRefresh(): void
    {
        $down = false;
        $http = new FakeHttpClient(function () use (&$down): array {
            if ($down) {
                throw new \RuntimeException('Zeitüberschreitung');
            }
            return ['status' => 200, 'body' => $this->ics()];
        });
        $clock = new FixedClock(new \DateTimeImmutable());
        $settings = new MemorySettings(['calendar_ics_url' => 'https://example.org/k.ics', 'calendar_cache_minutes' => 15]);
        $app = TestApp::create(TestApp::connection(), new FakeUsers(), [], $http, null, $settings, $clock);
        $cal = $app->calendar();

        $this->assertCount(2, $cal->events());
        $this->assertCount(1, $http->calls);
        $cal->events();
        $this->assertCount(1, $http->calls, 'frisch: kein Abruf');

        // Nach Ablauf und bei einer Störung: alte Fassung statt Fehler, der Fehler wird gemerkt.
        $clock->at = $clock->now()->modify('+16 minutes');
        $down = true;
        $this->assertCount(2, $cal->events(), 'Rückfall auf die alte Fassung');
        $this->assertCount(2, $http->calls);
        $this->assertCount(2, $cal->events());
        $this->assertCount(2, $http->calls, 'Fehler-Cache: kein neuer Abruf während der Störung');

        // Ohne alte Fassung: Fehler, aber auch der wird nur einmal je Minute abgerufen.
        $app->cache->delete(CalendarService::CACHE_KEY);
        try {
            $cal->events();
            $this->fail('Fehler erwartet');
        } catch (ApiError $e) {
            $this->assertSame(502, $e->status);
            $this->assertStringContainsString('Zeitüberschreitung', $e->getMessage());
        }
        $this->assertCount(2, $http->calls, 'gemerkter Fehler, kein Abruf');
        $cal->clearCache();
        try {
            $cal->events();
            $this->fail('Fehler erwartet');
        } catch (ApiError) {
        }
        $this->assertCount(3, $http->calls, 'nach dem Leeren (z. B. „Kalender testen“) wird wieder abgerufen');

        // Die Aufgabe im Hintergrund erneuert, sobald der Kalender wieder erreichbar ist.
        $down = false;
        $app->runTask(CalendarService::TASK);
        $this->assertCount(4, $http->calls);
        $this->assertCount(2, $cal->events(), 'aus dem Zwischenspeicher');
        $this->assertCount(4, $http->calls);
        $app->runTask(CalendarService::TASK);
        $this->assertCount(4, $http->calls, 'gerade erst abgerufen: Aufgabe tut nichts');

        // Veraltet, aber eine andere Anfrage ruft gerade ab (Sperre): alte Fassung ohne Abruf.
        $clock->at = $clock->now()->modify('+20 minutes');
        $app->cache->increment(CalendarService::CACHE_KEY . '_lock', 30);
        $this->assertCount(2, $cal->events());
        $this->assertCount(4, $http->calls, 'Sperre: nur ein Abruf gleichzeitig');
    }

    public function testCalendarTaskIsScheduledOnlyWhenConfigured(): void
    {
        $app = TestApp::create(TestApp::connection(), new FakeUsers(), [], null, null, new MemorySettings());
        $this->assertArrayNotHasKey(CalendarService::TASK, $app->scheduler->scheduled);
        $app->settingsService()->update(['calendar_ics_url' => 'https://example.org/k.ics']);
        $this->assertArrayHasKey(CalendarService::TASK, $app->scheduler->scheduled);
    }

    public function testGoogleCalendarsAreFetchedInParallel(): void
    {
        $http = new class implements ExtendedHttpClient {
            /** @var array<int, string[]> */
            public array $batches = [];

            public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeoutSeconds = 15): array
            {
                $this->batches[] = [$url];
                return ['status' => 200, 'headers' => [], 'body' => '{"items":[]}'];
            }

            public function requestAll(array $requests, int $timeoutSeconds = 15): array
            {
                $this->batches[] = array_column($requests, 'url');
                $out = [];
                foreach ($requests as $k => $r) {
                    $id = str_contains($r['url'], 'b%40') ? 'b' : 'a';
                    $out[$k] = ['status' => 200, 'headers' => [], 'body' => (string) json_encode(['items' => [
                        ['id' => $id, 'summary' => 'Termin ' . $id, 'start' => ['date' => '2030-05-0' . ($id === 'a' ? 2 : 1)], 'end' => ['date' => '2030-05-03']],
                    ]])];
                }
                return $out;
            }

            public function download(string $url, string $file, array $headers = [], int $timeoutSeconds = 90): array
            {
                throw new \RuntimeException('nicht gebraucht');
            }
        };
        $settings = new MemorySettings(['calendar_ids' => 'a@group.calendar.google.com, b@group.calendar.google.com', 'google_api_key' => 'KEY']);
        $app = TestApp::create(TestApp::connection(), new FakeUsers(), [], null, null, $settings);
        $cal = new CalendarService($settings, $http, $app->cache, $app->time());
        $events = $cal->events();
        $this->assertCount(1, $http->batches, 'beide Kalender in einem Durchgang');
        $this->assertCount(2, $http->batches[0]);
        $this->assertSame(['Termin b', 'Termin a'], array_column($events, 'title'), 'zusammengeführt und nach Beginn sortiert');
    }

    public function testSeveralGoogleCalendarsInOneBatchWithFake(): void
    {
        $down = false;
        $http = new FakeHttpClient(static function (string $m, string $url) use (&$down): array {
            if ($down && str_contains($url, 'b%40')) {
                throw new \RuntimeException('Zeitüberschreitung');
            }
            $id = str_contains($url, 'b%40') ? 'b' : 'a';
            return ['status' => 200, 'body' => (string) json_encode(['items' => [['id' => $id, 'summary' => 'Termin ' . $id, 'start' => ['date' => '2030-05-01'], 'end' => ['date' => '2030-05-02']]]])];
        });
        $clock = new FixedClock(new \DateTimeImmutable());
        $settings = new MemorySettings(['calendar_ids' => 'a@group.calendar.google.com, b@group.calendar.google.com', 'google_api_key' => 'KEY']);
        $app = TestApp::create(TestApp::connection(), new FakeUsers(), [], $http, null, $settings, $clock);
        $this->assertEqualsCanonicalizing(['Termin a', 'Termin b'], array_column($app->calendar()->events(), 'title'));
        $this->assertCount(1, $http->batches, 'beide Kalender über requestAll');
        $this->assertCount(2, $http->batches[0]);

        // Ein Kalender gestört: Rückfall auf die alte Fassung (beider Kalender), Fehler gemerkt.
        $down = true;
        $clock->at = $clock->now()->modify('+20 minutes');
        $this->assertCount(2, $app->calendar()->events());
        $this->assertCount(2, $http->batches);
        $this->assertIsString($app->cache->get(CalendarService::ERROR_KEY));
    }
    /* Google-Zugang der Galerie als Kalenderquelle */

    private const OAUTH_DRIVE_ONLY = ['client_id' => 'CID', 'client_secret' => 'SECRET', 'refresh_token' => 'REFRESH1', 'email' => 'galerie@example.org', 'scope' => DriveClient::SCOPE];

    /** @return array<string, mixed> OAuth-Verbindung wie aus der Übernahme, mit Kalender-Berechtigung */
    private static function oauthWithCalendar(): array
    {
        return ['scope' => DriveClient::SCOPE . ' ' . DriveClient::CALENDAR_SCOPE] + self::OAUTH_DRIVE_ONLY;
    }

    /** App mit simuliertem Google (DriveMock) und iCal-Adresse https://example.org/k.ics */
    private function googleApp(DriveMock $google, MemorySettings $settings): App
    {
        $inner = $google->http()->handler;
        $http = new FakeHttpClient(fn (string $m, string $url, array $h, ?string $b): array => str_starts_with($url, 'https://example.org/k.ics')
            ? ['status' => 200, 'body' => $this->ics()]
            : $inner($m, $url, $h, $b));
        return TestApp::create(TestApp::connection(), new FakeUsers(), [], $http, null, $settings);
    }

    /** @return array<int, array<string, mixed>> */
    private static function googleItems(): array
    {
        return [
            ['id' => 'g1', 'summary' => 'Probe', 'start' => ['dateTime' => '2030-05-01T19:00:00Z'], 'end' => ['dateTime' => '2030-05-01T21:00:00Z']],
            ['id' => 'g2', 'summary' => 'Privat', 'visibility' => 'private', 'start' => ['date' => '2030-05-02'], 'end' => ['date' => '2030-05-03']],
        ];
    }

    public function testDriveClientCalendarAccess(): void
    {
        $google = new DriveMock();
        $app = $this->googleApp($google, new MemorySettings());
        $drive = $app->drive();
        $this->assertFalse($drive->calendarAccess(), 'ohne Zugang');
        $drive->saveServiceAccount($google->serviceAccountJson());
        $this->assertTrue($drive->calendarAccess(), 'Dienstkonto fragt den Kalender immer mit an');

        $drive->saveOAuth(['client_id' => 'CID', 'client_secret' => 'SECRET', 'refresh_token' => 'REFRESH1']);
        $this->assertSame('', $drive->oauth()['scope'] ?? null, 'Verbindung ohne gespeicherte Berechtigungen');
        $this->assertFalse($drive->calendarAccess(), 'OAuth geht dem Dienstkonto vor; ohne calendar.readonly kein Kalender');
        $drive->saveOAuth(self::OAUTH_DRIVE_ONLY);
        $this->assertFalse($drive->calendarAccess());
        $drive->saveOAuth(['scope' => ' ' . DriveClient::CALENDAR_SCOPE . '.foo  ' . DriveClient::SCOPE . ' '] + self::OAUTH_DRIVE_ONLY);
        $this->assertFalse($drive->calendarAccess(), 'nur genau calendar.readonly');
        $drive->saveOAuth(['scope' => DriveClient::CALENDAR_SCOPE . '   ' . DriveClient::SCOPE] + self::OAUTH_DRIVE_ONLY);
        $this->assertTrue($drive->calendarAccess(), 'durch Leerzeichen getrennt, wie Google es liefert');
        $this->assertSame(DriveClient::CALENDAR_SCOPE . '   ' . DriveClient::SCOPE, $drive->oauth()['scope'] ?? null, 'unverändert gespeichert');
        $drive->removeOAuth();
        $this->assertTrue($drive->calendarAccess(), 'nach dem Trennen wieder das Dienstkonto');
    }

    public function testServiceAccountTokenHasBothScopesAndIsCachedPerScope(): void
    {
        $google = new DriveMock();
        $app = $this->googleApp($google, new MemorySettings());
        $app->drive()->saveServiceAccount($google->serviceAccountJson());
        // Token von vor der Kalender-Erweiterung (Zwischenspeicher nur mit der E-Mail-Adresse): nicht weiterverwenden.
        $app->cache->set('drive_token', ['email' => 'galerie@test.iam.gserviceaccount.com', 'token' => 'ALT'], 3600);
        $this->assertSame('TOKEN123', $app->drive()->accessToken());
        $this->assertTrue($google->jwtValid);
        $this->assertSame(DriveClient::SCOPE . ' ' . DriveClient::CALENDAR_SCOPE, $google->jwtScope, 'JWT mit Drive und Kalender');
        $this->assertSame('TOKEN123', $app->drive()->accessToken());
        $this->assertSame(1, $google->tokens, 'danach aus dem Zwischenspeicher');
    }

    public function testSourceOrder(): void
    {
        $google = new DriveMock();
        $settings = new MemorySettings(['calendar_ids' => 'a@group.calendar.google.com', 'google_api_key' => 'KEY', 'calendar_ics_url' => 'https://example.org/k.ics']);
        $app = $this->googleApp($google, $settings);
        $cal = $app->calendar();
        $app->drive()->saveServiceAccount($google->serviceAccountJson());
        $this->assertSame('google', $cal->source(), '1. eigener API-Schlüssel');
        $settings->set('google_api_key', '');
        $this->assertSame('google_connection', $cal->source(), '2. Google-Zugang der Galerie');
        $app->drive()->saveOAuth(self::OAUTH_DRIVE_ONLY);
        $this->assertSame('ics', $cal->source(), '3. iCal, wenn die Verbindung den Kalender nicht lesen darf');
        $this->assertTrue($cal->connectionNeedsCalendarAccess());
        $settings->set('calendar_ids', '');
        $this->assertFalse($cal->connectionNeedsCalendarAccess(), 'ohne Kalender-IDs kein Hinweis');
        $settings->set('calendar_ics_url', '');
        $this->assertSame('none', $cal->source());
        $settings->set('calendar_ids', 'a@group.calendar.google.com');
        $this->assertSame('none', $cal->source(), 'Kalender-IDs allein reichen ohne Zugang mit Kalender-Berechtigung nicht');
    }

    public function testGoogleConnectionWithOAuthUsesBearerToken(): void
    {
        $google = new DriveMock();
        $google->calendarItems = self::googleItems();
        $settings = new MemorySettings(['calendar_ids' => 'a@group.calendar.google.com', 'calendar_ics_url' => 'https://example.org/k.ics']);
        $app = $this->googleApp($google, $settings);
        $app->drive()->saveOAuth(self::oauthWithCalendar());
        $this->assertSame('google_connection', $app->calendar()->source());
        $this->assertSame(['Probe'], array_column($app->calendar()->events(), 'title'), 'private Termine ausgeblendet, iCal nicht genutzt');
        $this->assertCount(1, $google->calendarRequests);
        $req = $google->calendarRequests[0];
        $this->assertSame('Bearer TOKEN123', $req['headers']['Authorization'] ?? null);
        $this->assertStringNotContainsString('key=', $req['url'], 'kein API-Schlüssel in der Adresse');
        $this->assertStringContainsString('a%40group.calendar.google.com/events', $req['url']);
        $this->assertStringContainsString('singleEvents=true', $req['url']);
        $this->assertSame('refresh_token', $google->refreshForm['grant_type'] ?? null, 'Token über die OAuth-Verbindung');

        $s = $app->settingsService()->get();
        $this->assertSame('google_connection', $s['calendar']['source']);
        $this->assertFalse($s['calendar']['connection_needs_calendar_access']);
        $this->assertTrue($s['gallery']['calendar_access']);
    }

    public function testGoogleConnectionWithServiceAccountPagingAndParallel(): void
    {
        $google = new DriveMock();
        $settings = new MemorySettings(['calendar_ids' => 'a@group.calendar.google.com, b@group.calendar.google.com']);
        $inner = $google->http()->handler;
        $http = new FakeHttpClient(static function (string $m, string $url, array $h, ?string $b) use ($inner): array {
            if (!str_starts_with($url, 'https://www.googleapis.com/calendar/v3/')) {
                return $inner($m, $url, $h, $b);
            }
            if (($h['Authorization'] ?? '') !== 'Bearer TOKEN123' || str_contains($url, 'key=')) {
                return ['status' => 401, 'body' => '{}'];
            }
            $cal = str_contains($url, 'b%40') ? 'b' : 'a';
            $page = str_contains($url, 'pageToken=') ? 1 : 0;
            $item = ['id' => $cal . $page, 'summary' => "Termin $cal$page", 'start' => ['date' => '2030-05-0' . ($page + 1)], 'end' => ['date' => '2030-05-0' . ($page + 2)]];
            return ['status' => 200, 'body' => (string) json_encode(['items' => [$item]] + ($page === 0 && $cal === 'a' ? ['nextPageToken' => 'P2'] : []))];
        });
        $app = TestApp::create(TestApp::connection(), new FakeUsers(), [], $http, null, $settings);
        $app->drive()->saveServiceAccount($google->serviceAccountJson());
        $this->assertEqualsCanonicalizing(['Termin a0', 'Termin a1', 'Termin b0'], array_column($app->calendar()->events(), 'title'), 'mit Weiterblättern, Token auch auf Seite 2');
        $this->assertCount(1, $http->batches, 'beide Kalender über requestAll');
        $this->assertCount(2, $http->batches[0]);
    }

    public function testGoogleConnectionErrorHints(): void
    {
        $google = new DriveMock();
        $google->calendarStatus = 404;
        $settings = new MemorySettings(['calendar_ids' => 'a@group.calendar.google.com']);
        $app = $this->googleApp($google, $settings);
        $app->drive()->saveServiceAccount($google->serviceAccountJson());
        try {
            $app->calendar()->events();
            $this->fail('Fehler erwartet');
        } catch (ApiError $e) {
            $this->assertSame(502, $e->status);
            $this->assertStringContainsString('Not Found', $e->getMessage());
            $this->assertStringContainsString('für galerie@test.iam.gserviceaccount.com freigegeben', $e->getMessage());
            $this->assertStringContainsString('Google Calendar API', $e->getMessage());
        }

        $google->calendarStatus = 403;
        $app->drive()->saveOAuth(self::oauthWithCalendar());
        $app->calendar()->clearCache();
        try {
            $app->calendar()->events();
            $this->fail('Fehler erwartet');
        } catch (ApiError $e) {
            $this->assertStringContainsString('Kann das verbundene Google-Konto (galerie@example.org) den Kalender sehen', $e->getMessage());
        }

        // Verbindung abgelaufen: Meldung des Token-Endpunkts.
        $google->validRefreshToken = 'anderes';
        $app->cache->delete('drive_token');
        $app->calendar()->clearCache();
        try {
            $app->calendar()->events();
            $this->fail('Fehler erwartet');
        } catch (ApiError $e) {
            $this->assertSame(502, $e->status);
            $this->assertStringContainsString('abgelaufen oder wurde widerrufen', $e->getMessage());
        }

        // Eigener API-Schlüssel: kein Hinweis auf den Google-Zugang.
        $settings->set('google_api_key', 'KEY');
        $app->calendar()->clearCache();
        try {
            $app->calendar()->events();
            $this->fail('Fehler erwartet');
        } catch (ApiError $e) {
            $this->assertStringNotContainsString('Calendar API', $e->getMessage());
        }
    }

    public function testOAuthWithoutCalendarScopeFallsBackToIcsWithHint(): void
    {
        $google = new DriveMock();
        $settings = new MemorySettings(['calendar_ids' => 'a@group.calendar.google.com', 'calendar_ics_url' => 'https://example.org/k.ics']);
        $app = $this->googleApp($google, $settings);
        $app->drive()->saveServiceAccount($google->serviceAccountJson());
        $app->drive()->saveOAuth(self::OAUTH_DRIVE_ONLY);
        $this->assertSame('ics', $app->calendar()->source());
        $this->assertCount(2, $app->calendar()->events(), 'Termine aus der iCal-Adresse');
        $this->assertSame([], $google->calendarRequests);

        $s = $app->settingsService()->get();
        $this->assertTrue($s['calendar']['connection_needs_calendar_access']);
        $this->assertFalse($s['gallery']['calendar_access']);
        $item = array_values(array_filter($app->status()->report()['items'], static fn (array $i): bool => $i['key'] === 'calendar'))[0];
        $this->assertSame('ok', $item['level']);
        $this->assertStringContainsString('über die iCal-Adresse', $item['text']);
        $this->assertStringContainsString('neu verbunden', $item['text']);

        // Ohne iCal-Adresse: nicht eingerichtet, gelber Hinweis.
        $app->settingsService()->update(['calendar_ics_url' => '', 'calendar_ids' => 'a@group.calendar.google.com']);
        $item = array_values(array_filter($app->status()->report()['items'], static fn (array $i): bool => $i['key'] === 'calendar'))[0];
        $this->assertSame('warn', $item['level']);
        $this->assertFalse($app->calendar()->isConfigured());

        // Trennen: Das Dienstkonto liest den Kalender; der Zwischenspeicher wird geleert.
        $google->calendarItems = self::googleItems();
        $s = $app->settingsService()->update(['drive_oauth' => '']);
        $this->assertSame('google_connection', $s['calendar']['source']);
        $this->assertSame(['Probe'], array_column($app->calendar()->events(), 'title'));
        $item = array_values(array_filter($app->status()->report()['items'], static fn (array $i): bool => $i['key'] === 'calendar'))[0];
        $this->assertSame('ok', $item['level']);
        $this->assertStringContainsString('über den Google-Zugang der Galerie', $item['text']);
    }
}
