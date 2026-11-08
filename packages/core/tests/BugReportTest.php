<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\BugReportService;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Push\PushService;
use KompanieApp\Core\Push\WebPushCrypto;
use KompanieApp\Core\Storage\Backup;
use KompanieApp\Core\Tests\Support\FakeHttpClient;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\MemorySettings;
use KompanieApp\Core\Tests\Support\NullScheduler;
use KompanieApp\Core\Tests\Support\TestApp;
use KompanieApp\Core\Update\StatusReporter;
use PHPUnit\Framework\TestCase;

/**
 * Fehlermeldungen aus der App (Issue #64): Formular, Spamschutz, Eingang der Admins (schließen, weiterleiten),
 * Warteschlange, Versand an das Cockpit, Datenschutz.
 */
final class BugReportTest extends TestCase
{
    private const URL = 'https://status.example.org/reports';

    private FakeHttpClient $http;
    private FixedClock $clock;
    private NullScheduler $scheduler;
    private FakeUsers $users;
    /** @var array<int, array{status: int, body: string}> Antworten des Empfängers, der Reihe nach */
    private array $responses = [];

    protected function setUp(): void
    {
        $this->clock = new FixedClock(new \DateTimeImmutable('2026-10-01 12:00:00 UTC'));
        $this->scheduler = new NullScheduler();
        $this->users = new FakeUsers();
        $this->http = new FakeHttpClient(fn () => array_shift($this->responses) ?? ['status' => 201, 'body' => '{"ok":true}']);
    }

