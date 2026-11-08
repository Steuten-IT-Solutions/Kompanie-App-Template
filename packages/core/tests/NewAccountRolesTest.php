<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * Rolle und Zusatzrollen gleich beim Anlegen (einzeln, mehrere, Import, Anfragen): Wer Rollen vergeben darf, wählt jede
 * Rolle, die er auch danach vergeben dürfte; wer nur Mitglieder anlegen darf, nur Mitglied und die Rollen für neue Konten.
 */
final class NewAccountRolesTest extends TestCase
{
    /** @return array{0: \KompanieApp\Core\App, 1: FakeUsers, 2: \Closure, 3: array<string, string>} */
    private function setUpApp(bool $adminsAssignable = true): array
    {
        $users = new FakeUsers($adminsAssignable);
        $app = TestApp::create(TestApp::connection(), $users);
        $users->current = $users->add('admin', Roles::ADMIN);
        $call = static fn (string $method, string $path, array $body = []) => $app->api()->handle(new Request($method, $path, [], $body));
        $keys = [
            'board' => $app->roles()->create(['label' => 'Vorstand', 'capabilities' => TestApp::caps(['member', 'manage_events'])])['key'],
            'cash' => $app->roles()->create(['label' => 'Kasse', 'capabilities' => TestApp::caps(['member', 'manage_points'])])['key'],
            'friend' => $app->roles()->create(['label' => 'Freunde', 'capabilities' => ['calendar']])['key'],
            'clerk' => $app->roles()->create(['label' => 'Schriftführer', 'capabilities' => TestApp::caps(['member', 'create_users'])])['key'],
            'chief' => $app->roles()->create(['label' => 'Leitung', 'capabilities' => TestApp::caps(['member', 'create_users', 'promote_users', 'manage_events'])])['key'],
        ];
        return [$app, $users, $call, $keys];
    }

    public function testAdminCreatesWithAnyRoleAndExtraRoles(): void
    {
        [$app, $users, $call, $k] = $this->setUpApp();
        $res = $call('POST', '/users', ['first_name' => 'Vera', 'level' => $k['board'], 'extra_roles' => [$k['cash']]]);
        $this->assertSame(201, $res->status, (string) json_encode($res->data));
        $this->assertSame([$k['board'], [$k['cash']]], [$res->data['level'], $res->data['extra_roles']]);
        $vera = $users->find((int) $res->data['id']);
        $this->assertNotNull($vera);
        $this->assertSame([$k['board'], [$k['cash']]], [$vera->role, $app->roles()->extraRolesOf($vera)]);
        $this->assertSame(201, $call('POST', '/users', ['first_name' => 'Anna', 'level' => Roles::ADMIN])->status, 'Admins ernennen Admins (eigenständig)');

        $config = $app->api()->config();
        $this->assertContains($k['board'], $config['user']['create_roles']);
        $this->assertContains(Roles::ADMIN, $config['user']['create_roles']);
        $this->assertContains($k['cash'], $config['user']['create_extra_roles']);
        $this->assertNotContains(Roles::MEMBER, $config['user']['create_extra_roles'], 'nur eigene Rollen als Zusatzrolle');
        $roles = array_column($config['roles'], 'company', 'key');
        $this->assertSame([true, false, false], [$roles[Roles::MEMBER], $roles[$k['friend']], $roles[Roles::ADMIN]]);
    }

    public function testExtraRolesAreChecked(): void
    {
        [, , $call, $k] = $this->setUpApp();
        $this->assertSame(400, $call('POST', '/users', ['first_name' => 'X', 'extra_roles' => [Roles::MEMBER]])->status, 'feste Rolle');
        $this->assertSame(400, $call('POST', '/users', ['first_name' => 'X', 'extra_roles' => ['gibtsnicht']])->status);
        $this->assertSame(400, $call('POST', '/users', ['first_name' => 'X', 'level' => $k['friend'], 'extra_roles' => [$k['cash']]])->status, 'Grundrolle ohne Mitgliederbereich');
        $this->assertSame(400, $call('POST', '/users', ['first_name' => 'X', 'extra_roles' => 'kasse'])->status, 'keine Liste');
        // Die Grundrolle selbst als Zusatzrolle fällt weg.
        $res = $call('POST', '/users', ['first_name' => 'Y', 'level' => $k['board'], 'extra_roles' => [$k['board']]]);
        $this->assertSame([201, null], [$res->status, $res->data['extra_roles'] ?? null]);
    }

    public function testOnlyCreateUsersKeepsRolesForNewAccounts(): void
    {
        [$app, $users, $call, $k] = $this->setUpApp();
        $users->current = $users->add('schrift', $k['clerk']);
        $this->assertSame(403, $call('POST', '/users', ['first_name' => 'X', 'level' => $k['board']])->status, 'ohne Rollen vergeben');
        $this->assertSame(403, $call('POST', '/users', ['first_name' => 'X', 'extra_roles' => [$k['cash']]])->status);
        $this->assertSame(403, $call('POST', '/users', ['first_name' => 'X', 'level' => Roles::ADMIN])->status);
        $this->assertSame(201, $call('POST', '/users', ['first_name' => 'X'])->status);
        $config = $app->api()->config();
        $this->assertSame([Roles::MEMBER], $config['user']['create_roles']);
        $this->assertSame([], $config['user']['create_extra_roles']);
    }

