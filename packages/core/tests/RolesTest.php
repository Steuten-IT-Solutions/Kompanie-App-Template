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

/** Feste Rollen, Standardrollen der Bereiche, Rollen älterer Installationen und Anwärter als eigene Rolle (Issues #3, #23, #28). */
final class RolesTest extends TestCase
{
    public function testNewInstallationHasOnlyFixedRoles(): void
    {
        $app = TestApp::create(TestApp::connection(), features: false);
        $this->assertSame([Roles::GUEST, Roles::MEMBER, Roles::ADMIN], array_keys($app->roles()->all()), 'Anwärter legt der Admin selbst an');
        $this->assertEqualsCanonicalizing([Capability::Member, Capability::Calendar, Capability::ViewIntern], $app->roles()->capabilities(Roles::MEMBER), 'Mitgliederbereich mit Kalender');
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
        $this->assertEqualsCanonicalizing([Capability::Member, Capability::Calendar, Capability::ViewIntern, Capability::ManagePenalties], $app->roles()->capabilities('penalties'));

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
            [Capability::Member, Capability::Calendar, Capability::ViewIntern, Capability::ManageEvents, Capability::ManageDocuments, Capability::ManagePoints, Capability::ManagePenalties],
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
        $this->assertEqualsCanonicalizing([Capability::Member, Capability::Calendar, Capability::ViewIntern, Capability::ManagePoints, Capability::ManagePenalties], $app->roles()->capabilities('r1'));
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

    /** Stand vor diesem Update: Anwärter war eine feste Rolle und ist gespeichert (die Rollen wurden seitdem geändert). */
    public function testStoredFormerCandidateBecomesOwnRole(): void
    {
        $users = new FakeUsers();
        $settings = new MemorySettings([RoleService::SETTING => [
            ['key' => 'guest', 'label' => 'Gast', 'capabilities' => []],
            ['key' => 'candidate', 'label' => 'Rekrut', 'capabilities' => ['calendar']],
            ['key' => 'member', 'label' => 'Mitglied', 'capabilities' => ['member']],
            ['key' => 'r1', 'label' => 'Jugend', 'capabilities' => ['member'], 'points_factor' => 0.5],
            ['key' => 'admin', 'label' => 'Admin', 'capabilities' => []],
        ]]);
        $app = TestApp::create(TestApp::connection(), $users, settings: $settings, features: false);
        $roles = $app->roles()->all();
        $this->assertSame([Roles::GUEST, Roles::CANDIDATE, Roles::MEMBER, 'r1', Roles::ADMIN], array_keys($roles), 'eigene Rolle vor Mitglied: in den Zählern (von unten nach oben) wie bisher nach den Mitgliedern');
        $this->assertSame(['key' => 'candidate', 'label' => 'Rekrut', 'capabilities' => ['calendar'], 'points_factor' => 1.0, 'new_accounts' => true, 'promotable' => true], $roles[Roles::CANDIDATE]);
        $this->assertTrue($settings->get(RoleService::CANDIDATE_SETTING));
        $cand = $users->add('anwaerter', Roles::CANDIDATE);
        $this->assertSame([Capability::Calendar], $users->find($cand)?->capabilities, 'niemand verliert Rechte');
        $described = array_column($app->roles()->describe(), null, 'key')[Roles::CANDIDATE];
        $this->assertSame([false, true], [$described['system'], $described['deletable']], 'jetzt änderbar und löschbar');

        // Einmalig: Ändert der Admin die Rolle danach, bleibt es dabei, auch nach dem nächsten Aufruf.
        $app->roles()->update(Roles::CANDIDATE, ['new_accounts' => false]);
        $again = TestApp::create(TestApp::connection(), settings: $settings, features: false);
        $this->assertFalse($again->roles()->find(Roles::CANDIDATE)['new_accounts']);
        $app->roles()->delete(Roles::CANDIDATE, Roles::MEMBER);
        $this->assertSame(Roles::MEMBER, $users->find($cand)?->role);
        $this->assertNull(TestApp::create(TestApp::connection(), settings: $settings, features: false)->roles()->find(Roles::CANDIDATE), 'gelöscht bleibt gelöscht');
    }

    /** Nie gespeichert (die Rollen blieben seit Anwärter unverändert), aber vergeben: anlegen, mit freiem Namen. */
    public function testUnsavedFormerCandidateIsCreatedWhenSomeoneHasIt(): void
    {
        $users = new FakeUsers();
        $cand = $users->add('anwaerter', Roles::CANDIDATE);
        $settings = new MemorySettings([RoleService::SETTING => [
            ['key' => 'guest', 'label' => 'Gast', 'capabilities' => []],
            ['key' => 'member', 'label' => 'Mitglied', 'capabilities' => ['member']],
            ['key' => 'r1', 'label' => 'Anwärter', 'capabilities' => ['member']],
            ['key' => 'admin', 'label' => 'Admin', 'capabilities' => []],
        ]]);
        $app = TestApp::create(TestApp::connection(), $users, settings: $settings, features: false);
        $role = $app->roles()->find(Roles::CANDIDATE);
        $this->assertSame('Anwärter (fest)', $role['label'] ?? null, 'eigene Rolle gleichen Namens: anderer Name');
        $this->assertSame([['calendar'], true, true], [$role['capabilities'], $role['new_accounts'], $role['promotable']]);
        $this->assertSame([Capability::Calendar], $users->find($cand)?->capabilities);
        // Rechte der eigenen Rolle lassen sich weiter ändern, auch mit unverändertem Namen.
        $app->roles()->update('r1', ['label' => 'Anwärter', 'capabilities' => ['calendar']]);
        $this->assertSame([Capability::Calendar], $app->roles()->capabilities('r1'));
    }

    public function testNoCandidateRoleWhenNobodyHadIt(): void
    {
        $settings = new MemorySettings([RoleService::SETTING => [['key' => 'r1', 'label' => 'Kasse', 'capabilities' => ['member']]]]);
        $users = new FakeUsers();
        $app = TestApp::create(TestApp::connection(), $users, settings: $settings, features: false);
        $this->assertNull($app->roles()->find(Roles::CANDIDATE));
        $this->assertTrue($settings->get(RoleService::CANDIDATE_SETTING), 'nur einmal nachsehen');
        $this->assertSame([], $app->roles()->capabilities(Roles::CANDIDATE), 'unbekannte Rolle: keine Rechte');
    }

    public function testAllRolesCanBeReordered(): void
    {
        $users = new FakeUsers();
        $app = TestApp::create(TestApp::connection(), $users, features: false);
        $users->current = $users->add('admin', Roles::ADMIN);
        $a = $app->roles()->create(['label' => 'A', 'capabilities' => ['member']])['key'];
        $b = $app->roles()->create(['label' => 'B', 'capabilities' => ['calendar']])['key'];
        $this->assertSame([Roles::GUEST, Roles::MEMBER, $a, $b, Roles::ADMIN], array_keys($app->roles()->all()), 'neue Rollen vor Admin');
        $order = static fn (array $body) => $app->api()->handle(new Request('PUT', '/admin/roles/order', [], $body));
        // Auch feste Rollen: Anwärter (B) zwischen Gast und Mitglied, Admin nicht mehr am Ende.
        $res = $order(['keys' => [Roles::GUEST, $b, Roles::MEMBER, Roles::ADMIN, $a]]);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertSame([Roles::GUEST, $b, Roles::MEMBER, Roles::ADMIN, $a], array_column($res->data, 'key'));
        $this->assertSame([Roles::GUEST, $b, Roles::MEMBER, Roles::ADMIN, $a], array_column($app->api()->config()['roles'], 'key'));
        $c = $app->roles()->create(['label' => 'C', 'capabilities' => []])['key'];
        $this->assertSame($c, array_key_last($app->roles()->all()), 'Admin steht nicht am Ende: neue Rolle ganz hinten');
        $again = TestApp::create(TestApp::connection(), settings: new MemorySettings([RoleService::SETTING => $app->settings->get(RoleService::SETTING), RoleService::CANDIDATE_SETTING => true]), features: false);
        $this->assertSame([Roles::GUEST, $b, Roles::MEMBER, Roles::ADMIN, $a, $c], array_keys($again->roles()->all()), 'gespeicherte Reihenfolge bleibt');
        $all = [Roles::GUEST, $b, Roles::MEMBER, Roles::ADMIN, $a, $c];
        foreach ([[$a, $b, $c], [...$all, $a], [...array_slice($all, 1), 'r99'], 'r1', [...array_slice($all, 1), 3]] as $bad) {
            $this->assertSame(400, $order(['keys' => $bad])->status, (string) json_encode($bad));
        }
        $this->assertSame(400, $order([])->status);
        $users->current = $users->add('m', Roles::MEMBER);
        $this->assertSame(403, $order(['keys' => $all])->status, 'nur Admins');
    }

    public function testAdminsGetAPointsFactor(): void
    {
        $users = new FakeUsers();
        $app = TestApp::create(TestApp::connection(), $users);
        $users->current = $users->add('admin', Roles::ADMIN);
        $patch = static fn (string $key, array $body) => $app->api()->handle(new Request('PATCH', "/admin/roles/$key", [], $body));
        $this->assertSame(200, $patch(Roles::ADMIN, ['points_factor' => '0,5'])->status);
        $this->assertSame(0.5, $app->roles()->pointsFactorFor($users->current, Roles::ADMIN));
        $this->assertSame(0.5, array_column($app->api()->config()['roles'], 'points_factor', 'key')[Roles::ADMIN]);
        $this->assertSame(400, $patch(Roles::ADMIN, ['capabilities' => []])->status, 'Rechte bleiben fest');
        $this->assertSame(400, $patch(Roles::GUEST, ['points_factor' => 0.5])->status, 'Gäste bekommen keine Punkte');
        $this->assertSame(400, $patch(Roles::MEMBER, ['new_accounts' => false])->status, 'nur bei eigenen Rollen');
    }

    public function testRolesForNewAccountsAndPromotion(): void
    {
        $users = new FakeUsers();
        $app = TestApp::create(TestApp::connection(), $users);
        $admin = $users->add('admin', Roles::ADMIN);
        $users->current = $admin;
        $call = static fn (string $method, string $path, array $body = []) => $app->api()->handle(new Request($method, $path, [], $body));
        $friend = $app->roles()->create(['label' => 'Freunde', 'capabilities' => ['calendar']])['key'];
        $this->assertSame(400, $call('POST', '/users', ['name' => 'Fritz', 'level' => $friend])->status, 'nicht freigegeben');
        $this->assertSame(400, $call('POST', '/users', ['name' => 'Fritz', 'level' => Roles::ADMIN])->status);
        $this->assertSame(400, $call('POST', '/admin/roles', ['label' => 'X', 'capabilities' => ['member'], 'promotable' => true])->status, 'nur ohne Mitgliederbereich');
        $this->assertSame(400, $call('POST', '/admin/roles', ['label' => 'X', 'new_accounts' => 'ja'])->status);
        $this->assertSame(400, $call('PATCH', "/admin/roles/$friend", ['promotable' => true, 'capabilities' => ['member']])->status);

        $this->assertSame(200, $call('PATCH', "/admin/roles/$friend", ['new_accounts' => true, 'promotable' => true])->status);
        $leader = $app->roles()->create(['label' => 'Leitung', 'capabilities' => ['member', 'manage_events'], 'new_accounts' => true])['key'];
        $config = array_column($app->api()->config()['roles'], null, 'key');
        $this->assertSame([true, true, false], [$config[Roles::MEMBER]['new_accounts'], $config[$friend]['new_accounts'], $config[Roles::GUEST]['new_accounts']]);
        $this->assertSame([true, false], [$config[$friend]['promotable'], $config[$leader]['promotable']]);

        // Wer nur Mitglieder anlegen darf: freigegebene Rollen mit höchstens Mitgliederbereich bzw. Kalender, sonst nur
        // Rollen mit Rechten, die er weitergeben darf.
        $clerk = $users->add('schrift', $app->roles()->create(['label' => 'Schriftführer', 'capabilities' => ['member', 'create_users']])['key']);
        $users->current = $clerk;
        $res = $call('POST', '/users', ['name' => 'Fritz', 'level' => $friend]);
        $this->assertSame([201, $friend], [$res->status, $res->data['level'] ?? null], (string) json_encode($res->data));
        $this->assertSame(403, $call('POST', '/users', ['name' => 'Lena', 'level' => $leader])->status, 'Termine verwalten hat er selbst nicht');
        $this->assertSame(403, $call('POST', '/users/bulk', ['level' => $leader, 'people' => [['name' => 'Lena']]])->status);
        $users->current = $admin;
        $this->assertSame(201, $call('POST', '/users', ['name' => 'Lena', 'level' => $leader])->status, 'Admins dürfen alles');

        // Zum Mitglied machen: nur aus freigegebenen Rollen.
        $users->current = $clerk;
        $fritz = (int) $res->data['id'];
        $this->assertSame(200, $call('POST', "/members/$fritz/promote")->status);
        $this->assertSame(Roles::MEMBER, $users->find($fritz)?->role);
        $this->assertSame(400, $call('POST', "/members/$fritz/promote")->status, 'schon Mitglied');
        $users->current = $admin;
        $app->roles()->update($friend, ['promotable' => false]);
        $other = $users->add('freund', $friend);
        $users->current = $clerk;
        $this->assertSame(400, $call('POST', "/members/$other/promote")->status, 'nicht freigegeben');
    }

    public function testInternAloneOrIncludedInMemberArea(): void
    {
        $app = TestApp::create(TestApp::connection(), features: false);
        // Wie der Kalender auch ohne Mitgliederbereich (z. B. Anwärter, die „Intern“ sehen sollen)
        $key = $app->roles()->create(['label' => 'Anwärter mit Intern', 'capabilities' => ['calendar', 'view_intern']])['key'];
        $this->assertEqualsCanonicalizing([Capability::Calendar, Capability::ViewIntern], $app->roles()->capabilities($key));
        $only = $app->roles()->create(['label' => 'Nur Intern', 'capabilities' => ['view_intern']])['key'];
        $this->assertSame([Capability::ViewIntern], $app->roles()->capabilities($only));
        // Im Mitgliederbereich enthalten, nicht doppelt gespeichert; Mitglieder haben es fest.
        $app->roles()->update($key, ['capabilities' => ['member', 'view_intern']]);
        $this->assertSame(['member'], $app->roles()->find($key)['capabilities']);
        $this->assertContains(Capability::ViewIntern, $app->roles()->capabilities(Roles::MEMBER));
        $this->assertNotContains(Capability::ViewIntern, $app->roles()->capabilities(Roles::GUEST));
        $this->expectException(\KompanieApp\Core\Http\ApiError::class);
        $app->roles()->update($only, ['capabilities' => ['view_intern', 'manage_documents']]);
    }

    public function testCalendarAloneOrIncludedInMemberArea(): void
    {
        $app = TestApp::create(TestApp::connection(), features: false);
        $key = $app->roles()->create(['label' => 'Nur Kalender', 'capabilities' => ['calendar']])['key'];
        $this->assertSame(['calendar'], $app->roles()->find($key)['capabilities']);
        $app->roles()->update($key, ['capabilities' => ['calendar', 'member']]);
        $this->assertSame(['member'], $app->roles()->find($key)['capabilities'], 'Kalender im Mitgliederbereich enthalten, nicht doppelt gespeichert');
        $this->assertEqualsCanonicalizing([Capability::Member, Capability::Calendar, Capability::ViewIntern], $app->roles()->capabilities($key));
        $this->expectException(\KompanieApp\Core\Http\ApiError::class);
        $app->roles()->update($key, ['capabilities' => ['calendar', 'manage_events']]);
    }

    public function testCalendarOnlyRolesAreListedAndHandledLikeCandidates(): void
    {
        $users = new FakeUsers();
        $app = TestApp::create(TestApp::connection(), $users, features: false);
        $key = $app->roles()->create(['label' => 'Freunde', 'capabilities' => ['calendar']])['key'];
        $friend = $users->add('freund', $key);
        $cand = $users->add('anwaerter', TestApp::candidateRole($app));
        $users->add('gast', Roles::GUEST);
        $ids = static fn (array $rows): array => array_column($rows, 'id');
        $this->assertSame([], array_intersect([$friend, $cand], $ids($app->members()->list(false))));
        $this->assertEqualsCanonicalizing([$friend, $cand], $ids($app->members()->list(false, null, true)));
        $holder = $users->find($users->add('pflege', Roles::MEMBER));
        $this->assertNotNull($holder);
        $holder = new \KompanieApp\Core\Auth\User($holder->id, 'Pflege', 'pflege', 'x', [Capability::Member, Capability::Calendar, Capability::CreateUsers]);
        $this->assertArrayHasKey('password', $app->members()->resetPassword($holder, $friend));
    }
}
