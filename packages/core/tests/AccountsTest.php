<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** Konten verwalten (Issue #24): Angaben ändern, löschen mit allen eigenen Daten, „eingetragen von“. */
final class AccountsTest extends TestCase
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

    private function call(string $method, string $path, array $body = []): Response
    {
        return $this->app->api()->handle(new Request($method, $path, [], $body));
    }

    public function testEditNameEmailAndLogin(): void
    {
        $this->assertSame(['name' => 'Mitglied', 'login' => 'mitglied', 'email' => '', 'can_change_login' => true], $this->call('GET', "/members/{$this->m}/account")->data);
        $res = $this->call('PATCH', "/members/{$this->m}/account", ['name' => 'Max Muster', 'email' => 'max@example.org', 'username' => 'max']);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertSame(['Max Muster', 'max', 'max@example.org'], [$res->data['name'], $res->data['login'], $res->data['email']]);

        $this->assertSame(400, $this->call('PATCH', "/members/{$this->m}/account", ['name' => ' '])->status);
        $this->assertSame(400, $this->call('PATCH', "/members/{$this->m}/account", ['email' => 'kein-mail'])->status);
        $this->assertSame(400, $this->call('PATCH', "/members/{$this->m}/account", ['username' => 'admin'])->status, 'vergeben');
        $this->assertSame(400, $this->call('PATCH', "/members/{$this->m}/account", ['username' => 'mit leerzeichen'])->status);
        $this->assertSame(200, $this->call('PATCH', "/members/{$this->m}/account", ['username' => 'MAX'])->status, 'nur Groß-/Kleinschreibung');
        $this->assertSame(200, $this->call('PATCH', "/members/{$this->m}/account", ['email' => 'Max@Example.org'])->status, 'E-Mail nur Groß-/Kleinschreibung');
        $this->assertSame(400, $this->call('PATCH', "/members/{$this->m}/account", ['email' => str_repeat('a', 95) . '@example.org'])->status, 'zu lang für WordPress');

        // WordPress: Benutzernamen fest.
        $this->users->adminsAssignable = false;
        $this->assertSame(400, $this->call('PATCH', "/members/{$this->m}/account", ['username' => 'moritz'])->status);
        $this->assertSame(200, $this->call('PATCH', "/members/{$this->m}/account", ['name' => 'Moritz'])->status);
    }

    public function testGuards(): void
    {
        $creator = $this->users->add('anleger', Roles::MEMBER);
        $role = $this->call('POST', '/admin/roles', ['label' => 'Anleger', 'capabilities' => ['member', 'create_users']]);
        $this->assertSame(201, $role->status);
        $this->users->setRole($creator, (string) array_column($this->call('GET', '/admin/roles')->data, 'key', 'label')['Anleger']);
        $guest = $this->users->add('gast', Roles::GUEST);

        $this->users->current = $this->m;
        $this->assertSame(403, $this->call('PATCH', "/members/{$this->admin}/account", ['name' => 'X'])->status, 'ohne Recht');
        $this->assertSame(403, $this->call('DELETE', "/members/{$this->admin}")->status);

        $this->users->current = $creator;
        $this->assertSame(200, $this->call('PATCH', "/members/{$this->m}/account", ['name' => 'Neu'])->status);
        $this->assertSame(403, $this->call('PATCH', "/members/{$this->admin}/account", ['name' => 'X'])->status, 'Admin nur durch Admins');
        $this->assertSame(403, $this->call('DELETE', "/members/{$this->admin}")->status);
        $this->assertSame(403, $this->call('DELETE', "/members/$guest")->status, 'Gäste nur durch Admins');
        $this->assertSame(400, $this->call('DELETE', "/members/$creator")->status, 'nicht sich selbst');

        $this->users->current = $this->admin;
        $this->assertSame(400, $this->call('DELETE', "/members/{$this->admin}")->status);
        $this->assertSame(404, $this->call('DELETE', '/members/999')->status);
    }

    public function testDeleteRemovesOwnDataButKeepsEntriesForOthers(): void
    {
        $other = $this->users->add('anna', Roles::MEMBER);
        // Mitglied bekommt Punkte und Strafe; Anna bekommt Punkte, die das Mitglied (als Admin-Vertretung) einträgt.
        $this->call('POST', '/points', ['user_ids' => [$this->m], 'points' => 5, 'reason' => 'Fest']);
        $this->call('POST', '/penalties', ['user_ids' => [$this->m], 'amount_cents' => 200, 'reason' => 'Zu spät']);
        $this->users->setRole($this->m, Roles::ADMIN);
        $this->users->current = $this->m;
        $this->call('POST', '/points', ['user_ids' => [$other], 'points' => 3, 'reason' => 'Aufbau']);
        $this->users->current = $this->admin;
        $this->users->setRole($this->m, Roles::MEMBER);

        $this->assertSame(200, $this->call('DELETE', "/members/{$this->m}")->status);
        $this->assertNull($this->users->find($this->m));
        $this->assertSame([], $this->app->db->select('SELECT id FROM {points} WHERE user_id = ?', [$this->m]));
        $this->assertSame([], $this->app->db->select('SELECT id FROM {penalties} WHERE user_id = ?', [$this->m]));

        // Nächste Anfrage (eigene App wie im Betrieb; die Namen werden je Anfrage zwischengespeichert).
        $this->users->current = $other;
        $next = TestApp::create($this->app->db, $this->users);
        $points = $next->api()->handle(new Request('GET', '/me/points', [], []))->data;
        $this->assertSame('gelöschtes Konto', $points['entries'][0]['created_by_name'] ?? $points[0]['created_by_name'] ?? null);
    }

    public function testBulkCreateChecksEverythingFirst(): void
    {
        $this->users->add('maxm', Roles::MEMBER);
        $people = [['name' => 'Anna Alt'], ['name' => 'Ben Bär', 'username' => 'ben', 'email' => 'ben@example.org'], ['name' => 'Cara', 'username' => 'ben']];
        $res = $this->call('POST', '/users/bulk', ['people' => $people]);
        $this->assertSame(400, $res->status);
        $this->assertStringContainsString('Zeile 3 (Cara)', (string) $res->data['message']);
        $this->assertCount(3, $this->users->users, 'keiner angelegt');

        $this->assertSame(400, $this->call('POST', '/users/bulk', ['people' => [['name' => 'A', 'email' => 'x@example.org'], ['name' => 'B', 'email' => 'X@example.org']]])->status, 'E-Mail doppelt');
        $this->assertSame(400, $this->call('POST', '/users/bulk', ['people' => []])->status);
        $this->assertSame(400, $this->call('POST', '/users/bulk', ['people' => array_fill(0, 51, ['name' => 'X'])])->status);

        $people[2]['username'] = 'cara';
        $res = $this->call('POST', '/users/bulk', ['people' => $people, 'level' => 'candidate']);
        $this->assertSame(201, $res->status, (string) json_encode($res->data));
        $this->assertSame(['anna.alt', 'ben', 'cara'], array_column($res->data, 'username'));
        $this->assertSame(['candidate', 'candidate', 'candidate'], array_column($res->data, 'level'));
        $this->assertTrue(array_reduce($res->data, static fn (bool $ok, array $c): bool => $ok && strlen((string) $c['password']) >= 12, true));

        // Zwei gleiche Namen ohne Benutzernamen: verschiedene Benutzernamen.
        $res = $this->call('POST', '/users/bulk', ['people' => [['name' => 'Dora'], ['name' => 'Dora']]]);
        $this->assertSame(['dora', 'dora2'], array_column($res->data, 'username'));
    }
}
