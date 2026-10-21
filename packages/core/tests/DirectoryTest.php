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

/** Mitgliederliste für alle Mitglieder und Anwärter (Bereich directory): wer sie sieht, wer darin steht, welche Angaben. */
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
        $this->candidate = $this->users->add('anwaerter', Roles::CANDIDATE);
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

    public function testMembersAndCandidatesSeeEachOtherButNoGuests(): void
    {
        foreach ([$this->m, $this->candidate, $this->admin] as $viewer) {
            $res = $this->as($viewer);
            $this->assertSame(200, $res->status, (string) json_encode($res->data));
            $ids = array_column($res->data, 'id');
            sort($ids);
            $this->assertSame([$this->admin, $this->m, $this->candidate], $ids, 'Mitglieder, Anwärter und Admins, keine Gäste');
        }
        $this->assertSame(403, $this->as($this->guest)->status, 'Gäste sehen die Liste nicht');
        $this->assertSame(401, $this->as(null)->status);
    }

    public function testOnlyNamePhotoRankAndTitles(): void
    {
        $rank = $this->call('POST', '/ranks', ['title' => 'Gefreiter'])->data['id'];
        $title = $this->call('POST', '/titles', ['title' => 'Ehrenmitglied'])->data['id'];
        $this->call('PUT', "/members/{$this->m}/rank", ['rank_id' => $rank, 'reason' => 'x']);
        $this->call('PUT', "/members/{$this->m}/titles", ['title_ids' => [$title]]);
        $this->call('POST', '/points', ['user_ids' => [$this->m], 'points' => 5, 'reason' => 'Fest']);

        $entry = array_column($this->as($this->candidate)->data, null, 'id')[$this->m];
        $this->assertSame(['id', 'name', 'first_name', 'last_name', 'photo_url', 'rank_title', 'rank_badge_url', 'titles'], array_keys($entry));
        $this->assertSame('Gefreiter', $entry['rank_title']);
        $this->assertSame([['id' => $title, 'title' => 'Ehrenmitglied']], $entry['titles']);

        $this->users->current = $this->admin;
        $this->app->features()->update(['ranks' => false, 'titles' => false]);
        $entry = array_column($this->as($this->m)->data, null, 'id')[$this->m];
        $this->assertSame(['id', 'name', 'first_name', 'last_name', 'photo_url'], array_keys($entry), 'ohne die Bereiche kein Dienstgrad und keine Titel');
    }

    public function testFeatureSwitch(): void
    {
        $this->app->features()->update(['directory' => false]);
        $res = $this->as($this->m);
        $this->assertSame(403, $res->status);
        $this->assertSame('feature_disabled', $res->data['code'] ?? null);
    }
}
