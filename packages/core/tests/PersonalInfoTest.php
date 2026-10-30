<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\Address;
use KompanieApp\Core\Domain\RoleService;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Storage\Sql\Migrations;
use KompanieApp\Core\Storage\Sql\Migrator;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\MemorySettings;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * Persönliche Angaben: Adresse (Domain\Address), Handynummer, E-Mail und Geburtsdatum anderer nur mit view_personal,
 * ändern mit manage_personal; die eigenen unter Meins → Konto; beim Anlegen alles eintragen (create_users).
 */
final class PersonalInfoTest extends TestCase
{
    private const ADDRESS = ['street' => 'Musterstraße', 'house_number' => '1', 'extra' => '', 'postal_code' => '12345', 'city' => 'Musterstadt', 'country' => 'DE', 'country_name' => ''];

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

    /** Konto mit einer eigenen Rolle aus diesen Rechten (dazu, was Mitglieder sehen). */
    private function withRights(string $login, array $caps): int
    {
        $key = $this->app->roles()->create(['label' => 'Rolle ' . $login, 'capabilities' => [...Roles::MEMBER_DEFAULTS, ...$caps]])['key'];
        return $this->users->add($login, $key);
    }

    public function testOwnAddress(): void
    {
        $this->users->current = $this->m;
        $this->assertSame(['email' => '', 'phone' => '', 'address' => null], $this->call('GET', '/me/account')->data);

        $res = $this->call('PATCH', '/me/account', ['phone' => '+49 171 1234567', 'address' => ['street' => ' Hauptstraße  5a ', 'postal_code' => 'D-01067', 'city' => 'Dresden', 'country' => 'DE']]);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertSame(['street' => 'Hauptstraße', 'house_number' => '5a', 'extra' => '', 'postal_code' => '01067', 'city' => 'Dresden', 'country' => 'DE', 'country_name' => ''], $res->data['address'], 'Hausnummer in ihr Feld, PLZ ohne D-');

        // Handynummer entfernen lässt die Adresse stehen und umgekehrt.
        $this->assertSame('Hauptstraße', $this->call('PATCH', '/me/account', ['phone' => ''])->data['address']['street']);
        $res = $this->call('PATCH', '/me/account', ['phone' => '+49 171 1234567', 'address' => null]);
        $this->assertSame(['+49 171 1234567', null], [$res->data['phone'], $res->data['address']]);
        $this->call('PATCH', '/me/account', ['phone' => '']);
        $this->assertSame([], $this->app->db->select('SELECT user_id FROM {member_contacts}'), 'leer: keine Zeile');

        // Ungültiges ändert nichts, auch die Handynummer daneben nicht.
        $res = $this->call('PATCH', '/me/account', ['phone' => '+49 171 7654321', 'address' => ['street' => 'Weg', 'postal_code' => '1234', 'city' => 'Ort']]);
        $this->assertSame(400, $res->status);
        $this->assertStringContainsString('Deutschland', (string) $res->data['message']);
        $this->assertSame('', $this->call('GET', '/me/account')->data['phone']);
        $this->assertSame(200, $this->call('PATCH', '/me/account', ['address' => self::ADDRESS])->status, 'Adresse ohne Passwort');
    }

