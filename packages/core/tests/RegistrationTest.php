<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\RegistrationService;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Push\PushService;
use KompanieApp\Core\Storage\Backup;
use KompanieApp\Core\Tests\Support\FakeMailer;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\MemorySettings;
use KompanieApp\Core\Tests\Support\NullScheduler;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** Selbstregistrierung (Issue #314): Antrag, Bestätigung, Freigabe, Ablehnung, Fristen, mit und ohne Mailversand. */
final class RegistrationTest extends TestCase
{
    private FixedClock $clock;
    private FakeUsers $users;
    private FakeMailer $mailer;
    private NullScheduler $scheduler;
    private int $admin;

    protected function setUp(): void
    {
        $this->clock = new FixedClock(new \DateTimeImmutable('2026-11-01 12:00:00 UTC'));
        $this->users = new FakeUsers();
        $this->mailer = new FakeMailer();
        $this->scheduler = new NullScheduler();
        $this->admin = $this->users->add('admin', Roles::ADMIN);
    }

    private function app(bool $mail): App
    {
        $settings = new MemorySettings();
        if ($mail) {
            $settings->set('smtp_host', 'smtp.example.org');
            $settings->set('mail_from', 'info@kompanie.example');
        }
        $app = TestApp::create(TestApp::connection(), $this->users, settings: $settings, clock: $this->clock, mailer: $this->mailer, scheduler: $this->scheduler);
        $app->scheduleTasks();
        $this->users->current = $this->admin;
        [$s, $d] = $this->api($app, 'PUT', '/admin/registration', ['enabled' => true]);
        $this->assertSame(200, $s, json_encode($d));
        $this->users->current = null;
        return $app;
    }

    private function later(int $seconds): void
    {
        $this->clock->at = $this->clock->now()->modify("+$seconds seconds");
    }

    /** @return array{0: int, 1: mixed} */
    private function api(App $app, string $method, string $path, array $body = [], string $client = '203.0.113.5'): array
    {
        $res = $app->api()->handle(new Request($method, $path, [], $body, [], $client));
        return [$res->status, $res->data];
    }

    /** Formular öffnen, kurz warten, absenden (als Gast). */
    private function submit(App $app, array $fields = [], string $client = '203.0.113.5'): array
    {
        $current = $this->users->current;
        $this->users->current = null;
        [, $form] = $this->api($app, 'GET', '/registration/form');
        $this->later(5);
        $out = $this->api($app, 'POST', '/registration', $fields + [
            'token' => $form['token'],
            'consent' => true,
            'first_name' => 'Erika',
            'last_name' => 'Muster',
            'email' => 'erika@example.org',
            'username' => 'erika',
            'password' => 'sicheres-passwort',
            'message' => 'Ich bin neu dabei.',
        ], $client);
        $this->users->current = $current;
        return $out;
    }

    private function asAdmin(App $app, string $method, string $path, array $body = []): array
    {
        $this->users->current = $this->admin;
        try {
            return $this->api($app, $method, $path, $body);
        } finally {
            $this->users->current = null;
        }
    }

    private function idOf(string $login): ?int
    {
        foreach ($this->users->users as $id => $u) {
            if ($u['login'] === $login) {
                return $id;
            }
        }
        return null;
    }

    public function testOffByDefault(): void
    {
        $app = TestApp::create(TestApp::connection(), $this->users, clock: $this->clock, scheduler: $this->scheduler);
        $app->scheduleTasks();
        $this->assertFalse($app->api()->config()['registration']);
        [$s, $d] = $this->api($app, 'GET', '/registration/form');
        $this->assertSame(404, $s);
        $this->assertSame('registration_unavailable', $d['code']);
        $this->assertSame(404, $this->api($app, 'POST', '/registration', ['first_name' => 'X'])[0]);
        // Aufräumen läuft immer (auch nach dem Ausschalten bleiben Anfragen nicht liegen).
        $this->assertSame(RegistrationService::INTERVAL, $this->scheduler->scheduled[RegistrationService::TASK]);
    }

    public function testWithoutMailTheRequestWaitsAndTheAdminApprovesIt(): void
    {
        $app = $this->app(false);
        $this->assertTrue($app->api()->config()['registration']);
        [, $form] = $this->api($app, 'GET', '/registration/form');
        $this->assertFalse($form['email_confirmation']);

        [$s, $d] = $this->submit($app);
        $this->assertSame(201, $s, json_encode($d));
        $this->assertSame(['status' => 'pending'], $d);
        $this->assertNull($this->idOf('erika'), 'noch kein Konto');
        $this->assertSame([], $this->mailer->sent);

        // Anmelden geht noch nicht; die Anmeldung sagt, warum (nur mit dem richtigen Passwort).
        [$s, $d] = $this->api($app, 'POST', '/auth/login', ['username' => 'erika', 'password' => 'sicheres-passwort']);
        $this->assertSame(403, $s);
        $this->assertSame('registration_pending', $d['code']);
        [, $d] = $this->api($app, 'POST', '/auth/login', ['username' => 'erika', 'password' => 'falsch-falsch']);
        $this->assertSame('login_failed', $d['code']);
        $this->assertSame(['status' => 'pending'], $this->api($app, 'POST', '/registration/status', ['username' => 'erika@example.org', 'password' => 'sicheres-passwort'])[1]);
        $this->assertSame(['status' => 'none'], $this->api($app, 'POST', '/registration/status', ['username' => 'erika', 'password' => 'anderes-passwort'])[1]);

        // Zähler und Liste für die Freigebenden
        $this->users->current = $this->admin;
        $this->assertSame(1, $app->api()->config()['pendingRegistrations']);
        $this->users->current = null;
        $this->assertNull($app->api()->config()['pendingRegistrations'], 'Gäste sehen keinen Zähler');
        [$s, $list] = $this->asAdmin($app, 'GET', '/registrations');
        $this->assertSame(200, $s);
        $this->assertCount(1, $list['requests']);
        $r = $list['requests'][0];
        $this->assertSame(['Erika Muster', 'erika', 'erika@example.org', 'Ich bin neu dabei.', false, false], [$r['name'], $r['username'], $r['email'], $r['message'], $r['email_confirmed'], $r['username_taken']]);
        $this->assertSame(Roles::MEMBER, $list['default_role']);
        $this->assertArrayNotHasKey('password_hash', $r);

        [$s, $d] = $this->asAdmin($app, 'POST', '/registrations/' . $r['id'] . '/approve', []);
        $this->assertSame(200, $s, json_encode($d));
        $this->assertFalse($d['mailed']);
        $id = $this->idOf('erika');
        $this->assertNotNull($id);
        $this->assertSame([Roles::MEMBER, 'Erika', 'Muster', 'erika@example.org'], [$this->users->users[$id]['level'], $this->users->users[$id]['first'], $this->users->users[$id]['last'], $this->users->users[$id]['email']]);
        $this->assertTrue($this->users->verifyPassword($id, 'sicheres-passwort'), 'Passwort aus dem Antrag');
        $this->assertSame(0, (int) $app->db->scalar('SELECT COUNT(*) FROM {registration_requests}'));
        // Jetzt klappt die Anmeldung.
        $this->assertSame(200, $this->api($app, 'POST', '/auth/login', ['username' => 'erika', 'password' => 'sicheres-passwort'])[0]);
        $this->users->current = null;
        $this->assertSame(404, $this->asAdmin($app, 'POST', '/registrations/' . $r['id'] . '/approve', [])[0], 'schon bearbeitet');
    }

    public function testChecksAndProtection(): void
    {
        $app = $this->app(false);
        $this->users->users[$this->admin]['email'] = 'admin@example.org';
        $this->assertSame('form_expired', $this->api($app, 'POST', '/registration', ['token' => 'x', 'website' => ''])[1]['code']);
        [, $form] = $this->api($app, 'GET', '/registration/form');
        $this->assertSame('too_fast', $this->api($app, 'POST', '/registration', ['token' => $form['token']])[1]['code']);
        // Honeypot: wie Erfolg, aber nichts gespeichert
        $this->assertSame(['status' => 'pending'], $this->submit($app, ['website' => 'http://spam.example'])[1]);
        $this->assertSame(0, (int) $app->db->scalar('SELECT COUNT(*) FROM {registration_requests}'));

        $this->assertStringContainsString('Datenschutzerklärung', $this->submit($app, ['consent' => false])[1]['message']);
        $this->assertStringContainsString('Vor- und Nachnamen', $this->submit($app, ['last_name' => ''])[1]['message']);
        $this->assertStringContainsString('mindestens 8', $this->submit($app, ['password' => 'kurz'])[1]['message']);
        $this->assertStringContainsString('Benutzername', $this->submit($app, ['username' => 'mit leerzeichen'])[1]['message']);
        $this->assertStringContainsString('schon vergeben', $this->submit($app, ['username' => 'ADMIN'])[1]['message']);
        $this->assertStringContainsString('schon ein Konto', $this->submit($app, ['email' => 'Admin@example.org'])[1]['message']);

        $this->assertSame(201, $this->submit($app)[0]);
        $this->assertStringContainsString('schon vergeben', $this->submit($app, ['email' => 'anders@example.org'], '198.51.100.1')[1]['message'], 'Benutzername einer offenen Anfrage');
        $this->assertStringContainsString('schon eine Anfrage', $this->submit($app, ['username' => 'erika2'], '198.51.100.1')[1]['message']);

        // Angemeldet geht es nicht.
        $this->users->current = $this->admin;
        [, $form] = $this->api($app, 'GET', '/registration/form');
        $this->later(5);
        $this->assertSame('Du bist schon angemeldet.', $this->api($app, 'POST', '/registration', ['token' => $form['token']])[1]['message']);
        $this->users->current = null;
    }

    public function testRateLimitPerClient(): void
    {
        $app = $this->app(false);
        for ($i = 1; $i <= RegistrationService::CLIENT_RATE; $i++) {
            $this->assertSame(201, $this->submit($app, ['username' => 'p' . $i, 'email' => "p$i@example.org"])[0]);
        }
        [$s, $d] = $this->submit($app, ['username' => 'p9', 'email' => 'p9@example.org']);
        $this->assertSame(429, $s);
        $this->assertSame('too_many_attempts', $d['code']);
        $this->assertSame(201, $this->submit($app, ['username' => 'p9', 'email' => 'p9@example.org'], '2001:db8:1::1')[0], 'anderes Gerät');
    }

    public function testWithMailTheAddressIsConfirmedFirst(): void
    {
        $app = $this->app(true);
        [, $form] = $this->api($app, 'GET', '/registration/form');
        $this->assertTrue($form['email_confirmation']);
        $this->assertSame(['status' => 'confirm_email'], $this->submit($app)[1]);
        $this->assertCount(1, $this->mailer->sent);
        $mail = $this->mailer->sent[0];
        $this->assertSame('erika@example.org', $mail->to);
        $this->assertMatchesRegularExpression('~#/registrieren/bestaetigen/([0-9a-f]{64})~', $mail->text);
        preg_match('~#/registrieren/bestaetigen/([0-9a-f]{64})~', $mail->text, $m);
        $this->assertStringNotContainsString($m[1], (string) $app->db->scalar('SELECT token_hash FROM {registration_requests}'), 'nur der Hash');
        $this->assertSame([], $this->asAdmin($app, 'GET', '/registrations')[1]['requests'], 'unbestätigt nicht sichtbar');
        $this->assertSame('registration_unconfirmed', $this->api($app, 'POST', '/auth/login', ['username' => 'erika', 'password' => 'sicheres-passwort'])[1]['code']);

        $this->assertSame('link_invalid', $this->api($app, 'POST', '/registration/confirm', ['token' => str_repeat('0', 64)])[1]['code']);
        $this->assertSame(['status' => 'pending'], $this->api($app, 'POST', '/registration/confirm', ['token' => $m[1]])[1]);
        $this->assertSame('link_invalid', $this->api($app, 'POST', '/registration/confirm', ['token' => $m[1]])[1]['code'], 'Link nur einmal');
        $list = $this->asAdmin($app, 'GET', '/registrations')[1];
        $this->assertTrue($list['requests'][0]['email_confirmed']);

        [$s, $d] = $this->asAdmin($app, 'POST', '/registrations/' . $list['requests'][0]['id'] . '/approve', ['username' => 'erika.m']);
        $this->assertSame(200, $s, json_encode($d));
        $this->assertTrue($d['mailed']);
        $this->assertSame('erika.m', $d['member']['username']);
        $this->assertStringContainsString('Benutzername: erika.m', end($this->mailer->sent)->text);
    }

    public function testWithMailNothingRevealsAnExistingAccount(): void
    {
        $app = $this->app(true);
        $this->users->users[$this->admin]['email'] = 'admin@example.org';
        $this->assertSame(['status' => 'confirm_email'], $this->submit($app, ['email' => 'admin@example.org'])[1]);
        $this->assertSame(0, (int) $app->db->scalar('SELECT COUNT(*) FROM {registration_requests}'));
        $this->assertStringContainsString('schon ein Konto', $this->mailer->sent[0]->text);
    }

    public function testWithMailTheConfirmationLinkExpires(): void
    {
        $app = $this->app(true);
        $this->submit($app);
        preg_match('~bestaetigen/([0-9a-f]{64})~', $this->mailer->sent[0]->text, $m);
        $this->later(RegistrationService::CONFIRM_HOURS * 3600 + 60);
        $this->assertSame('link_invalid', $this->api($app, 'POST', '/registration/confirm', ['token' => $m[1]])[1]['code']);
        $app->runTask(RegistrationService::TASK);
        $this->assertSame(0, (int) $app->db->scalar('SELECT COUNT(*) FROM {registration_requests}'));
    }

    public function testRejectWithMailDeletesAndTellsThePerson(): void
    {
        $app = $this->app(true);
        $this->submit($app);
        preg_match('~bestaetigen/([0-9a-f]{64})~', $this->mailer->sent[0]->text, $m);
        $this->api($app, 'POST', '/registration/confirm', ['token' => $m[1]]);
        $id = $this->asAdmin($app, 'GET', '/registrations')[1]['requests'][0]['id'];
        [$s, $d] = $this->asAdmin($app, 'POST', "/registrations/$id/reject", ['reason' => 'Unbekannt']);
        $this->assertSame(['rejected' => true, 'mailed' => true], $d);
        $this->assertStringContainsString('Begründung: Unbekannt', end($this->mailer->sent)->text);
        $this->assertSame(0, (int) $app->db->scalar('SELECT COUNT(*) FROM {registration_requests}'));
    }

    public function testRejectWithoutMailKeepsOnlyWhatThePersonNeedsToSeeIt(): void
    {
        $app = $this->app(false);
        $this->submit($app);
        $id = $this->asAdmin($app, 'GET', '/registrations')[1]['requests'][0]['id'];
        $this->assertSame(['rejected' => true, 'mailed' => false], $this->asAdmin($app, 'POST', "/registrations/$id/reject", ['reason' => 'Bitte persönlich melden'])[1]);
        $row = $app->db->selectOne('SELECT * FROM {registration_requests}');
        $this->assertSame(['rejected', '', '', '', null], [$row['status'], $row['first_name'], $row['last_name'], $row['email'], $row['message']]);
        $this->assertSame([], $this->asAdmin($app, 'GET', '/registrations')[1]['requests']);
        [$s, $d] = $this->api($app, 'POST', '/auth/login', ['username' => 'erika', 'password' => 'sicheres-passwort']);
        $this->assertSame('registration_rejected', $d['code']);
        $this->assertStringContainsString('Bitte persönlich melden', $d['message']);
        // Nach der Frist ist auch das weg; der Name ist dann wieder frei.
        $this->later(RegistrationService::REJECTED_DAYS * 86400 + 60);
        $app->runTask(RegistrationService::TASK);
        $this->assertSame(0, (int) $app->db->scalar('SELECT COUNT(*) FROM {registration_requests}'));
    }

    public function testUnhandledRequestsExpireAndThePersonHearsAboutIt(): void
    {
        $app = $this->app(true);
        $this->submit($app);
        preg_match('~bestaetigen/([0-9a-f]{64})~', $this->mailer->sent[0]->text, $m);
        $this->api($app, 'POST', '/registration/confirm', ['token' => $m[1]]);
        $this->later(RegistrationService::PENDING_DAYS * 86400 + 60);
        $app->runTask(RegistrationService::TASK);
        $this->assertSame(0, (int) $app->db->scalar('SELECT COUNT(*) FROM {registration_requests}'));
        $this->assertStringContainsString('nicht bearbeitet', end($this->mailer->sent)->text);
    }

    public function testRightsAndRoles(): void
    {
        $app = $this->app(false);
        $member = $this->users->add('mitglied', Roles::MEMBER);
        $this->submit($app);
        $this->users->current = $member;
        $this->assertSame(403, $this->api($app, 'GET', '/registrations')[0]);
        $this->assertSame(403, $this->api($app, 'POST', '/registrations/1/approve')[0]);
        $this->assertSame(403, $this->api($app, 'PUT', '/admin/registration', ['enabled' => false])[0]);
        $this->users->current = null;

        // Vorgeschlagene Rolle: nur Mitglied oder Rollen für neue Konten, nie Admin
        $this->assertSame(400, $this->asAdmin($app, 'PUT', '/admin/registration', ['default_role' => Roles::ADMIN])[0]);
        $this->assertSame(400, $this->asAdmin($app, 'PUT', '/admin/registration', ['default_role' => 'gibtsnicht'])[0]);
        $candidate = TestApp::candidateRole($app);
        [$s, $d] = $this->asAdmin($app, 'PUT', '/admin/registration', ['default_role' => $candidate]);
        $this->assertSame(200, $s, json_encode($d));
        $this->assertSame($candidate, $d['default_role']);
        $this->assertStringEndsWith('#/registrieren', $d['link']);
        $id = $this->asAdmin($app, 'GET', '/registrations')[1]['requests'][0]['id'];
        $this->assertSame(400, $this->asAdmin($app, 'POST', "/registrations/$id/approve", ['level' => Roles::ADMIN])[0]);
        [$s, $d] = $this->asAdmin($app, 'POST', "/registrations/$id/approve", []);
        $this->assertSame(200, $s, json_encode($d));
        $this->assertSame($candidate, $this->users->users[$this->idOf('erika')]['level']);
    }

    public function testApproversGetNotifiedAndRequestsAreNotInBackups(): void
    {
        $app = $this->app(false);
        $this->assertContains(PushService::TYPE_REGISTRATIONS, $app->push()->availableTypes($this->users->find($this->admin)));
        $member = $this->users->add('mitglied', Roles::MEMBER);
        $this->assertNotContains(PushService::TYPE_REGISTRATIONS, $app->push()->availableTypes($this->users->find($member)));
        $this->assertNotContains('registration_requests', Backup::CORE_TABLES);
        $this->asAdmin($app, 'PUT', '/admin/registration', ['enabled' => false]);
        $this->assertNotContains(PushService::TYPE_REGISTRATIONS, $app->push()->availableTypes($this->users->find($this->admin)));
    }
}
