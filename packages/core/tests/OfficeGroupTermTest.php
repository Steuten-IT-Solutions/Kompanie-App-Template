<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Storage\Backup;
use KompanieApp\Core\Storage\Sql\Schema;
use KompanieApp\Core\Storage\Sql\Migrations;
use KompanieApp\Core\Storage\Sql\Migrator;
use KompanieApp\Core\Tests\Support\MemorySettings;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * Gemeinsame Amtszeiten einer Gruppe der Ämter mit Gruppenbild (Issue #282): Schalter, Anlegen in einem Formular, je Amt
 * eine normale Amtszeit, Bündeln vorhandener und importierter Amtszeiten, Bild öffentlich mit view_offices, Titel.
 */
final class OfficeGroupTermTest extends TestCase
{
    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $m;
    private int $group;
    private int $king;
    private int $queen;
    private int $minister;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $features = ['events' => false, 'calendar' => false, 'news' => false, 'gallery' => false, 'points' => false, 'penalties' => false, 'ranks' => false, 'titles' => true, 'directory' => true, 'offices' => true];
        $this->app = TestApp::create(TestApp::connection(), $this->users, [], null, null, null, new FixedClock(new \DateTimeImmutable('2026-10-20 12:00:00')), $features);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->m = $this->users->add('mitglied', Roles::MEMBER);
        $this->users->current = $this->admin;
        $this->group = (int) $this->ok('POST', '/office-groups', ['title' => 'Königshaus'])['id'];
        $this->king = (int) $this->ok('POST', '/offices', ['title' => 'König', 'group_id' => $this->group])['id'];
        $this->queen = (int) $this->ok('POST', '/offices', ['title' => 'Königin', 'group_id' => $this->group])['id'];
        $this->minister = (int) $this->ok('POST', '/offices', ['title' => 'Minister', 'group_id' => $this->group])['id'];
    }

    private function call(string $method, string $path, array $body = []): Response
    {
        return $this->app->api()->handle(new Request($method, $path, [], $body));
    }

    /** @return array<string, mixed> */
    private function ok(string $method, string $path, array $body = []): array
    {
        $res = $this->call($method, $path, $body);
        $this->assertLessThan(300, $res->status, $method . ' ' . $path . ': ' . json_encode($res->data));
        return (array) $res->data;
    }

    private function image(): string
    {
        $img = imagecreatetruecolor(6, 4);
        ob_start();
        imagepng($img);
        return (string) $this->ok('POST', '/rich-images', ['file' => ['name' => 'bild.png', 'data' => base64_encode((string) ob_get_clean())]])['uid'];
    }

    /** @return array<int, list<string>> Amt-ID => Namen der Amtszeit mit diesen Jahren */
    private function namesByOffice(int $groupTermId): array
    {
        $out = [];
        foreach ($this->ok('GET', '/offices')['offices'] as $o) {
            foreach ($o['terms'] as $t) {
                if ($t['group_term_id'] === $groupTermId) {
                    $out[$o['id']] = array_column($t['holders'], 'name');
                }
            }
        }
        return $out;
    }

    public function testCreateInOneFormWritesNormalTermsPerOffice(): void
    {
        $this->assertSame(400, $this->call('POST', "/office-groups/{$this->group}/terms", ['start_year' => 2025, 'offices' => []])->status, 'Schalter aus');
        $this->assertTrue($this->ok('PATCH', "/office-groups/{$this->group}", ['joint_terms' => true])['joint_terms']);
        $this->assertSame('Königshaus', $this->ok('GET', '/offices')['groups'][0]['title'], 'Titel bleibt');
        $this->assertSame(400, $this->call('PATCH', "/office-groups/{$this->group}", ['joint_terms' => 'ja'])->status);

        $uid = $this->image();
        $res = $this->ok('POST', "/office-groups/{$this->group}/terms", [
            'kind' => 'period', 'start_year' => 2025, 'end_year' => null, 'note' => 'Jubiläum', 'image_uid' => $uid,
            'offices' => [
                ['office_id' => $this->king, 'holders' => [['user_id' => $this->m]]],
                ['office_id' => $this->queen, 'holders' => [['name' => 'Erika Muster']]],
                ['office_id' => $this->minister, 'holders' => []],
            ],
        ]);
        $id = (int) $res['id'];
        $this->assertTrue($res['current']);
        $this->assertSame('https://example.org/app/bild/' . $uid, $res['image_url']);
        $this->assertSame([$this->king => ['Mitglied'], $this->queen => ['Erika Muster']], $this->namesByOffice($id), 'je Amt eine normale Amtszeit, ohne Personen keine');
        $overview = $this->ok('GET', '/offices');
        $this->assertSame([$id], array_column($overview['group_terms'], 'id'));
        $this->assertSame('Jubiläum', $overview['group_terms'][0]['note']);

        // Bild öffentlich wie die Ämter
        $this->assertSame(200, $this->app->richImages()->forDelivery(null, $uid)['status']);
        $this->app->roles()->update(Roles::GUEST, ['capabilities' => ['view_news']]);
        $this->assertSame(401, $this->app->richImages()->forDelivery(null, $uid)['status']);

        foreach ([
            'Amt aus anderer Gruppe' => ['start_year' => 2020, 'offices' => [['office_id' => (int) $this->ok('POST', '/offices', ['title' => 'Andere'])['id'], 'holders' => [['name' => 'X']]]]],
            'ohne Personen' => ['start_year' => 2020, 'offices' => [['office_id' => $this->king, 'holders' => []]]],
            'Amt doppelt' => ['start_year' => 2020, 'offices' => [['office_id' => $this->king, 'holders' => [['name' => 'X']]], ['office_id' => $this->king, 'holders' => [['name' => 'Y']]]]],
            'Jahr falsch' => ['start_year' => 1700, 'offices' => [['office_id' => $this->king, 'holders' => [['name' => 'X']]]]],
            'fremdes Bild' => ['start_year' => 2020, 'image_uid' => str_repeat('a', 32), 'offices' => [['office_id' => $this->king, 'holders' => [['name' => 'X']]]]],
        ] as $case => $body) {
            $this->assertSame(400, $this->call('POST', "/office-groups/{$this->group}/terms", $body)->status, $case);
        }
    }

    public function testUpdateMovesYearsForAllAndReplacesPeople(): void
    {
        $this->ok('PATCH', "/office-groups/{$this->group}", ['joint_terms' => true]);
        $id = (int) $this->ok('POST', "/office-groups/{$this->group}/terms", ['kind' => 'period', 'start_year' => 2024, 'end_year' => null, 'offices' => [
            ['office_id' => $this->king, 'holders' => [['name' => 'Karl']]],
            ['office_id' => $this->queen, 'holders' => [['name' => 'Klara']]],
        ]])['id'];
        $res = $this->ok('PATCH', "/office-group-terms/$id", ['end_year' => 2025]);
        $this->assertSame(2025, $res['end_year']);
        foreach ($this->ok('GET', '/offices')['offices'] as $o) {
            foreach ($o['terms'] as $t) {
                $this->assertSame([2024, 2025], [$t['start_year'], $t['end_year']], 'Jahre gelten für alle Ämter');
            }
        }
        $this->ok('PATCH', "/office-group-terms/$id", ['offices' => [
            ['office_id' => $this->king, 'holders' => [['name' => 'Karl']]],
            ['office_id' => $this->queen, 'holders' => []],
            ['office_id' => $this->minister, 'holders' => [['name' => 'Max'], ['name' => 'Moritz']]],
        ]]);
        $this->assertSame([$this->king => ['Karl'], $this->minister => ['Max', 'Moritz']], $this->namesByOffice($id));

        $uid = $this->image();
        $this->ok('PATCH', "/office-group-terms/$id", ['image_uid' => $uid]);
        $uid2 = $this->image();
        $this->ok('PATCH', "/office-group-terms/$id", ['image_uid' => $uid2]);
        $this->assertNull($this->app->richImages()->repository()->find($uid), 'altes Bild gelöscht');
        $this->ok('PATCH', "/office-group-terms/$id", ['image_uid' => null]);
        $this->assertNull($this->app->richImages()->repository()->find($uid2));

        // Einzelne Amtszeit auf andere Jahre: gehört dann zu einer neuen gemeinsamen Amtszeit
        $kingTerm = array_column($this->ok('GET', '/offices')['offices'], null, 'id')[$this->king]['terms'][0];
        $this->ok('PATCH', "/office-terms/{$kingTerm['id']}", ['start_year' => 2023]);
        $overview = $this->ok('GET', '/offices');
        $this->assertCount(2, $overview['group_terms']);
        $this->assertNotSame($id, array_column($overview['offices'], null, 'id')[$this->king]['terms'][0]['group_term_id']);

        $this->assertSame(200, $this->call('DELETE', "/office-group-terms/$id")->status);
        $this->assertSame([], array_column($this->ok('GET', '/offices')['offices'], null, 'id')[$this->minister]['terms'], 'Amtszeiten darin gelöscht');
        $this->assertSame(404, $this->call('PATCH', "/office-group-terms/$id", ['note' => 'x'])->status);
    }

    public function testSwitchOnBundlesExistingAndImportedTermsButNotHonors(): void
    {
        foreach ([[$this->king, 'Karl'], [$this->queen, 'Klara']] as [$office, $name]) {
            $this->ok('POST', "/offices/$office/terms", ['kind' => 'period', 'start_year' => 2019, 'end_year' => 2020, 'holders' => [['name' => $name]]]);
        }
        $this->ok('POST', "/offices/{$this->king}/terms", ['kind' => 'period', 'start_year' => 2021, 'end_year' => 2022, 'holders' => [['name' => 'Kurt']]]);
        $this->ok('POST', "/offices/{$this->minister}/terms", ['kind' => 'point', 'start_year' => 2019, 'honor' => true, 'holders' => [['name' => 'Ehrenminister']]]);
        $this->assertSame([], $this->ok('GET', '/offices')['group_terms'], 'Schalter aus: nichts gebündelt');

        $this->ok('PATCH', "/office-groups/{$this->group}", ['joint_terms' => true]);
        $overview = $this->ok('GET', '/offices');
        $this->assertSame([[2021, 2022], [2019, 2020]], array_map(static fn (array $t): array => [$t['start_year'], $t['end_year']], $overview['group_terms']), 'neueste zuerst');
        $this->assertSame([$this->king => ['Karl'], $this->queen => ['Klara']], $this->namesByOffice((int) $overview['group_terms'][1]['id']));
        $this->assertNull(array_column($overview['offices'], null, 'id')[$this->minister]['terms'][0]['group_term_id'], 'Ehrung nicht gebündelt');

        // Neue Amtszeit mit denselben Jahren (z. B. von Hand oder per Import) kommt dazu
        $this->ok('POST', '/offices/import', ['csv' => "gruppe;amt;beginn;ende;art;personen\nKönigshaus;Minister;2021;2022;;Max & Moritz", 'apply' => true]);
        $overview = $this->ok('GET', '/offices');
        $this->assertCount(2, $overview['group_terms']);
        $this->assertSame([$this->king => ['Kurt'], $this->minister => ['Max', 'Moritz']], $this->namesByOffice((int) $overview['group_terms'][0]['id']));

        // Ausschalten: alles bleibt gespeichert
        $this->ok('PATCH', "/office-groups/{$this->group}", ['joint_terms' => false]);
        $this->assertCount(2, $this->ok('GET', '/offices')['group_terms']);
    }

    public function testLinkedTitleAndGroupDeletionAndBackup(): void
    {
        $title = (int) $this->ok('POST', '/titles', ['title' => 'König'])['id'];
        $this->ok('PATCH', "/offices/{$this->king}", ['title_id' => $title]);
        $this->ok('PATCH', "/office-groups/{$this->group}", ['joint_terms' => true]);
        $uid = $this->image();
        $id = (int) $this->ok('POST', "/office-groups/{$this->group}/terms", ['start_year' => 2025, 'image_uid' => $uid, 'offices' => [['office_id' => $this->king, 'holders' => [['user_id' => $this->m]]]]])['id'];
        $this->assertSame(['König'], array_column($this->ok('GET', "/members/{$this->m}")['titles'], 'title'), 'laufende gemeinsame Amtszeit vergibt den Titel');

        $this->assertContains('office_group_terms', Backup::CORE_TABLES);
        $backup = new Backup($this->app->db);
        $tables = $backup->dump(['office_group_terms']);
        $this->app->db->execute('DELETE FROM {office_group_terms}');
        $backup->restore($tables, ['office_group_terms']);
        $this->assertSame([$id], array_column($this->ok('GET', '/offices')['group_terms'], 'id'));

        $this->ok('DELETE', "/office-group-terms/$id");
        $this->assertSame([], array_column($this->ok('GET', "/members/{$this->m}")['titles'], 'title'), 'gelöscht: Titel weg');
        $this->assertNull($this->app->richImages()->repository()->find($uid));

        // Gruppe löschen (nur leer): ihre gemeinsamen Amtszeiten mit
        $this->ok('POST', "/office-groups/{$this->group}/terms", ['start_year' => 2010, 'offices' => [['office_id' => $this->queen, 'holders' => [['name' => 'Q']]]]]);
        foreach ([$this->king, $this->queen, $this->minister] as $office) {
            $this->ok('PATCH', "/offices/$office", ['group_id' => null]);
        }
        $this->ok('DELETE', "/office-groups/{$this->group}");
        $overview = $this->ok('GET', '/offices');
        $this->assertSame([], $overview['group_terms']);
        $this->assertNull(array_column($overview['offices'], null, 'id')[$this->queen]['terms'][0]['group_term_id'], 'Amtszeit bleibt, ohne Bündelung');
    }

    /** Einzahl der Gruppe („Königshaus“ zu „Königshäuser“): anlegen, ändern, leeren, Länge, in der Übersicht. */
    public function testGroupSingular(): void
    {
        $this->assertSame('', $this->ok('GET', '/offices')['groups'][0]['singular'], 'ohne Angabe leer');

        $houses = $this->ok('POST', '/office-groups', ['title' => 'Königshäuser', 'singular' => ' Königshaus ']);
        $this->assertSame(['title' => 'Königshäuser', 'singular' => 'Königshaus'], ['title' => $houses['title'], 'singular' => $houses['singular']]);

        $renamed = $this->ok('PATCH', "/office-groups/{$this->group}", ['title' => 'Vorstände', 'singular' => 'Vorstand']);
        $this->assertSame(['Vorstände', 'Vorstand', false], [$renamed['title'], $renamed['singular'], $renamed['joint_terms']]);
        $this->assertSame('Vorstand', $this->ok('PATCH', "/office-groups/{$this->group}", ['joint_terms' => true])['singular'], 'fehlende Felder bleiben');
        $this->assertSame('', $this->ok('PATCH', "/office-groups/{$this->group}", ['singular' => ''])['singular'], 'leeren');
        $this->assertSame(400, $this->call('PATCH', "/office-groups/{$this->group}", ['singular' => str_repeat('x', 61)])->status);

        $groups = array_column($this->ok('GET', '/offices')['groups'], 'singular', 'title');
        $this->assertSame(['Vorstände' => '', 'Königshäuser' => 'Königshaus'], $groups);

        // Wie früher: nur der Titel
        $this->assertSame('', $this->app->offices()->createGroup('Ehrungen')['singular']);
    }

    /** Update (SQLite und MySQL): Vorhandene Gruppen bekommen eine leere Einzahl. */
    public function testMigrationAddsEmptyGroupSingular(): void
    {
        foreach (['sqlite', getenv('KOMPANIE_TEST_MYSQL') ? 'mysql' : null] as $driver) {
            if ($driver === null) {
                continue;
            }
            $db = TestApp::connection($driver);
            try {
                $settings = new MemorySettings();
                (new Migrator($db, $settings, array_filter(Migrations::all(), static fn (int $v): bool => $v < 45, ARRAY_FILTER_USE_KEY)))->migrate();
                $this->assertNotContains('singular', Schema::columns($db, 'office_groups'), $driver);
                $id = $db->insert('office_groups', ['title' => 'Königshäuser', 'sort' => 10]);

                $app = TestApp::create($db, new FakeUsers(), [], null, null, $settings);
                $this->assertSame(Migrations::latest(), (new Migrator($db, $settings))->current(), $driver);
                $this->assertSame('', $app->offices()->repository()->findGroup($id)['singular'] ?? null, $driver);
            } finally {
                TestApp::dropTables($db);
            }
        }
    }
}
