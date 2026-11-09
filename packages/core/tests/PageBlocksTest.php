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

/**
 * Eigene Seiten aus Blöcken: Text und Countdown, „Für wen?“ je Block (auch für Bilder), Prüfung, Zeitzone, Termin als
 * Ziel, ältere Apps (nur body) und ältere Versionen (body ohne blocks).
 */
final class PageBlocksTest extends TestCase
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

    public function testBlocksAudienceAndOlderClients(): void
    {
        $page = $this->ok('POST', '/pages', ['title' => 'Fest', 'audience' => 'public', 'blocks' => [
            ['type' => 'text', 'html' => '<p>Willkommen</p><script>x()</script>'],
            ['type' => 'countdown', 'title' => 'Bis zum Fest', 'date' => '2027-06-12', 'time' => '14:00', 'after_text' => 'Es hat begonnen'],
            ['type' => 'text', 'audience' => 'members', 'html' => '<p>Treffpunkt intern</p>'],
            ['id' => 'b1', 'type' => 'text', 'html' => ''],
        ]]);
        $this->assertSame(['text', 'countdown', 'text', 'text'], self::types($page), 'wer pflegt, sieht alle Blöcke (auch leere)');
        $this->assertSame('<p>Willkommen</p>', $page['blocks'][0]['html']);
        $this->assertSame(4, count(array_unique(array_column($page['blocks'], 'id'))), 'Kennungen eindeutig');
        $this->assertSame('<p>Willkommen</p>', $page['body'], 'body: nur Text für alle');
        $this->assertSame('2027-06-12T12:00:00Z', $page['blocks'][1]['starts_at'], 'Sommerzeit: 14:00 Uhr = 12:00 UTC');
        $this->assertNull($page['blocks'][1]['ends_at']);
        $this->assertSame('Europe/Berlin', $page['blocks'][1]['timezone'], 'Zeitzone der Installation für die Kalenderzählung');

        $id = $page['id'];
        $this->assertSame(['text', 'countdown'], self::types($this->ok('GET', "/pages/$id", [], null)), 'ohne Anmeldung ohne Mitglieder-Block');
        $this->assertSame(['text', 'countdown'], self::types($this->ok('GET', "/pages/$id", [], $this->guest)));
        $this->assertSame(['text', 'countdown', 'text'], self::types($this->ok('GET', "/pages/$id", [], $this->member)), 'leerer Text fällt weg');

        // Ältere App schickt nur body: ein Textblock
        $loaded = $this->ok('GET', "/pages/$id")['updated_at'];
        $old = $this->ok('PATCH', "/pages/$id", ['body' => '<p>Neu</p>', 'updated_at' => $loaded]);
        $this->assertSame(['text'], self::types($old));
        $this->assertSame('all', $old['blocks'][0]['audience']);

        // Ältere Version schreibt body direkt: Blöcke sind veraltet, es gilt body
        $this->ok('PATCH', "/pages/$id", ['blocks' => [['type' => 'countdown', 'date' => '2027-06-12'], ['type' => 'text', 'html' => '<p>A</p>']]]);
        $this->app->db->update('app_pages', ['body' => '<p>Von der alten Version</p>'], ['id' => $id]);
        $page = $this->ok('GET', "/pages/$id", [], null);
        $this->assertSame(['text'], self::types($page));
        $this->assertSame('<p>Von der alten Version</p>', $page['blocks'][0]['html']);

        // Bestehende Seite ohne blocks (vor der Migration): ein Textblock aus body
        $this->app->db->update('app_pages', ['blocks' => null, 'body' => '<p>Alt</p>'], ['id' => $id]);
        $this->assertSame([['id' => 'b1', 'type' => 'text', 'audience' => 'all', 'html' => '<p>Alt</p>']], $this->ok('GET', "/pages/$id", [], null)['blocks']);
    }

    public function testValidation(): void
    {
        $bad = [
            'kein Array' => 'x',
            'unbekannter Typ' => [['type' => 'video']],
            'für wen' => [['type' => 'text', 'audience' => 'public', 'html' => '<p>x</p>']],
            'ohne Datum' => [['type' => 'countdown']],
            'Datum falsch' => [['type' => 'countdown', 'date' => '2027-02-30']],
            'Uhrzeit falsch' => [['type' => 'countdown', 'date' => '2027-06-12', 'time' => '25:00']],
            'Ende vor Beginn' => [['type' => 'countdown', 'date' => '2027-06-12', 'time' => '14:00', 'end_time' => '13:00']],
            'Quelle' => [['type' => 'countdown', 'source' => 'kalender', 'date' => '2027-06-12']],
            'Termin fehlt' => [['type' => 'countdown', 'source' => 'event', 'event_id' => 999]],
            'danach' => [['type' => 'countdown', 'date' => '2027-06-12', 'after' => 'weg']],
            'Titel zu lang' => [['type' => 'countdown', 'date' => '2027-06-12', 'title' => str_repeat('x', 101)]],
            'zu viele' => array_fill(0, Blocks::MAX + 1, ['type' => 'text', 'html' => '<p>x</p>']),
        ];
        foreach ($bad as $why => $blocks) {
            $this->assertSame(400, $this->call('POST', '/pages', ['title' => 'X', 'blocks' => $blocks])->status, $why);
        }
        $page = $this->ok('POST', '/pages', ['title' => 'X', 'blocks' => [['type' => 'countdown', 'date' => '2027-06-12', 'end_date' => '2027-06-14', 'unbekannt' => 1]]]);
        $this->assertArrayNotHasKey('unbekannt', $page['blocks'][0], 'nur bekannte Felder');
        $this->assertSame('2027-06-14T22:00:00Z', $page['blocks'][0]['ends_at'], 'nur Enddatum: bis Ende des Tages');
        $this->assertSame(403, $this->call('PATCH', "/pages/{$page['id']}", ['blocks' => []], $this->member)->status);
    }

    public function testCountdownTimeZoneAndPhases(): void
    {
        $page = $this->ok('POST', '/pages', ['title' => 'Zeit', 'audience' => 'public', 'blocks' => [
            ['type' => 'countdown', 'date' => '2027-03-27', 'time' => '03:00'],
            ['type' => 'countdown', 'date' => '2027-03-28', 'time' => '03:00', 'end_date' => '2027-03-29', 'end_time' => '18:00', 'running_text' => 'Läuft', 'after' => 'hide'],
            ['type' => 'countdown', 'date' => '2027-03-02', 'after' => 'hide'],
        ]]);
        $this->assertSame(['2027-03-27T02:00:00Z', '2027-03-28T01:00:00Z', '2027-03-01T23:00:00Z'], array_column($page['blocks'], 'starts_at'), 'Winterzeit, Sommerzeit, ohne Uhrzeit 0:00');
        $id = $page['id'];

        $blocks = $this->app->blocks();
        $stored = Blocks::decode($this->app->db->scalar('SELECT blocks FROM {app_pages} WHERE id = ?', [$id]), '');
        $this->assertSame('Countdown bis 28.03.2027, 03:00 Uhr', $blocks->plain([$stored[1]], false));
        $this->assertSame('Countdown bis 02.03.2027', $blocks->plain([$stored[2]], false));

        // Läuft: nach Beginn, vor dem Ende
        $this->clock->at = new \DateTimeImmutable('2027-03-28 12:00:00', new \DateTimeZone('Europe/Berlin'));
        $this->assertSame('Läuft', $blocks->plain([$stored[1]], false));
        $this->assertSame([true, true], array_map(static fn (array $b): bool => $b['starts_at'] !== null, $this->ok('GET', "/pages/$id", [], null)['blocks']), 'abgelaufen und ausblenden: weg; der erste ohne Ende zeigt den Text danach, der zweite läuft');
        // Danach: ausgeblendet, für die Pflege weiter da
        $this->clock->at = new \DateTimeImmutable('2027-03-30 12:00:00', new \DateTimeZone('Europe/Berlin'));
        $this->assertSame(1, count($this->ok('GET', "/pages/$id", [], $this->member)['blocks']));
        $this->assertSame(3, count($this->ok('GET', "/pages/$id")['blocks']));
    }

    public function testCountdownForEvent(): void
    {
        $event = $this->ok('POST', '/events', ['title' => 'Sommerfest', 'start_date' => '2027-06-12', 'start_time' => '14:00', 'end_date' => '2027-06-13', 'end_time' => '02:00']);
        $page = $this->ok('POST', '/pages', ['title' => 'Fest', 'audience' => 'public', 'blocks' => [
            ['type' => 'countdown', 'source' => 'event', 'event_id' => $event['id'], 'date' => '2000-01-01'],
        ]]);
        $b = $page['blocks'][0];
        $this->assertSame(['event', $event['id'], 'Sommerfest', '2027-06-12T12:00:00Z', '2027-06-13T00:00:00Z'], [$b['source'], $b['event_id'], $b['event_title'], $b['starts_at'], $b['ends_at']]);
        $this->assertArrayNotHasKey('date', $b, 'bei einem Termin kein eigenes Datum');
        $this->assertSame('Sommerfest: 12.06.2027, 14:00 Uhr', $this->app->blocks()->plain(Blocks::decode($this->app->db->scalar('SELECT blocks FROM {app_pages}'), ''), false));

        // Verschoben: zählt mit
        $this->ok('PATCH', "/events/{$event['id']}", ['start_date' => '2027-06-19', 'end_date' => '2027-06-20']);
        $this->assertSame('2027-06-19T12:00:00Z', $this->ok('GET', "/pages/{$page['id']}", [], null)['blocks'][0]['starts_at']);

        // Bereich Termine aus: wie nach dem Ende
        $this->app->features()->update(['events' => false]);
        $b = $this->ok('GET', "/pages/{$page['id']}", [], null)['blocks'][0];
        $this->assertSame([null, null, null], [$b['starts_at'], $b['ends_at'], $b['event_title']]);
        $this->app->features()->update(['events' => true]);

        // Gelöscht: wie nach dem Ende
        $this->ok('DELETE', "/events/{$event['id']}");
        $this->assertNull($this->ok('GET', "/pages/{$page['id']}", [], null)['blocks'][0]['starts_at']);
    }

    public function testImagesFollowTheBlock(): void
    {
        $png = imagecreatetruecolor(4, 3);
        ob_start();
        imagepng($png);
        $bytes = (string) ob_get_clean();
        $upload = fn (): string => $this->call('POST', '/rich-images', ['file' => ['name' => 'bild.png', 'data' => base64_encode($bytes)]])->data['uid'];
        $pub = $upload();
        $intern = $upload();
        $d = fn (string $uid, ?int $user): int => $this->app->richImages()->forDelivery($user === null ? null : $this->users->find($user), $uid)['status'];

        $page = $this->ok('POST', '/pages', ['title' => 'Bilder', 'audience' => 'public', 'blocks' => [
            ['type' => 'text', 'html' => '<img data-image="' . $pub . '">'],
            ['type' => 'text', 'audience' => 'members', 'html' => '<img data-image="' . $intern . '">'],
        ]]);
        $this->assertSame(200, $d($pub, null));
        $this->assertSame(401, $d($intern, null), 'Bild im Mitglieder-Block: ohne Anmeldung nicht');
        $this->assertSame(403, $d($intern, $this->guest));
        $this->assertSame(200, $d($intern, $this->member));
        $this->assertStringContainsString('cache-control', strtolower(implode(',', array_keys($this->app->richImages()->forDelivery($this->users->find($this->member), $intern)['headers']))));
        $this->assertStringStartsWith('private', $this->app->richImages()->forDelivery($this->users->find($this->member), $intern)['headers']['Cache-Control']);

        // Bild in einen anderen Block verschoben: bleibt; aus allen Blöcken entfernt: gelöscht
        $this->ok('PATCH', "/pages/{$page['id']}", ['blocks' => [
            ['type' => 'text', 'html' => '<p>x</p>'],
            ['type' => 'text', 'html' => '<img data-image="' . $pub . '"><img data-image="' . $intern . '">'],
        ]]);
        $this->assertSame(200, $d($intern, null), 'jetzt im Block für alle');
        $this->ok('PATCH', "/pages/{$page['id']}", ['blocks' => [['type' => 'text', 'html' => '<img data-image="' . $pub . '">']]]);
        $this->assertNull($this->app->richImages()->repository()->find($intern));
        $this->assertNotNull($this->app->richImages()->repository()->find($pub));
    }
}
