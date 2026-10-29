<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Storage\Backup;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** Ehemalige Mitglieder: Konto stilllegen und Daten behalten, wieder aufnehmen, Import aus einer Chronik. */
final class FormerMembersTest extends TestCase
{
    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $m;
    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->clock = new FixedClock(new \DateTimeImmutable('2026-10-22 12:00:00'));
        $this->app = TestApp::create(TestApp::connection(), $this->users, clock: $this->clock);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->m = $this->users->add('mitglied', Roles::MEMBER, 'passwort123');
        $this->users->current = $this->admin;
    }

    /** @param array<string, mixed> $body */
    private function call(string $method, string $path, array $body = []): Response
    {
        return $this->app->api()->handle(new Request($method, $path, [], $body));
    }

    /** @return list<int> */
    private function memberIds(): array
    {
        return array_column($this->call('GET', '/members')->data, 'id');
    }

    public function testMarkKeepsDataAndRemovesFromListsAndLogin(): void
    {
        $this->call('POST', '/points', ['user_ids' => [$this->m], 'points' => 5, 'reason' => 'Fest']);
        $res = $this->call('PUT', "/members/{$this->m}/former", ['reason' => 'left', 'joined_on' => '03.05.1993', 'left_on' => '2016-06-30']);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertSame(['reason' => 'left', 'joined_on' => '1993-05-03', 'founder' => false, 'left_on' => '2016-06-30', 'previous_level' => Roles::MEMBER, 'personal_deleted' => false, 'offices_ended' => 0], $res->data);

        $this->assertSame(Roles::GUEST, $this->users->find($this->m)?->role, 'keine Rechte mehr');
        $this->assertNotContains($this->m, $this->memberIds());
        $former = $this->call('GET', '/members/former')->data;
        $this->assertCount(1, $former);
        $this->assertSame([$this->m, 'Mitglied', 5.0, 'left'], [$former[0]['id'], $former[0]['name'], (float) $former[0]['points'], $former[0]['reason']]);
        $detail = $this->call('GET', "/members/{$this->m}")->data;
        $this->assertSame('1993-05-03', $detail['former']['joined_on']);
        $this->assertSame(5.0, (float) $detail['points'], 'Punkte bleiben');

        $this->users->current = null;
        $this->assertSame(400, $this->app->api()->handle(new Request('POST', '/auth/login', [], ['username' => 'mitglied', 'password' => 'passwort123']))->status, 'Anmeldung gesperrt');
        $this->users->current = $this->admin;

        $this->assertSame(400, $this->call('PUT', "/members/{$this->m}/former", ['reason' => 'left'])->status, 'schon ehemalig');
        $this->assertSame(400, $this->call('PATCH', "/members/{$this->m}/role", ['level' => Roles::MEMBER])->status, 'erst wieder aufnehmen');
        $this->assertSame(400, $this->call('POST', "/members/{$this->m}/password")->status, 'kein Startpasswort für Ehemalige');

        $res = $this->call('PATCH', "/members/{$this->m}/former", ['reason' => 'deceased', 'left_on' => '2020']);
        $this->assertSame(['deceased', '1993-05-03', '2020'], [$res->data['reason'], $res->data['joined_on'], $res->data['left_on']], 'fehlende Angaben bleiben');
    }

    public function testValidation(): void
    {
        $id = "/members/{$this->m}/former";
        $this->assertSame(400, $this->call('PUT', $id, [])->status, 'ohne Grund');
        $this->assertSame(400, $this->call('PUT', $id, ['reason' => 'weg'])->status);
        $this->assertSame(400, $this->call('PUT', $id, ['reason' => 'left', 'left_on' => '2027'])->status, 'Zukunft');
        $this->assertSame(400, $this->call('PUT', $id, ['reason' => 'left', 'left_on' => '23.10.2026'])->status, 'morgen');
        $this->assertSame(400, $this->call('PUT', $id, ['reason' => 'left', 'left_on' => '31.02.2020'])->status, 'gibt es nicht');
        $this->assertSame(400, $this->call('PUT', $id, ['reason' => 'left', 'left_on' => '1799'])->status);
        $this->assertSame(400, $this->call('PUT', $id, ['reason' => 'left', 'left_on' => '12/2020'])->status);
        $this->assertSame(400, $this->call('PUT', $id, ['reason' => 'left', 'joined_on' => '2010', 'left_on' => '2009'])->status, 'Eintritt nach Ausscheiden');
        $this->assertSame(200, $this->call('PUT', $id, ['reason' => 'left', 'joined_on' => '2010', 'left_on' => '01.01.2010'])->status, 'gleiches Jahr');
        $this->assertSame(400, $this->call('PUT', "/members/{$this->admin}/former", ['reason' => 'left'])->status, 'nicht das eigene Konto');
        $this->assertSame(404, $this->call('PUT', '/members/999/former', ['reason' => 'left'])->status);
    }

    public function testReactivateRestoresRoles(): void
    {
        $this->call('POST', '/admin/roles', ['label' => 'Kasse', 'capabilities' => TestApp::caps(['member', 'manage_penalties'])]);
        $this->call('PATCH', "/members/{$this->m}/role", ['extra_roles' => ['r1', 'events']]);
        $this->call('PUT', "/members/{$this->m}/rights", ['grant' => ['manage_points'], 'revoke' => []]);
        $this->assertSame(200, $this->call('PUT', "/members/{$this->m}/former", ['reason' => 'other'])->status);
        $this->assertSame([], $this->app->roles()->extraRolesOf($this->users->find($this->m)), 'Gäste haben keine Zusatzrollen');

        $res = $this->call('DELETE', "/members/{$this->m}/former");
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertSame(['id' => $this->m, 'level' => Roles::MEMBER, 'extra_roles' => ['events', 'r1']], $res->data);
        $this->assertSame(['manage_points'], $this->app->roles()->overridesOf($this->m)['grant'], 'Ausnahmen wieder da');
        $this->assertContains($this->m, $this->memberIds());
        $this->assertSame([], $this->call('GET', '/members/former')->data);
        $this->assertNull($this->call('GET', "/members/{$this->m}")->data['former']);
        $this->assertSame(404, $this->call('DELETE', "/members/{$this->m}/former")->status, 'nicht ehemalig');
        // Wieder anmelden geht mit neuem Startpasswort.
        $this->assertSame(200, $this->call('POST', "/members/{$this->m}/password")->status);
    }

    public function testLimitsForNonAdmins(): void
    {
        $this->call('POST', '/admin/roles', ['label' => 'Schriftführer', 'capabilities' => TestApp::caps(['member', 'create_users'])]);
        $clerk = $this->users->add('schrift', 'r1');
        $admin2 = $this->users->add('admin2', Roles::ADMIN);
        $this->users->current = $clerk;
        $this->assertSame(403, $this->call('PUT', "/members/$admin2/former", ['reason' => 'left'])->status, 'Admins nur durch Admins');
        $this->assertSame(200, $this->call('PUT', "/members/{$this->m}/former", ['reason' => 'left'])->status);
        $this->assertSame(200, $this->call('GET', '/members/former')->status);
        $this->assertSame(200, $this->call('PATCH', "/members/{$this->m}/former", ['reason' => 'deceased'])->status, 'eigene Grenzen gelten mit der Rolle von vorher');

        $this->users->current = $this->admin;
        $this->assertSame(200, $this->call('PUT', "/members/$admin2/former", ['reason' => 'left'])->status);
        $this->assertSame(400, $this->call('PUT', "/members/{$this->admin}/former", ['reason' => 'left'])->status);
        $this->users->current = $clerk;
        $this->assertSame(403, $this->call('DELETE', "/members/$admin2/former")->status, 'früherer Admin nur durch Admins');
        $this->assertSame(403, $this->call('DELETE', "/members/$admin2")->status);
        $this->assertSame(200, $this->call('DELETE', "/members/{$this->m}/former")->status);

        $this->users->current = $this->m;
        $this->assertSame(403, $this->call('GET', '/members/former')->status, 'nur wer Konten anlegt');
    }

    public function testLastAdminAndDeletion(): void
    {
        $other = $this->users->add('admin2', Roles::ADMIN);
        $this->users->current = $other;
        $this->assertSame(200, $this->call('PUT', "/members/{$this->admin}/former", ['reason' => 'left'])->status);
        $this->assertSame(1, $this->users->countAdmins());
        $this->assertSame(200, $this->call('DELETE', "/members/{$this->admin}/former")->status);
        $this->assertSame(Roles::ADMIN, $this->users->find($this->admin)?->role, 'Admin wieder Admin');

        $this->call('PUT', "/members/{$this->m}/former", ['reason' => 'left']);
        $this->assertSame(200, $this->call('DELETE', "/members/{$this->m}")->status, 'Konto löschen geht auch für Ehemalige');
        $this->assertSame(0, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {former_members}'), 'mit allen Daten');
        $this->assertContains('former_members', Backup::CORE_TABLES);
    }

    public function testHoldersOfRanksStayInTheirOwnList(): void
    {
        $rank = $this->call('POST', '/ranks', ['title' => 'Gefreiter'])->data['id'];
        $this->call('PUT', "/members/{$this->m}/rank", ['rank_id' => $rank]);
        $this->call('PUT', "/members/{$this->m}/former", ['reason' => 'left']);
        $this->assertNotContains($this->m, $this->memberIds(), 'nicht als „früheres Mitglied mit Dienstgrad“ in der Mitgliederliste');
        $this->assertSame($rank, $this->app->ranks()->repository()->assignments()[$this->m] ?? null, 'Dienstgrad bleibt');
    }

    public function testImport(): void
    {
        $this->users->users[$this->m]['name'] = 'Erika Muster';
        $csv = "Vorname;Nachname;Eintritt;Ausgeschieden;Grund\n"
            . "Erika;Muster;Gründer;01.06.1979;\n"
            . "Max;Beispiel;03.02.1975;14.11.1991;verstorben\n"
            . "Paula;Probe;05.09.1976;1980;ausgetreten\n"
            . "Otto;Normal;Gründer;;\n"
            . "\n"
            . ";Ohne;;;\n"
            . "Max;Zukunft;;2030;\n"
            . "Otto;Normal;;;\n"
            . "Eva;Grund;;;weg\n";
        $res = $this->call('POST', '/members/former/import', ['csv' => $csv]);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $rows = array_column($res->data['rows'], null, 'line');
        $this->assertSame(['id' => $this->m, 'name' => 'Erika Muster', 'former' => false], $rows[2]['existing']);
        $this->assertSame(['deceased', '1975-02-03', '1991-11-14', false], [$rows[3]['reason'], $rows[3]['joined_on'], $rows[3]['left_on'], $rows[3]['founder']]);
        $this->assertSame(['left', '1976-09-05', '1980'], [$rows[4]['reason'], $rows[4]['joined_on'], $rows[4]['left_on']]);
        $this->assertSame(['left', '', true], [$rows[5]['reason'], $rows[5]['joined_on'], $rows[5]['founder']]);
        $this->assertArrayNotHasKey(6, $rows, 'Leerzeile');
        $this->assertStringContainsString('Vorname', $rows[7]['error']);
        $this->assertStringContainsString('Zukunft', $rows[8]['error']);
        $this->assertStringContainsString('Zeile 5', $rows[9]['error'], 'doppelt in der Tabelle');
        $this->assertStringContainsString('Grund', $rows[10]['error']);
        $this->assertSame([3, 1, false], [$res->data['new'], $res->data['existing'], $res->data['applied']]);
        $this->assertSame(400, $this->call('POST', '/members/former/import', ['csv' => $csv, 'apply' => true])->status, 'nicht mit Fehlern');
        $this->assertSame(1, count($this->users->users) - 1, 'nichts angelegt');

        $ok = implode("\n", array_slice(explode("\n", $csv), 0, 5));
        $res = $this->call('POST', '/members/former/import', ['csv' => $ok, 'apply' => true]);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertTrue($res->data['applied']);
        $former = array_column($this->call('GET', '/members/former')->data, null, 'name');
        $this->assertSame(['Max Beispiel', 'Otto Normal', 'Paula Probe'], array_keys($former), 'nach Namen');
        $this->assertSame('deceased', $former['Max Beispiel']['reason']);
        $this->assertTrue($former['Otto Normal']['founder']);
        $this->assertContains($this->m, $this->memberIds(), 'vorhandenes Konto unverändert');
        $new = $this->users->find($former['Paula Probe']['id']);
        $this->assertSame([Roles::GUEST, 'paula.probe', 'Paula', 'Probe'], [$new?->role, $new?->login, $new?->firstName, $new?->lastName]);

        // Zweiter Lauf legt nichts doppelt an.
        $res = $this->call('POST', '/members/former/import', ['csv' => $ok, 'apply' => true]);
        $this->assertSame([0, 4], [$res->data['new'], $res->data['existing']]);
        $this->assertTrue($res->data['rows'][1]['existing']['former']);
    }

    public function testCreateSingle(): void
    {
        $office = $this->call('POST', '/offices', ['title' => 'Vorsitz'])->data['id'];
        $this->call('POST', "/offices/$office/terms", ['kind' => 'period', 'start_year' => 1975, 'end_year' => 1980, 'holders' => [['name' => 'max beispiel']]]);
        $count = count($this->users->users);

        $this->assertSame(400, $this->call('POST', '/members/former', ['first_name' => '', 'reason' => 'left'])->status, 'ohne Vornamen');
        $this->assertSame(400, $this->call('POST', '/members/former', ['first_name' => 'Max', 'reason' => 'weg'])->status, 'ohne gültigen Grund');
        $this->assertSame(400, $this->call('POST', '/members/former', ['first_name' => 'Max', 'reason' => 'left', 'joined_on' => '1990', 'left_on' => '1980'])->status, 'Eintritt nach Ausscheiden');
        $this->assertCount($count, $this->users->users, 'nichts angelegt');

        $res = $this->call('POST', '/members/former', ['first_name' => ' Max ', 'last_name' => 'Beispiel', 'reason' => 'deceased', 'joined_on' => '1974', 'founder' => true, 'left_on' => '14.11.1991']);
        $this->assertSame(201, $res->status, (string) json_encode($res->data));
        $id = $res->data['id'];
        $this->assertSame(['Max Beispiel', 'deceased', '1974', true, '1991-11-14', 1], [$res->data['name'], $res->data['reason'], $res->data['joined_on'], $res->data['founder'], $res->data['left_on'], $res->data['offices_linked']]);
        $this->assertSame(Roles::GUEST, $this->users->find($id)?->role, 'ohne Anmeldung');
        $this->assertNotContains($id, $this->memberIds());
        $this->assertSame([$id], array_column($this->call('GET', '/members/former')->data, 'id'));
        $this->assertSame($id, (int) $this->app->db->select('SELECT user_id FROM {office_holders}')[0]['user_id'], 'Amtszeit verknüpft');

        $again = $this->call('POST', '/members/former', ['first_name' => 'max', 'last_name' => 'beispiel', 'reason' => 'left']);
        $this->assertSame(400, $again->status, 'nicht doppelt');
        $this->assertStringContainsString('ehemalige Person', (string) json_encode($again->data, JSON_UNESCAPED_UNICODE));
        $this->users->users[$this->m]['name'] = 'Erika Muster';
        $this->assertSame(400, $this->call('POST', '/members/former', ['first_name' => 'Erika', 'last_name' => 'Muster', 'reason' => 'left'])->status, 'Name eines aktiven Kontos');
    }

    public function testImportLinksFreeNamesInOffices(): void
    {
        $office = $this->call('POST', '/offices', ['title' => 'Vorsitz'])->data['id'];
        $king = $this->call('POST', '/offices', ['title' => 'König'])->data['id'];
        $this->call('POST', "/offices/$office/terms", ['kind' => 'period', 'start_year' => 1975, 'end_year' => 1980, 'holders' => [['name' => 'max  beispiel'], ['name' => 'Anna Andere']]]);
        $this->call('POST', "/offices/$king/terms", ['kind' => 'point', 'start_year' => 1978, 'holders' => [['name' => 'Max Beispiel']]]);
        $this->call('POST', "/offices/$king/terms", ['kind' => 'point', 'start_year' => 1979, 'holders' => [['name' => 'M. Beispiel']]]);
        $this->call('POST', "/offices/$king/terms", ['kind' => 'point', 'start_year' => 2020, 'holders' => [['user_id' => $this->m]]]);
        $holders = fn (): array => array_column($this->app->db->select('SELECT name, user_id FROM {office_holders} ORDER BY id'), 'user_id', 'name');

        $csv = "Max;Beispiel;1974;1990;verstorben\nOtto;Normal;;1985;";
        $res = $this->call('POST', '/members/former/import', ['csv' => $csv]);
        $this->assertSame(2, $res->data['offices_linked'], 'Vorschau: Groß-/Kleinschreibung und Leerzeichen egal, Abkürzungen nicht');
        $this->assertSame([null, null, null, null], array_values(array_slice($holders(), 0, 4)), 'Vorschau ändert nichts');

        $res = $this->call('POST', '/members/former/import', ['csv' => $csv, 'apply' => true]);
        $this->assertSame(2, $res->data['offices_linked']);
        $max = array_column($this->call('GET', '/members/former')->data, 'id', 'name')['Max Beispiel'];
        $this->assertSame(['max beispiel' => $max, 'Anna Andere' => null, 'Max Beispiel' => $max, 'M. Beispiel' => null, 'Mitglied' => $this->m], $holders(), 'gespeicherter Name bleibt');

        // Aus einem früheren Import (ohne Verknüpfung): ein neuer Lauf verknüpft nachträglich.
        $this->app->db->execute('UPDATE {office_holders} SET user_id = NULL WHERE user_id = ?', [$max]);
        $res = $this->call('POST', '/members/former/import', ['csv' => $csv, 'apply' => true]);
        $this->assertSame([0, 2, 2], [$res->data['new'], $res->data['existing'], $res->data['offices_linked']]);
        $this->assertSame($max, $holders()['Max Beispiel']);
    }

    public function testImportFormats(): void
    {
        // Excel unter Windows: Windows-1252, Tabulator statt Semikolon, Kopfzeile fehlt.
        $csv = mb_convert_encoding("Jörg\tTest\tGründer\t+ 07.04.2014\tverstorben", 'Windows-1252', 'UTF-8');
        $res = $this->call('POST', '/members/former/import', ['csv' => $csv]);
        $this->assertSame('Jörg Test', $res->data['rows'][0]['name']);
        $this->assertStringContainsString('TT.MM.JJJJ', $res->data['rows'][0]['error'], 'das Kreuz gehört nicht ins Datum');
        $res = $this->call('POST', '/members/former/import', ['csv' => "\xEF\xBB\xBFJörg;Test;Gründer;07.04.2014;verstorben"]);
        $this->assertSame(['deceased', '2014-04-07', true], [$res->data['rows'][0]['reason'], $res->data['rows'][0]['left_on'], $res->data['rows'][0]['founder']]);
        $this->assertSame(400, $this->call('POST', '/members/former/import', ['csv' => ' '])->status);
        $this->assertSame(400, $this->call('POST', '/members/former/import', ['csv' => str_repeat("A;B\n", 501)])->status, 'zu viele Zeilen');
    }
    // ---- Ämter enden beim Ausscheiden (Issue #181) ----

    /**
     * @param list<array<string, mixed>> $holders
     * @param array<string, mixed> $extra kind, end_year, note, honor
     */
    private function officeTerm(string $office, int $start, array $holders, array $extra = []): int
    {
        $offices = $this->app->offices();
        $id = null;
        foreach ($offices->repository()->offices() as $o) {
            if ($o['title'] === $office) {
                $id = $o['id'];
            }
        }
        $id ??= (int) $offices->createOffice(['title' => $office])['id'];
        return (int) $offices->createTerm($id, $extra + ['start_year' => $start, 'holders' => $holders])['id'];
    }

    /** @return array<string, mixed> Amtszeit mit Inhabern (Namen) */
    private function officeTermState(int $id): array
    {
        $repo = $this->app->offices()->repository();
        $t = $repo->findTerm($id);
        $this->assertNotNull($t);
        return ['end_year' => $t['end_year'], 'holders' => array_column($repo->holdersOf($id), 'name')];
    }

    public function testMarkEndsRunningOfficesWithTheYearOfLeaving(): void
    {
        $flag = $this->officeTerm('Fahne', 2006, [['user_id' => $this->m]]);
        $honor = $this->officeTerm('Ehrenspieß', 2025, [['user_id' => $this->m]], ['honor' => true]);
        $preview = $this->call('GET', "/members/{$this->m}/former/offices", []);
        $this->assertSame(200, $preview->status);
        $preview = $this->app->api()->handle(new Request('GET', "/members/{$this->m}/former/offices", ['left_on' => '15.03.2026']));
        $this->assertSame(['end_year' => 2026, 'terms' => [
            ['office' => 'Fahne', 'start_year' => 2006, 'end_year' => 2026, 'clamped' => false, 'shared' => false],
            ['office' => 'Ehrenspieß', 'start_year' => 2025, 'end_year' => 2026, 'clamped' => false, 'shared' => false],
        ]], $preview->data, 'Vorschau in der Reihenfolge der Ämter; laufende Ehrung endet ebenso');
        $this->assertNull($this->officeTermState($flag)['end_year'], 'Vorschau ändert nichts');

        $res = $this->call('PUT', "/members/{$this->m}/former", ['reason' => 'left', 'left_on' => '2026-03-15']);
        $this->assertSame(2, $res->data['offices_ended']);
        $this->assertSame([2026, 2026], [$this->officeTermState($flag)['end_year'], $this->officeTermState($honor)['end_year']]);
    }

    public function testEmptyLeavingDateEndsWithThisYearAndNeverBeforeTheStart(): void
    {
        $now = $this->officeTerm('Kasse', 2020, [['user_id' => $this->m]]);
        $res = $this->call('PUT', "/members/{$this->m}/former", ['reason' => 'deceased']);
        $this->assertSame(1, $res->data['offices_ended']);
        $this->assertSame(2026, $this->officeTermState($now)['end_year'], 'ohne Datum: dieses Jahr (Uhr: 2026)');

        $other = $this->users->add('zweites', Roles::MEMBER);
        $late = $this->officeTerm('Vorsitz', 2024, [['user_id' => $other]]);
        $preview = $this->app->api()->handle(new Request('GET', "/members/$other/former/offices", ['left_on' => '2019']))->data;
        $this->assertSame([2024, true], [$preview['terms'][0]['end_year'], $preview['terms'][0]['clamped']], 'Austritt vor dem Beginn: Ende = Beginn, mit Hinweis');
        $this->call('PUT', "/members/$other/former", ['reason' => 'left', 'left_on' => '2019']);
        $this->assertSame(2024, $this->officeTermState($late)['end_year']);
    }

    public function testSharedTermIsSplitAndOthersKeepRunning(): void
    {
        $other = $this->users->add('zweites', Roles::MEMBER);
        $shared = $this->officeTerm('Minister', 2022, [['name' => 'Hans Hof'], ['user_id' => $this->m], ['user_id' => $other]], ['note' => 'Unter Ida Igel', 'honor' => true]);
        $this->call('PUT', "/members/{$this->m}/former", ['reason' => 'left', 'left_on' => '2025']);

        $this->assertSame(['end_year' => null, 'holders' => ['Hans Hof', 'Zweites']], $this->officeTermState($shared), 'die anderen laufen weiter');
        $repo = $this->app->offices()->repository();
        $mine = array_values(array_filter($repo->terms(), static fn (array $t): bool => $t['id'] !== $shared));
        $this->assertCount(1, $mine);
        $this->assertSame(['period', 2022, 2025, 'Unter Ida Igel', true, $this->m], [$mine[0]['kind'], $mine[0]['start_year'], $mine[0]['end_year'], $mine[0]['note'], $mine[0]['honor'], $mine[0]['ended_for']]);
        $holders = $repo->holdersOf($mine[0]['id']);
        $this->assertSame([[$this->m, 'Mitglied']], array_map(static fn (array $h): array => [$h['user_id'], $h['name']], $holders), 'mit Konto und gespeichertem Namen');
    }

    public function testPointsAndEndedTermsStayUnchanged(): void
    {
        $point = $this->officeTerm('Ehrenmitglieder', 2010, [['user_id' => $this->m]], ['kind' => 'point', 'honor' => true]);
        $ended = $this->officeTerm('Kasse', 2000, [['user_id' => $this->m]], ['end_year' => 2005]);
        $res = $this->call('PUT', "/members/{$this->m}/former", ['reason' => 'left', 'left_on' => '2026']);
        $this->assertSame(0, $res->data['offices_ended']);
        $this->assertNull($this->officeTermState($point)['end_year']);
        $this->assertSame(2005, $this->officeTermState($ended)['end_year']);
        $this->assertSame(2, count($this->app->offices()->repository()->terms()), 'nichts gelöscht, nichts geteilt');
    }

    public function testLinkedTitleIsRemovedOtherTitlesStay(): void
    {
        $titles = $this->app->titles();
        $linked = (int) $titles->create(['title' => 'Kapitän'])['id'];
        $plain = (int) $titles->create(['title' => 'Ehrenkapitän'])['id'];
        $this->officeTerm('Kapitäne', 2020, [['user_id' => $this->m]]);
        $office = $this->app->offices()->repository()->offices()[0]['id'];
        $this->app->offices()->updateOffice($office, ['title_id' => $linked]);
        $this->assertSame(200, $this->call('PUT', "/members/{$this->m}/titles", ['title_ids' => [$linked, $plain]])->status);
        $this->assertEqualsCanonicalizing([$linked, $plain], $titles->repository()->assignments()[$this->m] ?? []);

        $this->call('PUT', "/members/{$this->m}/former", ['reason' => 'left', 'left_on' => '2026']);
        $this->assertSame([$plain], array_values($titles->repository()->assignments()[$this->m] ?? []), 'verknüpfter Titel entfällt, der andere bleibt');
    }

    public function testChangingTheLeavingDateMovesOnlyAutomaticEnds(): void
    {
        $auto = $this->officeTerm('Fahne', 2006, [['user_id' => $this->m]]);
        $manual = $this->officeTerm('Kasse', 2010, [['user_id' => $this->m]]);
        $this->call('PUT', "/members/{$this->m}/former", ['reason' => 'left', 'left_on' => '2026']);
        // Von Hand korrigiert: gehört danach der Pflege, ein neues Datum ändert sie nicht mehr.
        $this->app->offices()->updateTerm($manual, ['end_year' => 2024]);

        $this->call('PATCH', "/members/{$this->m}/former", ['left_on' => '2025']);
        $this->assertSame([2025, 2024], [$this->officeTermState($auto)['end_year'], $this->officeTermState($manual)['end_year']]);
        $this->call('PATCH', "/members/{$this->m}/former", ['reason' => 'other']);
        $this->assertSame(2025, $this->officeTermState($auto)['end_year'], 'gleiches Datum: nichts');
        $this->call('PATCH', "/members/{$this->m}/former", ['left_on' => '1990']);
        $this->assertSame(2006, $this->officeTermState($auto)['end_year'], 'nie vor dem Beginn');
    }

    public function testReactivateDoesNotReopenAndReleasesTheEnds(): void
    {
        $term = $this->officeTerm('Fahne', 2006, [['user_id' => $this->m]]);
        $this->call('PUT', "/members/{$this->m}/former", ['reason' => 'left', 'left_on' => '2025']);
        $this->assertSame(200, $this->call('DELETE', "/members/{$this->m}/former")->status);
        $t = $this->app->offices()->repository()->findTerm($term);
        $this->assertNotNull($t);
        $this->assertSame([2025, null], [$t['end_year'], $t['ended_for']], 'bleibt beendet, gehört wieder der Pflege');
    }

    public function testDeletingTheAccountReleasesTheEnds(): void
    {
        $term = $this->officeTerm('Fahne', 2006, [['user_id' => $this->m]]);
        $this->call('PUT', "/members/{$this->m}/former", ['reason' => 'left', 'left_on' => '2025']);
        $this->assertSame(200, $this->call('DELETE', "/members/{$this->m}")->status);
        $t = $this->app->offices()->repository()->findTerm($term);
        $this->assertNotNull($t);
        $this->assertSame([2025, null], [$t['end_year'], $t['ended_for']]);
    }

    public function testMarkIsOneTransaction(): void
    {
        $this->officeTerm('Fahne', 2006, [['user_id' => $this->m]]);
        // Schreiben der Amtszeit scheitert (Tabelle weg): Dann bleibt auch der Eintrag als ehemalig aus.
        $this->app->db->execute('ALTER TABLE {office_terms} RENAME TO {office_terms_weg}');
        try {
            $this->assertGreaterThanOrEqual(500, $this->call('PUT', "/members/{$this->m}/former", ['reason' => 'left'])->status);
        } finally {
            $this->app->db->execute('ALTER TABLE {office_terms_weg} RENAME TO {office_terms}');
        }
        $this->assertNull($this->app->formerMembers()->repository()->find($this->m), 'kein halber Zustand');
    }

    /** Scheitert ein Teil, gilt die Person nicht als erledigt: Der nächste Lauf wiederholt alles. */
    public function testRetentionRetriesAfterAFailure(): void
    {
        $this->assertSame(200, $this->call('PUT', "/members/{$this->m}/former", ['reason' => 'left', 'founder' => false, 'joined_on' => '', 'left_on' => '2000'])->status);
        $this->app->db->insert('birthdays', ['user_id' => $this->m, 'month' => 3, 'day' => 4, 'updated_by' => $this->admin, 'updated_at' => '2020-01-01 00:00:00']);
        $this->assertSame(200, $this->call('PUT', '/admin/former/retention', ['years' => 5])->status);
        $this->app->db->execute('ALTER TABLE {penalties} RENAME TO {penalties_weg}');
        try {
            $this->assertSame(0, @$this->app->formerMembers()->purgeExpired(), 'nicht erledigt');
        } finally {
            $this->app->db->execute('ALTER TABLE {penalties_weg} RENAME TO {penalties}');
        }
        $this->assertSame([$this->m], array_column($this->call('GET', '/admin/former/retention')->data['due'], 'id'), 'weiter fällig');
        $this->assertSame(0, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {birthdays} WHERE user_id = ?', [$this->m]), 'was ging, ist schon weg');
        $this->assertSame(1, $this->app->formerMembers()->purgeExpired(), 'nächster Lauf');
        $this->assertSame([], $this->call('GET', '/admin/former/retention')->data['due']);
    }

    /** Ausgeschieden am 29. Februar: Die Frist endet in einem Nicht-Schaltjahr am 28. Februar, nicht am 1. März. */
    public function testRetentionFromLeapDay(): void
    {
        $this->assertSame(200, $this->call('PUT', "/members/{$this->m}/former", ['reason' => 'left', 'founder' => false, 'joined_on' => '', 'left_on' => '2024-02-29'])->status);
        $this->clock->at = new \DateTimeImmutable('2026-02-28 12:00:00');
        $this->assertSame([$this->m], array_column($this->call('PUT', '/admin/former/retention', ['years' => 2, 'preview' => true])->data['due'], 'id'));
        $this->clock->at = new \DateTimeImmutable('2026-02-27 12:00:00');
        $this->assertSame([], $this->call('PUT', '/admin/former/retention', ['years' => 2, 'preview' => true])->data['due']);
    }

    /**
     * Aufbewahrungsfrist: Standard aus; danach persönliche Angaben weg, die Chronik bleibt (Name, Eintrag als ehemalig,
     * Ämter, Punkte, Dienstgrad, Titel, Foto, Eintrittsjahr), für alle Ehemaligen gleich.
     */
    public function testRetentionForgetsPersonalDataAfterTheDeadline(): void
    {
        $old = $this->m;
        $recent = $this->users->add('neulich', Roles::MEMBER);
        $founder = $this->users->add('gruender', Roles::MEMBER);
        $this->call('POST', '/points', ['user_ids' => [$old], 'points' => 5, 'reason' => 'Fest']);
        $this->officeTerm('Fahne', 2006, [['user_id' => $old]]);
        $db = $this->app->db;
        $now = '2020-01-01 00:00:00';
        $db->insert('penalties', ['user_id' => $old, 'amount_cents' => 500, 'reason' => 'Zu spät', 'entry_date' => '2019-05-01', 'created_by' => $this->admin, 'created_at' => $now]);
        $db->insert('attendance_entries', ['list_id' => 1, 'user_id' => $old, 'status' => 'present', 'updated_by' => $this->admin, 'updated_at' => $now]);
        $db->insert('birthdays', ['user_id' => $old, 'month' => 3, 'day' => 4, 'year' => 1960, 'updated_by' => $this->admin, 'updated_at' => $now]);
        $db->insert('join_years', ['user_id' => $old, 'year' => 1980, 'updated_by' => $this->admin, 'updated_at' => $now]);
        $db->insert('member_contacts', ['user_id' => $old, 'phone' => '+49 171 1234567', 'city' => 'Neuss', 'updated_at' => $now]);
        $this->users->updateProfile($old, 'Mit', 'Glied', 'alt@example.org', null);
        foreach ([[$old, 'left', false, '2020'], [$recent, 'left', false, '2024-06-30'], [$founder, 'deceased', true, '2010']] as [$id, $reason, $isFounder, $left]) {
            $res = $this->call('PUT', "/members/$id/former", ['reason' => $reason, 'founder' => $isFounder, 'joined_on' => '', 'left_on' => $left]);
            $this->assertSame(200, $res->status, (string) json_encode($res->data));
        }
        $scheduler = $this->app->scheduler;
        $this->assertInstanceOf(\KompanieApp\Core\Tests\Support\NullScheduler::class, $scheduler);

        // Standard: aus, nichts geplant, nichts gelöscht.
        $status = $this->call('GET', '/admin/former/retention')->data;
        $this->assertSame(['years' => 0, 'due' => [], 'soon' => []], $status);
        $this->assertSame(0, $this->app->formerMembers()->purgeExpired());

        $this->assertSame(400, $this->call('PUT', '/admin/former/retention', ['years' => 51])->status);
        $this->assertSame(400, $this->call('PUT', '/admin/former/retention', ['years' => 'fünf'])->status);
        $this->assertSame(400, $this->call('PUT', '/admin/former/retention', ['years' => null])->status, 'fehlt: Fehler, nicht „aus“');
        $this->users->current = $this->users->add('anwaerter', TestApp::candidateRole($this->app));
        $this->assertSame(403, $this->call('PUT', '/admin/former/retention', ['years' => 5])->status, 'nur Admins (manage_settings)');
        $this->users->current = $this->admin;

        // Vorschau vor dem Speichern: zeigt, wen es trifft (auch Gründungsmitglieder und Verstorbene), speichert nichts.
        $preview = $this->call('PUT', '/admin/former/retention', ['years' => 5, 'preview' => true]);
        $this->assertSame([$founder, $old], array_column($preview->data['due'], 'id'), 'nach Namen: Gruender, Mitglied');
        $this->assertSame(0, $this->app->formerMembers()->retention()['years'], 'nicht gespeichert');
        $this->assertArrayNotHasKey(\KompanieApp\Core\Domain\FormerMemberService::RETENTION_TASK, $scheduler->scheduled);

        // 5 Jahre: 2020 (31.12.2020 + 5 = 31.12.2025) und 2010 sind fällig, 2024 nicht.
        $res = $this->call('PUT', '/admin/former/retention', ['years' => 5]);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertSame(86400, $scheduler->scheduled[\KompanieApp\Core\Domain\FormerMemberService::RETENTION_TASK] ?? null, 'täglich eingeplant');
        $this->app->runTask(\KompanieApp\Core\Domain\FormerMemberService::RETENTION_TASK);

        // Persönliche Angaben weg
        $count = static fn (string $table): int => (int) $db->scalar('SELECT COUNT(*) FROM {' . $table . '} WHERE user_id = ?', [$old]);
        foreach (['penalties', 'attendance_entries', 'birthdays', 'member_contacts'] as $table) {
            $this->assertSame(0, $count($table), "$table gelöscht");
        }
        $profile = $this->users->profile($old);
        $this->assertSame('', $profile['email'], 'E-Mail weg');
        $this->assertMatchesRegularExpression('/^ehemalig-[0-9a-f]{12}$/', $profile['login'], 'Benutzername ohne Bezug zur Person');
        $this->assertFalse($this->users->verifyPassword($old, 'passwort123'), 'Anmeldung nicht mehr möglich');
        // Chronik bleibt
        $this->assertNotNull($this->users->find($old), 'Konto mit Namen bleibt');
        $this->assertTrue($this->app->formerMembers()->of($old)['personal_deleted'] ?? false, 'beim Wiederaufnehmen erkennbar');
        foreach (['points', 'join_years', 'former_members'] as $table) {
            $this->assertSame(1, $count($table), "$table bleibt");
        }
        $this->assertSame(1, (int) $db->scalar('SELECT COUNT(*) FROM {office_holders} WHERE user_id = ?', [$old]), 'Amt bleibt verknüpft');
        $formerIds = array_column($this->call('GET', '/members/former')->data, 'id');
        sort($formerIds);
        $this->assertSame([$old, $recent, $founder], $formerIds, 'alle bleiben in der Liste der Ehemaligen');

        // Erledigt: kommt nicht wieder dran; die übrigen bis zu ihrer Frist.
        $this->assertSame([], $this->call('GET', '/admin/former/retention')->data['due']);
        $this->assertSame(0, $this->app->formerMembers()->purgeExpired());

        // Die Frist gehört zur Installation: Eine Sicherung bringt sie nicht (ungesehen) zurück.
        $this->assertContains(\KompanieApp\Core\Domain\FormerMemberService::RETENTION_SETTING, Backup::LOCAL_SETTINGS);

        // Aus: Aufgabe nicht mehr eingeplant.
        $this->call('PUT', '/admin/former/retention', ['years' => 0]);
        $this->assertArrayNotHasKey(\KompanieApp\Core\Domain\FormerMemberService::RETENTION_TASK, $scheduler->scheduled);
    }
}
