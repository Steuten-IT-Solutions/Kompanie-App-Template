<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Push\WebPushCrypto;
use KompanieApp\Core\Storage\Backup;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** Titel (Issue #22): Liste, mehrere je Mitglied, Löschschutz, Rechte, Anzeige, Benachrichtigung. */
final class TitleServiceTest extends TestCase
{
    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $m;

    /** @param array<string, bool> $features */
    private function setUpApp(array $features = ['titles' => true]): void
    {
        $this->users = new FakeUsers();
        $all = ['events' => false, 'calendar' => false, 'news' => false, 'gallery' => false, 'points' => false, 'penalties' => false, 'ranks' => false, 'titles' => false];
        $this->app = TestApp::create(TestApp::connection(), $this->users, [], null, null, null, new FixedClock(new \DateTimeImmutable('2026-10-20 12:00:00')), $features + $all);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->m = $this->users->add('mitglied', Roles::MEMBER);
        $this->users->current = $this->admin;
    }

    private function call(string $method, string $path, array $body = [], array $query = []): Response
    {
        return $this->app->api()->handle(new Request($method, $path, $query, $body));
    }

    /** @return array<string, int> Bezeichnung => ID */
    private function titles(string ...$titles): array
    {
        $ids = [];
        foreach ($titles as $title) {
            $res = $this->call('POST', '/titles', ['title' => $title]);
            $this->assertSame(201, $res->status, (string) json_encode($res->data));
            $ids[$title] = $res->data['id'];
        }
        return $ids;
    }

    public function testListAndValidation(): void
    {
        $this->setUpApp();
        $this->assertSame([], $this->call('GET', '/titles')->data, 'neue Installation: leere Liste');
        $t = $this->titles('Ehrenmitglied', 'Vorsitz');
        $this->assertSame(['Ehrenmitglied', 'Vorsitz'], array_column($this->call('GET', '/titles')->data, 'title'));
        $this->assertSame(400, $this->call('POST', '/titles', ['title' => ' '])->status, 'leer');
        $this->assertSame(400, $this->call('POST', '/titles', ['title' => 'ehrenmitglied'])->status, 'doppelt, ohne Groß-/Kleinschreibung');
        $this->assertSame(400, $this->call('POST', '/titles', ['title' => str_repeat('x', 61)])->status, 'zu lang');
        $this->assertSame(400, $this->call('PATCH', "/titles/{$t['Vorsitz']}", ['title' => 'Ehrenmitglied'])->status);
        $this->assertSame(404, $this->call('PATCH', '/titles/999', ['title' => 'X'])->status);
        $this->assertSame(400, $this->call('PATCH', "/titles/{$t['Vorsitz']}", ['active' => 'ja'])->status);

        $res = $this->call('PUT', '/titles/order', ['ids' => [$t['Vorsitz'], $t['Ehrenmitglied']]]);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertSame(['Vorsitz', 'Ehrenmitglied'], array_column($res->data, 'title'));
        $this->assertSame(400, $this->call('PUT', '/titles/order', ['ids' => [$t['Vorsitz']]])->status, 'unvollständig');
    }

    public function testSeveralTitlesPerMemberAndDeleteProtection(): void
    {
        $this->setUpApp();
        $t = $this->titles('Ehrenmitglied', 'Vorsitz', 'Kassenprüfer');
        $res = $this->call('PUT', "/members/{$this->m}/titles", ['title_ids' => [$t['Kassenprüfer'], $t['Ehrenmitglied'], $t['Ehrenmitglied']]]);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertSame(['Ehrenmitglied', 'Kassenprüfer'], array_column($res->data['titles'], 'title'), 'Reihenfolge der Liste, ohne Doppelte');
        $this->assertSame(400, $this->call('PUT', "/members/{$this->m}/titles", ['title_ids' => [999]])->status);
        $this->assertSame(400, $this->call('PUT', "/members/{$this->m}/titles", ['title_ids' => 'x'])->status);
        $this->assertSame(200, $this->call('PUT', "/members/{$this->admin}/titles", ['title_ids' => []])->status, 'Admins sind Mitglieder');

        // Zugewiesen: nicht löschen, aber umbenennen.
        $res = $this->call('DELETE', "/titles/{$t['Ehrenmitglied']}");
        $this->assertSame(400, $res->status);
        $this->assertStringContainsString('einem Mitglied', (string) $res->data['message']);
        $this->assertSame(200, $this->call('PATCH', "/titles/{$t['Ehrenmitglied']}", ['title' => 'Ehrenkamerad'])->status);
        $this->assertSame(['Ehrenkamerad', 'Kassenprüfer'], array_column($this->call('GET', "/members/{$this->m}")->data['titles'], 'title'));
        $this->assertSame([1, 0, 1], array_column($this->call('GET', '/titles')->data, 'assigned'));
        $this->assertSame(200, $this->call('DELETE', "/titles/{$t['Vorsitz']}")->status, 'nicht zugewiesen');

        // Deaktiviert: wer ihn hat, behält ihn; neu vergeben geht nicht.
        $this->assertSame(200, $this->call('PATCH', "/titles/{$t['Kassenprüfer']}", ['active' => false])->status);
        $this->assertSame(200, $this->call('PUT', "/members/{$this->m}/titles", ['title_ids' => [$t['Kassenprüfer']]])->status);
        $other = $this->users->add('anna', Roles::MEMBER);
        $this->assertSame(400, $this->call('PUT', "/members/$other/titles", ['title_ids' => [$t['Kassenprüfer']]])->status);

        // Alle entfernen
        $this->assertSame([], $this->call('PUT', "/members/{$this->m}/titles", ['title_ids' => []])->data['titles']);
        $this->assertSame(200, $this->call('DELETE', "/titles/{$t['Kassenprüfer']}")->status);
    }

    public function testShownInMeAndMemberList(): void
    {
        $this->setUpApp(['titles' => true, 'ranks' => true]);
        $t = $this->titles('Ehrenmitglied');
        $this->call('PUT', "/members/{$this->m}/titles", ['title_ids' => [$t['Ehrenmitglied']]]);
        $list = array_column($this->call('GET', '/members')->data, null, 'id');
        $this->assertSame([['id' => $t['Ehrenmitglied'], 'title' => 'Ehrenmitglied']], $list[$this->m]['titles']);
        $this->assertSame([], $list[$this->admin]['titles']);

        $this->users->current = $this->m;
        $this->assertSame(['Ehrenmitglied'], array_column($this->call('GET', '/me')->data['titles'], 'title'));
        $this->assertSame(403, $this->call('GET', '/titles')->status, 'Mitglied ohne Recht');
        $this->assertSame(403, $this->call('PUT', "/members/{$this->m}/titles", ['title_ids' => []])->status);

        // Standardrolle „Titel verwalten“ des Bereichs: Liste pflegen und zuweisen, Titel in der Mitgliederliste.
        $chair = $this->users->add('rat', 'titles');
        $this->users->current = $chair;
        $this->assertSame(200, $this->call('GET', '/titles')->status);
        $members = $this->call('GET', '/members')->data;
        $this->assertArrayHasKey('titles', $members[0]);
        $this->assertArrayNotHasKey('rank_title', $members[0], 'Dienstgrade nur mit deren Recht');
        $this->assertSame(200, $this->call('PUT', "/members/{$this->m}/titles", ['title_ids' => []])->status);
    }

    public function testOnlyMembersAndFeatureSwitch(): void
    {
        $this->setUpApp();
        $t = $this->titles('Ehrenmitglied');
        $guest = $this->users->add('gast', Roles::GUEST);
        $this->assertSame(400, $this->call('PUT', "/members/$guest/titles", ['title_ids' => [$t['Ehrenmitglied']]])->status);
        // Mitglied wird Gast: behält den Titel, bis er entfernt wird; dann lässt sich der Titel löschen.
        $this->call('PUT', "/members/{$this->m}/titles", ['title_ids' => [$t['Ehrenmitglied']]]);
        $this->users->setRole($this->m, Roles::GUEST);
        $this->assertSame(['Ehrenmitglied'], array_column($this->call('GET', "/members/{$this->m}")->data['titles'], 'title'));
        $listed = array_column($this->call('GET', '/members')->data, null, 'id');
        $this->assertArrayHasKey($this->m, $listed, 'früheres Mitglied mit Titel bleibt in der Liste');
        $this->assertArrayNotHasKey($guest, $listed);
        $this->assertFalse($listed[$this->m]['member']);
        $this->assertSame(200, $this->call('PUT', "/members/{$this->m}/titles", ['title_ids' => []])->status);
        $this->assertSame(200, $this->call('DELETE', "/titles/{$t['Ehrenmitglied']}")->status);
        $t = $this->titles('Ehrenmitglied');
        $this->assertSame(404, $this->call('PUT', '/members/999/titles', ['title_ids' => []])->status);

        $this->app->features()->update(['titles' => false]);
        foreach ([['GET', '/titles'], ['POST', '/titles'], ['PUT', '/titles/order'], ['PATCH', "/titles/{$t['Ehrenmitglied']}"], ['DELETE', "/titles/{$t['Ehrenmitglied']}"], ['PUT', "/members/{$this->m}/titles"]] as [$method, $path]) {
            $res = $this->call($method, $path);
            $this->assertSame(403, $res->status, "$method $path");
            $this->assertSame('feature_disabled', $res->data['code'] ?? null, "$method $path");
        }
        $this->assertArrayNotHasKey('titles', $this->call('GET', "/members/{$this->m}")->data);
        $this->assertArrayNotHasKey('titles', $this->call('GET', '/me')->data);
    }

    public function testPushOnlyForNewTitles(): void
    {
        $this->setUpApp();
        $t = $this->titles('Ehrenmitglied', 'Vorsitz');
        $user = $this->users->find($this->m);
        $this->assertInstanceOf(User::class, $user);
        $this->assertContains('titles', $this->app->push()->availableTypes($user));
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->assertNotFalse($key);
        $keys = ['p256dh' => WebPushCrypto::b64uEncode(WebPushCrypto::rawPublic($key)), 'auth' => WebPushCrypto::b64uEncode(random_bytes(16))];
        $this->app->push()->subscribe($user, 'https://fcm.googleapis.com/fcm/send/titel', $keys, ['titles'], null, '');
        $queued = fn (): int => $this->app->push()->repository()->counts()['queued'];

        $this->call('PUT', "/members/{$this->m}/titles", ['title_ids' => [$t['Ehrenmitglied']]]);
        $this->assertSame(1, $queued());
        $this->call('PUT', "/members/{$this->m}/titles", ['title_ids' => [$t['Ehrenmitglied']]]);
        $this->call('PUT', "/members/{$this->m}/titles", ['title_ids' => []]);
        $this->assertSame(1, $queued(), 'unverändert bzw. entfernt: keine Nachricht');
        $this->call('PUT', "/members/{$this->m}/titles", ['title_ids' => [$t['Ehrenmitglied'], $t['Vorsitz']]]);
        $this->assertSame(2, $queued(), 'eine Nachricht für mehrere neue Titel');
    }

    public function testDeletedAccountReleasesItsTitles(): void
    {
        $this->setUpApp(['titles' => true, 'ranks' => true]);
        $t = $this->titles('Ehrenmitglied');
        $rank = $this->call('POST', '/ranks', ['title' => 'Gefreiter'])->data['id'];
        $this->call('PUT', "/members/{$this->m}/titles", ['title_ids' => [$t['Ehrenmitglied']]]);
        $this->call('PUT', "/members/{$this->m}/rank", ['rank_id' => $rank]);
        $this->app->userDeleted($this->m);
        // Verwaist (Konto ohne Hook gelöscht): blockiert das Löschen nicht.
        $orphan = $this->users->add('weg', Roles::MEMBER);
        $this->call('PUT', "/members/$orphan/titles", ['title_ids' => [$t['Ehrenmitglied']]]);
        $this->call('PUT', "/members/$orphan/rank", ['rank_id' => $rank]);
        unset($this->users->users[$orphan]);
        $this->assertSame(200, $this->call('DELETE', "/titles/{$t['Ehrenmitglied']}")->status);
        $this->assertSame(200, $this->call('DELETE', "/ranks/$rank")->status);
    }

    public function testBackupContainsTitles(): void
    {
        $this->setUpApp();
        $t = $this->titles('Ehrenmitglied');
        $this->call('PUT', "/members/{$this->m}/titles", ['title_ids' => [$t['Ehrenmitglied']]]);
        $backup = new Backup($this->app->db);
        $tables = $backup->dump(Backup::CORE_TABLES);
        $this->assertCount(1, $tables['member_titles']);
        $backup->restore(['points' => []], Backup::CORE_TABLES);
        $this->assertSame([], $this->app->titles()->repository()->all(false));
        $backup->restore($tables, Backup::CORE_TABLES);
        $this->assertSame(['Ehrenmitglied'], array_column($this->app->titles()->of($this->m), 'title'));
    }
}
