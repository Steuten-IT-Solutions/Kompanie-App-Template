<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Storage\Sql\Connection;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * Punkte und Strafen mit Kennung der Buchung (request_id, Warteschlange offline in der App): Kommt dieselbe Buchung noch
 * einmal, bucht der Server nicht doppelt, sondern liefert die Einträge von damals.
 */
final class BookingRequestIdTest extends TestCase
{
    private FakeUsers $users;
    private Connection $db;
    private App $app;
    private int $admin;
    private int $m;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->db = TestApp::connection();
        $this->app = TestApp::create($this->db, $this->users);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->m = $this->users->add('mitglied', Roles::MEMBER);
        $this->users->current = $this->admin;
    }

    private function rows(string $table, int $userId): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM {' . $table . '} WHERE user_id = ?', [$userId]);
    }

    /** @param array<string, mixed> $body */
    private function call(string $method, string $path, array $body = []): Response
    {
        return $this->app->api()->handle(new Request($method, $path, [], $body));
    }

    public function testPointsWithTheSameRequestIdAreBookedOnce(): void
    {
        $other = $this->users->add('zweites', Roles::MEMBER);
        $body = ['user_ids' => [$this->m, $other], 'points' => 3, 'reason' => 'Arbeitsdienst', 'request_id' => 'a1b2c3d4-0000-4000-8000-000000000001'];
        $first = $this->call('POST', '/points', $body);
        $this->assertSame(201, $first->status, (string) json_encode($first->data));
        $again = $this->call('POST', '/points', $body);
        $this->assertSame(201, $again->status);
        $this->assertSame(array_column($first->data, 'id'), array_column($again->data, 'id'), 'dieselben Einträge');
        $this->assertSame(1, $this->rows('points', $this->m), 'nur einmal gebucht');

        // Andere Kennung oder keine: neue Buchung
        $this->call('POST', '/points', ['request_id' => 'a1b2c3d4-0000-4000-8000-000000000002'] + $body);
        unset($body['request_id']);
        $this->call('POST', '/points', $body);
        $this->call('POST', '/points', $body);
        $this->assertSame(4, $this->rows('points', $this->m));
    }

    public function testPenaltiesWithTheSameRequestIdAreBookedOnce(): void
    {
        $body = ['user_ids' => [$this->m], 'amount_cents' => 200, 'reason' => 'Zu spät', 'request_id' => 'strafe-0001'];
        $first = $this->call('POST', '/penalties', $body);
        $this->assertSame(201, $first->status, (string) json_encode($first->data));
        $again = $this->call('POST', '/penalties', $body);
        $this->assertSame(array_column($first->data, 'id'), array_column($again->data, 'id'));
        $this->assertSame(1, $this->rows('penalties', $this->m));
    }

    public function testRequestIdOfAnotherAdminDoesNotReplay(): void
    {
        $body = ['user_ids' => [$this->m], 'amount_cents' => 200, 'reason' => 'Zu spät', 'request_id' => 'gleich-0001'];
        $this->call('POST', '/penalties', $body);
        $this->users->current = $this->users->add('admin2', Roles::ADMIN);
        // Gleiche Kennung einer anderen Person (praktisch ausgeschlossen, da zufällig): bucht nicht doppelt für dieselbe
        // Person und verrät nichts von der fremden Buchung.
        $res = $this->call('POST', '/penalties', $body);
        $this->assertNotSame(201, $res->status);
        $this->assertSame(1, $this->rows('penalties', $this->m));
    }

    public function testInvalidRequestIdIsRejected(): void
    {
        $res = $this->call('POST', '/points', ['user_ids' => [$this->m], 'points' => 1, 'reason' => 'x', 'request_id' => 'zu kurz']);
        $this->assertSame(400, $res->status);
        $this->assertSame(0, $this->rows('points', $this->m));
    }
}
