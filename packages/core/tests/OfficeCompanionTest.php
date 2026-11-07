<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * Begleitpersonen einer Amtszeit (z. B. Partnerin oder Partner): Konten oder freie Namen wie die Inhaber, gespeichert
 * beim Inhaber, den sie begleiten. Sie bekleiden das Amt nicht: kein verknüpfter Titel, kein Ende beim Ausscheiden.
 */
final class OfficeCompanionTest extends TestCase
{
    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $holder;
    private int $partner;
    private int $office;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $features = ['events' => false, 'calendar' => false, 'news' => false, 'gallery' => false, 'points' => false, 'penalties' => false, 'ranks' => false, 'directory' => true, 'offices' => true, 'titles' => true];
        $this->app = TestApp::create(TestApp::connection(), $this->users, [], null, null, null, new FixedClock(new \DateTimeImmutable('2026-10-20 12:00:00')), $features);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->holder = $this->users->add('Inhaber', Roles::MEMBER);
        $this->partner = $this->users->add('Partnerin', Roles::MEMBER);
        $this->users->current = $this->admin;
        $this->office = (int) $this->ok('POST', '/offices', ['title' => 'Vorsitz'])['id'];
    }

    private function call(string $method, string $path, array $body = []): Response
    {
        return $this->app->api()->handle(new Request($method, $path, [], $body));
    }

    /** @return array<string, mixed> */
    private function ok(string $method, string $path, array $body = []): array
    {
        $res = $this->call($method, $path, $body);
        $this->assertLessThan(300, $res->status, $method . ' ' . $path . ': ' . json_encode($res->data));
        return (array) $res->data;
    }

    /** @return list<array<string, mixed>> Amtszeiten des Amts im Zeitstrahl */
    private function terms(): array
    {
        foreach ($this->ok('GET', '/offices')['offices'] as $o) {
            if ($o['id'] === $this->office) {
                return $o['terms'];
            }
        }
        return [];
    }

    public function testCompanionsAreSavedWithTheirHolderAndShown(): void
    {
        $term = $this->ok('POST', "/offices/{$this->office}/terms", ['start_year' => 2026, 'holders' => [
            ['user_id' => $this->holder, 'companions' => [['name' => 'Erika Muster']]],
            ['name' => 'Zweiter Inhaber', 'companions' => [['user_id' => $this->partner]]],
            ['name' => 'Ohne Begleitung'],
        ]]);
        $this->assertSame([['name' => 'Erika Muster', 'user_id' => null]], $term['holders'][0]['companions']);
        $this->assertSame([['name' => 'Partnerin', 'user_id' => $this->partner]], $term['holders'][1]['companions']);
        $this->assertArrayNotHasKey('companions', $term['holders'][2]);

        $holders = $this->terms()[0]['holders'];
        $this->assertSame(['Inhaber', 'Zweiter Inhaber', 'Ohne Begleitung'], array_column($holders, 'name'), 'Begleitpersonen zählen nicht als Inhaber');
        $this->assertSame('Erika Muster', $holders[0]['companions'][0]['name']);
        $this->assertSame($this->partner, $holders[1]['companions'][0]['user_id']);

        // Ändern ohne holders behält die Begleitpersonen
        $this->ok('PATCH', "/office-terms/{$term['id']}", ['note' => 'Zusatz']);
        $this->assertSame('Erika Muster', $this->terms()[0]['holders'][0]['companions'][0]['name']);
        // mit holders ohne companions: weg
        $this->ok('PATCH', "/office-terms/{$term['id']}", ['holders' => [['user_id' => $this->holder]]]);
        $this->assertArrayNotHasKey('companions', $this->terms()[0]['holders'][0]);
    }

    public function testPhotoOfFreeCompanionIsKeptAndCleanedUp(): void
    {
        $img = imagecreatetruecolor(6, 4);
        ob_start();
        imagepng($img);
        $uid = (string) $this->ok('POST', '/rich-images', ['file' => ['name' => 'foto.png', 'data' => base64_encode((string) ob_get_clean())]])['uid'];
        $term = (int) $this->ok('POST', "/offices/{$this->office}/terms", ['start_year' => 2026, 'holders' => [
            ['user_id' => $this->holder, 'companions' => [['name' => 'Erika Muster', 'image_uid' => $uid]]],
        ]])['id'];
        $pool = fn (): array => array_column($this->app->db->select("SELECT uid FROM {rich_images} WHERE owner_type = 'office_holder'"), 'uid');
        $this->assertSame([$uid], $pool());
        $this->assertSame('https://example.org/app/bild/' . $uid, $this->terms()[0]['holders'][0]['companions'][0]['photo_url']);
        $this->ok('PATCH', "/office-terms/{$term}", ['holders' => [['user_id' => $this->holder]]]);
        $this->assertSame([], $pool(), 'nicht mehr gebraucht: gelöscht');
    }

    public function testInvalidCompanions(): void
    {
        $path = "/offices/{$this->office}/terms";
        $this->assertSame(400, $this->call('POST', $path, ['start_year' => 2026, 'holders' => [['user_id' => $this->holder, 'companions' => [['user_id' => $this->holder]]]]])->status, 'Inhaber begleitet sich nicht selbst');
        $this->assertSame(400, $this->call('POST', $path, ['start_year' => 2026, 'holders' => [['name' => 'A', 'companions' => [['name' => 'B']]], ['name' => 'B']]])->status, 'niemand doppelt in der Amtszeit');
        $this->assertSame(400, $this->call('POST', $path, ['start_year' => 2026, 'holders' => [['name' => 'A', 'companions' => [['name' => '']]]]])->status, 'Name fehlt');
        $this->assertSame(400, $this->call('POST', $path, ['start_year' => 2026, 'holders' => [['name' => 'A', 'companions' => 'B']]])->status);
        $many = array_map(static fn (int $i): array => ['name' => 'P' . $i], range(1, 6));
        $this->assertSame(400, $this->call('POST', $path, ['start_year' => 2026, 'holders' => [['name' => 'A', 'companions' => $many]]])->status, 'höchstens 5');
        $this->assertSame(400, $this->call('POST', $path, ['start_year' => 2026, 'holders' => [['name' => 'A', 'companions' => [['user_id' => 9999]]]]])->status, 'unbekanntes Konto');
    }

    public function testCompanionGetsNoLinkedTitleAndStaysWhenLeaving(): void
    {
        $title = (int) $this->ok('POST', '/titles', ['title' => 'Vorsitz'])['id'];
        $this->ok('PATCH', "/offices/{$this->office}", ['title_id' => $title]);
        $other = $this->users->add('Mitinhaber', Roles::MEMBER);
        $this->ok('POST', "/offices/{$this->office}/terms", ['start_year' => 2020, 'holders' => [
            ['user_id' => $this->holder, 'companions' => [['user_id' => $this->partner]]],
            ['user_id' => $other],
        ]]);
        $assigned = $this->app->titles()->repository()->assignments();
        $this->assertContains($title, $assigned[$this->holder] ?? []);
        $this->assertNotContains($title, $assigned[$this->partner] ?? [], 'Begleitperson bekleidet das Amt nicht');

        // Ausscheiden der Begleitperson beendet keine Amtszeit
        $this->ok('PUT', "/members/{$this->partner}/former", ['reason' => 'left', 'left_on' => '2025-12-31']);
        $this->assertCount(1, $this->terms());
        $this->assertTrue($this->terms()[0]['current']);

        // Ausscheiden des Inhabers: eigene, beendete Amtszeit, die Begleitperson geht mit
        $this->ok('PUT', "/members/{$this->holder}/former", ['reason' => 'left', 'left_on' => '2025-12-31']);
        $terms = $this->terms();
        $this->assertCount(2, $terms);
        [$running, $ended] = $terms;
        $this->assertSame(['Mitinhaber'], array_column($running['holders'], 'name'));
        $this->assertArrayNotHasKey('companions', $running['holders'][0]);
        $this->assertSame(2025, $ended['end_year']);
        $this->assertSame('Partnerin', $ended['holders'][0]['companions'][0]['name']);
    }

    public function testJointTermKeepsCompanionsAndFreeNamesCanBeLinked(): void
    {
        $group = (int) $this->ok('POST', '/office-groups', ['title' => 'Hofstaat'])['id'];
        $this->ok('PATCH', '/office-groups/' . $group, ['joint_terms' => true]);
        $this->ok('PATCH', "/offices/{$this->office}", ['group_id' => $group]);
        $joint = $this->ok('POST', "/office-groups/{$group}/terms", ['start_year' => 2026, 'offices' => [
            ['office_id' => $this->office, 'holders' => [['name' => 'Max Muster', 'companions' => [['name' => 'Partnerin']]]]],
        ]]);
        $this->assertSame('Partnerin', $joint['terms'][0]['holders'][0]['companions'][0]['name']);
        // Jahre ändern ohne offices: Begleitpersonen bleiben
        $this->ok('PATCH', '/office-group-terms/' . $joint['id'], ['start_year' => 2025]);
        $this->assertSame('Partnerin', $this->terms()[0]['holders'][0]['companions'][0]['name']);

        // Freier Name einer Begleitperson wird wie bei Inhabern mit dem Konto verknüpft
        $this->assertSame(1, $this->app->offices()->linkNames(['partnerin' => $this->partner], true));
        $this->assertSame($this->partner, $this->terms()[0]['holders'][0]['companions'][0]['user_id']);
    }
}