    public function testPromoteUsersMayCreateWhatTheyMayAssign(): void
    {
        [$app, $users, $call, $k] = $this->setUpApp();
        $users->current = $users->add('chef', $k['chief']);
        $this->assertSame(201, $call('POST', '/users', ['first_name' => 'Vera', 'level' => $k['board']])->status, 'Termine verwalten hat er selbst');
        $this->assertSame(403, $call('POST', '/users', ['first_name' => 'Kai', 'level' => $k['cash']])->status, 'Punkte verwalten hat er nicht');
        $this->assertSame(403, $call('POST', '/users', ['first_name' => 'Kai', 'extra_roles' => [$k['cash']]])->status);
        $this->assertSame(403, $call('POST', '/users', ['first_name' => 'Kai', 'level' => $k['clerk']])->status, 'Konten anlegen weitergeben nur Admins');
        $this->assertSame(403, $call('POST', '/users', ['first_name' => 'Kai', 'level' => Roles::ADMIN])->status);
        $res = $call('POST', '/users', ['first_name' => 'Lea', 'extra_roles' => [$k['board']]]);
        $this->assertSame([201, [$k['board']]], [$res->status, $res->data['extra_roles'] ?? null]);
        $config = $app->api()->config();
        $this->assertContains($k['board'], $config['user']['create_roles']);
        $this->assertNotContains($k['cash'], $config['user']['create_roles']);
        $this->assertSame([$k['board']], array_values(array_intersect($config['user']['create_extra_roles'], [$k['board'], $k['cash'], $k['clerk']])));
    }

    public function testNoAdminsWhereTheAppCannotAssignThem(): void
    {
        [, , $call] = $this->setUpApp(false);
        $this->assertSame(403, $call('POST', '/users', ['first_name' => 'Anna', 'level' => Roles::ADMIN])->status);
    }

    public function testBulkWithRoleExtraRolesAndNoAccess(): void
    {
        [$app, $users, $call, $k] = $this->setUpApp();
        $res = $call('POST', '/users/bulk', ['level' => $k['board'], 'extra_roles' => [$k['cash']], 'people' => [
            ['first_name' => 'Anna'],
            ['first_name' => 'Ben', 'no_access' => true, 'username' => 'egal'],
        ]]);
        $this->assertSame(201, $res->status, (string) json_encode($res->data));
        [$anna, $ben] = $res->data;
        $this->assertArrayHasKey('password', $anna);
        $this->assertSame([true, false], [$ben['no_access'] ?? null, isset($ben['password'])]);
        $this->assertTrue($app->members()->hasNoAccess((int) $ben['id']));
        foreach ([$anna, $ben] as $p) {
            $u = $users->find((int) $p['id']);
            $this->assertNotNull($u);
            $this->assertSame([$k['board'], [$k['cash']]], [$u->role, $app->roles()->extraRolesOf($u)]);
        }
        $this->assertSame(400, $call('POST', '/users/bulk', ['level' => $k['friend'], 'extra_roles' => [$k['cash']], 'people' => [['first_name' => 'C']]])->status);
    }

    public function testImportWithRoleAndExtraRoles(): void
    {
        [$app, $users, $call, $k] = $this->setUpApp();
        $csv = "Vorname;Nachname;Status;Zusatzrollen\nVera;Vorn;Vorstand;Kasse\nKai;Kurz;;Vorstand, Kasse\n";
        $res = $call('POST', '/members/import', ['csv' => $csv, 'apply' => true]);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertTrue($res->data['applied'], (string) json_encode($res->data['rows']));
        $this->assertSame(['Kasse'], $res->data['rows'][0]['extra_roles']);
        $this->assertSame(['Vorstand', 'Kasse'], $res->data['rows'][1]['extra_roles']);
        $byName = [];
        foreach ($users->list(true) as $u) {
            $byName[$u->name] = $u;
        }
        $this->assertSame([$k['board'], [$k['cash']]], [$byName['Vera Vorn']->role, $app->roles()->extraRolesOf($byName['Vera Vorn'])]);
        $this->assertSame([Roles::MEMBER, [$k['board'], $k['cash']]], [$byName['Kai Kurz']->role, $app->roles()->extraRolesOf($byName['Kai Kurz'])]);

        $bad = $call('POST', '/members/import', ['csv' => "Vorname;Zusatzrollen\nOtto;Gibtsnicht\n", 'apply' => false]);
        $this->assertStringContainsString('Gibtsnicht', (string) $bad->data['rows'][0]['error']);

        // Ohne Rollen vergeben: Status nur Mitglied und Rollen für neue Konten, Spalte Zusatzrollen übergangen.
        $users->current = $users->add('schrift', $k['clerk']);
        $res = $call('POST', '/members/import', ['csv' => "Vorname;Status;Zusatzrollen\nPia;Vorstand;Kasse\n", 'apply' => false]);
        $this->assertSame('Zusatzrollen', $res->data['columns']['ignored'][0]['column'] ?? null);
        $this->assertStringContainsString('Vorstand', (string) ($res->data['rows'][0]['error'] ?? ''));
    }
}