    /** @return iterable<string, array{0: array<string, string>, 1: string|null}> Eingabe, gespeicherte PLZ (null: 400) */
    public static function addresses(): iterable
    {
        $a = static fn (string $country, string $postal, array $more = []): array => $more + ['street' => 'Weg', 'postal_code' => $postal, 'city' => 'Ort', 'country' => $country];
        yield 'DE' => [$a('DE', '12345'), '12345'];
        yield 'DE Leitregion 00' => [$a('DE', '00123'), null];
        yield 'DE vier Ziffern' => [$a('DE', '1234'), null];
        yield 'AT' => [$a('AT', '1010'), '1010'];
        yield 'AT mit 0' => [$a('AT', '0101'), null];
        yield 'CH mit CH-' => [$a('CH', 'CH-8001'), '8001'];
        yield 'NL zusammengeschrieben' => [$a('NL', '1234ab'), '1234 AB'];
        yield 'NL ohne Buchstaben' => [$a('NL', '1234'), null];
        yield 'LU mit L-' => [$a('LU', 'L-1234'), '1234'];
        yield 'DK' => [$a('DK', '0800'), '0800'];
        yield 'PL' => [$a('PL', '00950'), '00-950'];
        yield 'CZ' => [$a('CZ', '11000'), '110 00'];
        yield 'CZ erste Ziffer' => [$a('CZ', '91000'), null];
        yield 'ES Provinz 52' => [$a('ES', '52001'), '52001'];
        yield 'ES Provinz 53' => [$a('ES', '53001'), null];
        yield 'TR Provinz 81' => [$a('TR', '81000'), '81000'];
        yield 'TR Provinz 82' => [$a('TR', '82000'), null];
        yield 'GB' => [$a('GB', 'sw1a1aa'), 'SW1A 1AA'];
        yield 'GB nur Ziffern' => [$a('GB', '12345'), null];
        yield 'anderes Land' => [$a('', '10001', ['country_name' => 'USA']), '10001'];
        yield 'anderes Land ohne Namen' => [$a('', '10001'), null];
        yield 'unbekanntes Land' => [$a('XX', '12345'), null];
        yield 'ohne Straße' => [['postal_code' => '12345', 'city' => 'Ort'], null];
        yield 'ohne Ort' => [['street' => 'Weg', 'postal_code' => '12345'], null];
        yield 'ohne PLZ' => [['street' => 'Weg', 'city' => 'Ort'], null];
        yield 'Straße mit unerlaubtem Zeichen' => [$a('DE', '12345', ['street' => 'Weg; 1=1']), null];
        yield 'Straße mit HTML (Tags fallen weg)' => [$a('DE', '12345', ['street' => 'Weg <b>']), '12345'];
        yield 'Hausnummer ungültig' => [$a('DE', '12345', ['house_number' => 'a1']), null];
        yield 'Hausnummer Bereich' => [$a('DE', '12345', ['house_number' => '12 - 14']), '12345'];
        yield 'Ort mit Ziffern' => [$a('FR', '75008', ['city' => 'Paris Cedex 08']), '75008'];
    }

    /**
     * @dataProvider addresses
     * @param array<string, string> $in
     */
    public function testAddressRules(array $in, ?string $postal): void
    {
        if ($postal === null) {
            $this->expectException(ApiError::class);
        }
        $this->assertSame($postal, Address::parse($in)['postal_code'] ?? null);
    }

    public function testWithoutCountryDefaultsToGermany(): void
    {
        $this->assertSame('DE', Address::parse(['street' => 'Weg', 'postal_code' => '12345', 'city' => 'Ort'])['country'] ?? null);
        $this->assertNull(Address::parse(['street' => ' ', 'country' => 'AT']), 'nur das Land: keine Adresse');
        $this->assertSame('12-14', Address::parse(['street' => 'Weg', 'house_number' => '12 - 14', 'postal_code' => '12345', 'city' => 'Ort'])['house_number'] ?? null);
    }

