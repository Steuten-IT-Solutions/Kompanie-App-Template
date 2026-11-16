<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Storage\Backup;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * Passive (Bereich passive): eigene Einträge ohne Konto; Liste und Pflege mit manage_passive, persönliche Angaben nur mit
 * view_personal bzw. manage_personal; Betreuer sehen unter Meins alle Angaben ihrer eigenen Passiven, nur lesend.
 */
final class PassiveMembersTest extends TestCase
{
    private const ADDRESS = ['street' => 'Musterstraße', 'house_number' => '1', 'extra' => '', 'postal_code' => '12345', 'city' => 'Musterstadt', 'country' => 'DE', 'country_name' => ''];

    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $member;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->app = TestApp::create(TestApp::connection(), $this->users, clock: new FixedClock(new \DateTimeImmutable('2026-11-16 12:00:00', new \DateTimeZone('Europe/Berlin'))));
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->member = $this->users->add('mitglied', Roles::MEMBER);
        $this->users->current = $this->admin;
    }

    private function call(string $method, string $path, array $body = []): Response
    {
        return $this->app->api()->handle(new Request($method, $path, [], $body));
    }

    /** Konto mit einer eigenen Rolle aus diesen Rechten (dazu, was Mitglieder sehen). */
    private function withRights(string $login, array $caps): int
    {
        $key = $this->app->roles()->create(['label' => 'Rolle ' . $login, 'capabilities' => [...Roles::TEMPLATES[Roles::MEMBER], ...$caps]])['key'];
        return $this->users->add($login, $key);
    }

    /** @param array<string, mixed> $extra */
    private function passive(string $first, string $last, array $extra = []): int
    {
        $res = $this->call('POST', '/passive-members', ['first_name' => $first, 'last_name' => $last] + $extra);
        $this->assertSame(201, $res->status, (string) json_encode($res->data));
        return $res->data['id'];
    }

    public function testCreateListUpdateDelete(): void
    {
        $id = $this->passive(' Erika ', ' Mustermann ', [
            'address' => self::ADDRESS, 'phone' => '+49 2151 123456', 'email' => 'erika@example.org', 'birth_date' => '1950-03-12', 'caretaker_id' => $this->member,
        ]);
        $this->passive('Anton', 'Abel');

        $list = $this->call('GET', '/passive-members')->data;
        $this->assertSame(['Abel', 'Mustermann'], array_column($list['passives'], 'last_name'), 'nach Nachnamen');
        $erika = $list['passives'][1];
        $this->assertSame('Erika Mustermann', $erika['name']);
        $this->assertSame(self::ADDRESS, $erika['address']);
        $this->assertSame('+49 2151 123456', $erika['phone']);
        $this->assertSame('erika@example.org', $erika['email']);
        $this->assertSame('1950-03-12', $erika['birth_date']);
        $this->assertSame(['id' => $this->member, 'name' => 'Mitglied'], $erika['caretaker']);
        $this->assertTrue($list['personal'] && $list['manage_personal']);
        $this->assertContains($this->member, array_column($list['caretakers'], 'id'));

        $res = $this->call('PATCH', "/passive-members/$id", ['phone' => '', 'address' => null, 'caretaker_id' => null]);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertSame('', $res->data['phone']);
        $this->assertNull($res->data['address']);
        $this->assertNull($res->data['caretaker']);
        $this->assertSame('erika@example.org', $res->data['email'], 'nicht mitgeschickt: bleibt');

        $this->assertSame(200, $this->call('DELETE', "/passive-members/$id")->status);
        $this->assertSame(404, $this->call('GET', "/passive-members/$id")->status);
        $this->assertCount(1, $this->call('GET', '/passive-members')->data['passives']);
    }

    public function testPassivesAreNoAccounts(): void
    {
        $before = count($this->users->list(true));
        $this->passive('Erika', 'Mustermann', ['email' => 'erika@example.org']);
        $this->assertCount($before, $this->users->list(true), 'kein Konto, also kein Zugang');
        $this->assertNotContains('Erika Mustermann', array_column($this->call('GET', '/members')->data, 'name'));
        $this->assertNotContains('Erika Mustermann', array_column($this->call('GET', '/directory')->data, 'name'));
    }

    public function testValidation(): void
    {
        $this->assertSame(400, $this->call('POST', '/passive-members', ['first_name' => 'Erika'])->status, 'Nachname fehlt');
        $this->assertSame(400, $this->call('POST', '/passive-members', ['last_name' => 'Mustermann'])->status, 'Vorname fehlt');
        $ok = ['first_name' => 'Erika', 'last_name' => 'Mustermann'];
        $this->assertSame(400, $this->call('POST', '/passive-members', $ok + ['email' => 'keine-adresse'])->status);
        $this->assertSame(400, $this->call('POST', '/passive-members', $ok + ['phone' => 'abc'])->status);
        $this->assertSame(400, $this->call('POST', '/passive-members', $ok + ['birth_date' => '2027-01-01'])->status, 'Zukunft');
        $this->assertSame(400, $this->call('POST', '/passive-members', $ok + ['birth_date' => '1950-02-30'])->status);
        $this->assertSame(400, $this->call('POST', '/passive-members', $ok + ['address' => ['street' => 'Musterstraße']])->status);
        $guest = $this->users->add('gast', Roles::GUEST);
        $this->assertSame(400, $this->call('POST', '/passive-members', $ok + ['caretaker_id' => $guest])->status, 'Betreuer nur Mitglieder');
        $this->assertSame(400, $this->call('POST', '/passive-members', $ok + ['caretaker_id' => 99999])->status);
        $this->assertSame([], $this->call('GET', '/passive-members')->data['passives']);
    }

    public function testRights(): void
    {
        $id = $this->passive('Erika', 'Mustermann', ['email' => 'erika@example.org', 'birth_date' => '1950-03-12']);

        $this->users->current = $this->member;
        $this->assertSame(403, $this->call('GET', '/passive-members')->status, 'ohne manage_passive');
        $this->assertSame(403, $this->call('POST', '/passive-members', ['first_name' => 'A', 'last_name' => 'B'])->status);

        // Nur manage_passive: Namen und Betreuer, aber keine persönlichen Angaben
        $this->users->current = $this->withRights('kassierer', ['manage_passive']);
        $list = $this->call('GET', '/passive-members')->data;
        $this->assertFalse($list['personal']);
        $this->assertArrayNotHasKey('email', $list['passives'][0]);
        $this->assertArrayNotHasKey('birth_date', $list['passives'][0]);
        $this->assertSame(200, $this->call('PATCH', "/passive-members/$id", ['first_name' => 'Erika Maria', 'caretaker_id' => $this->member])->status);
        $this->assertSame(403, $this->call('PATCH', "/passive-members/$id", ['email' => 'neu@example.org'])->status, 'E-Mail nur mit manage_personal');
        $this->assertSame(403, $this->call('POST', '/passive-members', ['first_name' => 'A', 'last_name' => 'B', 'phone' => '+49 171 1234567'])->status);
        $this->assertSame(201, $this->call('POST', '/passive-members', ['first_name' => 'A', 'last_name' => 'B'])->status);

        // Mit view_personal sehen, aber nicht ändern
        $this->users->current = $this->withRights('schriftfuehrer', ['manage_passive', 'view_personal']);
        $list = $this->call('GET', '/passive-members')->data;
        $this->assertTrue($list['personal']);
        $this->assertFalse($list['manage_personal']);
        $this->assertSame('erika@example.org', array_column($list['passives'], 'email', 'last_name')['Mustermann']);
        $this->assertSame(403, $this->call('PATCH', "/passive-members/$id", ['birth_date' => '1951-01-01'])->status);

        // Mit manage_personal auch ändern
        $this->users->current = $this->withRights('pfleger', ['manage_passive', 'view_personal', 'manage_personal']);
        $this->assertSame('1951-01-01', $this->call('PATCH', "/passive-members/$id", ['birth_date' => '1951-01-01'])->data['birth_date']);
    }

    public function testCaretakerSeesOwnPassivesReadOnly(): void
    {
        $other = $this->users->add('anderer', Roles::MEMBER);
        $this->passive('Erika', 'Mustermann', ['address' => self::ADDRESS, 'phone' => '+49 2151 123456', 'email' => 'erika@example.org', 'birth_date' => '1950-03-12', 'caretaker_id' => $this->member]);
        $this->passive('Otto', 'Fremd', ['email' => 'otto@example.org', 'caretaker_id' => $other]);
        $this->passive('Ohne', 'Betreuer');

        $this->users->current = $this->member;
        $this->assertSame(1, $this->call('GET', '/config')->data['caredPassives']);
        $mine = $this->call('GET', '/me/passive-members');
        $this->assertSame(200, $mine->status);
        $this->assertSame(['Mustermann'], array_column($mine->data, 'last_name'), 'nur die eigenen');
        $this->assertSame('erika@example.org', $mine->data[0]['email'], 'alle Angaben, auch ohne view_personal');
        $this->assertSame(self::ADDRESS, $mine->data[0]['address']);
        $this->assertSame('1950-03-12', $mine->data[0]['birth_date']);
        $id = $mine->data[0]['id'];
        $this->assertSame(403, $this->call('PATCH', "/passive-members/$id", ['email' => 'x@example.org'])->status, 'nur lesen');
        $this->assertSame(403, $this->call('GET', "/passive-members/$id")->status);

        $this->users->current = $this->admin;
        $this->assertSame(0, $this->call('GET', '/config')->data['caredPassives']);
        $this->assertSame([], $this->call('GET', '/me/passive-members')->data);
    }

    public function testFeatureOffAndCaretakerDeleted(): void
    {
        $this->passive('Erika', 'Mustermann', ['caretaker_id' => $this->member]);
        $this->call('DELETE', "/members/{$this->member}");
        $this->assertNull($this->call('GET', '/passive-members')->data['passives'][0]['caretaker'], 'Betreuer gelöscht: Passiver bleibt ohne');

        $this->app->features()->update(['passive' => false]);
        $res = $this->call('GET', '/passive-members');
        $this->assertSame(403, $res->status);
        $this->assertSame('feature_disabled', $res->data['code']);
        $this->assertSame(0, $this->call('GET', '/config')->data['caredPassives']);
    }

    public function testPayments(): void
    {
        $id = $this->passive('Erika', 'Mustermann', ['caretaker_id' => $this->member]);
        $this->assertSame(['fee_cents' => 0], $this->call('GET', '/passive-members/fee')->data, 'kein Vorschlag');
        $this->assertSame(['fee_cents' => 2400], $this->call('PUT', '/passive-members/fee', ['fee_cents' => 2400])->data);
        $list = $this->call('GET', '/passive-members')->data;
        $this->assertSame(2400, $list['fee_cents']);
        $this->assertSame(2026, $list['year']);
        $this->assertSame([], $list['passives'][0]['payments']);

        $res = $this->call('POST', "/passive-members/$id/payments", ['year' => 2026, 'amount_cents' => 2400, 'paid_on' => '2026-02-03', 'method' => 'sepa']);
        $this->assertSame(201, $res->status, (string) json_encode($res->data));
        $pay = $res->data['id'];
        $this->call('POST', "/passive-members/$id/payments", ['year' => 2025, 'amount_cents' => 2000, 'paid_on' => '2025-03-01', 'method' => 'cash']);
        $this->assertSame(400, $this->call('POST', "/passive-members/$id/payments", ['year' => 2026, 'amount_cents' => 100, 'paid_on' => '2026-03-01', 'method' => 'cash'])->status, 'je Jahr eine');
        $this->assertSame([2026, 2025], array_column($this->call('GET', '/passive-members')->data['passives'][0]['payments'], 'year'), 'neuestes zuerst');

        $res = $this->call('PATCH', "/passive-payments/$pay", ['amount_cents' => 3000, 'method' => 'paypal']);
        $this->assertSame(['id' => $pay, 'year' => 2026, 'amount_cents' => 3000, 'paid_on' => '2026-02-03', 'method' => 'paypal'], $res->data);
        $this->assertSame(400, $this->call('PATCH', "/passive-payments/$pay", ['method' => 'scheck'])->status, 'unbekannte Zahlungsart');
        $this->assertSame(400, $this->call('PATCH', "/passive-payments/$pay", ['year' => 2025])->status, 'Jahr schon belegt');

        // Betreuer sieht die Zahlungen, nur lesend
        $this->users->current = $this->member;
        $this->assertSame([2026, 2025], array_column($this->call('GET', '/me/passive-members')->data[0]['payments'], 'year'));
        $this->assertSame(403, $this->call('PATCH', "/passive-payments/$pay", ['amount_cents' => 1])->status);
        $this->assertSame(403, $this->call('POST', "/passive-members/$id/payments", ['year' => 2024, 'amount_cents' => 100, 'paid_on' => '2024-01-01', 'method' => 'cash'])->status);
        $this->assertSame(403, $this->call('PUT', '/passive-members/fee', ['fee_cents' => 1])->status);

        // Wer Passive verwaltet, darf eintragen (ohne persönliche Rechte)
        $this->users->current = $this->withRights('kassierer', ['manage_passive']);
        $this->assertSame(201, $this->call('POST', "/passive-members/$id/payments", ['year' => 2024, 'amount_cents' => 2000, 'paid_on' => '2024-01-05', 'method' => 'cash'])->status);
        $this->assertSame(200, $this->call('DELETE', "/passive-payments/$pay")->status);
        $this->assertSame(404, $this->call('DELETE', "/passive-payments/$pay")->status);
        $this->assertSame(['fee_cents' => 0], $this->call('PUT', '/passive-members/fee', ['fee_cents' => ''])->data, 'Vorschlag leeren');

        // Passiver gelöscht: Zahlungen auch
        $this->call('DELETE', "/passive-members/$id");
        $this->assertSame(0, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {passive_payments}'));
    }

    public function testPaymentValidation(): void
    {
        $id = $this->passive('Erika', 'Mustermann');
        $ok = ['year' => 2026, 'amount_cents' => 2400, 'paid_on' => '2026-02-03', 'method' => 'cash'];
        foreach ([
            'Jahr fehlt' => ['year' => null], 'Jahr zu früh' => ['year' => 1899], 'übernächstes Jahr' => ['year' => 2028],
            'Betrag 0' => ['amount_cents' => 0], 'Betrag negativ' => ['amount_cents' => -5], 'Betrag zu hoch' => ['amount_cents' => 100001],
            'Datum fehlt' => ['paid_on' => ''], 'Datum ungültig' => ['paid_on' => '2026-02-30'], 'Datum in der Zukunft' => ['paid_on' => '2026-11-17'],
            'Zahlungsart fehlt' => ['method' => null], 'Zahlungsart unbekannt' => ['method' => 'scheck'],
        ] as $why => $bad) {
            $this->assertSame(400, $this->call('POST', "/passive-members/$id/payments", $bad + $ok)->status, $why);
        }
        $this->assertSame(201, $this->call('POST', "/passive-members/$id/payments", ['year' => 2027] + $ok)->status, 'Vorauszahlung fürs nächste Jahr');
        $this->assertSame(404, $this->call('POST', '/passive-members/99999/payments', $ok)->status);
        $this->assertSame(400, $this->call('PUT', '/passive-members/fee', ['fee_cents' => 100001])->status);
        $this->assertSame(400, $this->call('PUT', '/passive-members/fee', ['fee_cents' => -1])->status);
    }

    public function testStandardRoleAndBackup(): void
    {
        $roles = array_column($this->call('GET', '/admin/roles')->data, 'capabilities', 'label');
        $this->assertContains('manage_passive', $roles['Passive verwalten'] ?? [], 'Standardrolle des Bereichs');
        $this->assertContains('passive_members', Backup::CORE_TABLES);
        $this->assertContains('passive_payments', Backup::CORE_TABLES);
    }
}
