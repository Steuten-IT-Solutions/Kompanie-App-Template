<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\RoleService;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Storage\Backup;
use KompanieApp\Core\Storage\UserData;
use KompanieApp\Core\Tests\Support\FakeHttpClient;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\MemorySettings;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * Zu- und Absagen bei Terminen des internen Kalenders (EventResponseService): eigene Rückmeldung mit Hinweis, Zähler für
 * alle, Namen nur für alle, die Termine pflegen, Frist bis Beginn bzw. Anmeldeschluss, Recht respond_calendar, stabile
 * Kennung bei iCal, Daten der Person (Löschen, Sicherung), Aufräumen.
 */
final class EventResponsesTest extends TestCase
{
    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $anna;
    private int $ben;
    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        // 14. November 2026, 12 Uhr in Berlin
        $this->clock = new FixedClock(new \DateTimeImmutable('2026-11-14 11:00:00', new \DateTimeZone('UTC')));
        $this->app = TestApp::create(TestApp::connection(), $this->users, clock: $this->clock);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->anna = $this->users->add('anna', Roles::MEMBER);
        $this->ben = $this->users->add('ben', Roles::MEMBER);
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
    private function ok(string $method, string $path, array $body = [], ?int $as = -1): array
    {
        $res = $this->call($method, $path, $body, $as);
        $this->assertLessThan(300, $res->status, $method . ' ' . $path . ': ' . json_encode($res->data));
        return (array) $res->data;
    }

    /** Quelle „In der App“ mit einem Termin; Kennung wie in GET /calendar. */
    private function appEvent(array $fields = []): string
    {
        $this->ok('PATCH', '/admin/settings', ['calendar_source' => 'app']);
        $entry = $this->ok('POST', '/calendar/entries', $fields + ['title' => 'Arbeitsdienst', 'start_date' => '2026-11-20', 'start_time' => '10:00']);
        return 'i' . $entry['id'];
    }

    /** @return array<string, mixed> responses des Termins aus GET /calendar für $as */
    private function responses(string $id, ?int $as = -1): array
    {
        foreach ($this->ok('GET', '/calendar', [], $as) as $e) {
            if ($e['id'] === $id) {
                return $e['responses'];
            }
        }
        $this->fail("Termin $id fehlt");
    }

    public function testRespondChangeAndWithdraw(): void
    {
        $id = $this->appEvent();
        $none = $this->responses($id, $this->anna);
        $this->assertSame([null, '', 0, 0, true, true, false], [$none['mine'], $none['note'], $none['yes'], $none['no'], $none['open'], $none['can_respond'], $none['can_list']]);
        $this->assertSame('2026-11-20T10:00:00+01:00', $none['until'], 'Frist: Beginn');

        $after = $this->ok('PUT', "/calendar/$id/response", ['status' => 'yes', 'note' => '  komme   später '], $this->anna);
        $this->assertSame(['yes', 'komme später', 1, 0], [$after['responses']['mine'], $after['responses']['note'], $after['responses']['yes'], $after['responses']['no']]);
        $this->ok('PUT', "/calendar/$id/response", ['status' => 'no'], $this->ben);
        $this->assertSame(['yes', 1, 1], [$this->responses($id, $this->anna)['mine'], $this->responses($id, $this->anna)['yes'], $this->responses($id, $this->anna)['no']]);
        $this->assertSame('no', $this->responses($id, $this->ben)['mine']);

        // Umentscheiden und zurücknehmen
        $this->ok('PUT', "/calendar/$id/response", ['status' => 'no', 'note' => ''], $this->anna);
        $this->assertSame([0, 2], [$this->responses($id)['yes'], $this->responses($id)['no']]);
        $back = $this->ok('DELETE', "/calendar/$id/response", [], $this->anna);
        $this->assertNull($back['responses']['mine']);
        $this->assertSame(1, $back['responses']['no']);

        $this->assertSame(400, $this->call('PUT', "/calendar/$id/response", ['status' => 'maybe'], $this->anna)->status, 'nur zusagen oder absagen');
        $this->assertSame(400, $this->call('PUT', "/calendar/$id/response", ['status' => 'yes', 'note' => str_repeat('x', 192)], $this->anna)->status);
        $this->assertSame(404, $this->call('PUT', '/calendar/i999/response', ['status' => 'yes'], $this->anna)->status);
    }