    public function testOnlyWithRightsForOthers(): void
    {
        $this->users->current = $this->m;
        $this->call('PATCH', '/me/account', ['phone' => '+49 171 1234567', 'address' => self::ADDRESS]);
        $this->app->db->insert('birthdays', ['user_id' => $this->m, 'month' => 2, 'day' => 3, 'year' => 1980, 'visible' => 1, 'show_age' => 0, 'updated_by' => $this->m, 'updated_at' => '2026-01-01 00:00:00']);

        // Nur Mitglieder anlegen: Name, Benutzername, E-Mail; nichts Persönliches darüber hinaus.
        $this->users->current = $this->withRights('anleger', ['create_users']);
        $detail = $this->call('GET', "/members/{$this->m}")->data;
        $this->assertArrayNotHasKey('personal', $detail);
        $this->assertArrayNotHasKey('birthday', $detail);
        $res = $this->call('GET', "/members/{$this->m}/account");
        $this->assertSame('', $res->data['phone'] ?? null, (string) json_encode($res->data));
        $this->assertSame(403, $this->call('PUT', "/members/{$this->m}/personal", ['phone' => ''])->status);
        $this->assertSame(403, $this->call('PATCH', "/members/{$this->m}/account", ['phone' => ''])->status);
        $this->assertSame(403, $this->call('PUT', "/members/{$this->m}/birthday", ['month' => 1, 'day' => 1])->status);
        $this->assertSame(200, $this->call('PATCH', "/members/{$this->m}/account", ['email' => 'neu@example.org'])->status, 'E-Mail für die Anmeldung bleibt');

        // Sehen: alles, aber nichts ändern.
        $this->users->current = $this->withRights('leser', ['view_personal']);
        $detail = $this->call('GET', "/members/{$this->m}")->data;
        $this->assertSame(['email' => 'neu@example.org', 'phone' => '+49 171 1234567', 'address' => self::ADDRESS], $detail['personal']);
        $this->assertSame(1980, $detail['birthday']['year']);
        $this->assertSame(403, $this->call('PUT', "/members/{$this->m}/personal", ['phone' => ''])->status);

        // Pflegen: auch beim Admin; dessen E-Mail aber nur mit den Grenzen der Kontoverwaltung.
        $this->users->current = $this->withRights('pfleger', ['view_personal', 'manage_personal']);
        $res = $this->call('PUT', "/members/{$this->admin}/personal", ['phone' => '+49 151 7654321', 'address' => self::ADDRESS]);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertSame(['+49 151 7654321', 'Musterstadt'], [$res->data['phone'], $res->data['address']['city']]);
        $this->assertSame(403, $this->call('PUT', "/members/{$this->admin}/personal", ['email' => 'chef@example.org'])->status);
        $res = $this->call('PUT', "/members/{$this->m}/personal", ['email' => 'mitglied@example.org', 'address' => null]);
        $this->assertSame(['mitglied@example.org', null], [$res->data['email'], $res->data['address']]);
        $this->assertSame(400, $this->call('PUT', "/members/{$this->m}/personal", ['address' => ['street' => 'Weg']])->status);
        $this->assertSame(200, $this->call('PUT', "/members/{$this->m}/birthday", ['month' => 4, 'day' => 5])->status);
    }

    public function testRightsNeedEachOther(): void
    {
        $roles = $this->app->roles();
        $this->expectException(ApiError::class);
        $this->expectExceptionMessage('nur zusammen mit „Persönliche Angaben sehen“');
        $roles->create(['label' => 'Nur pflegen', 'capabilities' => ['member', 'manage_personal']]);
    }

    public function testCreateWithAllPersonalData(): void
    {
        $this->users->current = $this->withRights('anleger', ['create_users']);
        $res = $this->call('POST', '/users', ['first_name' => 'Erika', 'phone' => '+49 171 1234567', 'address' => self::ADDRESS]);
        $this->assertSame(201, $res->status, (string) json_encode($res->data));
        $this->users->current = $this->admin;
        $this->assertSame(['email' => '', 'phone' => '+49 171 1234567', 'address' => self::ADDRESS], $this->app->members()->personal((int) $res->data['id']));

        // Ungültige Adresse: niemand angelegt.
        $count = count($this->users->users);
        $res = $this->call('POST', '/users/bulk', ['people' => [['name' => 'Anna'], ['name' => 'Ben', 'address' => ['street' => 'Weg', 'postal_code' => 'x', 'city' => 'Ort']]]]);
        $this->assertSame(400, $res->status);
        $this->assertStringContainsString('Zeile 2 (Ben)', (string) $res->data['message']);
        $this->assertCount($count, $this->users->users);
        $res = $this->call('POST', '/users', ['first_name' => 'Cara', 'phone' => 'abc']);
        $this->assertSame(400, $res->status);
        $this->assertCount($count, $this->users->users);
    }

