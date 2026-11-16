<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
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

        $this->users->current = $this->withRights('punktwart', ['manage_points']);
        $sections = array_column($this->get('/admin/report/sections')->data, 'key');
        $this->assertSame(['members', 'points'], $sections, 'nur, was die Person verwalten darf');
        $plain = self::section($this->get('/admin/report', ['from' => '2026-01-01', 'to' => '2026-12-31', 'sections' => 'members'])->data, 'members');
        $this->assertNotContains('E-Mail', array_column($plain['tables'][0]['columns'], 'label'));
        $this->assertFalse($plain['landscape']);
        $this->assertStringNotContainsString('anna@example.org', (string) json_encode($plain));
        $this->assertStringNotContainsString('2151', (string) json_encode($plain));
    }

    public function testRightsFeaturesAndInput(): void
    {
        $this->users->current = $this->withRights('punktwart', ['manage_points']);
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
        $role = $this->app->roles()->create(['label' => 'Punktwart', 'capabilities' => [...Roles::TEMPLATES[Roles::MEMBER], 'manage_points']])['key'];
        $this->assertSame(['members', 'points'], array_column($this->get('/admin/report/sections', [], $role)->data, 'key'), 'Auswahl wie für die Rolle');
        $res = $this->get('/admin/report', ['from' => '2026-01-01', 'to' => '2026-12-31', 'sections' => 'points'], $role);
        $this->assertSame(403, $res->status);
        $this->assertStringContainsString('Vorschau', (string) json_encode($res->data, JSON_UNESCAPED_UNICODE));
    }
}
