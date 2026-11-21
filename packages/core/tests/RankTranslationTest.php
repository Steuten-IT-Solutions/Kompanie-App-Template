<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\I18n\I18n;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Push\WebPushCrypto;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * Eigene Namen der Dienstgrade je weiterer Sprache („Einrichten je Sprache“): Anzeige in der Sprache der Anfrage bzw.
 * der Person, gespeichert bleibt die deutsche Bezeichnung, Löschen nimmt die Übersetzungen mit.
 */
final class RankTranslationTest extends TestCase
{
    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $m;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $all = ['events' => false, 'calendar' => false, 'news' => false, 'gallery' => false, 'penalties' => false];
        $this->app = TestApp::create(TestApp::connection(), $this->users, [], null, null, null, new FixedClock(new \DateTimeImmutable('2026-10-20 12:00:00')), ['ranks' => true, 'points' => true] + $all);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->m = $this->users->add('mitglied', Roles::MEMBER);
        $this->users->current = $this->admin;
    }

    protected function tearDown(): void
    {
        I18n::setLocale(I18n::SOURCE_LOCALE);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $query
     */
    private function call(string $method, string $path, array $body = [], ?string $locale = null, array $query = []): Response
    {
        return $this->app->api()->handle(new Request($method, $path, $query, $body, [], '', null, $locale));
    }

    /** @return array{0: int, 1: int} IDs von Gefreiter (ohne Schwelle) und Unteroffizier (ab 50 Punkten) */
    private function setUpRanks(): array
    {
        $this->assertSame(200, $this->call('PATCH', '/admin/settings', ['languages' => 'de,en'])->status);
        $low = $this->call('POST', '/ranks', ['title' => 'Gefreiter', 'min_points' => null]);
        $high = $this->call('POST', '/ranks', ['title' => 'Unteroffizier', 'min_points' => 50]);
        $this->assertSame([201, 201], [$low->status, $high->status]);
        $res = $this->call('PUT', '/admin/translations', ['locale' => 'en', 'items' => [
            ['kind' => 'rank', 'ref' => (string) $low->data['id'], 'text' => 'Lance Corporal'],
            ['kind' => 'rank', 'ref' => (string) $high->data['id'], 'text' => 'Sergeant'],
        ]]);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        return [(int) $low->data['id'], (int) $high->data['id']];
    }

    public function testRankNamesInTheLanguageOfTheRequest(): void
    {
        [$low, $high] = $this->setUpRanks();
        $this->assertSame(200, $this->call('PUT', "/members/{$this->m}/rank", ['rank_id' => $low, 'reason' => ''])->status);

        // Gespeichert bleibt die deutsche Bezeichnung, auch in der Liste der Dienstgrade und im Verlauf.
        $this->assertSame(['Gefreiter', 'Unteroffizier'], array_column($this->call('GET', '/ranks', [], 'en')->data, 'title'));
        $member = $this->call('GET', "/members/{$this->m}", [], 'en')->data;
        $this->assertSame(['Gefreiter', $low], [$member['rank_title'], $member['rank_id']], 'mit ID für den Namen in der App');
        $this->assertSame(['Gefreiter', null, $low], [$member['rank_history'][0]['to'], $member['rank_history'][0]['from_rank_id'], $member['rank_history'][0]['to_rank_id']]);
        $list = array_column($this->call('GET', '/members', [], 'en')->data, null, 'id');
        $this->assertSame($low, $list[$this->m]['rank_id']);

        // Meldungen mit einer Bezeichnung: in der Sprache der Anfrage
        $res = $this->call('PUT', "/members/{$this->m}/rank", ['rank_id' => $high, 'reason' => ''], 'en');
        $this->assertSame(400, $res->status);
        $this->assertStringContainsString('“Sergeant”', (string) json_encode($res->data, JSON_UNESCAPED_UNICODE));
        $res = $this->call('PUT', "/members/{$this->m}/rank", ['rank_id' => $high, 'reason' => '']);
        $this->assertStringContainsString('„Unteroffizier“', (string) json_encode($res->data, JSON_UNESCAPED_UNICODE), 'auf Deutsch die deutsche');

        // Listen und Berichte rechnet der Server in der Sprache der Anfrage
        $this->assertSame([$this->m => 'Lance Corporal'], I18n::in('en', fn (): array => $this->app->ranks()->titles()));
        $this->assertSame([$this->m => 'Gefreiter'], $this->app->ranks()->titles());
    }

    public function testPushInTheLanguageOfTheMember(): void
    {
        if (!$this->app->push()->supported()) {
            $this->markTestSkipped('ohne Push-Unterstützung');
        }
        [$low] = $this->setUpRanks();
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->assertNotFalse($key);
        $keys = ['p256dh' => WebPushCrypto::b64uEncode(WebPushCrypto::rawPublic($key)), 'auth' => WebPushCrypto::b64uEncode(random_bytes(16))];
        $this->app->push()->subscribe($this->users->find($this->m), 'https://fcm.googleapis.com/fcm/send/m', $keys, ['ranks'], null, '1.2.3.4');
        $member = $this->users->find($this->m);
        $this->assertNotNull($member);
        $this->app->languages()->saveForUser($member, 'en');

        $this->assertSame(200, $this->call('PUT', "/members/{$this->m}/rank", ['rank_id' => $low, 'reason' => ''])->status);
        $payload = json_decode((string) $this->app->db->scalar('SELECT payload FROM {push_queue} ORDER BY id DESC LIMIT 1'), true);
        $this->assertIsArray($payload);
        $this->assertStringContainsString('Lance Corporal', (string) $payload['body']);
        $history = $this->app->db->select('SELECT to_title FROM {rank_history} WHERE user_id = ?', [$this->m]);
        $this->assertSame('Gefreiter', $history[0]['to_title'], 'Verlauf speichert die deutsche Bezeichnung');
    }

    public function testDeletingARankDeletesItsTranslations(): void
    {
        [$low, $high] = $this->setUpRanks();
        $this->assertSame(200, $this->call('DELETE', "/ranks/$low")->status);
        $this->assertSame([(string) $high => ['label' => 'Sergeant']], $this->app->translations()->repository()->all()['en']['rank']);
    }
}
