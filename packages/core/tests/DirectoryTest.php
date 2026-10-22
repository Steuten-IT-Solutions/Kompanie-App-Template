<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\BirthdayService;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Storage\Backup;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** Mitgliederliste (Bereich directory, Issue #112: öffentlich): wer sie sieht, wer darin steht, welche Angaben. */
final class DirectoryTest extends TestCase
{
    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $m;
    private int $candidate;
    private int $guest;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->app = TestApp::create(TestApp::connection(), $this->users);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->m = $this->users->add('mitglied', Roles::MEMBER);
        $this->candidate = $this->users->add('anwaerter', TestApp::candidateRole($this->app));
        $this->guest = $this->users->add('gast', Roles::GUEST);
        $this->users->current = $this->admin;
    }

    private function call(string $method, string $path, array $body = []): Response
    {
        return $this->app->api()->handle(new Request($method, $path, [], $body));
    }

    private function as(?int $id): Response
    {
        $this->users->current = $id;
        return $this->call('GET', '/directory');
    }

    public function testEveryoneSeesMembersAndCandidatesButNoGuests(): void
    {
        // Öffentlich: auch Gäste und ohne Anmeldung; darin stehen nur Mitglieder, Anwärter und Admins.
        foreach ([$this->m, $this->candidate, $this->admin, $this->guest, null] as $viewer) {
            $res = $this->as($viewer);
            $this->assertSame(200, $res->status, (string) json_encode($res->data));
            $ids = array_column($res->data, 'id');
            sort($ids);
            $this->assertSame([$this->admin, $this->m, $this->candidate], $ids, 'Mitglieder, Anwärter und Admins, keine Gäste');
        }
    }

    public function testOnlyNamePhotoRankAndTitles(): void
    {
        $rank = $this->call('POST', '/ranks', ['title' => 'Gefreiter'])->data['id'];
        $title = $this->call('POST', '/titles', ['title' => 'Ehrenmitglied'])->data['id'];
        $this->call('PUT', "/members/{$this->m}/rank", ['rank_id' => $rank, 'reason' => 'x']);
        $this->call('PUT', "/members/{$this->m}/titles", ['title_ids' => [$title]]);
        $this->call('POST', '/points', ['user_ids' => [$this->m], 'points' => 5, 'reason' => 'Fest']);

        $entry = array_column($this->as(null)->data, null, 'id')[$this->m];
        $this->assertSame(['id', 'name', 'first_name', 'last_name', 'photo_url', 'rank_title', 'rank_badge_url', 'titles', 'birthday', 'join_year', 'join_date'], array_keys($entry), 'auch ohne Anmeldung keine Punkte');
        $this->assertNull($entry['birthday'], 'ohne Freigabe kein Geburtstag');
        $this->assertNull($entry['join_year'], 'ohne Freigabe kein Eintrittsjahr');
        $this->assertSame('Gefreiter', $entry['rank_title']);
        $this->assertSame([['id' => $title, 'title' => 'Ehrenmitglied']], $entry['titles']);

        $this->users->current = $this->admin;
        $this->app->features()->update(['ranks' => false, 'titles' => false, 'birthdays' => false]);
        $entry = array_column($this->as($this->m)->data, null, 'id')[$this->m];
        $this->assertSame(['id', 'name', 'first_name', 'last_name', 'photo_url', 'join_year', 'join_date'], array_keys($entry), 'ohne die Bereiche kein Dienstgrad, keine Titel und kein Geburtstag');
    }

    public function testCountsPerGroupWithoutRolesPerPerson(): void
    {
        $young = $this->app->roles()->create(['label' => 'Jungmariner', 'capabilities' => ['member']])['key'];
        $this->users->add('jung', $young);
        $counts = fn (int $viewer): array => array_column($this->countsAs($viewer)->data, 'count', 'group');

        // Ohne eigene Gruppe zählen Jungmariner (und Admins) als Mitglieder, wie in der Anwesenheit; Gäste nie.
        $this->assertEquals([Roles::MEMBER => 3, TestApp::candidateRole($this->app) => 1], $counts($this->candidate));
        $this->app->attendance()->setGroups([$young => '']);
        $this->assertEquals([Roles::MEMBER => 2, $young => 1, TestApp::candidateRole($this->app) => 1], $counts($this->m));
        $this->assertSame(['group', 'count'], array_keys($this->countsAs($this->m)->data[0]), 'nur Summen');

        // Öffentlich wie die Liste (Issue #112)
        $this->assertEquals([Roles::MEMBER => 2, $young => 1, TestApp::candidateRole($this->app) => 1], $counts($this->guest));
        $this->assertSame(200, $this->countsAs(null)->status);
        $this->users->current = $this->admin;
        $this->app->features()->update(['directory' => false]);
        $this->assertSame(403, $this->countsAs($this->m)->status);
    }

    private function countsAs(?int $id): Response
    {
        $this->users->current = $id;
        return $this->call('GET', '/directory/counts');
    }

    public function testFormerMembersPublicWithDatesButNoReason(): void
    {
        $title = $this->call('POST', '/titles', ['title' => 'Ehrenmitglied'])->data['id'];
        $this->call('PUT', "/members/{$this->m}/titles", ['title_ids' => [$title]]);
        $res = $this->call('PUT', "/members/{$this->m}/former", ['reason' => 'deceased', 'joined_on' => '1993-05-03', 'founder' => true, 'left_on' => '2016-06-30']);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));

        // Öffentlich wie die Liste; Ehemalige stehen nicht mehr in der Liste der Mitglieder.
        foreach ([$this->candidate, $this->guest, null] as $viewer) {
            $this->assertNotContains($this->m, array_column($this->as($viewer)->data, 'id'));
            $this->users->current = $viewer;
            $res = $this->call('GET', '/directory/former');
            $this->assertSame(200, $res->status, (string) json_encode($res->data));
            $this->assertSame([$this->m], array_column($res->data, 'id'));
        }
        $entry = $res->data[0];
        $this->assertSame(['id', 'name', 'first_name', 'last_name', 'founder', 'joined_year', 'left_year', 'joined_on', 'left_on', 'photo_url', 'rank_title', 'rank_badge_url', 'titles'], array_keys($entry), 'kein Grund');
        $this->assertSame([true, '1993', '2016', '1993-05-03', '2016-06-30'], [$entry['founder'], $entry['joined_year'], $entry['left_year'], $entry['joined_on'], $entry['left_on']], 'Daten wie eingetragen, dazu die Jahre');
        $this->assertSame([['id' => $title, 'title' => 'Ehrenmitglied']], $entry['titles']);

        // In den Ämtern öffnet der Name die Seite der Person, auch ohne Anmeldung (wie bei Personen der Liste).
        $this->users->current = $this->admin;
        $this->app->features()->update(['offices' => true]);
        $office = $this->call('POST', '/offices', ['title' => 'Vorsitz'])->data['id'];
        $this->call('POST', "/offices/$office/terms", ['kind' => 'period', 'start_year' => 1990, 'end_year' => 2000, 'holders' => [['user_id' => $this->m]]]);
        $this->users->current = null;
        $this->assertSame($this->m, $this->call('GET', '/offices')->data['offices'][0]['terms'][0]['holders'][0]['user_id'] ?? null);

        // Ohne Daten bleiben die Jahre leer; wieder aufgenommen steht die Person nicht mehr bei den Ehemaligen.
        $this->users->current = $this->admin;
        $this->call('PATCH', "/members/{$this->m}/former", ['joined_on' => '', 'left_on' => '']);
        $f = $this->call('GET', '/directory/former')->data[0];
        $this->assertSame(['', '', '', ''], [$f['joined_year'], $f['left_year'], $f['joined_on'], $f['left_on']]);
        $this->call('DELETE', "/members/{$this->m}/former");
        $this->assertSame([], $this->call('GET', '/directory/former')->data);
    }

    public function testBirthdayAndJoinYearOnlyAsThePersonChooses(): void
    {
        $entry = function (?int $viewer): array {
            return array_column($this->as($viewer)->data, null, 'id')[$this->m];
        };
        $this->users->current = $this->m;
        $this->assertSame(200, $this->call('PUT', '/me/birthday', ['month' => 3, 'day' => 12, 'year' => 1988, 'show_age' => true])->status);
        $this->assertSame(200, $this->call('PUT', '/me/join-year', ['year' => 2005])->status);
        $this->assertSame(2005, $entry($this->m)['join_year'], 'Standard: nur Mitgliedern');
        $this->assertSame([3, 12], [$entry($this->m)['birthday']['month'], $entry($this->m)['birthday']['day']], 'Standard: nur Mitgliedern');
        $this->assertNull($entry(null)['birthday'], 'Standard: nicht öffentlich');
        $this->assertNull($entry(null)['join_year'], 'Standard: nicht öffentlich');

        // Nicht anzeigen: niemand sieht es auf der Seite.
        $this->users->current = $this->m;
        $this->call('PUT', '/me/birthday', ['month' => 3, 'day' => 12, 'profile' => 'none']);
        $this->call('PUT', '/me/join-year', ['year' => 2005, 'profile' => 'none']);
        foreach ([$this->m, null] as $viewer) {
            $this->assertNull($entry($viewer)['birthday']);
            $this->assertNull($entry($viewer)['join_year']);
        }

        // Nur Mitgliedern: angemeldete Mitglieder (Recht member) sehen es, Anwärter, Gäste und Besucher nicht.
        $this->users->current = $this->m;
        $this->call('PUT', '/me/birthday', ['month' => 3, 'day' => 12, 'profile' => 'members']);
        $this->call('PUT', '/me/join-year', ['year' => 2005, 'profile' => 'members']);
        foreach ([$this->m, $this->admin] as $viewer) {
            $e = $entry($viewer);
            $this->assertSame(2005, $e['join_year']);
            $this->assertSame([3, 12], [$e['birthday']['month'], $e['birthday']['day']]);
        }
        foreach ([$this->candidate, $this->guest, null] as $viewer) {
            $this->assertNull($entry($viewer)['birthday']);
            $this->assertNull($entry($viewer)['join_year']);
        }

        // Allen: auch ohne Anmeldung; mit „Alter anzeigen“ und Jahr das ganze Datum mit Alter, sonst nur Tag und Monat.
        $this->users->current = $this->m;
        $this->call('PUT', '/me/birthday', ['month' => 3, 'day' => 12, 'year' => 1988, 'profile' => 'public']);
        $this->call('PUT', '/me/join-year', ['year' => 2005, 'profile' => 'public']);
        $today = $this->app->time()->today();
        $this->assertSame(['month' => 3, 'day' => 12, 'year' => 1988, 'age' => BirthdayService::ageOn(3, 12, 1988, $today)], $entry(null)['birthday']);
        $this->assertSame(2005, $entry(null)['join_year']);
        $this->users->current = $this->m;
        $this->call('PUT', '/me/birthday', ['month' => 3, 'day' => 12, 'year' => 1988, 'show_age' => false]);
        $this->assertSame(['month' => 3, 'day' => 12, 'year' => null, 'age' => null], $entry(null)['birthday'], 'kein Geburtsjahr ohne „Alter anzeigen“');
        $this->assertStringNotContainsString('1988', (string) json_encode($this->as(null)->data));

        // Die Verwaltung ändert Datum und Jahr, nicht die Anzeige.
        $this->users->current = $this->admin;
        $this->assertSame(['year' => 2004, 'date' => '2004', 'profile' => 'public'], $this->call('PUT', "/members/{$this->m}/join-year", ['year' => 2004, 'profile' => 'none'])->data['join_year']);
        $this->assertSame('public', $this->call('PUT', "/members/{$this->m}/birthday", ['month' => 4, 'day' => 1, 'profile' => 'none'])->data['birthday']['profile']);
        $this->assertSame(['year' => 2004, 'date' => '2004', 'profile' => 'public'], $this->call('GET', "/members/{$this->m}")->data['join_year']);

        // Der Bereich Geburtstage aus: kein Geburtstag in der Liste, das Eintrittsjahr bleibt.
        $this->app->features()->update(['birthdays' => false]);
        $this->assertArrayNotHasKey('birthday', $entry(null));
        $this->assertSame(2004, $entry(null)['join_year']);

        // Ehemalige haben ihre eigenen Jahre; Konto löschen entfernt das Eintrittsjahr.
        $this->users->current = $this->admin;
        $this->call('PUT', "/members/{$this->m}/former", ['reason' => 'left']);
        $this->users->current = null;
        $this->assertArrayNotHasKey('join_year', $this->call('GET', '/directory/former')->data[0]);
        $this->assertArrayNotHasKey('join_date', $this->call('GET', '/directory/former')->data[0]);
        $this->users->current = $this->admin;
        $this->assertSame(200, $this->call('DELETE', "/members/{$this->m}")->status);
        $this->assertNull($this->app->joinYears()->of($this->m));
        $this->assertContains('join_years', Backup::CORE_TABLES);
    }

    public function testJoinYearInput(): void
    {
        $this->users->current = $this->m;
        foreach ([null, '', 'abc', 1799, (int) $this->app->time()->today()->format('Y') + 1] as $year) {
            $this->assertSame(400, $this->call('PUT', '/me/join-year', ['year' => $year])->status, (string) json_encode($year));
        }
        $this->assertSame(400, $this->call('PUT', '/me/join-year', ['year' => 2000, 'profile' => 'alle'])->status);
        $this->assertSame(['year' => 1800, 'date' => '1800', 'profile' => 'members'], $this->call('PUT', '/me/join-year', ['year' => '1800'])->data['join_year']);
        $this->assertSame(403, $this->call('PUT', "/members/{$this->admin}/join-year", ['year' => 2000])->status, 'fremdes nur mit create_users');
        $this->users->current = $this->candidate;
        $this->assertSame(403, $this->call('PUT', '/me/join-year', ['year' => 2000])->status, 'nur Mitglieder');
        $this->users->current = $this->admin;
        $this->assertSame(404, $this->call('PUT', '/members/9999/join-year', ['year' => 2000])->status);
    }

    public function testJoinDateWithDayAndMonth(): void
    {
        $this->users->current = $this->m;
        $put = fn (array $body): Response => $this->call('PUT', '/me/join-year', $body);
        $this->assertSame(['year' => 2005, 'date' => '2005-03-12', 'profile' => 'public'], $put(['date' => '12.3.2005', 'profile' => 'public'])->data['join_year']);
        $this->assertSame('2005-03-12', $put(['date' => '2005-03-12'])->data['join_year']['date'], 'auch JJJJ-MM-TT');
        // Auf der Seite in der Mitgliederliste das ganze Datum, wie eingetragen (dazu das Jahr für ältere Apps)
        $public = array_column($this->as(null)->data, null, 'id')[$this->m];
        $this->assertSame([2005, '2005-03-12'], [$public['join_year'], $public['join_date']]);
        $this->users->current = $this->m;
        $put(['date' => '2005', 'profile' => 'members']);
        $own = array_column($this->as($this->m)->data, null, 'id')[$this->m];
        $this->assertSame([2005, '2005'], [$own['join_year'], $own['join_date']], 'nur das Jahr eingetragen: nur das Jahr');
        $this->assertSame([null, null], [array_column($this->as(null)->data, null, 'id')[$this->m]['join_year'], array_column($this->as(null)->data, null, 'id')[$this->m]['join_date']], 'nicht freigegeben: weder Jahr noch Datum');
        $this->users->current = $this->m;
        $put(['date' => '2005-03-12', 'profile' => 'public']);
        // Verwaltung: ganzes Datum in Liste und Details
        $this->users->current = $this->admin;
        $this->assertSame('2005-03-12', array_column($this->call('GET', '/members')->data, null, 'id')[$this->m]['joined_on']);
        $this->assertSame('2005-03-12', $this->call('GET', "/members/{$this->m}")->data['join_year']['date']);
        $this->assertSame(['year' => 2004, 'date' => '2004-12-31', 'profile' => 'public'], $this->call('PUT', "/members/{$this->m}/join-year", ['date' => '31.12.2004'])->data['join_year']);
        // Nur das Jahr: das Datum entfällt
        $this->assertSame(['year' => 2004, 'date' => '2004', 'profile' => 'public'], $this->call('PUT', "/members/{$this->m}/join-year", ['date' => '2004'])->data['join_year']);
        $this->assertSame('2004', array_column($this->call('GET', '/members')->data, null, 'id')[$this->m]['joined_on']);
        // Ältere Apps schicken year: ebenso nur das Jahr
        $this->call('PUT', "/members/{$this->m}/join-year", ['date' => '01.05.2003']);
        $this->assertSame('2003', $this->call('PUT', "/members/{$this->m}/join-year", ['year' => 2003])->data['join_year']['date']);

        $tomorrow = $this->app->time()->today()->modify('+1 day')->format('d.m.Y');
        foreach (['', '31.02.2005', '2005-13-01', '12.03.', 'März 2005', '01.01.1799', $tomorrow, null] as $bad) {
            $this->assertSame(400, $this->call('PUT', "/members/{$this->m}/join-year", ['date' => $bad])->status, (string) json_encode($bad));
        }
        $this->assertSame('2003', $this->app->joinYears()->of($this->m)['date'] ?? null, 'Fehler ändern nichts');
    }

    public function testAgeOnTheDay(): void
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $on = static fn (string $d): \DateTimeImmutable => new \DateTimeImmutable($d, $tz);
        $this->assertSame(37, BirthdayService::ageOn(3, 12, 1988, $on('2026-03-11')));
        $this->assertSame(38, BirthdayService::ageOn(3, 12, 1988, $on('2026-03-12')));
        // 29.02.: in Jahren ohne Schalttag ab dem 28.02. (wie die Liste und die Benachrichtigung)
        $this->assertSame(26, BirthdayService::ageOn(2, 29, 2000, $on('2027-02-27')));
        $this->assertSame(27, BirthdayService::ageOn(2, 29, 2000, $on('2027-02-28')));
        $this->assertSame(27, BirthdayService::ageOn(2, 29, 2000, $on('2028-02-28')));
        $this->assertSame(28, BirthdayService::ageOn(2, 29, 2000, $on('2028-02-29')));
    }

    public function testFeatureSwitch(): void
    {
        $this->app->features()->update(['directory' => false]);
        foreach ([$this->m, null] as $viewer) {
            foreach (['/directory', '/directory/former'] as $path) {
                $this->users->current = $viewer;
                $res = $this->call('GET', $path);
                $this->assertSame(403, $res->status);
                $this->assertSame('feature_disabled', $res->data['code'] ?? null);
            }
        }
    }
}
