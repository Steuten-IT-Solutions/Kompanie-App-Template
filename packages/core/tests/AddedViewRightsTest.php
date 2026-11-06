<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\RoleService;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\MemorySettings;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * Später dazugekommene Rechte zum Sehen (Roles::ADDED_VIEW, Issue #278): Neue Installationen haben sie bei Gast und
 * Mitglied, beim Update bekommt sie jede Rolle außer Admin genau einmal (RoleService::migrateAddedViewRights).
 */
final class AddedViewRightsTest extends TestCase
{
    public function testRightsAreViewRightsAndGrantable(): void
    {
        foreach (Roles::ADDED_VIEW as $value) {
            $cap = Capability::from($value);
            $this->assertTrue($cap->isView(), $value);
            $this->assertNull($cap->requires(), $value . ': auch für Gast');
            $this->assertContains($cap, Roles::GRANTABLE, $value);
        }
    }

    public function testNewInstallation(): void
    {
        $settings = new MemorySettings();
        $app = TestApp::create(TestApp::connection(), settings: $settings, features: false);
        $this->assertSame(Roles::ADDED_VIEW, $settings->get(RoleService::ADDED_VIEW_SETTING), 'nichts mehr zu verteilen');
        foreach ([Roles::GUEST, Roles::MEMBER] as $key) {
            $caps = $app->roles()->find($key)['capabilities'] ?? [];
            foreach (Roles::ADDED_VIEW as $value) {
                $this->assertContains($value, $caps, "$key: $value");
            }
        }
        $anonymous = array_map(static fn (Capability $c): string => $c->value, $app->anonymous()->capabilities);
        $this->assertSame([], array_values(array_diff(Roles::ADDED_VIEW, $anonymous)), 'auch ohne Anmeldung');
    }

    public function testUpdateGivesThemOnceToEveryRoleButAdmin(): void
    {
        $db = TestApp::connection();
        $settings = new MemorySettings();
        $users = new FakeUsers();
        TestApp::create($db, $users, settings: $settings, features: false);
        $a = $users->add('a', Roles::MEMBER);
        $b = $users->add('b', 'r1');

        // Stand vor dem Update: Rollen ohne die neuen Rechte, eine Person mit einer Ausnahme.
        $stored = [
            ['key' => 'guest', 'label' => 'Gast', 'capabilities' => ['view_news']],
            ['key' => 'member', 'label' => 'Mitglied', 'capabilities' => ['member', 'view_news', 'view_documents']],
            ['key' => 'r1', 'label' => 'Kasse', 'capabilities' => ['member', 'manage_points']],
            ['key' => 'admin', 'label' => 'Admin', 'capabilities' => []],
        ];
        $settings->set(RoleService::SETTING, $stored);
        $settings->set(RoleService::OVERRIDES_SETTING, [['user' => $a, 'grant' => ['manage_events'], 'revoke' => ['view_documents']]]);
        $settings->delete(RoleService::ADDED_VIEW_SETTING);

        $app = TestApp::create($db, $users, settings: $settings, features: false);
        $this->assertSame(Roles::ADDED_VIEW, $settings->get(RoleService::ADDED_VIEW_SETTING));
        $roles = $app->roles()->all();
        $this->assertSame(['view_news', ...Roles::ADDED_VIEW], $roles[Roles::GUEST]['capabilities']);
        $this->assertSame(['view_news', ...Roles::ADDED_VIEW, 'member', 'view_documents'], $roles[Roles::MEMBER]['capabilities']);
        $this->assertSame([...Roles::ADDED_VIEW, 'member', 'manage_points'], $roles['r1']['capabilities'], 'auch eigene Rollen');
        $this->assertSame(['grant' => ['manage_events'], 'revoke' => ['view_documents']], $app->roles()->overridesOf($a), 'Ausnahmen bleiben');
        $has = static fn (int $id): array => array_map(static fn (Capability $c): string => $c->value, $users->find($id)?->capabilities ?? []);
        $this->assertSame([], array_values(array_diff(Roles::ADDED_VIEW, $has($b))));

        // Nur einmal: Was der Admin danach entzieht, bleibt entzogen.
        $app->roles()->update(Roles::GUEST, ['capabilities' => ['view_news']]);
        $again = TestApp::create($db, $users, settings: $settings, features: false);
        $this->assertSame(['view_news'], $again->roles()->find(Roles::GUEST)['capabilities'] ?? null);
    }

    public function testRightAddedLaterIsHandedOutOnItsOwn(): void
    {
        // Ein Recht, das in einer späteren Version zu ADDED_VIEW dazukommt: Nur das fehlende wird verteilt.
        [$first, $second] = Roles::ADDED_VIEW;
        $db = TestApp::connection();
        $settings = new MemorySettings();
        $users = new FakeUsers();
        TestApp::create($db, $users, settings: $settings, features: false);
        $settings->set(RoleService::SETTING, [
            ['key' => 'guest', 'label' => 'Gast', 'capabilities' => ['view_news']],
            ['key' => 'member', 'label' => 'Mitglied', 'capabilities' => ['member']],
            ['key' => 'admin', 'label' => 'Admin', 'capabilities' => []],
        ]);
        $settings->set(RoleService::ADDED_VIEW_SETTING, [$first]);

        $app = TestApp::create($db, $users, settings: $settings, features: false);
        $this->assertSame([$first, $second], $settings->get(RoleService::ADDED_VIEW_SETTING));
        $guest = $app->roles()->find(Roles::GUEST)['capabilities'] ?? [];
        $this->assertContains($second, $guest);
        $this->assertNotContains($first, $guest, 'schon verteilt, vom Admin entzogen');
    }
}
