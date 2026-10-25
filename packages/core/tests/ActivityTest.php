<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\ActivityService;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Storage\Backup;
use KompanieApp\Core\Tests\Support\FakeHttpClient;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\TestApp;
use KompanieApp\Core\Update\StatusReporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Nutzungsauswertung (Issue #175): Erfassen an den Routen, Zusammenfassen, je Person an und aus, Fristen, Löschen mit dem
 * Konto, Auswertung nur für Admins, Zahlen ohne Personenbezug für die Statusmeldungen.
 */
final class ActivityTest extends TestCase
{
    private FakeUsers $users;
    private FixedClock $clock;
    private App $app;
    private int $admin;
    private int $member;
    private int $other;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->clock = new FixedClock(new \DateTimeImmutable('2026-10-20 12:00:00', new \DateTimeZone('Europe/Berlin')));
        $this->app = TestApp::create(TestApp::connection(), $this->users, clock: $this->clock);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->member = $this->users->add('mitglied', Roles::MEMBER);
        $this->other = $this->users->add('zweites', Roles::MEMBER);
    }

    protected function tearDown(): void
    {
        TestApp::dropTables($this->app->db);
    }

    private function as(?int $user): void
    {
        $this->users->current = $user;
    }

    private function call(string $method, string $path, array $body = [], array $query = [], ?string $preview = null): Response
    {
        return $this->app->api()->handle(new Request($method, $path, $query, $body, [], '', $preview));
    }

    private function later(int $seconds): void
    {
        $this->clock->at = $this->clock->now()->modify("+$seconds seconds");
    }

    /** @return list<array{user_id: int, area: string, action: string}> */
    private function entries(): array
    {
        return array_map(static fn (array $r): array => ['user_id' => (int) $r['user_id'], 'area' => (string) $r['area'], 'action' => (string) $r['action']], $this->app->db->select('SELECT user_id, area, action FROM {activity} ORDER BY id'));
    }

    /** @return array<string, array{0: int, 1: int}> Bereich => [Personen, Aktionen] an diesem Tag */
    private function daily(string $day = '2026-10-20'): array
    {
        $out = [];
        foreach ($this->app->db->select('SELECT area, users, actions FROM {activity_daily} WHERE day = ?', [$day]) as $r) {
            $out[(string) $r['area']] = [(int) $r['users'], (int) $r['actions']];
        }
        ksort($out);
        return $out;
    }

    public function testRoutesAreRecordedWithAreaAndActionOnly(): void
    {
        $this->as($this->member);
        $this->assertSame(200, $this->call('GET', '/me/points')->status);
        $this->assertSame(200, $this->call('GET', '/events')->status);
        $this->assertSame(200, $this->call('GET', '/me')->status);
        $this->assertSame([
            ['user_id' => $this->member, 'area' => 'points', 'action' => 'view'],
            ['user_id' => $this->member, 'area' => 'events', 'action' => 'view'],
            ['user_id' => $this->member, 'area' => 'app', 'action' => 'view'],
        ], $this->entries());
        $columns = array_keys((array) $this->app->db->selectOne('SELECT * FROM {activity}'));
        sort($columns);
        $this->assertSame(['action', 'area', 'at', 'id', 'user_id'], $columns, 'keine Inhalte, keine IP-Adresse, kein User-Agent');

        $this->as($this->admin);
        $this->assertSame(201, $this->call('POST', '/events', ['title' => 'Probe', 'start_date' => '2026-11-01'])->status);
        $poll = $this->call('POST', '/polls', ['title' => 'Mitfahren?', 'type' => 'single', 'options' => ['Ja', 'Nein']]);
        $this->assertSame(201, $poll->status, (string) json_encode($poll->data));
        $this->as($this->member);
        $this->assertSame(200, $this->call('PUT', '/polls/' . $poll->data['id'] . '/vote', ['option_ids' => [$poll->data['options'][0]['id']]])->status);
        $this->assertSame(['events', 'create'], [$this->entries()[3]['area'], $this->entries()[3]['action']]);
        $this->assertSame(['polls', 'vote'], [$this->entries()[5]['area'], $this->entries()[5]['action']]);
    }

    public function testNotRecorded(): void
    {
        // Ohne Anmeldung, Konfiguration und Abgleich, abgelehnte Anfragen, Vorschau.
        $this->as(null);
        $this->call('GET', '/events');
        $this->as($this->member);
        $this->call('GET', '/config');
        $this->call('GET', '/auth/csrf');
        $this->assertSame(403, $this->call('GET', '/admin/activity')->status);
        $this->as($this->admin);
        $this->assertSame(200, $this->call('GET', '/me/points', [], [], Roles::MEMBER)->status);
        $this->assertSame([], $this->entries());
        $this->assertSame([], $this->daily());
        // Konto ohne Rechte (Gast)
        $guest = $this->users->add('gast', Roles::GUEST);
        $this->as($guest);
        $this->call('GET', '/me');
        $this->assertSame([], $this->entries());
    }

    public function testLoginIsRecorded(): void
    {
        $res = $this->call('POST', '/auth/login', ['username' => 'mitglied', 'password' => 'geheim123']);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertSame([['user_id' => $this->member, 'area' => 'login', 'action' => 'login']], $this->entries());
    }

    public function testViewsAreMergedForFifteenMinutesChangesNot(): void
    {
        $this->as($this->member);
        $this->call('GET', '/me/points');
        $this->later(600);
        $this->call('GET', '/me/points');
        $this->assertCount(1, $this->entries(), 'zweite Ansicht innerhalb von 15 Minuten zählt nicht');
        $this->later(ActivityService::VIEW_GAP);
        $this->call('GET', '/me/points');
        $this->assertCount(2, $this->entries());
        $this->as($this->admin);
        $this->call('POST', '/events', ['title' => 'A', 'start_date' => '2026-11-01']);
        $this->call('POST', '/events', ['title' => 'B', 'start_date' => '2026-11-02']);
        $this->assertCount(4, $this->entries(), 'Änderungen zählen jedes Mal');
        // Tageszahlen ohne Personenbezug: Personen je Bereich einmal am Tag, Aktionen jedes Mal.
        $this->assertSame(['_all' => [2, 4], 'events' => [1, 2], 'points' => [1, 2]], $this->daily());
    }

    public function testPersonalOffDeletesAndCountsOnlyWithoutPersons(): void
    {
        $this->assertTrue($this->app->activity()->personal(), 'Standard: je Person an');
        $this->as($this->member);
        $this->call('GET', '/me/points');
        $this->assertSame(1, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {activity_last}'));

        $this->as($this->admin);
        $this->assertSame(400, $this->call('PUT', '/admin/activity/settings', ['personal' => 'nein'])->status);
        $res = $this->call('PUT', '/admin/activity/settings', ['personal' => false]);
        $this->assertSame([200, false], [$res->status, $res->data['personal']]);
        $this->assertSame(0, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {activity}'), 'ausschalten löscht die Einträge je Person');
        $this->assertSame(0, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {activity_last}'));

        $this->as($this->other);
        $this->call('GET', '/me/points');
        $this->assertSame([], $this->entries(), 'aus: keine Einträge je Person');
        $this->assertSame([2, 2], $this->daily()['points'], 'gezählt wird weiter');
        $this->as($this->admin);
        $overview = $this->call('GET', '/admin/activity')->data;
        $this->assertFalse($overview['personal']);
        $this->assertNull($overview['members'], 'ohne Erfassung je Person keine Liste der Konten');
        $this->assertSame([], $this->call('GET', '/admin/activity/entries')->data['entries']);
    }

    public function testUsageHasOnlySumsAndIsPlausible(): void
    {
        $this->as($this->member);
        $this->call('GET', '/me/points');
        $this->later(3 * 86400);
        $this->as($this->other);
        $this->call('GET', '/events');
        $this->as($this->admin);
        $this->call('GET', '/me');
        $usage = $this->app->activity()->usage();
        $this->assertSame(['users' => 3, 'active' => ['d1' => 2, 'd7' => 3, 'd30' => 3]], ['users' => $usage['users'], 'active' => $usage['active']]);
        $this->assertSame(['app' => 1, 'events' => 1, 'points' => 1], (array) $usage['areas'], 'Personen je Bereich in den letzten 30 Tagen');
        $this->later(31 * 86400);
        $usage = $this->app->activity()->usage();
        $this->assertSame(['d1' => 0, 'd7' => 0, 'd30' => 0], $usage['active']);
        $this->assertSame('{}', json_encode($usage['areas']), 'leer als Objekt');

        // Nie mehr aktive Konten als Konten (z. B. gerade gelöschte oder stillgelegte): auf users begrenzt.
        $this->as($this->member);
        $this->call('GET', '/me');
        $this->users->users[$this->member]['level'] = Roles::GUEST;
        $this->users->users[$this->other]['level'] = Roles::GUEST;
        $this->users->users[$this->admin]['level'] = Roles::GUEST;
        $usage = $this->app->activity()->usage();
        $this->assertSame([0, 0, 0, 0], [$usage['users'], $usage['active']['d1'], $usage['active']['d7'], $usage['active']['d30']]);
    }

    public function testHeartbeatCarriesUsageEventsNot(): void
    {
        $http = new FakeHttpClient(static fn () => ['status' => 202, 'body' => '']);
        $settings = new \KompanieApp\Core\Tests\Support\MemorySettings();
        $settings->set(StatusReporter::SETTING, true);
        $app = TestApp::create(TestApp::connection(), $this->users, [], $http, null, $settings, $this->clock, packageConfig: ['statusUrl' => 'https://cockpit.example/status', 'kompanie' => 'org/test']);
        $this->as($this->member);
        $app->api()->handle(new Request('GET', '/me/points'));
        $hb = $app->statusReporter()->payload();
        $this->assertSame(3, $hb['format']);
        $this->assertSame(['users' => 3, 'active' => ['d1' => 1, 'd7' => 1, 'd30' => 1], 'areas' => ['points' => 1]], json_decode((string) json_encode($hb['usage']), true));
        $json = (string) json_encode($hb);
        $this->assertStringNotContainsString('mitglied', $json, 'keine Namen');
        $this->assertStringNotContainsString('"user_id"', $json, 'keine Benutzer-IDs');
        $event = $app->statusReporter()->payload('event', \KompanieApp\Core\Telemetry\Code::UpdateFailed);
        $this->assertArrayNotHasKey('usage', $event, 'usage nur im Lebenszeichen');
        $this->assertSame(3, $event['format']);
        TestApp::dropTables($app->db);
    }

    public function testOverviewAndEntriesOnlyForAdmins(): void
    {
        $this->as($this->member);
        $this->call('GET', '/me/points');
        $this->later(3600);
        $this->as($this->other);
        $this->call('GET', '/events');

        // Auch eine Rolle mit allen vergebbaren Rechten sieht die Auswertung nicht; view_activity ist nicht vergebbar.
        $this->as($this->admin);
        $this->assertSame(400, $this->call('POST', '/admin/roles', ['label' => 'Vorstand', 'capabilities' => ['member', 'view_activity']])->status);
        $role = $this->call('POST', '/admin/roles', ['label' => 'Vorstand', 'capabilities' => ['member', 'manage_points', 'manage_settings']]);
        $this->assertSame(400, $role->status, 'manage_settings ist ebenso wenig vergebbar');
        $res = $this->call('POST', '/admin/roles', ['label' => 'Vorstand', 'capabilities' => array_map(static fn (Capability $c): string => $c->value, \KompanieApp\Core\Auth\Roles::GRANTABLE)]);
        $this->assertSame(201, $res->status, (string) json_encode($res->data));
        $this->assertNotContains(Capability::ViewActivity, \KompanieApp\Core\Auth\Roles::GRANTABLE);
        $key = array_key_last(array_filter($this->app->roles()->all(), static fn (array $r): bool => $r['label'] === 'Vorstand'));
        $board = $this->users->add('vorstand', (string) $key);
        $this->as($board);
        $this->assertSame(403, $this->call('GET', '/admin/activity')->status);
        $this->assertSame(403, $this->call('GET', '/admin/activity/entries')->status);

        $this->as($this->admin);
        $res = $this->call('GET', '/admin/activity');
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $o = $res->data;
        $this->assertSame([true, 90, '2026-09-21', '2026-10-20'], [$o['personal'], $o['keep_days'], $o['from'], $o['to']], 'Standard: letzte 30 Tage');
        $this->assertSame([['day' => '2026-10-20', 'users' => 3, 'actions' => 3]], $o['days'], 'mit der neuen Rolle des Admins');
        $byArea = array_column($o['areas'], null, 'area');
        $this->assertSame(['area' => 'points', 'label' => 'Punkte', 'actions' => 1, 'people' => 1], $byArea['points']);
        $last = array_column($o['members'], 'last_active', 'id');
        $this->assertSame([$this->admin, $this->member, $this->other], array_keys(array_filter($last)));
        $this->assertNull($last[$board]);

        $entries = $this->call('GET', '/admin/activity/entries')->data;
        $this->assertSame([['Admin', 'app', 'create'], ['Zweites', 'events', 'view'], ['Mitglied', 'points', 'view']], array_map(static fn (array $e): array => [$e['name'], $e['area'], $e['action']], $entries['entries']), 'neueste zuerst');
        $this->assertFalse($entries['truncated']);
        $one = $this->call('GET', '/admin/activity/entries', [], ['user_id' => (string) $this->member])->data['entries'];
        $this->assertSame([$this->member], array_unique(array_column($one, 'user_id')));
        $this->assertSame([], $this->call('GET', '/admin/activity/entries', [], ['from' => '2026-10-21', 'to' => '2026-10-22'])->data['entries']);
        $this->assertSame(400, $this->call('GET', '/admin/activity', [], ['from' => '2026-10-20', 'to' => '2026-10-01'])->status);
        $this->assertSame(400, $this->call('GET', '/admin/activity', [], ['from' => '2025-01-01', 'to' => '2026-10-20'])->status);
        $this->assertSame(400, $this->call('GET', '/admin/activity', [], ['from' => 'gestern'])->status);
        $this->assertSame(400, $this->call('GET', '/admin/activity/entries', [], ['user_id' => 'x'])->status);
        $this->assertSame(403, $this->call('PUT', '/admin/activity/settings', ['personal' => false], [], Roles::MEMBER)->status, 'Vorschau: nur ansehen');
    }

    public function testPruneKeepsDailyCounts(): void
    {
        $this->as($this->member);
        $this->call('GET', '/me/points');
        $this->later(32 * 86400);
        $this->app->runTask(ActivityService::TASK);
        $this->assertSame(0, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {activity_seen}'), 'Pseudonyme nach 31 Tagen gelöscht');
        $this->assertCount(1, $this->entries(), 'Einträge je Person bleiben 90 Tage');
        $this->later(59 * 86400);
        $this->app->runTask(ActivityService::TASK);
        $this->assertSame([], $this->entries(), 'nach 90 Tagen gelöscht');
        $this->assertSame(['_all' => [1, 1], 'points' => [1, 1]], $this->daily(), 'Tageszahlen ohne Personenbezug bleiben');
        $this->assertSame(1, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {activity_last}'), 'zuletzt aktiv bleibt bis zum Löschen des Kontos');
        $this->assertContains(ActivityService::TASK, array_keys(App::TASKS));
    }

    public function testAccountDeletionRemovesEntriesAndPseudonym(): void
    {
        $this->as($this->member);
        $this->call('GET', '/me/points');
        $this->as($this->other);
        $this->call('GET', '/me/points');
        $this->app->userDeleted($this->member);
        $this->assertSame([$this->other], array_unique(array_column($this->entries(), 'user_id')));
        $this->assertSame([$this->other], array_map(static fn (array $r): int => (int) $r['user_id'], $this->app->db->select('SELECT user_id FROM {activity_last}')));
        $this->assertSame(2, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {activity_seen}'), 'nur das Pseudonym des anderen Kontos (points und irgendein Bereich)');
        $this->assertSame([2, 2], $this->daily()['points'], 'Tageszahlen bleiben');
    }

    /** @return array<string, array{string}> */
    public static function drivers(): array
    {
        return getenv('KOMPANIE_TEST_MYSQL') ? ['sqlite' => ['sqlite'], 'mysql' => ['mysql']] : ['sqlite' => ['sqlite']];
    }

    /** Schreiben mit ON CONFLICT bzw. ON DUPLICATE KEY, Zählen, Fristen und Löschen auf beiden Datenbanken. */
    #[DataProvider('drivers')]
    public function testOnEveryDatabase(string $driver): void
    {
        $db = TestApp::connection($driver);
        try {
            $app = TestApp::create($db, $this->users, clock: $this->clock);
            $this->as($this->member);
            $get = static fn (string $path) => $app->api()->handle(new Request('GET', $path));
            $get('/me/points');
            $get('/me/points');
            $this->later(ActivityService::VIEW_GAP);
            $get('/me/points');
            $this->as($this->other);
            $get('/me/points');
            $this->assertSame(3, (int) $db->scalar('SELECT COUNT(*) FROM {activity}'));
            $this->assertSame(['users' => 2, 'actions' => 3], array_map('intval', (array) $db->selectOne("SELECT users, actions FROM {activity_daily} WHERE area = 'points'")));
            $this->assertSame(2, (int) $db->scalar('SELECT COUNT(*) FROM {activity_last}'));
            $this->assertSame(['d1' => 2, 'd7' => 2, 'd30' => 2], $app->activity()->usage()['active']);
            $this->later(86400);
            $this->as($this->member);
            $get('/me/points');
            $this->assertSame(['users' => 1, 'actions' => 1], array_map('intval', (array) $db->selectOne("SELECT users, actions FROM {activity_daily} WHERE area = 'points' AND day = '2026-10-21'")), 'neuer Tag');
            $this->assertSame((int) $this->clock->now()->getTimestamp(), (int) $db->scalar('SELECT at FROM {activity_last} WHERE user_id = ?', [$this->member]));
            $app->userDeleted($this->member);
            $this->assertSame(2, (int) $db->scalar('SELECT COUNT(*) FROM {activity_seen}'));
            $this->later(100 * 86400);
            $app->activity()->prune();
            $this->assertSame([0, 0], [(int) $db->scalar('SELECT COUNT(*) FROM {activity}'), (int) $db->scalar('SELECT COUNT(*) FROM {activity_seen}')]);
            $app->activity()->setPersonal(false);
            $this->assertSame(0, (int) $db->scalar('SELECT COUNT(*) FROM {activity_last}'));
        } finally {
            TestApp::dropTables($db);
        }
    }

    public function testNotInBackups(): void
    {
        foreach (['activity', 'activity_last', 'activity_seen', 'activity_daily'] as $table) {
            $this->assertNotContains($table, Backup::CORE_TABLES);
        }
        $this->assertContains(ActivityService::SECRET_SETTING, Backup::LOCAL_SETTINGS);
    }
}
