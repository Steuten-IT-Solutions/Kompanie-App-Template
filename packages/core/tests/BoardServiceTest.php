<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\BoardService;
use KompanieApp\Core\Domain\UserNames;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Push\PushService;
use KompanieApp\Core\Push\WebPushCrypto;
use KompanieApp\Core\Storage\Backup;
use KompanieApp\Core\Tests\Support\FakeHttpClient;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\NullScheduler;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** Schwarzes Brett (Issue #5): Rechte, Prüfung, 24 Stunden gültig, Aufräumen, Benachrichtigungen. */
final class BoardServiceTest extends TestCase
{
    private FakeUsers $users;
    private FixedClock $clock;
    private FakeHttpClient $http;
    private NullScheduler $scheduler;
    private App $app;
    private int $admin;
    private int $m;
    private int $cand;
    private int $guest;

    /** @param array<string, bool> $features */
    private function setUpApp(array $features = ['board' => true]): void
    {
        $this->users = new FakeUsers();
        $this->clock = new FixedClock(new \DateTimeImmutable('2026-10-20 12:00:00', new \DateTimeZone('Europe/Berlin')));
        $this->http = new FakeHttpClient(static fn (): array => ['status' => 201, 'body' => '']);
        $this->scheduler = new NullScheduler();
        $off = array_fill_keys(['events', 'calendar', 'news', 'gallery', 'points', 'penalties', 'ranks', 'titles', 'attendance', 'documents', 'board'], false);
        $this->app = TestApp::create(TestApp::connection(), $this->users, [], $this->http, null, null, $this->clock, $features + $off, [], $this->scheduler);
        $this->app->scheduleTasks();
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->m = $this->users->add('mitglied', Roles::MEMBER);
        $this->cand = $this->users->add('anwaerter', TestApp::candidateRole($this->app));
        $this->guest = $this->users->add('gast', Roles::GUEST);
        $this->users->current = $this->admin;
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

    /** @return array<string, mixed> */
    private function post(string $text): array
    {
        $res = $this->call('POST', '/board', ['text' => $text]);
        $this->assertSame(201, $res->status, (string) json_encode($res->data));
        return $res->data;
    }

    private function move(string $modifier): void
    {
        $this->clock->at = $this->clock->now()->modify($modifier);
    }

    public function testRightsPerRole(): void
    {
        $this->setUpApp();
        $this->assertSame(401, $this->as(null)->call('GET', '/board')->status, 'nicht angemeldet');
        $this->assertSame(403, $this->as($this->guest)->call('GET', '/board')->status, 'Gast-Konto');
        $this->assertSame(403, $this->call('POST', '/board', ['text' => 'x'])->status);
        $this->assertSame(200, $this->as($this->m)->call('GET', '/board')->status, 'Mitglied liest');
        $this->assertSame(403, $this->call('POST', '/board', ['text' => 'x'])->status, 'Mitglied ohne Recht schreibt nicht');
        $this->assertSame(200, $this->as($this->cand)->call('GET', '/board')->status, 'Anwärter liest');
        $this->assertSame(403, $this->call('POST', '/board', ['text' => 'x'])->status);

        // Standardrolle des Bereichs („Schwarzes Brett verwalten“: member + post_board)
        $roles = array_column($this->app->roles()->describe(), 'capabilities', 'key');
        $this->assertSame(['member', 'post_board'], $roles['board'] ?? null);
        $poster = $this->users->add('schreiber', 'board');
        $item = $this->as($poster)->post('Treffen fällt aus');
        $this->assertSame(['kind' => 'message', 'text' => 'Treffen fällt aus', 'author_name' => 'Schreiber'], array_intersect_key($item, array_flip(['kind', 'text', 'author_name'])));

        // Ändern und löschen darf jeder mit dem Recht, nicht nur, wer sie geschrieben hat.
        $this->assertSame('Treffen findet statt', $this->as($this->admin)->call('PATCH', "/board/{$item['id']}", ['text' => 'Treffen findet statt'])->data['text']);
        $this->assertSame(403, $this->as($this->m)->call('PATCH', "/board/{$item['id']}", ['text' => 'x'])->status);
        $this->assertSame(403, $this->call('DELETE', "/board/{$item['id']}")->status);
        $this->assertSame(200, $this->as($poster)->call('DELETE', "/board/{$item['id']}")->status, 'vorzeitig löschen');
        $this->assertSame([], $this->as($this->m)->call('GET', '/board')->data['items']);
        $this->assertSame(404, $this->as($this->admin)->call('DELETE', "/board/{$item['id']}")->status);
    }

    public function testFeatureOff(): void
    {
        $this->setUpApp(['board' => false]);
        foreach ([['GET', '/board'], ['POST', '/board'], ['PATCH', '/board/1'], ['DELETE', '/board/1']] as [$method, $path]) {
            $res = $this->call($method, $path, ['text' => 'x']);
            $this->assertSame([403, 'feature_disabled'], [$res->status, $res->data['code']], "$method $path");
        }
        $this->assertArrayNotHasKey(BoardService::TASK, $this->scheduler->scheduled, 'Aufräumen nur mit Bereich');
        $this->assertNotContains('board', $this->app->push()->availableTypes($this->users->find($this->m)));
        $this->assertArrayNotHasKey('board', array_column($this->app->roles()->describe(), 'key', 'key'), 'Standardrolle erst beim Einschalten');
    }

    public function testValidation(): void
    {
        $this->setUpApp();
        foreach (['leer' => '', 'nur Leerraum' => " \n ", 'zu lang' => str_repeat('x', 501), 'kein Text' => ['x']] as $name => $text) {
            $this->assertSame(400, $this->call('POST', '/board', ['text' => $text])->status, $name);
        }
        $this->assertSame(400, $this->call('POST', '/board')->status, 'ohne Text');
        $this->assertSame(500, mb_strlen($this->post(str_repeat('ä', 500))['text']), '500 Zeichen gehen');
        $item = $this->post("Zeile 1\r\n\r\nZeile <b>2</b> <script>x</script>\n  https://example.org  ");
        $this->assertSame("Zeile 1\n\nZeile 2 x\nhttps://example.org", $item['text'], 'Zeilenumbrüche bleiben, kein HTML');
        $this->assertSame(400, $this->call('PATCH', "/board/{$item['id']}", ['text' => ''])->status);
        $this->assertSame(404, $this->call('PATCH', '/board/999', ['text' => 'x'])->status);
    }

    public function testValidFor24HoursAndCleanup(): void
    {
        $this->setUpApp();
        $a = $this->post('Erste');
        $this->move('+1 hour');
        $b = $this->post('Zweite');
        $this->assertSame('2026-10-20T10:00:00+00:00', $a['created_at']);
        $this->assertSame('2026-10-21T10:00:00+00:00', $a['expires_at'], 'genau 24 Stunden');
        $items = $this->as($this->cand)->call('GET', '/board')->data['items'];
        $this->assertSame(['Zweite', 'Erste'], array_column($items, 'text'), 'neueste zuerst');

        // Ändern: Ablauf bleibt, updated_at wechselt; unveränderter Text ändert nichts.
        $this->move('+30 minutes');
        $edited = $this->as($this->admin)->call('PATCH', "/board/{$a['id']}", ['text' => 'Erste (geändert)'])->data;
        $this->assertSame([$a['expires_at'], $a['created_at'], '2026-10-20T11:30:00+00:00'], [$edited['expires_at'], $edited['created_at'], $edited['updated_at']]);
        $this->move('+1 minute');
        $this->assertSame($edited['updated_at'], $this->call('PATCH', "/board/{$a['id']}", ['text' => 'Erste (geändert)'])->data['updated_at']);

        // Zweimal in derselben Sekunde geändert (Issue #68): updated_at wird trotzdem später, sonst bliebe eine auf dem
        // Gerät ausgeblendete Fassung ausgeblendet.
        $first = $this->call('PATCH', "/board/{$a['id']}", ['text' => 'Erste (noch einmal)'])->data['updated_at'];
        $second = $this->call('PATCH', "/board/{$a['id']}", ['text' => 'Erste (und noch einmal)'])->data['updated_at'];
        $this->assertSame(['2026-10-20T11:31:00+00:00', '2026-10-20T11:31:01+00:00'], [$first, $second]);
        $this->assertSame('2026-10-20T11:31:02+00:00', $this->call('PATCH', "/board/{$a['id']}", ['text' => 'Erste (geändert)'])->data['updated_at'], 'auch vor der Uhr: immer eine Sekunde weiter');
        $this->move('+1 minute');
        $this->assertSame('2026-10-20T11:32:00+00:00', $this->call('PATCH', "/board/{$a['id']}", ['text' => 'Erste (wieder)'])->data['updated_at'], 'danach wieder die Uhrzeit');

        // Nach 24 Stunden: nicht mehr geliefert, nicht mehr änderbar; die Aufgabe löscht sie.
        $this->clock->at = new \DateTimeImmutable('2026-10-21 12:00:00', new \DateTimeZone('Europe/Berlin'));
        $this->assertSame(['Zweite'], array_column($this->call('GET', '/board')->data['items'], 'text'));
        $this->assertSame(404, $this->call('PATCH', "/board/{$a['id']}", ['text' => 'zu spät'])->status);
        $this->assertSame(404, $this->call('DELETE', "/board/{$a['id']}")->status);
        $this->assertSame(2, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {board_messages}'), 'noch in der Tabelle');
        $this->assertSame(3600, $this->scheduler->scheduled[BoardService::TASK] ?? null, 'Aufgabe eingeplant');
        $this->app->runTask(BoardService::TASK);
        $this->assertSame([$b['id']], array_map('intval', array_column($this->app->db->select('SELECT id FROM {board_messages}'), 'id')), 'Abgelaufene gelöscht, kein Verlauf');
        $this->move('+2 hours');
        $this->app->runTask(BoardService::TASK);
        $this->assertSame(0, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {board_messages}'));
    }

    public function testDeletedAuthorAndBackup(): void
    {
        $this->setUpApp();
        $poster = $this->users->add('schreiber', 'board');
        $this->as($poster)->post('Bitte Uniform mitbringen');
        unset($this->users->users[$poster]);
        $this->app->userDeleted($poster);
        $items = $this->as($this->m)->call('GET', '/board')->data['items'];
        $this->assertSame([UserNames::DELETED], array_column($items, 'author_name'), 'Nachricht bleibt, Verfasser „gelöschtes Konto“');
        $this->assertContains('board_messages', Backup::CORE_TABLES);
        $this->assertCount(1, (new Backup($this->app->db))->dump(['board_messages'])['board_messages']);
    }

    /** @return array{endpoint: string, keys: array{p256dh: string, auth: string}} */
    private static function device(string $name): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        return [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/' . $name,
            'keys' => ['p256dh' => WebPushCrypto::b64uEncode(WebPushCrypto::rawPublic($key)), 'auth' => WebPushCrypto::b64uEncode(random_bytes(16))],
        ];
    }

    /** @return list<array{url: string, headers: array<string, string>}> */
    private function pushes(): array
    {
        return array_values(array_filter($this->http->calls, static fn (array $c): bool => str_starts_with($c['url'], 'https://fcm.googleapis.com/fcm/send/')));
    }

    /** @param list<string>|null $types */
    private function subscribe(?int $user, string $device, ?array $types = null): void
    {
        $d = self::device($device);
        $this->app->push()->subscribe($user === null ? null : $this->users->find($user), $d['endpoint'], $d['keys'], $types, null, '1.2.3.4');
    }

    public function testPushToRecipientsExceptAuthor(): void
    {
        $this->setUpApp(['board' => true, 'events' => true]);
        if (!$this->app->push()->supported()) {
            $this->markTestSkipped('ohne Push-Unterstützung');
        }
        $off = $this->users->add('ohne', Roles::MEMBER);
        $this->subscribe($this->m, 'm-handy');
        $this->subscribe($this->m, 'm-tablet');
        $this->subscribe($this->cand, 'anwaerter');
        $this->subscribe($this->admin, 'admin');
        $this->subscribe($this->guest, 'gast-konto');
        $this->subscribe(null, 'gast');
        $this->subscribe($off, 'ohne-brett', ['events']);
        $this->assertContains('board', $this->app->push()->availableTypes($this->users->find($this->cand)), 'Anwärter können die Art abonnieren');
        $this->assertNotContains('board', $this->app->push()->availableTypes($this->users->find($this->guest)));
        $this->assertNotContains('board', $this->app->push()->availableTypes(null));

        $item = $this->as($this->admin)->post("Heute kein Training!\nHalle gesperrt.");
        $queue = $this->app->db->select('SELECT q.urgency, q.ttl, s.endpoint FROM {push_queue} q JOIN {push_subscriptions} s ON s.id = q.subscription_id ORDER BY q.id');
        $this->assertSame(
            ['https://fcm.googleapis.com/fcm/send/m-handy', 'https://fcm.googleapis.com/fcm/send/m-tablet', 'https://fcm.googleapis.com/fcm/send/anwaerter'],
            array_column($queue, 'endpoint'),
            'je Gerät der Empfänger eine Nachricht, nicht an den Verfasser, Gäste oder Geräte ohne die Art',
        );
        $this->assertSame(['high'], array_values(array_unique(array_column($queue, 'urgency'))));
        $this->assertSame([86400], array_values(array_unique(array_map('intval', array_column($queue, 'ttl')))));
        $this->assertContains(PushService::TASK_SEND, $this->scheduler->soon, 'Versand sofort angestoßen');

        // Eine Stunde später verschickt: TTL höchstens bis zum Ablauf der Nachricht.
        $this->move('+1 hour');
        $this->assertSame(3, $this->app->push()->processQueue());
        foreach ($this->pushes() as $p) {
            $this->assertSame('high', $p['headers']['Urgency']);
            $this->assertLessThanOrEqual(23 * 3600, (int) $p['headers']['TTL']);
        }

        // Ohne Haken „Benachrichtigung senden“: veröffentlicht, aber kein Push; push nur als true/false.
        $queued = $this->app->push()->repository()->counts()['queued'];
        $this->assertSame(201, $this->call('POST', '/board', ['text' => 'Leise Nachricht', 'push' => false])->status);
        $this->assertSame($queued, $this->app->push()->repository()->counts()['queued'], 'kein Push mit push=false');
        $this->assertSame(400, $this->call('POST', '/board', ['text' => 'x', 'push' => 'nein'])->status);

        // Ändern verschickt nichts.
        $this->http->calls = [];
        $this->call('PATCH', "/board/{$item['id']}", ['text' => 'Doch Training']);
        $this->assertSame($queued, $this->app->push()->repository()->counts()['queued'], 'kein Push beim Ändern');

        // Schreibt jemand anderes mit dem Recht, bekommt auch der Admin eine Benachrichtigung.
        $poster = $this->users->add('schreiber', 'board');
        $this->as($poster)->post('Zweite');
        $this->assertSame(4, $this->app->push()->repository()->counts()['queued'], 'jetzt auch der Admin, nicht der Verfasser');

        // Art „Schwarzes Brett“ ausgeschaltet: kein Push.
        $this->app->db->execute('DELETE FROM {push_queue}');
        foreach ($this->app->push()->repository()->all() as $sub) {
            if ($sub['user_id'] > 0) {
                $this->app->push()->repository()->update($sub['id'], ['types' => array_values(array_diff($sub['types'], ['board'])) ?: ['events']], $this->app->time()->nowUtc());
            }
        }
        $this->as($this->admin)->post('Dritte');
        $this->assertSame(0, $this->app->push()->repository()->counts()['queued'], 'Art abbestellt');
    }

    public function testTtlNeverLongerThanTheMessage(): void
    {
        $this->setUpApp();
        if (!$this->app->push()->supported()) {
            $this->markTestSkipped('ohne Push-Unterstützung');
        }
        $this->subscribe($this->m, 'm');
        $this->assertSame(1, $this->app->pushTriggers()->boardPosted(['id' => 1, 'text' => 'x', 'author_name' => 'A', 'expires_at' => $this->clock->now()->modify('+2 hours')->format('c')], $this->admin));
        $this->assertSame(7200, (int) $this->app->db->scalar('SELECT ttl FROM {push_queue}'));
        $this->assertSame('brett/1', json_decode((string) $this->app->db->scalar('SELECT payload FROM {push_queue}'), true)['route'], 'führt zur Nachricht (Issue #190)');
        $this->assertSame(0, $this->app->pushTriggers()->boardPosted(['id' => 2, 'text' => 'x', 'author_name' => 'A', 'expires_at' => $this->clock->now()->modify('-1 minute')->format('c')], $this->admin), 'abgelaufen: nichts');
        $this->app->push()->processQueue();
        $msg = $this->pushes()[0];
        $this->assertSame('7200', $msg['headers']['TTL']);
    }

    public function testExistingSubscriptionsGetTheTypeOnFirstSwitchOn(): void
    {
        $this->setUpApp(['board' => false, 'events' => true]);
        if (!$this->app->push()->supported()) {
            $this->markTestSkipped('ohne Push-Unterstützung');
        }
        $this->subscribe($this->m, 'm');
        $this->subscribe($this->cand, 'a');
        $this->subscribe(null, 'gast');
        $types = fn (): array => array_column($this->app->push()->repository()->all(), 'types', 'endpoint');
        $this->assertSame(['events', 'reminder'], $types()['https://fcm.googleapis.com/fcm/send/m']);

        $this->app->features()->update(['board' => true]);
        $this->assertSame(['board', 'events', 'reminder'], $types()['https://fcm.googleapis.com/fcm/send/m'], 'beim ersten Einschalten dazu');
        $this->assertSame(['board', 'events', 'reminder'], $types()['https://fcm.googleapis.com/fcm/send/a'], 'auch Anwärter');
        $this->assertSame(['events', 'reminder'], $types()['https://fcm.googleapis.com/fcm/send/gast'], 'Gäste nicht');

        // Danach entscheidet das Gerät: Aus- und wieder Einschalten des Bereichs ändert die Abos nicht mehr.
        $this->app->push()->update($this->users->find($this->m), 'https://fcm.googleapis.com/fcm/send/m', null, ['events'], null);
        $this->app->features()->update(['board' => false]);
        $this->app->features()->update(['board' => true]);
        $this->assertSame(['events'], $types()['https://fcm.googleapis.com/fcm/send/m']);
    }
}
