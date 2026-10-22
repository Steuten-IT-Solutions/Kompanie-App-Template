<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** Dienstgrade (Issue #9): allein frei, mit Punkten nach Schwellen, Übersicht, Anlage. */
final class RankServiceTest extends TestCase
{
    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $m;

    /** @param array<string, bool> $features */
    private function setUpApp(array $features): void
    {
        $this->users = new FakeUsers();
        $all = ['events' => false, 'calendar' => false, 'news' => false, 'gallery' => false, 'points' => false, 'penalties' => false, 'ranks' => false];
        $this->app = TestApp::create(TestApp::connection(), $this->users, [], null, null, null, new FixedClock(new \DateTimeImmutable('2026-10-20 12:00:00')), $features + $all);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->m = $this->users->add('mitglied', Roles::MEMBER);
        $this->users->current = $this->admin;
    }

    private function call(string $method, string $path, array $body = [], array $query = []): Response
    {
        return $this->app->api()->handle(new Request($method, $path, $query, $body));
    }

    /** @return array<string, int> Bezeichnung => ID */
    private function ranks(array $list): array
    {
        $ids = [];
        foreach ($list as [$title, $min]) {
            $res = $this->call('POST', '/ranks', ['title' => $title, 'min_points' => $min]);
            $this->assertSame(201, $res->status, (string) json_encode($res->data));
            $ids[$title] = $res->data['id'];
        }
        return $ids;
    }

    private function points(int $userId, int $points, string $date): void
    {
        $res = $this->call('POST', '/points', ['user_ids' => [$userId], 'points' => $points, 'reason' => 'Test', 'date' => $date]);
        $this->assertSame(201, $res->status, (string) json_encode($res->data));
    }

    public function testRanksAloneArePromotedAndDemotedFreely(): void
    {
        $this->setUpApp(['ranks' => true]);
        $r = $this->ranks([['Gefreiter', 10], ['Unteroffizier', 50]]);
        $this->assertSame(403, $this->call('GET', '/promotions')->status, 'Übersicht nur mit Punkten');
        $this->assertSame(403, $this->call('GET', '/me/points')->status, 'Punkte aus');

        $res = $this->call('PUT', "/members/{$this->m}/rank", ['rank_id' => $r['Unteroffizier'], 'reason' => 'Verdienste']);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertSame('Unteroffizier', $res->data['rank']['title']);
        $this->assertArrayNotHasKey('points_to_next', $res->data, 'ohne Punkte keine Schwellen');
        $this->assertSame(400, $this->call('PUT', "/members/{$this->m}/rank", ['rank_id' => $r['Unteroffizier']])->status, 'keine Änderung');
        $this->assertSame(200, $this->call('PUT', "/members/{$this->m}/rank", ['rank_id' => null])->status, 'frei degradieren auf keinen');

        $this->users->current = $this->m;
        $me = $this->call('GET', '/me/rank')->data;
        $this->assertNull($me['rank']);
        $this->assertSame(['demotion', 'promotion'], array_column($me['history'], 'kind'));
        $this->assertSame('Verdienste', $me['history'][1]['reason']);
        $this->assertNull($me['history'][1]['points_at_change']);
        $this->assertNull($me['ranks'][0]['min_points'], 'Mitglieder sehen ohne Punkte keine Schwellen');
        $this->assertSame(403, $this->call('PUT', "/members/{$this->m}/rank", ['rank_id' => $r['Gefreiter']])->status, 'nur mit „Dienstgrade verwalten“');
        $this->assertSame(403, $this->call('POST', '/ranks', ['title' => 'X'])->status);
    }

    public function testThresholdsMustRiseWithTheOrder(): void
    {
        $this->setUpApp(['ranks' => true, 'points' => true]);
        $r = $this->ranks([['A', 10], ['B', null], ['C', 30]]);
        $this->assertSame(400, $this->call('POST', '/ranks', ['title' => 'D', 'min_points' => 30])->status, 'gleiche Schwelle über C');
        $this->assertSame(400, $this->call('PATCH', '/ranks/' . $r['C'], ['min_points' => 5])->status, 'C unter A');
        $this->assertSame(400, $this->call('PUT', '/ranks/order', ['ids' => [$r['C'], $r['A'], $r['B']]])->status, 'Reihenfolge gegen die Schwellen');
        $this->assertSame(200, $this->call('PATCH', '/ranks/' . $r['A'], ['active' => false])->status);
        $this->assertSame(200, $this->call('PUT', '/ranks/order', ['ids' => [$r['C'], $r['A'], $r['B']]])->status, 'inaktive zählen nicht');
        $this->assertSame(400, $this->call('PUT', '/ranks/order', ['ids' => [$r['C'], $r['A']]])->status, 'alle angeben');
        $this->assertSame(400, $this->call('POST', '/ranks', ['title' => 'c', 'min_points' => 99])->status, 'Bezeichnung doppelt');
    }

    public function testWithPointsThresholdRulesApplyToAdminsToo(): void
    {
        $this->setUpApp(['ranks' => true, 'points' => true]);
        $r = $this->ranks([['Gefreiter', 10], ['Ehrenrang', null], ['Unteroffizier', 50]]);
        $this->points($this->m, 8, '2026-01-10');
        $res = $this->call('PUT', "/members/{$this->m}/rank", ['rank_id' => $r['Gefreiter']]);
        $this->assertSame(400, $res->status, 'Schwelle nicht erreicht, auch als Admin');
        $this->assertStringContainsString('fehlen 2 Punkte', $res->data['message']);

        $this->points($this->m, 5, '2026-02-01');
        $this->assertSame(200, $this->call('PUT', "/members/{$this->m}/rank", ['rank_id' => $r['Gefreiter']])->status);
        $this->assertSame(200, $this->call('PUT', "/members/{$this->m}/rank", ['rank_id' => $r['Ehrenrang']])->status, 'ohne Schwelle frei');
        $this->assertSame(200, $this->call('PUT', "/members/{$this->m}/rank", ['rank_id' => $r['Gefreiter']])->status, 'von einem Rang ohne Schwelle frei zurück');

        $detail = $this->call('GET', "/members/{$this->m}")->data;
        $this->assertSame('Gefreiter', $detail['rank']['rank']['title']);
        $this->assertSame('Unteroffizier', $detail['rank']['next_rank']['title'], 'Dienstgrade ohne Schwelle übersprungen');
        $this->assertSame(37, $detail['rank']['points_to_next']);
        $this->assertSame(13, $detail['rank']['points']);
        $this->assertSame(13, $detail['rank_history'][0]['points_at_change']);

        // Degradieren auch über der Schwelle (z. B. zu hoch eingestuft), als Degradierung im Verlauf.
        $this->assertFalse($this->call('GET', "/members/{$this->m}")->data['rank']['below_current_threshold']);
        $this->assertSame(200, $this->call('PUT', "/members/{$this->m}/rank", ['rank_id' => null])->status, 'degradieren über der Schwelle');
        $this->assertSame('demotion', $this->call('GET', "/members/{$this->m}")->data['rank_history'][0]['kind']);

        // Danach gilt fürs Befördern wieder die Schwelle.
        $this->points($this->m, -5, '2026-03-01');
        $this->assertSame(400, $this->call('PUT', "/members/{$this->m}/rank", ['rank_id' => $r['Gefreiter']])->status);
    }

    public function testProgressAndPromotionOverview(): void
    {
        $this->setUpApp(['ranks' => true, 'points' => true]);
        $r = $this->ranks([['Gefreiter', 10], ['Unteroffizier', 50]]);
        $a = $this->users->add('anna', Roles::MEMBER);
        $b = $this->users->add('bernd', Roles::MEMBER);
        $this->users->add('gast', Roles::GUEST);
        // Anna: Schwelle am 10.1. erreicht, am 1.2. unterschritten, am 1.3. wieder erreicht.
        $this->points($a, 12, '2026-01-10');
        $this->points($a, -5, '2026-02-01');
        $this->points($a, 6, '2026-03-01');
        // Bernd: Schwelle schon am 5.1. erreicht und gehalten.
        $this->points($b, 20, '2026-01-05');
        $this->points($this->m, 4, '2026-01-01');

        $list = $this->call('GET', '/promotions')->data;
        $this->assertSame(['Bernd', 'Anna', 'Mitglied', 'Admin'], array_column($list, 'name'), 'erreicht (älteste zuerst), dann nach fehlenden Punkten');
        $this->assertSame('2026-01-05', $list[0]['threshold_reached_since']);
        $this->assertSame('2026-03-01', $list[1]['threshold_reached_since'], 'nach Unterschreiten zählt das erneute Erreichen');
        $this->assertSame(-10, $list[0]['points_to_next']);
        $this->assertSame(6, $list[2]['points_to_next']);
        $this->assertSame('Gefreiter', $list[0]['reachable_rank']['title']);
        $this->assertNull($list[2]['threshold_reached_since']);

        $this->assertSame(200, $this->call('PUT', "/members/$b/rank", ['rank_id' => $r['Gefreiter']])->status);
        $this->users->current = $b;
        $me = $this->call('GET', '/me/rank')->data;
        $this->assertSame('Unteroffizier', $me['next_rank']['title']);
        $this->assertSame(30, $me['points_to_next']);
        $this->assertSame([10, 50], array_column($me['ranks'], 'min_points'));
        $this->assertSame('Gefreiter', $this->call('GET', '/me')->data['rank']['title']);
    }

    public function testCreateMemberWithInitialPointsAndRank(): void
    {
        $this->setUpApp(['ranks' => true, 'points' => true]);
        $r = $this->ranks([['Gefreiter', 30], ['Unteroffizier', 50]]);
        $before = count($this->users->users);
        $res = $this->call('POST', '/users', ['name' => 'Zu Wenig', 'initial_points' => 20, 'rank_id' => $r['Gefreiter']]);
        $this->assertSame(400, $res->status);
        $this->assertSame('Für „Gefreiter“ sind mindestens 30 Punkte nötig.', $res->data['message']);
        $this->assertCount($before, $this->users->users, 'kein Konto angelegt');
        $this->assertSame(400, $this->call('POST', '/users', ['name' => 'X', 'initial_points' => -1])->status);
        $this->assertSame(400, $this->call('POST', '/users', ['name' => 'X', 'level' => TestApp::candidateRole($this->app), 'rank_id' => $r['Gefreiter'], 'initial_points' => 40])->status, 'nur für Mitglieder');

        $res = $this->call('POST', '/users', ['name' => 'Neu Dabei', 'initial_points' => 40, 'initial_points_date' => '2026-05-01', 'rank_id' => $r['Gefreiter']]);
        $this->assertSame(201, $res->status, (string) json_encode($res->data));
        $id = $res->data['id'];
        $detail = $this->call('GET', "/members/$id")->data;
        $this->assertSame('Gefreiter', $detail['rank']['rank']['title']);
        $this->assertSame(40, $detail['points']);
        $this->assertSame('Anfangsbestand bei Übernahme', $detail['point_list'][0]['reason']);
        $this->assertSame('2026-05-01', $detail['point_list'][0]['date']);
        $this->assertSame(['initial'], array_column($detail['rank_history'], 'kind'));
        $this->assertSame(10, $detail['rank']['points_to_next'], 'bis Unteroffizier (50)');
        $this->assertNull($detail['rank']['threshold_reached_since']);
    }

    public function testCreateRightsAndDeletion(): void
    {
        $this->setUpApp(['ranks' => true, 'points' => true]);
        $r = $this->ranks([['Gefreiter', null]]);
        // Konten anlegen ohne Dienstgrade/Punkte verwalten: keine Anfangswerte.
        $key = $this->app->roles()->create(['label' => 'Konten', 'capabilities' => ['member', 'create_users']])['key'];
        $this->users->current = $this->users->add('konten', $key);
        $this->assertSame(403, $this->call('POST', '/users', ['name' => 'X', 'rank_id' => $r['Gefreiter']])->status);
        $this->assertSame(403, $this->call('POST', '/users', ['name' => 'X', 'initial_points' => 5])->status);
        $this->assertSame(201, $this->call('POST', '/users', ['name' => 'Ohne Extras'])->status);

        $this->users->current = $this->admin;
        $this->assertSame(200, $this->call('PUT', "/members/{$this->m}/rank", ['rank_id' => $r['Gefreiter']])->status);
        $this->assertSame(400, $this->call('DELETE', '/ranks/' . $r['Gefreiter'])->status, 'vergeben: nur deaktivieren');
        $this->assertSame(200, $this->call('PATCH', '/ranks/' . $r['Gefreiter'], ['active' => false])->status);
        $this->assertSame(400, $this->call('PUT', "/members/{$this->m}/rank", ['rank_id' => $r['Gefreiter']])->status, 'deaktiviert');
        $this->assertSame('Gefreiter', $this->call('GET', "/members/{$this->m}")->data['rank']['rank']['title'], 'bleibt zugewiesen');
        $this->assertSame([], $this->call('GET', '/ranks')->data, 'Mitglieder sehen nur aktive');
        $this->assertCount(1, $this->call('GET', '/ranks', [], ['all' => '1'])->data);
    }

    public function testFeaturesOffBlockRoutesAndKeepAssignments(): void
    {
        $this->setUpApp(['ranks' => true]);
        $r = $this->ranks([['Gefreiter', 10]]);
        $this->assertSame(200, $this->call('PUT', "/members/{$this->m}/rank", ['rank_id' => $r['Gefreiter']])->status, 'ohne Punkte frei');
        $this->app->features()->update(['points' => true]);
        $this->assertSame('Gefreiter', $this->call('GET', "/members/{$this->m}")->data['rank']['rank']['title'], 'Punkte einschalten ändert nichts');
        $this->assertTrue($this->call('GET', "/members/{$this->m}")->data['rank']['below_current_threshold']);
        $this->app->features()->update(['ranks' => false]);
        foreach ([['GET', '/ranks'], ['GET', '/promotions'], ['PUT', "/members/{$this->m}/rank"]] as [$method, $path]) {
            $res = $this->call($method, $path);
            $this->assertSame('feature_disabled', $res->data['code'] ?? null, "$method $path");
        }
        $this->assertArrayNotHasKey('rank', $this->call('GET', "/members/{$this->m}")->data);
        $this->assertArrayNotHasKey('rank', $this->call('GET', '/me')->data);
    }

    public function testPushOnRankChange(): void
    {
        $this->setUpApp(['ranks' => true]);
        $r = $this->ranks([['Gefreiter', null]]);
        $user = $this->users->find($this->m);
        $this->assertInstanceOf(User::class, $user);
        $this->assertContains('ranks', $this->app->push()->availableTypes($user));
        $this->assertSame(200, $this->call('PUT', "/members/{$this->m}/rank", ['rank_id' => $r['Gefreiter']])->status);
    }

    public function testFormerMemberCanOnlyLoseTheRank(): void
    {
        $this->setUpApp(['ranks' => true, 'points' => true]);
        $r = $this->ranks([['Gefreiter', 10]]);
        $this->points($this->m, 20, '2026-01-01');
        $this->call('PUT', "/members/{$this->m}/rank", ['rank_id' => $r['Gefreiter']]);
        $this->users->setRole($this->m, Roles::GUEST);
        $this->assertSame(400, $this->call('DELETE', "/ranks/{$r['Gefreiter']}")->status, 'noch vergeben');
        // Über der Schwelle verlieren auch frühere Mitglieder den Dienstgrad.
        $res = $this->call('PUT', "/members/{$this->m}/rank", ['rank_id' => null]);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertSame(200, $this->call('DELETE', "/ranks/{$r['Gefreiter']}")->status);
        [$again] = array_values($this->ranks([['Gefreiter', 10]]));
        $this->assertSame(400, $this->call('PUT', "/members/{$this->m}/rank", ['rank_id' => $again])->status, 'Gäste bekommen keinen');
    }

    public function testSameDayEntriesAndNullDatesInOverview(): void
    {
        $this->setUpApp(['ranks' => true, 'points' => true]);
        $this->ranks([['Gefreiter', 10]]);
        $a = $this->users->add('anna', Roles::MEMBER);
        $this->points($a, 15, '2026-01-10');
        // Am selben Tag erst abziehen, dann wieder gutschreiben: Am Ende des Tages nie unter der Schwelle.
        $this->points($a, -10, '2026-02-01');
        $this->points($a, 10, '2026-02-01');
        $row = array_column($this->call('GET', '/promotions')->data, null, 'user_id')[$a];
        $this->assertSame('2026-01-10', $row['threshold_reached_since']);
    }

    public function testOldBackupWithoutRankTablesEmptiesThem(): void
    {
        $this->setUpApp(['ranks' => true]);
        $r = $this->ranks([['Gefreiter', null]]);
        $this->call('PUT', "/members/{$this->m}/rank", ['rank_id' => $r['Gefreiter']]);
        (new \KompanieApp\Core\Storage\Backup($this->app->db))->restore(['points' => []], \KompanieApp\Core\Storage\Backup::CORE_TABLES);
        $this->assertSame([], $this->app->ranks()->repository()->assignments());
        $this->assertSame([], $this->app->ranks()->repository()->all(false));
    }
}
