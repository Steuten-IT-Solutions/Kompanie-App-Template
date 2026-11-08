<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** Freie Namen in Amtszeiten mit Mitgliedern verknüpfen: Vorschläge (tolerant bei Namen) und Verknüpfen auf Bestätigung. */
final class OfficeLinkTest extends TestCase
{
    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $office;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $features = ['events' => false, 'calendar' => false, 'news' => false, 'gallery' => false, 'points' => false, 'penalties' => false, 'ranks' => false, 'directory' => true, 'offices' => true, 'titles' => true];
        $this->app = TestApp::create(TestApp::connection(), $this->users, [], null, null, null, new FixedClock(new \DateTimeImmutable('2026-11-08 12:00:00')), $features);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->users->current = $this->admin;
        $this->office = (int) $this->ok('POST', '/offices', ['title' => 'König'])['id'];
    }

    private function call(string $method, string $path, array $body = []): Response
    {
        return $this->app->api()->handle(new Request($method, $path, [], $body));
    }

    /** @return array<string, mixed> */
    private function ok(string $method, string $path, array $body = []): array
    {
        $res = $this->call($method, $path, $body);
        $this->assertLessThan(300, $res->status, $method . ' ' . $path . ': ' . json_encode($res->data));
        return (array) $res->data;
    }

    private function person(string $first, string $last, string $level = Roles::MEMBER): int
    {
        $id = (int) $this->ok('POST', '/users', ['first_name' => $first, 'last_name' => $last, 'no_access' => true])['id'];
        if ($level !== Roles::MEMBER) {
            $this->users->users[$id]['level'] = $level;
        }
        return $id;
    }

    /** @return list<array<string, mixed>> */
    private function holders(): array
    {
        foreach ($this->ok('GET', '/offices')['offices'] as $o) {
            if ($o['id'] === $this->office) {
                return array_merge(...array_map(static fn (array $t): array => $t['holders'], $o['terms']));
            }
        }
        return [];
    }

    public function testSuggestionsAreTolerantAndLinkingNeedsConfirmation(): void
    {
        $exact = $this->person('Jürgen', 'Müller-Lang');
        $similar = $this->person('Jürg', 'Mueller Lang');
        $this->person('Jürgen', 'Schmitz');
        $this->ok('POST', "/offices/{$this->office}/terms", ['kind' => 'point', 'start_year' => 1999, 'holders' => [['name' => 'Juergen Mueller-Lang', 'companions' => [['name' => 'Niemand Bekannt']]]]]);
        $this->ok('POST', "/offices/{$this->office}/terms", ['kind' => 'point', 'start_year' => 2005, 'holders' => [['name' => 'juergen  mueller-lang']]]);

        $list = $this->ok('GET', '/offices/link-suggestions');
        $this->assertCount(1, $list, 'nur Namen mit passenden Personen');
        $s = $list[0];
        $this->assertSame(2, $s['entries'], 'gleicher Name ohne Unterschied in Groß- und Kleinschreibung und Leerzeichen');
        $this->assertSame(['König 1999', 'König 2005'], $s['terms']);
        $this->assertSame([[$exact, 'name'], [$similar, 'similar']], array_map(static fn (array $c): array => [$c['id'], $c['match']], $s['candidates']));
        $this->assertSame([null, null], array_map(static fn (array $h): ?int => $h['user_id'] ?? null, $this->holders()), 'Vorschläge ändern nichts');

        $this->assertSame(['linked' => 2], $this->ok('POST', '/offices/link', ['name' => $s['name'], 'user_id' => $exact]));
        $this->assertSame([$exact, $exact], array_map(static fn (array $h): ?int => $h['user_id'] ?? null, $this->holders()));
        $this->assertSame([], $this->ok('GET', '/offices/link-suggestions'));
        $this->assertSame(404, $this->call('POST', '/offices/link', ['name' => $s['name'], 'user_id' => $exact])->status, 'nichts mehr zu verknüpfen');
    }

    public function testFormerMembersAreSuggestedButGuestsAreNot(): void
    {
        $former = $this->person('Otto', 'Alt');
        $this->ok('PUT', "/members/$former/former", ['reason' => 'deceased', 'left_on' => '2001']);
        $guest = $this->users->add('gast', Roles::GUEST);
        $this->users->users[$guest]['name'] = 'Otto Alt';
        $this->ok('POST', "/offices/{$this->office}/terms", ['kind' => 'point', 'start_year' => 1980, 'holders' => [['name' => 'Otto Alt']]]);

        $c = $this->ok('GET', '/offices/link-suggestions')[0]['candidates'];
        $this->assertSame([[$former, true]], array_map(static fn (array $x): array => [$x['id'], $x['former']], $c));
        $this->assertSame(400, $this->call('POST', '/offices/link', ['name' => 'Otto Alt', 'user_id' => $guest])->status);
        $this->assertSame(['linked' => 1], $this->ok('POST', '/offices/link', ['name' => 'Otto Alt', 'user_id' => $former]));
    }

    public function testOnlyForManagers(): void
    {
        $member = $this->users->add('mitglied', Roles::MEMBER);
        $this->users->current = $member;
        $this->assertSame(403, $this->call('GET', '/offices/link-suggestions')->status);
        $this->assertSame(403, $this->call('POST', '/offices/link', ['name' => 'X', 'user_id' => $member])->status);
    }
}
