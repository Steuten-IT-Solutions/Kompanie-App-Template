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
 * Update auf einzelne Rechte zum Sehen (RoleService::migrateViewRights): Früher schloss der Mitgliederbereich alles
 * Sehen ein, Öffentliches sah jeder, der Kalender brachte das Brett mit. Danach sieht jede Person genau dasselbe.
 */
final class ViewRightsMigrationTest extends TestCase
{
    public function testEveryoneKeepsWhatTheySaw(): void
    {
        $db = TestApp::connection();
        $settings = new MemorySettings();
        $users = new FakeUsers();
        $before = TestApp::create($db, $users, settings: $settings, features: false);

        $a = $users->add('a', Roles::MEMBER);
        $b = $users->add('b', 'r2');
        $c = $users->add('c', Roles::MEMBER);
        $d = $users->add('d', Roles::MEMBER);
        $e = $users->add('e', 'r3');
        $f = $users->add('f', 'r2');
        $g = $users->add('g', Roles::GUEST);
        $before->roles()->setExtraRoles($d, ['r1']);

        // Stand vor dem Update, so gespeichert.
        $settings->set(RoleService::SETTING, [
            ['key' => 'guest', 'label' => 'Gast', 'capabilities' => []],
            ['key' => 'member', 'label' => 'Mitglied', 'capabilities' => ['member']],
            ['key' => 'r1', 'label' => 'Kasse', 'capabilities' => ['member', 'manage_points']],
            ['key' => 'r2', 'label' => 'Anwärter', 'capabilities' => ['calendar']],
            ['key' => 'r3', 'label' => 'Anwärter mit Intern', 'capabilities' => ['calendar', 'view_intern']],
            ['key' => 'admin', 'label' => 'Admin', 'capabilities' => []],
        ]);
        $settings->set(RoleService::OVERRIDES_SETTING, [
            ['user' => $a, 'grant' => [], 'revoke' => ['member']],
            ['user' => $b, 'grant' => ['member', 'manage_events'], 'revoke' => []],
            ['user' => $c, 'grant' => ['manage_documents'], 'revoke' => []],
            ['user' => $d, 'grant' => [], 'revoke' => ['manage_points']],
            ['user' => $f, 'grant' => [], 'revoke' => ['calendar']],
        ]);
        $settings->delete(RoleService::VIEW_SETTING);

        $app = TestApp::create($db, $users, settings: $settings, features: false);
        $this->assertTrue($settings->get(RoleService::VIEW_SETTING));
        $caps = static fn (int $id): array => array_map(static fn (Capability $cap): string => $cap->value, $users->find($id)?->capabilities ?? []);

        $this->assertEqualsCanonicalizing(Roles::PUBLIC_DEFAULTS, $caps($a), 'Mitgliederbereich entzogen: nur das Öffentliche');
        $this->assertEqualsCanonicalizing([...Roles::MEMBER_DEFAULTS, 'manage_events'], $caps($b));
        $this->assertEqualsCanonicalizing([...Roles::MEMBER_DEFAULTS, 'manage_documents'], $caps($c));
        $this->assertEqualsCanonicalizing(Roles::MEMBER_DEFAULTS, $caps($d), 'Zusatzrolle, ihr Recht entzogen');
        $this->assertEqualsCanonicalizing([...Roles::CANDIDATE_DEFAULTS, 'view_intern'], $caps($e));
        $this->assertEqualsCanonicalizing(Roles::PUBLIC_DEFAULTS, $caps($f), 'Kalender entzogen: auch das Brett nicht mehr');
        $this->assertEqualsCanonicalizing(Roles::PUBLIC_DEFAULTS, $caps($g));
        $this->assertEqualsCanonicalizing(Roles::PUBLIC_DEFAULTS, array_map(static fn (Capability $cap): string => $cap->value, $app->anonymous()->capabilities), 'ohne Anmeldung wie bisher');
        $this->assertSame(TestApp::caps(['member', 'manage_points']), $app->roles()->find('r1')['capabilities'] ?? null);

        // Nur einmal: Was der Admin danach ändert, bleibt.
        $app->roles()->update(Roles::MEMBER, ['capabilities' => array_values(array_diff(TestApp::caps(['member']), ['view_catalog']))]);
        $again = TestApp::create($db, $users, settings: $settings, features: false);
        $this->assertNotContains('view_catalog', $again->roles()->find(Roles::MEMBER)['capabilities'] ?? []);
        $this->assertEqualsCanonicalizing([...Roles::MEMBER_DEFAULTS, 'manage_documents'], [...$caps($c), 'view_catalog'], 'Ausnahmen bleiben');
    }

    public function testNewInstallationNeedsNoMigration(): void
    {
        $settings = new MemorySettings();
        $app = TestApp::create(TestApp::connection(), settings: $settings, features: false);
        $this->assertTrue($settings->get(RoleService::VIEW_SETTING));
        $this->assertSame(TestApp::caps([]), $app->roles()->find(Roles::GUEST)['capabilities'] ?? null);
    }
}
