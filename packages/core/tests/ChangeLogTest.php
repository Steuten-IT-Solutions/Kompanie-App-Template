<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\ChangeLog\Subjects;
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
 * Änderungsprotokoll: Mitglieder, Rollen, Einstellungen, Anlegen, Ändern und Löschen in den Bereichen mit alten und neuen Werten; persönliche Angaben
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
        $this->assertSame(200, $this->call('DELETE', '/events/' . $event->data['id'])->status);
        [$e, $created] = $this->entries();
        $this->assertSame(['create', 'Festplatz'], [$created['action'], self::fields($created)['Ort']['new']]);
        $this->assertSame(['Termin', 'delete', 'events', 'Schützenfest'], [$e['type'], $e['action'], $e['area'], $e['label']]);
        $f = self::fields($e);
        $this->assertSame(['field' => 'Ort', 'old' => 'Festplatz'], $f['Ort']);
        $this->assertSame('2027-06-01', $f['Beginn']['old']);
        // Fehlgeschlagen: kein Eintrag
        $this->assertSame(404, $this->call('DELETE', '/events/' . $event->data['id'])->status);
        $this->assertCount(2, $this->entries());
    }

    /** Jede ändernde Route ist erfasst oder steht mit Grund in Subjects::NOT_RECORDED, nie in beiden. */
    public function testEveryChangingRouteIsCoveredOrExcluded(): void
    {
        $subjects = $this->app->api()->changeSubjects();
        $routes = [];
        foreach ($this->app->api()->routes() as $route) {
            if ($route->method !== 'GET' && $route->method !== 'HEAD') {
                $routes[] = $route->method . ' ' . $route->path;
            }
        }
        $missing = array_values(array_filter($routes, static fn (string $k): bool => !in_array($k, Subjects::NOT_RECORDED, true) && !in_array($k, $subjects->keys(), true)));
        $this->assertSame([], $missing, 'erfassen oder in Subjects::NOT_RECORDED begründen');
        $this->assertSame([], array_values(array_intersect(Subjects::NOT_RECORDED, $subjects->keys())), 'nicht in beiden Listen');
        foreach ([...$subjects->keys(), ...Subjects::NOT_RECORDED] as $key) {
            $this->assertContains($key, $routes, "Route $key gibt es nicht");
        }
    }

    /** Anlegen und Ändern in den Bereichen, auch mit Feldern aus anderen Tabellen und mehreren Einträgen auf einmal. */
    public function testCreateAndUpdateInAreas(): void
    {
        $event = $this->call('POST', '/events', ['title' => 'Schützenfest', 'start_date' => '2027-06-01', 'location' => 'Festplatz'])->data['id'];
        $this->assertSame(200, $this->call('PATCH', "/events/{$event}", ['location' => 'Halle'])->status);
        [$update, $create] = $this->entries();
        $this->assertSame(['Termin', 'create', 'events'], [$create['type'], $create['action'], $create['area']]);
        $this->assertSame(['update', ['field' => 'Ort', 'old' => 'Festplatz', 'new' => 'Halle']], [$update['action'], self::fields($update)['Ort']]);
        $this->assertCount(1, $update['changes'], 'nur was sich geändert hat');

        // Seite mit Blöcken: der Inhalt als Text
        $page = $this->call('POST', '/pages', ['title' => 'Über uns', 'blocks' => [['type' => 'text', 'audience' => 'all', 'html' => '<p>Hallo <b>Welt</b></p>']]]);
        $this->assertSame(201, $page->status, (string) json_encode($page->data));
        [$e] = $this->entries();
        $this->assertSame(['Seite', 'create', 'Hallo Welt'], [$e['type'], $e['action'], self::fields($e)['Inhalt']['new']]);

        // Umfrage mit Antworten (andere Tabelle)
        $poll = $this->call('POST', '/polls', ['title' => 'Grillfest?', 'type' => 'single', 'options' => ['Ja', 'Nein']]);
        $this->assertSame(201, $poll->status, (string) json_encode($poll->data));
        [$e] = $this->entries();
        $this->assertSame(['Umfrage', 'Ja, Nein'], [$e['type'], self::fields($e)['Antworten']['new']]);

        // Punkte für zwei Mitglieder: zwei Einträge, je mit dem Mitglied
        $other = $this->users->add('zweites', Roles::MEMBER);
        $this->assertSame(201, $this->call('POST', '/points', ['user_ids' => [$this->member, $other], 'points' => 5, 'reason' => 'Fest'])->status);
        [$a, $b] = $this->entries();
        $this->assertSame(['Punkte', 'Punkte', 'create', 'create'], [$a['type'], $b['type'], $a['action'], $b['action']]);
        $this->assertEqualsCanonicalizing([$this->member, $other], [$a['subject_user_id'], $b['subject_user_id']]);

        // Amtszeit unter einem Amt: die Adresse trägt die ID des Amts, angelegt wird die Amtszeit
        $office = $this->call('POST', '/offices', ['title' => 'Vorsitz'])->data['id'];
        $term = $this->call('POST', "/offices/{$office}/terms", ['kind' => 'period', 'start_year' => 1975, 'end_year' => 1980, 'holders' => [['name' => 'Max Beispiel']]]);
        $this->assertSame(201, $term->status, (string) json_encode($term->data));
        [$e, $o] = $this->entries();
        $this->assertSame(['Amt', 'create'], [$o['type'], $o['action']]);
        $this->assertSame(['Amtszeit', 'create', 'Vorsitz 1975–1980', 'Max Beispiel'], [$e['type'], $e['action'], $e['label'], self::fields($e)['Inhaber']['new']]);
    }

    public function testAssignmentsOrderAttendanceAndEvents(): void
    {
        $rank = $this->call('POST', '/ranks', ['title' => 'Gefreiter'])->data['id'];
        $this->assertSame(200, $this->call('PUT', "/members/{$this->member}/rank", ['rank_id' => $rank])->status);
        [$e] = $this->entries();
        $this->assertSame(['Mitglied', 'update', 'ranks'], [$e['type'], $e['action'], $e['area']]);
        $this->assertSame(['old' => null, 'new' => 'Gefreiter'], array_intersect_key(self::fields($e)['Dienstgrad'], ['old' => 1, 'new' => 1]));

        $first = $this->call('POST', '/titles', ['title' => 'Ehrenmitglied'])->data['id'];
        $second = $this->call('POST', '/titles', ['title' => 'Vorsitz'])->data['id'];
        $this->assertSame(200, $this->call('PUT', '/titles/order', ['ids' => [$second, $first]])->status);
        [$e] = $this->entries();
        $this->assertSame(['Einstellungen', 'Reihenfolge der Titel'], [$e['type'], $e['label']]);
        $this->assertSame(['old' => 'Ehrenmitglied, Vorsitz', 'new' => 'Vorsitz, Ehrenmitglied'], array_intersect_key(self::fields($e)['Reihenfolge'], ['old' => 1, 'new' => 1]));

        // Freie Anwesenheitsliste anlegen, dann ändern (ohne ID in der Adresse)
        $list = $this->call('PUT', '/attendance', ['source' => 'free', 'title' => 'Übung', 'date' => '2026-11-10', 'entries' => [$this->member => 'present']]);
        $this->assertSame(201, $list->status, (string) json_encode($list->data));
        $this->assertSame(200, $this->call('PUT', '/attendance', ['id' => $list->data['id'], 'entries' => [$this->member => 'absent']])->status);
        [$update, $create] = $this->entries();
        $this->assertSame(['Anwesenheitsliste', 'create', 'Mitglied: anwesend'], [$create['type'], $create['action'], self::fields($create)['Einträge']['new']]);
        $this->assertSame(['update', 'Mitglied: anwesend', 'Mitglied: fehlt'], [$update['action'], self::fields($update)['Einträge']['old'], self::fields($update)['Einträge']['new']]);

        // Mailgruppe anlegen
        $this->assertSame(201, $this->call('POST', '/mails/groups', ['label' => 'Vorstand', 'users' => [$this->member]])->status);
        [$e] = $this->entries();
        $this->assertSame(['Mailgruppe', 'create', 'Vorstand'], [$e['type'], $e['action'], $e['label']]);

        // Ohne vergleichbaren Stand: nur der Satz
        $this->assertSame(200, $this->call('POST', '/admin/setup/done')->status);
        [$e] = $this->entries();
        $this->assertSame(['event', 'Ersteinrichtung abgeschlossen', []], [$e['action'], $e['label'], $e['changes']]);
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
                $this->assertIsArray(($subject->all)(), $key);
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

    /** Geheimes von einem Wert auf einen anderen: ein Eintrag „geändert“, ohne Wert und ohne Prüfsumme in der Antwort. */
    public function testSecretSettingsChangeIsRecordedWithoutValue(): void
    {
        foreach ([['/admin/mail', ['password' => 'alt-geheim'], ['password' => 'neu-geheim'], 'SMTP-Passwort'],
            ['/admin/settings', ['calendar_ics_url' => 'https://a.example/x.ics'], ['calendar_ics_url' => 'https://b.example/y.ics'], 'iCal-Adresse'],
            ['/admin/settings', ['google_api_key' => 'AAAA'], ['google_api_key' => 'BBBB'], 'Google-API-Schlüssel']] as [$path, $first, $second, $field]) {
            $this->assertSame(200, $this->call('PATCH', $path, $first)->status);
            $this->assertSame(200, $this->call('PATCH', $path, $second)->status);
            [$e] = $this->entries();
            $this->assertSame([['field' => $field, 'secret' => true]], $e['changes'], $field);
        }
        $all = (string) json_encode($this->entries()) . $this->app->db->scalar("SELECT GROUP_CONCAT(changes) FROM {change_log}");
        foreach (['geheim', 'b.example', 'BBBB', hash('sha256', 'BBBB')] as $secret) {
            $this->assertStringNotContainsString($secret, $all);
        }
    }

    /** Einstellungen: jeder Schlüssel der GET-Antwort hat einen Anzeigenamen oder ist mit Grund ausgelassen. */
    public function testEverySettingHasALabel(): void
    {
        $ignored = Subjects::settingsIgnored();
        foreach (Subjects::SETTINGS_FIELDS as $path => $fields) {
            $res = $this->call('GET', $path);
            $this->assertSame(200, $res->status, $path);
            $flat = [];
            $walk = function (array $d, string $prefix) use (&$walk, &$flat): void {
                foreach ($d as $k => $v) {
                    is_array($v) && !array_is_list($v) && $v !== [] ? $walk($v, $prefix . $k . '.') : $flat[] = $prefix . $k;
                }
            };
            $walk($res->data, '');
            foreach ($flat as $key) {
                $skip = array_filter($ignored[$path] ?? [], static fn (string $i): bool => $i === $key || (str_ends_with($i, '.') && str_starts_with($key, $i)));
                $this->assertTrue(isset($fields[$key]) || $skip !== [], "$path: $key braucht einen Anzeigenamen (SETTINGS_FIELDS) oder einen Grund (settingsIgnored)");
            }
            foreach (array_keys($fields) as $key) {
                $this->assertContains($key, $flat, "$path: $key gibt es nicht");
            }
        }
    }

    /** Mehrere Einträge einer Aktion teilen eine Kennung (batch), einzelne nicht. */
    public function testBatchOfOneAction(): void
    {
        $other = $this->users->add('zweites', Roles::MEMBER);
        $this->assertSame(201, $this->call('POST', '/points', ['user_ids' => [$this->member, $other], 'points' => 5, 'reason' => 'Fest'])->status);
        $this->assertSame(200, $this->call('PUT', "/members/{$this->member}/personal", ['phone' => '+49 171 1234567'])->status);
        [$single, $a, $b] = $this->entries();
        $this->assertSame('', $single['batch']);
        $this->assertSame(16, strlen($a['batch']));
        $this->assertSame($a['batch'], $b['batch']);
        $this->assertSame(['Punkte', 'Punkte'], [$a['type'], $b['type']]);
    }

    /** Ohne Konto (Aufgaben, Kommandozeile) und außerhalb der API (track); innerhalb einer erfassten Anfrage kein zweiter Eintrag. */
    public function testSystemActorAndTrack(): void
    {
        $this->app->changeLog()->event(ChangeLogService::SYSTEM, 'Update auf Version 1.2.3 automatisch eingespielt');
        [$e] = $this->entries();
        $this->assertSame([0, ChangeLogService::SYSTEM, 'event', 'Update auf Version 1.2.3 automatisch eingespielt'], [$e['actor_id'], $e['actor_name'], $e['action'], $e['label']]);

        $api = $this->app->api();
        $api->track('PATCH /admin/birthdays', ChangeLogService::CLI, fn () => $this->app->birthdays()->setUpcomingDays(14));
        [$e] = $this->entries();
        $this->assertSame([ChangeLogService::CLI, 'Geburtstage', ['old' => '7', 'new' => '14']], [$e['actor_name'], $e['label'], array_intersect_key(self::fields($e)['Tage im Voraus'], ['old' => 1, 'new' => 1])]);

        // Ohne Angabe: wer angemeldet ist
        $api->track('PATCH /admin/birthdays', null, fn () => $this->app->birthdays()->setUpcomingDays(21));
        $this->assertSame($this->admin, $this->entries()[0]['actor_id']);
        $count = count($this->entries());
        // Eigene Einstellung (z. B. WordPress), über custom()
        $value = 'a';
        $api->track($api->changeSubjects()->custom('Platzhalterbild', static function () use (&$value): array {
            return ['Bild' => $value];
        }), null, static function () use (&$value): void {
            $value = 'b';
        });
        $this->assertSame([['field' => 'Bild', 'old' => 'a', 'new' => 'b']], $this->entries()[0]['changes']);
        $this->assertCount($count + 1, $this->entries());
    }

    /** Folgen: Der Ämter-Import legt Gruppen und Ämter an; die Galerie nur auf dem Server löscht die Alben aus Drive. */
    public function testSideEffectsAreRecorded(): void
    {
        $res = $this->call('POST', '/offices/import', ['csv' => "gruppe;amt;beginn;ende;art;personen\nKönigshaus;Minister;2021;2022;;Max", 'apply' => true]);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $types = array_column($this->entries(), 'type');
        sort($types);
        $this->assertSame(['Amt', 'Amtszeit', 'Ämtergruppe'], $types);
        $this->assertCount(1, array_unique(array_column($this->entries(), 'batch')));

        $this->app->db->insert('albums', ['drive_id' => 'A1', 'year' => '2026', 'title' => 'Sommerfest', 'sort_date' => '2026-06-27 18:00:00', 'synced_at' => '2026-06-28 00:00:00', 'source' => 'drive']);
        $this->assertSame(200, $this->call('PATCH', '/admin/settings', ['gallery_source' => 'server'])->status);
        $byType = array_column(array_slice($this->entries(), 0, 2), null, 'type');
        $this->assertSame(['delete', 'Sommerfest'], [$byType['Album']['action'], $byType['Album']['label']]);
        $this->assertSame(['old' => 'Google Drive', 'new' => 'nur dieser Server'], array_intersect_key(self::fields($byType['Einstellungen'])['Quelle der Galerie'], ['old' => 1, 'new' => 1]));
    }

    /** Frist der Ehemaligen: nur die Frist, nicht die Liste der fälligen Personen. */
    public function testRetentionWithoutPeople(): void
    {
        $this->assertSame(200, $this->call('PUT', '/admin/former/retention', ['years' => 5])->status);
        [$e] = $this->entries();
        $this->assertSame([['field' => 'Frist (Jahre)', 'old' => '0', 'new' => '5']], $e['changes']);
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
