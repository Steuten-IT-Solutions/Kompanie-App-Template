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
use KompanieApp\Core\Storage\Sql\Migrations;
use KompanieApp\Core\Storage\Sql\Migrator;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\MemorySettings;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** Umbau des Schwarzen Bretts (Issue #172), Teile A und C: Titel, Enddatum, Zeitzonen, Push-TTL, Änderungsverfolgung. */
final class BoardRebuildTest extends TestCase
{
    private FakeUsers $users;
    private FixedClock $clock;
    private App $app;
    private int $admin;
    private int $m;
    private int $poster;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        // 12:00 in Berlin (Sommerzeit endet am 25. Oktober) = 10:00 UTC
        $this->clock = new FixedClock(new \DateTimeImmutable('2026-10-20 12:00:00', new \DateTimeZone('Europe/Berlin')));
        $this->app = TestApp::create(TestApp::connection(), $this->users, [], null, null, null, $this->clock, ['board' => true]);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->m = $this->users->add('mitglied', Roles::MEMBER);
        $this->poster = $this->users->add('schreiber', 'board');
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
    private function post(array $body): array
    {
        $res = $this->call('POST', '/board', $body + ['title' => 'Titel', 'text' => 'Text', 'push' => false]);
        $this->assertSame(201, $res->status, (string) json_encode($res->data));
        return $res->data;
    }

    public function testExpiryDefaultFreeChoiceAndTimezones(): void
    {
        $this->assertSame('2026-10-21T10:00:00+00:00', $this->post([])['expires_at'], 'ohne Angabe 24 Stunden');
        $this->assertSame('2026-10-21T10:00:00+00:00', $this->post(['expires_at' => null])['expires_at']);
        // ISO 8601 mit Offset: in UTC umgerechnet
        $this->assertSame('2026-10-22T16:30:00+00:00', $this->post(['expires_at' => '2026-10-22T18:30:00+02:00'])['expires_at']);
        $this->assertSame('2026-10-22T18:30:00+00:00', $this->post(['expires_at' => '2026-10-22T18:30:00.000Z'])['expires_at'], 'wie new Date().toISOString()');
        // Ohne Zeitzone: Zeitzone der Kompanie (Europe/Berlin), auch über die Zeitumstellung hinweg
        $this->assertSame('2026-10-22T16:30:00+00:00', $this->post(['expires_at' => '2026-10-22T18:30'])['expires_at'], 'Sommerzeit');
        $this->assertSame('2026-10-26T17:30:00+00:00', $this->post(['expires_at' => '2026-10-26 18:30'])['expires_at'], 'Winterzeit');
        // Keine Obergrenze
        $this->assertSame('2031-12-31T23:00:00+00:00', $this->post(['expires_at' => '2032-01-01T00:00'])['expires_at']);
        $this->assertSame('2026-10-20T10:00:01+00:00', $this->post(['expires_at' => '2026-10-20T10:00:01Z'])['expires_at'], 'eine Sekunde in der Zukunft reicht');
        foreach (['Vergangenheit' => '2026-10-20T09:00:00Z', 'jetzt' => '2026-10-20T12:00', 'kein Datum' => 'morgen', 'ungültiger Tag' => '2026-02-30T10:00', 'Zahl' => 1793000000, 'Liste' => ['x']] as $name => $value) {
            $res = $this->call('POST', '/board', ['title' => 'T', 'text' => 'x', 'expires_at' => $value, 'push' => false]);
            $this->assertSame(400, $res->status, $name);
        }
        $stored = $this->app->db->select('SELECT expires_at FROM {board_messages} ORDER BY id');
        $this->assertSame('2026-10-22 16:30:00', $stored[2]['expires_at'], 'gespeichert in UTC');
    }

    public function testTitleAndExpiryEditableAndUpdatedAtRule(): void
    {
        $item = $this->post(['title' => 'Training', 'text' => '<p>Heute 19 Uhr</p>']);
        $this->clock->at = $this->clock->now()->modify('+10 minutes');
        // Nur das Enddatum: updated_at bleibt (ausgeblendet bleibt ausgeblendet), kein neuer Push
        $queued = (int) $this->app->db->scalar('SELECT COUNT(*) FROM {push_queue}');
        $longer = $this->as($this->poster)->call('PATCH', "/board/{$item['id']}", ['expires_at' => '2026-11-30T20:00'])->data;
        $this->assertSame('2026-11-30T19:00:00+00:00', $longer['expires_at'], 'verlängert');
        $this->assertSame($item['updated_at'], $longer['updated_at'], 'updated_at unverändert');
        $shorter = $this->call('PATCH', "/board/{$item['id']}", ['expires_at' => '2026-10-20T14:00'])->data;
        $this->assertSame(['2026-10-20T12:00:00+00:00', $item['updated_at']], [$shorter['expires_at'], $shorter['updated_at']], 'verkürzt');
        $this->assertSame(400, $this->call('PATCH', "/board/{$item['id']}", ['expires_at' => '2026-10-20T11:00'])->status, 'nicht in die Vergangenheit');
        $this->assertSame($queued, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {push_queue}'));
        // Titel ändern: neues updated_at (erscheint wieder)
        $renamed = $this->call('PATCH', "/board/{$item['id']}", ['title' => 'Training fällt aus'])->data;
        $this->assertSame(['Training fällt aus', '2026-10-20T10:10:00+00:00'], [$renamed['title'], $renamed['updated_at']]);
        // Alles zusammen
        $all = $this->call('PATCH', "/board/{$item['id']}", ['title' => 'Neu', 'text' => '<p>Neu</p>', 'expires_at' => '2026-10-23T10:00:00Z'])->data;
        $this->assertSame(['Neu', '<p>Neu</p>', '2026-10-23T10:00:00+00:00'], [$all['title'], $all['text'], $all['expires_at']]);
        $this->assertSame(403, $this->as($this->m)->call('PATCH', "/board/{$item['id']}", ['expires_at' => '2026-10-25T10:00'])->status, 'nur mit post_board');
        // Nach dem Ablauf nicht mehr änderbar
        $this->clock->at = new \DateTimeImmutable('2026-10-23 10:00:00', new \DateTimeZone('UTC'));
        $this->assertSame(404, $this->as($this->admin)->call('PATCH', "/board/{$item['id']}", ['expires_at' => '2026-10-30T10:00'])->status);
    }

    public function testPushShowsTitleAndTtlIsCapped(): void
    {
        if (!$this->app->push()->supported()) {
            $this->markTestSkipped('ohne Push-Unterstützung');
        }
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->assertNotFalse($key);
        $this->app->push()->subscribe($this->users->find($this->m), 'https://fcm.googleapis.com/fcm/send/m', ['p256dh' => WebPushCrypto::b64uEncode(WebPushCrypto::rawPublic($key)), 'auth' => WebPushCrypto::b64uEncode(random_bytes(16))], null, null, '1.2.3.4');
        $this->post(['title' => 'Kein Training', 'text' => '<p>Die Halle ist gesperrt.</p>', 'push' => true, 'expires_at' => '2026-12-31T12:00']);
        $row = $this->app->db->selectOne('SELECT payload, ttl FROM {push_queue}');
        $this->assertNotNull($row);
        $payload = json_decode((string) $row['payload'], true);
        $this->assertSame('Schwarzes Brett: Admin', $payload['title']);
        $this->assertSame('Kein Training', $payload['body'], 'Text = Titel, nicht der Inhalt');
        $this->assertSame(PushService::MAX_TTL, (int) $row['ttl'], 'auf die Obergrenze der Push-Dienste gekappt (28 Tage)');
        $this->assertSame(2419200, PushService::MAX_TTL);
        $this->app->db->execute('DELETE FROM {push_queue}');
        $this->post(['title' => 'Kurz', 'push' => true, 'expires_at' => '2026-10-20T13:00']);
        $this->assertSame(3600, (int) $this->app->db->scalar('SELECT ttl FROM {push_queue}'), 'sonst bis zum Enddatum');
    }

    public function testMigrationGivesExistingMessagesTitleAndHtml(): void
    {
        foreach (['sqlite', getenv('KOMPANIE_TEST_MYSQL') ? 'mysql' : null] as $driver) {
            if ($driver === null) {
                continue;
            }
            $db = TestApp::connection($driver);
            try {
                $settings = new MemorySettings();
                $all = Migrations::all();
                (new Migrator($db, $settings, array_filter($all, static fn (int $v): bool => $v <= 27, ARRAY_FILTER_USE_KEY)))->migrate();
                $db->insert('board_messages', ['body' => "Halle gesperrt!\r\nBitte <b>nicht</b> kommen & weitersagen.", 'author_id' => 1, 'created_at' => '2026-10-20 10:00:00', 'updated_at' => '2026-10-20 10:00:00', 'expires_at' => '2026-10-21 10:00:00']);
                $db->insert('board_messages', ['body' => str_repeat('Länger ', 30), 'author_id' => 1, 'created_at' => '2026-10-20 10:00:00', 'updated_at' => '2026-10-20 10:00:00', 'expires_at' => '2026-10-21 10:00:00']);
                (new Migrator($db, $settings))->migrate();
                $rows = $db->select('SELECT title, body FROM {board_messages} ORDER BY id');
                $this->assertSame('Halle gesperrt!', $rows[0]['title'], "$driver: erste Zeile als Titel");
                $this->assertSame('<p>Halle gesperrt!<br>Bitte &lt;b&gt;nicht&lt;/b&gt; kommen &amp; weitersagen.</p>', $rows[0]['body'], "$driver: escaped, Zeilenumbruch als <br>");
                $this->assertSame(80, mb_strlen($rows[1]['title']), "$driver: höchstens 80 Zeichen (Zeichen, nicht Bytes)");
                $this->assertStringEndsWith('…', $rows[1]['title']);
                // Wiederholt ausgeführt (Versionsstand verloren): nichts doppelt escaped
                $settings->set(Migrator::SETTING, 27);
                (new Migrator($db, $settings))->migrate();
                $this->assertSame($rows, $db->select('SELECT title, body FROM {board_messages} ORDER BY id'));
                // longtext: mehr als 64 KB
                $db->insert('board_messages', ['title' => 'Lang', 'body' => str_repeat('x', 70000), 'author_id' => 1, 'created_at' => '2026-10-20 10:00:00', 'updated_at' => '2026-10-20 10:00:00', 'expires_at' => '2026-10-21 10:00:00']);
                $this->assertSame(70000, strlen((string) $db->scalar("SELECT body FROM {board_messages} WHERE title = 'Lang'")), "$driver: longtext");
            } finally {
                TestApp::dropTables($db);
            }
        }
    }

    public function testRevisionsHistoryAndRights(): void
    {
        $item = $this->as($this->poster)->post(['title' => 'Erst', 'text' => '<p>Alt</p>']);
        $this->clock->at = $this->clock->now()->modify('+5 minutes');
        $this->as($this->admin)->call('PATCH', "/board/{$item['id']}", ['title' => 'Zweit', 'text' => '<p>Neu</p>', 'expires_at' => '2026-10-22T12:00']);
        // Mitglieder sehen keinen Verlauf und nicht, wer geändert hat
        $memberView = $this->as($this->m)->call('GET', '/board')->data['items'][0];
        $this->assertArrayNotHasKey('last_edit', $memberView);
        $this->assertSame(403, $this->call('GET', "/board/{$item['id']}/history")->status);
        $this->assertSame(403, $this->call('GET', '/board/history')->status);
        $editorView = $this->as($this->poster)->call('GET', '/board')->data['items'][0];
        $this->assertSame(['name' => 'Admin', 'at' => '2026-10-20T10:05:00+00:00'], $editorView['last_edit']);

        $history = $this->call('GET', "/board/{$item['id']}/history")->data;
        $this->assertSame(['created', 'title', 'content', 'expires'], array_column($history['revisions'], 'action'));
        $this->assertSame(['Schreiber', 'Admin', 'Admin', 'Admin'], array_column($history['revisions'], 'user_name'));
        $this->assertSame(['Erst', 'Zweit'], [$history['revisions'][1]['before'], $history['revisions'][1]['after']]);
        $this->assertSame(['<p>Alt</p>', '<p>Neu</p>'], [$history['revisions'][2]['before'], $history['revisions'][2]['after']], 'vorher/nachher');
        $this->assertSame(['2026-10-21T10:00:00+00:00', '2026-10-22T10:00:00+00:00'], [$history['revisions'][3]['before'], $history['revisions'][3]['after']]);
        $this->assertEquals(['title' => 'Erst', 'text' => '<p>Alt</p>', 'expires_at' => '2026-10-21T10:00:00+00:00'], $history['revisions'][0]['after']);

        // Vorzeitig löschen: mit Konto und Zeitpunkt; danach im Verlauf der letzten 30 Tage
        $this->clock->at = $this->clock->now()->modify('+1 hour');
        $this->assertSame(200, $this->as($this->admin)->call('DELETE', "/board/{$item['id']}")->status);
        $this->assertSame([], $this->as($this->m)->call('GET', '/board')->data['items']);
        $ended = $this->as($this->poster)->call('GET', '/board/history')->data;
        $this->assertSame(30, $ended['keep_days']);
        $this->assertSame([['Zweit', 'Admin', '2026-10-20T11:05:00+00:00', false]], array_map(static fn (array $e): array => [$e['title'], $e['deleted_by_name'], $e['deleted_at'], $e['active']], $ended['items']));
        $history = $this->call('GET', "/board/{$item['id']}/history")->data;
        $this->assertSame('deleted', end($history['revisions'])['action']);
        $this->assertSame(['expires_at' => '2026-10-22T10:00:00+00:00'], end($history['revisions'])['before'], 'vorheriges Enddatum');
        $this->assertSame('2026-10-20 11:05:00', $this->app->db->scalar('SELECT expires_at FROM {board_messages} WHERE id = ?', [$item['id']]), 'gilt auch für ältere Versionen nicht mehr');

        // Gelöschte Konten erscheinen als „gelöschtes Konto“
        unset($this->users->users[$this->poster], $this->users->users[$this->admin]);
        $this->app->userDeleted($this->admin);
        $this->users->current = $this->users->add('admin2', Roles::ADMIN);
        $history = $this->call('GET', "/board/{$item['id']}/history")->data;
        $this->assertSame([UserNames::DELETED], array_values(array_unique(array_column($history['revisions'], 'user_name'))));
        $this->assertSame(UserNames::DELETED, $history['message']['author_name']);

        // 30 Tage nach dem Löschen endgültig weg
        $this->clock->at = new \DateTimeImmutable('2026-11-19 11:05:01', new \DateTimeZone('UTC'));
        $this->app->board()->cleanup();
        $this->assertSame(404, $this->call('GET', "/board/{$item['id']}/history")->status);
        $this->assertSame(0, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {board_message_revisions}'));
    }

    public function testOrphanRevisionsFromOlderVersionsAreRemoved(): void
    {
        $item = $this->post(['title' => 'X']);
        // Eine ältere Version löscht abgelaufene Nachrichten sofort (ohne Verlauf).
        $this->app->db->execute('DELETE FROM {board_messages}');
        $this->clock->at = new \DateTimeImmutable('2026-11-19 09:59:59', new \DateTimeZone('UTC'));
        $this->app->board()->cleanup();
        $this->assertSame(1, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {board_message_revisions} WHERE message_id = ?', [$item['id']]), 'erst 30 Tage nach dem letzten Eintrag');
        $this->clock->at = new \DateTimeImmutable('2026-11-19 10:00:01', new \DateTimeZone('UTC'));
        $this->app->board()->cleanup();
        $this->assertSame(0, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {board_message_revisions}'));
    }

    public function testBoardItemsShape(): void
    {
        $item = $this->post(['title' => 'Kurz', 'text' => '<ul><li>eins</li><li>zwei</li></ul>']);
        $this->assertSame("• eins\n• zwei", $item['excerpt']);
        $this->assertSame(BoardService::KIND_MESSAGE, $item['kind']);
        $member = $this->as($this->m)->call('GET', '/board')->data['items'][0];
        $this->assertSame(['kind', 'id', 'title', 'text', 'excerpt', 'author_name', 'created_at', 'updated_at', 'expires_at'], array_keys($member));
    }
}
