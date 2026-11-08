<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\Blocks\Blocks;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** Blöcke „Knopf mit Link“ (button) und „Nächste Termine“ (events). */
final class LinkEventsBlocksTest extends TestCase
{
    private FakeUsers $users;
    private App $app;
    private FixedClock $clock;
    private int $admin;
    private int $member;
    private int $guest;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->clock = new FixedClock(new \DateTimeImmutable('2027-03-01 12:00:00', new \DateTimeZone('Europe/Berlin')));
        $this->app = TestApp::create(TestApp::connection(), $this->users, clock: $this->clock);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->member = $this->users->add('anna', Roles::MEMBER);
        $this->guest = $this->users->add('gast', Roles::GUEST);
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

    /** @return list<string> */
    private static function types(array $page): array
    {
        return array_column((array) $page['blocks'], 'type');
    }

    public function testButton(): void
    {
        foreach (['https://example.org/anmeldung' => null, 'mailto:vorstand@example.org' => null, 'tel:+49 211 123456' => 'tel:+49211123456', '#/termine' => null] as $url => $stored) {
            $page = $this->ok('POST', '/pages', ['title' => 'K', 'blocks' => [['type' => 'button', 'label' => 'Jetzt anmelden', 'url' => $url]]]);
            $this->assertSame(['label' => 'Jetzt anmelden', 'url' => $stored ?? $url, 'style' => 'primary'], array_intersect_key($page['blocks'][0], ['label' => 1, 'url' => 1, 'style' => 1]), $url);
        }
        $bad = [
            'ohne Beschriftung' => ['url' => 'https://example.org'],
            'Beschriftung zu lang' => ['label' => str_repeat('x', 61), 'url' => 'https://example.org'],
            'ohne Ziel' => ['label' => 'X'],
            'http' => ['label' => 'X', 'url' => 'http://example.org'],
            'javascript' => ['label' => 'X', 'url' => 'javascript:alert(1)'],
            'relativ' => ['label' => 'X', 'url' => '/wp-admin'],
            'Leerzeichen' => ['label' => 'X', 'url' => 'https://example.org/a b'],
            'Art' => ['label' => 'X', 'url' => 'https://example.org', 'style' => 'rot'],
        ];
        foreach ($bad as $why => $block) {
            $this->assertSame(400, $this->call('POST', '/pages', ['title' => 'X', 'blocks' => [['type' => 'button'] + $block]])->status, $why);
        }
        $blocks = $this->app->blocks();
        $this->assertSame('Termine: https://example.org/app/#/termine', $blocks->plain([['type' => 'button', 'label' => 'Termine', 'url' => '#/termine']], false));
        $this->assertSame('Schreib uns: vorstand@example.org', $blocks->plain([['type' => 'button', 'label' => 'Schreib uns', 'url' => 'mailto:vorstand@example.org']], false));
    }

    public function testNextEvents(): void
    {
        $this->ok('POST', '/events', ['title' => 'Vorbei', 'start_date' => '2027-02-01']);
        $this->ok('POST', '/events', ['title' => 'Schießen', 'start_date' => '2027-03-05', 'start_time' => '19:00', 'location' => 'Halle']);
        $this->ok('POST', '/events', ['title' => 'Fest', 'start_date' => '2027-06-12']);
        $this->ok('POST', '/events', ['title' => 'Später', 'start_date' => '2027-09-01']);
        $page = $this->ok('POST', '/pages', ['title' => 'T', 'audience' => 'public', 'blocks' => [['type' => 'events', 'title' => 'Demnächst', 'count' => 2]]]);
        $b = $this->ok('GET', "/pages/{$page['id']}", [], null)['blocks'][0];
        $this->assertSame(['Demnächst', 2], [$b['title'], $b['count']]);
        $this->assertSame(['Schießen', 'Fest'], array_column($b['events'], 'title'), 'ab heute, die nächsten zwei');
        $this->assertSame(['2027-03-05', '19:00', false, 'Halle'], [$b['events'][0]['start_date'], $b['events'][0]['start_time'], $b['events'][0]['all_day'], $b['events'][0]['location']]);
        $this->assertSame("Demnächst\n05.03.2027, 19:00 Uhr: Schießen (Halle)\n12.06.2027: Fest", $this->app->blocks()->plain(Blocks::decode($this->app->db->scalar('SELECT blocks FROM {app_pages}'), ''), false));
        // Ohne Angabe drei; mehr als 20 abgelehnt
        $three = $this->ok('POST', '/pages', ['title' => 'U', 'blocks' => [['type' => 'events']]]);
        $this->assertSame(3, $three['blocks'][0]['count']);
        $this->assertSame(400, $this->call('POST', '/pages', ['title' => 'X', 'blocks' => [['type' => 'events', 'count' => 21]]])->status);
        $this->assertSame(400, $this->call('POST', '/pages', ['title' => 'X', 'blocks' => [['type' => 'events', 'count' => 'viele']]])->status);
        // Bereich Termine aus: leer
        $this->app->features()->update(['events' => false]);
        $this->assertSame([], $this->ok('GET', "/pages/{$page['id']}", [], null)['blocks'][0]['events']);
    }
}
