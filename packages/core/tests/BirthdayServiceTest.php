<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\BirthdayService;
use KompanieApp\Core\Domain\MaintenanceService;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Push\PushService;
use KompanieApp\Core\Push\WebPushCrypto;
use KompanieApp\Core\Storage\Backup;
use KompanieApp\Core\Tests\Support\FakeHttpClient;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\NullScheduler;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Geburtstage (Issue #1): Rechte, Prüfung, Sichtbarkeit, Zeitraum, Zeitzone, Schalttag, Löschen, Benachrichtigung. */
final class BirthdayServiceTest extends TestCase
{
    private FakeUsers $users;
    private FixedClock $clock;
    private NullScheduler $scheduler;
    private App $app;
    private int $admin;
    private int $m;
    private int $m2;
    private int $m3;
    private int $cand;
    private int $guest;
    private int $creator;

    /** @param array<string, bool> $features */
    private function setUpApp(array $features = ['birthdays' => true], string $now = '2026-10-20 12:00:00'): void
    {
        $this->users = new FakeUsers();
        $this->clock = new FixedClock(new \DateTimeImmutable($now, new \DateTimeZone('Europe/Berlin')));
        $this->scheduler = new NullScheduler();
        $off = array_fill_keys(['events', 'calendar', 'news', 'gallery', 'points', 'penalties', 'ranks', 'titles', 'attendance', 'documents', 'board', 'polls', 'birthdays'], false);
        $http = new FakeHttpClient(static fn (): array => ['status' => 201, 'body' => '']);
        $this->app = TestApp::create(TestApp::connection(), $this->users, [], $http, null, null, $this->clock, $features + $off, [], $this->scheduler);
        $this->app->scheduleTasks();
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->m = $this->users->add('mitglied', Roles::MEMBER);
        $this->m2 = $this->users->add('zweite', Roles::MEMBER);
        $this->m3 = $this->users->add('dritte', Roles::MEMBER);
        $this->cand = $this->users->add('anwaerter', TestApp::candidateRole($this->app));
        $this->guest = $this->users->add('gast', Roles::GUEST);
        // Eigene Rolle mit „Mitglieder anlegen“
        $role = $this->app->roles()->create(['label' => 'Anleger', 'capabilities' => TestApp::caps(['member', 'create_users'])]);
        $this->creator = $this->users->add('anleger', $role['key']);
        $this->users->current = $this->admin;
    }

    private function call(string $method, string $path, array $body = [], array $query = []): Response
    {
        return $this->app->api()->handle(new Request($method, $path, $query, $body));
    }

    private function as(?int $user): self
    {
        $this->users->current = $user;
        return $this;
    }

    private function at(string $local): void
    {
        $this->clock->at = new \DateTimeImmutable($local, new \DateTimeZone('Europe/Berlin'));
    }

    /** @param array<string, mixed> $body */
    private function setOwn(int $user, array $body, int $status = 200): array
    {
        $res = $this->as($user)->call('PUT', '/me/birthday', $body);
        $this->assertSame($status, $res->status, (string) json_encode($res->data));
        return $res->data;
    }

    /** @return array<string, array<string, mixed>> Name => Eintrag */
    private function listed(?int $days = null): array
    {
        $res = $this->as($this->m)->call('GET', '/birthdays', [], $days === null ? [] : ['days' => (string) $days]);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        return array_column($res->data, null, 'name');
    }

    public function testOwnBirthdayWithAndWithoutYear(): void
    {
        $this->setUpApp();
        $this->assertSame(['birthday' => null], $this->as($this->m)->call('GET', '/me/birthday')->data);
        $saved = $this->setOwn($this->m, ['month' => 3, 'day' => 14, 'year' => 1990]);
        $this->assertSame(['birthday' => ['month' => 3, 'day' => 14, 'year' => 1990, 'visible' => true, 'show_age' => false, 'profile' => 'members']], $saved, 'neu: sichtbar, ohne Alter');
        $saved = $this->setOwn($this->m, ['month' => '3', 'day' => '15', 'year' => null, 'show_age' => true]);
        $this->assertSame(['month' => 3, 'day' => 15, 'year' => null, 'visible' => true, 'show_age' => true, 'profile' => 'members'], $saved['birthday'], 'ohne Jahr; nicht genannte Schalter bleiben');
        $this->setOwn($this->m, ['month' => 3, 'day' => 15, 'visible' => false]);
        $this->assertSame(['month' => 3, 'day' => 15, 'year' => null, 'visible' => false, 'show_age' => true, 'profile' => 'members'], $this->call('GET', '/me/birthday')->data['birthday']);
        $this->setOwn($this->m, ['month' => 3, 'day' => 15, 'visible' => 'nein'], 400);
        // 29.02. ohne Jahr ist erlaubt
        $this->assertSame(29, $this->setOwn($this->m2, ['month' => 2, 'day' => 29])['birthday']['day']);
        $this->assertSame(['birthday' => null], $this->as($this->m)->call('DELETE', '/me/birthday')->data);
        $this->assertSame(['birthday' => null], $this->call('GET', '/me/birthday')->data);
        $this->assertSame(0, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {birthdays} WHERE user_id = ?', [$this->m]));
    }

    public function testValidation(): void
    {
        $this->setUpApp();
        foreach ([
            ['month' => 2, 'day' => 31],
            ['month' => 2, 'day' => 31, 'year' => 1990],
            ['month' => 4, 'day' => 31],
            ['month' => 13, 'day' => 1],
            ['month' => 0, 'day' => 1],
            ['month' => 1, 'day' => 0],
            ['month' => 1],
            ['month' => 'x', 'day' => 1],
            ['month' => 2, 'day' => 29, 'year' => 1990],
            ['month' => 1, 'day' => 1, 'year' => 1899],
            ['month' => 1, 'day' => 1, 'year' => 'neunzehn'],
            ['month' => 10, 'day' => 21, 'year' => 2026],
            ['month' => 1, 'day' => 1, 'year' => 2027],
        ] as $body) {
            $this->setOwn($this->m, $body, 400);
        }
        $this->assertStringContainsString('kein Schaltjahr', $this->setOwn($this->m, ['month' => 2, 'day' => 29, 'year' => 1990], 400)['message']);
        $this->assertStringContainsString('Zukunft', $this->setOwn($this->m, ['month' => 10, 'day' => 21, 'year' => 2026], 400)['message']);
        $this->setOwn($this->m, ['month' => 2, 'day' => 29, 'year' => 1992]);
        $this->setOwn($this->m, ['month' => 10, 'day' => 20, 'year' => 2026], 200);
        $this->setOwn($this->m, ['month' => 1, 'day' => 1, 'year' => 1900], 200);
        $this->assertSame(0, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {birthdays} WHERE user_id <> ?', [$this->m]));
    }

    public function testRightsAndFeature(): void
    {
        $this->setUpApp();
        $this->assertSame(401, $this->as(null)->call('GET', '/birthdays')->status);
        $this->assertSame(401, $this->call('GET', '/me/birthday')->status);
        $this->assertSame(403, $this->as($this->guest)->call('GET', '/birthdays')->status, 'Gast-Konto');
        $this->assertSame(403, $this->as($this->cand)->call('GET', '/birthdays')->status, 'Anwärter');
        $this->assertSame(403, $this->as($this->cand)->call('PUT', '/me/birthday', ['month' => 1, 'day' => 2])->status, 'Anwärter tragen keinen ein');
        $this->assertSame(200, $this->as($this->m)->call('GET', '/birthdays')->status);
        $this->assertSame(400, $this->call('GET', '/birthdays', [], ['days' => 'viele'])->status);
        $this->assertSame(400, $this->call('GET', '/birthdays', [], ['days' => '400'])->status);
        $this->assertSame(403, $this->as($this->m)->call('PUT', "/members/{$this->m2}/birthday", ['month' => 1, 'day' => 2])->status, 'Mitglied für andere → 403');
        $this->assertSame(403, $this->call('DELETE', "/members/{$this->m2}/birthday")->status);
        $this->assertSame(404, $this->as($this->admin)->call('PUT', '/members/999/birthday', ['month' => 1, 'day' => 2])->status);
        $this->assertSame([], array_values(array_filter(array_column($this->app->roles()->describe(), 'key'), static fn (string $k): bool => $k === 'birthdays')), 'keine eigene Standardrolle');

        $this->app->features()->update(['birthdays' => false]);
        foreach ([['GET', '/birthdays'], ['GET', '/me/birthday'], ['PUT', '/me/birthday'], ['DELETE', '/me/birthday'], ['PUT', '/members/2/birthday'], ['DELETE', '/members/2/birthday']] as [$method, $path]) {
            $res = $this->as($this->m)->call($method, $path, ['month' => 1, 'day' => 2]);
            $this->assertSame([403, 'feature_disabled'], [$res->status, $res->data['code']], "$method $path");
        }
        $this->assertArrayNotHasKey('birthday', $this->as($this->admin)->call('GET', "/members/{$this->m}")->data);
        $this->assertNotContains('birthdays', $this->app->push()->availableTypes($this->users->find($this->m)));
        $this->assertArrayNotHasKey(BirthdayService::TASK, $this->scheduler->scheduled);
        $res = $this->call('POST', '/users', ['name' => 'Ohne Bereich', 'birthday' => '1990-01-02']);
        $this->assertSame(400, $res->status, 'Geburtstag beim Anlegen nur mit Bereich');
        $this->app->features()->update(['birthdays' => true]);
        $this->assertSame(3600, $this->scheduler->scheduled[BirthdayService::TASK] ?? null, 'stündlich eingeplant');
    }

    public function testManagerSetsDateButNotSwitches(): void
    {
        $this->setUpApp();
        $this->setOwn($this->m, ['month' => 5, 'day' => 6, 'year' => 1980, 'visible' => false, 'show_age' => true]);
        $res = $this->as($this->creator)->call('PUT', "/members/{$this->m}/birthday", ['month' => 7, 'day' => 8, 'year' => 1981, 'visible' => true, 'show_age' => false]);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertSame(['month' => 7, 'day' => 8, 'year' => 1981, 'visible' => false, 'show_age' => true, 'profile' => 'members'], $res->data['birthday'], 'Schalter bleiben, wie die Person sie gesetzt hat');
        $this->assertSame(400, $this->call('PUT', "/members/{$this->m}/birthday", ['month' => 2, 'day' => 31])->status);
        $this->assertSame(400, $this->call('PUT', "/members/{$this->m}/birthday", ['month' => 1, 'day' => 1, 'year' => 2030])->status);
        // Neu durch die Verwaltung: sichtbar, ohne Alter
        $res = $this->call('PUT', "/members/{$this->m2}/birthday", ['month' => 1, 'day' => 2]);
        $this->assertSame(['month' => 1, 'day' => 2, 'year' => null, 'visible' => true, 'show_age' => false, 'profile' => 'members'], $res->data['birthday']);
        $this->assertSame($this->creator, (int) $this->app->db->scalar('SELECT updated_by FROM {birthdays} WHERE user_id = ?', [$this->m2]));

        // Mitgliedsdetails: mit Jahr und Schaltern
        $detail = $this->call('GET', "/members/{$this->m}")->data;
        $this->assertSame(['month' => 7, 'day' => 8, 'year' => 1981, 'visible' => false, 'show_age' => true, 'profile' => 'members'], $detail['birthday']);
        $this->assertNull($this->call('GET', "/members/{$this->m3}")->data['birthday']);

        // Wer Mitgliedsdetails nur wegen anderer Rechte sieht (hier Punkte), bekommt kein Geburtsdatum
        $pointsRole = $this->app->roles()->create(['label' => 'Punktewart', 'capabilities' => TestApp::caps(['member', 'manage_points'])]);
        $pointsManager = $this->users->add('punktewart', $pointsRole['key']);
        $res = $this->as($pointsManager)->call('GET', "/members/{$this->m}");
        $this->assertSame(200, $res->status);
        $this->assertArrayNotHasKey('birthday', $res->data);
        $this->as($this->creator);

        $this->assertSame(['birthday' => null], $this->call('DELETE', "/members/{$this->m}/birthday")->data);
        $this->assertSame(['birthday' => null], $this->as($this->m)->call('GET', '/me/birthday')->data);
    }

    public function testCreateUsersWithBirthday(): void
    {
        $this->setUpApp();
        $res = $this->as($this->creator)->call('POST', '/users', ['name' => 'Max Mustermann', 'birthday' => '1981-10-22']);
        $this->assertSame(201, $res->status, (string) json_encode($res->data));
        $this->assertSame(['month' => 10, 'day' => 22, 'year' => 1981, 'visible' => true, 'show_age' => false, 'profile' => 'members'], $this->app->birthdays()->of((int) $res->data['id']));
        $this->assertSame(400, $this->call('POST', '/users', ['name' => 'Falsch', 'birthday' => '1990-02-31'])->status);
        $this->assertSame(400, $this->call('POST', '/users', ['name' => 'Falsch', 'birthday' => '22.10.1981'])->status);
        $this->assertFalse($this->users->loginExists('falsch'), 'ungültiger Geburtstag: kein Konto');

        $res = $this->call('POST', '/users/bulk', ['people' => [['name' => 'Erika Muster', 'birthday' => '02-29'], ['name' => 'Ohne Geburtstag']]]);
        $this->assertSame(201, $res->status, (string) json_encode($res->data));
        $this->assertSame(['month' => 2, 'day' => 29, 'year' => null, 'visible' => true, 'show_age' => false, 'profile' => 'members'], $this->app->birthdays()->of((int) $res->data[0]['id']));
        $this->assertNull($this->app->birthdays()->of((int) $res->data[1]['id']));
        $res = $this->call('POST', '/users/bulk', ['people' => [['name' => 'Gut'], ['name' => 'Schlecht', 'birthday' => '2030-01-01']]]);
        $this->assertSame(400, $res->status);
        $this->assertStringContainsString('Zeile 2 (Schlecht)', $res->data['message']);
        $this->assertFalse($this->users->loginExists('gut'), 'bei einem Fehler wird keiner angelegt');
    }

    public function testListVisibilityAgeAndNoYear(): void
    {
        $this->setUpApp();
        $this->setOwn($this->m, ['month' => 10, 'day' => 22, 'year' => 1981, 'show_age' => true]);
        $this->setOwn($this->m2, ['month' => 10, 'day' => 21, 'year' => 1990]); // ohne „Alter anzeigen“
        $this->setOwn($this->m3, ['month' => 10, 'day' => 23, 'show_age' => true]); // ohne Jahr
        $this->setOwn($this->admin, ['month' => 10, 'day' => 20, 'year' => 1970, 'visible' => false, 'show_age' => true]);
        $this->app->birthdays()->store($this->users->find($this->admin), $this->cand, ['month' => 10, 'day' => 20, 'year' => 2000]);
        $this->app->birthdays()->store($this->users->find($this->admin), $this->guest, ['month' => 10, 'day' => 20, 'year' => 2000]);

        $res = $this->as($this->m)->call('GET', '/birthdays');
        $this->assertSame(['Zweite', 'Mitglied', 'Dritte'], array_column($res->data, 'name'), 'ab heute sortiert; unsichtbare, Anwärter und Gäste fehlen');
        $this->assertSame(
            ['user_id' => $this->m, 'name' => 'Mitglied', 'month' => 10, 'day' => 22, 'date' => '2026-10-22', 'days_until' => 2, 'today' => false, 'age' => 45],
            $res->data[1],
        );
        $this->assertNull($res->data[0]['age'], 'ohne „Alter anzeigen“ kein Alter');
        $this->assertNull($res->data[2]['age'], 'ohne Jahr kein Alter');
        $json = (string) json_encode($res->data);
        foreach (['1981', '1990', '1970', '"year"'] as $needle) {
            $this->assertStringNotContainsString($needle, $json, 'nie das Geburtsjahr');
        }
        // Wer seinen Geburtstag verbirgt, sieht ihn trotzdem selbst unter „Konto“.
        $this->assertFalse($this->as($this->admin)->call('GET', '/me/birthday')->data['birthday']['visible']);

        // Anwärter werden Mitglied: Dann erscheint der Geburtstag.
        $this->users->setRole($this->cand, Roles::MEMBER);
        $this->assertArrayHasKey('Anwaerter', $this->listed());
        // „Anderen Mitgliedern zeigen“ aus: verschwindet.
        $this->setOwn($this->m, ['month' => 10, 'day' => 22, 'visible' => false]);
        $this->assertArrayNotHasKey('Mitglied', $this->listed());
    }

    public function testUpcomingWindowIncludesTodayAndDaySevenNotEight(): void
    {
        $this->setUpApp();
        $this->setOwn($this->m, ['month' => 10, 'day' => 20]); // heute
        $this->setOwn($this->m2, ['month' => 10, 'day' => 27]); // Tag 7
        $this->setOwn($this->m3, ['month' => 10, 'day' => 28]); // Tag 8
        $this->setOwn($this->admin, ['month' => 10, 'day' => 19]); // gestern → in einem Jahr
        $upcoming = $this->listed(BirthdayService::UPCOMING_DAYS);
        $this->assertSame(['Mitglied', 'Zweite'], array_keys($upcoming));
        $this->assertSame([0, true], [$upcoming['Mitglied']['days_until'], $upcoming['Mitglied']['today']]);
        $this->assertSame([7, false], [$upcoming['Zweite']['days_until'], $upcoming['Zweite']['today']]);
        $all = $this->listed();
        $this->assertSame(['Mitglied', 'Zweite', 'Dritte', 'Admin'], array_keys($all));
        $this->assertSame(8, $all['Dritte']['days_until']);
        $this->assertSame(['2027-10-19', 364], [$all['Admin']['date'], $all['Admin']['days_until']]);
        $this->assertSame(['Mitglied'], array_keys($this->listed(0)), 'days=0: nur heute');
    }

    public function testAdminsChooseHowManyDaysAheadBirthdaysAreUpcoming(): void
    {
        $this->setUpApp();
        $config = fn (): mixed => $this->as($this->m)->call('GET', '/config')->data['birthdays'];
        $res = $this->as($this->admin)->call('GET', '/admin/birthdays');
        $this->assertSame(['upcoming_days' => 7], $res->data, 'Standard: 7 Tage');
        $this->assertSame(['upcomingDays' => 7], $config());
        $this->assertSame(403, $this->as($this->m)->call('PATCH', '/admin/birthdays', ['upcoming_days' => 3])->status, 'nur Admins');
        $this->assertSame(403, $this->as($this->m)->call('GET', '/admin/birthdays')->status);

        // Ungültig: 400, nichts gespeichert.
        foreach ([['upcoming_days' => 31], ['upcoming_days' => -1], ['upcoming_days' => 2.5], ['upcoming_days' => 'drei'], ['upcoming_days' => ''], []] as $body) {
            $this->assertSame(400, $this->as($this->admin)->call('PATCH', '/admin/birthdays', $body)->status, (string) json_encode($body));
        }
        $this->assertSame(7, $this->app->birthdays()->upcomingDays());

        $res = $this->as($this->admin)->call('PATCH', '/admin/birthdays', ['upcoming_days' => 14]);
        $this->assertSame(['upcoming_days' => 14], $res->data);
        $this->assertSame(['upcomingDays' => 14], $config(), 'die App bekommt den Vorlauf mit /config');
        $this->as($this->admin)->call('PATCH', '/admin/birthdays', ['upcoming_days' => '0']);
        $this->assertSame(0, $this->app->birthdays()->upcomingDays(), 'auch als Text aus Formularen; 0 = nur heute');
        $this->as($this->admin)->call('PATCH', '/admin/birthdays', ['upcoming_days' => 30]);
        $this->assertSame(30, $this->app->birthdays()->upcomingDays());

        // Ohne den Bereich: keine Angabe in /config, die Einstellung nicht erreichbar.
        $this->app->features()->update(['birthdays' => false]);
        $this->assertNull($config());
        $this->assertSame(403, $this->as($this->admin)->call('GET', '/admin/birthdays')->status);
    }

    public function testYearChange(): void
    {
        $this->setUpApp(['birthdays' => true], '2026-12-28 10:00:00');
        $this->setOwn($this->m, ['month' => 1, 'day' => 2, 'year' => 1980, 'show_age' => true]);
        $this->setOwn($this->m2, ['month' => 12, 'day' => 31]);
        $upcoming = $this->listed(7);
        $this->assertSame(['Zweite', 'Mitglied'], array_keys($upcoming));
        $this->assertSame(['date' => '2027-01-02', 'days_until' => 5, 'age' => 47], array_intersect_key($upcoming['Mitglied'], array_flip(['date', 'days_until', 'age'])), 'Alter im neuen Jahr');
    }

    public function testLeapDayInNonLeapYearOnFebruary28(): void
    {
        $this->setUpApp(['birthdays' => true], '2027-02-27 10:00:00');
        $this->setOwn($this->m, ['month' => 2, 'day' => 29, 'year' => 2000, 'show_age' => true]);
        $this->setOwn($this->m2, ['month' => 3, 'day' => 1]);
        $list = $this->listed();
        $this->assertSame(['2027-02-28', 1, 27], [$list['Mitglied']['date'], $list['Mitglied']['days_until'], $list['Mitglied']['age']]);
        $this->assertSame([2, 29], [$list['Mitglied']['month'], $list['Mitglied']['day']], 'Datum selbst bleibt der 29.02.');
        $this->at('2027-02-28 10:00:00');
        $this->assertTrue($this->listed()['Mitglied']['today'], 'am 28.02. im Jahr ohne Schalttag');
        $this->assertSame(1, $this->listed()['Zweite']['days_until']);
        $this->at('2027-03-01 10:00:00');
        $this->assertSame(['2028-02-29', 365], [$this->listed()['Mitglied']['date'], $this->listed()['Mitglied']['days_until']], 'im Schaltjahr am 29.02.');
        $this->at('2028-02-28 10:00:00');
        $this->assertSame([1, false], [$this->listed()['Mitglied']['days_until'], $this->listed()['Mitglied']['today']]);
    }

    public function testTodayInCompanyTimeZoneAroundMidnight(): void
    {
        $this->setUpApp();
        $this->setOwn($this->m, ['month' => 10, 'day' => 21]);
        // 21:30 UTC = 23:30 in Berlin (Sommerzeit): noch der 20.
        $this->clock->at = new \DateTimeImmutable('2026-10-20 21:30:00', new \DateTimeZone('UTC'));
        $this->assertSame([1, false], [$this->listed()['Mitglied']['days_until'], $this->listed()['Mitglied']['today']]);
        // 22:30 UTC = 00:30 in Berlin: schon der 21., auch wenn der Server (UTC) noch den 20. hat.
        $this->clock->at = new \DateTimeImmutable('2026-10-20 22:30:00', new \DateTimeZone('UTC'));
        $this->assertSame([0, true], [$this->listed()['Mitglied']['days_until'], $this->listed()['Mitglied']['today']]);
        // Zukunft nach Ortszeit: Jahrgang 2026 am 21.10. ist jetzt erlaubt.
        $this->setOwn($this->m2, ['month' => 10, 'day' => 21, 'year' => 2026]);
    }

    public function testAccountDeletionRemovesBirthday(): void
    {
        $this->setUpApp();
        $this->setOwn($this->m, ['month' => 1, 'day' => 2, 'year' => 1990]);
        $this->setOwn($this->m2, ['month' => 1, 'day' => 3]);
        $this->assertSame(200, $this->as($this->admin)->call('DELETE', "/members/{$this->m}")->status);
        $this->assertSame(0, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {birthdays} WHERE user_id = ?', [$this->m]));
        $this->assertSame(1, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {birthdays}'), 'die der anderen bleiben');
    }

    public function testBackupContainsTable(): void
    {
        $this->setUpApp();
        $this->setOwn($this->m, ['month' => 1, 'day' => 2]);
        $this->assertContains('birthdays', Backup::CORE_TABLES);
        $this->assertContains(PushService::BIRTHDAYS_OFFERED_SETTING, Backup::LOCAL_SETTINGS);
        $this->assertCount(1, (new Backup($this->app->db))->dump(['birthdays'])['birthdays']);
    }

    /* ---------------------------------------------------------------- */
    /* Benachrichtigung                                                 */
    /* ---------------------------------------------------------------- */

    /** @return array{endpoint: string, keys: array{p256dh: string, auth: string}} */
    private static function device(string $name): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        return [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/' . $name,
            'keys' => ['p256dh' => WebPushCrypto::b64uEncode(WebPushCrypto::rawPublic($key)), 'auth' => WebPushCrypto::b64uEncode(random_bytes(16))],
        ];
    }

    /** @param list<string>|null $types */
    private function subscribe(?int $user, string $device, ?array $types = null): void
    {
        $d = self::device($device);
        $this->app->push()->subscribe($user === null ? null : $this->users->find($user), $d['endpoint'], $d['keys'], $types, null, '1.2.3.4');
    }

    /** @return list<array{device: string, title: string, body: string, route: string, ttl: int}> */
    private function queued(): array
    {
        $out = [];
        foreach ($this->app->db->select('SELECT s.endpoint, q.payload, q.ttl FROM {push_queue} q JOIN {push_subscriptions} s ON s.id = q.subscription_id ORDER BY q.id') as $row) {
            $msg = json_decode((string) $row['payload'], true);
            $out[] = ['device' => substr((string) $row['endpoint'], strlen('https://fcm.googleapis.com/fcm/send/')), 'title' => $msg['title'], 'body' => $msg['body'], 'route' => $msg['route'], 'ttl' => (int) $row['ttl']];
        }
        return $out;
    }

    private function setUpPush(string $now = '2026-10-20 06:30:00'): void
    {
        $this->setUpApp(['birthdays' => true, 'events' => true], $now);
        if (!$this->app->push()->supported()) {
            $this->markTestSkipped('ohne Push-Unterstützung');
        }
        $this->subscribe($this->m, 'm');
        $this->subscribe($this->m2, 'm2');
        $this->subscribe($this->m3, 'm3-ohne', ['events']);
        $this->subscribe($this->cand, 'anwaerter');
        $this->subscribe($this->guest, 'gast-konto');
        $this->subscribe(null, 'gast');
        $this->subscribe($this->admin, 'admin');
        $this->users->users[$this->m]['name'] = 'Max Mustermann';
    }

    public function testPushOnTheDayFromSevenOnceAndNotToTheBirthdayPerson(): void
    {
        $this->setUpPush();
        $this->assertContains('birthdays', $this->app->push()->availableTypes($this->users->find($this->m)));
        $this->assertNotContains('birthdays', $this->app->push()->availableTypes($this->users->find($this->cand)), 'nur Mitglieder');
        $this->assertNotContains('birthdays', $this->app->push()->availableTypes(null));
        $this->assertSame(PushService::TYPE_BIRTHDAYS, 'birthdays');
        $this->assertSame('Geburtstage: am Tag selbst', $this->app->push()->types()['birthdays']['label']);

        $this->setOwn($this->m, ['month' => 10, 'day' => 20, 'year' => 1981, 'show_age' => true]);
        $this->setOwn($this->m2, ['month' => 10, 'day' => 21, 'year' => 1990, 'show_age' => true]); // morgen

        // Vor 7 Uhr Ortszeit (06:30): nichts.
        $this->app->runTask(BirthdayService::TASK);
        $this->assertSame([], $this->queued());
        $this->assertNull($this->app->birthdays()->repository()->find($this->m)['last_notified'] ?? null);

        $this->at('2026-10-20 07:05:00');
        $this->app->runTask(BirthdayService::TASK);
        $q = $this->queued();
        $this->assertSame(['m2', 'admin'], array_column($q, 'device'), 'alle Mitglieder außer dem Geburtstagskind, nur mit der Art');
        $this->assertSame(['Geburtstage', 'Heute hat Max Mustermann Geburtstag (wird 45)', 'termine'], [$q[0]['title'], $q[0]['body'], $q[0]['route']]);
        $this->assertSame(16 * 3600 + 55 * 60, $q[0]['ttl'], 'zustellbar bis Mitternacht');
        $this->assertSame('2026-10-20', $this->app->birthdays()->repository()->find($this->m)['last_notified'] ?? null);

        // Wiederholte Läufe (auch am selben Tag später): kein zweites Mal.
        $this->app->runTask(BirthdayService::TASK);
        $this->at('2026-10-20 18:00:00');
        $this->app->runTask(BirthdayService::TASK);
        $this->assertCount(2, $this->queued());

        // Am nächsten Tag die nächste, nicht vor 7 Uhr.
        $this->app->db->execute('DELETE FROM {push_queue}');
        $this->at('2026-10-21 00:30:00');
        $this->app->runTask(BirthdayService::TASK);
        $this->assertSame([], $this->queued());
        $this->at('2026-10-21 09:00:00');
        $this->app->runTask(BirthdayService::TASK);
        $this->assertSame(['m', 'admin'], array_column($this->queued(), 'device'));
        $this->assertSame('Heute hat Zweite Geburtstag (wird 36)', $this->queued()[0]['body']);
    }

    public function testAdminsSwitchNotificationOffAndChooseTheHour(): void
    {
        $this->setUpPush('2026-10-20 07:30:00');
        $this->setOwn($this->m, ['month' => 10, 'day' => 20, 'year' => 1981]);
        $res = $this->as($this->admin)->call('GET', '/admin/push');
        $this->assertSame(['enabled' => true, 'hour' => 7], $res->data['birthdays'], 'Standard: an, ab 7 Uhr');
        $this->assertSame(403, $this->as($this->m)->call('PATCH', '/admin/push', ['birthday_notify' => false])->status, 'nur Admins');

        // Ungültige Angaben: 400, nichts gespeichert (auch nicht der gültige Teil).
        foreach ([['birthday_hour' => 24], ['birthday_hour' => -1], ['birthday_hour' => 7.5], ['birthday_hour' => 'acht'], ['birthday_notify' => 'nein'], ['birthday_notify' => false, 'birthday_hour' => 24]] as $body) {
            $this->assertSame(400, $this->as($this->admin)->call('PATCH', '/admin/push', $body)->status, (string) json_encode($body));
        }
        $this->assertSame(['enabled' => true, 'hour' => 7], $this->app->birthdays()->notifySettings());

        // Aus: kein Versand, nichts beansprucht.
        $res = $this->as($this->admin)->call('PATCH', '/admin/push', ['birthday_notify' => false]);
        $this->assertSame(['enabled' => false, 'hour' => 7], $res->data['birthdays']);
        $this->app->runTask(BirthdayService::TASK);
        $this->assertSame([], $this->queued());
        $this->assertNull($this->app->birthdays()->repository()->find($this->m)['last_notified'] ?? null);

        // Wieder an, aber erst ab 9 Uhr: um 07:30 nichts, ab 09:00 einmal.
        $res = $this->as($this->admin)->call('PATCH', '/admin/push', ['birthday_notify' => true, 'birthday_hour' => 9]);
        $this->assertSame(['enabled' => true, 'hour' => 9], $res->data['birthdays']);
        $this->app->runTask(BirthdayService::TASK);
        $this->assertSame([], $this->queued());
        $this->at('2026-10-20 09:00:00');
        $this->app->runTask(BirthdayService::TASK);
        $this->assertSame(['m2', 'admin'], array_column($this->queued(), 'device'));

        // Früher gestellt: kein zweites Mal am selben Tag.
        $this->as($this->admin)->call('PATCH', '/admin/push', ['birthday_hour' => '6']);
        $this->assertSame(6, $this->app->birthdays()->notifyHour(), 'Stunde auch als Text aus Formularen');
        $this->app->runTask(BirthdayService::TASK);
        $this->assertCount(2, $this->queued());

        // Ohne den Bereich Geburtstage keine Angaben dazu.
        $this->app->features()->update(['birthdays' => false]);
        $this->assertNull($this->as($this->admin)->call('GET', '/admin/push')->data['birthdays']);
    }

    public function testPushOnlyForVisibleAndAgeOnlyIfReleased(): void
    {
        $this->setUpPush('2026-10-20 08:00:00');
        $this->setOwn($this->m, ['month' => 10, 'day' => 20, 'year' => 1981]); // ohne „Alter anzeigen“
        $this->setOwn($this->m2, ['month' => 10, 'day' => 20, 'year' => 1990, 'show_age' => true, 'visible' => false]);
        $this->setOwn($this->m3, ['month' => 10, 'day' => 20, 'show_age' => true]); // ohne Jahr
        $this->app->birthdays()->store($this->users->find($this->admin), $this->cand, ['month' => 10, 'day' => 20, 'year' => 2000]);
        $this->app->runTask(BirthdayService::TASK);
        $bodies = array_values(array_unique(array_column($this->queued(), 'body')));
        $this->assertSame(['Heute hat Max Mustermann Geburtstag', 'Heute hat Dritte Geburtstag'], $bodies, 'unsichtbare und Anwärter: keine; Alter nur, wenn freigegeben');
        $this->assertNotContains('m', array_column(array_filter($this->queued(), static fn (array $q): bool => str_contains($q['body'], 'Max')), 'device'));
        $this->assertNull($this->app->birthdays()->repository()->find($this->m2)['last_notified'] ?? null, 'unsichtbar: nicht beansprucht');

        // Später am Tag eingetragen: kommt beim nächsten Lauf noch.
        $this->app->db->execute('DELETE FROM {push_queue}');
        $this->setOwn($this->admin, ['month' => 10, 'day' => 20]);
        $this->app->runTask(BirthdayService::TASK);
        $this->assertSame(['m', 'm2'], array_column($this->queued(), 'device'));
    }

    public function testMaintenancePausesAndFeatureOffSendsNothing(): void
    {
        $this->setUpPush('2026-10-20 08:00:00');
        $this->setOwn($this->m, ['month' => 10, 'day' => 20]);
        $this->assertFalse(MaintenanceService::runsDuringMaintenance(BirthdayService::TASK));
        $this->as($this->admin)->call('POST', '/admin/maintenance', ['on' => true, 'reason' => 'Umbau']);
        $this->app->runTask(BirthdayService::TASK);
        $this->assertSame([], $this->queued());
        $this->call('POST', '/admin/maintenance', ['on' => false]);
        $this->app->features()->update(['birthdays' => false]);
        $this->app->runTask(BirthdayService::TASK);
        $this->assertSame([], $this->queued());
        $this->app->features()->update(['birthdays' => true]);
        $this->app->runTask(BirthdayService::TASK);
        $this->assertCount(2, $this->queued());
    }

    /** @return array<string, array{string}> */
    public static function drivers(): array
    {
        return getenv('KOMPANIE_TEST_MYSQL') ? ['sqlite' => ['sqlite'], 'mysql' => ['mysql']] : ['sqlite' => ['sqlite']];
    }

    #[DataProvider('drivers')]
    public function testClaimOncePerDayOnEveryDatabase(string $driver): void
    {
        $db = TestApp::connection($driver);
        try {
            $users = new FakeUsers();
            $app = TestApp::create($db, $users, [], null, null, null, new FixedClock(new \DateTimeImmutable('2026-10-20 12:00:00', new \DateTimeZone('Europe/Berlin'))), ['birthdays' => true]);
            $repo = $app->birthdays()->repository();
            $repo->save(5, 10, 20, null, null, null, 1, '2026-10-20 10:00:00');
            $this->assertTrue($repo->claim(5, '2026-10-20'));
            $this->assertFalse($repo->claim(5, '2026-10-20'), 'derselbe Tag nur einmal');
            $this->assertSame('2026-10-20', $repo->find(5)['last_notified'] ?? null);
            $this->assertTrue($repo->claim(5, '2027-10-20'), 'im nächsten Jahr wieder');
            $repo->save(5, 10, 21, 1990, false, true, 2, '2026-10-20 11:00:00');
            $this->assertSame(['month' => 10, 'day' => 21, 'year' => 1990, 'visible' => false, 'show_age' => true, 'profile' => 'members'], $app->birthdays()->of(5));
            $repo->save(5, 10, 22, null, null, null, 2, '2026-10-20 11:00:00');
            $this->assertSame(['month' => 10, 'day' => 22, 'year' => null, 'visible' => false, 'show_age' => true, 'profile' => 'members'], $app->birthdays()->of(5), 'Schalter bleiben');
        } finally {
            TestApp::dropTables($db);
        }
    }

    public function testExistingSubscriptionsGetTheTypeOnFirstSwitchOn(): void
    {
        $this->setUpApp(['birthdays' => false, 'events' => true]);
        if (!$this->app->push()->supported()) {
            $this->markTestSkipped('ohne Push-Unterstützung');
        }
        $this->subscribe($this->m, 'm');
        $this->subscribe($this->cand, 'a');
        $types = fn (): array => array_column($this->app->push()->repository()->all(), 'types', 'endpoint');
        $this->assertSame(['events', 'reminder'], $types()['https://fcm.googleapis.com/fcm/send/m']);
        $this->app->features()->update(['birthdays' => true]);
        $this->assertSame(['birthdays', 'events', 'reminder'], $types()['https://fcm.googleapis.com/fcm/send/m']);
        $this->assertSame(['events', 'reminder'], $types()['https://fcm.googleapis.com/fcm/send/a'], 'Anwärter nicht');
        $this->app->push()->update($this->users->find($this->m), 'https://fcm.googleapis.com/fcm/send/m', null, ['events'], null);
        $this->app->features()->update(['birthdays' => false]);
        $this->app->features()->update(['birthdays' => true]);
        $this->assertSame(['events'], $types()['https://fcm.googleapis.com/fcm/send/m'], 'nur einmal');
    }
}
