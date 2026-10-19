<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\RoleService;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\MemorySettings;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** Feste Rollen, Standardrollen der Bereiche und Rollen älterer Installationen (Issues #3, #28). */
final class RolesTest extends TestCase
{
    public function testNewInstallationHasOnlyFixedRoles(): void
    {
        $app = TestApp::create(TestApp::connection(), features: false);
        $this->assertSame([Roles::GUEST, Roles::MEMBER, Roles::ADMIN], array_keys($app->roles()->all()));
        $this->assertEqualsCanonicalizing([Capability::Member], $app->roles()->capabilities(Roles::MEMBER));
        $this->assertSame([], $app->roles()->capabilities(Roles::ORGANIZER), 'frühere Vorlage gibt es nicht');
    }

    public function testFeaturesBringStandardRoles(): void
    {
        $users = new FakeUsers();
        $app = TestApp::create(TestApp::connection(), $users, ['labels' => ['sections' => ['penalties' => 'Kasse']]], features: false);
        $app->features()->update(['points' => true, 'penalties' => true, 'calendar' => true]);
        $roles = $app->roles()->all();
        $this->assertSame([Roles::GUEST, Roles::MEMBER, 'points', 'penalties', Roles::ADMIN], array_keys($roles), 'Kalender braucht keine Rechte');
        $this->assertSame('Punkte verwalten', $roles['points']['label']);
        $this->assertSame('Kasse verwalten', $roles['penalties']['label'], 'mit der Bezeichnung aus kompanie.json');
        $this->assertEqualsCanonicalizing([Capability::Member, Capability::ManagePenalties], $app->roles()->capabilities('penalties'));

        // Ausschalten: Rolle verschwindet, wenn niemand sie hat; sonst bleibt sie.
        $users->add('kasse', 'penalties');
        $app->features()->update(['points' => false, 'penalties' => false]);
        $this->assertSame([Roles::GUEST, Roles::MEMBER, 'penalties', Roles::ADMIN], array_keys($app->roles()->all()));
        // Wieder einschalten: keine zweite Rolle.
        $app->features()->update(['penalties' => true]);
        $this->assertCount(4, $app->roles()->all());
    }

    public function testOwnRoleWithSameNameIsNotDuplicated(): void
    {
        $app = TestApp::create(TestApp::connection(), features: false);
        $app->roles()->create(['label' => 'Termine verwalten', 'capabilities' => ['member', 'manage_events']]);
        $app->features()->update(['events' => true]);
        $this->assertSame(1, count(array_filter($app->roles()->all(), static fn (array $r): bool => $r['label'] === 'Termine verwalten')));
    }

    public function testExistingInstallationKeepsFormerRoles(): void
    {
        $db = TestApp::connection();
        $settings = new MemorySettings();
        // Stand vor dieser Version: Datenbank eingerichtet, noch keine Rollen in den Einstellungen.
        TestApp::create($db, settings: $settings, features: false);
        $settings->delete(RoleService::SETTING);
        $app = TestApp::create($db, config: ['labels' => ['roles' => ['manager' => 'Spieß']]], settings: $settings, features: false);
        $roles = $app->roles()->all();
        $this->assertSame([Roles::GUEST, Roles::MEMBER, Roles::ORGANIZER, Roles::MANAGER, Roles::ADMIN], array_keys($roles));
        $this->assertSame('Spieß', $roles[Roles::MANAGER]['label']);
        $this->assertEqualsCanonicalizing(
            [Capability::Member, Capability::ManageEvents, Capability::ManageDocuments, Capability::ManagePoints, Capability::ManagePenalties],
            $app->roles()->capabilities(Roles::MANAGER),
        );
        // Bestehende Installationen bekommen beim Einschalten neue Standardrollen, behalten aber ihre.
        $app->features()->update(['points' => true]);
        $this->assertArrayHasKey('points', $app->roles()->all());
        $this->assertArrayHasKey(Roles::MANAGER, $app->roles()->all());
    }

    public function testFormerManageRightIsSplit(): void
    {
        $settings = new MemorySettings([RoleService::SETTING => [['key' => 'r1', 'label' => 'Kasse', 'capabilities' => ['member', 'manage']]]]);
        $app = TestApp::create(TestApp::connection(), settings: $settings, features: false);
        $this->assertEqualsCanonicalizing([Capability::Member, Capability::ManagePoints, Capability::ManagePenalties], $app->roles()->capabilities('r1'));
        $this->assertSame([Roles::GUEST, Roles::MEMBER, 'r1', Roles::ADMIN], array_keys($app->roles()->all()), 'feste Rollen ergänzt');
    }

    public function testMemberIsFixedButCanBeRenamed(): void
    {
        $users = new FakeUsers();
        $app = TestApp::create(TestApp::connection(), $users);
        $users->current = $users->add('admin', Roles::ADMIN);
        $patch = static fn (array $body) => $app->api()->handle(new Request('PATCH', '/admin/roles/member', [], $body));
        $this->assertSame(400, $patch(['capabilities' => []])->status);
        $this->assertSame(200, $patch(['label' => 'Kamerad', 'points_factor' => 1])->status);
        $this->assertSame('Kamerad', $app->roles()->label(Roles::MEMBER));
        $this->assertSame(400, $app->api()->handle(new Request('DELETE', '/admin/roles/member'))->status);
    }

    public function testNonAdminsCannotPassOnUserRights(): void
    {
        $users = new FakeUsers();
        $app = TestApp::create(TestApp::connection(), $users);
        $app->roles()->create(['label' => 'Schriftführer', 'capabilities' => ['member', 'promote_users', 'create_users', 'manage_points']]);
        $schrift = $users->add('schrift', 'r1');
        $kasse = $users->add('kasse', 'penalties');
        $m = $users->add('m', Roles::MEMBER);
        $users->current = $schrift;
        $role = static fn (int $id, string $level) => $app->api()->handle(new Request('PATCH', "/members/$id/role", [], ['level' => $level]))->status;
        $this->assertSame(403, $role($m, 'r1'), 'Rolle mit Rollen vergeben/Konten anlegen: nur Admins');
        $this->assertSame(403, $role($m, 'penalties'), 'Rechte, die er selbst nicht hat');
        $this->assertSame(200, $role($m, 'points'), 'eigene Rechte darf er weitergeben');
        $reset = static fn (int $id) => $app->api()->handle(new Request('POST', "/members/$id/password"))->status;
        $this->assertSame(403, $reset($kasse), 'kein Passwort von Personen mit anderen Rechten (Kontoübernahme)');
        $this->assertSame(200, $reset($m));
    }
}
