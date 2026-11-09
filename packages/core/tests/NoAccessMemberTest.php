<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\MemberService;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Tests\Support\FakeMailer;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\MemorySettings;
use KompanieApp\Core\Tests\Support\NullScheduler;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * Mitglieder ohne Zugang (z. B. für Chroniken): anlegen ohne Startpasswort, später „Zugang einrichten“ oder beim Freigeben
 * einer Selbstregistrierung verbinden, mit Vorschlägen.
 */
final class NoAccessMemberTest extends TestCase
{
    private FixedClock $clock;
    private FakeUsers $users;
    private FakeMailer $mailer;
    private int $admin;
    private App $app;

    protected function setUp(): void
    {
        $this->clock = new FixedClock(new \DateTimeImmutable('2026-11-08 12:00:00 UTC'));
        $this->users = new FakeUsers();
        $this->mailer = new FakeMailer();
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->app = TestApp::create(TestApp::connection(), $this->users, settings: new MemorySettings(), clock: $this->clock, mailer: $this->mailer, scheduler: new NullScheduler());
        $this->app->scheduleTasks();
        [$s, $d] = $this->asAdmin('PUT', '/admin/registration', ['enabled' => true]);
        $this->assertSame(200, $s, json_encode($d));
    }

    /** @return array{0: int, 1: mixed} */
    private function api(string $method, string $path, array $body = []): array
    {
        $res = $this->app->api()->handle(new Request($method, $path, [], $body, [], '203.0.113.5'));
        return [$res->status, $res->data];
    }

    /** @return array{0: int, 1: mixed} */
    private function asAdmin(string $method, string $path, array $body = []): array
    {
        $this->users->current = $this->admin;
        try {
            return $this->api($method, $path, $body);
        } finally {
            $this->users->current = null;
        }
    }

    private function createNoAccess(string $first, string $last, string $email = ''): int
    {
        [$s, $d] = $this->asAdmin('POST', '/users', ['first_name' => $first, 'last_name' => $last, 'no_access' => true] + ($email !== '' ? ['email' => $email] : []));
        $this->assertSame(201, $s, json_encode($d));
        $this->assertTrue($d['no_access']);
        $this->assertArrayNotHasKey('password', $d, 'kein Startpasswort');
        return (int) $d['id'];
    }

    /** Antrag als Gast stellen (ohne Mailversand: wartet gleich auf Freigabe). */
    private function submit(array $fields = []): array
    {
        [, $form] = $this->api('GET', '/registration/form');
        $this->clock->at = $this->clock->now()->modify('+5 seconds');
        return $this->api('POST', '/registration', $fields + [
            'token' => $form['token'],
            'consent' => true,
            'first_name' => 'Jürgen',
            'last_name' => 'Müller-Lang',
            'email' => 'juergen@example.org',
            'username' => 'juergen',
            'password' => 'sicheres-passwort',
        ]);
    }

    public function testCreatedWithoutAccessIsAnOrdinaryMemberThatCannotLogIn(): void
    {
        $id = $this->createNoAccess('Jürgen', 'Müller-Lang');
        $this->assertSame(Roles::MEMBER, $this->users->users[$id]['level']);
        [$s, $list] = $this->asAdmin('GET', '/members');
        $this->assertSame(200, $s);
        $row = array_values(array_filter($list, static fn (array $m): bool => $m['id'] === $id))[0];
        $this->assertTrue($row['no_access']);
        $this->assertFalse(array_values(array_filter($list, fn (array $m): bool => $m['id'] === $this->admin))[0]['no_access']);
        [, $detail] = $this->asAdmin('GET', '/members/' . $id);
        $this->assertTrue($detail['no_access']);
        // Niemand kennt das Passwort: Anmelden geht nicht.
        $login = $this->users->users[$id]['login'];
        $this->assertNotSame(200, $this->api('POST', '/auth/login', ['username' => $login, 'password' => 'geheim123'])[0]);
        $this->assertTrue($this->app->members()->hasNoAccess($id));
    }

    public function testSetUpAccessGivesAStartPassword(): void
    {
        $id = $this->createNoAccess('Erika', 'Muster');
        [$s, $d] = $this->asAdmin('POST', '/members/' . $id . '/password');
        $this->assertSame(200, $s, json_encode($d));
        $this->assertSame($this->users->users[$id]['login'], $d['username']);
        $this->assertTrue($this->users->verifyPassword($id, $d['password']));
        $this->assertFalse($this->app->members()->hasNoAccess($id));
        [, $detail] = $this->asAdmin('GET', '/members/' . $id);
        $this->assertFalse($detail['no_access']);
    }

