<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\PollService;
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
use PHPUnit\Framework\TestCase;

/** Umfragen (Issue #4): Rechte, Antworten, Sichtbarkeit, Anonymität, Ende, Löschen, Benachrichtigungen, Brett. */
final class PollServiceTest extends TestCase
{
    private FakeUsers $users;
    private FixedClock $clock;
    private FakeHttpClient $http;
    private NullScheduler $scheduler;
    private App $app;
    private int $admin;
    private int $m;
    private int $m2;
    private int $m3;
    private int $cand;
    private int $guest;
    private int $manager;

    /** @param array<string, bool> $features */
    private function setUpApp(array $features = ['board' => true, 'polls' => true]): void
    {
        $this->users = new FakeUsers();
        $this->clock = new FixedClock(new \DateTimeImmutable('2026-10-20 12:00:00', new \DateTimeZone('Europe/Berlin')));
        $this->http = new FakeHttpClient(static fn (): array => ['status' => 201, 'body' => '']);
        $this->scheduler = new NullScheduler();
        $off = array_fill_keys(['events', 'calendar', 'news', 'gallery', 'points', 'penalties', 'ranks', 'titles', 'attendance', 'documents', 'board', 'polls'], false);
        $this->app = TestApp::create(TestApp::connection(), $this->users, [], $this->http, null, null, $this->clock, $features + $off, [], $this->scheduler);
        $this->app->scheduleTasks();
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->m = $this->users->add('mitglied', Roles::MEMBER);
        $this->m2 = $this->users->add('zweite', Roles::MEMBER);
        $this->m3 = $this->users->add('dritte', Roles::MEMBER);
        $this->cand = $this->users->add('anwaerter', TestApp::candidateRole($this->app));
        $this->guest = $this->users->add('gast', Roles::GUEST);
        $this->manager = $features['polls'] ?? false ? $this->users->add('umfrager', 'polls') : 0;
        $this->users->current = $this->admin;
    }

    private function call(string $method, string $path, array $body = []): Response
    {
        return $this->app->api()->handle(new Request($method, $path, [], $body));
    }

    private function as(?int $user): self
    {
        $this->users->current = $user;
        return $this;
    }

    private function move(string $modifier): void
    {
        $this->clock->at = $this->clock->now()->modify($modifier);
    }

    /**
     * @param array<string, mixed> $in
     * @return array<string, mixed>
     */
    private function create(array $in = []): array
    {
        $res = $this->call('POST', '/polls', $in + ['title' => 'Grillfest am Samstag?', 'type' => 'single', 'options' => ['Ja', 'Nein', 'Vielleicht']]);
        $this->assertSame(201, $res->status, (string) json_encode($res->data));
        return $res->data;
    }

    /** @param array<string, mixed> $poll */
    private function opt(array $poll, string $title): int
    {
        foreach ($poll['options'] as $o) {
            if ($o['title'] === $title) {
                return $o['id'];
            }
        }
        $this->fail("Möglichkeit $title fehlt");
    }

    /** @return array<string, mixed> */
    private function vote(int $pollId, array $body, int $status = 200): array
    {
        $res = $this->call('PUT', "/polls/$pollId/vote", $body);
        $this->assertSame($status, $res->status, (string) json_encode($res->data));
        return $res->data;
    }

    /** @return array<string, int> Titel => Anzahl */
    private static function counts(array $poll): array
    {
        return array_column($poll['results_data']['options'], 'count', 'title');
    }

    public function testRightsPerRole(): void
    {
        $this->setUpApp();
        $this->assertSame(401, $this->as(null)->call('GET', '/polls')->status);
        $this->assertSame(403, $this->as($this->guest)->call('GET', '/polls')->status, 'Gast-Konto');
        $this->assertSame(403, $this->as($this->cand)->call('GET', '/polls')->status, 'Anwärter nehmen nicht teil');
        $this->assertSame(200, $this->as($this->m)->call('GET', '/polls')->status);
        $this->assertSame(403, $this->call('POST', '/polls', ['title' => 'x', 'type' => 'text'])->status, 'Mitglied legt nicht an');

        $roles = array_column($this->app->roles()->describe(), 'capabilities', 'key');
        $this->assertSame(TestApp::caps(['member', 'manage_polls']), $roles['polls'] ?? null, 'Standardrolle „Umfragen verwalten“');
        $poll = $this->as($this->manager)->create();
        $this->assertEquals(['status' => 'running', 'type' => 'single', 'anonymous' => false, 'results' => 'after_vote', 'created_by_name' => 'Umfrager'], array_intersect_key($poll, array_flip(['status', 'type', 'anonymous', 'results', 'created_by_name'])));
        $id = $poll['id'];
        foreach ([['PATCH', "/polls/$id"], ['POST', "/polls/$id/close"], ['DELETE', "/polls/$id"]] as [$method, $path]) {
            $this->assertSame(403, $this->as($this->m)->call($method, $path, ['title' => 'y'])->status, "Mitglied: $method $path");
        }
        $this->assertSame(403, $this->as($this->cand)->call('PUT', "/polls/$id/vote", ['option_ids' => [$this->opt($poll, 'Ja')]])->status, 'Anwärter antworten nicht');
        // Jede und jeder mit dem Recht verwaltet, nicht nur, wer sie angelegt hat.
        $this->assertSame('Grillfest am Sonntag?', $this->as($this->admin)->call('PATCH', "/polls/$id", ['title' => 'Grillfest am Sonntag?'])->data['title']);
        $this->assertSame(200, $this->call('DELETE', "/polls/$id")->status);
        $this->assertSame(404, $this->as($this->manager)->call('GET', "/polls/$id")->status);
    }

