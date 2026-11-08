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

/** Blöcke „Ansprechpartner“ (contacts), „Hinweis-Kasten“ (notice) und „Trennlinie/Abstand“ (divider). */
final class ContactsNoticeBlocksTest extends TestCase
{
    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $member;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $features = ['pages' => true, 'offices' => true, 'directory' => true];
        $this->app = TestApp::create(TestApp::connection(), $this->users, [], null, null, null, new FixedClock(new \DateTimeImmutable('2026-10-20 12:00:00')), $features);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->member = $this->users->add('anna', Roles::MEMBER);
        $this->users->users[$this->member]['email'] = 'anna@example.org';
        $this->users->current = $this->admin;
    }

    private function call(string $method, string $path, array $body = [], ?int $as = -1): Response
    {
        if ($as !== -1) {
            $this->users->current = $as;
        }
        $res = $this->app->api()->handle(new Request($method, $path, [], $body));
        $this->users->current = $this->admin;
        return $res;
    }

    /** @return array<string, mixed> */
    private function ok(string $method, string $path, array $body = [], ?int $as = -1): array
    {
        $res = $this->call($method, $path, $body, $as);
        $this->assertLessThan(300, $res->status, $method . ' ' . $path . ': ' . json_encode($res->data));
        return (array) $res->data;
    }

    public function testContacts(): void
    {
        $chair = (int) $this->ok('POST', '/offices', ['title' => 'Vorsitz'])['id'];
        $treasurer = (int) $this->ok('POST', '/offices', ['title' => 'Kasse'])['id'];
        $empty = (int) $this->ok('POST', '/offices', ['title' => 'Fahnenträger'])['id'];
        $this->ok('POST', "/offices/$chair/terms", ['start_year' => 2020, 'end_year' => 2023, 'holders' => [['name' => 'Früher']]]);
        $this->ok('POST', "/offices/$chair/terms", ['start_year' => 2024, 'holders' => [['user_id' => $this->member]]]);
        $this->ok('POST', "/offices/$treasurer/terms", ['start_year' => 2025, 'holders' => [['name' => 'Karl Kasse']]]);
        $page = $this->ok('POST', '/pages', ['title' => 'Kontakt', 'audience' => 'public', 'blocks' => [
            ['type' => 'contacts', 'title' => 'Sprich uns an', 'office_ids' => [$treasurer, $chair, $empty, $chair]],
        ]]);
        $this->assertSame([$treasurer, $chair, $empty], $page['blocks'][0]['office_ids'], 'doppelte einmal, Reihenfolge des Blocks');

        $offices = $this->ok('GET', "/pages/{$page['id']}")['blocks'][0]['offices'];
        $this->assertSame(['Kasse', 'Vorsitz', 'Fahnenträger'], array_column($offices, 'title'));
        $this->assertSame(['Anna'], array_column($offices[1]['holders'], 'name'), 'nur die laufende Amtszeit');
        $this->assertSame('anna@example.org', $offices[1]['holders'][0]['email'], 'mit view_personal: Kontaktdaten');
        $this->assertSame([], $offices[2]['holders']);

        foreach ([null, $this->member] as $who) {
            $shown = $this->ok('GET', "/pages/{$page['id']}", [], $who)['blocks'][0]['offices'];
            $this->assertArrayNotHasKey('email', $shown[1]['holders'][0], 'ohne view_personal keine Kontaktdaten');
            $this->assertStringNotContainsString('anna@example.org', (string) json_encode($shown));
        }
        $this->assertSame("Sprich uns an\nKasse: Karl Kasse\nVorsitz: Anna\nFahnenträger: zurzeit nicht besetzt", $this->app->blocks()->plain([['type' => 'contacts', 'title' => 'Sprich uns an', 'office_ids' => [$treasurer, $chair, $empty]]], true));

        foreach ([[], [999999], ['x'], array_fill(0, 21, $chair)] as $ids) {
            $this->assertSame(400, $this->call('POST', '/pages', ['title' => 'X', 'blocks' => [['type' => 'contacts', 'office_ids' => $ids]]])->status, (string) json_encode($ids));
        }
        // Amt gelöscht: fällt weg; Bereich Ämter aus: Block fällt weg
        $this->ok('DELETE', "/offices/$empty");
        $this->assertCount(2, $this->ok('GET', "/pages/{$page['id']}", [], null)['blocks'][0]['offices']);
        $this->app->features()->update(['offices' => false]);
        $this->assertSame([], $this->ok('GET', "/pages/{$page['id']}", [], null)['blocks']);
    }

    public function testNoticeAndDivider(): void
    {
        $page = $this->ok('POST', '/pages', ['title' => 'H', 'blocks' => [
            ['type' => 'notice', 'title' => 'Achtung', 'text' => "Halle gesperrt.\nBitte draußen.", 'tone' => 'warning'],
            ['type' => 'divider'],
            ['type' => 'divider', 'style' => 'space'],
        ]]);
        $this->assertSame(['Achtung', "Halle gesperrt.\nBitte draußen.", 'warning'], [$page['blocks'][0]['title'], $page['blocks'][0]['text'], $page['blocks'][0]['tone']]);
        $this->assertSame(['line', 'space'], [$page['blocks'][1]['style'], $page['blocks'][2]['style']]);
        $this->assertSame("Achtung\nHalle gesperrt.\nBitte draußen.", $this->app->blocks()->plain([['type' => 'notice', 'title' => 'Achtung', 'text' => "Halle gesperrt.\nBitte draußen."], ['type' => 'divider']], true));
        foreach ([['type' => 'notice'], ['type' => 'notice', 'text' => 'x', 'tone' => 'rot'], ['type' => 'notice', 'text' => str_repeat('x', 1001)], ['type' => 'divider', 'style' => 'doppelt']] as $block) {
            $this->assertSame(400, $this->call('POST', '/pages', ['title' => 'X', 'blocks' => [$block]])->status, (string) json_encode($block));
        }
    }
}
