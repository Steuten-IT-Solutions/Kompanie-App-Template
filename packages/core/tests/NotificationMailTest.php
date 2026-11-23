<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\BoardMailService;
use KompanieApp\Core\Domain\NotificationMailService;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Push\PushService;
use KompanieApp\Core\Tests\Support\FakeMailer;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\NullScheduler;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** Benachrichtigungen zusätzlich per E-Mail, mit einem Test-Mailer: Es wird nichts verschickt. */
final class NotificationMailTest extends TestCase
{
    private FakeUsers $users;
    private FixedClock $clock;
    private FakeMailer $mailer;
    private NullScheduler $scheduler;
    private App $app;
    private int $admin;
    private int $m;
    private int $other;
    private int $noMail;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->clock = new FixedClock(new \DateTimeImmutable('2026-11-23 12:00:00', new \DateTimeZone('Europe/Berlin')));
        $this->mailer = new FakeMailer();
        $this->scheduler = new NullScheduler();
        $this->app = TestApp::create(TestApp::connection(), $this->users, ['name' => 'Musterkompanie'], null, null, null, $this->clock, ['board' => true, 'points' => true, 'polls' => true], [], $this->scheduler, null, null, $this->mailer);
        $this->admin = $this->user('admin', Roles::ADMIN, 'admin@example.org');
        $this->m = $this->user('mitglied', Roles::MEMBER, 'mitglied@example.org');
        $this->other = $this->user('zweites', Roles::MEMBER, 'zweites@example.org');
        $this->noMail = $this->user('ohneadresse', Roles::MEMBER, '');
        $this->users->current = $this->admin;
    }

    private function user(string $login, string $role, string $email): int
    {
        $id = $this->users->add($login, $role);
        $this->users->users[$id]['email'] = $email;
        return $id;
    }

    private function call(string $method, string $path, array $body = []): Response
    {
        return $this->app->api()->handle(new Request($method, $path, [], $body));
    }

    private function as(?int $user): self
    {
        $this->users->current = $user;
        return $this;
    }

    private function configure(): void
    {
        $res = $this->as($this->admin)->call('PATCH', '/admin/mail', ['from' => 'info@musterkompanie.example', 'host' => 'smtp.example.org', 'port' => 587, 'secure' => 'starttls', 'user' => 'info', 'password' => 'geheim']);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
    }

    private function points(int $userId, string $title = 'Punkte erhalten'): void
    {
        $this->app->push()->notifyUser($userId, PushService::TYPE_POINTS, PushService::message($title, '+2 – Schießen', 'meins/punkte/7', 'punkte-7'));
    }

    private function queued(): int
    {
        return (int) $this->app->db->scalar('SELECT COUNT(*) FROM {notification_mails} WHERE status = ?', [BoardMailService::PENDING]);
    }

    public function testAllTypesOnByDefaultWithoutBoard(): void
    {
        $data = $this->as($this->m)->call('GET', '/me/mail')->data['notifications'];
        $this->assertFalse($data['available'], 'ohne eingerichteten Mailversand');
        $this->configure();
        $data = $this->as($this->m)->call('GET', '/me/mail')->data['notifications'];
        $keys = array_column($data['types'], 'key');
        $this->assertTrue($data['available']);
        $this->assertContains(PushService::TYPE_POINTS, $keys);
        $this->assertContains(PushService::TYPE_POLLS, $keys);
        $this->assertNotContains(PushService::TYPE_BOARD, $keys, 'das Brett hat eigene Mails');
        $this->assertNotContains(PushService::TYPE_BOARD_PUBLIC, $keys);
        $this->assertSame($keys, $data['selected'], 'anfangs alle an');
    }

    public function testNotificationIsMailedAndCanBeSwitchedOff(): void
    {
        $this->configure();
        $this->points($this->m);
        $this->points($this->noMail);
        $this->assertSame(1, $this->queued(), 'ohne Mailadresse keine Mail');
        $this->assertContains(BoardMailService::TASK, $this->scheduler->soon, 'Versand angestoßen');
        $this->assertSame([], $this->mailer->sent, 'nicht in der Anfrage verschickt');

        $this->app->runTask(BoardMailService::TASK);
        $this->assertSame(['mitglied@example.org'], $this->mailer->recipients());
        $mail = $this->mailer->sent[0];
        $this->assertSame('Punkte erhalten', $mail->subject, 'Betreff = Titel der Benachrichtigung');
        $this->assertStringContainsString('+2 – Schießen', $mail->text);
        $this->assertStringContainsString('https://example.org/app/#/meins/punkte/7', $mail->html, 'Link in die App');
        $this->assertStringContainsString('Konto → Benachrichtigungen', $mail->text, 'Hinweis zum Abschalten');

        // Abschalten je Art, alle übrigen bleiben an
        $res = $this->as($this->m)->call('PUT', '/me/mail/notifications', ['types' => [PushService::TYPE_POLLS]]);
        $this->assertSame(200, $res->status);
        $this->assertSame([PushService::TYPE_POLLS], $res->data['notifications']['selected']);
        $this->points($this->m);
        $this->assertSame(0, $this->queued(), 'abgeschaltet');
        // Wieder an; leere Liste schaltet alles ab
        $this->assertContains(PushService::TYPE_POINTS, $this->call('PUT', '/me/mail/notifications', ['types' => [PushService::TYPE_POINTS, 'unbekannt']])->data['notifications']['selected']);
        $this->assertSame([], $this->call('PUT', '/me/mail/notifications', ['types' => []])->data['notifications']['selected']);
        $this->assertSame(400, $this->call('PUT', '/me/mail/notifications', ['types' => 'points'])->status);
        $this->assertSame(401, $this->as(null)->call('PUT', '/me/mail/notifications', ['types' => []])->status);
    }

    public function testSwitchedOffBeforeSendingCancels(): void
    {
        $this->configure();
        $this->points($this->m);
        $this->assertSame(1, $this->queued());
        $this->as($this->m)->call('PUT', '/me/mail/notifications', ['types' => []]);
        $this->app->runTask(BoardMailService::TASK);
        $this->assertSame([], $this->mailer->sent, 'inzwischen abgeschaltet: nicht mehr zustellen');
        $this->assertSame(BoardMailService::CANCELLED, $this->app->db->scalar('SELECT status FROM {notification_mails}'));
    }

    public function testAudienceRespectsIncludeAndRights(): void
    {
        $this->configure();
        // Neue Umfrage: an alle Mitglieder außer dem, der sie angelegt hat
        $this->app->push()->notifyAudience(PushService::TYPE_POLLS, PushService::message('Neue Umfrage', 'Ausflug?', 'umfragen/1', 'umfrage-1'), fn (int $uid): bool => $uid !== $this->admin);
        $this->app->runTask(BoardMailService::TASK);
        $this->assertSame(['mitglied@example.org', 'zweites@example.org'], $this->mailer->recipients());
        // Mit Ablaufzeit 0 nichts
        $this->app->push()->notifyAudience(PushService::TYPE_POLLS, PushService::message('Alt', '', 'umfragen/1'), static fn (int $uid): bool => true, ttl: 0);
        $this->assertSame(0, $this->queued());
    }

    public function testWithoutMailSetupNothingIsQueued(): void
    {
        $this->points($this->m);
        $this->assertSame(0, $this->queued());
    }

    public function testCleanupRemovesOldFinishedEntries(): void
    {
        $this->configure();
        $this->points($this->m);
        $this->app->runTask(BoardMailService::TASK);
        $this->clock->at = $this->clock->at?->modify("+" . (NotificationMailService::KEEP_DAYS + 1) . " days");
        $this->assertSame(1, $this->app->notificationMails()->cleanup());
        $this->assertSame(0, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {notification_mails}'));
    }
}
