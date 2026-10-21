<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\RoleService;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\MemorySettings;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** Rechte einzelner Personen zusätzlich zur Rolle geben oder entziehen. */
final class CapabilityOverridesTest extends TestCase
{
    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $m;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->app = TestApp::create(TestApp::connection(), $this->users);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->m = $this->users->add('mitglied', Roles::MEMBER);
        $this->users->current = $this->admin;
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $query
     */
    private function call(string $method, string $path, array $body = [], array $query = []): Response
    {
        return $this->app->api()->handle(new Request($method, $path, $query, $body));
    }

    /** @param string[] $capabilities */
    private function role(string $label, array $capabilities): string
    {
        $this->assertSame(201, $this->call('POST', '/admin/roles', ['label' => $label, 'capabilities' => $capabilities])->status);
        return (string) array_column($this->call('GET', '/admin/roles')->data, 'key', 'label')[$label];
    }

    /** @param string[] $grant @param string[] $revoke */
    private function rights(int $id, array $grant, array $revoke = []): Response
    {
        return $this->call('PUT', "/members/$id/rights", ['grant' => $grant, 'revoke' => $revoke]);
    }

    private function can(int $id, Capability $cap): bool
    {
        return (bool) $this->users->find($id)?->can($cap);
    }

    public function testGrantAndRevokeApplyImmediately(): void
    {
        $res = $this->rights($this->m, ['manage_points']);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertSame(['member', 'calendar'], $res->data['role']);
        $this->assertSame(['manage_points'], $res->data['grant']);
        $this->assertSame([], $res->data['revoke']);
        $this->assertTrue($this->can($this->m, Capability::ManagePoints));
        $this->assertFalse($this->can($this->m, Capability::ManagePenalties));

        // Wirkt wie ein Recht aus der Rolle: Die Person darf Punkte vergeben.
        $this->users->current = $this->m;
        $this->assertSame(201, $this->call('POST', '/points', ['user_ids' => [$this->admin], 'points' => 2, 'reason' => 'Aufbau'])->status);
        $this->users->current = $this->admin;

        $kasse = $this->role('Kasse', ['member', 'manage_points', 'manage_penalties']);
        $k = $this->users->add('kasse', $kasse);
        $this->assertSame(200, $this->rights($k, [], ['manage_penalties'])->status);
        $this->assertTrue($this->can($k, Capability::ManagePoints));
        $this->assertFalse($this->can($k, Capability::ManagePenalties));
        $this->users->current = $k;
        $this->assertSame(403, $this->call('POST', '/penalties', ['user_ids' => [$this->m], 'amount_cents' => 100, 'reason' => 'x'])->status);
        $this->users->current = $this->admin;

        // Auf der Seite des Mitglieds, nur für die, die sie ändern dürfen.
        $detail = $this->call('GET', "/members/$k")->data;
        $this->assertSame(['grant' => [], 'revoke' => ['manage_penalties']], ['grant' => $detail['rights']['grant'], 'revoke' => $detail['rights']['revoke']]);
        $this->assertContains('create_users', $detail['rights']['editable']);
        $this->assertNull($this->call('GET', "/members/{$this->admin}")->data['rights'], 'Admins haben keine Ausnahmen');

        // Zurücksetzen.
        $this->assertSame(200, $this->rights($k, [])->status);
        $this->assertTrue($this->can($k, Capability::ManagePenalties));
        $this->assertSame([['user' => $this->m, 'grant' => ['manage_points'], 'revoke' => []]], $this->app->settings->get(RoleService::OVERRIDES_SETTING));
    }

    public function testRedundantEntriesAreDropped(): void
    {
        $res = $this->rights($this->m, ['member', 'calendar', 'manage_events'], ['manage_points']);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertSame(['manage_events'], $res->data['grant'], 'Mitgliederbereich und Kalender hat die Rolle schon');
        $this->assertSame([], $res->data['revoke'], 'Punkte hat die Rolle nicht');
    }

    public function testInvalidRequests(): void
    {
        $candidate = $this->users->add('anwaerter', Roles::CANDIDATE);
        $guest = $this->users->add('gast', Roles::GUEST);
        $this->assertSame(400, $this->rights($candidate, ['manage_points'])->status, 'Verwalten braucht den Mitgliederbereich');
        $this->assertSame(200, $this->rights($candidate, ['member', 'manage_points'])->status);
        $this->assertTrue($this->can($candidate, Capability::ManagePoints));
        $this->assertSame(400, $this->rights($this->m, [], ['calendar'])->status, 'Kalender gehört zum Mitgliederbereich');
        $this->assertSame(200, $this->rights($this->m, ['calendar'], ['member'])->status, 'ohne Mitgliederbereich, nur Kalender');
        $this->assertSame([Capability::Calendar], $this->users->find($this->m)?->capabilities);
        $this->assertSame(400, $this->rights($this->m, ['manage_points'], ['manage_points'])->status);
        $this->assertSame(400, $this->rights($this->m, ['manage_settings'])->status, 'Einstellungen nur für Admins');
        $this->assertSame(400, $this->rights($this->m, ['gibtsnicht'])->status);
        $this->assertSame(400, $this->call('PUT', "/members/{$this->m}/rights", ['grant' => 'manage_points'])->status);
        $this->assertSame(400, $this->rights($this->admin, ['manage_points'])->status, 'Admin: ohnehin alles; zugleich man selbst');
        $this->assertSame(400, $this->rights($guest, ['member'])->status, 'Gäste: erst eine Rolle geben');
        $this->assertSame(404, $this->rights(999, ['member'])->status);

        $this->users->current = $this->m;
        $this->assertSame(403, $this->rights($candidate, [])->status, 'ohne „Rollen vergeben“');
    }