    public function testLoggingInClearsTheMark(): void
    {
        $id = $this->createNoAccess('Erika', 'Muster');
        $this->users->current = $id;
        $this->assertSame(200, $this->api('GET', '/me')[0]);
        $this->users->current = null;
        $this->assertFalse($this->app->members()->hasNoAccess($id));
    }

    public function testMatchesAreTolerantAndSortedBestFirst(): void
    {
        $byName = $this->createNoAccess('Juergen', 'Mueller Lang');
        $similar = $this->createNoAccess('Jürg', 'Müller-Lang');
        $byEmail = $this->createNoAccess('Hans', 'Anders', 'JUERGEN@example.org');
        $this->createNoAccess('Jürgen', 'Schmitz');
        // Ein Mitglied mit Zugang wird nie vorgeschlagen.
        $this->asAdmin('POST', '/users', ['first_name' => 'Jürgen', 'last_name' => 'Müller-Lang']);

        [$s, $d] = $this->submit();
        $this->assertSame(201, $s, json_encode($d));
        [, $list] = $this->asAdmin('GET', '/registrations');
        $r = $list['requests'][0];
        $this->assertSame([[$byEmail, 'email'], [$byName, 'name'], [$similar, 'similar']], array_map(static fn (array $m): array => [$m['id'], $m['match']], $r['matches']));
        $this->assertCount(4, $list['no_access_members']);
        $this->assertFalse($r['email_taken'], 'Adresse eines Mitglieds ohne Zugang zählt nicht als vergeben');
    }

    public function testRegistrationWithTheAddressOfAMemberWithoutAccessIsAccepted(): void
    {
        $this->createNoAccess('Jürgen', 'Müller-Lang', 'juergen@example.org');
        [$s, $d] = $this->submit();
        $this->assertSame(201, $s, json_encode($d));
        // Bei einem Konto mit Zugang bleibt es dabei: Die Adresse ist vergeben.
        $this->asAdmin('POST', '/users', ['first_name' => 'Max', 'last_name' => 'Muster', 'email' => 'max@example.org']);
        [$s] = $this->submit(['email' => 'max@example.org', 'username' => 'max2']);
        $this->assertSame(400, $s);
    }

    public function testApproveLinksTheRequestToTheMember(): void
    {
        $id = $this->createNoAccess('Jürgen', 'Müller-Lang');
        $this->submit();
        [, $list] = $this->asAdmin('GET', '/registrations');
        $request = $list['requests'][0]['id'];
        $before = count($this->users->users);

        [$s, $d] = $this->asAdmin('POST', '/registrations/' . $request . '/approve', ['user_id' => $id]);
        $this->assertSame(200, $s, json_encode($d));
        $this->assertTrue($d['linked']);
        $this->assertSame($id, $d['member']['id']);
        $this->assertCount($before, $this->users->users, 'kein zweites Konto');
        $this->assertSame(['juergen', 'juergen@example.org'], [$this->users->users[$id]['login'], $this->users->users[$id]['email']]);
        $this->assertTrue($this->users->verifyPassword($id, 'sicheres-passwort'), 'Passwort aus dem Antrag');
        $this->assertFalse($this->app->members()->hasNoAccess($id));
        $this->assertSame(0, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {registration_requests}'));
        $this->assertSame(200, $this->api('POST', '/auth/login', ['username' => 'juergen', 'password' => 'sicheres-passwort'])[0]);
    }

    public function testOnlyMembersWithoutAccessCanBeLinked(): void
    {
        [, $other] = $this->asAdmin('POST', '/users', ['first_name' => 'Jürgen', 'last_name' => 'Müller-Lang']);
        $this->submit();
        [, $list] = $this->asAdmin('GET', '/registrations');
        $request = $list['requests'][0]['id'];
        [$s] = $this->asAdmin('POST', '/registrations/' . $request . '/approve', ['user_id' => $other['id']]);
        $this->assertSame(400, $s);
        $this->assertSame(1, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {registration_requests}'), 'Anfrage bleibt');
        $this->assertTrue($this->users->verifyPassword($other['id'], $other['password']), 'Passwort unverändert');
    }

    public function testSettingSurvivesInUserSettings(): void
    {
        $id = $this->createNoAccess('Erika', 'Muster');
        $this->assertSame('1', $this->app->db->scalar('SELECT value FROM {user_settings} WHERE user_id = ? AND name = ?', [$id, MemberService::NO_ACCESS]));
    }
}