    private function app(string $url = self::URL): App
    {
        $app = TestApp::create(TestApp::connection(), $this->users, http: $this->http, settings: new MemorySettings(), clock: $this->clock,
            packageConfig: ['reportUrl' => $url, 'kompanie' => 'org/kompanie-test'], scheduler: $this->scheduler);
        $app->scheduleTasks();
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

    /** Formular öffnen, kurz warten, absenden. */
    private function report(App $app, array $fields = [], string $client = '203.0.113.5'): array
    {
        [, $form] = $this->api($app, 'GET', '/reports/form');
        $this->later(5);
        return $this->api($app, 'POST', '/reports', $fields + [
            'token' => $form['token'],
            'consent' => true,
            'description' => "Der Knopf geht nicht.\nZweite Zeile",
            'expected' => 'Dass er geht',
            'page' => '#/termine/verwalten',
            'userAgent' => 'Mozilla/5.0 (Test)',
            'lastError' => 'Fehler 500',
        ], $client);
    }

    private static function png(int $w = 4, int $h = 3): string
    {
        $im = imagecreatetruecolor($w, $h);
        ob_start();
        imagepng($im);
        return base64_encode((string) ob_get_clean());
    }

    /** Alle offenen Meldungen als Admin weiterleiten (erst dann gehen sie an das Cockpit). */
    private function forwardAll(App $app): void
    {
        $before = $this->users->current;
        $this->users->current = $this->admin();
        foreach ($app->db->select("SELECT id FROM {report_inbox} WHERE status = 'open' ORDER BY id") as $r) {
            [$s, $d] = $this->api($app, 'POST', '/reports/review/' . $r['id'] . '/forward');
            $this->assertSame(200, $s, json_encode($d));
        }
        $this->users->current = $before;
    }

    private ?int $adminId = null;

    private function admin(): int
    {
        return $this->adminId ??= $this->users->add('chefin', Roles::ADMIN);
    }

    /** @return array<int, array<string, mixed>> gesendete Meldungen */
    private function sent(): array
    {
        $out = [];
        foreach ($this->http->calls as $c) {
            if ($c['url'] === self::URL) {
                $out[] = json_decode((string) $c['body'], true) + ['_signature' => $c['headers'][StatusReporter::HEADER] ?? '', '_body' => $c['body']];
            }
        }
        return $out;
    }

    public function testWithoutReceiverReportsGoToTheAdminsButCannotBeForwarded(): void
    {
        $app = $this->app('');
        $this->assertTrue($app->api()->config()['reports']);
        [$s, $form] = $this->api($app, 'GET', '/reports/form');
        $this->assertSame(200, $s);
        $this->assertFalse($form['forward']);
        [$s] = $this->report($app);
        $this->assertSame(201, $s);
        $this->assertSame(1, $app->bugReports()->openCount());
        $this->users->current = $this->admin();
        [$s, $list] = $this->api($app, 'GET', '/reports/review');
        $this->assertSame(200, $s);
        $this->assertFalse($list['forward']);
        [$s, $d] = $this->api($app, 'POST', '/reports/review/' . $list['reports'][0]['id'] . '/forward');
        $this->assertSame([409, 'reports_not_forwardable'], [$s, $d['code']]);
        $this->assertSame(0, $app->bugReports()->queued());
        // Die Aufgabe räumt den Eingang auch ohne Empfänger auf.
        $this->assertSame(BugReportService::INTERVAL, $this->scheduler->scheduled[BugReportService::TASK]);
        // Nur https (wie die Statusmeldungen)
        $this->assertFalse($this->app('http://example.org/reports')->bugReports()->forwardable());
    }

    public function testGuestReportWaitsForTheAdminsAndIsSentAfterForwarding(): void
    {
        $app = $this->app();
        $this->assertTrue($app->api()->config()['reports']);
        $this->assertNull($app->api()->config()['openReports'], 'ohne Anmeldung kein Zähler');
        $this->assertSame(BugReportService::INTERVAL, $this->scheduler->scheduled[BugReportService::TASK]);
        [$s, $form] = $this->api($app, 'GET', '/reports/form');
        $this->assertSame(200, $s);
        $this->assertSame(['version' => $app->env->version(), 'role' => 'ohne Anmeldung'], $form['details']);
        $this->assertSame(BugReportService::MIN_SECONDS, $form['minSeconds']);
        $this->assertTrue($form['forward']);

        [$s, $d] = $this->report($app);
        $this->assertSame(201, $s, json_encode($d));
        $this->assertSame(['queued' => true], $d);
        $this->assertSame(0, $app->bugReports()->queued(), 'nicht gleich an die Betreuer');
        $this->assertSame(1, $app->bugReports()->openCount());
        $this->assertNotContains(BugReportService::TASK, $this->scheduler->soon);
        $app->runTask(BugReportService::TASK);
        $this->assertSame([], $this->sent(), 'vor dem Weiterleiten geht nichts raus');

        $this->users->current = $this->admin();
        $this->assertSame(1, $app->api()->config()['openReports']);
        [, $list] = $this->api($app, 'GET', '/reports/review');
        $item = $list['reports'][0];
        $this->assertSame('open', $item['status']);
        $this->assertSame("Der Knopf geht nicht.\nZweite Zeile", $item['description']);
        $this->assertSame('#/termine/verwalten', $item['page']);
        $this->assertSame('ohne Anmeldung', $item['role']);
        $this->assertNull($item['name']);
        $this->assertSame('Fehler 500', $item['last_error']);

        [$s, $d] = $this->api($app, 'POST', '/reports/review/' . $item['id'] . '/forward');
        $this->assertSame(200, $s, json_encode($d));
        $this->assertSame('forwarded', $d['status']);
        $this->assertSame('Chefin', $d['reviewed_by']);
        $this->assertSame(1, $app->bugReports()->queued());
        $this->assertSame(0, $app->bugReports()->openCount());
        $this->assertContains(BugReportService::TASK, $this->scheduler->soon, 'Versand vorgezogen');
        $row = $app->db->selectOne('SELECT * FROM {bug_reports}');
        $this->assertSame(0, (int) $row['user_id'], 'ohne Namen keine Benutzer-ID');
        $report = json_decode((string) $row['report'], true);
        $this->assertSame("Der Knopf geht nicht.\nZweite Zeile", $report['description']);
        $this->assertSame('ohne Anmeldung', $report['role']);
        $this->assertNull($report['name']);
        $this->assertSame('Fehler 500', $report['lastError']);
        $this->assertSame(409, $this->api($app, 'POST', '/reports/review/' . $item['id'] . '/forward')[0], 'nur einmal');
        $this->assertSame(409, $this->api($app, 'POST', '/reports/review/' . $item['id'] . '/close', ['reason' => 'x'])[0]);
    }

    public function testOnlyReviewersSeeTheInbox(): void
    {
        $app = $this->app();
        $this->report($app);
        $member = $this->users->add('max', Roles::MEMBER);
        $this->users->current = $member;
        $this->assertNull($app->api()->config()['openReports']);
        $this->assertSame(403, $this->api($app, 'GET', '/reports/review')[0]);
        $this->assertSame(403, $this->api($app, 'POST', '/reports/review/1/forward')[0]);
        $this->assertSame(403, $this->api($app, 'POST', '/reports/review/1/close', ['reason' => 'x'])[0]);
        $this->assertSame(403, $this->api($app, 'GET', '/reports/review/1/screenshot')[0]);
        $this->users->current = null;
        $this->assertSame(401, $this->api($app, 'GET', '/reports/mine')[0]);
        // Das Recht lässt sich an Rollen vergeben.
        $this->assertContains(\KompanieApp\Core\Auth\Capability::ReviewReports, Roles::GRANTABLE);
    }

    public function testCloseWithReasonTellsTheReporter(): void
    {
        $app = $this->app();
        $id = $this->users->add('max', Roles::MEMBER);
        $this->users->current = $id;
        $this->report($app, ['screenshot' => ['data' => self::png()]]);
        [$s, $mine] = $this->api($app, 'GET', '/reports/mine');
        $this->assertSame(200, $s);
        $this->assertSame([['open', null]], array_map(static fn ($r) => [$r['status'], $r['reason']], $mine));

        $this->users->current = $this->admin();
        [, $list] = $this->api($app, 'GET', '/reports/review');
        $item = $list['reports'][0];
        $this->assertSame('Max', $item['name'], 'Admins sehen, wer angemeldet gemeldet hat');
        $this->assertFalse($item['share_name']);
        $this->assertTrue($item['screenshot']);
        [$s, $shot] = $this->api($app, 'GET', '/reports/review/' . $item['id'] . '/screenshot');
        $this->assertSame(200, $s);
        $this->assertSame('image/png', $shot['type']);

        $this->assertSame(400, $this->api($app, 'POST', '/reports/review/' . $item['id'] . '/close', ['reason' => '  '])[0], 'Begründung ist Pflicht');
        $this->assertSame(400, $this->api($app, 'POST', '/reports/review/' . $item['id'] . '/close', ['reason' => str_repeat('a', BugReportService::MAX_REASON + 1)])[0]);
        [$s, $d] = $this->api($app, 'POST', '/reports/review/' . $item['id'] . '/close', ['reason' => 'Bitte die App neu laden, dann geht es.']);
        $this->assertSame(200, $s, json_encode($d));
        $this->assertSame(['closed', 'Bitte die App neu laden, dann geht es.', false], [$d['status'], $d['reason'], $d['screenshot']]);
        $this->assertNull($app->db->scalar('SELECT screenshot FROM {report_inbox}'), 'Bildschirmfoto gelöscht');
        $this->assertSame(404, $this->api($app, 'GET', '/reports/review/' . $item['id'] . '/screenshot')[0]);
        $this->assertSame(0, $app->bugReports()->queued(), 'geschlossen geht nicht an die Betreuer');

        $this->users->current = $id;
        [, $mine] = $this->api($app, 'GET', '/reports/mine');
        $this->assertSame([['closed', 'Bitte die App neu laden, dann geht es.']], array_map(static fn ($r) => [$r['status'], $r['reason']], $mine));

        // Erledigte nach 30 Tagen gelöscht, offene bleiben
        $this->users->current = null;
        $this->report($app);
        $this->later(BugReportService::KEEP_DONE_DAYS * 86400 + 60);
        $app->runTask(BugReportService::TASK);
        $this->assertSame(['open'], array_column($app->db->select('SELECT status FROM {report_inbox}'), 'status'));
    }

    public function testReviewersAndReporterAreNotified(): void
    {
        $app = $this->app();
        if (!$app->push()->supported()) {
            $this->markTestSkipped('ohne Push-Unterstützung');
        }
        $admin = $this->admin();
        $member = $this->users->add('max', Roles::MEMBER);
        $other = $this->users->add('eva', Roles::MEMBER);
        foreach ([[$admin, PushService::TYPE_REPORTS_REVIEW], [$member, PushService::TYPE_REPORTS], [$other, PushService::TYPE_REPORTS]] as [$uid, $type]) {
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
            $this->assertNotFalse($key);
            $keys = ['p256dh' => WebPushCrypto::b64uEncode(WebPushCrypto::rawPublic($key)), 'auth' => WebPushCrypto::b64uEncode(random_bytes(16))];
            $app->push()->subscribe($this->users->find($uid), 'https://fcm.googleapis.com/fcm/send/' . $uid, $keys, [$type], null, '1.2.3.4');
        }
        $queued = fn (int $uid): array => array_map(
            static fn (array $r): array => json_decode((string) $r['payload'], true),
            $app->db->select('SELECT q.payload FROM {push_queue} q JOIN {push_subscriptions} s ON s.id = q.subscription_id WHERE s.user_id = ? ORDER BY q.id', [$uid]),
        );
        $this->users->current = $member;
        $this->report($app);
        $this->assertSame(['Max hat einen Fehler gemeldet.'], array_column($queued($admin), 'body'));
        $this->assertSame('verwaltung/meldungen', $queued($admin)[0]['route']);
        $this->assertSame([], $queued($other), 'neue Meldungen nur mit review_reports');

        $this->users->current = $admin;
        [, $list] = $this->api($app, 'GET', '/reports/review');
        $this->api($app, 'POST', '/reports/review/' . $list['reports'][0]['id'] . '/close', ['reason' => 'Erledigt, bitte neu anmelden.']);
        $this->assertSame(['Deine Fehlermeldung ist erledigt: Erledigt, bitte neu anmelden.'], array_column($queued($member), 'body'));
        $this->assertSame('hilfe/melden', $queued($member)[0]['route']);
    }

    public function testInboxIsLimited(): void
    {
        $app = $this->app();
        $now = $this->clock->now()->getTimestamp();
        for ($i = 0; $i < BugReportService::MAX_OPEN; $i++) {
            $app->db->insert('report_inbox', ['uuid' => sprintf('%032x', $i), 'report' => '{}', 'status' => 'open', 'created_at' => $now]);
        }
        [$s, $d] = $this->report($app);
        $this->assertSame([503, 'reports_full'], [$s, $d['code']]);
    }

    public function testNameOnlyWhenWantedAndOnlyForAccounts(): void
    {
        $app = $this->app();
        $id = $this->users->add('max', Roles::MEMBER);
        $this->users->current = $id;
        [$s] = $this->report($app);
        $this->assertSame(201, $s);
        [$s] = $this->report($app, ['includeName' => true]);
        $this->assertSame(201, $s);
        $this->assertSame([$id, $id], array_map(static fn ($r) => (int) $r['user_id'], $app->db->select('SELECT user_id FROM {report_inbox} ORDER BY id')), 'im Eingang immer mit Konto');
        $this->forwardAll($app);
        $rows = $app->db->select('SELECT user_id, report FROM {bug_reports} ORDER BY id');
        $this->assertSame([0, $id], array_map(static fn ($r) => (int) $r['user_id'], $rows));
        $this->assertNull(json_decode((string) $rows[0]['report'], true)['name']);
        $this->assertSame('Max', json_decode((string) $rows[1]['report'], true)['name']);
        $this->assertSame($app->roles()->label(Roles::MEMBER), json_decode((string) $rows[1]['report'], true)['role']);

        // Konto gelöscht: die Meldung mit Namen geht mit, die ohne bleibt; im Eingang beide.
        $app->userDeleted($id);
        $this->assertSame([0], array_map(static fn ($r) => (int) $r['user_id'], $app->db->select('SELECT user_id FROM {bug_reports}')));
        $this->assertSame(0, (int) $app->db->scalar('SELECT COUNT(*) FROM {report_inbox}'));

        $this->users->current = null;
        [$s, $d] = $this->report($app, ['includeName' => true]);
        $this->assertSame(400, $s, 'Gäste haben keinen Namen');
        $this->assertStringContainsString('Ohne Anmeldung', $d['message']);
    }

    public function testValidation(): void
    {
        $app = $this->app();
        $this->assertSame(400, $this->report($app, ['consent' => false])[0], 'ohne Einwilligung');
        $this->assertSame(400, $this->report($app, ['consent' => 'ja'])[0], 'Einwilligung nur als true');
        $this->assertSame(400, $this->report($app, ['description' => "  \n "])[0], 'Beschreibung ist Pflicht');
        $this->assertSame(400, $this->report($app, ['description' => str_repeat('a', BugReportService::MAX_DESCRIPTION + 1)])[0], 'zu lang');
        $this->assertSame(201, $this->report($app, ['description' => str_repeat('ä', BugReportService::MAX_DESCRIPTION)])[0], 'genau die Höchstlänge');
        $this->assertSame(400, $this->report($app, ['expected' => str_repeat('a', BugReportService::MAX_EXPECTED + 1)])[0]);
        // Steuerzeichen entfernt, Seite und Gerät einzeilig und gekürzt
        $this->report($app, ['description' => "a\x07b", 'page' => "#/x\n" . str_repeat('y', 500)]);
        $report = json_decode((string) $app->db->scalar('SELECT report FROM {report_inbox} ORDER BY id DESC LIMIT 1'), true);
        $this->assertSame('ab', $report['description']);
        $this->assertSame(200, mb_strlen($report['page']));
        $this->assertStringNotContainsString("\n", $report['page']);
    }

    public function testScreenshotMustBeASmallRealImage(): void
    {
        $app = $this->app();
        [$s] = $this->report($app, ['screenshot' => ['type' => 'image/png', 'data' => 'data:image/png;base64,' . self::png()]]);
        $this->assertSame(201, $s);
        $row = $app->db->selectOne('SELECT screenshot, screenshot_type FROM {report_inbox}');
        $this->assertSame('image/png', $row['screenshot_type']);
        $this->assertSame(base64_decode(self::png(), true), base64_decode((string) $row['screenshot'], true));

        $this->assertSame(400, $this->report($app, ['screenshot' => ['data' => 'kein base64 !!']])[0]);
        $this->assertSame(400, $this->report($app, ['screenshot' => ['data' => base64_encode("\x89PNG\r\n\x1a\n" . 'aber kein Bild')]])[0], 'nur die Signatur');
        $this->assertSame(400, $this->report($app, ['screenshot' => ['data' => base64_encode('<svg xmlns="http://www.w3.org/2000/svg"/>')]])[0], 'kein SVG');
        $this->assertSame(400, $this->report($app, ['screenshot' => ['data' => base64_encode(str_repeat('x', BugReportService::MAX_SCREENSHOT_BYTES + 1))]])[0], 'zu groß');
        // Ein PNG, das 20000 Pixel breit zu sein behauptet (Kopf von Hand gebaut)
        $ihdr = pack('NNCCCCC', 20000, 10, 8, 2, 0, 0, 0);
        $bomb = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr));
        $this->assertSame(400, $this->report($app, ['screenshot' => ['data' => base64_encode($bomb)]])[0], 'unsinnige Maße');
        $this->assertSame(1, $app->bugReports()->openCount());
        // Beim Weiterleiten geht das Bildschirmfoto in die Warteschlange und verschwindet aus dem Eingang.
        $this->forwardAll($app);
        $this->assertSame(base64_decode(self::png(), true), base64_decode((string) $app->db->scalar('SELECT screenshot FROM {bug_reports}'), true));
        $this->assertNull($app->db->scalar('SELECT screenshot FROM {report_inbox}'));
    }

    public function testSpamProtection(): void
    {
        $app = $this->app();
        // Honeypot: scheinbar angenommen, aber nicht gespeichert
        [$s, $d] = $this->report($app, ['website' => 'https://spam.example']);
        $this->assertSame([201, ['queued' => true]], [$s, $d]);
        $this->assertSame(0, $app->bugReports()->openCount());

        // Zeitfalle: zu schnell, ohne, gefälscht, zu alt
        [, $form] = $this->api($app, 'GET', '/reports/form');
        $body = ['token' => $form['token'], 'consent' => true, 'description' => 'x'];
        $this->assertSame([429, 'too_fast'], [$this->api($app, 'POST', '/reports', $body)[0], $this->api($app, 'POST', '/reports', $body)[1]['code']]);
        $this->assertSame('form_expired', $this->api($app, 'POST', '/reports', ['token' => null] + $body)[1]['code']);
        [$at] = explode('.', $form['token']);
        $this->assertSame('form_expired', $this->api($app, 'POST', '/reports', ['token' => ($at - 100) . '.' . str_repeat('0', 64)] + $body)[1]['code']);
        $this->later(BugReportService::MAX_FORM_AGE + 1);
        $this->assertSame('form_expired', $this->api($app, 'POST', '/reports', $body)[1]['code']);

        // Je Anfragendem höchstens CLIENT_RATE in der Stunde; ein anderer darf weiter
        for ($i = 0; $i < BugReportService::CLIENT_RATE; $i++) {
            $this->assertSame(201, $this->report($app, [], '198.51.100.7')[0]);
        }
        $this->assertSame(429, $this->report($app, [], '198.51.100.7')[0]);
        $this->assertSame(201, $this->report($app, [], '2001:db8::1')[0], 'andere Adresse nicht betroffen');
        // IPv6: das ganze /64 zählt als einer
        for ($i = 1; $i < BugReportService::CLIENT_RATE; $i++) {
            $this->report($app, [], '2001:db8::' . ($i + 1));
        }
        $this->assertSame(429, $this->report($app, [], '2001:db8::ff')[0]);
    }

    public function testQueueAndDailyLimits(): void
    {
        $app = $this->app();
        $sent = 0;
        for ($i = 0; $i < BugReportService::DAILY_RATE; $i++) {
            $sent += $this->report($app, [], '192.0.2.' . $i)[0] === 201 ? 1 : 0;
        }
        $this->assertSame(BugReportService::DAILY_RATE, $sent);
        $this->assertSame(429, $this->report($app, [], '192.0.2.200')[0], 'je Installation und Tag begrenzt');
    }

    public function testSendsSignedReportWithKeyUntilAcceptedThenDeletes(): void
    {
        $app = $this->app();
        // Statusmeldungen sind aus: Fehlermeldungen brauchen diese Zustimmung nicht.
        $this->assertFalse((bool) $app->settings->get(StatusReporter::SETTING, false));
        $this->report($app, ['screenshot' => ['data' => self::png()]]);
        $this->report($app);
        $this->forwardAll($app);
        $this->responses = [['status' => 201, 'body' => '{}'], ['status' => 201, 'body' => '{}']];
        $app->runTask(BugReportService::TASK);
        $sent = $this->sent();
        $this->assertCount(2, $sent);
        $first = $sent[0];
        $this->assertSame(['format', 'installation', 'kompanie', 'sent', 'nonce', 'report', 'screenshot', 'key', '_signature', '_body'], array_keys($first));
        $this->assertSame(1, $first['format']);
        $this->assertSame('org/kompanie-test', $first['kompanie']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $first['report']['id']);
        $this->assertSame(['id', 'description', 'expected', 'page', 'version', 'variant', 'userAgent', 'role', 'name', 'lastError', 'created'], array_keys($first['report']));
        $this->assertSame('image/png', $first['screenshot']['type']);
        $secret = (string) $app->settings->get(StatusReporter::SECRET_SETTING);
        $this->assertSame($secret, $first['key'], 'Vertrauen beim ersten Kontakt');
        $this->assertSame(StatusReporter::sign($first['_body'], $secret), $first['_signature']);
        $this->assertSame((string) $app->settings->get(StatusReporter::ID_SETTING), $first['installation']);
        $this->assertArrayNotHasKey('key', $sent[1], 'nach der Bestätigung ohne Geheimnis');
        $this->assertNull($sent[1]['screenshot']);
        $this->assertSame(0, $app->bugReports()->queued());
        $this->assertSame($first['installation'], $app->settings->get(BugReportService::REGISTERED_SETTING));
        // Eigene Bestätigung: Die Statusmeldungen senden ihr Geheimnis weiter, bis das Cockpit sie bestätigt.
        $this->assertNull($app->settings->get(StatusReporter::REGISTERED_SETTING));
    }

    public function testRetriesDropsAndExpiry(): void
    {
        $app = $this->app();
        $this->report($app, ['description' => 'eins']);
        $this->report($app, ['description' => 'zwei']);
        $this->forwardAll($app);

        // Server nicht erreichbar: Lauf bricht ab, beide bleiben
        $this->responses = [['status' => 503, 'body' => '']];
        $app->runTask(BugReportService::TASK);
        $this->assertCount(1, $this->sent());
        $this->assertSame(2, $app->bugReports()->queued());
        $this->assertSame(1, (int) $app->db->scalar('SELECT attempts FROM {bug_reports} ORDER BY id LIMIT 1'));

        // Cockpit kennt die Installation nicht mehr: beim nächsten Mal wieder mit Geheimnis; ungültig: verworfen
        $app->settings->set(BugReportService::REGISTERED_SETTING, (string) $app->settings->get(StatusReporter::ID_SETTING));
        $this->http->calls = [];
        $this->responses = [['status' => 409, 'body' => '{"code":"unknown_installation"}'], ['status' => 400, 'body' => '{"code":"invalid"}']];
        $app->runTask(BugReportService::TASK);
        $this->assertCount(2, $this->sent());
        $this->assertArrayNotHasKey('key', $this->sent()[0]);
        $this->assertNull($app->settings->get(BugReportService::REGISTERED_SETTING));
        $this->assertSame(['eins'], array_map(static fn ($r) => json_decode((string) $r['report'], true)['description'], $app->db->select('SELECT report FROM {bug_reports}')));

        $this->http->calls = [];
        $this->responses = [['status' => 201, 'body' => '{}']];
        $app->runTask(BugReportService::TASK);
        $this->assertArrayHasKey('key', $this->sent()[0]);
        $this->assertSame(0, $app->bugReports()->queued());

        // Nach 7 Tagen ohne Zustellung verworfen
        $this->report($app);
        $this->forwardAll($app);
        $this->later(BugReportService::KEEP_DAYS * 86400 + 60);
        $this->http->calls = [];
        $app->runTask(BugReportService::TASK);
        $this->assertSame([], $this->sent());
        $this->assertSame(0, $app->bugReports()->queued());
    }

    public function testQueueIsNotPartOfBackupsAndRunsDuringMaintenance(): void
    {
        $this->assertNotContains('bug_reports', Backup::CORE_TABLES);
        $this->assertNotContains('report_inbox', Backup::CORE_TABLES);
        $this->assertContains(BugReportService::REGISTERED_SETTING, Backup::LOCAL_SETTINGS);
        $this->assertTrue(\KompanieApp\Core\Domain\MaintenanceService::runsDuringMaintenance(BugReportService::TASK));
    }
}
