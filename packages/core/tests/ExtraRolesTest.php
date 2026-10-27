<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Storage\Backup;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** Eine Grundrolle und beliebig viele Zusatzrollen je Person (Issue #66). */
final class ExtraRolesTest extends TestCase
{
    private FakeUsers $users;
    private App $app;
    private int $admin;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->app = TestApp::create(TestApp::connection(), $this->users);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->users->current = $this->admin;
    }

    /** @param array<string, mixed> $body */
    private function call(string $method, string $path, array $body = [], array $query = []): Response
    {
        return $this->app->api()->handle(new Request($method, $path, $query, $body));
    }

    /** @param list<string> $extra */
    private function setRoles(int $id, array $extra, ?string $level = null): Response
    {
        return $this->call('PATCH', "/members/$id/role", ['extra_roles' => $extra] + ($level !== null ? ['level' => $level] : []));
    }

    /** @return list<string> */
    private function caps(int $id): array
    {
        return array_map(static fn (Capability $c): string => $c->value, $this->users->find($id)?->capabilities ?? []);
    }

    public function testRightsAreTheUnionOfAllRoles(): void
    {
        $m = $this->users->add('kassenwart', Roles::MEMBER);
        $res = $this->setRoles($m, ['penalties', 'events']);
        $this->assertSame(200, $res->status, json_encode($res->data) ?: '');
        $this->assertSame(['id' => $m, 'level' => Roles::MEMBER, 'extra_roles' => ['events', 'penalties']], $res->data, 'Reihenfolge der Rollen, level bleibt die Grundrolle');
        $this->assertEqualsCanonicalizing(TestApp::caps(['member', 'manage_penalties', 'manage_events']), $this->caps($m));

        $list = array_column($this->call('GET', '/members')->data, null, 'id');
        $this->assertSame(['events', 'penalties'], $list[$m]['extra_roles']);
        $this->assertSame([], $list[$this->admin]['extra_roles'] ?? [], 'Admins haben keine');
        $this->assertSame(['events', 'penalties'], $this->call('GET', "/members/$m")->data['extra_roles']);

        $this->users->current = $m;
        $this->assertSame(['events', 'penalties'], $this->call('GET', '/me')->data['extra_roles']);
        $config = $this->app->api()->config();
        $this->assertSame(Roles::MEMBER, $config['user']['level']);
        $this->assertSame(['events', 'penalties'], $config['user']['extra_roles']);
        $this->assertContains('manage_penalties', $config['user']['capabilities']);
        $this->assertSame(201, $this->call('POST', '/penalties', ['user_ids' => [$this->admin], 'amount_cents' => 100, 'reason' => 'x'])->status, 'Recht aus der Zusatzrolle wirkt');
        $this->assertSame(403, $this->call('POST', '/points', ['user_ids' => [$this->admin], 'points' => 1, 'reason' => 'x'])->status);

        // Leere Liste: keine Zusatzrollen mehr, Grundrolle unverändert.
        $this->users->current = $this->admin;
        $this->assertSame([], $this->setRoles($m, [])->data['extra_roles']);
        $this->assertEqualsCanonicalizing(TestApp::caps(['member']), $this->caps($m));
        // Nur level wie bisher: Zusatzrollen bleiben.
        $this->setRoles($m, ['events']);
        $res = $this->call('PATCH', "/members/$m/role", ['level' => 'points']);
        $this->assertSame(['id' => $m, 'level' => 'points', 'extra_roles' => ['events']], $res->data);
        $roles = array_column($this->call('GET', '/admin/roles')->data, null, 'key');
        $this->assertSame(1, $roles['events']['users']);
        $this->assertSame(1, $roles['events']['extra_users']);
        $this->assertSame(1, $roles['points']['users']);
        $this->assertSame(0, $roles['points']['extra_users']);
        $this->assertTrue(array_column($config['roles'], 'extra', 'key')['events']);
        $this->assertFalse(array_column($config['roles'], 'extra', 'key')[Roles::MEMBER]);
    }

    public function testOnlyOwnRolesOnTopOfAMemberBaseRole(): void
    {
        $m = $this->users->add('m', Roles::MEMBER);
        foreach ([Roles::GUEST, Roles::MEMBER, Roles::ADMIN] as $system) {
            $this->assertSame(400, $this->setRoles($m, [$system])->status, "$system nur als Grundrolle");
        }
        $this->assertSame(400, $this->setRoles($m, ['r99'])->status, 'unbekannte Rolle');
        $this->assertSame(400, $this->call('PATCH', "/members/$m/role", ['extra_roles' => 'points'])->status, 'keine Liste');
        $this->assertSame(400, $this->call('PATCH', "/members/$m/role", [])->status, 'nichts angegeben');
        $this->assertSame([], $this->setRoles($m, ['events', 'events'], 'events')->data['extra_roles'], 'Grundrolle als Zusatzrolle fällt weg');

        $candidate = $this->users->add('anwaerter', TestApp::candidateRole($this->app));
        $this->assertSame(400, $this->setRoles($candidate, ['points'])->status, 'Rolle ohne Mitgliederbereich (Anwärter): keine Zusatzrollen');
        $guest = $this->users->add('gast', Roles::GUEST);
        $this->assertSame(400, $this->setRoles($guest, ['points'])->status);
        $other = $this->users->add('admin2', Roles::ADMIN);
        $this->assertSame(400, $this->setRoles($other, ['points'])->status, 'Admins haben alle Rechte');
        $this->assertSame(400, $this->setRoles($this->admin, ['points'])->status, 'nicht die eigenen');

        // In einem Schritt Grundrolle und Zusatzrollen: geprüft wird gegen die neue Grundrolle.
        $this->assertSame(200, $this->setRoles($candidate, ['points'], Roles::MEMBER)->status);
        $this->assertSame(['points'], $this->call('GET', "/members/$candidate")->data['extra_roles']);
        // Neue Grundrolle ohne Mitgliederbereich: Zusatzrollen fallen weg (sonst wirkten sie nach einem Wechsel zurück wieder).
        $res = $this->call('PATCH', "/members/$candidate/role", ['level' => TestApp::candidateRole($this->app)]);
        $this->assertSame([], $res->data['extra_roles']);
        $this->assertEqualsCanonicalizing(TestApp::caps(['calendar']), $this->caps($candidate));
        $this->call('PATCH', "/members/$candidate/role", ['level' => Roles::MEMBER]);
        $this->assertSame([], $this->call('GET', "/members/$candidate")->data['extra_roles']);
    }

    public function testStoredExtraRolesOnlyApplyWithAMemberBaseRole(): void
    {
        // Etwa nach einem Wechsel der Grundrolle im WP-Backend: gespeichert, aber ohne Wirkung.
        $m = $this->users->add('m', Roles::MEMBER);
        $this->setRoles($m, ['points']);
        $this->users->setRole($m, TestApp::candidateRole($this->app));
        $this->assertEqualsCanonicalizing(TestApp::caps(['calendar']), $this->caps($m));
        $this->assertSame([], $this->app->roles()->extraRoles($m, TestApp::candidateRole($this->app)));
        $this->users->setRole($m, Roles::MEMBER);
        $this->assertContains('manage_points', $this->caps($m));
    }

    public function testSmallestPointsFactorApplies(): void
    {
        $young = $this->app->roles()->create(['label' => 'Jungmariner', 'capabilities' => TestApp::caps(['member']), 'points_factor' => 0.5])['key'];
        $double = $this->app->roles()->create(['label' => 'Doppelt', 'capabilities' => TestApp::caps(['member']), 'points_factor' => 2])['key'];
        $a = $this->users->add('a', Roles::MEMBER);
        $b = $this->users->add('b', $double);
        $c = $this->users->add('c', $young);
        $this->setRoles($a, [$double]);
        $this->setRoles($b, [$young, 'points']);
        $this->setRoles($c, [$double]);
        $byUser = array_column($this->call('POST', '/points', ['user_ids' => [$a, $b, $c], 'points' => 4, 'reason' => 'Fest'])->data, 'factor', 'user_id');
        $this->assertSame([$a => 1.0, $b => 0.5, $c => 0.5], $byUser, 'der kleinste Faktor aller Rollen, auch wenn er aus der Grundrolle kommt');
    }

    public function testNonAdminsAssignOnlyRolesTheyCouldGiveAsBaseRole(): void
    {
        $this->app->roles()->create(['label' => 'Schriftführer', 'capabilities' => TestApp::caps(['member', 'promote_users', 'manage_points', 'manage_events'])]);
        $schrift = $this->users->add('schrift', 'r1');
        $m = $this->users->add('m', Roles::MEMBER);
        $kasse = $this->users->add('kasse', Roles::MEMBER);
        $this->setRoles($kasse, ['penalties']);
        $this->users->current = $schrift;
        $this->assertSame(200, $this->setRoles($m, ['points', 'events'])->status, 'eigene Rechte darf er weitergeben');
        $this->assertSame(403, $this->setRoles($m, ['points', 'penalties'])->status, 'Rechte, die er selbst nicht hat');
        $this->assertSame(403, $this->setRoles($m, ['r1'])->status, 'Rolle mit „Rollen vergeben“: nur Admins');
        $this->assertSame(403, $this->setRoles($kasse, [])->status, 'Person mit mehr Rechten (aus einer Zusatzrolle) nicht ändern');
        $this->assertSame(403, $this->call('PATCH', "/members/$kasse/role", ['level' => 'points'])->status, 'auch nicht ihre Grundrolle');
        $this->assertSame(['penalties'], $this->app->roles()->extraRoles($kasse, Roles::MEMBER));
        // Ohne „Rollen vergeben“ gar nicht.
        $this->users->current = $m;
        $this->assertSame(403, $this->setRoles($kasse, [])->status);
    }

    public function testDeletingARoleReplacesOrDropsItAsExtraRole(): void
    {
        $r1 = $this->app->roles()->create(['label' => 'Fahnenträger', 'capabilities' => TestApp::caps(['member', 'manage_events'])])['key'];
        $r2 = $this->app->roles()->create(['label' => 'Ersatz', 'capabilities' => TestApp::caps(['member', 'manage_documents'])])['key'];
        $a = $this->users->add('a', Roles::MEMBER);
        $b = $this->users->add('b', $r2);
        $this->setRoles($a, [$r1]);
        $this->setRoles($b, [$r1]);
        $res = $this->call('DELETE', "/admin/roles/$r1");
        $this->assertSame(400, $res->status, 'auch als Zusatzrolle zugeordnet: Ersatz nötig');
        $this->assertStringContainsString('2 Personen', $res->data['message']);
        $this->assertSame(200, $this->call('DELETE', "/admin/roles/$r1", [], ['replacement' => $r2])->status);
        $this->assertSame([$r2], $this->app->roles()->extraRoles($a, Roles::MEMBER), 'Zusatzrolle ersetzt');
        $this->assertSame([], $this->app->roles()->extraRoles($b, $r2), 'Ersatz ist schon ihre Grundrolle');
        $this->assertSame(0, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {member_roles} WHERE role = ?', [$r1]));

        // Feste Rolle als Ersatz: Die Zusatzrolle fällt einfach weg.
        $this->setRoles($b, ['points']);
        $this->assertSame(200, $this->call('DELETE', "/admin/roles/$r2", [], ['replacement' => Roles::MEMBER])->status);
        $this->assertSame(Roles::MEMBER, $this->users->find($b)?->role);
        $this->assertSame([], $this->app->roles()->extraRoles($a, Roles::MEMBER));
        $this->assertSame(['points'], $this->app->roles()->extraRoles($b, Roles::MEMBER));
    }

    public function testStandardRoleStaysWhileSomeoneHasItAsExtraRole(): void
    {
        $m = $this->users->add('m', Roles::MEMBER);
        $this->setRoles($m, ['points']);
        $this->call('PUT', '/admin/features', ['points' => false]);
        $this->assertTrue($this->app->roles()->exists('points'), 'noch zugeordnet');
        $this->setRoles($m, []);
        $this->call('PUT', '/admin/features', ['points' => true]);
        $this->call('PUT', '/admin/features', ['points' => false]);
        $this->assertFalse($this->app->roles()->exists('points'));
    }

    public function testAttendanceGroupFollowsTheBaseRole(): void
    {
        $m = $this->users->add('m', Roles::MEMBER);
        $this->setRoles($m, ['points']);
        $list = $this->call('PUT', '/attendance', ['source' => 'free', 'title' => 'Übung', 'date' => '2026-10-19', 'entries' => [$m => 'present']])->data;
        $people = array_column($this->call('GET', '/attendance/' . $list['id'])->data['people'], null, 'id');
        $this->assertSame(Roles::MEMBER, $people[$m]['role']);
        $this->assertSame(Roles::MEMBER, $people[$m]['group']);
    }

    public function testDeletedAccountLosesItsExtraRolesAndBackupContainsThem(): void
    {
        $a = $this->users->add('a', Roles::MEMBER);
        $b = $this->users->add('b', Roles::MEMBER);
        $this->setRoles($a, ['points']);
        $this->setRoles($b, ['events']);
        $this->assertContains('member_roles', Backup::CORE_TABLES);
        $dump = (new Backup($this->app->db))->dump(Backup::CORE_TABLES);
        $this->assertCount(2, $dump['member_roles']);

        $this->assertSame(200, $this->call('DELETE', "/members/$a")->status);
        $this->assertSame([[(string) $b]], array_map(static fn (array $r): array => [(string) $r['user_id']], $this->app->db->select('SELECT user_id FROM {member_roles}')));

        (new Backup($this->app->db))->restore($dump, Backup::CORE_TABLES);
        $this->assertSame(2, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {member_roles}'));
    }
}