    public function testNamesOnlyForThoseWhoMaintainEvents(): void
    {
        $id = $this->appEvent();
        $this->ok('PUT', "/calendar/$id/response", ['status' => 'yes', 'note' => 'mit Fahrrad'], $this->anna);
        $this->ok('PUT', "/calendar/$id/response", ['status' => 'no', 'note' => 'Urlaub'], $this->ben);
        $this->assertSame(403, $this->call('GET', "/calendar/$id/responses", [], $this->anna)->status);

        $list = $this->ok('GET', "/calendar/$id/responses");
        $this->assertSame([['id' => $this->anna, 'name' => 'Anna', 'note' => 'mit Fahrrad']], $list['yes']);
        $this->assertSame([['id' => $this->ben, 'name' => 'Ben', 'note' => 'Urlaub']], $list['no']);
        $this->assertSame(['Admin'], array_column($list['none'], 'name'), 'noch nicht gemeldet');
        $this->assertTrue($list['event']['responses']['can_list']);

        // Mit dem Recht manage_calendar (Rolle „Kalender verwalten“) auch ohne Admin
        $this->users->setRole($this->ben, 'calendar');
        $this->assertSame(200, $this->call('GET', "/calendar/$id/responses", [], $this->ben)->status);
        $this->assertTrue($this->responses($id, $this->ben)['can_list']);
    }

    public function testDeadlineIsStartOrEndOfRespondUntil(): void
    {
        $id = $this->appEvent(['respond_until' => '2026-11-17']);
        $this->assertSame('2026-11-18T00:00:00+01:00', $this->responses($id)['until'], 'bis Ende des Anmeldeschlusses');
        $this->assertSame(400, $this->call('POST', '/calendar/entries', ['title' => 'X', 'start_date' => '2026-11-20', 'respond_until' => '2026-11-21'])->status, 'nach dem Beginn');
        $this->assertSame(400, $this->call('POST', '/calendar/entries', ['title' => 'X', 'start_date' => '2026-11-20', 'respond_until' => 'bald'])->status);

        $this->ok('PUT', "/calendar/$id/response", ['status' => 'yes'], $this->anna);
        $this->clock->at = new \DateTimeImmutable('2026-11-17 23:00:00', new \DateTimeZone('UTC'));
        $this->assertFalse($this->responses($id)['open']);
        $res = $this->call('PUT', "/calendar/$id/response", ['status' => 'no'], $this->anna);
        $this->assertSame([409, 'responses_closed'], [$res->status, $res->data['code'] ?? null]);
        $this->assertSame(409, $this->call('DELETE', "/calendar/$id/response", [], $this->anna)->status);
        $this->assertSame('yes', $this->responses($id, $this->anna)['mine'], 'bleibt stehen');

        // Anmeldeschluss entfernt: bis zum Beginn
        $this->ok('PATCH', '/calendar/entries/' . substr($id, 1), ['respond_until' => '']);
        $this->assertTrue($this->responses($id)['open']);
        $this->assertNull($this->ok('GET', '/calendar/entries')[0]['respond_until']);

        // Ganztägig: bis 0 Uhr des Tages
        $day = 'i' . $this->ok('POST', '/calendar/entries', ['title' => 'Fest', 'start_date' => '2026-11-19'])['id'];
        $this->assertSame('2026-11-19T00:00:00+01:00', $this->responses($day)['until']);
        $this->assertSame(200, $this->call('PUT', "/calendar/$day/response", ['status' => 'yes'], $this->anna)->status);
        $this->clock->at = new \DateTimeImmutable('2026-11-18 23:30:00', new \DateTimeZone('UTC'));
        $this->assertSame(409, $this->call('PUT', "/calendar/$day/response", ['status' => 'no'], $this->anna)->status);
    }

