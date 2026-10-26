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

/** Mitglieder aus einer Tabelle übernehmen, mit Anwärtern und Ehemaligen (Ersterfassung nach der Installation). */
final class MemberImportTest extends TestCase
{
    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $m;
    private string $candidate;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->app = TestApp::create(TestApp::connection(), $this->users, clock: new FixedClock(new \DateTimeImmutable('2026-10-22 12:00:00')));
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->m = $this->users->add('mitglied', Roles::MEMBER);
        $this->users->users[$this->m]['name'] = 'Erika Muster';
        $this->users->current = $this->admin;
        $this->candidate = TestApp::candidateRole($this->app);
    }

    /** @param array<string, mixed> $body */
    private function call(string $method, string $path, array $body = []): Response
    {
        return $this->app->api()->handle(new Request($method, $path, [], $body));
    }

    private function import(string $csv, bool $apply = false): Response
    {
        return $this->call('POST', '/members/import', ['csv' => $csv, 'apply' => $apply]);
    }

    /** @return array<string, int> Name => ID aller Konten */
    private function ids(): array
    {
        $out = [];
        foreach ($this->users->users as $id => $u) {
            $out[$u['name']] = $id;
        }
        return $out;
    }

    public function testPreviewAndApply(): void
    {
        $rank = $this->call('POST', '/ranks', ['title' => 'Gefreiter'])->data['id'];
        $this->call('POST', '/titles', ['title' => 'Ehrenmitglied']);
        $csv = "Name;Vorname;E-Mail;Status;Geburtstag;Eintritt;Punkte;Dienstgrad;Titel;Ausgeschieden;Grund;Telefon\n"
            . "Muster;Erika;;;;;;;;;;0123\n"
            . "Beispiel;Max;max@example.org;Mitglied;03.02.1980;1999;12;gefreiter;Ehrenmitglied;;;\n"
            . "Probe;Paula;;Anwärter;24.12.;15.03.2025;;;;;;\n"
            . ";;;;;;;;;;;\n"
            . "Alt;Otto;;ehemalig;;Gründer;;;;2001;verstorben;\n"
            . "Früher;Fritz;;Ehemalig;01.01.1950;1970;;;;1990;;\n";
        $res = $this->import($csv);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $d = $res->data;
        $this->assertSame(['last_name', 'first_name', 'email', 'status', 'birthday', 'joined', 'points', 'rank', 'titles', 'left', 'reason'], $d['columns']['used']);
        $this->assertSame([['column' => 'Telefon', 'reason' => 'unbekannte Spalte']], $d['columns']['ignored']);
        $this->assertSame(['active' => 2, 'former' => 2], $d['new']);
        $this->assertSame([1, false, []], [$d['existing'], $d['applied'], $d['created']]);
        $rows = array_column($d['rows'], null, 'line');
        $this->assertSame(['id' => $this->m, 'name' => 'Erika Muster', 'former' => false], $rows[2]['existing']);
        $this->assertSame(['member', 'max.beispiel', 'max@example.org', '1980-02-03', '1999', 12, 'Gefreiter', ['Ehrenmitglied']], [$rows[3]['level'], $rows[3]['username'], $rows[3]['email'], $rows[3]['birthday'], $rows[3]['joined_on'], $rows[3]['points'], $rows[3]['rank'], $rows[3]['titles']]);
        $this->assertSame([$this->candidate, '12-24', '2025-03-15'], [$rows[4]['level'], $rows[4]['birthday'], $rows[4]['joined_on']]);
        $this->assertArrayNotHasKey(5, $rows, 'Leerzeile');
        $this->assertSame(['former', 'deceased', true, '', '2001', ''], [$rows[6]['status'], $rows[6]['reason'], $rows[6]['founder'], $rows[6]['joined_on'], $rows[6]['left_on'], $rows[6]['username']]);
        $this->assertSame(['left', '1970', '1990', '1950-01-01'], [$rows[7]['reason'], $rows[7]['joined_on'], $rows[7]['left_on'], $rows[7]['birthday']]);
        $this->assertCount(2, $this->users->users, 'Vorschau legt nichts an');

        $res = $this->import($csv, true);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertTrue($res->data['applied']);
        $this->assertArrayNotHasKey('error', $res->data);
        $created = array_column($res->data['created'], null, 'name');
        $this->assertSame(['Max Beispiel', 'Paula Probe'], array_keys($created), 'Zugangsdaten nur für aktive Konten');
        $this->assertSame([Roles::MEMBER, 'max.beispiel'], [$created['Max Beispiel']['level'], $created['Max Beispiel']['username']]);
        $this->assertNotSame('', $created['Max Beispiel']['password']);
        $this->assertSame($this->candidate, $created['Paula Probe']['level']);

        $ids = $this->ids();
        $max = $ids['Max Beispiel'];
        $detail = $this->call('GET', "/members/$max")->data;
        $this->assertSame(12.0, (float) $detail['points']);
        $this->assertSame('Gefreiter', $detail['rank_title']);
        $this->assertSame(['Ehrenmitglied'], array_column($detail['titles'], 'title'));
        $this->assertSame([2, 3, 1980], [$detail['birthday']['month'], $detail['birthday']['day'], $detail['birthday']['year']]);
        $this->assertSame('1999', $detail['join_year']['date']);
        $this->assertSame($rank, $this->app->ranks()->current($max)['id'] ?? null);
        $this->assertSame('2025-03-15', $this->call('GET', '/members/' . $ids['Paula Probe'])->data['join_year']['date']);

        $former = array_column($this->call('GET', '/members/former')->data, null, 'name');
        $this->assertSame(['Fritz Früher', 'Otto Alt'], array_keys($former));
        $this->assertSame(['deceased', true, '2001'], [$former['Otto Alt']['reason'], $former['Otto Alt']['founder'], $former['Otto Alt']['left_on']]);
        $this->assertSame(Roles::GUEST, $this->users->find($ids['Otto Alt'])?->role, 'Ehemalige ohne Anmeldung');

        // Ein zweiter Lauf legt niemanden doppelt an.
        $res = $this->import($csv, true);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertSame([['active' => 0, 'former' => 0], 5, []], [$res->data['new'], $res->data['existing'], $res->data['created']]);
        $this->assertTrue(array_column($res->data['rows'], null, 'line')[6]['existing']['former']);
        $this->assertCount(6, $this->users->users);
    }

    public function testErrorsBlockApply(): void
    {
        $this->call('POST', '/titles', ['title' => 'Ehrenmitglied']);
        $csv = "Vorname;Nachname;Benutzername;E-Mail;Status;Geburtstag;Eintritt;Punkte;Dienstgrad;Titel;Grund\n"
            . ";Ohne;;;;;;;;;\n"                                // 2: Vorname fehlt
            . "Anna;A;anna;;;;;;;;\n"                           // 3: gut
            . "Bernd;B;anna;;;;;;;;\n"                          // 4: Benutzername doppelt
            . "Clara;C;;a@example.org;;;;;;;\n"                 // 5: gut
            . "Dora;D;;A@example.org;;;;;;;\n"                  // 6: E-Mail doppelt
            . "Anna;A;;;;;;;;;\n"                               // 7: Name doppelt
            . "Emil;E;;;Vorstand;;;;;;\n"                       // 8: Status unbekannt
            . "Fritz;F;;;;31.02.1990;;;;;\n"                    // 9: Datum gibt es nicht
            . "Gerd;G;;;;1990/01/01;;;;;\n"                     // 10: Format
            . "Hans;H;;;;;2030;;;;\n"                           // 11: Zukunft
            . "Ida;I;;;Anwärter;;;5;;;\n"                       // 12: Punkte nur für Mitglieder
            . "Jan;J;;;;;;;Major;;\n"                           // 13: Dienstgrad unbekannt
            . "Karl;K;;;;;;;;Ehrenmitglied, Unbekannt;\n"       // 14: Titel unbekannt
            . "Lena;L;;;;;;;;;verstorben\n"                     // 15: Grund nur bei Ehemaligen
            . "Mia;M;;;;;Gründer;;;;\n"                         // 16: Gründer nur bei Ehemaligen
            . "Nils;N;;;ehemalig;;;;;;weg\n"                    // 17: Grund unbekannt
            . "Otto;O;;;;;;-3;;;\n";                            // 18: Punkte
        $res = $this->import($csv);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $rows = array_column($res->data['rows'], null, 'line');
        $expect = [2 => 'Vorname', 4 => 'Benutzername', 6 => 'E-Mail', 7 => 'Zeile 3', 8 => 'Vorstand', 9 => 'gibt es nicht', 10 => 'TT.MM.JJJJ', 11 => 'Zukunft', 12 => 'Mitgliederbereich', 13 => 'Major', 14 => 'Unbekannt', 15 => 'nur bei Ehemaligen', 16 => 'Gründer', 17 => 'Grund', 18 => 'Anfangspunkte'];
        foreach ($expect as $line => $text) {
            $this->assertStringContainsString($text, $rows[$line]['error'] ?? '', "Zeile $line");
        }
        $this->assertArrayNotHasKey('error', $rows[3]);
        $this->assertArrayNotHasKey('error', $rows[5]);
        $this->assertSame(400, $this->import($csv, true)->status);
        $this->assertCount(2, $this->users->users, 'nichts angelegt');
    }

    public function testHeaderAndFormats(): void
    {
        $this->assertSame(400, $this->import('Max;Muster')->status, 'ohne Kopfzeile');
        $this->assertSame(400, $this->import("Vorname;Nachname\n")->status, 'nur Kopfzeile');
        $this->assertSame(400, $this->import(' ')->status);
        $this->assertSame(400, $this->import("Vorname\n" . str_repeat("A\n", 501))->status, 'zu viele Zeilen');

        // Aus Excel gelesen bzw. kopiert: Tabulator; Windows-1252; Komma; BOM.
        $res = $this->import(mb_convert_encoding("VORNAME\tNach-Name\tE_Mail\nJörg\tTest\t", 'Windows-1252', 'UTF-8'));
        $this->assertSame(['Jörg Test', ['first_name', 'last_name', 'email']], [$res->data['rows'][0]['name'], $res->data['columns']['used']]);
        $res = $this->import("\xEF\xBB\xBFvorname,nachname,status\nAnna,Komma,ehemalig");
        $this->assertSame(['Anna Komma', 'former'], [$res->data['rows'][0]['name'], $res->data['rows'][0]['status']]);
        $res = $this->import("Vorname;Vorname;Nachname\nA;B;C");
        $this->assertSame([['column' => 'Vorname', 'reason' => 'doppelt']], $res->data['columns']['ignored']);
        $this->assertSame('A C', $res->data['rows'][0]['name']);
    }

    public function testColumnsOfDisabledAreasAndMissingRightsAreIgnored(): void
    {
        $this->call('PUT', '/admin/features', ['birthdays' => false, 'points' => false]);
        $csv = "Vorname;Geburtstag;Punkte;Dienstgrad\nAnna;01.01.1990;5;";
        $ignored = array_column($this->import($csv)->data['columns']['ignored'], 'column');
        $this->assertSame(['Geburtstag', 'Punkte'], $ignored);

        // Wer nur Konten anlegt, vergibt beim Import keine Dienstgrade.
        $creator = $this->users->add('anleger', $this->app->roles()->create(['label' => 'Anleger', 'capabilities' => TestApp::caps(['member', 'create_users'])])['key']);
        $this->users->current = $creator;
        $res = $this->import($csv);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertContains('Dienstgrad', array_column($res->data['columns']['ignored'], 'column'));

        $this->users->current = $this->m;
        $this->assertSame(403, $this->import($csv)->status, 'nur wer Konten anlegt');
    }
}
