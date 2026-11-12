<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\ChangeLogService;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Storage\Backup;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Änderungsprotokoll: Mitglieder, Rollen, Einstellungen und jedes Löschen mit alten und neuen Werten; persönliche Angaben
 * nur mit view_personal, Geheimes nie; aus, Frist, Löschen mit dem Konto, Auskunft, nicht in Sicherungen.
 */
final class ChangeLogTest extends TestCase
{
    private FakeUsers $users;
    private FixedClock $clock;
    private App $app;
    private int $admin;
    private int $member;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->clock = new FixedClock(new \DateTimeImmutable('2026-11-12 10:00:00', new \DateTimeZone('Europe/Berlin')));
        $this->app = TestApp::create(TestApp::connection(), $this->users, clock: $this->clock);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->member = $this->users->add('mitglied', Roles::MEMBER);
        $this->users->current = $this->admin;
    }

    protected function tearDown(): void
    {
        TestApp::dropTables($this->app->db);
    }

    private function call(string $method, string $path, array $body = [], array $query = [], ?string $preview = null): Response
    {
        return $this->app->api()->handle(new Request($method, $path, $query, $body, [], '', $preview));
    }

    private function withRights(string $login, array $caps): int
    {
        $key = $this->app->roles()->create(['label' => 'Rolle ' . $login, 'capabilities' => [...Roles::TEMPLATES[Roles::MEMBER], ...$caps]])['key'];
        return $this->users->add($login, $key);
    }

    /** @return list<array<string, mixed>> neueste zuerst, wie die Ansicht */
    private function entries(array $query = []): array
    {
        $res = $this->call('GET', '/admin/changes', [], $query);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        return $res->data['entries'];
    }

    /** @return array<string, array<string, mixed>> Feld => Änderung */
    private static function fields(array $entry): array
    {
        return array_column($entry['changes'], null, 'field');
    }

    public function testPersonalDataChangeWithOldAndNewValues(): void
    {
        $res = $this->call('PUT', "/members/{$this->member}/personal", ['phone' => '+49 171 1234567', 'email' => 'neu@example.org']);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        [$e] = $this->entries();
        $this->assertSame(['Mitglied', 'update', 'app', 'Verwaltung', $this->admin, $this->member], [$e['type'], $e['action'], $e['area'], $e['area_label'], $e['actor_id'], $e['subject_user_id']]);
        $this->assertSame('Mitglied', $e['label']);
        $f = self::fields($e);
        $this->assertSame(['old' => null, 'new' => '+49 171 1234567', 'personal' => true], array_diff_key($f['Handynummer'], ['field' => 1]));
        $this->assertSame('neu@example.org', $f['E-Mail']['new']);
        $this->assertArrayNotHasKey('Rolle', $f, 'nur was sich geändert hat');

        // Mit view_changes ohne view_personal: Feld sichtbar, Werte nicht
        $this->users->current = $this->withRights('vorstand', ['view_changes']);
        [$e] = $this->entries();
        $this->assertSame(['field' => 'Handynummer', 'hidden' => true, 'personal' => true], self::fields($e)['Handynummer']);
        // Ohne das Recht gar nicht
        $this->users->current = $this->member;
        $this->assertSame(403, $this->call('GET', '/admin/changes')->status);
    }

    public function testRoleRightsAndOwnAccount(): void
    {
        $role = $this->app->roles()->create(['label' => 'Kassierer', 'capabilities' => [...Roles::TEMPLATES[Roles::MEMBER], 'manage_penalties']]);
        $this->assertSame(200, $this->call('PATCH', "/members/{$this->member}/role", ['level' => $role['key']])->status);
        $this->assertSame(200, $this->call('PUT', "/members/{$this->member}/rights", ['grant' => ['view_personal'], 'revoke' => []])->status);
        [$rights, $level] = $this->entries();
        $this->assertSame(['old' => 'Mitglied', 'new' => 'Kassierer'], array_intersect_key(self::fields($level)['Rolle'], ['old' => 1, 'new' => 1]));
        $this->assertSame('view_personal', self::fields($rights)['Rechte zusätzlich']['new']);

        $this->users->current = $this->member;
        $res = $this->call('POST', '/me/password', ['current_password' => 'geheim123', 'new_password' => 'NeuesPasswort1!']);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->users->current = $this->admin;
        [$e] = $this->entries();
        $this->assertSame([['field' => 'Passwort', 'secret' => true]], $e['changes']);
        $this->assertSame([$this->member, $this->member], [$e['actor_id'], $e['subject_user_id']]);
        $raw = (string) $this->app->db->scalar('SELECT GROUP_CONCAT(changes) FROM {change_log}');
        $this->assertStringNotContainsString('NeuesPasswort1!', $raw);
    }

    public function testPasswordResetHasNoValues(): void
    {
        $res = $this->call('POST', "/members/{$this->member}/password");
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        [$e] = $this->entries();
        $this->assertSame(['Mitglied', 'update'], [$e['type'], $e['action']]);
        $this->assertSame([['field' => 'Passwort', 'secret' => true]], $e['changes']);
        $raw = (string) $this->app->db->scalar('SELECT changes FROM {change_log}');
        $this->assertStringNotContainsString((string) $res->data['password'], $raw);
    }

    public function testDeletionsInAreasWithOldValues(): void
    {
        $event = $this->call('POST', '/events', ['title' => 'Schützenfest', 'start_date' => '2027-06-01', 'location' => 'Festplatz']);
        $this->assertSame(201, $event->status);
        $this->assertSame([], $this->entries(), 'Anlegen in den Bereichen kommt mit Stufe 2');
        $this->assertSame(200, $this->call('DELETE', '/events/' . $event->data['id'])->status);
        [$e] = $this->entries();
        $this->assertSame(['Termin', 'delete', 'events', 'Schützenfest'], [$e['type'], $e['action'], $e['area'], $e['label']]);
        $f = self::fields($e);
        $this->assertSame(['field' => 'Ort', 'old' => 'Festplatz'], $f['Ort']);
        $this->assertSame('2027-06-01', $f['Beginn']['old']);
        // Fehlgeschlagen: kein Eintrag
        $this->assertSame(404, $this->call('DELETE', '/events/' . $event->data['id'])->status);
        $this->assertCount(1, $this->entries());
    }

    public function testEveryDeleteRouteIsCovered(): void
    {
        // Abstimmungen (Anonymität) und Push-Abos (Technik des Geräts) absichtlich nicht.
        $excluded = ['DELETE /polls/{id}/vote', 'DELETE /me/push', 'DELETE /push'];
        $subjects = $this->app->api()->changeSubjects();
        $missing = [];
        foreach ($this->app->api()->routes() as $route) {
            $key = $route->method . ' ' . $route->path;
            if ($route->method === 'DELETE' && !in_array($key, $excluded, true) && $subjects->for($route->method, $route->path) === null) {
                $missing[] = $key;
            }
        }
        $this->assertSame([], $missing);
        foreach ($subjects->keys() as $key) {
            [$method, $path] = explode(' ', $key, 2);
            $this->assertNotEmpty(array_filter($this->app->api()->routes(), static fn ($r): bool => $r->method === $method && $r->path === $path), "Route $key gibt es nicht");
        }
    }

    /** Jede erfasste Route lädt ihren Stand ohne Fehler (GET-Routen der Einstellungen, Tabellen, Spalten). */
    public function testEverySubjectLoads(): void
    {
        $subjects = $this->app->api()->changeSubjects();
        foreach ($subjects->keys() as $key) {
            [$method, $path] = explode(' ', $key, 2);
            $subject = $subjects->for($method, $path);
            $this->assertNotNull($subject);
            $request = new Request($method, $path, [], ['ids' => [1]], ['id' => (string) $this->member, 'key' => 'member']);
            foreach (($subject->ids)($request, null) as $id) {
                ($subject->load)($id);
            }
            if ($subject->all !== null) {
                $this->assertNotEmpty(($subject->all)(), $key);
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testCreateAndDeleteMember(): void
    {
        $res = $this->call('POST', '/users', ['first_name' => 'Max', 'last_name' => 'Muster', 'username' => 'max', 'email' => 'max@example.org']);
        $this->assertSame(201, $res->status, (string) json_encode($res->data));
        $id = (int) $res->data['id'];
        [$e] = $this->entries();
        $this->assertSame(['Mitglied', 'create', 'Max Muster', $id], [$e['type'], $e['action'], $e['label'], $e['subject_user_id']]);
        $this->assertSame('max@example.org', self::fields($e)['E-Mail']['new']);
        $this->assertArrayNotHasKey('Passwort', self::fields($e));
        $this->assertStringNotContainsString((string) ($res->data['password'] ?? 'kein Passwort'), (string) $this->app->db->scalar('SELECT changes FROM {change_log}'));

        // Was die Person selbst geändert hat, bleibt mit ihrem Namen
        $this->users->current = $id;
        $this->assertSame(200, $this->call('PUT', '/me/join-year', ['year' => 2001])->status);
        $this->users->current = $this->admin;
        $this->assertSame(200, $this->call('DELETE', "/members/{$id}")->status);
        $entries = $this->entries();
        $this->assertSame(['delete', 'update', 'create'], array_column($entries, 'action'));
        $this->assertSame('Max Muster', $entries[0]['label']);
        foreach ([$entries[0], $entries[2]] as $about) {
            foreach ($about['changes'] as $c) {
                $this->assertArrayNotHasKey('old', $c, 'keine Werte über die gelöschte Person');
                $this->assertArrayNotHasKey('new', $c);
            }
        }
        // Ihre eigene Änderung bleibt mit ihrem Namen; sie betraf ihr eigenes Konto, also auch ohne Werte.
        $this->assertSame(['Max Muster', $id, 'update'], [$entries[1]['actor_name'], $entries[1]['actor_id'], $entries[1]['action']]);
        $this->assertSame([['field' => 'Eintritt']], $entries[1]['changes']);
    }

    public function testRolesAreLogged(): void
    {
        $res = $this->call('POST', '/admin/roles', ['label' => 'Schriftführer', 'capabilities' => ['member', 'send_mail']]);
        $this->assertSame(201, $res->status, (string) json_encode($res->data));
        $key = array_search('Schriftführer', array_column($this->app->roles()->all(), 'label', 'key'), true);
        $this->assertSame(200, $this->call('PATCH', "/admin/roles/{$key}", ['label' => 'Schriftführerin'])->status);
        $this->assertSame(200, $this->call('DELETE', "/admin/roles/{$key}")->status);
        $entries = $this->entries();
        $this->assertSame([['delete', 'Schriftführerin'], ['update', 'Schriftführerin'], ['create', 'Schriftführer']], array_map(static fn (array $e): array => [$e['action'], $e['label']], $entries));
        $this->assertSame(['old' => 'Schriftführer', 'new' => 'Schriftführerin'], array_intersect_key(self::fields($entries[1])['Bezeichnung'], ['old' => 1, 'new' => 1]));
    }

    public function testSettingsWithoutSecrets(): void
    {
        $res = $this->call('PATCH', '/admin/mail', ['from' => 'info@musterkompanie.example', 'host' => 'smtp.example.org', 'port' => 587, 'secure' => 'starttls', 'user' => 'info', 'password' => 'streng-geheim']);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        [$e] = $this->entries();
        $this->assertSame(['Einstellungen', 'update', 'E-Mail'], [$e['type'], $e['action'], $e['label']]);
        $this->assertNotSame([], $e['changes']);
        $this->assertStringNotContainsString('streng-geheim', (string) json_encode($e));
        $this->assertStringNotContainsString('streng-geheim', (string) $this->app->db->scalar('SELECT changes FROM {change_log}'));
        $this->assertSame(200, $this->call('DELETE', '/admin/error-log')->status);
        $this->assertSame(['delete', 'Fehlerprotokoll'], [$this->entries()[0]['action'], $this->entries()[0]['label']]);
    }

    public function testNotRecorded(): void
    {
        // Vorschau, ohne Unterschied, abgelehnt, ausgeschaltet
        $this->assertSame(403, $this->call('PUT', "/members/{$this->member}/personal", ['phone' => '+49 171 1234567'], [], Roles::MEMBER)->status);
        $this->assertSame(200, $this->call('PUT', "/members/{$this->member}/personal", ['phone' => ''])->status);
        $this->users->current = $this->member;
        $this->assertSame(403, $this->call('PUT', "/members/{$this->admin}/personal", ['phone' => '+49 171 1234567'])->status);
        $this->users->current = $this->admin;
        $this->assertSame([], $this->entries());

        $this->assertSame(200, $this->call('PUT', "/members/{$this->member}/personal", ['phone' => '+49 171 1234567'])->status);
        $this->assertCount(1, $this->entries());
        $res = $this->call('PUT', '/admin/changes/settings', ['enabled' => false]);
        $this->assertSame(['enabled' => false, 'keep_days' => 365], array_intersect_key($res->data, ['enabled' => 1, 'keep_days' => 1]));
        $this->assertSame(0, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {change_log}'), 'aus löscht alle Einträge');
        $this->assertSame(200, $this->call('PUT', "/members/{$this->member}/personal", ['phone' => ''])->status);
        $this->assertSame(0, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {change_log}'));
        $this->assertSame(200, $this->call('PUT', '/admin/changes/settings', ['enabled' => true])->status);
        [$e] = $this->entries();
        $this->assertSame(['Änderungsprotokoll', ['old' => 'nein', 'new' => 'ja']], [$e['label'], array_intersect_key(self::fields($e)['Änderungen protokollieren'], ['old' => 1, 'new' => 1])]);
        // Nur Admins stellen ein
        $this->users->current = $this->withRights('vorstand', ['view_changes']);
        $this->assertSame(403, $this->call('PUT', '/admin/changes/settings', ['enabled' => false])->status);
    }

    public function testKeepDays(): void
    {
        $this->assertSame(400, $this->call('PUT', '/admin/changes/settings', ['keep_days' => 10])->status);
        $this->assertSame(400, $this->call('PUT', '/admin/changes/settings', ['keep_days' => 800])->status);
        $this->assertSame(200, $this->call('PUT', "/members/{$this->member}/personal", ['phone' => '+49 171 1234567'])->status);
        $this->clock->at = $this->clock->now()->modify('+40 days');
        $this->assertSame(200, $this->call('PUT', "/members/{$this->member}/personal", ['phone' => ''])->status);
        $this->app->runTask(ChangeLogService::TASK);
        $this->assertSame(2, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {change_log}'), 'Standard 365 Tage');
        $this->assertSame(200, $this->call('PUT', '/admin/changes/settings', ['keep_days' => 30])->status);
        // Die erste Änderung ist 40 Tage alt und fällt sofort weg; die Änderung der Frist selbst kommt dazu.
        $this->assertSame(['Änderungsprotokoll', 'Mitglied'], array_column($this->entries(['from' => '2026-10-01', 'to' => '2026-12-31']), 'label'));
        $this->assertSame(2, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {change_log}'));
        $this->assertContains(ChangeLogService::TASK, array_keys(App::TASKS));
    }

    public function testFormerRetentionRemovesOnlyPersonalValues(): void
    {
        $this->assertSame(200, $this->call('PUT', "/members/{$this->member}/personal", ['phone' => '+49 171 1234567'])->status);
        $this->assertSame(200, $this->call('PUT', "/members/{$this->member}/join-year", ['year' => 1999])->status);
        $this->app->changeLog()->forgetSubject($this->member, true);
        [$join, $phone] = $this->entries();
        $this->assertSame(['field' => 'Handynummer', 'personal' => true], self::fields($phone)['Handynummer']);
        $this->assertSame('1999', self::fields($join)['Eintritt']['new']);
        $this->assertSame(0, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {change_log} WHERE personal = 1'));
    }

    public function testPersonalDataExport(): void
    {
        $this->assertSame(200, $this->call('PUT', "/members/{$this->member}/personal", ['phone' => '+49 171 1234567'])->status);
        $this->users->current = $this->member;
        $this->assertSame(200, $this->call('PUT', '/me/join-year', ['year' => 2001])->status);
        $data = $this->app->personalData()->export($this->users->find($this->member), $this->member)['data'];
        $this->assertCount(2, $data['change_log']['about']);
        $this->assertSame('+49 171 1234567', self::fields($data['change_log']['about'][0])['Handynummer']['new']);
        $this->assertCount(1, $data['change_log']['by']);
        $this->assertArrayNotHasKey('changes', $data['change_log']['by'][0]);
    }

    public function testPushIsNotUsage(): void
    {
        $this->users->current = $this->member;
        $this->call('DELETE', '/me/push', ['endpoint' => 'https://push.example.org/abc']);
        $this->assertSame(0, (int) $this->app->db->scalar("SELECT COUNT(*) FROM {activity} WHERE area = 'app'"));
    }

    public function testNotInBackups(): void
    {
        $this->assertNotContains('change_log', Backup::CORE_TABLES);
    }

    /** @return array<string, array{string}> */
    public static function drivers(): array
    {
        return getenv('KOMPANIE_TEST_MYSQL') ? ['sqlite' => ['sqlite'], 'mysql' => ['mysql']] : ['sqlite' => ['sqlite']];
    }

    #[DataProvider('drivers')]
    public function testOnEveryDatabase(string $driver): void
    {
        $db = TestApp::connection($driver);
        try {
            $app = TestApp::create($db, $this->users, clock: $this->clock);
            $res = $app->api()->handle(new Request('PUT', "/members/{$this->member}/personal", [], ['phone' => '+49 171 1234567']));
            $this->assertSame(200, $res->status);
            $this->assertSame(1, (int) $db->scalar('SELECT COUNT(*) FROM {change_log} WHERE personal = 1'));
            $app->userDeleted($this->member);
            $this->assertSame(0, (int) $db->scalar('SELECT COUNT(*) FROM {change_log} WHERE personal = 1'));
            $app->changeLog()->updateSettings(['enabled' => false]);
            $this->assertSame(0, (int) $db->scalar('SELECT COUNT(*) FROM {change_log}'));
        } finally {
            TestApp::dropTables($db);
        }
    }
}
