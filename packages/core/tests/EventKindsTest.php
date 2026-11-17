<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Storage\Backup;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * Terminarten (EventKindService): Liste in den Einstellungen, Art am Termin des internen Kalenders (Recht manage_calendar,
 * sichtbar für alle mit dem Kalender), Vorschlag aus dem Titel, Übernahme in die Anwesenheitsliste.
 */
final class EventKindsTest extends TestCase
{
    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $anna;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->app = TestApp::create(TestApp::connection(), $this->users, clock: new FixedClock(new \DateTimeImmutable('2026-11-14 11:00:00', new \DateTimeZone('UTC'))));
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->anna = $this->users->add('anna', Roles::MEMBER);
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

    /** Quelle „In der App“ mit einem Termin; Kennung wie in GET /calendar. */
    private function appEvent(string $title, string $date = '2026-11-20'): string
    {
        $this->ok('PATCH', '/admin/settings', ['calendar_source' => 'app']);
        $entry = $this->ok('POST', '/calendar/entries', ['title' => $title, 'start_date' => $date, 'start_time' => '19:00']);
        return 'i' . $entry['id'];
    }

    /** @return array<string, mixed> der Termin aus GET /calendar für $as */
    private function event(string $id, ?int $as = -1): array
    {
        foreach ($this->ok('GET', '/calendar', [], $as) as $e) {
            if ($e['id'] === $id) {
                return $e;
            }
        }
        $this->fail('Termin fehlt: ' . $id);
    }

    public function testOptionsAreValidatedAndInConfig(): void
    {
        $this->assertSame([], $this->ok('GET', '/config')['eventKinds']);
        $this->assertSame(['kinds' => ['Versammlung', 'Arbeitsdienst']], $this->ok('PUT', '/admin/event-kinds', ['kinds' => [' Versammlung ', '', 'Arbeitsdienst']]));
        $this->assertSame(['Versammlung', 'Arbeitsdienst'], $this->ok('GET', '/config', [], $this->anna)['eventKinds']);
        $this->assertSame(400, $this->call('PUT', '/admin/event-kinds', ['kinds' => ['Versammlung', 'versammlung']])->status);
        $this->assertSame(400, $this->call('PUT', '/admin/event-kinds', ['kinds' => [str_repeat('x', 41)]])->status);
        $this->assertSame(400, $this->call('PUT', '/admin/event-kinds', ['kinds' => 'Versammlung'])->status);
        $this->assertSame(403, $this->call('PUT', '/admin/event-kinds', ['kinds' => []], $this->anna)->status);
        $this->assertSame(['Versammlung', 'Arbeitsdienst'], $this->app->eventKinds()->options(), 'Fehler ändern nichts');
    }

    public function testSuggestionMatchesWholeWordsAndPrefersLongest(): void
    {
        $this->ok('PUT', '/admin/event-kinds', ['kinds' => ['Versammlung', 'Jahresversammlung', 'Schießen']]);
        $kinds = $this->app->eventKinds();
        $this->assertSame('Versammlung', $kinds->suggest('Versammlung März'));
        $this->assertSame('Versammlung', $kinds->suggest('2. versammlung'));
        $this->assertSame('Jahresversammlung', $kinds->suggest('Jahresversammlung 2027'));
        $this->assertSame('Schießen', $kinds->suggest('Pokal-Schießen'));
        $this->assertNull($kinds->suggest('Versammlungsraum aufräumen'));
    }

