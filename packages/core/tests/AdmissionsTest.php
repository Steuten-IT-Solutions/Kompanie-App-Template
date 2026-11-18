<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\AdmissionService;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Storage\Backup;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * Aufnahmen (AdmissionService): Anwärter als Grund- oder Zusatzrolle, Voraussetzung je Rolle (Teilnahmen an einer
 * Terminart, Mindestdauer ab „Anwärter seit“), Übersicht, Zahl, Aufnehmen mit Eintritt, Hinweis nach dem Speichern.
 */
final class AdmissionsTest extends TestCase
{
    private FakeUsers $users;
    private App $app;
    private FixedClock $clock;
    private int $admin;
    private int $clerk;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->clock = new FixedClock(new \DateTimeImmutable('2026-11-14 11:00:00', new \DateTimeZone('UTC')));
        $this->app = TestApp::create(TestApp::connection(), $this->users, clock: $this->clock);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->users->current = $this->admin;
        $this->clerk = $this->users->add('schrift', $this->app->roles()->create(['label' => 'Schriftführer', 'capabilities' => TestApp::caps(['member', 'view_accounts', 'create_users', 'view_attendance', 'manage_attendance'])])['key']);
        $this->ok('PUT', '/admin/event-kinds', ['kinds' => ['Versammlung', 'Arbeitsdienst']]);
    }

    private function call(string $method, string $path, array $body = [], ?int $as = null): Response
    {
        $this->users->current = $as ?? $this->admin;
        $res = $this->app->api()->handle(new Request($method, $path, [], $body));
        $this->users->current = $this->admin;
        return $res;
    }

    /** @return array<string, mixed> */
    private function ok(string $method, string $path, array $body = [], ?int $as = null): array
    {
        $res = $this->call($method, $path, $body, $as);
        $this->assertLessThan(300, $res->status, $method . ' ' . $path . ': ' . json_encode($res->data));
        return (array) $res->data;
    }

    /** Anwärter als Grundrolle (ohne Mitgliederbereich, „Als Mitglied aufnehmen“). */
    private function baseRole(array $rule = []): string
    {
        $key = $this->app->roles()->create(['label' => 'Anwärter', 'capabilities' => TestApp::caps(['calendar']), 'promotable' => true, 'new_accounts' => true])['key'];
        if ($rule !== []) {
            $this->ok('PATCH', "/admin/roles/$key", $rule);
        }
        return $key;
    }

    /** Freie Anwesenheitsliste mit Art; $present anwesend. */
    private function list(string $date, string $kind, array $present, array $excused = []): int
    {
        $entries = [];
        foreach ($present as $id) {
            $entries[(string) $id] = 'present';
        }
        foreach ($excused as $id) {
            $entries[(string) $id] = 'excused';
        }
        $list = $this->ok('PUT', '/attendance', ['source' => 'free', 'title' => 'Treffen', 'date' => $date, 'kind' => $kind, 'entries' => $entries]);
        return (int) $list['id'];
    }

    /** @return array<string, mixed> Eintrag aus GET /admissions */
    private function entry(int $id): array
    {
        foreach ($this->ok('GET', '/admissions', [], $this->clerk) as $e) {
            if ($e['id'] === $id) {
                return $e;
            }
        }
        $this->fail('fehlt in /admissions: ' . $id);
    }

    public function testRuleOnRoleIsValidated(): void
    {
        $key = $this->baseRole();
        $roles = array_column($this->ok('PATCH', "/admin/roles/$key", ['admission_kind' => 'Versammlung', 'admission_count' => 6, 'admission_months' => 12]), null, 'key');
        $this->assertSame(['admission_extra' => false, 'admission_kind' => 'Versammlung', 'admission_count' => 6, 'admission_months' => 12], array_intersect_key($roles[$key], array_flip(['admission_extra', 'admission_kind', 'admission_count', 'admission_months'])));
        $this->assertSame(400, $this->call('PATCH', "/admin/roles/$key", ['admission_kind' => 'Feier'])->status);
        $this->assertSame(400, $this->call('PATCH', "/admin/roles/$key", ['admission_count' => 101])->status);
        $this->assertSame(400, $this->call('PATCH', "/admin/roles/$key", ['admission_months' => -1])->status);
        $this->assertSame(400, $this->call('PATCH', "/admin/roles/$key", ['admission_kind' => '', 'admission_count' => 3])->status, 'Teilnahmen nur mit Terminart');
        $this->assertSame(400, $this->call('PATCH', '/admin/roles/member', ['admission_extra' => true])->status, 'nur eigene Rollen');
        $this->assertSame(400, $this->call('PATCH', "/admin/roles/$key", ['label' => 'Neu', 'admission_count' => 'x'])->status);
        $this->assertSame('Anwärter', $this->app->roles()->label($key), 'Fehler ändern auch die Rolle nicht');
        $this->assertSame(['Versammlung', 6, 12], [$this->app->admissions()->rule($key)['kind'], $this->app->admissions()->rule($key)['count'], $this->app->admissions()->rule($key)['months']]);
        $this->assertSame(403, $this->call('PATCH', "/admin/roles/$key", ['admission_count' => 1], $this->clerk)->status);

        // Beim Anlegen gleich mit; ungültige Angaben legen keine Rolle an
        $count = count($this->app->roles()->all());
        $this->assertSame(400, $this->call('POST', '/admin/roles', ['label' => 'Probe', 'admission_kind' => 'Feier'])->status);
        $this->assertCount($count, $this->app->roles()->all());
        $created = array_column($this->ok('POST', '/admin/roles', ['label' => 'Probe', 'admission_extra' => true, 'admission_months' => 6]), null, 'label')['Probe'];
        $this->assertSame([true, 6], [$created['admission_extra'], $created['admission_months']]);
    }

    public function testBaseRoleCandidateFulfilsByAttendanceAndMonths(): void
    {
        $key = $this->baseRole(['admission_kind' => 'Versammlung', 'admission_count' => 2, 'admission_months' => 1]);
        // Anlegen in der App: „Anwärter seit“ = heute
        $fritz = (int) $this->ok('POST', '/users', ['name' => 'Fritz Neu', 'level' => $key])['id'];
        $e = $this->entry($fritz);
        $this->assertSame(['base', '2026-11-14', 'open', 0, 2, '2026-12-14'], [$e['way'], $e['since'], $e['status'], $e['attended'], $e['missing'], $e['months_on']]);

        // Es zählen alle Teilnahmen, auch vor „Anwärter seit“; entschuldigt und andere Arten nicht
        $this->list('2026-10-01', 'Versammlung', [$fritz]);
        $this->list('2026-10-15', 'Versammlung', [], [$fritz]);
        $this->list('2026-10-20', 'Arbeitsdienst', [$fritz]);
        $this->list('2026-11-12', 'Versammlung', [$fritz]);
        $e = $this->entry($fritz);
        $this->assertSame([2, 0, 'open'], [$e['attended'], $e['missing'], $e['status']], 'Mindestdauer fehlt noch');

        $this->ok('PUT', "/members/$fritz/candidate-since", ['since' => '01.09.2026'], $this->clerk);
        $e = $this->entry($fritz);
        $this->assertSame(['2026-09-01', 'fulfilled', '2026-11-12'], [$e['since'], $e['status'], $e['fulfilled_on']]);
        $this->assertSame(1, $this->ok('GET', '/config', [], $this->clerk)['admissionsDue']);
        $this->assertNull($this->ok('GET', '/config', [], $fritz)['admissionsDue'] ?? null);
        $this->assertSame(400, $this->call('PUT', "/members/$fritz/candidate-since", ['since' => '2026'], $this->clerk)->status);
        $this->assertSame(400, $this->call('PUT', "/members/$fritz/candidate-since", ['since' => '01.01.2027'], $this->clerk)->status, 'Zukunft');
        $this->assertSame(403, $this->call('GET', '/admissions', [], $fritz)->status);
    }

    public function testExtraRoleCandidateAndAdmission(): void
    {
        $key = $this->app->roles()->create(['label' => 'Anwärter', 'capabilities' => []])['key'];
        $old = $this->users->add('alt', Roles::MEMBER);
        $this->ok('PATCH', "/members/$old/role", ['extra_roles' => [$key]]);
        $this->assertSame([], $this->ok('GET', '/admissions', [], $this->clerk), 'noch keine Anwärter-Rolle');
        $this->assertSame(0, $this->ok('GET', '/config', [], $this->clerk)['admissionsDue'], 'Kachel auch ohne Anwärter-Rolle (Seite erklärt die Einrichtung)');

        $this->clock->at = new \DateTimeImmutable('2026-11-14 12:00:00', new \DateTimeZone('UTC'));
        $this->ok('PATCH', "/admin/roles/$key", ['admission_extra' => true, 'admission_kind' => 'Versammlung', 'admission_count' => 1]);
        $this->clock->at = new \DateTimeImmutable('2026-11-14 13:00:00', new \DateTimeZone('UTC'));
        $new = $this->users->add('neu', Roles::MEMBER);
        $this->ok('PATCH', "/members/$new/role", ['extra_roles' => [$key]]);
        $this->assertNull($this->entry($old)['since'], 'vor dem Anhaken zugeordnet: nachtragen');
        $this->assertSame(['extra', '2026-11-14'], [$this->entry($new)['way'], $this->entry($new)['since']]);

        $this->list('2026-11-13', 'Versammlung', [$old]);
        $this->assertSame('fulfilled', $this->entry($old)['status']);
        $this->assertSame('open', $this->entry($new)['status']);
        $this->assertSame('open', $this->ok('GET', "/members/$new", [], $this->clerk)['admission']['status']);
        $this->assertNull($this->ok('GET', "/members/{$this->clerk}")['admission']);

        // Aufnehmen: Zusatzrolle weg, Eintritt gesetzt, wenn leer
        $this->ok('PUT', "/members/$new/join-year", ['date' => '2020'], $this->clerk);
        $res = $this->ok('POST', "/members/$new/admit", [], $this->clerk);
        $this->assertSame([Roles::MEMBER, [], null], [$res['level'], $res['extra_roles'], $res['join_date']]);
        $this->assertSame(2020, $this->app->joinYears()->of($new)['year'] ?? null, 'Eintritt bleibt');
        $res = $this->ok('POST', "/members/$old/admit", [], $this->clerk);
        $this->assertSame('2026-11-14', $res['join_date']);
        $this->assertSame('2026-11-14', $this->app->joinYears()->of($old)['date'] ?? null);
        $this->assertSame([], $this->ok('GET', '/admissions', [], $this->clerk));
        $this->assertSame(400, $this->call('POST', "/members/$old/admit", [], $this->clerk)->status, 'kein Anwärter mehr');
        $this->assertSame(400, $this->call('POST', "/members/{$this->clerk}/admit", [], $this->clerk)->status, 'nicht sich selbst');
    }

    public function testAdmitBaseRoleWithoutRequirementAndHintAfterSaving(): void
    {
        $key = $this->baseRole();
        $anna = $this->users->add('anna', $key);
        $e = $this->entry($anna);
        $this->assertSame(['none', null], [$e['status'], $e['since']], 'ohne Voraussetzung; vorhandene ohne Datum');

        $this->ok('PATCH', "/admin/roles/$key", ['admission_kind' => 'Versammlung', 'admission_count' => 1]);
        $list = $this->ok('PUT', '/attendance', ['source' => 'free', 'title' => 'Versammlung', 'date' => '2026-11-13', 'entries' => [(string) $anna => 'present']], $this->clerk);
        $this->assertSame([['user_id' => $anna, 'name' => 'Anna']], $list['admissions_new']);
        $list = $this->ok('PUT', '/attendance', ['id' => $list['id'], 'entries' => [(string) $anna => 'present']], $this->clerk);
        $this->assertSame([], $list['admissions_new'], 'nur neu erfüllt');

        $res = $this->ok('POST', "/members/$anna/admit", [], $this->clerk);
        $this->assertSame(Roles::MEMBER, $res['level']);
        $this->assertSame([], $this->app->admissions()->repository()->all());
    }

    public function testFlaggedBaseRoleWithMemberAreaIsCandidate(): void
    {
        // Anwärter-Rolle mit „Gehört zur Kompanie“ (kein promotable möglich) als Rolle der Person
        $key = $this->app->roles()->create(['label' => 'Anwärter', 'capabilities' => TestApp::caps(['member'])])['key'];
        $this->assertFalse($this->app->roles()->promotable($key));
        $this->ok('PATCH', "/admin/roles/$key", ['admission_extra' => true, 'admission_kind' => 'Versammlung', 'admission_count' => 1]);
        $karl = (int) $this->ok('POST', '/users', ['name' => 'Karl Neu', 'level' => $key])['id'];
        $e = $this->entry($karl);
        $this->assertSame(['base', '2026-11-14', 'open'], [$e['way'], $e['since'], $e['status']], 'Anwärter seit beim Anlegen');

        $this->list('2026-11-13', 'Versammlung', [$karl]);
        $this->assertSame('fulfilled', $this->entry($karl)['status']);
        $res = $this->ok('POST', "/members/$karl/admit", [], $this->clerk);
        $this->assertSame(Roles::MEMBER, $res['level']);
        $this->assertSame([], $this->ok('GET', '/admissions', [], $this->clerk));
        // Der Knopf „zum Mitglied machen“ bleibt auf freigegebene Rollen beschränkt
        $other = $this->users->add('otto', $key);
        $this->ok('PATCH', "/admin/roles/$key", ['admission_extra' => false]);
        $this->assertSame(400, $this->call('POST', "/members/$other/promote", [], $this->clerk)->status);
    }

    public function testListWithSeveralKindsCountsForEach(): void
    {
        $key = $this->baseRole(['admission_kind' => 'Arbeitsdienst', 'admission_count' => 2]);
        $fritz = (int) $this->ok('POST', '/users', ['name' => 'Fritz Neu', 'level' => $key])['id'];
        $this->ok('PUT', '/attendance', ['source' => 'free', 'title' => 'Treffen', 'date' => '2026-10-01', 'kinds' => ['Versammlung', 'Arbeitsdienst'], 'entries' => [(string) $fritz => 'present']]);
        $this->list('2026-10-05', 'Versammlung', [$fritz]);
        $this->assertSame(1, $this->entry($fritz)['attended'], 'zählt für die zweite Art der Liste, die Liste nur mit Versammlung nicht');
        $this->ok('PUT', '/attendance', ['source' => 'free', 'title' => 'Treffen', 'date' => '2026-10-08', 'kinds' => ['Arbeitsdienst', 'Versammlung'], 'entries' => [(string) $fritz => 'present']]);
        $e = $this->entry($fritz);
        $this->assertSame([2, 'fulfilled', '2026-10-08'], [$e['attended'], $e['status'], $e['fulfilled_on']]);
    }

    public function testAddMonthsKeepsEndOfMonth(): void
    {
        $this->assertSame('2027-02-28', AdmissionService::addMonths('2027-01-31', 1));
        $this->assertSame('2028-02-28', AdmissionService::addMonths('2027-02-28', 12));
        $this->assertSame('2027-05-14', AdmissionService::addMonths('2026-11-14', 6));
    }

    public function testStoredInBackup(): void
    {
        $this->assertContains('candidate_since', Backup::CORE_TABLES);
    }
}