    public function testFeatureOffAndRequiresBoard(): void
    {
        $this->setUpApp(['board' => true, 'polls' => false]);
        foreach ([['GET', '/polls'], ['POST', '/polls'], ['GET', '/polls/1'], ['PATCH', '/polls/1'], ['POST', '/polls/1/close'], ['DELETE', '/polls/1'], ['PUT', '/polls/1/vote'], ['DELETE', '/polls/1/vote']] as [$method, $path]) {
            $res = $this->call($method, $path);
            $this->assertSame([403, 'feature_disabled'], [$res->status, $res->data['code']], "$method $path");
        }
        $this->assertArrayNotHasKey(PollService::TASK, $this->scheduler->scheduled, 'Erinnerung nur mit Bereich');
        $this->assertNotContains('polls', $this->app->push()->availableTypes($this->users->find($this->m)));
        $this->assertSame([], array_filter($this->as($this->m)->call('GET', '/board')->data['items'], static fn (array $i): bool => $i['kind'] === 'poll'));
        // Nur zusammen mit dem Schwarzen Brett (dort stehen die laufenden Umfragen).
        $this->assertSame(400, $this->as($this->admin)->call('PUT', '/admin/features', ['board' => false, 'polls' => true])->status);
        $this->assertSame(200, $this->call('PUT', '/admin/features', ['polls' => true])->status);
        $this->assertSame(3600, $this->scheduler->scheduled[PollService::TASK] ?? null, 'Erinnerung stündlich eingeplant');
    }

    public function testCreateValidation(): void
    {
        $this->setUpApp();
        $base = ['title' => 'Frage', 'type' => 'single', 'options' => ['A', 'B']];
        $cases = [
            'ohne Frage' => ['title' => '  '],
            'Frage zu lang' => ['title' => str_repeat('x', 151)],
            'unbekannte Art' => ['type' => 'ranking'],
            'eine Möglichkeit' => ['options' => ['A', '']],
            'keine Möglichkeiten' => ['options' => null],
            'zu viele' => ['options' => array_map(static fn (int $i): string => "Option $i", range(1, 21))],
            'zu lang' => ['options' => ['A', str_repeat('y', 101)]],
            'doppelt' => ['options' => ['Ja', 'ja']],
            'Sichtbarkeit' => ['results' => 'everyone'],
            'anonym kein Wahrheitswert' => ['anonymous' => 'ja'],
            'Ende in der Vergangenheit' => ['ends_at' => '2026-10-20T11:00'],
            'Ende unlesbar' => ['ends_at' => 'morgen'],
            'Ende ungültiges Datum' => ['ends_at' => '2026-02-30T10:00'],
            'Beschreibung zu lang' => ['description' => str_repeat('d', 1001)],
        ];
        foreach ($cases as $name => $change) {
            $this->assertSame(400, $this->call('POST', '/polls', $change + $base)->status, $name);
        }
        $this->assertCount(20, $this->create(['options' => array_map(static fn (int $i): string => "Option $i", range(1, 20))])['options'], '20 gehen');
        $text = $this->create(['type' => 'text', 'options' => ['wird ignoriert'], 'description' => "Zeile 1\nZeile <b>2</b>"]);
        $this->assertSame([], $text['options']);
        $this->assertSame("Zeile 1\nZeile 2", $text['description']);

        // Ende in der Zeitzone der Kompanie, gespeichert in UTC; ISO mit Zeitzone geht auch.
        $this->assertSame('2026-10-25T19:00:00+00:00', $this->create(['ends_at' => '2026-10-25T20:00'])['ends_at'], 'Winterzeit');
        $this->assertSame('2026-10-21T18:00:00+00:00', $this->create(['ends_at' => '2026-10-21 20:00'])['ends_at'], 'Sommerzeit');
        $this->assertSame('2026-10-22T08:00:00+00:00', $this->create(['ends_at' => '2026-10-22T10:00:00+02:00'])['ends_at']);
        $this->assertNull($this->create(['ends_at' => ''])['ends_at'], 'ohne Ende');
    }