    public function testImportWithPhoneAndAddress(): void
    {
        $csv = "Vorname;Nachname;Handynummer;Straße;Hausnummer;PLZ;Ort;Land\nErika;Muster;+49 171 1234567;Musterstraße;1;12345;Musterstadt;\nMax;Beispiel;;Hauptplatz;2;1010;Wien;Österreich\nOtto;Weit;;Main St;5;10001;New York;USA";
        $res = $this->call('POST', '/members/import', ['csv' => $csv, 'apply' => true]);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertSame(['phone', 'street', 'house_number', 'postal_code', 'city', 'country'], array_values(array_diff($res->data['columns']['used'], ['first_name', 'last_name'])));
        $ids = array_column($res->data['created'], 'id', 'name');
        $this->assertSame(self::ADDRESS, $this->app->members()->personal((int) $ids['Erika Muster'])['address']);
        $this->assertSame('AT', $this->app->members()->personal((int) $ids['Max Beispiel'])['address']['country'] ?? null);
        $this->assertSame(['', 'USA'], array_values(array_intersect_key($this->app->members()->personal((int) $ids['Otto Weit'])['address'] ?? [], ['country' => 1, 'country_name' => 1])));

        $res = $this->call('POST', '/members/import', ['csv' => "Vorname;PLZ;Ort;Straße\nFalsch;123;Ort;Weg", 'apply' => false]);
        $this->assertStringContainsString('Postleitzahl', (string) ($res->data['rows'][0]['error'] ?? ''));
    }

    /** Nach dem Update: Wer Mitglieder anlegt, sieht und pflegt die persönlichen Angaben weiter (migratePersonalRights). */
    public function testRolesWithCreateUsersKeepAccess(): void
    {
        $db = TestApp::connection();
        $settings = new MemorySettings();
        $users = new FakeUsers();
        TestApp::create($db, $users, settings: $settings, features: false);
        $a = $users->add('a', 'r1');
        $b = $users->add('b', Roles::MEMBER);
        $c = $users->add('c', 'r1');
        $settings->set(RoleService::SETTING, [
            ['key' => 'guest', 'label' => 'Gast', 'capabilities' => []],
            ['key' => 'member', 'label' => 'Mitglied', 'capabilities' => ['member']],
            ['key' => 'r1', 'label' => 'Schriftführer', 'capabilities' => ['member', 'create_users']],
            ['key' => 'r2', 'label' => 'Kasse', 'capabilities' => ['member', 'manage_penalties']],
            ['key' => 'admin', 'label' => 'Admin', 'capabilities' => []],
        ]);
        $settings->set(RoleService::OVERRIDES_SETTING, [
            ['user' => $b, 'grant' => ['create_users'], 'revoke' => []],
            ['user' => $c, 'grant' => [], 'revoke' => ['create_users']],
        ]);
        $settings->delete(RoleService::PERSONAL_SETTING);

        $app = TestApp::create($db, $users, settings: $settings, features: false);
        $this->assertTrue($settings->get(RoleService::PERSONAL_SETTING));
        $has = static fn (int $id, string $cap): bool => in_array($cap, array_map(static fn ($x): string => $x->value, $users->find($id)?->capabilities ?? []), true);
        $this->assertTrue($has($a, 'view_personal') && $has($a, 'manage_personal'), 'Rolle mit create_users');
        $this->assertTrue($has($b, 'view_personal') && $has($b, 'manage_personal'), 'zusätzlich gegeben');
        $this->assertFalse($has($c, 'view_personal') || $has($c, 'manage_personal'), 'entzogen');
        $this->assertNotContains('view_personal', $app->roles()->all()['r2']['capabilities']);
    }

    /** Datenbankversion 35: Spalten der Adresse; vorhandene Handynummern bleiben. */
    public function testMigrationAddsAddressColumns(): void
    {
        $db = TestApp::connection();
        try {
            $settings = new MemorySettings();
            (new Migrator($db, $settings, array_filter(Migrations::all(), static fn (int $v): bool => $v < 35, ARRAY_FILTER_USE_KEY)))->migrate();
            $db->insert('member_contacts', ['user_id' => 7, 'phone' => '+49 171 1234567', 'updated_at' => '2026-01-01 00:00:00']);
            (new Migrator($db, $settings))->migrate();
            $this->assertSame(Migrations::latest(), (new Migrator($db, $settings))->current());
            $row = $db->selectOne('SELECT * FROM {member_contacts} WHERE user_id = 7');
            $this->assertSame(['+49 171 1234567', '', ''], [$row['phone'] ?? null, $row['street'] ?? null, $row['country'] ?? null]);
        } finally {
            TestApp::dropTables($db);
        }
    }
}
