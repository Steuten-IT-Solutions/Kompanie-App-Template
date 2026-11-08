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

/** Blöcke „Ablauf/Zeitplan“ (schedule) und „Zahlen/Fakten“ (facts). */
final class ScheduleFactsBlocksTest extends TestCase
{
    private FakeUsers $users;
    private App $app;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->app = TestApp::create(TestApp::connection(), $this->users);
        $this->users->current = $this->users->add('admin', Roles::ADMIN);
    }

    private function call(string $method, string $path, array $body = []): Response
    {
        return $this->app->api()->handle(new Request($method, $path, [], $body));
    }

    public function testScheduleAndFacts(): void
    {
        $res = $this->call('POST', '/pages', ['title' => 'Fest', 'blocks' => [
            ['type' => 'schedule', 'title' => 'Ablauf', 'items' => [['time' => '14:00', 'text' => 'Treffen'], ['text' => 'Umzug', 'extra' => 1]]],
            ['type' => 'facts', 'items' => [['value' => '1848', 'label' => 'gegründet'], ['value' => '250+', 'label' => 'Mitglieder']]],
        ]]);
        $this->assertSame(201, $res->status, (string) json_encode($res->data));
        [$schedule, $facts] = $res->data['blocks'];
        $this->assertSame([['time' => '14:00', 'text' => 'Treffen'], ['time' => '', 'text' => 'Umzug']], $schedule['items']);
        $this->assertSame(['', [['value' => '1848', 'label' => 'gegründet'], ['value' => '250+', 'label' => 'Mitglieder']]], [$facts['title'], $facts['items']]);
        $blocks = $this->app->blocks();
        $this->assertSame("Ablauf\n14:00  Treffen\nUmzug", $blocks->plain([['type' => 'schedule'] + $schedule], true));
        $this->assertSame("1848 gegründet\n250+ Mitglieder", $blocks->plain([['type' => 'facts'] + $facts], true));

        $bad = [
            'ohne Punkte' => ['type' => 'schedule', 'items' => []],
            'Punkt ohne Text' => ['type' => 'schedule', 'items' => [['time' => '14:00']]],
            'Zeit zu lang' => ['type' => 'schedule', 'items' => [['time' => str_repeat('x', 21), 'text' => 'x']]],
            'zu viele Punkte' => ['type' => 'schedule', 'items' => array_fill(0, 51, ['text' => 'x'])],
            'ohne Zahlen' => ['type' => 'facts'],
            'ohne Bezeichnung' => ['type' => 'facts', 'items' => [['value' => '1']]],
            'zu viele Zahlen' => ['type' => 'facts', 'items' => array_fill(0, 13, ['value' => '1', 'label' => 'x'])],
        ];
        foreach ($bad as $why => $block) {
            $this->assertSame(400, $this->call('POST', '/pages', ['title' => 'X', 'blocks' => [$block]])->status, $why);
        }
    }
}
