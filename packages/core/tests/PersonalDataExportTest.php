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
        // Namentliche Umfrage: Pseudonym und user_id (PollRepository::saveVote)
        $named = $db->insert('polls', ['title' => 'Namentlich', 'description' => '', 'type' => 'text', 'anonymous' => 0, 'results' => 'after_vote', 'created_by' => $this->admin, 'created_at' => $now, 'updated_at' => $now]);
        $db->insert('poll_answers', ['poll_id' => $named, 'vote_ref' => PollService::pseudonym($secret, $this->m), 'user_id' => $this->m, 'option_id' => 0, 'answer_text' => 'Meine namentliche Meinung']);
        // Entwürfe: in der App nur für sie selbst sichtbar
        foreach (['signature' => 'sig-bild', 'draft' => 'entwurf-bild'] as $owner => $uid) {
            $db->insert('rich_images', ['uid' => $uid, 'owner_type' => $owner, 'owner_id' => $this->m, 'user_id' => $this->m, 'mime' => 'image/png', 'width' => 1, 'height' => 1, 'bytes' => 1, 'data' => 'AA==', 'created_at' => $now]);
        }
        $this->app->settings->set(\KompanieApp\Core\Domain\ActivityService::SECRET_SETTING, str_repeat('ab', 32));
        $db->insert('activity_seen', ['token' => \KompanieApp\Core\Domain\ActivityService::token(str_repeat('ab', 32), $this->m), 'area' => 'events', 'at' => 1793000000]);
        $db->insert('bug_reports', ['uuid' => 'b1', 'user_id' => $this->m, 'report' => 'Fehlertext mit Daten anderer', 'screenshot' => 'BILD', 'created_at' => 1]);
        $db->insert('attachments', ['uid' => 'entwurf-anhang', 'owner_type' => 'draft', 'owner_id' => $this->m, 'user_id' => $this->m, 'name' => 'entwurf.txt', 'mime' => 'text/plain', 'bytes' => 1, 'file' => 'anh-x.bin', 'created_at' => $now]);
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
        $answers = array_column($d['data']['poll_answers'], 'answer_text');
        sort($answers);
        $this->assertSame(['Meine anonyme Meinung', 'Meine namentliche Meinung'], $answers, 'eigene Antworten, jede einmal');
        $json = (string) json_encode($d);
        foreach (['GEHEIM-P256', 'GEHEIM-AUTH', 'vote_ref', 'Nicht meins'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $json);
        }
        $this->assertSame('https://push.example/abc', $d['data']['push_subscriptions'][0]['endpoint']);
        $this->assertEqualsCanonicalizing(['sig-bild', 'entwurf-bild'], array_column($d['data']['rich_images'], 'uid'));
        $this->assertSame(['entwurf-anhang'], array_column($d['data']['attachments'], 'uid'));
        $this->assertArrayNotHasKey('file', $d['data']['attachments'][0], 'Ablageort auf dem Server nicht');
        $this->assertSame([['area' => 'events', 'at' => 1793000000]], array_map(static fn (array $r): array => ['area' => $r['area'], 'at' => (int) $r['at']], $d['data']['activity_seen'] ?? []), 'zuletzt gesehen, ohne Pseudonym');
        $this->assertSame(['Fehlertext mit Daten anderer'], array_column($d['data']['bug_reports'], 'report'));
        $this->assertStringNotContainsString('BILD', $json);
    }

    public function testExportForAdministration(): void
    {
        $res = $this->call('GET', "/members/{$this->m}/data");
        $this->assertSame(200, $res->status);
        $this->assertStringNotContainsString('push.example', (string) json_encode($res->data), 'Push-Adresse nur in der eigenen Auskunft');
        $this->assertCount(1, $res->data['data']['push_subscriptions'], 'das Abo selbst schon');
        $this->assertSame($this->m, $res->data['person']['id']);
        $this->assertSame(['Meine namentliche Meinung'], array_column($res->data['data']['poll_answers'], 'answer_text'), 'anonyme Antworten nicht für die Verwaltung');
        $this->assertCount(1, $res->data['data']['poll_votes'], 'Teilnahme schon');
        $this->assertSame(['sig-bild'], array_column($res->data['data']['rich_images'], 'uid'), 'Entwürfe nur in der eigenen Auskunft');
        $this->assertArrayNotHasKey('attachments', $res->data['data']);
        $this->assertSame([null], array_map(static fn (array $r) => $r['report'] ?? null, $res->data['data']['bug_reports']), 'Fehlermeldung nur, dass es sie gibt');

        // Wer Konten anlegt, aber kein Admin ist: keine Admins; ohne create_users gar nicht; ohne Anmeldung 401.
        $res = $this->call('POST', '/admin/roles', ['label' => 'Nur Konten', 'capabilities' => TestApp::caps(['member', 'create_users'])]);
        $this->assertLessThan(300, $res->status, (string) json_encode($res->data));
        $accountsOnly = $this->users->add('konten', (string) array_column($this->call('GET', '/admin/roles')->data, 'key', 'label')['Nur Konten']);
        $res = $this->call('POST', '/admin/roles', ['label' => 'Schriftführer', 'capabilities' => TestApp::caps(['member', 'create_users', 'view_personal'])]);
        $this->assertLessThan(300, $res->status, (string) json_encode($res->data));
        $secretary = $this->users->add('schrift', (string) array_column($this->call('GET', '/admin/roles')->data, 'key', 'label')['Schriftführer']);
        $all = array_map(static fn ($c) => $c->value, \KompanieApp\Core\Domain\PersonalDataExport::OTHERS_REQUIRE);
        $res = $this->call('POST', '/admin/roles', ['label' => 'Verwalter', 'capabilities' => TestApp::caps(['member', ...$all])]);
        $this->assertLessThan(300, $res->status, (string) json_encode($res->data));
        $role = (string) array_column($this->call('GET', '/admin/roles')->data, 'key', 'label')['Verwalter'];
        $manager = $this->users->add('verwalter', $role);
        $this->users->current = $accountsOnly;
        $this->assertSame(403, $this->call('GET', "/members/{$this->m}/data")->status, 'ohne view_personal');
        $this->users->current = $secretary;
        $this->assertSame(403, $this->call('GET', "/members/{$this->m}/data")->status, 'ohne Strafen, Anwesenheit, Umfragen …: sähe sonst Verborgenes');
        $this->assertFalse($this->call('GET', "/members/{$this->m}/account")->data['can_export'], 'Knopf ausgeblendet');
        $this->users->current = $manager;
        $this->assertTrue($this->call('GET', "/members/{$this->m}/account")->data['can_export']);
        $this->assertSame(200, $this->call('GET', "/members/{$this->m}/data")->status);
        $this->assertSame(403, $this->call('GET', "/members/{$this->admin}/data")->status);
        $this->users->current = $this->other;
        $this->assertSame(403, $this->call('GET', "/members/{$this->m}/data")->status);
        $this->users->current = null;
        $this->assertSame(401, $this->call('GET', '/me/data')->status);
    }

    /** Ältere Fotos (nur 128 und 480 Pixel): die größte vorhandene Fassung. */
    public function testOlderPhotoWithoutLargeSizes(): void
    {
        $jpeg = base64_encode('alt-480');
        $this->app->db->insert('member_photos', ['user_id' => $this->m, 'version' => 'alt', 'small' => base64_encode('alt-128'), 'large' => $jpeg, 'updated_by' => $this->admin, 'updated_at' => '2020-01-01 00:00:00']);
        $this->users->current = $this->m;
        $this->assertSame($jpeg, $this->call('GET', '/me/data')->data['person']['photo']['base64'] ?? null);
    }

    /**
     * Jede Tabelle der Auskunft gehört zu einem Recht aus PersonalDataExport::OTHERS_REQUIRE (bzw. view_activity, das die
     * Auskunft eigens prüft): Eine neue Tabelle braucht hier eine Zuordnung, sonst sähe man über die Auskunft über andere,
     * was die App einem sonst verbirgt.
     */
    public function testEveryExportedTableHasACapability(): void
    {
        $area = [
            'points' => 'manage_points', 'penalties' => 'manage_penalties', 'rank_history' => 'manage_ranks', 'member_ranks' => 'manage_ranks',
            'member_titles' => 'manage_titles', 'member_roles' => 'create_users', 'attendance_entries' => 'manage_attendance',
            'poll_votes' => 'manage_polls', 'poll_answers' => 'manage_polls', 'birthdays' => 'view_personal', 'join_years' => 'view_personal',
            'member_photos' => 'view_personal', 'former_members' => 'create_users', 'member_contacts' => 'view_personal',
            'bug_reports' => 'view_personal', 'user_signatures' => 'view_personal', 'user_settings' => 'view_personal',
            'board_mails' => 'send_mail', 'mailing_recipients' => 'send_mail', 'activity' => 'view_activity', 'activity_last' => 'view_activity',
        ];
        $required = [...array_map(static fn ($c) => $c->value, \KompanieApp\Core\Domain\PersonalDataExport::OTHERS_REQUIRE), 'view_activity'];
        foreach (array_keys(UserData::tables()) as $table) {
            $this->assertArrayHasKey($table, $area, "$table: welches Recht braucht die Auskunft über andere?");
            $this->assertContains($area[$table], $required, "$table: Recht fehlt in PersonalDataExport::OTHERS_REQUIRE");
        }
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
