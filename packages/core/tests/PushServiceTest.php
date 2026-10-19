<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Port\NewsSource;
use KompanieApp\Core\Push\PushService;
use KompanieApp\Core\Push\WebPushCrypto;
use KompanieApp\Core\Tests\Support\FakeHttpClient;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\MemorySettings;
use KompanieApp\Core\Tests\Support\NullScheduler;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** Web Push (Issue #19): Typen je Bereich, Abos, Gäste, Warteschlange, Versand, Auslöser. */
final class PushServiceTest extends TestCase
{
    private FakeUsers $users;
    private FakeHttpClient $http;
    private FixedClock $clock;
    private MemorySettings $settings;
    private NullScheduler $scheduler;
    /** Antwort der Push-Dienste je Endpoint (Status), sonst 201 */
    private array $pushStatus = [];
    /** Inhalt des iCal-Feeds */
    private string $ics = '';
    /** Neuigkeiten der Quelle: [Titel, Datum], neueste zuerst */
    private array $feed = [];

    /** @return list<array{0: string, 1: string}> */
    public function feed(): array
    {
        return $this->feed;
    }

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->clock = new FixedClock(new \DateTimeImmutable('2026-10-19 10:00:00', new \DateTimeZone('Europe/Berlin')));
        $this->settings = new MemorySettings(['calendar_ics_url' => 'https://example.org/k.ics']);
        $this->scheduler = new NullScheduler();
        $this->http = new FakeHttpClient(function (string $method, string $url) {
            if (str_ends_with($url, '.ics')) {
                return ['status' => 200, 'body' => $this->ics];
            }
            return ['status' => $this->pushStatus[$url] ?? 201, 'body' => ''];
        });
    }

    /** @param array<string, bool>|null $features */
    private function app(?array $features = null): App
    {
        $app = TestApp::create(TestApp::connection(), $this->users, [], $this->http, null, $this->settings, $this->clock, $features, [], $this->scheduler);
        $app->scheduleTasks();
        return $app;
    }

    /** @return array{endpoint: string, keys: array{p256dh: string, auth: string}, key: \OpenSSLAsymmetricKey, auth: string} */
    private static function device(string $name): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        $auth = random_bytes(16);
        return [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/send/' . $name,
            'keys' => ['p256dh' => WebPushCrypto::b64uEncode(WebPushCrypto::rawPublic($key)), 'auth' => WebPushCrypto::b64uEncode($auth)],
            'key' => $key,
            'auth' => $auth,
        ];
    }

    /** @return array<int, array{url: string, body: ?string, headers: array}> Anfragen an Push-Dienste */
    private function pushes(): array
    {
        return array_values(array_filter($this->http->calls, static fn (array $c): bool => str_starts_with($c['url'], 'https://fcm.googleapis.com/fcm/send/')));
    }

    /** Entschlüsselt den Inhalt einer gesendeten Nachricht als Gerät. */
    private static function decrypt(string $body, array $device): array
    {
        $salt = substr($body, 0, 16);
        $idlen = ord($body[20]);
        $asPublic = substr($body, 21, $idlen);
        $data = substr($body, 21 + $idlen);
        $ua = WebPushCrypto::rawPublic($device['key']);
        $ecdh = (string) openssl_pkey_derive(self::key($asPublic), $device['key'], 32);
        $prkKey = hash_hmac('sha256', $ecdh, $device['auth'], true);
        $ikm = hash_hmac('sha256', "WebPush: info\0" . $ua . $asPublic . "\x01", $prkKey, true);
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);
        $plain = (string) openssl_decrypt(substr($data, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($data, -16));
        return (array) json_decode(rtrim($plain, "\x02"), true);
    }

    private static function key(string $raw): \OpenSSLAsymmetricKey
    {
        $key = WebPushCrypto::publicKeyFromRaw($raw);
        self::assertNotNull($key);
        return $key;
    }

    public function testTypesFollowFeaturesAndRoles(): void
    {
        $app = $this->app();
        $member = $this->users->find($this->users->add('m', Roles::MEMBER));
        $guest = $this->users->find($this->users->add('g', Roles::GUEST));
        $p = $app->push();
        $this->assertSame(['penalties', 'points', 'events', 'reminder', 'news'], $p->availableTypes($member));
        $this->assertSame(['events', 'reminder', 'news'], $p->availableTypes(null), 'Gäste ohne Konto: nur Öffentliches');
        $this->assertSame(['events', 'reminder', 'news'], $p->availableTypes($guest), 'Konto ohne Mitgliederbereich');

        $app->features()->update(['penalties' => false, 'news' => false, 'events' => false]);
        $this->assertSame(['points', 'events', 'reminder'], $p->availableTypes($member), 'Termine aus dem Kalender');
        $this->assertSame([], $p->availableTypes(null), 'ohne öffentliche Termine und News nichts für Gäste');
        $this->assertSame(0, $p->notifyAll('news', PushService::message('x', 'y', 'news')), 'ausgeschalteter Bereich: kein Versand');
    }

    public function testSubscribeValidatesAndGuestsNeedTheirSecret(): void
    {
        $app = $this->app();
        $p = $app->push();
        $d = self::device('a');
        $sub = $p->subscribe(null, $d['endpoint'], $d['keys'], null, null, '1.2.3.4');
        $this->assertSame(['events', 'reminder', 'news'], $sub['types']);

        foreach ([
            'kein https' => ['http://fcm.googleapis.com/fcm/send/x', $d['keys']],
            'fremder Server' => ['https://intern.example.test/x', $d['keys']],
            'ähnlicher Name' => ['https://evilfcm.googleapis.com.example.test/x', $d['keys']],
            'eigener Port' => ['https://fcm.googleapis.com:8443/x', $d['keys']],
            'kaputter Schlüssel' => ['https://fcm.googleapis.com/fcm/send/b', ['p256dh' => WebPushCrypto::b64uEncode(str_repeat("\x04", 65)), 'auth' => $d['keys']['auth']]],
            'auth zu kurz' => ['https://fcm.googleapis.com/fcm/send/c', ['p256dh' => $d['keys']['p256dh'], 'auth' => 'abc']],
        ] as $name => [$endpoint, $keys]) {
            try {
                $p->subscribe(null, $endpoint, $keys, null, null, '1.2.3.4');
                $this->fail($name);
            } catch (ApiError $e) {
                $this->assertSame(400, $e->status, $name);
            }
        }

        // Gäste ändern und löschen nur mit dem auth-Geheimnis des Geräts.
        try {
            $p->update(null, $d['endpoint'], WebPushCrypto::b64uEncode(random_bytes(16)), ['news'], null);
            $this->fail('falsches Geheimnis');
        } catch (ApiError $e) {
            $this->assertSame(404, $e->status);
        }
        $this->assertSame(['news'], $p->update(null, $d['endpoint'], $d['keys']['auth'], ['news', 'penalties'], null)['types'], 'nur erlaubte Typen');
        $this->assertFalse($p->unsubscribe(null, $d['endpoint'], WebPushCrypto::b64uEncode(random_bytes(16))));
        $this->assertTrue($p->unsubscribe(null, $d['endpoint'], $d['keys']['auth']));
    }

    public function testDeviceMovesToAnotherAccountOnlyWithItsSecret(): void
    {
        $app = $this->app();
        $p = $app->push();
        $anna = $this->users->find($this->users->add('anna', Roles::MEMBER));
        $max = $this->users->find($this->users->add('max', Roles::MEMBER));
        $d = self::device('shared');
        $p->subscribe($anna, $d['endpoint'], $d['keys'], ['points'], true, '');
        $other = self::device('other');
        try {
            $p->subscribe($max, $d['endpoint'], $other['keys'], null, null, '');
            $this->fail('fremdes Gerät übernimmt das Abo');
        } catch (ApiError $e) {
            $this->assertSame(403, $e->status);
        }
        $sub = $p->subscribe($max, $d['endpoint'], $d['keys'], null, null, '');
        $this->assertSame($max->id, $sub['user_id'], 'dasselbe Gerät, anderes Konto');
        $this->assertFalse($sub['details'], 'Einstellungen des vorigen Kontos werden nicht übernommen');
    }

    public function testGuestLimit(): void
    {
        $p = $this->app()->push();
        for ($i = 0; $i < PushService::GUEST_RATE; $i++) {
            $d = self::device('g' . $i);
            $p->subscribe(null, $d['endpoint'], $d['keys'], null, null, '9.9.9.9');
        }
        $d = self::device('zu-viel');
        try {
            $p->subscribe(null, $d['endpoint'], $d['keys'], null, null, '9.9.9.9');
            $this->fail('Begrenzung greift nicht');
        } catch (ApiError $e) {
            $this->assertSame(429, $e->status);
        }
        $this->assertSame('too_many_attempts', $e->errorCode);
        $p->subscribe(null, $d['endpoint'], $d['keys'], null, null, '8.8.8.8');
        $this->assertSame(PushService::GUEST_RATE + 1, $p->repository()->countGuests(), 'andere Adresse geht');

        // IPv6: ein /64-Netz zählt als ein Anfragender.
        for ($i = 0; $i < PushService::GUEST_RATE; $i++) {
            $d = self::device('v6-' . $i);
            $p->subscribe(null, $d['endpoint'], $d['keys'], null, null, '2001:db8:1:2::' . dechex($i + 1));
        }
        $d = self::device('v6-zu-viel');
        try {
            $p->subscribe(null, $d['endpoint'], $d['keys'], null, null, '2001:db8:1:2:ffff::1');
            $this->fail('Begrenzung für IPv6 greift nicht');
        } catch (ApiError $e) {
            $this->assertSame(429, $e->status);
        }
    }

    public function testGuestRulesStaleAccountsAndExpiry(): void
    {
        $app = $this->app();
        $p = $app->push();
        $d = self::device('a');
        foreach ([[], ['points']] as $types) {
            try {
                $p->subscribe(null, $d['endpoint'], $d['keys'], $types, null, '1.1.1.1');
                $this->fail('ohne Arten angenommen');
            } catch (ApiError $e) {
                $this->assertSame(400, $e->status, 'Abo ohne (erlaubte) Arten');
            }
        }
        try {
            $p->subscribe(null, 'https://fcm.googleapis.com\\@intern.example.test/x', $d['keys'], null, null, '1.1.1.1');
            $this->fail('Adresse mit Sonderzeichen angenommen');
        } catch (ApiError $e) {
            $this->assertSame(400, $e->status);
        }

        // Konto nicht mehr angemeldet: Das Gerät (mit seinem Geheimnis) gibt das Abo frei, ohne Geheimnis nicht.
        $m = $this->users->find($this->users->add('m', Roles::MEMBER));
        $p->subscribe($m, $d['endpoint'], $d['keys'], null, null, '');
        $this->assertFalse($p->releaseForeign(null, $d['endpoint'], self::device('x')['keys']['auth']));
        $this->assertFalse($p->releaseForeign($m, $d['endpoint'], $d['keys']['auth']), 'noch angemeldet');
        $this->assertTrue($p->releaseForeign(null, $d['endpoint'], $d['keys']['auth']));
        $this->assertNull($p->repository()->byEndpoint($d['endpoint']));

        // Gast-Abos ohne Änderung und ohne Nachricht seit 180 Tagen verfallen; Konten nicht.
        $p->subscribe(null, $d['endpoint'], $d['keys'], null, null, '1.1.1.1');
        $k = self::device('k');
        $p->subscribe($m, $k['endpoint'], $k['keys'], null, null, '');
        $this->clock->at = $this->clock->now()->modify('+179 days');
        $this->assertSame(0, $p->expireGuests());
        $this->clock->at = $this->clock->now()->modify('+2 days');
        $this->assertSame(1, $p->expireGuests());
        $this->assertNotNull($p->repository()->byEndpoint($k['endpoint']));
    }

    public function testNewsOnlyGenuinelyNewPosts(): void
    {
        $test = $this;
        $news = new class ($test) implements NewsSource {
            public function __construct(private readonly PushServiceTest $test)
            {
            }

            public function available(): bool
            {
                return true;
            }

            public function page(int $page, int $perPage): array
            {
                $items = array_map(static fn (array $i): array => ['id' => $i[0], 'title' => $i[0], 'date' => gmdate('c', (int) strtotime($i[1]))], $this->test->feed());
                return ['items' => array_slice($items, 0, $perPage), 'totalPages' => 1];
            }

            public function item(string $id): ?array
            {
                return null;
            }
        };
        $app = TestApp::create(TestApp::connection(), $this->users, [], $this->http, null, $this->settings, $this->clock, null, [], $this->scheduler, $news);
        $g = self::device('g');
        $app->push()->subscribe(null, $g['endpoint'], $g['keys'], ['news'], null, '1.1.1.1');
        $t = $app->pushTriggers();
        $check = static fn (): int => $t->checkNews();
        $this->feed = [['B', 'Mon, 02 Mar 2026 10:00:00 +0100'], ['A', 'Sun, 01 Mar 2026 10:00:00 +0100'], ['Alt', 'Sun, 01 Feb 2026 10:00:00 +0100']];
        $this->assertSame(0, $check(), 'erster Lauf: nur merken');
        array_unshift($this->feed, ['C', 'Thu, 05 Mar 2026 10:00:00 +0100']);
        $this->assertSame(1, $check());
        $this->assertSame(0, $check(), 'nicht doppelt');
        // C gelöscht, ein älterer Beitrag rückt nach: nicht neu.
        array_shift($this->feed);
        $this->feed[] = ['Uralt', 'Thu, 01 Jan 2026 10:00:00 +0100'];
        $this->assertSame(0, $check());
        // Viele auf einmal: eine Zusammenfassung.
        foreach (['D', 'E', 'F', 'G'] as $n => $title) {
            array_unshift($this->feed, [$title, sprintf('Sun, %02d Mar 2026 10:00:00 +0100', 10 + $n)]);
        }
        $this->assertSame(1, $check());
        $app->push()->repository()->dequeue(array_column($app->push()->repository()->claim('9999-12-31 00:00:00', '9999-12-31 00:00:00', 100), 'id'));
    }

    public function testMembersWithUnconfiguredCalendarGetPublicEvents(): void
    {
        $this->settings = new MemorySettings([]);
        $app = $this->app();
        $m = $this->users->find($this->users->add('m', Roles::MEMBER));
        $this->assertFalse($app->push()->usesCalendar($m), 'Kalender an, aber ohne Adresse');
    }

    public function testQueueSendsEncryptedSignedMessagesAndCleansUp(): void
    {
        $app = $this->app();
        $p = $app->push();
        $m = $this->users->find($this->users->add('m', Roles::MEMBER));
        $a = self::device('a');
        $b = self::device('b');
        $p->subscribe($m, $a['endpoint'], $a['keys'], null, true, '');
        $p->subscribe($m, $b['endpoint'], $b['keys'], ['points'], false, '');

        $app->pushTriggers()->penaltiesAdded([['id' => 7, 'user_id' => $m->id, 'amount_cents' => 250, 'reason' => 'Zu spät']]);
        $this->assertSame(1, $p->repository()->counts()['queued'], 'nur das Gerät mit dem Typ');
        $this->assertContains(PushService::TASK_SEND, $this->scheduler->soon, 'Versand vorgezogen');
        $this->assertSame(1, $p->processQueue());
        $sent = $this->pushes();
        $this->assertCount(1, $sent);
        $this->assertSame('aes128gcm', $sent[0]['headers']['Content-Encoding']);
        $this->assertSame('86400', $sent[0]['headers']['TTL']);
        $this->assertMatchesRegularExpression('/^vapid t=[^.]+\.[^.]+\.[^,]+, k=[A-Za-z0-9_-]{87}$/', $sent[0]['headers']['Authorization']);
        $msg = self::decrypt((string) $sent[0]['body'], $a);
        $this->assertSame('meins/strafen', $msg['route']);
        $this->assertSame('2,50 € – Zu spät', $msg['body'], 'Details eingeschaltet');
        $this->assertSame(0, $p->repository()->counts()['queued']);

        // 410: Abo weg; 503: später erneut, nach drei Versuchen verworfen.
        $this->pushStatus[$a['endpoint']] = 410;
        $this->pushStatus[$b['endpoint']] = 503;
        $app->pushTriggers()->pointsAdded([['id' => 9, 'user_id' => $m->id, 'points' => 2.5, 'reason' => 'Sommerfest']]);
        $p->processQueue();
        $this->assertNull($p->repository()->byEndpoint($a['endpoint']));
        $this->assertSame(1, $p->repository()->counts()['queued']);
        for ($i = 0; $i < 3; $i++) {
            $this->clock->at = $this->clock->now()->modify('+20 minutes');
            $p->processQueue();
        }
        $this->assertSame(0, $p->repository()->counts()['queued'], 'nach drei Versuchen verworfen');
        $this->assertNotNull($p->repository()->byEndpoint($b['endpoint']), '5xx löscht das Abo nicht');
    }

    public function testClaimedMessagesAreNotSentTwiceAndOldOnesExpire(): void
    {
        $app = $this->app();
        $p = $app->push();
        $m = $this->users->find($this->users->add('m', Roles::MEMBER));
        $a = self::device('a');
        $p->subscribe($m, $a['endpoint'], $a['keys'], ['points'], null, '');
        $app->pushTriggers()->pointsAdded([['id' => 1, 'user_id' => $m->id, 'points' => 1, 'reason' => 'x']]);

        // Ein anderer Lauf hat die Nachricht gerade beansprucht: Dieser sendet sie nicht noch einmal.
        $now = gmdate('Y-m-d H:i:s', $this->clock->now()->getTimestamp());
        $this->assertCount(1, $p->repository()->claim($now, gmdate('Y-m-d H:i:s', $this->clock->now()->getTimestamp() + 600), 10));
        $this->assertSame([], $p->repository()->claim($now, $now, 10), 'schon beansprucht');
        $this->assertSame(0, $p->processQueue());
        $this->assertSame([], $this->pushes());
        // Bricht der Lauf ab, ist sie nach der Sperre wieder fällig; die Lebensdauer zählt ab dem Einreihen.
        $this->clock->at = $this->clock->now()->modify('+11 minutes');
        $this->assertSame(1, $p->processQueue());
        $this->assertSame((string) (86400 - 660), $this->pushes()[0]['headers']['TTL']);

        // Zu alt (länger als die Lebensdauer in der Warteschlange): verworfen statt gesendet.
        $app->pushTriggers()->pointsAdded([['id' => 2, 'user_id' => $m->id, 'points' => 1, 'reason' => 'y']]);
        $this->clock->at = $this->clock->now()->modify('+25 hours');
        $this->assertSame(0, $p->processQueue());
        $this->assertCount(1, $this->pushes());
        $this->assertSame(0, $p->repository()->counts()['queued']);

        // FCM mit 403 (Abo gehört zu einem anderen Serverschlüssel): Abo entfernt.
        $this->pushStatus[$a['endpoint']] = 403;
        $app->pushTriggers()->pointsAdded([['id' => 3, 'user_id' => $m->id, 'points' => 1, 'reason' => 'z']]);
        $p->processQueue();
        $this->assertNull($p->repository()->byEndpoint($a['endpoint']));
    }

    public function testPointsMessageWithoutDetailsSwitch(): void
    {
        $app = $this->app();
        $m = $this->users->find($this->users->add('m', Roles::MEMBER));
        $a = self::device('a');
        $app->push()->subscribe($m, $a['endpoint'], $a['keys'], null, false, '');
        $app->pushTriggers()->penaltiesAdded([['id' => 1, 'user_id' => $m->id, 'amount_cents' => 100, 'reason' => 'Geheim']]);
        $app->pushTriggers()->pointsAdded([['id' => 2, 'user_id' => $m->id, 'points' => -1.5, 'reason' => 'Abzug']]);
        $app->push()->processQueue();
        $bodies = array_map(fn (array $c): array => self::decrypt((string) $c['body'], $a), $this->pushes());
        $this->assertSame('Tippen zum Ansehen.', $bodies[0]['body'], 'Betrag und Grund nur mit Details');
        $this->assertSame('−1,5 – Abzug', $bodies[1]['body']);
        $this->assertSame('Punkte abgezogen', $bodies[1]['title']);
    }

    private function ics(string ...$events): void
    {
        $this->ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n" . implode('', $events) . "END:VCALENDAR\r\n";
    }

    private static function vevent(string $uid, string $start, string $summary, string $location = ''): string
    {
        return "BEGIN:VEVENT\r\nUID:$uid\r\nDTSTART;TZID=Europe/Berlin:{$start}T190000\r\nDTEND;TZID=Europe/Berlin:{$start}T210000\r\nSUMMARY:$summary\r\nLOCATION:$location\r\nEND:VEVENT\r\n";
    }

    public function testCalendarChangesFirstRunSilentThenNewAndChanged(): void
    {
        $app = $this->app();
        $m = $this->users->find($this->users->add('m', Roles::MEMBER));
        $d = self::device('m');
        $g = self::device('g');
        $app->push()->subscribe($m, $d['endpoint'], $d['keys'], ['events'], null, '');
        $app->push()->subscribe(null, $g['endpoint'], $g['keys'], ['events'], null, '1.1.1.1');

        $this->ics(self::vevent('a', '20261101', 'Probe', 'Halle'));
        $this->assertSame(0, $app->pushTriggers()->checkCalendar(), 'erster Lauf: nur merken');
        $app->calendar()->clearCache();
        $this->ics(self::vevent('a', '20261101', 'Probe umbenannt', 'Halle'), self::vevent('b', '20261102', 'Fest'));
        $this->assertSame(1, $app->pushTriggers()->checkCalendar(), 'neuer Termin, Titeländerung zählt nicht; nur an Mitglieder mit Kalender');
        $app->calendar()->clearCache();
        $this->ics(self::vevent('a', '20261101', 'Probe umbenannt', 'Sporthalle'), self::vevent('b', '20261102', 'Fest'));
        $this->assertSame(1, $app->pushTriggers()->checkCalendar(), 'Ort geändert');
        $app->push()->processQueue();
        $titles = array_map(fn (array $c): string => self::decrypt((string) $c['body'], $d)['title'], $this->pushes());
        $this->assertSame(['Neuer Termin', 'Termin geändert'], $titles);
    }

    public function testPublicEventsGoToGuestsAndAccountsWithoutCalendar(): void
    {
        $app = $this->app();
        $m = $this->users->find($this->users->add('m', Roles::MEMBER));
        $d = self::device('m');
        $g = self::device('g');
        $app->push()->subscribe($m, $d['endpoint'], $d['keys'], ['events'], null, '');
        $app->push()->subscribe(null, $g['endpoint'], $g['keys'], ['events'], null, '1.1.1.1');
        $admin = $this->users->add('admin', Roles::ADMIN);
        $this->users->current = $admin;
        $res = $app->api()->handle(new Request('POST', '/events', [], ['title' => 'Sommerfest', 'start_date' => '2026-11-07', 'location' => 'Festplatz']));
        $this->assertSame(201, $res->status);
        $this->assertSame(1, $app->push()->repository()->counts()['queued'], 'nur der Gast (das Mitglied hat den Kalender)');
        $id = $res->data['id'];
        $app->api()->handle(new Request('PATCH', "/events/$id", [], ['title' => 'Sommerfest 2026'], ['id' => (string) $id]));
        $this->assertSame(1, $app->push()->repository()->counts()['queued'], 'nur der Titel geändert: nichts');
        $app->api()->handle(new Request('PATCH', "/events/$id", [], ['start_date' => '2026-11-08'], ['id' => (string) $id]));
        $this->assertSame(2, $app->push()->repository()->counts()['queued'], 'Datum geändert');
        $app->push()->processQueue();
        $msg = self::decrypt((string) $this->pushes()[0]['body'], $g);
        $this->assertSame('Neuer Termin', $msg['title']);
        $this->assertSame('Sommerfest – Sa 07.11.2026 – Festplatz', $msg['body']);
    }

    public function testReminderOncePerDayFromTheConfiguredHour(): void
    {
        $app = $this->app();
        $g = self::device('g');
        $app->push()->subscribe(null, $g['endpoint'], $g['keys'], ['reminder'], null, '1.1.1.1');
        $admin = $this->users->add('admin', Roles::ADMIN);
        $this->users->current = $admin;
        $app->api()->handle(new Request('POST', '/events', [], ['title' => 'Vereinsabend', 'start_date' => '2026-10-20', 'start_time' => '19:00']));
        $app->push()->repository()->dequeue(array_column($app->push()->repository()->claim('9999-12-31 00:00:00', '9999-12-31 00:00:00', 100), 'id'));
        $tz = new \DateTimeZone('Europe/Berlin');
        $t = $app->pushTriggers();
        $this->assertSame(0, $t->sendReminders(new \DateTimeImmutable('2026-10-19 17:59', $tz)), 'vor 18 Uhr nichts');
        $this->assertSame(1, $t->sendReminders(new \DateTimeImmutable('2026-10-19 18:05', $tz)));
        $this->assertSame(0, $t->sendReminders(new \DateTimeImmutable('2026-10-19 19:30', $tz)), 'nur einmal am Tag');
        $app->push()->setReminderHour(20);
        $this->assertSame(0, $t->sendReminders(new \DateTimeImmutable('2026-10-20 19:30', $tz)));
        $app->push()->processQueue();
        $msg = self::decrypt((string) $this->pushes()[0]['body'], $g);
        $this->assertSame('Morgen: Vereinsabend', $msg['title']);
        $this->assertSame('Vereinsabend (19:00 Uhr)', $msg['body']);
    }
}
