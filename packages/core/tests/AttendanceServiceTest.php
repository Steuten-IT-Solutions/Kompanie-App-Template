<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Storage\Sql\PdoConnection;
use KompanieApp\Core\Tests\Support\CountingPdo;
use KompanieApp\Core\Tests\Support\FakeHttpClient;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\MemorySettings;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** Anwesenheit (Issue #21): Listen je Termin, Status, Zähler nach Gruppen, Vorschlag für Punkte, Rechte. */
final class AttendanceServiceTest extends TestCase
{
    private FakeUsers $users;
    private App $app;
    private string $ics = '';
    private int $admin;
    private int $m;
    private int $cand;

    /** @param array<string, bool> $features */
    private function setUpApp(array $features = ['attendance' => true, 'calendar' => true, 'points' => true, 'penalties' => true], ?\PDO $pdo = null): void
    {
        $this->users = new FakeUsers();
        $this->ics = "BEGIN:VCALENDAR\r\n"
            . "BEGIN:VEVENT\r\nUID:probe\r\nDTSTART:20261015T170000Z\r\nDTEND:20261015T190000Z\r\nSUMMARY:Probe\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:fest\r\nDTSTART;VALUE=DATE:20261101\r\nDTEND;VALUE=DATE:20261102\r\nSUMMARY:Fest\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:alt\r\nDTSTART:20260601T170000Z\r\nSUMMARY:Alt\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $http = new FakeHttpClient(fn () => ['status' => 200, 'body' => $this->ics]);
        $all = ['events' => false, 'calendar' => false, 'news' => false, 'gallery' => false, 'points' => false, 'penalties' => false, 'ranks' => false, 'titles' => false, 'attendance' => false];
        $db = $pdo === null ? TestApp::connection() : new PdoConnection($pdo, 'kompanie_');
        $this->app = TestApp::create($db, $this->users, [], $http, null, new MemorySettings(['calendar_ics_url' => 'https://example.test/k.ics']), new FixedClock(new \DateTimeImmutable('2026-10-20 12:00:00')), $features + $all);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->m = $this->users->add('mitglied', Roles::MEMBER);
        $this->cand = $this->users->add('anwaerter', TestApp::candidateRole($this->app));
        $this->users->add('gast', Roles::GUEST);
        $this->users->current = $this->admin;
    }

    private function call(string $method, string $path, array $body = [], array $query = []): Response
    {
        return $this->app->api()->handle(new Request($method, $path, $query, $body));
    }

    /** @return array<string, mixed> */
    private function createForEvent(string $key = ''): array
    {
        if ($key === '') {
            $key = (string) $this->call('GET', '/attendance/events')->data[0]['key'];
        }
        $res = $this->call('PUT', '/attendance', ['source' => 'calendar', 'event_key' => $key]);
        $this->assertContains($res->status, [200, 201], (string) json_encode($res->data));
        return $res->data;
    }

    public function testCalendarListKeepsSnapshot(): void
    {
        $this->setUpApp();
        $events = $this->call('GET', '/attendance/events')->data;
        $this->assertSame(['Probe'], array_column($events, 'title'), 'nur die letzten Wochen bis heute');
        $this->assertSame('2026-10-15', $events[0]['date']);
        $this->assertNull($events[0]['list_id']);

        $res = $this->call('PUT', '/attendance', ['source' => 'calendar', 'event_key' => $events[0]['key'], 'entries' => [$this->m => 'present', $this->cand => 'absent']]);
        $this->assertSame(201, $res->status, (string) json_encode($res->data));
        $list = $res->data;
        $this->assertSame(['Probe', '2026-10-15'], [$list['title'], $list['date']]);
        $this->assertSame(['present' => 1, 'excused' => 0, 'absent' => 1], $list['counts']);
        $people = array_column($list['people'], null, 'id');
        $this->assertCount(3, $people, 'alle mit einer Rolle, ohne Gäste');
        $this->assertTrue($people[$this->m]['member']);
        $this->assertFalse($people[$this->cand]['member'], 'Anwärter: keine Punkte und Strafen');

        // Dieselbe Liste beim zweiten Öffnen; die Momentaufnahme bleibt, auch ohne den Termin im Kalender.
        $this->assertSame(200, $this->call('PUT', '/attendance', ['source' => 'calendar', 'event_key' => $events[0]['key']])->status);
        $this->assertSame($list['id'], $this->call('GET', '/attendance/events')->data[0]['list_id']);
        $this->ics = "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n";
        $this->app->calendar()->clearCache();
        $this->assertSame('Probe', $this->call('GET', '/attendance/' . $list['id'])->data['title']);
        $this->assertSame(400, $this->call('PUT', '/attendance', ['source' => 'calendar', 'event_key' => 'gibtsnicht'])->status);
        $this->assertSame(['Probe'], array_column($this->call('GET', '/attendance')->data, 'title'));
    }

    public function testFreeListAndStatusChanges(): void
    {
        $this->setUpApp(['attendance' => true]);
        $this->assertSame([], $this->call('GET', '/attendance/events')->data, 'ohne Kalender keine Termine zur Auswahl');
        $this->assertSame(400, $this->call('PUT', '/attendance', ['source' => 'free', 'title' => ' ', 'date' => '2026-10-19'])->status);
        $this->assertSame(400, $this->call('PUT', '/attendance', ['source' => 'free', 'title' => 'Arbeitsdienst', 'date' => '19.10.2026'])->status);
        $res = $this->call('PUT', '/attendance', ['source' => 'free', 'title' => 'Arbeitsdienst', 'date' => '2026-10-19']);
        $this->assertSame(201, $res->status);
        $id = $res->data['id'];

        $this->assertSame(400, $this->call('PUT', '/attendance', ['id' => $id, 'entries' => [$this->m => 'krank']])->status);
        $guest = $this->users->add('gast2', Roles::GUEST);
        $this->assertSame(400, $this->call('PUT', '/attendance', ['id' => $id, 'entries' => [$guest => 'present']])->status, 'Gäste stehen nicht in der Liste');
        $this->call('PUT', '/attendance', ['id' => $id, 'entries' => [$this->m => 'excused', $this->cand => 'present']]);
        $res = $this->call('PUT', '/attendance', ['id' => $id, 'title' => 'Arbeitseinsatz', 'entries' => [$this->cand => null]]);
        $this->assertSame('Arbeitseinsatz', $res->data['title'], 'freie Listen lassen sich umbenennen');
        $this->assertSame(['present' => 0, 'excused' => 1, 'absent' => 0], $res->data['counts']);
        $this->assertSame('Admin', $res->data['updated_by_name']);

        $this->users->current = $this->m;
        $mine = $this->call('GET', '/me/attendance')->data;
        $this->assertSame([['list_id' => $id, 'title' => 'Arbeitseinsatz', 'date' => '2026-10-19', 'status' => 'excused', 'updated_by_name' => 'Admin']], $mine['entries']);
        $this->assertSame(1, $mine['counts']['excused']);
        $this->assertSame(403, $this->call('GET', '/attendance')->status, 'Mitglied ohne Recht');
        $this->assertSame(403, $this->call('GET', "/attendance/$id")->status);

        $this->users->current = $this->admin;
        $this->assertSame(200, $this->call('DELETE', "/attendance/$id")->status);
        $this->assertSame(404, $this->call('GET', "/attendance/$id")->status);
    }

    public function testPointsFromProposalOnlyOnce(): void
    {
        $this->setUpApp();
        $list = $this->createForEvent();
        $other = $this->users->add('anna', Roles::MEMBER);
        $this->call('PUT', '/attendance', ['id' => $list['id'], 'entries' => [$this->m => 'present', $other => 'present']]);
        $role = $this->call('POST', '/admin/roles', ['label' => 'Jung', 'capabilities' => ['member'], 'points_factor' => 0.5]);
        $this->assertSame(201, $role->status);
        $this->users->setRole($other, (string) array_column($this->call('GET', '/admin/roles')->data, 'key', 'label')['Jung']);

        $res = $this->call('POST', '/points', ['user_ids' => [$this->m], 'points' => 4, 'reason' => 'Probe', 'attendance_id' => $list['id']]);
        $this->assertSame(201, $res->status, (string) json_encode($res->data));
        $this->assertSame($list['id'], $res->data[0]['attendance_id']);
        // Noch einmal für beide: nur, wer noch keine hat; mit dem Faktor der Rolle.
        $res = $this->call('POST', '/points', ['user_ids' => [$this->m, $other], 'points' => 4, 'reason' => 'Probe', 'attendance_id' => $list['id']]);
        $this->assertSame([$other], array_column($res->data, 'user_id'));
        $this->assertSame(2, $res->data[0]['points'], 'Faktor 0,5');
        $this->assertSame(400, $this->call('POST', '/points', ['user_ids' => [$this->m], 'points' => 4, 'reason' => 'Probe', 'attendance_id' => $list['id']])->status, 'schon gebucht');
        $this->assertSame(400, $this->call('POST', '/points', ['user_ids' => [$this->m], 'points' => 4, 'reason' => 'Probe', 'attendance_id' => 999])->status);
        $booked = array_column(array_column($this->call('GET', '/attendance/' . $list['id'])->data['people'], null, 'id'), 'booked', 'id');
        $this->assertTrue($booked[$this->m] && $booked[$other]);
        $this->assertFalse($booked[$this->cand]);

        // Storniert: wieder buchbar.
        $this->call('DELETE', '/points/' . $res->data[0]['id']);
        $this->assertFalse(array_column($this->call('GET', '/attendance/' . $list['id'])->data['people'], 'booked', 'id')[$other]);
        // Liste gelöscht: Punkte bleiben, ohne Verbindung zur Liste.
        $this->assertSame(200, $this->call('DELETE', '/attendance/' . $list['id'])->status);
        $this->assertSame([], $this->app->db->select('SELECT id FROM {points} WHERE attendance_id IS NOT NULL'));
        $this->assertCount(1, $this->app->db->select('SELECT id FROM {points} WHERE deleted_at IS NULL'), 'die nicht stornierte Buchung bleibt');

        // Übersicht zählt nur, wer heute in den Listen steht (keine Gäste).
        $list = $this->createForEvent();
        $this->call('PUT', '/attendance', ['id' => $list['id'], 'entries' => [$this->m => 'present', $other => 'present']]);
        $this->users->setRole($other, Roles::GUEST);
        $this->assertSame(1, $this->call('GET', '/attendance')->data[0]['counts']['present']);
    }

    public function testGroupsForCounter(): void
    {
        $this->setUpApp();
        $list = $this->createForEvent();
        $chief = $this->users->add('leitung', 'points');
        $this->call('PUT', '/attendance', ['id' => $list['id'], 'entries' => [$this->m => 'present', $this->admin => 'present', $chief => 'present', $this->cand => 'present']]);
        $groups = fn (): array => array_column($this->call('GET', '/attendance/' . $list['id'])->data['counts_by_group']['present'], 'count', 'group');
        $this->assertSame([Roles::MEMBER => 3, TestApp::candidateRole($this->app) => 1], $groups(), 'ohne Einstellung: mit Mitgliederbereich als Mitglied; Mitglieder vor Anwärtern');

        $this->assertSame(200, $this->call('PUT', '/attendance/groups', ['groups' => ['points' => null, TestApp::candidateRole($this->app) => Roles::MEMBER]])->status);
        $this->assertSame([Roles::MEMBER => 3, 'points' => 1], $groups());
        $res = $this->call('PUT', '/attendance/groups', ['groups' => [Roles::ADMIN => 'points', 'points' => Roles::MEMBER]]);
        $this->assertSame(400, $res->status, 'keine Ketten');
        $this->assertSame(400, $this->call('PUT', '/attendance/groups', ['groups' => [Roles::GUEST => Roles::MEMBER]])->status);
        $this->assertSame(400, $this->call('PUT', '/attendance/groups', ['groups' => ['points' => 'gibtsnicht']])->status);
        $list = array_column($this->call('GET', '/attendance/groups')->data, 'counts_as', 'key');
        $this->assertArrayNotHasKey(Roles::GUEST, $list);
        $this->assertNull($list['points']);
        $this->assertSame(Roles::MEMBER, $list[TestApp::candidateRole($this->app)]);

        // Mitgliederliste: dieselbe Gruppe je Person für den Zähler über der Liste.
        $members = array_column($this->call('GET', '/members', [], ['candidates' => '1'])->data, 'group', 'id');
        $this->assertSame(['points', Roles::MEMBER, Roles::MEMBER], [$members[$chief], $members[$this->cand], $members[$this->m]]);

        $this->users->current = $chief;
        $this->assertSame(403, $this->call('GET', '/attendance/groups')->status, 'nur Admins');
    }

    public function testFeatureOffAndQueryCount(): void
    {
        $pdo = new CountingPdo('sqlite::memory:');
        $this->setUpApp(['attendance' => true, 'points' => true], $pdo);
        $res = $this->call('PUT', '/attendance', ['source' => 'free', 'title' => 'Übung', 'date' => '2026-10-19']);
        $id = $res->data['id'];
        $entries = [];
        for ($i = 0; $i < 40; $i++) {
            $entries[$this->users->add("p$i", Roles::MEMBER)] = $i % 3 === 0 ? 'absent' : 'present';
        }
        $pdo->log = [];
        $this->call('GET', "/attendance/$id");
        $read = count($pdo->log);
        $this->assertLessThanOrEqual(5, $read, 'Liste laden: feste Zahl von Abfragen');
        $pdo->log = [];
        $this->call('PUT', '/attendance', ['id' => $id, 'entries' => $entries]);
        $this->assertLessThanOrEqual(40 + 10, count($pdo->log), 'je Person höchstens ein Schreibzugriff');
        $pdo->log = [];
        $this->call('PUT', '/attendance', ['id' => $id, 'entries' => $entries]);
        $this->assertSame([], array_values(array_filter($pdo->writes(), static fn (string $q): bool => str_contains($q, 'attendance_entries'))), 'unverändert: nichts schreiben');

        $this->app->features()->update(['attendance' => false]);
        foreach ([['GET', '/attendance'], ['PUT', '/attendance'], ['GET', '/attendance/events'], ['GET', "/attendance/$id"], ['DELETE', "/attendance/$id"], ['GET', '/attendance/groups'], ['GET', '/me/attendance']] as [$method, $path]) {
            $res = $this->call($method, $path);
            $this->assertSame(403, $res->status, "$method $path");
            $this->assertSame('feature_disabled', $res->data['code'] ?? null, "$method $path");
        }
        $res = $this->call('POST', '/points', ['user_ids' => [$this->m], 'points' => 1, 'reason' => 'x', 'attendance_id' => $id]);
        $this->assertSame(403, $res->status, 'Vorschlag aus einer Liste nur mit dem Bereich');
    }

    public function testDeletedAccountAndBackup(): void
    {
        $this->setUpApp(['attendance' => true]);
        $id = $this->call('PUT', '/attendance', ['source' => 'free', 'title' => 'Übung', 'date' => '2026-10-19', 'entries' => [$this->m => 'present']])->data['id'];
        $backup = new \KompanieApp\Core\Storage\Backup($this->app->db);
        $tables = $backup->dump(\KompanieApp\Core\Storage\Backup::CORE_TABLES);
        $this->assertCount(1, $tables['attendance_entries']);
        $this->app->userDeleted($this->m);
        $this->assertSame([], $this->app->attendance()->repository()->entries($id));
        $backup->restore($tables, \KompanieApp\Core\Storage\Backup::CORE_TABLES);
        $this->assertCount(1, $this->app->attendance()->repository()->entries($id));
    }
}