    public function testVotingChangingAndWithdrawing(): void
    {
        $this->setUpApp();
        $poll = $this->create();
        $id = $poll['id'];
        [$ja, $nein, $vielleicht] = [$this->opt($poll, 'Ja'), $this->opt($poll, 'Nein'), $this->opt($poll, 'Vielleicht')];
        $this->assertFalse($this->as($this->m)->call('GET', "/polls/$id")->data['results_visible'], 'after_vote: vor der eigenen Antwort keine Ergebnisse');
        $this->assertNull($this->call('GET', "/polls/$id")->data['results_data']);

        // Ungültige Antworten
        $this->vote($id, [], 400);
        $this->vote($id, ['option_ids' => []], 400);
        $this->vote($id, ['option_ids' => [$ja, $nein]], 400);
        $this->vote($id, ['option_ids' => [999]], 400);
        $other = $this->as($this->admin)->create(['title' => 'Andere']);
        $this->as($this->m)->vote($id, ['option_ids' => [$this->opt($other, 'Ja')]], 400);
        $this->vote($id, ['option_ids' => ['abc']], 400);
        $this->assertFalse($this->call('GET', "/polls/$id")->data['has_voted'], 'nichts gespeichert');

        $mine = $this->vote($id, ['option_ids' => [$ja]]);
        $this->assertTrue($mine['has_voted']);
        $this->assertSame(['option_ids' => [$ja], 'text' => null], $mine['my_answer']);
        $this->assertTrue($mine['results_visible']);
        $this->assertSame(['Ja' => 1, 'Nein' => 0, 'Vielleicht' => 0], self::counts($mine));
        $this->assertTrue($mine['locked']);
        $this->as($this->m2)->vote($id, ['option_ids' => [(string) $nein]]);
        $this->as($this->m3)->vote($id, ['option_ids' => [$nein]]);

        // Ändern während der Laufzeit: Summen stimmen.
        $changed = $this->as($this->m)->vote($id, ['option_ids' => [$nein]]);
        $this->assertSame(['Ja' => 0, 'Nein' => 3, 'Vielleicht' => 0], self::counts($changed));
        $this->assertSame(3, $changed['results_data']['voters']);
        $this->assertSame([0, 100, 0], array_column($changed['results_data']['options'], 'percent'));
        $this->as($this->m3)->vote($id, ['option_ids' => [$vielleicht]]);
        $this->assertSame([0, 67, 33], array_column($this->as($this->m)->call('GET', "/polls/$id")->data['results_data']['options'], 'percent'));

        // Zurückziehen
        $after = $this->as($this->m2)->call('DELETE', "/polls/$id/vote")->data;
        $this->assertFalse($after['has_voted']);
        $this->assertNull($after['my_answer']);
        $this->assertFalse($after['results_visible']);
        $this->assertSame(['Ja' => 0, 'Nein' => 1, 'Vielleicht' => 1], self::counts($this->as($this->admin)->call('GET', "/polls/$id")->data), 'manage_polls sieht immer');

        // Mehrfachauswahl: keine → 400, mehrere zählen je Möglichkeit; Prozent der Teilnehmenden.
        $multi = $this->create(['title' => 'Welche Tage?', 'type' => 'multiple', 'options' => ['Fr', 'Sa', 'So']]);
        $this->as($this->m)->vote($multi['id'], ['option_ids' => []], 400);
        $this->vote($multi['id'], ['option_ids' => [$this->opt($multi, 'Fr'), $this->opt($multi, 'Sa'), $this->opt($multi, 'Sa')]]);
        $res = $this->as($this->m2)->vote($multi['id'], ['option_ids' => [$this->opt($multi, 'Sa')]]);
        $this->assertSame(['Fr' => 1, 'Sa' => 2, 'So' => 0], self::counts($res));
        $this->assertSame([50, 100, 0], array_column($res['results_data']['options'], 'percent'));

        // Freitext: leer oder zu lang → 400; Liste der Texte.
        $text = $this->as($this->admin)->create(['title' => 'Ideen?', 'type' => 'text']);
        $this->as($this->m)->vote($text['id'], ['text' => '  '], 400);
        $this->vote($text['id'], ['text' => str_repeat('t', 1001)], 400);
        $this->vote($text['id'], ['option_ids' => [1]], 400);
        $this->vote($text['id'], ['text' => 'Zelt']);
        $res = $this->as($this->m2)->vote($text['id'], ['text' => "Musik\nund Tanz"]);
        $this->assertSame(['option_ids' => [], 'text' => "Musik\nund Tanz"], $res['my_answer']);
        $this->assertSame([['text' => 'Zelt', 'name' => 'Mitglied'], ['text' => "Musik\nund Tanz", 'name' => 'Zweite']], $res['results_data']['texts'], 'namentlich: nach Namen');
        $this->assertSame(2, $res['results_data']['voters']);
    }

