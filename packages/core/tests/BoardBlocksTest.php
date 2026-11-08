<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\BoardMailService;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Tests\Support\FakeMailer;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\NullScheduler;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * Nachrichten am Schwarzen Brett aus Blöcken: „Für wen?“ je Block (auch bei öffentlichen Nachrichten und für Bilder),
 * ältere Apps (text), Verlauf, Mail mit allen Blöcken.
 */
final class BoardBlocksTest extends TestCase
{
    private FakeUsers $users;
    private FixedClock $clock;
    private FakeMailer $mailer;
    private App $app;
    private int $admin;
    private int $member;
    private int $guest;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->clock = new FixedClock(new \DateTimeImmutable('2027-03-01 12:00:00', new \DateTimeZone('Europe/Berlin')));
        $this->mailer = new FakeMailer();
        $this->app = TestApp::create(TestApp::connection(), $this->users, ['name' => 'Musterkompanie'], null, null, null, $this->clock, ['board' => true], [], new NullScheduler(), null, null, $this->mailer);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->member = $this->users->add('anna', Roles::MEMBER);
        $this->guest = $this->users->add('gast', Roles::GUEST);
        $this->users->users[$this->admin]['email'] = 'admin@example.org';
        $this->users->users[$this->member]['email'] = 'anna@example.org';
        $this->users->current = $this->admin;
    }

    private function call(string $method, string $path, array $body = [], ?int $as = -1): Response
    {
        if ($as !== -1) {
            $this->users->current = $as;
        }
        $res = $this->app->api()->handle(new Request($method, $path, [], $body));
        $this->users->current = $this->admin;
        return $res;
    }

    /** @return array<string, mixed> */
    private function ok(string $method, string $path, array $body = [], ?int $as = -1): array
    {
        $res = $this->call($method, $path, $body, $as);
        $this->assertLessThan(300, $res->status, $method . ' ' . $path . ': ' . json_encode($res->data));
        return (array) $res->data;
    }

    /** @return array<string, mixed>|null */
    private function item(int $id, ?int $as): ?array
    {
        foreach ($this->ok('GET', '/board', [], $as)['items'] as $item) {
            if (($item['id'] ?? null) === $id) {
                return $item;
            }
        }
        return null;
    }

    public function testBlocksAudienceOlderClientsAndHistory(): void
    {
        $item = $this->ok('POST', '/board', ['title' => 'Schützenfest', 'audience' => 'public', 'push' => false, 'blocks' => [
            ['type' => 'text', 'html' => '<p>Für alle</p>'],
            ['type' => 'countdown', 'title' => 'Festbeginn', 'source' => 'date', 'date' => '2027-03-10', 'time' => '18:00'],
            ['type' => 'text', 'audience' => 'members', 'html' => '<p>Treffpunkt intern</p>'],
        ]]);
        $this->assertSame(['text', 'countdown', 'text'], array_column($item['blocks'], 'type'));
        $this->assertSame('2027-03-10T17:00:00Z', $item['blocks'][1]['starts_at']);
        $this->assertSame('<p>Für alle</p>', $item['text'], 'text für ältere Apps: nur Textblöcke für alle');

        $public = $this->item($item['id'], null);
        $this->assertSame(['text', 'countdown'], array_column($public['blocks'], 'type'), 'ohne Anmeldung ohne Mitglieder-Block');
        $this->assertStringNotContainsString('intern', (string) json_encode($public));
        $this->assertSame(['text', 'countdown'], array_column($this->item($item['id'], $this->guest)['blocks'], 'type'));
        $member = $this->item($item['id'], $this->member);
        $this->assertSame(['text', 'countdown', 'text'], array_column($member['blocks'], 'type'));
        $this->assertSame("Für alle\n\nFestbeginn: 10.03.2027, 18:00 Uhr\n\nTreffpunkt intern", $member['excerpt']);

        // Ältere App ändert nur text: danach ein Textblock
        $this->ok('PATCH', "/board/{$item['id']}", ['text' => '<p>Neu</p>']);
        $this->assertSame([['id' => 'b1', 'type' => 'text', 'audience' => 'all', 'html' => '<p>Neu</p>']], $this->item($item['id'], $this->member)['blocks']);

        // Verlauf: Blöcke als Text, Mitglieder-Blöcke gekennzeichnet
        $history = $this->ok('GET', "/board/{$item['id']}/history");
        $created = $history['revisions'][0]['after']['text'];
        $this->assertStringContainsString('<p>Festbeginn: 10.03.2027, 18:00 Uhr</p>', $created);
        $this->assertStringContainsString('<p><em>Nur für Mitglieder:</em></p><p>Treffpunkt intern</p>', $created);
        $content = $history['revisions'][1];
        $this->assertSame(['content', '<p>Neu</p>'], [$content['action'], $content['after']]);
        $this->assertStringContainsString('Festbeginn', (string) $content['before']);

        // Gleicher Inhalt als Blöcke: keine Änderung
        $before = count($this->ok('GET', "/board/{$item['id']}/history")['revisions']);
        $this->ok('PATCH', "/board/{$item['id']}", ['blocks' => [['id' => 'b1', 'type' => 'text', 'html' => '<p>Neu</p>']]]);
        $this->assertCount($before, $this->ok('GET', "/board/{$item['id']}/history")['revisions']);
    }

    public function testValidation(): void
    {
        $base = ['title' => 'T', 'push' => false];
        $this->assertSame(400, $this->call('POST', '/board', $base + ['blocks' => []])->status, 'ohne Inhalt');
        $this->assertSame(400, $this->call('POST', '/board', $base + ['blocks' => [['type' => 'text', 'html' => '<p></p>']]])->status);
        $this->assertSame(400, $this->call('POST', '/board', $base + ['blocks' => [['type' => 'gibtsnicht']]])->status);
        $this->assertSame(400, $this->call('POST', '/board', $base)->status, 'weder text noch blocks');
        $only = $this->ok('POST', '/board', $base + ['blocks' => [['type' => 'countdown', 'source' => 'date', 'date' => '2027-03-10']]]);
        $this->assertSame('', $only['text'], 'nur ein Countdown');
        $this->assertSame(400, $this->call('PATCH', "/board/{$only['id']}", ['blocks' => []])->status);
    }

    public function testMailContainsAllBlocks(): void
    {
        $res = $this->call('PATCH', '/admin/mail', ['from' => 'info@musterkompanie.example', 'host' => 'smtp.example.org', 'port' => 587, 'secure' => 'starttls', 'user' => 'info', 'password' => 'geheim']);
        $this->assertSame(200, $res->status);
        $this->ok('POST', '/board', ['title' => 'Fest', 'push' => false, 'mail' => true, 'audience' => 'public', 'blocks' => [
            ['type' => 'text', 'html' => '<p>Hallo</p>'],
            ['type' => 'countdown', 'source' => 'date', 'date' => '2027-03-10', 'time' => '18:00'],
            ['type' => 'text', 'audience' => 'members', 'html' => '<p>Nur intern</p>'],
        ]]);
        $this->app->runTask(BoardMailService::TASK);
        $this->assertNotSame([], $this->mailer->sent);
        $mail = $this->mailer->sent[0];
        $this->assertStringContainsString('<p>Hallo</p><p>Countdown bis 10.03.2027, 18:00 Uhr</p><p>Nur intern</p>', $mail->html, 'Mails gehen nur an Mitglieder');
        $this->assertStringContainsString("Hallo\n\nCountdown bis 10.03.2027, 18:00 Uhr\n\nNur intern", $mail->text);
    }

    public function testImagesInMembersBlocks(): void
    {
        $png = imagecreatetruecolor(4, 3);
        ob_start();
        imagepng($png);
        $bytes = (string) ob_get_clean();
        $upload = fn (): string => $this->call('POST', '/rich-images', ['file' => ['name' => 'bild.png', 'data' => base64_encode($bytes)]])->data['uid'];
        $pub = $upload();
        $intern = $upload();
        $d = fn (string $uid, ?int $user): int => $this->app->richImages()->forDelivery($user === null ? null : $this->users->find($user), $uid)['status'];

        $item = $this->ok('POST', '/board', ['title' => 'Bilder', 'audience' => 'public', 'push' => false, 'blocks' => [
            ['type' => 'text', 'html' => '<img data-image="' . $pub . '">'],
            ['type' => 'text', 'audience' => 'members', 'html' => '<img data-image="' . $intern . '">'],
        ]]);
        $this->assertSame(200, $d($pub, null));
        $this->assertNotSame(200, $d($intern, null), 'Bild im Mitglieder-Block: ohne Anmeldung nicht');
        $this->assertNotSame(200, $d($intern, $this->guest));
        $this->assertSame(200, $d($intern, $this->member));

        // Aus der Nachricht entfernt: bleibt für den Verlauf
        $this->ok('PATCH', "/board/{$item['id']}", ['blocks' => [['type' => 'text', 'html' => '<p>ohne Bilder</p>']]]);
        $this->assertNotNull($this->app->richImages()->repository()->find($intern));
    }
}
