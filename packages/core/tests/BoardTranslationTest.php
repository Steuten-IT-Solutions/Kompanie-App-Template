<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\BoardMailService;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\I18n\I18n;
use KompanieApp\Core\Push\WebPushCrypto;
use KompanieApp\Core\Tests\Support\FakeHttpClient;
use KompanieApp\Core\Tests\Support\FakeMailer;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\NullScheduler;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * Schwarzes Brett je Sprache (Plan „Mehrsprachigkeit“, PR 9c): Fassung beim Schreiben, Rückfall auf Deutsch mit Hinweis,
 * source zum Bearbeiten, Verlauf, Mail und Benachrichtigung in der Sprache der Empfänger.
 */
final class BoardTranslationTest extends TestCase
{
    private FakeUsers $users;
    private FakeMailer $mailer;
    private App $app;
    private int $admin;
    private int $de;
    private int $en;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->mailer = new FakeMailer();
        $clock = new FixedClock(new \DateTimeImmutable('2026-10-20 12:00:00', new \DateTimeZone('Europe/Berlin')));
        $http = new FakeHttpClient(static fn (): array => ['status' => 201, 'body' => '']);
        $this->app = TestApp::create(TestApp::connection(), $this->users, ['name' => 'Musterkompanie'], $http, null, null, $clock, ['board' => true], [], new NullScheduler(), null, null, $this->mailer);
        $this->admin = $this->user('admin', Roles::ADMIN, 'admin@example.org');
        $this->de = $this->user('anna', Roles::MEMBER, 'anna@example.org');
        $this->en = $this->user('bob', Roles::MEMBER, 'bob@example.org');
        $this->app->boardMail()->setWants($this->admin, false);
    }

    protected function tearDown(): void
    {
        I18n::setLocale(I18n::SOURCE_LOCALE);
    }

    private function user(string $login, string $role, string $email): int
    {
        $id = $this->users->add($login, $role);
        $this->users->users[$id]['email'] = $email;
        return $id;
    }

    /** @param array<string, mixed> $body */
    private function call(?int $user, string $method, string $path, array $body = [], ?string $locale = null): Response
    {
        $this->users->current = $user;
        $res = $this->app->api()->handle(new Request($method, $path, [], $body, [], '', null, $locale));
        I18n::setLocale(I18n::SOURCE_LOCALE);
        return $res;
    }

    /** @return array<string, mixed> */
    private function data(Response $res): array
    {
        $this->assertLessThan(300, $res->status, (string) json_encode($res->data));
        return json_decode((string) json_encode($res->data), true);
    }

    /** @return array<string, mixed> */
    private function message(?int $user, ?string $locale = null): array
    {
        $items = array_values(array_filter($this->data($this->call($user, 'GET', '/board', [], $locale))['items'], static fn (array $i): bool => $i['kind'] === 'message'));
        $this->assertCount(1, $items);
        return $items[0];
    }

    private function subscribe(int $user, string $name): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->assertNotFalse($key);
        $keys = ['p256dh' => WebPushCrypto::b64uEncode(WebPushCrypto::rawPublic($key)), 'auth' => WebPushCrypto::b64uEncode(random_bytes(16))];
        $this->app->push()->subscribe($this->users->find($user), 'https://fcm.googleapis.com/fcm/send/' . $name, $keys, null, null, '1.2.3.4');
    }

    public function testMessagePerLanguage(): void
    {
        $this->call($this->admin, 'PATCH', '/admin/mail', ['from' => 'info@musterkompanie.example', 'host' => 'smtp.example.org', 'port' => 587, 'secure' => 'starttls', 'user' => 'info', 'password' => 'geheim']);
        $german = [
            ['id' => 'b1', 'type' => 'text', 'html' => '<p>Heute kein Training</p>'],
            ['id' => 'b2', 'type' => 'notice', 'title' => 'Halle', 'text' => 'Gesperrt', 'tone' => 'warning'],
        ];
        // Ohne eingeschaltetes Englisch gibt es keine Fassung
        $this->assertSame(400, $this->call($this->admin, 'POST', '/board', ['title' => 'Training', 'blocks' => $german, 'translations' => ['en' => ['title' => 'Practice']]])->status);
        $this->call($this->admin, 'PATCH', '/admin/settings', ['languages' => 'de,en']);
        $this->data($this->call($this->en, 'PUT', '/me/locale', ['locale' => 'en']));
        $push = $this->app->push()->supported();
        if ($push) {
            $this->subscribe($this->de, 'anna');
            $this->subscribe($this->en, 'bob');
        }

        $item = $this->data($this->call($this->admin, 'POST', '/board', [
            'title' => 'Training fällt aus',
            'blocks' => $german,
            'translations' => ['en' => ['title' => 'No practice', 'blocks' => ['b1' => ['html' => '<p>No practice today</p>'], 'zz' => ['html' => '<p>?</p>']]]],
            'push' => true,
            'mail' => true,
        ]));
        $id = (int) $item['id'];
        $this->assertSame('Training fällt aus', $item['title']);
        $this->assertSame(['en'], array_keys($item['translations']));
        $this->assertSame(['b1'], array_keys($item['translations']['en']['blocks']), 'unbekannte Kennungen fallen weg');
        $this->assertArrayNotHasKey('source', $item, 'auf Deutsch ist die Nachricht schon die deutsche');

        // Lesen: auf Englisch die Fassung, der Hinweis fehlt nicht (Text des Hinweises fehlt noch)
        $en = $this->message($this->en, 'en');
        $this->assertSame('No practice', $en['title']);
        $this->assertSame('some', $en['untranslated']);
        $byId = array_column($en['blocks'], null, 'id');
        $this->assertSame('<p>No practice today</p>', $byId['b1']['html']);
        $this->assertSame('Halle', $byId['b2']['title'], 'fehlt: deutsch');
        $this->assertStringContainsString('No practice today', $en['text'], 'Text für ältere Apps auch übersetzt');
        $this->assertArrayNotHasKey('translations', $en, 'nur für alle, die ans Brett schreiben');
        $de = $this->message($this->de);
        $this->assertSame('Training fällt aus', $de['title']);
        $this->assertArrayNotHasKey('untranslated', $de);

        // Zum Bearbeiten in der englischen App: die deutsche Fassung unter source
        $admin = $this->message($this->admin, 'en');
        $this->assertSame('No practice', $admin['title']);
        $this->assertSame('Training fällt aus', $admin['source']['title']);
        $this->assertSame('<p>Heute kein Training</p>', array_column($admin['source']['blocks'], null, 'id')['b1']['html']);

        // Benachrichtigung je Sprache
        if ($push) {
            $rows = $this->app->db->select('SELECT q.payload, s.endpoint FROM {push_queue} q JOIN {push_subscriptions} s ON s.id = q.subscription_id ORDER BY q.id');
            $bodies = [];
            foreach ($rows as $r) {
                $bodies[basename((string) $r['endpoint'])] = json_decode((string) $r['payload'], true)['body'] ?? null;
            }
            $this->assertSame(['anna' => 'Training fällt aus', 'bob' => 'No practice'], $bodies);
        }

        // Mail je Sprache
        $this->app->runTask(BoardMailService::TASK);
        $subjects = [];
        foreach ($this->mailer->sent as $mail) {
            $subjects[$mail->to] = $mail->subject;
        }
        $this->assertSame(['anna@example.org' => 'Training fällt aus', 'bob@example.org' => 'No practice'], $subjects);
        $bob = array_values(array_filter($this->mailer->sent, static fn ($m): bool => $m->to === 'bob@example.org'))[0];
        $this->assertStringContainsString('No practice today', $bob->html);
        $this->assertStringContainsString('lang="en"', $bob->html);

        // Ändern: nur die Fassung; im Verlauf vermerkt, gleiche Fassung ändert nichts
        $before = $this->message($this->de)['updated_at'];
        $this->data($this->call($this->admin, 'PATCH', "/board/$id", ['translations' => ['en' => ['blocks' => ['b1' => ['html' => '<p>No practice today</p>'], 'b2' => ['title' => 'Hall', 'text' => 'Closed']]]]]));
        $this->assertArrayNotHasKey('untranslated', $this->message($this->en, 'en'));
        $this->assertNotSame($before, $this->message($this->de)['updated_at'], 'erscheint wieder, wo sie ausgeblendet war');
        $this->data($this->call($this->admin, 'PATCH', "/board/$id", ['translations' => ['en' => ['title' => 'No practice']]]));
        $history = $this->data($this->call($this->admin, 'GET', "/board/$id/history"));
        $tr = array_values(array_filter($history['revisions'], static fn (array $r): bool => $r['action'] === 'translation'));
        $this->assertCount(2, $tr, 'beim Schreiben und beim Ändern, nicht bei gleicher Fassung');
        $this->assertSame('en', $tr[1]['after']['locale']);
        $this->assertStringContainsString('Closed', (string) json_encode($tr[1]['after']));
        $this->assertSame(400, $this->call($this->admin, 'PATCH', "/board/$id", ['translations' => ['de' => ['title' => 'X']]])->status, 'Deutsch steht, wo es ist');
        $this->assertSame(403, $this->call($this->de, 'PATCH', "/board/$id", ['translations' => ['en' => ['title' => 'X']]])->status);

        // Leerer Titel: wieder der deutsche
        $this->data($this->call($this->admin, 'PATCH', "/board/$id", ['translations' => ['en' => ['title' => '']]]));
        $this->assertSame('Training fällt aus', $this->message($this->en, 'en')['title']);
    }
}
