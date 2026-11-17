<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\PassiveMemberService;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Push\PushService;
use KompanieApp\Core\Push\WebPushCrypto;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * Geburtstage der Passiven (Entscheidungen vom 17.11.2026): Erinnerung nur an die eingestellten Alter (anfangs keine),
 * N Tage vorher (Vorgabe 7), per Push an alle mit manage_passive und view_personal, dazu als Hinweis in der Liste.
 */
final class PassiveBirthdaysTest extends TestCase
{
    private FakeUsers $users;
    private App $app;
    private FixedClock $clock;
    private int $admin;
    private int $member;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->clock = new FixedClock(new \DateTimeImmutable('2026-11-16 12:00:00', new \DateTimeZone('Europe/Berlin')));
        $this->app = TestApp::create(TestApp::connection(), $this->users, clock: $this->clock);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->member = $this->users->add('mitglied', Roles::MEMBER);
        $this->users->current = $this->admin;
    }

    private function at(string $local): void
    {
        $this->clock->at = new \DateTimeImmutable($local, new \DateTimeZone('Europe/Berlin'));
    }

    private function call(string $method, string $path, array $body = []): Response
    {
        return $this->app->api()->handle(new Request($method, $path, [], $body));
    }

    private function withRights(string $login, array $caps): int
    {
        $key = $this->app->roles()->create(['label' => 'Rolle ' . $login, 'capabilities' => [...Roles::TEMPLATES[Roles::MEMBER], ...$caps]])['key'];
        return $this->users->add($login, $key);
    }

    private function passive(string $first, string $last, ?string $birth): int
    {
        $res = $this->call('POST', '/passive-members', ['first_name' => $first, 'last_name' => $last, 'birth_date' => $birth]);
        $this->assertSame(201, $res->status, (string) json_encode($res->data));
        return $res->data['id'];
    }

    public function testSettingsStartEmptyAndAreValidated(): void
    {
        $settings = $this->call('GET', '/passive-members/settings')->data;
        $this->assertSame([], $settings['birthday_ages'], 'anfangs keine Alter = keine Erinnerung');
        $this->assertSame(7, $settings['birthday_days']);

        foreach ([['birthday_ages' => '50'], ['birthday_ages' => [0]], ['birthday_ages' => [121]], ['birthday_ages' => ['fünfzig']], ['birthday_ages' => range(1, 31)], ['birthday_days' => -1], ['birthday_days' => 31], ['birthday_days' => 'x']] as $body) {
            $this->assertSame(400, $this->call('PATCH', '/passive-members/settings', $body)->status, (string) json_encode($body));
        }
        $this->assertSame([], $this->call('GET', '/passive-members/settings')->data['birthday_ages'], 'nichts gespeichert');

        $res = $this->call('PATCH', '/passive-members/settings', ['birthday_ages' => [80, '50', 60, 50, ''], 'birthday_days' => '3']);
        $this->assertSame(200, $res->status);
        $this->assertSame([50, 60, 80], $res->data['birthday_ages'], 'aufsteigend, doppelte einmal');
        $this->assertSame(3, $res->data['birthday_days']);
        $this->assertSame(['Bar', 'SEPA', 'PayPal'], $res->data['methods'], 'anderes bleibt');

        $this->assertSame([], $this->call('PATCH', '/passive-members/settings', ['birthday_ages' => []])->data['birthday_ages'], 'leeren schaltet aus');
    }

    public function testUpcomingOnlyForChosenAgesAndWithViewPersonal(): void
    {
        // Heute 16.11.2026
        $a = $this->passive('Anna', 'Achtzig', '1946-11-20'); // wird in 4 Tagen 80
        $this->passive('Bernd', 'Neunundsiebzig', '1947-11-18'); // wird 79: kein gewähltes Alter
        $c = $this->passive('Clara', 'Fünfzig', '1976-11-16'); // heute 50
        $this->passive('Dieter', 'Später', '1956-11-24'); // wird 70, aber erst in 8 Tagen
        $this->passive('Ernst', 'Ohne', null);

        $this->assertSame([], $this->call('GET', '/passive-members')->data['birthdays'], 'ohne Alter kein Hinweis');

        $this->call('PATCH', '/passive-members/settings', ['birthday_ages' => [50, 70, 80]]);
        $list = $this->call('GET', '/passive-members')->data['birthdays'];
        $this->assertSame([
            ['id' => $c, 'name' => 'Clara Fünfzig', 'date' => '2026-11-16', 'age' => 50, 'days' => 0],
            ['id' => $a, 'name' => 'Anna Achtzig', 'date' => '2026-11-20', 'age' => 80, 'days' => 4],
        ], $list);

        $this->call('PATCH', '/passive-members/settings', ['birthday_days' => 8]);
        $this->assertSame(['Clara Fünfzig', 'Anna Achtzig', 'Dieter Später'], array_column($this->call('GET', '/passive-members')->data['birthdays'], 'name'));

        // Ohne view_personal: kein Hinweis (Geburtsdaten sind persönliche Angaben)
        $this->users->current = $this->withRights('kassierer', ['view_passive', 'manage_passive']);
        $this->assertSame([], $this->call('GET', '/passive-members')->data['birthdays']);
        $this->assertSame(200, $this->call('PATCH', '/passive-members/settings', ['birthday_days' => 7])->status, 'Einstellung mit manage_passive');
    }

    public function testLeapDayAndTurnOfYear(): void
    {
        $this->at('2027-02-25 12:00:00');
        $id = $this->passive('Lea', 'Schalttag', '1960-02-29');
        $this->call('PATCH', '/passive-members/settings', ['birthday_ages' => [67]]);
        $this->assertSame([['id' => $id, 'name' => 'Lea Schalttag', 'date' => '2027-02-28', 'age' => 67, 'days' => 3]], $this->call('GET', '/passive-members')->data['birthdays']);

        $this->at('2026-12-28 12:00:00');
        $id2 = $this->passive('Jan', 'Neujahr', '1937-01-02');
        $this->call('PATCH', '/passive-members/settings', ['birthday_ages' => [90]]);
        $this->assertSame([['id' => $id2, 'name' => 'Jan Neujahr', 'date' => '2027-01-02', 'age' => 90, 'days' => 5]], $this->call('GET', '/passive-members')->data['birthdays']);
    }

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

    private function subscribe(int $user, string $device): void
    {
        $d = self::device($device);
        $this->app->push()->subscribe($this->users->find($user), $d['endpoint'], $d['keys'], null, null, '1.2.3.4');
    }

    /** @return list<array{device: string, title: string, body: string, route: string}> */
    private function queued(): array
    {
        $out = [];
        foreach ($this->app->db->select('SELECT s.endpoint, q.payload FROM {push_queue} q JOIN {push_subscriptions} s ON s.id = q.subscription_id ORDER BY q.id') as $row) {
            $msg = json_decode((string) $row['payload'], true);
            $out[] = ['device' => substr((string) $row['endpoint'], strlen('https://fcm.googleapis.com/fcm/send/')), 'title' => $msg['title'], 'body' => $msg['body'], 'route' => $msg['route']];
        }
        return $out;
    }

    public function testPushToManagersWithViewPersonalOnceAndFromTheHour(): void
    {
        if (!$this->app->push()->supported()) {
            $this->markTestSkipped('ohne Push-Unterstützung');
        }
        $manager = $this->withRights('verwalter', ['view_passive', 'manage_passive', 'view_personal']);
        $cashier = $this->withRights('kassierer', ['view_passive', 'manage_passive']);
        $reader = $this->withRights('leser', ['view_passive', 'view_personal']);
        $this->subscribe($manager, 'verwalter');
        $this->subscribe($cashier, 'kassierer');
        $this->subscribe($reader, 'leser');
        $this->subscribe($this->member, 'mitglied');
        $this->subscribe($this->admin, 'admin');
        $this->passive('Anna', 'Achtzig', '1946-11-23'); // in 7 Tagen 80
        $this->passive('Bernd', 'Morgen', '1956-11-17'); // morgen 70

        // Ohne Alter: Aufgabe nicht eingeplant, Art nicht angeboten, nichts gesendet
        $this->assertNotContains(PushService::TYPE_PASSIVE_BIRTHDAYS, $this->app->push()->availableTypes($this->users->find($manager)));
        $this->assertSame(0, $this->app->passives()->remindBirthdays());

        $this->call('PATCH', '/passive-members/settings', ['birthday_ages' => [70, 80]]);
        $this->assertContains(PushService::TYPE_PASSIVE_BIRTHDAYS, $this->app->push()->availableTypes($this->users->find($manager)));
        $this->assertNotContains(PushService::TYPE_PASSIVE_BIRTHDAYS, $this->app->push()->availableTypes($this->users->find($this->member)));
        $types = array_column($this->app->push()->repository()->all(), 'types', 'endpoint');
        $this->assertContains(PushService::TYPE_PASSIVE_BIRTHDAYS, $types['https://fcm.googleapis.com/fcm/send/verwalter'], 'bestehende Abos bekommen die Art beim Einrichten');

        // Vor 7 Uhr nichts
        $this->at('2026-11-16 06:30:00');
        $this->app->runTask(PassiveMemberService::TASK);
        $this->assertSame([], $this->queued());

        $this->at('2026-11-16 07:10:00');
        $this->app->runTask(PassiveMemberService::TASK);
        $q = $this->queued();
        $this->assertSame(['verwalter', 'admin', 'verwalter', 'admin'], array_column($q, 'device'), 'nur Verwalter (manage_passive) mit view_personal');
        $this->assertSame(['Geburtstag eines Passiven', 'Bernd Morgen wird morgen 70 Jahre alt.', 'verwaltung/passive'], [$q[0]['title'], $q[0]['body'], $q[0]['route']]);
        $this->assertSame('Anna Achtzig wird am 23.11. 80 Jahre alt.', $q[2]['body']);

        // Nur einmal je Geburtstag, auch an den folgenden Tagen
        $this->app->runTask(PassiveMemberService::TASK);
        $this->at('2026-11-20 09:00:00');
        $this->app->runTask(PassiveMemberService::TASK);
        $this->assertCount(4, $this->queued());

        // Ausschalten: nichts mehr
        $this->app->db->execute('DELETE FROM {push_queue}');
        $this->app->db->execute("UPDATE {passive_members} SET birthday_reminded = ''");
        $this->call('PATCH', '/passive-members/settings', ['birthday_ages' => []]);
        $this->assertSame(0, $this->app->passives()->remindBirthdays());
        $this->assertSame([], $this->queued());
    }
}
