<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Storage\Sql\Migrations;
use KompanieApp\Core\Storage\Sql\Migrator;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\MemorySettings;
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
        $this->assertSame(
            ['name' => 'Mitglied', 'first_name' => 'Mitglied', 'last_name' => '', 'login' => 'mitglied', 'email' => '', 'phone' => '', 'can_change_login' => true],
            $this->call('GET', "/members/{$this->m}/account")->data,
        );
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

        $this->assertSame(0, $this->users->passwordChanges, 'fremdes Konto: diese Sitzung bleibt, wie sie ist');
        $this->assertSame(200, $this->call('PATCH', "/members/{$this->admin}/account", ['username' => 'chef'])->status);
        $this->assertSame(1, $this->users->passwordChanges, 'eigener Benutzername: dieses Gerät bleibt angemeldet');
        $this->call('PATCH', "/members/{$this->admin}/account", ['name' => 'Chef']);
        $this->assertSame(1, $this->users->passwordChanges, 'ohne neuen Benutzernamen keine neue Sitzung');

        // Umgebung ohne änderbare Benutzernamen.
        $this->users->adminsAssignable = false;
        $this->assertSame(400, $this->call('PATCH', "/members/{$this->m}/account", ['username' => 'moritz'])->status);
        $this->assertSame(200, $this->call('PATCH', "/members/{$this->m}/account", ['name' => 'Moritz'])->status);
    }

    public function testOwnEmailAndPhone(): void
    {
        $this->users->current = $this->m;
        $this->assertSame(['email' => '', 'phone' => '', 'address' => null], $this->call('GET', '/me/account')->data);

        // Handynummer ohne Passwort; Leerzeichen werden vereinheitlicht.
        $res = $this->call('PATCH', '/me/account', ['email' => '', 'phone' => ' +49  171 1234567 ']);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertSame(['email' => '', 'phone' => '+49 171 1234567', 'address' => null], $res->data);
        $this->assertSame(400, $this->call('PATCH', '/me/account', ['phone' => 'abc 123'])->status);
        $this->assertSame(400, $this->call('PATCH', '/me/account', ['phone' => '12'])->status, 'zu wenige Ziffern');

        // Neue E-Mail-Adresse nur mit dem aktuellen Passwort.
        $this->assertSame(400, $this->call('PATCH', '/me/account', ['email' => 'neu@example.org', 'phone' => '+49 171 1234567'])->status);
        $this->assertSame(400, $this->call('PATCH', '/me/account', ['email' => 'neu@example.org', 'current_password' => 'falsch'])->status);
        $res = $this->call('PATCH', '/me/account', ['email' => 'neu@example.org', 'phone' => '', 'current_password' => 'geheim123']);
        $this->assertSame(['email' => 'neu@example.org', 'phone' => '', 'address' => null], $res->data);
        $this->assertSame(200, $this->call('PATCH', '/me/account', ['email' => 'Neu@Example.org'])->status, 'nur Groß-/Kleinschreibung');
        $this->assertSame(400, $this->call('PATCH', '/me/account', ['email' => 'kein-mail', 'current_password' => 'geheim123'])->status);

        // Name und Benutzername lassen sich hier nicht ändern; die Verwaltung sieht die Handynummer.
        $this->call('PATCH', '/me/account', ['phone' => '0171/1234567', 'name' => 'Anders', 'username' => 'anders']);
        $this->users->current = $this->admin;
        $p = $this->call('GET', "/members/{$this->m}/account")->data;
        $this->assertSame(['Mitglied', 'mitglied', '0171/1234567'], [$p['name'], $p['login'], $p['phone']]);

        // Eigenes Konto in der Verwaltung: Handynummer ohne, neue E-Mail-Adresse nur mit dem aktuellen Passwort.
        $res = $this->call('PATCH', "/members/{$this->admin}/account", ['phone' => '+49 151 7654321']);
        $this->assertSame([200, '+49 151 7654321'], [$res->status, $res->data['phone']]);
        $this->assertSame(400, $this->call('PATCH', "/members/{$this->admin}/account", ['email' => 'chef@example.org'])->status);
        $this->assertSame(400, $this->call('PATCH', "/members/{$this->admin}/account", ['email' => 'chef@example.org', 'current_password' => 'falsch'])->status);
        $res = $this->call('PATCH', "/members/{$this->admin}/account", ['email' => 'chef@example.org', 'current_password' => 'geheim123']);
        $this->assertSame([200, 'chef@example.org'], [$res->status, $res->data['email']]);
        $this->assertSame(200, $this->call('PATCH', "/members/{$this->m}/account", ['email' => 'm@example.org'])->status, 'fremdes Konto ohne Passwort');

        // Ohne Anmeldung nicht.
        $this->users->current = null;
        $this->assertSame(401, $this->call('GET', '/me/account')->status);
    }

    public function testOpenPenaltiesOfAllMembers(): void
    {
        $other = $this->users->add('anna', Roles::MEMBER);
        $first = $this->call('POST', '/penalties', ['user_ids' => [$this->m, $other], 'amount_cents' => 200, 'reason' => 'Zu spät', 'date' => '2026-05-01'])->data;
        $rows = $this->call('POST', '/penalties', ['user_ids' => [$this->m], 'amount_cents' => 350, 'reason' => 'Handy', 'date' => '2026-06-01'])->data;
        $paid = $this->call('POST', '/penalties', ['user_ids' => [$other], 'amount_cents' => 100, 'reason' => 'Bezahlt', 'date' => '2026-06-02'])->data;
        $this->call('PATCH', '/penalties/' . $paid[0]['id'], ['status' => 'paid']);
        $cancelled = $this->call('POST', '/penalties', ['user_ids' => [$other], 'amount_cents' => 100, 'reason' => 'Storniert'])->data;
        $this->call('DELETE', '/penalties/' . $cancelled[0]['id']);

        $open = $this->call('GET', '/penalties/open')->data;
        $this->assertSame([[$rows[0]['id'], 'Mitglied', 350], [$first[1]['id'], 'Anna', 200], [$first[0]['id'], 'Mitglied', 200]], array_map(
            static fn (array $p): array => [$p['id'], $p['user_name'], $p['amount_cents']],
            $open,
        ), 'nur offene, neueste zuerst, mit Namen');
        $this->assertSame('open', $open[0]['status']);

        $this->users->current = $this->m;
        $this->assertSame(403, $this->call('GET', '/penalties/open')->status, 'nur wer Strafen verwaltet');
    }

    public function testFirstAndLastName(): void
    {
        // Konten von früher (ein Namensfeld): das letzte Wort ist der Nachname.
        $this->users->users[$this->m]['name'] = 'Hans Peter Schulz';
        $p = $this->call('GET', "/members/{$this->m}/account")->data;
        $this->assertSame(['Hans Peter', 'Schulz'], [$p['first_name'], $p['last_name']]);
        $row = array_column($this->call('GET', '/members')->data, null, 'id')[$this->m];
        $this->assertSame(['Hans Peter Schulz', 'Hans Peter', 'Schulz'], [$row['name'], $row['first_name'], $row['last_name']]);

        // Getrennt geändert; der Anzeigename folgt.
        $res = $this->call('PATCH', "/members/{$this->m}/account", ['first_name' => ' Maria ', 'last_name' => 'von  Berg']);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertSame(['Maria von Berg', 'Maria', 'von Berg'], [$res->data['name'], $res->data['first_name'], $res->data['last_name']]);
        $detail = $this->call('GET', "/members/{$this->m}")->data;
        $this->assertSame(['Maria', 'von Berg'], [$detail['first_name'], $detail['last_name']]);

        // Nur ein Feld geschickt: das andere bleibt.
        $res = $this->call('PATCH', "/members/{$this->m}/account", ['first_name' => 'Marie']);
        $this->assertSame(['Marie von Berg', 'Marie', 'von Berg'], [$res->data['name'], $res->data['first_name'], $res->data['last_name']]);
        $res = $this->call('PATCH', "/members/{$this->m}/account", ['last_name' => 'Berg']);
        $this->assertSame(['Marie', 'Berg'], [$res->data['first_name'], $res->data['last_name']]);
        $this->call('PATCH', "/members/{$this->m}/account", ['first_name' => 'Maria', 'last_name' => 'von Berg']);

        // Nur der Vorname ist Pflicht; ohne Namensfelder bleibt der Name.
        $this->assertSame(400, $this->call('PATCH', "/members/{$this->m}/account", ['first_name' => ' ', 'last_name' => 'Berg'])->status);
        $res = $this->call('PATCH', "/members/{$this->m}/account", ['email' => 'maria@example.org']);
        $this->assertSame(['Maria', 'von Berg'], [$res->data['first_name'], $res->data['last_name']]);
        $res = $this->call('PATCH', "/members/{$this->m}/account", ['first_name' => 'Kassenwart', 'last_name' => '']);
        $this->assertSame(['Kassenwart', 'Kassenwart', ''], [$res->data['name'], $res->data['first_name'], $res->data['last_name']]);
        $this->assertSame(400, $this->call('PATCH', "/members/{$this->m}/account", ['first_name' => str_repeat('a', 60), 'last_name' => str_repeat('b', 60)])->status, 'zusammen zu lang');

        // Anlegen mit getrennten Feldern, einzeln und mehrere.
        $res = $this->call('POST', '/users', ['first_name' => 'Anna', 'last_name' => 'Alt']);
        $this->assertSame(201, $res->status, (string) json_encode($res->data));
        $this->assertSame(['Anna Alt', 'Anna', 'Alt', 'anna.alt'], [$res->data['name'], $res->data['first_name'], $res->data['last_name'], $res->data['username']]);
        $this->assertSame(400, $this->call('POST', '/users', ['first_name' => '', 'last_name' => 'Alt'])->status);
        $res = $this->call('POST', '/users/bulk', ['people' => [['first_name' => 'Ben', 'last_name' => 'Bär'], ['first_name' => '', 'last_name' => 'Ohne']]]);
        $this->assertSame(400, $res->status);
        $this->assertStringContainsString('Zeile 2 (Ohne): Bitte einen Vornamen angeben.', (string) $res->data['message']);
    }

    public function testGuards(): void
    {
        $creator = $this->users->add('anleger', Roles::MEMBER);
        $role = $this->call('POST', '/admin/roles', ['label' => 'Anleger', 'capabilities' => TestApp::caps(['member', 'create_users'])]);
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
        $this->call('PATCH', "/members/{$this->m}/account", ['phone' => '0171 1234567']);
        $this->users->setRole($this->m, Roles::ADMIN);
        $this->users->current = $this->m;
        $this->call('POST', '/points', ['user_ids' => [$other], 'points' => 3, 'reason' => 'Aufbau']);
        $this->users->current = $this->admin;
        $this->users->setRole($this->m, Roles::MEMBER);

        $this->assertSame(200, $this->call('DELETE', "/members/{$this->m}")->status);
        $this->assertNull($this->users->find($this->m));
        $this->assertSame([], $this->app->db->select('SELECT id FROM {points} WHERE user_id = ?', [$this->m]));
        $this->assertSame([], $this->app->db->select('SELECT id FROM {penalties} WHERE user_id = ?', [$this->m]));
        $this->assertSame([], $this->app->db->select('SELECT phone FROM {member_contacts} WHERE user_id = ?', [$this->m]));

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
        $res = $this->call('POST', '/users/bulk', ['people' => $people, 'level' => TestApp::candidateRole($this->app)]);
        $this->assertSame(201, $res->status, (string) json_encode($res->data));
        $this->assertSame(['anna.alt', 'ben', 'cara'], array_column($res->data, 'username'));
        $this->assertSame(array_fill(0, 3, TestApp::candidateRole($this->app)), array_column($res->data, 'level'));
        $this->assertTrue(array_reduce($res->data, static fn (bool $ok, array $c): bool => $ok && strlen((string) $c['password']) >= 12, true));

        // Zwei gleiche Namen ohne Benutzernamen: verschiedene Benutzernamen.
        $res = $this->call('POST', '/users/bulk', ['people' => [['name' => 'Dora'], ['name' => 'Dora']]]);
        $this->assertSame(['dora', 'dora2'], array_column($res->data, 'username'));
    }

    /** Datenbankversion 34 (SQLite und MySQL): Handynummern wie +491716438655 so schreiben, wie die App sie jetzt speichert. */
    public function testMigrationFormatsPhoneNumbers(): void
    {
        $cases = [
            '' => '',
            '+491716438655' => '+49 171 6438655',
            '+4915123456789' => '+49 1512 3456789',
            '+4916012345678' => '+49 160 12345678',
            '+49284112345' => '+49 284112345',
            '+436641234567' => '+43 6641234567',
            '+352621123456' => '+352 621123456',
            '+420601123456' => '+420 601123456',
            '+31612345678' => '+31 612345678',
            // Unbekannte Ländervorwahl, schon mit Leerzeichen oder ohne +: unverändert
            '+12125550100' => '+12125550100',
            '+49 171 6438655' => '+49 171 6438655',
            '0171 1234567' => '0171 1234567',
        ];
        foreach (['sqlite', getenv('KOMPANIE_TEST_MYSQL') ? 'mysql' : null] as $driver) {
            if ($driver === null) {
                continue;
            }
            $db = TestApp::connection($driver);
            try {
                $settings = new MemorySettings();
                (new Migrator($db, $settings, array_filter(Migrations::all(), static fn (int $v): bool => $v < 34, ARRAY_FILTER_USE_KEY)))->migrate();
                $id = 1;
                foreach (array_keys($cases) as $phone) {
                    $db->insert('member_contacts', ['user_id' => $id++, 'phone' => (string) $phone, 'updated_at' => '2026-01-01 00:00:00']);
                }
                (new Migrator($db, $settings))->migrate();
                $this->assertSame(Migrations::latest(), (new Migrator($db, $settings))->current(), $driver);
                $this->assertSame(
                    array_values($cases),
                    array_map('strval', array_column($db->select('SELECT phone FROM {member_contacts} ORDER BY user_id'), 'phone')),
                    $driver,
                );
            } finally {
                TestApp::dropTables($db);
            }
        }
    }
}
