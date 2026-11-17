<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\EventResponseService;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * Bericht als PDF (Domain\ReportService): Abschnitte je Recht und Bereich, Zeitraum, Summen, persönliche Angaben nur mit
 * view_personal, Eintrag im Änderungsprotokoll, keine Daten in der Vorschau.
 */
final class ReportTest extends TestCase
{
    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $anna;
    private int $bert;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->app = TestApp::create(TestApp::connection(), $this->users, clock: new FixedClock(new \DateTimeImmutable('2026-11-16 12:00:00', new \DateTimeZone('Europe/Berlin'))));
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->anna = $this->users->add('anna', Roles::MEMBER);
        $this->bert = $this->users->add('bert', Roles::MEMBER);
        $this->users->users[$this->anna]['email'] = 'anna@example.org';
        $this->users->current = $this->admin;
    }

    /** @param array<string, mixed> $query */
    private function get(string $path, array $query = [], ?string $preview = null): Response
    {
        return $this->app->api()->handle(new Request('GET', $path, $query, [], [], '', $preview));
    }

    /** @param array<string, mixed> $body */
    private function call(string $method, string $path, array $body = []): Response
    {
        $res = $this->app->api()->handle(new Request($method, $path, [], $body));
        $this->assertLessThan(300, $res->status, (string) json_encode($res->data));
        return $res;
    }

    /** @param list<string> $caps */
    private function withRights(string $login, array $caps): int
    {
        $key = $this->app->roles()->create(['label' => 'Rolle ' . $login, 'capabilities' => [...Roles::TEMPLATES[Roles::MEMBER], ...$caps]])['key'];
        return $this->users->add($login, $key);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function section(array $data, string $key): array
    {
        foreach ($data['sections'] as $s) {
            if ($s['key'] === $key) {
                return $s;
            }
        }
        self::fail('Abschnitt fehlt: ' . $key);
    }

    public function testPointsPenaltiesAndAttendanceInRange(): void
    {
        $this->call('POST', '/points', ['user_ids' => [$this->anna, $this->bert], 'points' => 3, 'reason' => 'Schießen', 'date' => '2026-03-01']);
        $this->call('POST', '/points', ['user_ids' => [$this->anna], 'points' => 2, 'reason' => 'Fest', 'date' => '2026-05-10']);
        $this->call('POST', '/points', ['user_ids' => [$this->anna], 'points' => 9, 'reason' => 'Vorjahr', 'date' => '2025-12-31']);
        $cancelled = $this->call('POST', '/points', ['user_ids' => [$this->bert], 'points' => 5, 'reason' => 'Storniert', 'date' => '2026-04-01'])->data[0]['id'];
        $this->call('DELETE', '/points/' . $cancelled);
        $penalty = $this->call('POST', '/penalties', ['user_ids' => [$this->anna], 'amount_cents' => 250, 'reason' => 'Zu spät', 'date' => '2026-02-01'])->data[0]['id'];
        $this->call('POST', '/penalties', ['user_ids' => [$this->bert], 'amount_cents' => 1000, 'reason' => 'Handy', 'date' => '2026-06-01']);
        $this->call('PATCH', '/penalties/' . $penalty, ['status' => 'paid']);
        $this->call('PUT', '/attendance', ['source' => 'free', 'title' => 'Probe', 'date' => '2026-04-02', 'entries' => [$this->anna => 'present', $this->bert => 'absent']]);
        $this->call('PUT', '/attendance', ['source' => 'free', 'title' => 'Vorjahr', 'date' => '2025-04-02', 'entries' => [$this->anna => 'present']]);

        $res = $this->get('/admin/report', ['from' => '2026-01-01', 'to' => '2026-12-31', 'sections' => 'attendance,points,penalties']);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $data = $res->data;
        $this->assertSame(['2026-01-01', '2026-12-31'], [$data['from'], $data['to']]);
        $this->assertSame(['points', 'penalties', 'attendance'], array_column($data['sections'], 'key'), 'Reihenfolge des Berichts');

        $points = self::section($data, 'points');
        $this->assertTrue($points['dated']);
        $this->assertSame([['Anna', '5', '2'], ['Bert', '3', '1']], $points['tables'][0]['rows'], 'Summe je Mitglied, ohne Storniertes und Vorjahr');
        $this->assertSame([
            ['01.03.2026', 'Anna', '3', 'Schießen'],
            ['01.03.2026', 'Bert', '3', 'Schießen'],
            ['10.05.2026', 'Anna', '2', 'Fest'],
        ], $points['tables'][1]['rows']);
        $this->assertTrue($points['tables'][1]['columns'][2]['num']);

        $penalties = self::section($data, 'penalties');
        $this->assertSame([['Anna', '2,50 €', '0,00 €'], ['Bert', '10,00 €', '10,00 €'], ['Gesamt', '12,50 €', '10,00 €']], $penalties['tables'][0]['rows']);
        $this->assertSame(['01.02.2026', 'Anna', '2,50 €', 'Zu spät', 'bezahlt am 16.11.2026'], $penalties['tables'][1]['rows'][0]);
        $this->assertSame('offen', $penalties['tables'][1]['rows'][1][4]);

        $attendance = self::section($data, 'attendance');
        $this->assertSame([['Anna', '1', '0', '0'], ['Bert', '0', '0', '1']], $attendance['tables'][0]['rows']);
        $this->assertSame([['02.04.2026', 'Probe', '1', '0', '1']], $attendance['tables'][1]['rows']);

        // Im Änderungsprotokoll: wer, Zeitraum und Abschnitte, ohne Inhalt
        $log = $this->get('/admin/changes', ['from' => '2026-11-16', 'to' => '2026-11-16'])->data['entries'];
        $report = array_values(array_filter($log, static fn (array $e): bool => $e['type'] === 'Bericht'));
        $this->assertCount(1, $report);
        $this->assertSame('Bericht als PDF: 01.01.2026 bis 31.12.2026 (Punkte, Strafen, Anwesenheit)', $report[0]['label']);
        $this->assertSame('event', $report[0]['action']);
    }

    public function testEmptyRangeKeepsSectionsWithHint(): void
    {
        $data = $this->get('/admin/report', ['from' => '2020-01-01', 'to' => '2020-01-31', 'sections' => ['points']])->data;
        $table = self::section($data, 'points')['tables'][0];
        $this->assertSame([], $table['rows']);
        $this->assertNotSame('', $table['empty']);
    }

    public function testMembersPersonalOnlyWithViewPersonal(): void
    {
        $this->app->memberContacts()->setPhone($this->anna, '+49 2151 1', '2026-01-01 00:00:00');
        $full = self::section($this->get('/admin/report', ['from' => '2026-01-01', 'to' => '2026-12-31', 'sections' => 'members'])->data, 'members');
        $this->assertFalse($full['dated']);
        $this->assertTrue($full['landscape']);
        $labels = array_column($full['tables'][0]['columns'], 'label');
        $this->assertContains('E-Mail', $labels);
        $anna = array_values(array_filter($full['tables'][0]['rows'], static fn (array $r): bool => $r[0] === 'Anna'))[0];
        $this->assertContains('anna@example.org', $anna);
        $this->assertContains('+49 2151 1', $anna);

        $this->users->current = $this->withRights('punktwart', ['view_points', 'manage_points']);
        $sections = array_column($this->get('/admin/report/sections')->data, 'key');
        $this->assertSame(['members', 'points'], $sections, 'nur, was die Person verwalten darf');
        $plain = self::section($this->get('/admin/report', ['from' => '2026-01-01', 'to' => '2026-12-31', 'sections' => 'members'])->data, 'members');
        $this->assertNotContains('E-Mail', array_column($plain['tables'][0]['columns'], 'label'));
        $this->assertFalse($plain['landscape']);
        $this->assertStringNotContainsString('anna@example.org', (string) json_encode($plain));
        $this->assertStringNotContainsString('2151', (string) json_encode($plain));
    }

    public function testFurtherSections(): void
    {
        $this->app->features()->update(['events' => true, 'calendar' => true, 'ranks' => true, 'titles' => true, 'offices' => true, 'inventory' => true, 'passive' => true]);
        $this->app->settings->set(EventResponseService::ENABLED_SETTING, true);
        $this->call('POST', '/events', ['title' => 'Schützenfest', 'start_date' => '2026-07-04', 'end_date' => '2026-07-06']);
        $this->call('POST', '/events', ['title' => 'Vorjahr', 'start_date' => '2025-07-04']);
        $this->call('PATCH', '/admin/settings', ['calendar_source' => 'app']);
        $entry = $this->call('POST', '/calendar/entries', ['title' => 'Arbeitsdienst', 'start_date' => '2026-11-20', 'start_time' => '10:00', 'end_time' => '12:00', 'location' => 'Halle'])->data;
        $this->users->current = $this->anna;
        $this->call('PUT', '/calendar/i' . $entry['id'] . '/response', ['status' => 'yes']);
        $this->users->current = $this->bert;
        $this->call('PUT', '/calendar/i' . $entry['id'] . '/response', ['status' => 'no']);
        $this->users->current = $this->admin;
        $rank = $this->call('POST', '/ranks', ['title' => 'Gefreiter'])->data['id'];
        $this->call('PUT', "/members/{$this->anna}/rank", ['rank_id' => $rank]);
        $title = $this->call('POST', '/titles', ['title' => 'König'])->data['id'];
        $this->call('PUT', "/members/{$this->bert}/titles", ['title_ids' => [$title]]);
        $office = $this->call('POST', '/offices', ['title' => 'Hauptmann'])->data['id'];
        $this->call('POST', "/offices/$office/terms", ['kind' => 'period', 'start_year' => 2020, 'holders' => [['user_id' => $this->anna, 'name' => 'Anna']]]);
        $this->call('POST', "/offices/$office/terms", ['kind' => 'period', 'start_year' => 2010, 'end_year' => 2015, 'holders' => [['name' => 'Früher']]]);
        $this->call('POST', '/inventory', ['title' => 'Bierbank', 'quantity' => 10, 'location' => 'Lager']);
        $this->call('POST', '/passive-members', ['first_name' => 'Paul', 'last_name' => 'Passiv', 'caretaker_id' => $this->anna, 'email' => 'paul@example.org']);

        $keys = 'passive,events,calendar,ranks,titles,offices,inventory';
        $this->assertSame(['members', 'passive', 'points', 'penalties', 'attendance', 'events', 'calendar', 'ranks', 'titles', 'offices', 'inventory'], array_column($this->get('/admin/report/sections')->data, 'key'));
        $res = $this->get('/admin/report', ['from' => '2026-01-01', 'to' => '2026-12-31', 'sections' => $keys]);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $data = $res->data;
        $this->assertSame(explode(',', $keys), array_column($data['sections'], 'key'));

        $this->assertSame([['04.07.2026 – 06.07.2026', 'ganztägig', 'Schützenfest', '']], self::section($data, 'events')['tables'][0]['rows']);
        $calendar = self::section($data, 'calendar')['tables'];
        $this->assertSame([['20.11.2026', '10:00–12:00 Uhr', 'Arbeitsdienst', 'Halle']], $calendar[0]['rows']);
        $this->assertSame([['20.11.2026', 'Arbeitsdienst', '1', '1']], $calendar[1]['rows']);
        $this->assertSame([['Anna', '1', '0'], ['Bert', '0', '1']], $calendar[2]['rows']);
        $this->assertSame(['16.11.2026', 'Anna', 'Beförderung', '', 'Gefreiter'], array_slice(self::section($data, 'ranks')['tables'][0]['rows'][0], 0, 5));
        $this->assertSame([['16.11.2026', 'Bert', 'König']], self::section($data, 'titles')['tables'][0]['rows']);
        $this->assertSame([['Hauptmann', 'seit 2020', 'Anna', '']], self::section($data, 'offices')['tables'][0]['rows'], 'ohne die Amtszeit 2010–2015');
        $inventory = self::section($data, 'inventory')['tables'];
        $this->assertSame([['Bierbank', '', '10', 'Lager']], $inventory[0]['rows']);
        $this->assertSame('Angelegt', $inventory[1]['rows'][0][2]);
        $passive = self::section($data, 'passive');
        $this->assertFalse($passive['dated']);
        $this->assertSame('Paul Passiv', $passive['tables'][0]['rows'][0][0]);
        $this->assertSame('Anna', $passive['tables'][0]['rows'][0][1]);
        $this->assertStringContainsString('paul@example.org', (string) json_encode($passive));

        // Vorjahr: keine Wechsel, Titel, Zu-/Absagen; der Bestand ist trotzdem da
        $old = $this->get('/admin/report', ['from' => '2025-01-01', 'to' => '2025-12-31', 'sections' => $keys])->data;
        $this->assertSame([], self::section($old, 'ranks')['tables'][0]['rows']);
        $this->assertSame([], self::section($old, 'titles')['tables'][0]['rows']);
        $this->assertSame([], self::section($old, 'calendar')['tables'][1]['rows']);
        $this->assertSame([['Bierbank', '', '10', 'Lager']], self::section($old, 'inventory')['tables'][0]['rows']);
        $this->assertSame([], self::section($old, 'inventory')['tables'][1]['rows']);

        // Passive ohne view_personal: ohne Kontaktdaten
        $this->users->current = $this->withRights('passivwart', ['view_passive', 'manage_passive']);
        $plain = self::section($this->get('/admin/report', ['from' => '2026-01-01', 'to' => '2026-12-31', 'sections' => 'passive'])->data, 'passive');
        $this->assertStringNotContainsString('paul@example.org', (string) json_encode($plain));
        $this->assertFalse($plain['landscape']);
    }

    public function testCalendarOnlyWithAppEntriesOrResponses(): void
    {
        $this->app->features()->update(['calendar' => true]);
        $this->app->settings->delete(EventResponseService::ENABLED_SETTING);
        $this->call('PATCH', '/admin/settings', ['calendar_source' => 'ics']);
        $this->assertNotContains('calendar', array_column($this->get('/admin/report/sections')->data, 'key'));
        $this->app->settings->set(EventResponseService::ENABLED_SETTING, true);
        $this->assertContains('calendar', array_column($this->get('/admin/report/sections')->data, 'key'));
        $tables = self::section($this->get('/admin/report', ['from' => '2026-01-01', 'to' => '2026-12-31', 'sections' => 'calendar'])->data, 'calendar')['tables'];
        $this->assertSame(['Zu- und Absagen je Termin', 'Zu- und Absagen je Mitglied'], array_column($tables, 'title'), 'ohne Termine aus der App');
    }

    public function testRightsFeaturesAndInput(): void
    {
        $this->users->current = $this->withRights('punktwart', ['view_points', 'manage_points']);
        $res = $this->get('/admin/report', ['from' => '2026-01-01', 'to' => '2026-12-31', 'sections' => 'points,penalties']);
        $this->assertSame(403, $res->status, 'ein Abschnitt ohne Recht: kein Bericht, statt ihn still wegzulassen');

        $this->users->current = $this->anna;
        $this->assertSame(403, $this->get('/admin/report/sections')->status, 'Mitglieder ohne Verwaltungsrechte');

        $this->users->current = $this->admin;
        $this->app->features()->update(['points' => false]);
        $this->assertNotContains('points', array_column($this->get('/admin/report/sections')->data, 'key'));
        $this->assertSame(403, $this->get('/admin/report', ['from' => '2026-01-01', 'to' => '2026-12-31', 'sections' => 'points'])->status);

        foreach ([
            ['from' => '2026-02-01', 'to' => '2026-01-01', 'sections' => 'members'],
            ['from' => '2026-01-01', 'to' => 'morgen', 'sections' => 'members'],
            ['from' => '2000-01-01', 'to' => '2026-01-01', 'sections' => 'members'],
            ['from' => '2026-01-01', 'to' => '2026-12-31', 'sections' => ''],
            ['from' => '2026-01-01', 'to' => '2026-12-31', 'sections' => 'members,geheim'],
        ] as $query) {
            $this->assertSame(400, $this->get('/admin/report', $query)->status, (string) json_encode($query));
        }
    }

    public function testNoReportInPreview(): void
    {
        $role = $this->app->roles()->create(['label' => 'Punktwart', 'capabilities' => [...Roles::TEMPLATES[Roles::MEMBER], 'view_points', 'manage_points']])['key'];
        $this->assertSame(['members', 'points'], array_column($this->get('/admin/report/sections', [], $role)->data, 'key'), 'Auswahl wie für die Rolle');
        $res = $this->get('/admin/report', ['from' => '2026-01-01', 'to' => '2026-12-31', 'sections' => 'points'], $role);
        $this->assertSame(403, $res->status);
        $this->assertStringContainsString('Vorschau', (string) json_encode($res->data, JSON_UNESCAPED_UNICODE));
    }
}
