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

/** Block „Karte“ (map): Ort als Text, die App verlinkt ihn in die Karten-App; nichts von fremden Anbietern eingebettet. */
final class MapBlockTest extends TestCase
{
    private FakeUsers $users;
    private App $app;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->app = TestApp::create(TestApp::connection(), $this->users, [], null, null, null, null, ['pages' => true]);
        $this->users->current = $this->users->add('admin', Roles::ADMIN);
    }

    private function call(string $method, string $path, array $body = [], bool $anonymous = false): Response
    {
        $me = $this->users->current;
        if ($anonymous) {
            $this->users->current = null;
        }
        $res = $this->app->api()->handle(new Request($method, $path, [], $body));
        $this->users->current = $me;
        return $res;
    }

    public function testPlaceAndLink(): void
    {
        $res = $this->call('POST', '/pages', ['title' => 'Anfahrt', 'audience' => 'public', 'blocks' => [['type' => 'map', 'title' => 'So findet ihr uns', 'place' => '  Am Markt 1, 12345 Musterstadt ']]]);
        $this->assertSame(201, $res->status, json_encode($res->data));
        $block = $res->data['blocks'][0];
        $this->assertSame(['So findet ihr uns', 'Am Markt 1, 12345 Musterstadt'], [$block['title'], $block['place']]);
        $guest = $this->call('GET', "/pages/{$res->data['id']}", [], true);
        $this->assertSame('Am Markt 1, 12345 Musterstadt', $guest->data['blocks'][0]['place'], 'öffentliche Seite: auch ohne Anmeldung');
        $this->assertSame(
            "So findet ihr uns\nAm Markt 1, 12345 Musterstadt\nKarte: https://www.google.com/maps/search/?api=1&query=Am%20Markt%201%2C%2012345%20Musterstadt",
            $this->app->blocks()->plain([['type' => 'map', 'title' => 'So findet ihr uns', 'place' => 'Am Markt 1, 12345 Musterstadt']], true),
        );
        foreach ([['place' => ''], ['place' => str_repeat('x', 201)], ['place' => 'x', 'title' => str_repeat('x', 101)]] as $bad) {
            $this->assertSame(400, $this->call('POST', '/pages', ['title' => 'X', 'blocks' => [['type' => 'map'] + $bad]])->status, json_encode($bad));
        }
    }
}