    public function testEndedPollsAcceptNoChanges(): void
    {
        $this->setUpApp();
        $poll = $this->create(['ends_at' => '2026-10-22T20:00']);
        $id = $poll['id'];
        $ja = $this->opt($poll, 'Ja');
        $this->as($this->m)->vote($id, ['option_ids' => [$ja]]);

        // Ende erreicht
        $this->clock->at = new \DateTimeImmutable('2026-10-22 20:00:00', new \DateTimeZone('Europe/Berlin'));
        $this->assertSame('ended', $this->call('GET', "/polls/$id")->data['status']);
        $this->vote($id, ['option_ids' => [$ja]], 400);
        $this->assertSame(400, $this->call('DELETE', "/polls/$id/vote")->status);
        $this->assertSame(400, $this->as($this->m2)->call('PUT', "/polls/$id/vote", ['option_ids' => [$ja]])->status);
        $this->assertSame(400, $this->as($this->admin)->call('PATCH', "/polls/$id", ['ends_at' => '2026-10-30T20:00'])->status, 'nicht wieder öffnen');
        $this->assertSame(400, $this->call('POST', "/polls/$id/close")->status, 'schon beendet');

        // Vorzeitig beendet
        $other = $this->create();
        $closed = $this->call('POST', "/polls/{$other['id']}/close")->data;
        $this->assertSame(['ended', '2026-10-22T18:00:00+00:00'], [$closed['status'], $closed['closed_at']]);
        $this->as($this->m)->vote($other['id'], ['option_ids' => [$this->opt($other, 'Ja')]], 400);
        $this->assertSame(400, $this->as($this->admin)->call('PATCH', "/polls/{$other['id']}", ['title' => 'neu'])->status);

        // Beendete stehen weiter in der Liste (mit Ergebnis), aber nicht am Brett.
        $list = $this->as($this->m2)->call('GET', '/polls')->data;
        $this->assertSame([$other['id'], $id], array_column($list, 'id'));
        $this->assertSame([true, true], array_column($list, 'results_visible'), 'after_vote: nach dem Ende alle');
        $this->assertSame([], $this->call('GET', '/board')->data['items']);
    }

    public function testResultVisibility(): void
    {
        $this->setUpApp();
        $polls = [];
        foreach (PollService::RESULTS as $mode) {
            $polls[$mode] = $this->as($this->admin)->create(['title' => "Sichtbar $mode", 'results' => $mode, 'ends_at' => '2026-10-25T12:00']);
            $this->as($this->m)->vote($polls[$mode]['id'], ['option_ids' => [$this->opt($polls[$mode], 'Ja')]]);
        }
        $visible = fn (int $user, string $mode): bool => $this->as($user)->call('GET', "/polls/{$polls[$mode]['id']}")->data['results_visible'];
        $data = fn (int $user, string $mode): mixed => $this->as($user)->call('GET', "/polls/{$polls[$mode]['id']}")->data['results_data'];
        // Läuft: after_vote nur, wer teilgenommen hat; after_end niemand; managers nur manage_polls.
        $this->assertSame([true, false, false], [$visible($this->m, 'after_vote'), $visible($this->m, 'after_end'), $visible($this->m, 'managers')]);
        $this->assertSame([false, false, false], [$visible($this->m2, 'after_vote'), $visible($this->m2, 'after_end'), $visible($this->m2, 'managers')]);
        $this->assertNull($data($this->m, 'managers'));
        $this->assertSame([true, true, true], [$visible($this->manager, 'after_vote'), $visible($this->manager, 'after_end'), $visible($this->manager, 'managers')]);
        // Auch die Liste filtert.
        $list = array_column($this->as($this->m2)->call('GET', '/polls')->data, 'results_data', 'title');
        $this->assertSame([null, null, null], array_values($list));

        $this->clock->at = new \DateTimeImmutable('2026-10-25 12:00:00', new \DateTimeZone('Europe/Berlin'));
        $this->assertSame([true, true, false], [$visible($this->m2, 'after_vote'), $visible($this->m2, 'after_end'), $visible($this->m2, 'managers')]);
        $this->assertSame(['Ja' => 1, 'Nein' => 0, 'Vielleicht' => 0], self::counts(['results_data' => $data($this->m2, 'after_end')]));
        $byTitle = array_column($this->as($this->m)->call('GET', '/polls')->data, null, 'title');
        $this->assertSame([], $byTitle['Sichtbar after_end']['results_data']['options'][1]['names']);
        $this->assertNull($byTitle['Sichtbar managers']['results_data'], 'managers: auch nach dem Ende nicht');
    }

