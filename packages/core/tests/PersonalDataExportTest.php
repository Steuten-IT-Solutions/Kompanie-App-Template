<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\PollService;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Storage\Backup;
use KompanieApp\Core\Storage\Sql\Schema;
use KompanieApp\Core\Storage\UserData;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** Auskunft nach Art. 15 DSGVO: eigene Daten und die einer Person für die Verwaltung. */
final class PersonalDataExportTest extends TestCase
{
    /**
     * Kerntabellen mit user_id, die bewusst nicht zur Person gehören (sonst gehört die Tabelle in UserData::TABLES,
     * damit sie beim Löschen und in der Auskunft vorkommt).
     */
    private const NOT_PERSONAL = [
        'inventory_log' => 'user_id ist nur „geändert von“ (Issue #6)',
        'office_holders' => 'Chronik; Auskunft fragt sie eigens ab, beim Löschen wird nur die Verknüpfung gelöst',
        'list_rows' => 'Auskunft fragt sie eigens ab, beim Löschen wird nur die Verknüpfung gelöst',
        'push_subscriptions' => 'Auskunft und Löschen fragen sie eigens ab (ohne Schlüssel bzw. mit Warteschlange)',
        'board_message_revisions' => 'Verlauf der Nachricht, bleibt beim Löschen; Auskunft fragt ihn eigens ab',
        'rich_images' => 'user_id ist „hochgeladen von“, das Bild gehört der Nachricht; Signatur und Entwürfe fragt die Auskunft eigens ab',
        'attachments' => 'user_id ist „hochgeladen von“, der Anhang gehört der Nachricht bzw. Mail',
    ];

    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $m;
    private int $other;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->app = TestApp::create(TestApp::connection(), $this->users, clock: new FixedClock(new \DateTimeImmutable('2026-10-22 12:00:00')));
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->m = $this->users->add('mitglied', Roles::MEMBER);
        $this->other = $this->users->add('andere', Roles::MEMBER);
        $this->users->current = $this->admin;
        $this->call('POST', '/points', ['user_ids' => [$this->m], 'points' => 5, 'reason' => 'Schützenfest']);
        $this->call('POST', '/points', ['user_ids' => [$this->other], 'points' => 3, 'reason' => 'Nicht meins']);
        $db = $this->app->db;
        $now = '2026-10-01 10:00:00';
        $db->insert('push_subscriptions', ['user_id' => $this->m, 'endpoint' => 'https://push.example/abc', 'endpoint_hash' => 'h1', 'p256dh' => 'GEHEIM-P256', 'auth' => 'GEHEIM-AUTH', 'created_at' => $now, 'updated_at' => $now]);
        $poll = $db->insert('polls', ['title' => 'Anonym', 'description' => '', 'type' => 'text', 'anonymous' => 1, 'results' => 'after_vote', 'created_by' => $this->admin, 'created_at' => $now, 'updated_at' => $now]);
        $db->insert('poll_votes', ['poll_id' => $poll, 'user_id' => $this->m, 'voted_at' => $now, 'updated_at' => $now]);
        $secret = $this->app->polls()->secret();
        $db->insert('poll_answers', ['poll_id' => $poll, 'vote_ref' => PollService::pseudonym($secret, $this->m), 'user_id' => null, 'option_id' => 0, 'answer_text' => 'Meine anonyme Meinung']);
    }

    private function call(string $method, string $path, array $body = []): Response
    {
        return $this->app->api()->handle(new Request($method, $path, [], $body));
    }

    public function testOwnExport(): void
    {
        $this->users->current = $this->m;
        $res = $this->call('GET', '/me/data');
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertStringContainsString('meine-daten-2026-10-22.json', $res->headers['Content-Disposition'] ?? '');
        $d = $res->data;
        $this->assertSame('kompanie-app-personal-data', $d['format']);
        $this->assertSame($this->m, $d['person']['id']);
        $this->assertSame(['Schützenfest'], array_column($d['data']['points'], 'reason'), 'nur eigene Punkte');
        $this->assertSame('Meine anonyme Meinung', $d['data']['poll_answers'][0]['answer_text'] ?? null, 'eigene anonyme Antwort');
        $json = (string) json_encode($d);
        foreach (['GEHEIM-P256', 'GEHEIM-AUTH', 'vote_ref', 'Nicht meins'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $json);
        }
        $this->assertSame('https://push.example/abc', $d['data']['push_subscriptions'][0]['endpoint']);
    }

    public function testExportForAdministration(): void
    {
        $res = $this->call('GET', "/members/{$this->m}/data");
        $this->assertSame(200, $res->status);
        $this->assertSame($this->m, $res->data['person']['id']);
        $this->assertArrayNotHasKey('poll_answers', $res->data['data'], 'anonyme Antworten nicht für die Verwaltung');
        $this->assertCount(1, $res->data['data']['poll_votes'], 'Teilnahme schon');

        // Wer Konten anlegt, aber kein Admin ist: keine Admins; ohne create_users gar nicht; ohne Anmeldung 401.
        $res = $this->call('POST', '/admin/roles', ['label' => 'Verwalter', 'capabilities' => TestApp::caps(['member', 'create_users'])]);
        $this->assertLessThan(300, $res->status, (string) json_encode($res->data));
        $role = (string) array_column($this->call('GET', '/admin/roles')->data, 'key', 'label')['Verwalter'];
        $manager = $this->users->add('verwalter', $role);
        $this->users->current = $manager;
        $this->assertSame(200, $this->call('GET', "/members/{$this->m}/data")->status);
        $this->assertSame(403, $this->call('GET', "/members/{$this->admin}/data")->status);
        $this->users->current = $this->other;
        $this->assertSame(403, $this->call('GET', "/members/{$this->m}/data")->status);
        $this->users->current = null;
        $this->assertSame(401, $this->call('GET', '/me/data')->status);
    }

    /** Jede Kerntabelle mit user_id steht in UserData (Löschen und Auskunft) oder ist hier begründet ausgenommen. */
    public function testEveryPersonalTableIsCovered(): void
    {
        $covered = array_keys(UserData::tables());
        foreach (Backup::CORE_TABLES as $table) {
            if (in_array('user_id', Schema::columns($this->app->db, $table), true)) {
                $this->assertTrue(in_array($table, $covered, true) || isset(self::NOT_PERSONAL[$table]), "$table fehlt in UserData::TABLES");
            }
        }
    }
}
