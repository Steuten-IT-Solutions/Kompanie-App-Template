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
        $this->assertSame(['Versammlung', 'Arbeitsdienst'], $this->ok('PUT', '/admin/event-kinds', ['kinds' => [' Versammlung ', '', 'Arbeitsdienst']])['kinds']);
        $this->assertSame(['Versammlung', 'Arbeitsdienst'], $this->ok('GET', '/config', [], $this->anna)['eventKinds']);
        $this->assertSame(400, $this->call('PUT', '/admin/event-kinds', ['kinds' => ['Versammlung', 'versammlung']])->status);
        $this->assertSame(400, $this->call('PUT', '/admin/event-kinds', ['kinds' => [str_repeat('x', 41)]])->status);
        $this->assertSame(400, $this->call('PUT', '/admin/event-kinds', ['kinds' => 'Versammlung'])->status);
        $this->assertSame(403, $this->call('PUT', '/admin/event-kinds', ['kinds' => []], $this->anna)->status);
        $this->assertSame(['Versammlung', 'Arbeitsdienst'], $this->app->eventKinds()->options(), 'Fehler ändern nichts');
    }

    public function testHiddenTagsAreSavedPerKindAndInConfig(): void
    {
        $this->assertSame([], $this->ok('GET', '/config')['eventKindsHidden'], 'Vorgabe: alle Arten mit Schild');
        $this->assertSame(
            ['kinds' => ['Versammlung', 'Arbeitsdienst'], 'hidden' => []],
            $this->ok('PUT', '/admin/event-kinds', ['kinds' => ['Versammlung', 'Arbeitsdienst']]),
        );
        $this->assertSame(
            ['kinds' => ['Versammlung', 'Arbeitsdienst'], 'hidden' => ['Arbeitsdienst']],
            $this->ok('PUT', '/admin/event-kinds', ['kinds' => ['Versammlung', 'Arbeitsdienst'], 'hidden' => ['Arbeitsdienst', 'Gibt es nicht']]),
        );
        $this->assertSame(['Arbeitsdienst'], $this->ok('GET', '/config', [], $this->anna)['eventKindsHidden']);
        // Ohne hidden (ältere Oberfläche) bleibt es, wie es war
        $this->assertSame(['Arbeitsdienst'], $this->ok('PUT', '/admin/event-kinds', ['kinds' => ['Arbeitsdienst', 'Schießen']])['hidden']);
        $this->assertSame(400, $this->call('PUT', '/admin/event-kinds', ['kinds' => ['Versammlung'], 'hidden' => 'Arbeitsdienst'])->status);
        $this->assertSame(['Arbeitsdienst', 'Schießen'], $this->app->eventKinds()->options(), 'Fehler ändern nichts');
        // Eine entfernte Art verliert die Einstellung; neu angelegt hat sie wieder ein Schild
        $this->assertSame([], $this->ok('PUT', '/admin/event-kinds', ['kinds' => ['Schießen']])['hidden']);
        $this->assertSame([], $this->ok('PUT', '/admin/event-kinds', ['kinds' => ['Schießen', 'Arbeitsdienst']])['hidden']);
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
        $this->assertSame(['id' => $id, 'kind' => 'Arbeitsdienst', 'kinds' => ['Arbeitsdienst']], $this->ok('PUT', "/calendar/$id/kind", ['kind' => 'Arbeitsdienst']));
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

    public function testSuggestionsListEveryMatchingKind(): void
    {
        $this->ok('PUT', '/admin/event-kinds', ['kinds' => ['Arbeitsdienst', 'Versammlung', 'Außerordentliche Versammlung']]);
        $kinds = $this->app->eventKinds();
        $this->assertSame(['Arbeitsdienst', 'Versammlung'], $kinds->suggestAll('Versammlung mit Arbeitsdienst'));
        $this->assertSame(['Außerordentliche Versammlung'], $kinds->suggestAll('Außerordentliche Versammlung'), 'Teil einer längeren Art fällt weg');
        $this->assertSame([], $kinds->suggestAll('Feier'));

        $id = $this->appEvent('Versammlung und Arbeitsdienst');
        $e = $this->event($id);
        $this->assertSame([], $e['kinds']);
        $this->assertSame(['Arbeitsdienst', 'Versammlung'], $e['kind_suggestions']);
        $this->assertSame('Arbeitsdienst', $e['kind_suggestion'], 'ältere Oberflächen: der längste Vorschlag');
        $list = $this->ok('PUT', '/attendance', ['source' => 'free', 'title' => 'Versammlung, danach Arbeitsdienst', 'date' => '2026-11-10']);
        $this->assertSame(['Arbeitsdienst', 'Versammlung'], $list['kinds']);
    }

    public function testEventAndListCanHaveSeveralKinds(): void
    {
        $this->ok('PUT', '/admin/event-kinds', ['kinds' => ['Versammlung', 'Arbeitsdienst', 'Schießen']]);
        $id = $this->appEvent('Treffen', '2026-11-12');
        // Reihenfolge wie in den Einstellungen, doppelte fallen weg
        $this->assertSame(
            ['id' => $id, 'kind' => 'Versammlung', 'kinds' => ['Versammlung', 'Arbeitsdienst']],
            $this->ok('PUT', "/calendar/$id/kind", ['kinds' => ['Arbeitsdienst', 'Versammlung', 'Arbeitsdienst']]),
        );
        $this->assertSame(400, $this->call('PUT', "/calendar/$id/kind", ['kinds' => ['Versammlung', 'Feier']])->status);
        $this->assertSame(['Versammlung', 'Arbeitsdienst'], $this->ok('GET', '/attendance/events')[0]['kinds'], 'Fehler ändern nichts');

        $list = $this->ok('PUT', '/attendance', ['source' => 'calendar', 'event_key' => $id]);
        $this->assertSame(['Versammlung', 'Arbeitsdienst'], $list['kinds']);
        $this->assertSame('Versammlung', $list['kind'], 'ältere Oberflächen: die erste');
        $this->assertSame(['Versammlung', 'Arbeitsdienst'], $this->ok('GET', '/attendance')[0]['kinds']);

        // Termin ändern: Liste folgt; an der Liste ändern: nur die Liste
        $this->ok('PUT', "/calendar/$id/kind", ['kinds' => ['Schießen', 'Arbeitsdienst']]);
        $this->assertSame(['Arbeitsdienst', 'Schießen'], $this->ok('GET', '/attendance/' . $list['id'])['kinds']);
        $this->assertSame(['Versammlung'], $this->ok('PUT', '/attendance', ['id' => $list['id'], 'kinds' => ['Versammlung']])['kinds']);
        $this->assertSame([], $this->ok('PUT', '/attendance', ['id' => $list['id'], 'kinds' => []])['kinds']);

        // Eine entfernte Art bleibt neben einer neuen erhalten
        $this->ok('PUT', "/calendar/$id/kind", ['kinds' => ['Schießen', 'Versammlung']]);
        $this->ok('PUT', '/admin/event-kinds', ['kinds' => ['Versammlung', 'Arbeitsdienst']]);
        $this->assertSame(['Versammlung', 'Arbeitsdienst', 'Schießen'], $this->ok('PUT', "/calendar/$id/kind", ['kinds' => ['Schießen', 'Versammlung', 'Arbeitsdienst']])['kinds']);
        $this->assertSame([], $this->ok('PUT', "/calendar/$id/kind", ['kinds' => []])['kinds']);
    }

    public function testRowsOfOlderVersionsAndAfterRollbackReadTheFirstKind(): void
    {
        $this->ok('PUT', '/admin/event-kinds', ['kinds' => ['Versammlung', 'Arbeitsdienst']]);
        $id = $this->appEvent('Treffen', '2026-11-12');
        $list = $this->ok('PUT', '/attendance', ['source' => 'free', 'title' => 'Treffen', 'date' => '2026-11-10', 'kinds' => ['Versammlung', 'Arbeitsdienst']]);
        $row = $this->app->db->selectOne('SELECT kind, kinds FROM {attendance_lists} WHERE id = ?', [$list['id']]);
        $this->assertSame(['kind' => 'Versammlung', 'kinds' => "Versammlung\nArbeitsdienst"], ['kind' => (string) $row['kind'], 'kinds' => (string) $row['kinds']]);
        // Ältere Version hat nur kind geändert: kinds passt nicht mehr dazu
        $this->app->db->update('attendance_lists', ['kind' => 'Arbeitsdienst'], ['id' => $list['id']]);
        $this->assertSame(['Arbeitsdienst'], $this->ok('GET', '/attendance/' . $list['id'])['kinds']);
        $this->app->db->update('attendance_lists', ['kind' => ''], ['id' => $list['id']]);
        $this->assertSame([], $this->ok('GET', '/attendance/' . $list['id'])['kinds']);
        // Zeile von vor den mehreren Arten (kinds leer)
        $this->ok('PUT', "/calendar/$id/kind", ['kinds' => ['Arbeitsdienst']]);
        $this->app->db->execute("UPDATE {event_kinds} SET kinds = ''");
        $this->assertSame(['Arbeitsdienst'], $this->ok('GET', '/attendance/events')[0]['kinds']);
    }

    public function testStoredInBackup(): void
    {
        $this->assertContains('event_kinds', Backup::CORE_TABLES);
    }
}
