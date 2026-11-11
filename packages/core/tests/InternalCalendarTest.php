<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\CalendarService;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Push\PushService;
use KompanieApp\Core\Storage\Backup;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * Interner Kalender in der App (Quelle „app“, CalendarService): Quelle wählen, Termine pflegen mit manage_calendar,
 * getrennt von den öffentlichen Terminen, Lesen mit dem Recht calendar, Standardrolle, Benachrichtigungen,
 * Anwesenheit, Installationen ohne Auswahl unverändert, Sicherung.
 */
final class InternalCalendarTest extends TestCase
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

    private function useApp(): void
    {
        $this->ok('PATCH', '/admin/settings', ['calendar_source' => 'app']);
    }

    public function testWithoutChoiceNothingChanges(): void
    {
        $calendar = $this->ok('GET', '/admin/settings')['calendar'];
        $this->assertSame('', $calendar['choice']);
        $this->assertSame('none', $calendar['source']);
        $this->assertFalse($this->call('GET', '/config')->data['calendarInApp']);
        $res = $this->call('POST', '/calendar/entries', ['title' => 'X', 'start_date' => '2026-11-20']);
        $this->assertSame(409, $res->status, 'ohne Quelle app kein Pflegen');
        $this->assertSame('not_in_app', $res->data['code'] ?? null);
        $this->assertNull($this->app->roles()->find('calendar'), 'Rolle erst mit der Quelle app');
        $this->assertSame(400, $this->call('PATCH', '/admin/settings', ['calendar_source' => 'outlook'])->status);

        // Ohne Auswahl gilt die iCal-Adresse wie bisher, auch neben Kalender-IDs ohne Zugang
        $this->app->settings->set('calendar_ics_url', 'https://example.org/a.ics');
        $this->assertSame('ics', $this->app->calendar()->source());
    }

    public function testChoiceDecidesTheSource(): void
    {
        $s = $this->app->settings;
        $s->set('calendar_ics_url', 'https://example.org/a.ics');
        $s->set('calendar_ids', 'abc@group.calendar.google.com');
        $s->set('google_api_key', 'KEY');
        $this->assertSame('google', $this->app->calendar()->source(), 'ohne Auswahl: Kalender-IDs vor der iCal-Adresse');
        $this->ok('PATCH', '/admin/settings', ['calendar_source' => 'ics']);
        $this->assertSame('ics', $this->app->calendar()->source());
        $this->assertSame('abc@group.calendar.google.com', $s->get('calendar_ids'), 'IDs bleiben für ein Zurückwechseln');
        $this->ok('PATCH', '/admin/settings', ['calendar_source' => 'google', 'calendar_ics_url' => 'https://example.org/b.ics']);
        $this->assertSame('google', $this->app->calendar()->source());
        $this->assertSame('abc@group.calendar.google.com', $s->get('calendar_ids'), 'mit Auswahl löscht eine neue iCal-Adresse die IDs nicht');
        $this->useApp();
        $this->assertSame('app', $this->app->calendar()->source());
        $this->assertSame('app', $this->ok('GET', '/admin/settings')['calendar']['choice']);
        $this->assertTrue($this->app->calendar()->isConfigured(), 'in der App ist nichts einzurichten');

        // Gewählte Quelle ohne Angaben: nicht eingerichtet
        $s->set('google_api_key', '');
        $s->set('calendar_ids', '');
        $this->ok('PATCH', '/admin/settings', ['calendar_source' => 'google']);
        $this->assertSame('none', $this->app->calendar()->source());
    }

    public function testManageAndReadSeparatelyFromPublicEvents(): void
    {
        $this->useApp();
        $this->assertTrue($this->call('GET', '/config')->data['calendarInApp']);
        $entry = $this->ok('POST', '/calendar/entries', ['title' => '  Vorstandssitzung ', 'start_date' => '2026-11-20', 'start_time' => '19:30', 'location' => 'Vereinsheim', 'description' => 'Nur Vorstand']);
        $this->assertSame('Vorstandssitzung', $entry['title']);
        $this->ok('POST', '/calendar/entries', ['title' => 'Arbeitsdienst', 'start_date' => '2026-11-14', 'end_date' => '2026-11-15']);
        $this->ok('POST', '/events', ['title' => 'Schützenfest', 'start_date' => '2026-11-21']);
        $this->assertSame(400, $this->call('POST', '/calendar/entries', ['title' => '', 'start_date' => '2026-11-20'])->status);
        $this->assertSame(400, $this->call('POST', '/calendar/entries', ['title' => 'X', 'start_date' => 'morgen'])->status);

        // Mitglieder sehen nur den internen Kalender, Gäste nichts davon; öffentliche Termine bleiben getrennt.
        $list = $this->ok('GET', '/calendar', [], $this->member);
        $this->assertSame(['Arbeitsdienst', 'Vorstandssitzung'], array_column($list, 'title'));
        $this->assertSame('i' . $entry['id'], $list[1]['id']);
        $this->assertFalse($list[1]['all_day']);
        $this->assertSame('Vereinsheim', $list[1]['location']);
        $this->assertSame('2026-11-14', $list[0]['start']);
        $this->assertSame('2026-11-16', $list[0]['end'], 'ganztägig: Ende exklusiv');
        $this->assertSame(['Schützenfest'], array_column($this->ok('GET', '/events', [], null), 'title'));
        $this->assertSame(401, $this->call('GET', '/calendar', [], null)->status, 'ohne Anmeldung nicht');

        // Pflegen nur mit manage_calendar
        $this->assertSame(403, $this->call('POST', '/calendar/entries', ['title' => 'X', 'start_date' => '2026-11-20'], $this->member)->status);
        $this->assertSame(403, $this->call('GET', '/calendar/entries', [], $this->member)->status);
        $updated = $this->ok('PATCH', '/calendar/entries/' . $entry['id'], ['start_time' => '20:00']);
        $this->assertSame('20:00', $updated['start_time']);
        $this->assertSame(['Arbeitsdienst', 'Vorstandssitzung'], array_column($this->ok('GET', '/calendar/entries'), 'title'));
        $this->ok('DELETE', '/calendar/entries/' . $entry['id']);
        $this->assertSame(404, $this->call('DELETE', '/calendar/entries/' . $entry['id'])->status);
        $this->assertSame(['Arbeitsdienst'], array_column($this->ok('GET', '/calendar', [], $this->member), 'title'));

        // Zurück zu einer anderen Quelle: Termine bleiben gespeichert, Pflegen geht nicht mehr.
        $this->ok('PATCH', '/admin/settings', ['calendar_source' => 'ics']);
        $this->assertSame(409, $this->call('GET', '/calendar/entries')->status);
        $this->assertSame(1, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {internal_events}'));
    }

    public function testRoleComesAndGoesWithTheSource(): void
    {
        $this->useApp();
        $role = $this->app->roles()->find('calendar');
        $this->assertSame(TestApp::caps(['member', 'manage_calendar']), $role['capabilities'] ?? null);
        $this->assertSame('Kalender verwalten', $role['label'] ?? null);
        $this->ok('PATCH', '/admin/settings', ['calendar_source' => 'ics']);
        $this->assertNull($this->app->roles()->find('calendar'), 'niemand hat sie: weg');
        $this->assertContains(Capability::ManageCalendar, Roles::GRANTABLE);
        $this->assertSame(Capability::Member, Capability::ManageCalendar->requires());
    }

    public function testPushForNewAndChangedEntries(): void
    {
        $this->app->settings->set(PushService::CALENDAR_SETTING, ['alt' => ['2026-01-01', '2026-01-02', '']]);
        $this->useApp();
        $this->assertSame([], $this->app->settings->get(PushService::CALENDAR_SETTING), 'Stand neu begonnen, ohne Meldung des Alten');
        $entry = $this->ok('POST', '/calendar/entries', ['title' => 'Probe', 'start_date' => '2026-11-20']);
        $seen = $this->app->settings->get(PushService::CALENDAR_SETTING);
        $this->assertArrayHasKey('i' . $entry['id'], $seen, 'gleich gemeldet und gemerkt');
        $this->ok('PATCH', '/calendar/entries/' . $entry['id'], ['start_date' => '2026-11-21']);
        $this->assertSame('2026-11-21', $this->app->settings->get(PushService::CALENDAR_SETTING)['i' . $entry['id']][0]);
    }

    public function testAttendanceUsesEntries(): void
    {
        $this->useApp();
        $this->ok('POST', '/calendar/entries', ['title' => 'Übungsabend', 'start_date' => '2026-11-03', 'start_time' => '19:00']);
        $this->ok('POST', '/calendar/entries', ['title' => 'Später', 'start_date' => '2026-12-03']);
        $events = $this->app->attendance()->events();
        $this->assertSame(['Übungsabend'], array_column($events, 'title'), 'vergangene Termine zur Auswahl');
        $this->assertStringStartsWith('i', $events[0]['key']);
    }

    public function testBackupContainsEntries(): void
    {
        $this->assertContains('internal_events', Backup::CORE_TABLES);
        $this->assertNotNull($this->app->calendar());
        $this->assertSame(CalendarService::SOURCES, ['app', 'google', 'ics']);
    }
}
