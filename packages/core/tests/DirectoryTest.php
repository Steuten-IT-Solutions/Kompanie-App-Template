<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
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
        $this->assertSame(['id', 'name', 'first_name', 'last_name', 'photo_url', 'rank_title', 'rank_badge_url', 'titles'], array_keys($entry), 'auch ohne Anmeldung keine Punkte');
        $this->assertSame('Gefreiter', $entry['rank_title']);
        $this->assertSame([['id' => $title, 'title' => 'Ehrenmitglied']], $entry['titles']);

        $this->users->current = $this->admin;
        $this->app->features()->update(['ranks' => false, 'titles' => false]);
        $entry = array_column($this->as($this->m)->data, null, 'id')[$this->m];
        $this->assertSame(['id', 'name', 'first_name', 'last_name', 'photo_url'], array_keys($entry), 'ohne die Bereiche kein Dienstgrad und keine Titel');
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

    public function testFormerMembersPublicWithYearsOnly(): void
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
        $this->assertSame(['id', 'name', 'first_name', 'last_name', 'founder', 'joined_year', 'left_year', 'photo_url', 'rank_title', 'rank_badge_url', 'titles'], array_keys($entry), 'kein Grund, keine genauen Daten');
        $this->assertSame([true, '1993', '2016'], [$entry['founder'], $entry['joined_year'], $entry['left_year']]);
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
        $this->assertSame(['', ''], [$this->call('GET', '/directory/former')->data[0]['joined_year'], $this->call('GET', '/directory/former')->data[0]['left_year']]);
        $this->call('DELETE', "/members/{$this->m}/former");
        $this->assertSame([], $this->call('GET', '/directory/former')->data);
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
