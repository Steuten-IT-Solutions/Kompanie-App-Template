<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\RoleService;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\MemorySettings;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** Rollen frei anlegen, Rechte zuweisen, Punktefaktor (Issue #28). */
final class CustomRolesTest extends TestCase
{
    private FakeUsers $users;
    private App $app;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->app = TestApp::create(TestApp::connection(), $this->users, ['labels' => ['roles' => ['manager' => 'Spieß']]]);
        $this->users->current = $this->users->add('admin', Roles::ADMIN);
    }

    /** @param array<string, mixed> $body */
    private function call(string $method, string $path, array $body = [], array $query = []): Response
    {
        return $this->app->api()->handle(new Request($method, $path, $query, $body));
    }

    /** @return array<string, array<string, mixed>> */
    private function byLabel(): array
    {
        return array_column($this->call('GET', '/admin/roles')->data, null, 'label');
    }

    public function testTemplatesWithLabelsFromKompanieJson(): void
    {
        $roles = $this->call('GET', '/admin/roles')->data;
        $this->assertSame([Roles::GUEST, Roles::MEMBER, Roles::ORGANIZER, Roles::MANAGER, Roles::ADMIN], array_column($roles, 'key'));
        $this->assertSame(['Gast', 'Mitglied', 'Verwalter', 'Spieß', 'Admin'], array_column($roles, 'label'));
        $this->assertSame(1, $roles[4]['users'], 'ein Admin');
        $this->assertTrue($roles[0]['system'] && $roles[4]['system']);
        $this->assertFalse($roles[1]['deletable'], 'Mitglied ist die Rolle neuer Konten');
        $this->assertSame('Spieß', array_column($this->app->api()->config()['roles'], 'label', 'key')[Roles::MANAGER]);
        // kompanie.json gilt nur für die Vorlagen beim ersten Start.
        $again = TestApp::create(TestApp::connection(), settings: new MemorySettings([RoleService::SETTING => $this->app->settings->get(RoleService::SETTING)]), config: ['labels' => ['roles' => ['manager' => 'Hauptmann']]]);
        $this->assertSame('Spieß', $again->roles()->label(Roles::MANAGER));
    }

    public function testCreateAssignAndRightsApplyImmediately(): void
    {
        $res = $this->call('POST', '/admin/roles', ['label' => 'Kassenwart', 'capabilities' => ['member', 'manage']]);
        $this->assertSame(201, $res->status);
        $role = $this->byLabel()['Kassenwart'];
        $this->assertSame('r1', $role['key']);
        $this->assertEqualsCanonicalizing(['member', 'manage'], $role['capabilities']);

        $kasse = $this->users->add('kasse', Roles::MEMBER);
        $this->assertSame(200, $this->call('PATCH', "/members/$kasse/role", ['level' => 'r1'])->status);
        $this->users->current = $kasse;
        $this->assertSame(200, $this->call('GET', '/members')->status, 'Punkte/Strafen verwalten');
        $this->assertSame(403, $this->call('POST', '/events', ['title' => 'x', 'start_date' => '2099-01-01'])->status, 'keine Terminpflege');

        // Rechte ändern wirkt sofort.
        $this->users->current = 1;
        $this->call('PATCH', '/admin/roles/r1', ['capabilities' => ['member', 'manage_events']]);
        $this->users->current = $kasse;
        $this->assertSame(403, $this->call('GET', '/members')->status);
        $this->assertSame(201, $this->call('POST', '/events', ['title' => 'Fest', 'start_date' => '2099-01-01'])->status);
    }

    public function testValidation(): void
    {
        $this->assertSame(400, $this->call('POST', '/admin/roles', ['label' => ''])->status, 'ohne Namen');
        $this->assertSame(400, $this->call('POST', '/admin/roles', ['label' => 'mitglied'])->status, 'Name schon vergeben');
        $this->assertSame(400, $this->call('POST', '/admin/roles', ['label' => 'X', 'capabilities' => ['manage_settings']])->status, 'Einstellungen nur für Admins');
        $this->assertSame(400, $this->call('POST', '/admin/roles', ['label' => 'X', 'capabilities' => ['manage']])->status, 'Verwalten ohne Mitgliederbereich');
        $this->assertSame(400, $this->call('POST', '/admin/roles', ['label' => 'X', 'points_factor' => 'halb'])->status);
        $this->assertSame(400, $this->call('POST', '/admin/roles', ['label' => 'X', 'points_factor' => 11])->status);
        $this->assertSame(400, $this->call('PATCH', '/admin/roles/admin', ['capabilities' => ['member']])->status, 'Admin fest');
        $this->assertSame(400, $this->call('PATCH', '/admin/roles/guest', ['capabilities' => ['member']])->status, 'Gast fest');
        $this->assertSame(200, $this->call('PATCH', '/admin/roles/guest', ['label' => 'Besucher'])->status, 'umbenennen geht');
        $this->assertSame(404, $this->call('PATCH', '/admin/roles/r9', ['label' => 'Y'])->status);
        $this->assertSame(400, $this->call('DELETE', '/admin/roles/member')->status);
        $this->assertSame(400, $this->call('DELETE', '/admin/roles/admin')->status);
    }

    public function testOnlyAdminsManageRoles(): void
    {
        foreach ([Roles::MANAGER, Roles::ORGANIZER, Roles::MEMBER] as $role) {
            $this->users->current = $this->users->add('u-' . $role, $role);
            $this->assertSame(403, $this->call('GET', '/admin/roles')->status, $role);
            $this->assertSame(403, $this->call('POST', '/admin/roles', ['label' => 'X'])->status, $role);
        }
    }

    public function testDeleteNeedsReplacementWhenAssigned(): void
    {
        $this->call('POST', '/admin/roles', ['label' => 'Fahnenträger', 'capabilities' => ['member']]);
        $u = $this->users->add('fahne', 'r1');
        $res = $this->call('DELETE', '/admin/roles/r1');
        $this->assertSame(400, $res->status);
        $this->assertStringContainsString('1 Personen', $res->data['message']);
        $this->assertSame(400, $this->call('DELETE', '/admin/roles/r1', [], ['replacement' => 'admin'])->status, 'nicht zum Admin machen');
        $this->assertSame(200, $this->call('DELETE', '/admin/roles/r1', [], ['replacement' => 'member'])->status);
        $this->assertSame(Roles::MEMBER, $this->users->find($u)?->role);
        $this->assertArrayNotHasKey('Fahnenträger', $this->byLabel());
    }

    public function testNoRightsBeyondOwn(): void
    {
        // Wer Rollen vergeben darf, aber kein Admin ist, vergibt nur Rollen mit Rechten, die er selbst hat.
        $this->call('POST', '/admin/roles', ['label' => 'Schriftführer', 'capabilities' => ['member', 'promote_users']]);
        $this->call('POST', '/admin/roles', ['label' => 'Kassenwart', 'capabilities' => ['member', 'manage']]);
        $schrift = $this->users->add('schrift', 'r1');
        $m = $this->users->add('m', Roles::MEMBER);
        $this->users->current = $schrift;
        $this->assertSame(403, $this->call('PATCH', "/members/$m/role", ['level' => 'r2'])->status, 'Kassenwart hat Rechte, die er nicht hat');
        $this->assertSame(403, $this->call('PATCH', "/members/$m/role", ['level' => 'admin'])->status);
        $this->assertSame(200, $this->call('PATCH', "/members/$m/role", ['level' => 'r1'])->status, 'eigene Rechte darf er weitergeben');
        $this->assertSame(400, $this->call('PATCH', "/members/$m/role", ['level' => 'unbekannt'])->status);
    }

    public function testPointsFactorAtBooking(): void
    {
        $this->call('POST', '/admin/roles', ['label' => 'Jungmariner', 'capabilities' => ['member'], 'points_factor' => '0,5']);
        $this->assertSame(0.5, $this->byLabel()['Jungmariner']['points_factor']);
        $jung = $this->users->add('jung', 'r1');
        $alt = $this->users->add('alt', Roles::MEMBER);
        $res = $this->call('POST', '/points', ['user_ids' => [$jung, $alt], 'points' => 5, 'reason' => 'Schützenfest']);
        $this->assertSame(201, $res->status);
        $byUser = array_column($res->data, null, 'user_id');
        $this->assertSame(2.5, $byUser[$jung]['points']);
        $this->assertSame(5, $byUser[$jung]['base_points']);
        $this->assertSame(0.5, $byUser[$jung]['factor']);
        $this->assertSame(5, $byUser[$alt]['points']);

        // Faktor übergehen
        $this->call('POST', '/points', ['user_ids' => [$jung], 'points' => 3, 'reason' => 'Sonder', 'apply_factor' => false]);
        // Spätere Änderung der Rolle ändert alte Einträge nicht.
        $this->call('PATCH', '/admin/roles/r1', ['points_factor' => 1]);
        $members = array_column($this->call('GET', '/members')->data, 'points', 'id');
        $this->assertSame(5.5, $members[$jung]);
        $this->assertSame(5, $members[$alt]);
        $this->users->current = $jung;
        $this->assertSame(5.5, $this->call('GET', '/me')->data['points']);
    }
}
