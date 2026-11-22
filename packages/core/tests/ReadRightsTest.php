<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\RoleService;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\MemorySettings;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * Rechte in Lesen und Schreiben aufgeteilt (Entscheidung vom 17.11.2026): Jedes Schreibrecht aus Capability::READ_OF setzt
 * sein Leserecht voraus, das Leserecht allein zeigt die Daten ohne Ändern, und beim Update bekommt jeder mit dem
 * Schreibrecht das Leserecht einmal dazu (RoleService::migrateReadRights).
 */
final class ReadRightsTest extends TestCase
{
    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $anna;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->app = TestApp::create(TestApp::connection(), $this->users);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->anna = $this->users->add('anna', Roles::MEMBER);
        $this->users->current = $this->admin;
    }

    /** @param array<string, mixed> $body */
    private function call(string $method, string $path, array $body = []): Response
    {
        return $this->app->api()->handle(new Request($method, $path, $method === 'GET' ? $body : [], $method === 'GET' ? [] : $body));
    }

    /** @param list<string> $caps */
    private function withRights(string $login, array $caps): int
    {
        $key = $this->app->roles()->create(['label' => 'Rolle ' . $login, 'capabilities' => [...Roles::TEMPLATES[Roles::MEMBER], ...$caps]])['key'];
        return $this->users->add($login, $key);
    }

    public function testEveryWriteRightRequiresItsReadRight(): void
    {
        foreach (Capability::READ_OF as $write => $read) {
            $this->assertSame(Capability::from($read), Capability::from($write)->requires(), $write);
            $this->assertSame(Capability::Member, Capability::from($read)->requires(), "$read setzt member voraus");
            $this->assertContains(Capability::from($read), Roles::GRANTABLE, "$read ist vergebbar");
        }
        $res = $this->call('POST', '/admin/roles', ['label' => 'Kasse', 'capabilities' => TestApp::caps(['member', 'manage_points'])]);
        $this->assertSame(400, $res->status, 'Schreiben ohne Lesen');
        $this->assertStringContainsString('Recht zum Sehen', (string) ($res->data['message'] ?? ''));
        $this->assertSame(201, $this->call('POST', '/admin/roles', ['label' => 'Kasse', 'capabilities' => TestApp::caps(['member', 'view_points', 'manage_points'])])->status);
        $this->assertSame(201, $this->call('POST', '/admin/roles', ['label' => 'Nur sehen', 'capabilities' => TestApp::caps(['member', 'view_points'])])->status);
        $this->assertSame(400, $this->call('POST', '/admin/roles', ['label' => 'Ohne Mitglied', 'capabilities' => ['view_points']])->status, 'Lesen setzt member voraus');
    }

    public function testReadRightsShowWithoutChanging(): void
    {
        $this->assertSame(201, $this->call('POST', '/points', ['user_ids' => [$this->anna], 'points' => 3, 'reason' => 'Schießen', 'date' => '2026-03-01'])->status);
        $penalty = $this->call('POST', '/penalties', ['user_ids' => [$this->anna], 'amount_cents' => 250, 'reason' => 'Zu spät', 'date' => '2026-03-01']);
        $this->assertSame(201, $penalty->status, (string) json_encode($penalty->data));

        $this->users->current = $this->withRights('leser', ['view_points', 'view_penalties', 'view_promotions', 'view_titles', 'view_attendance']);
        $members = $this->call('GET', '/members');
        $this->assertSame(200, $members->status, 'Mitgliederliste mit Leserechten');
        $anna = array_values(array_filter($members->data, fn (array $m): bool => $m['id'] === $this->anna))[0] ?? [];
        $this->assertSame(3, $anna['points'] ?? null, 'sieht Punkte');
        $this->assertSame(200, $this->call('GET', '/penalties/open')->status);
        $this->assertSame(200, $this->call('GET', '/titles')->status);
        $this->assertSame(200, $this->call('GET', '/attendance')->status);
        $this->assertSame(200, $this->call('GET', '/attendance/events')->status);
        $detail = $this->call('GET', '/members/' . $this->anna);
        $this->assertSame(200, $detail->status);
        $this->assertArrayHasKey('attendance', $detail->data, 'Anwesenheit beim Mitglied');
        $this->assertArrayHasKey('titles', $detail->data, 'Titel beim Mitglied');

        $this->assertSame(403, $this->call('POST', '/points', ['user_ids' => [$this->anna], 'points' => 1, 'reason' => 'x', 'date' => '2026-03-02'])->status);
        $this->assertSame(403, $this->call('POST', '/penalties', ['user_ids' => [$this->anna], 'amount_cents' => 100, 'reason' => 'x', 'date' => '2026-03-02'])->status);
        $this->assertSame(403, $this->call('POST', '/titles', ['title' => 'Ehrenmitglied'])->status);
        $this->assertSame(403, $this->call('PUT', '/members/' . $this->anna . '/titles', ['title_ids' => []])->status);
        $this->assertSame(403, $this->call('PUT', '/members/' . $this->anna . '/rank', ['rank_id' => null])->status);
        $this->assertSame(403, $this->call('PUT', '/attendance', ['entries' => []])->status);

        $this->users->current = $this->withRights('ohne', []);
        $this->assertSame(403, $this->call('GET', '/members')->status, 'ohne Rechte keine Mitgliederliste');
        $this->assertSame(403, $this->call('GET', '/penalties/open')->status);
        $this->assertSame(403, $this->call('GET', '/titles')->status);
        $this->assertSame(403, $this->call('GET', '/attendance')->status);
    }

    public function testContentReadRightsShowWithoutChanging(): void
    {
        $item = $this->call('POST', '/inventory', ['title' => 'Bierbank', 'quantity' => 10, 'location' => 'Lager']);
        $this->assertSame(201, $item->status, (string) json_encode($item->data));
        $list = $this->call('POST', '/lists', ['title' => 'Ausflug']);
        $this->assertSame(201, $list->status, (string) json_encode($list->data));
        $listId = (int) $list->data['id'];

        $this->users->current = $this->withRights('leser2', ['view_inventory', 'view_lists', 'view_mail', 'view_responses', 'view_passive', 'view_reports', 'view_submissions']);
        foreach (['/inventory', '/inventory/locations', '/inventory/log', '/inventory/' . $item->data['id'] . '/log', '/lists', '/lists/' . $listId, '/mails', '/passive-members', '/passive-members/settings', '/reports/review', '/gallery/review'] as $path) {
            $this->assertSame(200, $this->call('GET', $path)->status, "GET $path mit Leserecht");
        }
        // Das Recht reicht; hier fehlt nur der Schalter für Zu- und Absagen.
        $this->assertStringContainsString('ausgeschaltet', (string) ($this->call('GET', '/calendar/x/responses')->data['message'] ?? ''), 'Zu- und Absagen mit view_responses');
        $this->assertSame(403, $this->call('POST', '/inventory', ['title' => 'Tisch', 'quantity' => 1, 'location' => 'Lager'])->status);
        $this->assertSame(403, $this->call('PATCH', '/inventory/' . $item->data['id'], ['quantity' => 2])->status);
        $this->assertSame(403, $this->call('POST', '/lists', ['title' => 'x'])->status);
        $this->assertSame(403, $this->call('DELETE', '/lists/' . $listId)->status);
        $this->assertSame(403, $this->call('GET', '/mails/options')->status, 'Schreiben bleibt bei send_mail');
        $this->assertSame(403, $this->call('POST', '/mails', ['subject' => 'x', 'text' => 'x'])->status);
        $this->assertSame(403, $this->call('POST', '/reports/review/1/close', ['reason' => 'erledigt'])->status, 'Schließen bleibt bei review_reports');
        $this->assertSame(403, $this->call('POST', '/gallery/review/1/reject', ['reason' => 'nein'])->status, 'Ablehnen bleibt bei review_gallery');
        $this->assertSame(403, $this->call('POST', '/passive-members', ['first_name' => 'Paul', 'last_name' => 'Passiv'])->status);

        $this->users->current = $this->withRights('ohne2', []);
        foreach (['/inventory', '/lists', '/mails', '/calendar/x/responses', '/passive-members', '/reports/review', '/gallery/review'] as $path) {
            $this->assertSame(403, $this->call('GET', $path)->status, "GET $path ohne Leserecht");
        }
    }

    public function testAccountReadRightShowsWithoutChanging(): void
    {
        $this->assertSame(Capability::ViewAccounts, Capability::CreateUsers->requires());
        $this->assertSame(Capability::ViewAccounts, Capability::PromoteUsers->requires());
        $this->assertContains(Capability::ViewAccounts, Capability::accountRights(), 'nur Admins geben es weiter');
        $this->assertSame(400, $this->call('POST', '/admin/roles', ['label' => 'Anleger', 'capabilities' => TestApp::caps(['member', 'create_users'])])->status, 'Anlegen ohne Konten sehen');

        $this->users->current = $this->withRights('kontenleser', ['view_accounts']);
        $this->assertSame(200, $this->call('GET', '/members')->status, 'Mitgliederliste mit Konten sehen');
        $detail = $this->call('GET', '/members/' . $this->anna);
        $this->assertSame(200, $detail->status);
        $this->assertArrayHasKey('join_year', $detail->data);
        $this->assertArrayHasKey('no_access', $detail->data);
        $this->assertSame([], $detail->data['rights']['editable'] ?? null, 'Rechte sichtbar, nichts änderbar');
        foreach (['/members/' . $this->anna . '/account', '/registrations', '/admissions'] as $path) {
            $this->assertSame(200, $this->call('GET', $path)->status, "GET $path mit view_accounts");
        }
        $this->assertSame(403, $this->call('PATCH', '/members/' . $this->anna . '/account', ['first_name' => 'X'])->status);
        $this->assertSame(403, $this->call('POST', '/users', ['first_name' => 'Neu', 'last_name' => 'Person', 'level' => 'member'])->status);
        $this->assertSame(403, $this->call('PATCH', '/members/' . $this->anna . '/role', ['level' => 'guest'])->status);
        $this->assertSame(403, $this->call('PUT', '/members/' . $this->anna . '/rights', ['grant' => [], 'revoke' => []])->status);
        $this->assertSame(403, $this->call('PUT', '/members/' . $this->anna . '/join-year', ['date' => '2001'])->status);
        $this->assertSame(403, $this->call('POST', '/members/' . $this->anna . '/password')->status);

        $this->users->current = $this->withRights('ohne3', []);
        foreach (['/members/' . $this->anna . '/account', '/registrations', '/admissions'] as $path) {
            $this->assertSame(403, $this->call('GET', $path)->status, "GET $path ohne view_accounts");
        }
    }

    public function testRestrictedDocumentsAndPollResultsWithoutManaging(): void
    {
        $pdf = base64_encode("%PDF-1.4\n%\xe2\xe3\xcf\xd3\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
        $doc = $this->call('POST', '/documents', ['title' => 'Kassenbericht', 'date' => '2026-10-01', 'visibility' => 'managers', 'file' => ['name' => 'kasse.pdf', 'data' => $pdf]]);
        $this->assertSame(201, $doc->status, (string) json_encode($doc->data));
        $poll = $this->call('POST', '/polls', ['title' => 'Grillfest?', 'type' => 'single', 'options' => ['Ja', 'Nein'], 'results' => 'managers']);
        $this->assertSame(201, $poll->status, (string) json_encode($poll->data));
        $titles = fn (): array => array_column($this->call('GET', '/documents')->data['documents'] ?? [], 'title');

        $this->users->current = $this->withRights('leser4', ['view_restricted_documents', 'view_poll_results']);
        $this->assertContains('Kassenbericht', $titles(), 'eingeschränktes Dokument sichtbar');
        $this->assertTrue($this->call('GET', '/polls/' . $poll->data['id'])->data['results_visible'] ?? null, 'Ergebnisse laufender Umfrage');
        $this->assertSame(403, $this->call('POST', '/polls', ['title' => 'x', 'type' => 'text'])->status);
        $this->assertSame(403, $this->call('DELETE', '/documents/' . $doc->data['id'])->status);

        $this->users->current = $this->anna;
        $this->assertNotContains('Kassenbericht', $titles());
        $this->assertFalse($this->call('GET', '/polls/' . $poll->data['id'])->data['results_visible'] ?? null);
    }

    public function testBoardHistoryWithoutPosting(): void
    {
        $msg = $this->call('POST', '/board', ['title' => 'Nur für Anna', 'text' => '<p>Hallo</p>', 'audience' => 'person', 'audience_ids' => [$this->anna], 'push' => false, 'mail' => false]);
        $this->assertSame(201, $msg->status, (string) json_encode($msg->data));
        $this->assertSame(200, $this->call('DELETE', '/board/' . $msg->data['id'])->status);
        $live = $this->call('POST', '/board', ['title' => 'Nur für Anna 2', 'text' => '<p>Hallo</p>', 'audience' => 'person', 'audience_ids' => [$this->anna], 'push' => false, 'mail' => false]);
        $this->assertSame(201, $live->status, (string) json_encode($live->data));

        $this->users->current = $this->withRights('leser5', ['view_board_history']);
        $items = array_column($this->call('GET', '/board')->data['items'] ?? [], null, 'id');
        $this->assertArrayHasKey($live->data['id'], $items, 'Nachricht für eine andere Person sichtbar');
        $this->assertArrayHasKey('last_edit', $items[$live->data['id']]);
        $history = $this->call('GET', '/board/history');
        $this->assertSame(200, $history->status);
        $this->assertContains('Nur für Anna', array_column($history->data['items'] ?? [], 'title'), 'gelöschte Nachricht im Verlauf');
        $this->assertSame(200, $this->call('GET', '/board/' . $msg->data['id'] . '/history')->status);
        $this->assertSame(403, $this->call('POST', '/board', ['title' => 'x', 'text' => 'x'])->status);
        $this->assertSame(403, $this->call('DELETE', '/board/' . $live->data['id'])->status);

        $this->users->current = $this->withRights('leser6', []);
        $this->assertArrayNotHasKey($live->data['id'], array_column($this->call('GET', '/board')->data['items'] ?? [], null, 'id'));
        $this->assertSame(403, $this->call('GET', '/board/history')->status);
    }

    public function testCompanyMembersWithoutAssigning(): void
    {
        $company = $this->call('POST', '/companies', ['name' => 'Jägerkompanie']);
        $this->assertSame(201, $company->status, (string) json_encode($company->data));
        $this->assertSame(200, $this->call('PUT', '/members/' . $this->anna . '/companies', ['company_ids' => [$company->data['id']]])->status);

        $this->users->current = $this->withRights('leser7', ['view_company_members']);
        $member = $this->call('GET', '/members/' . $this->anna);
        $this->assertSame(200, $member->status, (string) json_encode($member->data));
        $this->assertSame(['Jägerkompanie'], array_column($member->data['companies'] ?? [], 'name'));
        $this->assertSame([$this->anna], $this->call('GET', '/companies')->data[0]['member_ids'] ?? null);
        $this->assertSame(403, $this->call('PUT', '/members/' . $this->anna . '/companies', ['company_ids' => []])->status);

        $this->users->current = $this->withRights('leser8', []);
        $this->assertArrayNotHasKey('companies', $this->call('GET', '/members/' . $this->anna)->data);
    }

    public function testUpdateGivesReadRightsOnceToEveryoneWithTheWriteRight(): void
    {
        $db = TestApp::connection();
        $settings = new MemorySettings();
        $users = new FakeUsers();
        TestApp::create($db, $users, settings: $settings, features: false);
        $granted = $users->add('granted', Roles::MEMBER);
        $revoked = $users->add('revoked', 'r1');

        // Stand vor dem Update: Schreibrechte ohne Leserechte, auch bei Gast und in Ausnahmen einzelner Personen.
        $settings->set(RoleService::SETTING, [
            ['key' => 'guest', 'label' => 'Gast', 'capabilities' => ['view_news', 'manage_titles']],
            ['key' => 'member', 'label' => 'Mitglied', 'capabilities' => ['member', 'view_news']],
            ['key' => 'r1', 'label' => 'Kasse', 'capabilities' => ['member', 'manage_points', 'manage_penalties']],
            ['key' => 'admin', 'label' => 'Admin', 'capabilities' => []],
        ]);
        $settings->set(RoleService::OVERRIDES_SETTING, [
            ['user' => $granted, 'grant' => ['manage_attendance'], 'revoke' => []],
            ['user' => $revoked, 'grant' => [], 'revoke' => ['manage_penalties']],
        ]);
        $settings->set(RoleService::READ_SPLIT_SETTING, ['manage_personal']);

        $app = TestApp::create($db, $users, settings: $settings, features: false);
        $this->assertEqualsCanonicalizing(array_keys(Capability::READ_OF), $settings->get(RoleService::READ_SPLIT_SETTING));
        $roles = $app->roles()->all();
        $this->assertContains('view_points', $roles['r1']['capabilities']);
        $this->assertContains('view_penalties', $roles['r1']['capabilities']);
        $this->assertContains('view_titles', $roles[Roles::GUEST]['capabilities'], 'auch Gast');
        $this->assertNotContains('view_points', $roles[Roles::MEMBER]['capabilities'], 'ohne Schreibrecht nichts dazu');
        $this->assertSame(['grant' => ['view_attendance', 'manage_attendance'], 'revoke' => []], $app->roles()->overridesOf($granted));
        $this->assertSame(['grant' => [], 'revoke' => ['view_penalties', 'manage_penalties']], $app->roles()->overridesOf($revoked), 'entzogen bleibt entzogen');
        $has = static fn (int $id): array => array_map(static fn (Capability $c): string => $c->value, $users->find($id)?->capabilities ?? []);
        $this->assertContains('manage_attendance', $has($granted), 'Schreibrecht bleibt wirksam');
        $this->assertContains('manage_points', $has($revoked));
        $this->assertNotContains('view_penalties', $has($revoked));

        // Nur einmal: Was der Admin danach ändert, bleibt.
        $app->roles()->update('r1', ['capabilities' => TestApp::caps(['member', 'view_points'])]);
        $again = TestApp::create($db, $users, settings: $settings, features: false);
        $this->assertSame(TestApp::caps(['member', 'view_points']), $again->roles()->find('r1')['capabilities'] ?? null);
    }

    public function testNewInstallationNeedsNoMigration(): void
    {
        $this->assertEqualsCanonicalizing(array_keys(Capability::READ_OF), $this->app->settings->get(RoleService::READ_SPLIT_SETTING));
    }
}