    public function testDelegatedPromoterOnlyChangesOwnRights(): void
    {
        $chef = $this->users->add('chef', $this->role('Chef', ['member', 'promote_users', 'manage_points']));
        $this->users->current = $chef;

        $res = $this->rights($this->m, ['manage_points']);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertSame(['member', 'calendar', 'manage_points'], $res->data['editable']);
        $this->assertSame(403, $this->rights($this->m, ['manage_points', 'manage_events'])->status, 'hat er selbst nicht');
        $this->assertSame(403, $this->rights($this->m, ['manage_points', 'create_users'])->status, 'bleibt den Admins vorbehalten');
        $this->assertSame(400, $this->rights($chef, [])->status, 'nicht die eigenen');
        $this->assertNull($this->call('GET', "/members/$chef")->data['rights']);

        // Hat ein Admin der Person ein Recht gegeben, das der Chef nicht hat, verwaltet er sie nicht mehr.
        $this->users->current = $this->admin;
        $this->assertSame(200, $this->rights($this->m, ['manage_points', 'manage_events'])->status);
        $this->users->current = $chef;
        $this->assertSame(403, $this->rights($this->m, [])->status);
        $this->assertNull($this->call('GET', "/members/{$this->m}")->data['rights']);
        $this->assertSame(403, $this->call('PATCH', "/members/{$this->m}/role", ['level' => Roles::CANDIDATE])->status);
    }

    public function testOverridesSurviveRoleChangesWithoutWhatTheRoleCovers(): void
    {
        $this->assertSame(200, $this->rights($this->m, ['manage_events', 'manage_polls'])->status);
        // Termine verwalten (Standardrolle des Bereichs) hat manage_events selbst: Diese Ausnahme fällt weg.
        $this->assertSame(200, $this->call('PATCH', "/members/{$this->m}/role", ['level' => 'events'])->status);
        $this->assertSame(['manage_polls'], $this->call('GET', "/members/{$this->m}")->data['rights']['grant']);
        $this->assertTrue($this->can($this->m, Capability::ManagePolls));

        // Entzogen: nur, solange die Rolle das Recht hat; sonst käme es bei einer späteren Rolle unbemerkt zurück.
        $this->assertSame(200, $this->rights($this->m, ['manage_polls'], ['manage_events'])->status);
        $this->assertSame(200, $this->call('PATCH', "/members/{$this->m}/role", ['level' => Roles::MEMBER])->status);
        $this->assertSame(['grant' => ['manage_polls'], 'revoke' => []], array_intersect_key($this->call('GET', "/members/{$this->m}")->data['rights'], ['grant' => 1, 'revoke' => 1]));
        $this->assertSame(200, $this->call('PATCH', "/members/{$this->m}/role", ['level' => 'events'])->status);
        $this->assertTrue($this->can($this->m, Capability::ManageEvents));

        // Anwärter: ohne Mitgliederbereich wirken Rechte zum Verwalten nicht.
        $this->assertSame(200, $this->call('PATCH', "/members/{$this->m}/role", ['level' => Roles::CANDIDATE])->status);
        $this->assertSame([Capability::Calendar], $this->users->find($this->m)?->capabilities);
        $this->assertSame(200, $this->call('POST', "/members/{$this->m}/promote")->status);
        $this->assertTrue($this->can($this->m, Capability::ManagePolls));

        // Gast und Admin: keine Ausnahmen; sie verfallen.
        $this->assertSame(200, $this->call('PATCH', "/members/{$this->m}/role", ['level' => Roles::GUEST])->status);
        $this->assertSame([], $this->app->settings->get(RoleService::OVERRIDES_SETTING));
        $this->assertSame([], $this->users->find($this->m)?->capabilities);
    }

    public function testDeletedRoleAndDeletedAccount(): void
    {
        $kasse = $this->role('Kasse', ['member', 'manage_points', 'manage_penalties']);
        $k = $this->users->add('kasse', $kasse);
        $this->assertSame(200, $this->rights($k, ['manage_events'], ['manage_penalties'])->status);
        $this->assertSame(200, $this->call('DELETE', "/admin/roles/$kasse", [], ['replacement' => 'points'])->status);
        $this->assertSame('points', $this->users->find($k)?->role);
        $this->assertSame([['user' => $k, 'grant' => ['manage_events'], 'revoke' => []]], $this->app->settings->get(RoleService::OVERRIDES_SETTING));

        $this->assertSame(200, $this->call('DELETE', "/members/$k")->status);
        $this->assertSame([], $this->app->settings->get(RoleService::OVERRIDES_SETTING));
    }

    public function testStoredForNextRequest(): void
    {
        $this->assertSame(200, $this->rights($this->m, ['manage_documents'])->status);
        $users = new FakeUsers();
        $next = TestApp::create(TestApp::connection(), $users, settings: new MemorySettings([
            RoleService::SETTING => $this->app->settings->get(RoleService::SETTING),
            RoleService::OVERRIDES_SETTING => $this->app->settings->get(RoleService::OVERRIDES_SETTING),
        ]));
        $this->assertSame([Capability::Member, Capability::ManageDocuments, Capability::Calendar], $next->roles()->capabilitiesFor(Roles::MEMBER, $this->m));
        $this->assertSame([Capability::Member, Capability::Calendar], $next->roles()->capabilitiesFor(Roles::MEMBER, $this->admin), 'andere Person');
    }
}
