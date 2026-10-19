<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Config\KompanieConfig;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** Rolle „Verwalter“ (organizer) zwischen Mitglied und Leitung (Issue #3). */
final class RolesTest extends TestCase
{
    public function testTemplatesBuildOnEachOther(): void
    {
        $order = [Roles::GUEST, Roles::MEMBER, Roles::ORGANIZER, Roles::MANAGER, Roles::ADMIN];
        for ($i = 1; $i < count($order); $i++) {
            $lower = Roles::templateCapabilities($order[$i - 1]);
            $higher = Roles::templateCapabilities($order[$i]);
            $this->assertSame([], array_udiff($lower, $higher, static fn (Capability $a, Capability $b): int => strcmp($a->value, $b->value)), $order[$i] . ' hat alle Rechte von ' . $order[$i - 1]);
        }
        $this->assertEqualsCanonicalizing([Capability::Member, Capability::ManageEvents, Capability::ManageDocuments], Roles::templateCapabilities(Roles::ORGANIZER));
        $this->assertEqualsCanonicalizing([Capability::Member, Capability::ManageEvents, Capability::ManageDocuments, Capability::Manage], Roles::templateCapabilities(Roles::MANAGER));
    }

    public function testDefaultLabelsAndOwnLabels(): void
    {
        $default = new KompanieConfig([]);
        $this->assertSame('Verwalter', $default->roleLabel('organizer'));
        $this->assertSame('Leitung', $default->roleLabel('manager'));
        $own = new KompanieConfig(['labels' => ['roles' => ['organizer' => 'Schriftführer', 'manager' => 'Spieß']]]);
        $this->assertSame('Schriftführer', $own->roleLabel('organizer'));
        $this->assertSame('Spieß', $own->roleLabel('manager'));
    }

    public function testOrganizerMaintainsEventsButNoPointsOrPenalties(): void
    {
        $users = new FakeUsers();
        $app = TestApp::create(TestApp::connection(), $users);
        $member = $users->add('mitglied', Roles::MEMBER);
        $users->current = $users->add('verwalter', Roles::ORGANIZER);
        $h = static fn (string $m, string $p, array $body = []) => $app->api()->handle(new Request($m, $p, [], $body));

        $created = $h('POST', '/events', ['title' => 'Schützenfest', 'start_date' => '2099-07-01']);
        $this->assertSame(201, $created->status);
        $this->assertSame(200, $h('PATCH', '/events/' . $created->data['id'], ['title' => 'Schützenfest 2099', 'start_date' => '2099-07-01'])->status);
        $this->assertSame(200, $h('DELETE', '/events/' . $created->data['id'])->status);
        $this->assertSame(200, $h('GET', '/me/points')->status, 'Verwalter ist Mitglied');

        foreach ([
            ['POST', '/points', ['user_ids' => [$member], 'points' => 1, 'reason' => 'x']],
            ['POST', '/penalties', ['user_ids' => [$member], 'amount_cents' => 100, 'reason' => 'x']],
            ['POST', '/catalog', ['label' => 'Fest', 'points' => 1]],
            ['GET', '/members', []],
            ['POST', '/users', ['name' => 'Hans Probe']],
            ['PATCH', "/members/$member/role", ['level' => 'organizer']],
            ['GET', '/admin/settings', []],
        ] as [$m, $p, $body]) {
            $this->assertSame(403, $h($m, $p, $body)->status, "$m $p");
        }
    }

    public function testManagerNowMaintainsEventsToo(): void
    {
        $users = new FakeUsers();
        $app = TestApp::create(TestApp::connection(), $users);
        $users->current = $users->add('leitung', Roles::MANAGER);
        $res = $app->api()->handle(new Request('POST', '/events', [], ['title' => 'Königsschießen', 'start_date' => '2099-08-01']));
        $this->assertSame(201, $res->status);
    }

    public function testMemberCannotMaintainEvents(): void
    {
        $users = new FakeUsers();
        $app = TestApp::create(TestApp::connection(), $users);
        $users->current = $users->add('mitglied', Roles::MEMBER);
        $this->assertSame(403, $app->api()->handle(new Request('POST', '/events', [], ['title' => 'x', 'start_date' => '2099-01-01']))->status);
    }

    public function testAdminAssignsOrganizerAndBack(): void
    {
        $users = new FakeUsers();
        $app = TestApp::create(TestApp::connection(), $users);
        $member = $users->add('mitglied', Roles::MEMBER);
        $users->current = $users->add('admin', Roles::ADMIN);
        $role = static fn (mixed $level) => $app->api()->handle(new Request('PATCH', "/members/$member/role", [], ['level' => $level]));

        $res = $role('organizer');
        $this->assertSame(200, $res->status);
        $this->assertSame('organizer', $res->data['level']);
        $this->assertSame(Roles::ORGANIZER, $users->find($member)?->role);
        $this->assertSame('member', $role('member')->data['level']);
        $this->assertSame(400, $role('verwalter')->status, 'unbekannte Rolle');
    }
}