    public function testRightRespondCalendar(): void
    {
        $id = $this->appEvent();
        $this->assertSame([Capability::Calendar], array_values(array_filter([Capability::RespondCalendar->requires()])));
        // Gast mit Konto und dem Kalender: sieht die Zähler, sagt aber nur mit dem Recht zu.
        $guest = $this->users->add('gast', Roles::GUEST);
        $this->ok('PATCH', '/admin/roles/guest', ['capabilities' => [...$this->app->roles()->find(Roles::GUEST)['capabilities'], 'calendar']]);
        $this->assertFalse($this->responses($id, $guest)['can_respond']);
        $this->assertSame(403, $this->call('PUT', "/calendar/$id/response", ['status' => 'yes'], $guest)->status);
        $this->ok('PATCH', '/admin/roles/guest', ['capabilities' => [...$this->app->roles()->find(Roles::GUEST)['capabilities'], 'respond_calendar']]);
        $this->assertSame(200, $this->call('PUT', "/calendar/$id/response", ['status' => 'yes'], $guest)->status, 'Gast darf es, wenn der Admin will');
        $this->assertSame(1, $this->responses($id)['yes']);

        // Ohne Kalender kein Zusagen (Voraussetzung)
        $res = $this->call('POST', '/admin/roles', ['label' => 'Nur Zusagen', 'capabilities' => ['respond_calendar']]);
        $this->assertSame(400, $res->status);
        $this->assertStringContainsString('internen Kalender', (string) ($res->data['message'] ?? ''));

        // Wem das Recht fehlt, zählt nicht mehr (die Rückmeldung bleibt gespeichert).
        $this->ok('PUT', "/calendar/$id/response", ['status' => 'yes'], $this->anna);
        $this->assertSame(2, $this->responses($id)['yes']);
        $this->ok('PUT', "/members/{$this->anna}/rights", ['grant' => [], 'revoke' => ['respond_calendar']]);
        $this->assertSame(1, $this->responses($id)['yes']);
        $this->assertSame(403, $this->call('PUT', "/calendar/$id/response", ['status' => 'no'], $this->anna)->status);
    }

    public function testExistingRolesWithCalendarGetTheRightOnce(): void
    {
        $settings = new MemorySettings();
        $users = new FakeUsers();
        $db = TestApp::connection();
        TestApp::create($db, $users, settings: $settings, features: false);
        $settings->set(RoleService::SETTING, [
            ['key' => 'guest', 'label' => 'Gast', 'capabilities' => ['view_events', 'calendar']],
            ['key' => 'member', 'label' => 'Mitglied', 'capabilities' => ['member', 'calendar', 'view_board']],
            ['key' => 'r1', 'label' => 'Anwärter', 'capabilities' => ['calendar']],
            ['key' => 'r2', 'label' => 'Kasse', 'capabilities' => ['member', 'manage_points']],
            ['key' => 'admin', 'label' => 'Admin', 'capabilities' => []],
        ]);
        $settings->delete(RoleService::RESPOND_SETTING);
        $app = TestApp::create($db, $users, settings: $settings, features: false);
        $has = static fn (string $key): bool => in_array('respond_calendar', $app->roles()->find($key)['capabilities'] ?? [], true);
        $this->assertSame([false, true, true, false], [$has('guest'), $has('member'), $has('r1'), $has('r2')]);
        $this->assertTrue($settings->get(RoleService::RESPOND_SETTING));

        // Einmalig: Entzieht der Admin es danach, bleibt es entzogen.
        $app->roles()->update('r1', ['capabilities' => ['calendar']]);
        $again = TestApp::create($db, $users, settings: $settings, features: false);
        $this->assertSame([Capability::Calendar], $again->roles()->capabilities('r1'));

        // Neue Installation: Mitglied hat es, Gast nicht.
        $fresh = TestApp::create(TestApp::connection(), features: false);
        $this->assertContains(Capability::RespondCalendar, $fresh->roles()->capabilities(Roles::MEMBER));
        $this->assertNotContains(Capability::RespondCalendar, $fresh->roles()->capabilities(Roles::GUEST));
    }