    public function testNamedPollsShowNamesOnlyToAllowedViewers(): void
    {
        $this->setUpApp();
        $poll = $this->create(['results' => 'after_vote']);
        $this->as($this->m2)->vote($poll['id'], ['option_ids' => [$this->opt($poll, 'Ja')]]);
        $this->as($this->m)->vote($poll['id'], ['option_ids' => [$this->opt($poll, 'Ja')]]);
        $rows = $this->call('GET', "/polls/{$poll['id']}")->data['results_data']['options'];
        $this->assertSame(['Mitglied', 'Zweite'], $rows[0]['names'], 'namentlich, sortiert');
        $this->assertNull($this->as($this->m3)->call('GET', "/polls/{$poll['id']}")->data['results_data'], 'ohne eigene Antwort weder Zahlen noch Namen');
        $this->assertStringNotContainsString('Zweite', (string) json_encode($this->call('GET', '/polls')->data));
    }

    public function testAnonymousPollsNeverRevealWhoAnsweredWhat(): void
    {
        $this->setUpApp();
        $choice = $this->create(['title' => 'Geheim', 'anonymous' => true]);
        $text = $this->create(['title' => 'Geheimer Text', 'type' => 'text', 'anonymous' => true]);
        $ja = $this->opt($choice, 'Ja');
        $responses = [];
        foreach ([$this->m => 'Zuletzt', $this->m2 => 'Anfang', $this->m3 => 'mitte'] as $user => $answer) {
            $responses[] = $this->as($user)->call('PUT', "/polls/{$choice['id']}/vote", ['option_ids' => [$ja]])->data;
            $responses[] = $this->call('PUT', "/polls/{$text['id']}/vote", ['text' => $answer])->data;
        }
        $responses[] = $this->as($this->m)->call('PUT', "/polls/{$choice['id']}/vote", ['option_ids' => [$this->opt($choice, 'Nein')]])->data;
        foreach ([$this->m, $this->m2, $this->admin, $this->manager] as $viewer) {
            $this->as($viewer);
            $responses[] = $this->call('GET', '/polls')->data;
            $responses[] = $this->call('GET', "/polls/{$choice['id']}")->data;
            $responses[] = $this->call('GET', "/polls/{$text['id']}")->data;
            $responses[] = $this->call('GET', '/board')->data;
        }
        $responses[] = $this->as($this->admin)->call('PATCH', "/polls/{$choice['id']}", ['title' => 'Geheim!'])->data;
        $responses[] = $this->call('POST', "/polls/{$choice['id']}/close")->data;
        $responses[] = $this->as($this->m2)->call('GET', "/polls/{$choice['id']}")->data;

        $ref = PollService::pseudonym($this->app->polls()->secret(), $this->m);
        foreach ($responses as $r) {
            $json = (string) json_encode($r, JSON_UNESCAPED_UNICODE);
            foreach (['Mitglied', 'Zweite', 'Dritte', '"names"', '"name"', '"user_id"', 'vote_ref', $ref] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $json, "kein $forbidden");
            }
        }
        $res = $this->as($this->manager)->call('GET', "/polls/{$text['id']}")->data['results_data'];
        $this->assertSame([['text' => 'Anfang'], ['text' => 'mitte'], ['text' => 'Zuletzt']], $res['texts'], 'alphabetisch, nicht in der Reihenfolge der Abgabe');
        $this->assertSame(['Ja' => 2, 'Nein' => 1, 'Vielleicht' => 0], self::counts($this->call('GET', "/polls/{$choice['id']}")->data));
        // Die eigene Antwort sieht man selbst.
        $this->assertSame([$this->opt($choice, 'Nein')], $this->as($this->m)->call('GET', "/polls/{$choice['id']}")->data['my_answer']['option_ids']);
        $this->assertSame('mitte', $this->as($this->m3)->call('GET', "/polls/{$text['id']}")->data['my_answer']['text']);
        // In der Tabelle: keine user_id bei anonymen Antworten, nur das Pseudonym.
        $this->assertSame(0, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {poll_answers} WHERE user_id IS NOT NULL'));
        $this->assertSame(1, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {poll_answers} WHERE vote_ref = ? AND poll_id = ?', [$ref, $choice['id']]));
    }

    public function testAnonymityAndVisibilityFixedAfterFirstAnswer(): void
    {
        $this->setUpApp();
        $poll = $this->create();
        $id = $poll['id'];
        // Vor der ersten Antwort änderbar
        $edited = $this->call('PATCH', "/polls/$id", ['anonymous' => true, 'results' => 'after_end', 'options' => ['Ja', 'Nein'], 'description' => 'Bitte bis Freitag'])->data;
        $this->assertSame([true, 'after_end', ['Ja', 'Nein'], false], [$edited['anonymous'], $edited['results'], array_column($edited['options'], 'title'), $edited['locked']]);
        $this->assertSame(400, $this->call('PATCH', "/polls/$id", ['type' => 'multiple', 'options' => ['nur eine']])->status);
        $this->as($this->m)->vote($id, ['option_ids' => [$this->opt($edited, 'Ja')]]);

        $this->as($this->admin);
        foreach (['anonym' => ['anonymous' => false], 'Sichtbarkeit' => ['results' => 'managers'], 'Art' => ['type' => 'multiple'], 'Möglichkeiten' => ['options' => ['Ja', 'Nein', 'Egal']]] as $name => $change) {
            $this->assertSame(400, $this->call('PATCH', "/polls/$id", $change)->status, $name);
        }
        $this->assertSame(200, $this->call('PATCH', "/polls/$id", ['anonymous' => true, 'results' => 'after_end'])->status, 'gleicher Wert geht');
        $res = $this->call('PATCH', "/polls/$id", ['title' => 'Neue Frage', 'ends_at' => '2026-10-30T18:00'])->data;
        $this->assertSame(['Neue Frage', '2026-10-30T17:00:00+00:00', true], [$res['title'], $res['ends_at'], $res['anonymous']], 'Frage und Ende gehen weiter');
        $this->assertSame(1, $res['results_data']['voters'], 'Antwort bleibt');
    }

    public function testUpdatedAtOnlyOnRealChanges(): void
    {
        $this->setUpApp();
        $poll = $this->create();
        $this->move('+1 hour');
        $same = $this->call('PATCH', "/polls/{$poll['id']}", ['title' => $poll['title'], 'options' => ['Ja', 'Nein', 'Vielleicht']])->data;
        $this->assertSame($poll['updated_at'], $same['updated_at']);
        $changed = $this->call('PATCH', "/polls/{$poll['id']}", ['title' => 'Anders'])->data;
        $this->assertSame('2026-10-20T11:00:00+00:00', $changed['updated_at']);
    }

    public function testDeleteRemovesAnswers(): void
    {
        $this->setUpApp();
        $poll = $this->create(['anonymous' => true]);
        $this->as($this->m)->vote($poll['id'], ['option_ids' => [$this->opt($poll, 'Ja')]]);
        $this->assertSame(200, $this->as($this->manager)->call('DELETE', "/polls/{$poll['id']}")->status);
        foreach (['polls', 'poll_options', 'poll_votes', 'poll_answers'] as $t) {
            $this->assertSame(0, (int) $this->app->db->scalar("SELECT COUNT(*) FROM {{$t}}"), $t);
        }
        $this->assertSame(404, $this->call('DELETE', "/polls/{$poll['id']}")->status);
        $this->assertSame(404, $this->as($this->m)->call('PUT', "/polls/{$poll['id']}/vote", ['option_ids' => [1]])->status);
    }

    public function testAccountDeletionRemovesVotesAndAnswers(): void
    {
        $this->setUpApp();
        $named = $this->create(['title' => 'Namentlich']);
        $anon = $this->create(['title' => 'Anonym', 'anonymous' => true]);
        $text = $this->create(['title' => 'Text anonym', 'type' => 'text', 'anonymous' => true]);
        foreach ([$this->m, $this->m2] as $user) {
            $this->as($user)->vote($named['id'], ['option_ids' => [$this->opt($named, 'Ja')]]);
            $this->vote($anon['id'], ['option_ids' => [$this->opt($anon, 'Nein')]]);
            $this->vote($text['id'], ['text' => 'Antwort ' . $user]);
        }
        unset($this->users->users[$this->m]);
        $this->app->userDeleted($this->m);
        $this->assertSame(0, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {poll_votes} WHERE user_id = ?', [$this->m]));
        $this->assertSame(0, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {poll_answers} WHERE user_id = ?', [$this->m]));
        $this->assertSame(0, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {poll_answers} WHERE vote_ref = ?', [PollService::pseudonym($this->app->polls()->secret(), $this->m)]), 'auch anonyme über das Pseudonym');
        $this->assertSame(3, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {poll_answers}'), 'die der anderen bleiben');
        $res = $this->as($this->admin)->call('GET', "/polls/{$text['id']}")->data['results_data'];
        $this->assertSame([['text' => 'Antwort ' . $this->m2]], $res['texts']);
        $this->assertSame(['Ja' => 1, 'Nein' => 0, 'Vielleicht' => 0], self::counts($this->call('GET', "/polls/{$named['id']}")->data));
    }

    public function testBackupContainsTablesAndSecret(): void
    {
        $this->setUpApp();
        $poll = $this->create(['anonymous' => true]);
        $this->as($this->m)->vote($poll['id'], ['option_ids' => [$this->opt($poll, 'Ja')]]);
        foreach (['polls', 'poll_options', 'poll_votes', 'poll_answers'] as $t) {
            $this->assertContains($t, Backup::CORE_TABLES);
        }
        $this->assertNotContains(PollService::SECRET_SETTING, Backup::LOCAL_SETTINGS, 'Geheimnis kommt mit in die Sicherung, sonst passen die Pseudonyme nicht');
        $this->assertContains(PushService::POLLS_OFFERED_SETTING, Backup::LOCAL_SETTINGS);
        $dump = (new Backup($this->app->db))->dump(Backup::CORE_TABLES);
        $this->assertCount(1, $dump['poll_answers']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->app->polls()->secret());
    }

    public function testBoardItemsForMembers(): void
    {
        $this->setUpApp();
        $this->as($this->admin)->call('POST', '/board', ['title' => 'Titel', 'text' => 'Dringend']);
        $poll = $this->create(['ends_at' => '2026-10-25T12:00']);
        $closed = $this->create(['title' => 'Schon zu']);
        $this->call('POST', "/polls/{$closed['id']}/close");

        $items = $this->as($this->m)->call('GET', '/board')->data['items'];
        $this->assertSame(['message', 'poll'], array_column($items, 'kind'), 'Nachrichten zuerst, dann laufende Umfragen');
        $this->assertSame(
            ['kind' => 'poll', 'id' => $poll['id'], 'title' => 'Grillfest am Samstag?', 'type' => 'single', 'ends_at' => '2026-10-25T11:00:00+00:00', 'expires_at' => '2026-10-25T11:00:00+00:00', 'has_voted' => false, 'created_at' => $poll['created_at'], 'updated_at' => $poll['updated_at']],
            $items[1],
        );
        $this->as($this->m)->vote($poll['id'], ['option_ids' => [$this->opt($poll, 'Ja')]]);
        $this->assertTrue($this->call('GET', '/board')->data['items'][1]['has_voted'], 'beantwortet: die App zeigt sie nicht mehr im Banner, aber am Brett');
        $this->assertSame(['message'], array_column($this->as($this->cand)->call('GET', '/board')->data['items'], 'kind'), 'Anwärter sehen keine Umfragen');

        // Nach dem Ende verschwindet sie vom Brett.
        $this->clock->at = new \DateTimeImmutable('2026-10-25 12:00:00', new \DateTimeZone('Europe/Berlin'));
        $this->assertSame([], array_column($this->as($this->m)->call('GET', '/board')->data['items'], 'kind'));
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

    /** @param list<string>|null $types */
    private function subscribe(?int $user, string $device, ?array $types = null): void
    {
        $d = self::device($device);
        $this->app->push()->subscribe($user === null ? null : $this->users->find($user), $d['endpoint'], $d['keys'], $types, null, '1.2.3.4');
    }

    /** @return list<string> Endpunkte in der Warteschlange */
    private function queued(): array
    {
        return array_map(
            static fn (string $e): string => substr($e, strlen('https://fcm.googleapis.com/fcm/send/')),
            array_column($this->app->db->select('SELECT s.endpoint FROM {push_queue} q JOIN {push_subscriptions} s ON s.id = q.subscription_id ORDER BY q.id'), 'endpoint'),
        );
    }

    private function setUpPush(): void
    {
        $this->setUpApp(['board' => true, 'polls' => true, 'events' => true]);
        if (!$this->app->push()->supported()) {
            $this->markTestSkipped('ohne Push-Unterstützung');
        }
        $this->subscribe($this->m, 'm-handy');
        $this->subscribe($this->m, 'm-tablet');
        $this->subscribe($this->m2, 'm2');
        $this->subscribe($this->m3, 'm3-ohne', ['events']);
        $this->subscribe($this->cand, 'anwaerter');
        $this->subscribe($this->guest, 'gast-konto');
        $this->subscribe(null, 'gast');
        $this->subscribe($this->admin, 'admin');
        $this->subscribe($this->manager, 'umfrager');
    }

    public function testPushForNewPollToEveryMemberDeviceExceptCreator(): void
    {
        $this->setUpPush();
        $this->assertContains('polls', $this->app->push()->availableTypes($this->users->find($this->m)));
        $this->assertNotContains('polls', $this->app->push()->availableTypes($this->users->find($this->cand)), 'nur Mitglieder');
        $this->assertNotContains('polls', $this->app->push()->availableTypes(null));

        $poll = $this->as($this->manager)->create(['ends_at' => '2026-10-25T12:00']);
        $this->assertSame(['m-handy', 'm-tablet', 'm2', 'admin'], $this->queued(), 'je Gerät der Mitglieder genau eine Nachricht, nicht an den, der sie angelegt hat');
        $this->assertContains(PushService::TASK_SEND, $this->scheduler->soon);
        $payload = $this->app->push()->repository()->all();
        $this->assertNotEmpty($payload);
        $row = $this->app->db->selectOne('SELECT payload, ttl FROM {push_queue} ORDER BY id');
        $this->assertNotNull($row);
        $msg = json_decode((string) $row['payload'], true);
        $this->assertSame(['Umfragen: neue Umfrage', 'Grillfest am Samstag? (bis 25.10.2026, 12:00 Uhr)', 'umfragen/' . $poll['id']], [$msg['title'], $msg['body'], $msg['route']]);
        $this->assertSame(86400, (int) $row['ttl']);

        // Ändern und Antworten verschicken nichts.
        $this->app->db->execute('DELETE FROM {push_queue}');
        $this->as($this->admin)->call('PATCH', "/polls/{$poll['id']}", ['title' => 'Grillfest?']);
        $this->as($this->m)->vote($poll['id'], ['option_ids' => [$this->opt($poll, 'Ja')]]);
        $this->assertSame([], $this->queued());
    }

    public function testReminderOnlyForMembersWithoutVoteAndOnlyOnce(): void
    {
        $this->setUpPush();
        $poll = $this->as($this->admin)->create(['ends_at' => '2026-10-23T12:00']);
        $soon = $this->create(['title' => 'Kurzfristig', 'ends_at' => '2026-10-21T08:00']);
        $open = $this->create(['title' => 'Ohne Ende']);
        $this->as($this->m)->vote($poll['id'], ['option_ids' => [$this->opt($poll, 'Ja')]]);
        $this->app->db->execute('DELETE FROM {push_queue}');

        // Noch mehr als 24 Stunden: nichts. Die kurzfristige wurde schon mit der neuen Umfrage angekündigt.
        $this->app->runTask(PollService::TASK);
        $this->assertSame([], $this->queued());

        $this->clock->at = new \DateTimeImmutable('2026-10-22 12:30:00', new \DateTimeZone('Europe/Berlin'));
        $this->app->runTask(PollService::TASK);
        $this->assertSame(['m2', 'admin', 'umfrager'], $this->queued(), 'an alle Mitglieder ohne Antwort, auch den, der sie angelegt hat');
        $row = $this->app->db->selectOne('SELECT payload, ttl FROM {push_queue} ORDER BY id');
        $this->assertNotNull($row);
        $this->assertStringContainsString('noch bis 23.10.2026, 12:00 Uhr', (string) json_decode((string) $row['payload'], true)['body']);
        $this->assertSame(23 * 3600 + 1800, (int) $row['ttl'], 'höchstens bis zum Ende zustellbar');

        // Wiederholte Läufe erinnern nicht noch einmal.
        $this->move('+1 hour');
        $this->app->runTask(PollService::TASK);
        $this->app->runTask(PollService::TASK);
        $this->assertCount(3, $this->queued());
        $reminded = fn (int $id): ?string => $this->app->polls()->repository()->find($id)['reminded_at'] ?? null;
        $this->assertNotNull($reminded($poll['id']));
        $this->assertNull($reminded($open['id']), 'ohne Ende keine Erinnerung');
        $this->assertNotNull($reminded($soon['id']));

        // Neues, späteres Ende: wieder eine Erinnerung möglich.
        $this->as($this->admin)->call('PATCH', "/polls/{$poll['id']}", ['ends_at' => '2026-10-28T12:00']);
        $this->assertNull($reminded($poll['id']));
    }

    public function testExistingSubscriptionsGetTheTypeOnFirstSwitchOn(): void
    {
        $this->setUpApp(['board' => true, 'polls' => false, 'events' => true]);
        if (!$this->app->push()->supported()) {
            $this->markTestSkipped('ohne Push-Unterstützung');
        }
        $this->subscribe($this->m, 'm');
        $this->subscribe($this->cand, 'a');
        $this->subscribe(null, 'gast');
        $types = fn (): array => array_column($this->app->push()->repository()->all(), 'types', 'endpoint');
        $this->assertSame(['board', 'events', 'reminder'], $types()['https://fcm.googleapis.com/fcm/send/m']);

        $this->app->features()->update(['polls' => true]);
        $this->assertSame(['board', 'polls', 'events', 'reminder'], $types()['https://fcm.googleapis.com/fcm/send/m'], 'in der üblichen Reihenfolge');
        $this->assertSame(['board', 'events', 'reminder'], $types()['https://fcm.googleapis.com/fcm/send/a'], 'Anwärter nicht');
        $this->assertSame(['events', 'reminder'], $types()['https://fcm.googleapis.com/fcm/send/gast']);

        $this->app->push()->update($this->users->find($this->m), 'https://fcm.googleapis.com/fcm/send/m', null, ['events'], null);
        $this->app->features()->update(['polls' => false]);
        $this->app->features()->update(['polls' => true]);
        $this->assertSame(['events'], $types()['https://fcm.googleapis.com/fcm/send/m'], 'nur einmal');
    }
}
