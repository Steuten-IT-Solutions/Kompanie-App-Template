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

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->app = TestApp::create(TestApp::connection(), $this->users, clock: new FixedClock(new \DateTimeImmutable('2026-10-22 12:00:00')));
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
        $this->assertSame(['reason' => 'left', 'joined_on' => '1993-05-03', 'founder' => false, 'left_on' => '2016-06-30', 'previous_level' => Roles::MEMBER], $res->data);

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
        $this->call('POST', '/admin/roles', ['label' => 'Kasse', 'capabilities' => ['member', 'manage_penalties']]);
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
        $this->call('POST', '/admin/roles', ['label' => 'Schriftführer', 'capabilities' => ['member', 'create_users']]);
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
}