    /** iCal: Die Kennung des Termins hängt am Beginn; Rückmeldungen gelten weiter, wenn er verschoben wird. */
    public function testIcsResponsesSurviveMovedEvents(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:fest\r\nDTSTART:20261120T170000Z\r\nDTEND:20261120T200000Z\r\nSUMMARY:Fest\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:probe\r\nDTSTART:20261116T180000Z\r\nDTEND:20261116T200000Z\r\nRRULE:FREQ=WEEKLY;COUNT=3\r\nSUMMARY:Probe\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $body = $ics;
        $http = new FakeHttpClient(static function () use (&$body): array {
            return ['status' => 200, 'body' => $body];
        });
        $this->app = TestApp::create(TestApp::connection(), $this->users, http: $http, clock: $this->clock);
        $this->ok('PATCH', '/admin/settings', ['calendar_source' => 'ics', 'calendar_ics_url' => 'https://example.org/k.ics']);
        $byTitle = function (): array {
            $out = [];
            foreach ($this->ok('GET', '/calendar', [], $this->anna) as $e) {
                $out[$e['title'] . ' ' . substr($e['start'], 0, 10)] = $e;
            }
            return $out;
        };
        $events = $byTitle();
        $this->assertArrayNotHasKey('response_key', $events['Fest 2026-11-20'], 'nur intern');
        $this->ok('PUT', '/calendar/' . $events['Fest 2026-11-20']['id'] . '/response', ['status' => 'yes'], $this->anna);
        $this->ok('PUT', '/calendar/' . $events['Probe 2026-11-23']['id'] . '/response', ['status' => 'no'], $this->anna);

        // Fest einen Tag später, zweite Probe einzeln verschoben (RECURRENCE-ID)
        $body = str_replace('20261120T', '20261121T', $ics);
        $body = str_replace("END:VCALENDAR\r\n", "BEGIN:VEVENT\r\nUID:probe\r\nRECURRENCE-ID:20261123T180000Z\r\nDTSTART:20261124T180000Z\r\nDTEND:20261124T200000Z\r\nSUMMARY:Probe\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n", $body);
        $this->app->calendar()->clearCache();
        $events = $byTitle();
        $this->assertArrayHasKey('Fest 2026-11-21', $events);
        $this->assertSame('yes', $events['Fest 2026-11-21']['responses']['mine'], 'nach dem Verschieben noch da');
        $this->assertArrayNotHasKey('Probe 2026-11-23', $events);
        $this->assertSame('no', $events['Probe 2026-11-24']['responses']['mine'], 'einzeln verschobener Termin einer Serie');
        $this->assertNull($events['Probe 2026-11-16']['responses']['mine'], 'andere Termine der Serie nicht');
        $this->assertNull($events['Probe 2026-11-30']['responses']['mine']);
    }

    public function testPersonalDataBackupAndCleanup(): void
    {
        $id = $this->appEvent();
        $this->ok('PUT', "/calendar/$id/response", ['status' => 'yes', 'note' => 'gern'], $this->anna);
        $this->ok('PUT', "/calendar/$id/response", ['status' => 'no'], $this->ben);
        $this->assertContains('event_responses', Backup::CORE_TABLES);
        $this->assertArrayHasKey('event_responses', UserData::tables());

        $mine = $this->ok('GET', '/me/data', [], $this->anna)['data']['event_responses'] ?? [];
        $this->assertSame([['Arbeitsdienst', '2026-11-20', 'yes', 'gern']], array_map(static fn (array $r): array => [$r['event_title'], $r['event_date'], $r['status'], $r['note']], $mine));

        UserData::purge($this->app->db, $this->anna);
        $this->assertSame([0, 1], [$this->responses($id)['yes'], $this->responses($id)['no']]);

        // Aufräumen: 90 Tage nach dem Termin
        $this->clock->at = new \DateTimeImmutable('2027-02-17 12:00:00', new \DateTimeZone('UTC'));
        $this->assertSame(0, $this->app->eventResponses()->cleanup());
        $this->clock->at = new \DateTimeImmutable('2027-02-19 12:00:00', new \DateTimeZone('UTC'));
        $this->assertSame(1, $this->app->eventResponses()->cleanup());
    }
}
