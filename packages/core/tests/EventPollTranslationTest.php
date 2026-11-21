<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\I18n\I18n;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * Termine und Umfragen je Sprache (Plan „Mehrsprachigkeit“, PR 9d): Fassungen beim Anlegen und Ändern, Rückfall auf
 * Deutsch mit Hinweis, deutsche Fassung zum Bearbeiten, Prüfungen, Löschen.
 */
final class EventPollTranslationTest extends TestCase
{
    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $member;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $clock = new FixedClock(new \DateTimeImmutable('2026-11-06 12:00:00', new \DateTimeZone('UTC')));
        $this->app = TestApp::create(TestApp::connection(), $this->users, clock: $clock);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->member = $this->users->add('anna', Roles::MEMBER);
    }

    protected function tearDown(): void
    {
        I18n::setLocale(I18n::SOURCE_LOCALE);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $query
     */
    private function call(?int $user, string $method, string $path, array $body = [], ?string $locale = null, array $query = []): Response
    {
        $this->users->current = $user;
        $res = $this->app->api()->handle(new Request($method, $path, $query, $body, [], '', null, $locale));
        I18n::setLocale(I18n::SOURCE_LOCALE);
        return $res;
    }

    /** @return array<string, mixed> */
    private function data(Response $res): array
    {
        $this->assertLessThan(300, $res->status, (string) json_encode($res->data));
        return json_decode((string) json_encode($res->data), true);
    }

    public function testPublicEventPerLanguage(): void
    {
        $base = ['title' => 'Schützenfest', 'start_date' => '2026-12-01', 'location' => 'Festzelt', 'description' => 'Mit Umzug'];
        $this->assertSame(400, $this->call($this->admin, 'POST', '/events', $base + ['translations' => ['en' => ['title' => 'Fair']]])->status, 'Englisch noch aus');
        $this->call($this->admin, 'PATCH', '/admin/settings', ['languages' => 'de,en']);
        $event = $this->data($this->call($this->admin, 'POST', '/events', $base + ['translations' => ['en' => ['title' => 'Shooting fair', 'location' => 'Marquee', 'url' => 'x']]], 'en'));
        $id = $event['id'];
        $this->assertSame('Schützenfest', $event['title'], 'Antwort zum Bearbeiten: deutsch, auch in der englischen App');
        $this->assertSame(['title' => 'Shooting fair', 'location' => 'Marquee', 'description' => ''], $event['translations']['en']);

        $en = $this->data($this->call(null, 'GET', '/events', [], 'en'))[0];
        $this->assertSame(['Shooting fair', 'Marquee', 'Mit Umzug', 'some'], [$en['title'], $en['location'], $en['description'], $en['untranslated']]);
        $this->assertArrayNotHasKey('translations', $en);
        $de = $this->data($this->call(null, 'GET', '/events'))[0];
        $this->assertSame('Schützenfest', $de['title']);
        $this->assertArrayNotHasKey('untranslated', $de);

        // Zum Bearbeiten nur mit manage_events
        $src = $this->data($this->call($this->admin, 'GET', '/events', [], 'en', ['source' => '1']))[0];
        $this->assertSame('Schützenfest', $src['title']);
        $this->assertSame('Marquee', $src['translations']['en']['location']);
        $this->assertSame('Shooting fair', $this->data($this->call($this->member, 'GET', '/events', [], 'en', ['source' => '1']))[0]['title']);

        // Ändern: nur die Beschreibung, Rest bleibt; zu langer Titel geht nicht
        $this->data($this->call($this->admin, 'PATCH', "/events/$id", ['translations' => ['en' => ['description' => 'With parade']]]));
        $en = $this->data($this->call(null, 'GET', '/events', [], 'en'))[0];
        $this->assertSame(['Shooting fair', 'With parade'], [$en['title'], $en['description']]);
        $this->assertArrayNotHasKey('untranslated', $en);
        $this->assertSame(400, $this->call($this->admin, 'PATCH', "/events/$id", ['translations' => ['en' => ['title' => str_repeat('x', 192)]]])->status);
        $this->assertSame('Shooting fair', $this->app->events()->inLocale($this->app->events()->get($id, true) ?? [], 'en')['title'], 'für Benachrichtigungen');

        $this->assertSame(200, $this->call($this->admin, 'DELETE', "/events/$id")->status);
        $this->assertSame([], $this->app->translations()->repository()->forRef('event', (string) $id));
    }

    public function testInternalCalendarEntryPerLanguage(): void
    {
        $this->call($this->admin, 'PATCH', '/admin/settings', ['calendar_source' => 'app', 'languages' => 'de,en']);
        $entry = $this->data($this->call($this->admin, 'POST', '/calendar/entries', ['title' => 'Arbeitsdienst', 'start_date' => '2026-11-20', 'location' => 'Schießstand', 'translations' => ['en' => ['title' => 'Work day']]]));
        $list = $this->data($this->call($this->admin, 'GET', '/calendar/entries', [], 'en'));
        $this->assertSame('Arbeitsdienst', $list[0]['title'], 'Verwaltung: deutsch');
        $this->assertSame('Work day', $list[0]['translations']['en']['title']);
        $calendar = $this->data($this->call($this->member, 'GET', '/calendar', [], 'en'));
        $ev = $calendar['events'][0] ?? $calendar[0] ?? [];
        $this->assertSame(['Work day', 'some'], [$ev['title'] ?? null, $ev['untranslated'] ?? null], 'Ort nur auf Deutsch');
        $this->assertSame(200, $this->call($this->admin, 'DELETE', '/calendar/entries/' . $entry['id'])->status);
        $this->assertSame([], $this->app->translations()->repository()->forRef('calendar_entry', (string) $entry['id']));
    }

    public function testPollPerLanguage(): void
    {
        $this->call($this->admin, 'PATCH', '/admin/settings', ['languages' => 'de,en']);
        $poll = $this->data($this->call($this->admin, 'POST', '/polls', [
            'title' => 'Grillfest?', 'description' => 'Am Samstag', 'type' => 'single', 'options' => ['Ja', 'Nein'],
            'translations' => ['en' => ['title' => 'Barbecue?', 'options' => ['Yes', '']]],
        ]));
        $id = $poll['id'];
        $this->assertSame(400, $this->call($this->admin, 'PATCH', "/polls/$id", ['translations' => ['en' => ['options' => ['Yes']]]])->status, 'gleich viele wie auf Deutsch');

        $en = $this->data($this->call($this->member, 'GET', "/polls/$id", [], 'en'));
        $this->assertSame(['Barbecue?', 'Am Samstag', ['Yes', 'Nein'], 'some'], [$en['title'], $en['description'], array_column($en['options'], 'title'), $en['untranslated']]);
        $this->assertArrayNotHasKey('translations', $en, 'nur für die Verwaltung');
        $this->assertSame('Barbecue?', $this->app->polls()->titleIn($id, 'Grillfest?', 'en'));

        // Abstimmen in der englischen App gilt wie auf Deutsch (dieselben Möglichkeiten)
        $this->data($this->call($this->member, 'PUT', "/polls/$id/vote", ['option_ids' => [$en['options'][0]['id']]], 'en'));
        $de = $this->data($this->call($this->member, 'GET', "/polls/$id"));
        $this->assertSame(['Ja', 'Nein'], array_column($de['options'], 'title'));
        $this->assertSame([$de['options'][0]['id']], $de['my_answer']['option_ids']);

        // Verwaltung in der englischen App: deutsch unter source, Fassungen unter translations
        $admin = $this->data($this->call($this->admin, 'GET', "/polls/$id", [], 'en'));
        $this->assertSame(['title' => 'Grillfest?', 'description' => 'Am Samstag', 'options' => ['Ja', 'Nein']], $admin['source']);
        $this->assertSame(['title' => 'Barbecue?', 'description' => '', 'options' => ['Yes', '']], $admin['translations']['en'], 'leer bleibt leer');

        $this->data($this->call($this->admin, 'PATCH', "/polls/$id", ['translations' => ['en' => ['description' => 'On Saturday']]]));
        $this->assertSame('On Saturday', $this->data($this->call($this->member, 'GET', "/polls/$id", [], 'en'))['description']);
        $board = array_values(array_filter($this->data($this->call($this->member, 'GET', '/board', [], 'en'))['items'], static fn (array $i): bool => $i['kind'] === 'poll'));
        $this->assertSame('Barbecue?', $board[0]['title'] ?? null);

        $this->assertSame(200, $this->call($this->admin, 'DELETE', "/polls/$id")->status);
        $this->assertSame([], $this->app->translations()->repository()->forRef('poll', (string) $id));
    }
}