    public function testKindAtEventForEveryoneSetByCalendarManagers(): void
    {
        $this->ok('PUT', '/admin/event-kinds', ['kinds' => ['Versammlung', 'Arbeitsdienst']]);
        $id = $this->appEvent('Versammlung November');
        $admin = $this->event($id);
        $this->assertNull($admin['kind']);
        $this->assertSame('Versammlung', $admin['kind_suggestion']);
        $this->assertArrayNotHasKey('kind_suggestion', $this->event($id, $this->anna), 'Vorschlag nur für alle, die Termine pflegen');
        $this->assertArrayNotHasKey('response_key', $admin);

        $this->assertSame(403, $this->call('PUT', "/calendar/$id/kind", ['kind' => 'Versammlung'], $this->anna)->status);
        $this->assertSame(400, $this->call('PUT', "/calendar/$id/kind", ['kind' => 'Feier'])->status);
        $this->assertSame(404, $this->call('PUT', '/calendar/igibtsnicht/kind', ['kind' => 'Versammlung'])->status);
        $this->assertSame(['id' => $id, 'kind' => 'Arbeitsdienst'], $this->ok('PUT', "/calendar/$id/kind", ['kind' => 'Arbeitsdienst']));
        $this->assertSame('Arbeitsdienst', $this->event($id, $this->anna)['kind']);
        $this->assertArrayNotHasKey('kind_suggestion', $this->event($id));

        // Aus der Liste entfernt: Der Termin behält seine Art, neu wählen lässt sie sich nicht mehr.
        $this->ok('PUT', '/admin/event-kinds', ['kinds' => ['Versammlung']]);
        $this->assertSame('Arbeitsdienst', $this->event($id)['kind']);
        $this->ok('PUT', "/calendar/$id/kind", ['kind' => 'Arbeitsdienst']);
        $this->ok('PUT', "/calendar/$id/kind", ['kind' => null]);
        $this->assertNull($this->event($id)['kind']);
        $this->assertSame(400, $this->call('PUT', "/calendar/$id/kind", ['kind' => 'Arbeitsdienst'])->status);
    }

    public function testAttendanceListTakesKindOfEventAndFollowsChanges(): void
    {
        $this->ok('PUT', '/admin/event-kinds', ['kinds' => ['Versammlung', 'Arbeitsdienst']]);
        $id = $this->appEvent('Treffen', '2026-11-12');
        // Vergangener Termin: steht nicht mehr unter GET /calendar, aber in der Anwesenheit
        $this->ok('PUT', "/calendar/$id/kind", ['kind' => 'Versammlung']);
        $events = $this->ok('GET', '/attendance/events');
        $this->assertSame('Versammlung', $events[0]['kind']);
        $list = $this->ok('PUT', '/attendance', ['source' => 'calendar', 'event_key' => $id]);
        $this->assertSame('Versammlung', $list['kind']);
        $this->assertSame('Versammlung', $this->ok('GET', '/attendance')[0]['kind']);

        $this->ok('PUT', "/calendar/$id/kind", ['kind' => 'Arbeitsdienst']);
        $this->assertSame('Arbeitsdienst', $this->ok('GET', '/attendance/' . $list['id'])['kind'], 'Liste folgt der Art des Termins');

        // An der Liste selbst ändern, auch auf keine
        $this->assertSame('Versammlung', $this->ok('PUT', '/attendance', ['id' => $list['id'], 'kind' => 'Versammlung'])['kind']);
        $this->assertSame(400, $this->call('PUT', '/attendance', ['id' => $list['id'], 'kind' => 'Feier'])->status);
        $this->assertNull($this->ok('PUT', '/attendance', ['id' => $list['id'], 'kind' => ''])['kind']);
    }

    public function testNewListsUseTitleSuggestionUnlessKindGiven(): void
    {
        $this->ok('PUT', '/admin/event-kinds', ['kinds' => ['Versammlung', 'Arbeitsdienst']]);
        $this->assertSame('Arbeitsdienst', $this->ok('PUT', '/attendance', ['source' => 'free', 'title' => 'Arbeitsdienst am Zelt', 'date' => '2026-11-10'])['kind']);
        $this->assertNull($this->ok('PUT', '/attendance', ['source' => 'free', 'title' => 'Arbeitsdienst', 'date' => '2026-11-11', 'kind' => ''])['kind']);
        $this->assertSame('Versammlung', $this->ok('PUT', '/attendance', ['source' => 'free', 'title' => 'Treffen', 'date' => '2026-11-11', 'kind' => 'Versammlung'])['kind']);
        $this->assertNull($this->ok('PUT', '/attendance', ['source' => 'free', 'title' => 'Treffen', 'date' => '2026-11-12'])['kind']);
        $id = $this->appEvent('Versammlung', '2026-11-13');
        $this->assertSame('Versammlung', $this->ok('PUT', '/attendance', ['source' => 'calendar', 'event_key' => $id])['kind'], 'ohne Art am Termin: Vorschlag');
    }

    public function testStoredInBackup(): void
    {
        $this->assertContains('event_kinds', Backup::CORE_TABLES);
    }
}
