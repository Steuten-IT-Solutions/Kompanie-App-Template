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
 * Import der Passiven aus einer Tabelle (Domain\PassiveImportService): Kopfzeile unter einem Titel, Spalten an ihren
 * Namen, Betreuer als „V. Nachname“, Beitrag in Spalten je Zahlungsart, Vorschau, Dubletten, Rechte. Alle Daten erfunden.
 */
final class PassiveImportTest extends TestCase
{
    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $caretaker;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->app = TestApp::create(TestApp::connection(), $this->users, clock: new FixedClock(new \DateTimeImmutable('2026-11-17 12:00:00', new \DateTimeZone('Europe/Berlin'))));
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->caretaker = $this->users->create('Anna', 'Beispiel', 'betreuer', '', 'geheim123');
        $this->users->create('Bernd', 'Doppel', 'zweite', '', 'geheim123');
        $this->users->create('Berta', 'Doppel', 'dritte', '', 'geheim123');
        $this->users->current = $this->admin;
    }

    private function call(string $method, string $path, array $body = []): Response
    {
        return $this->app->api()->handle(new Request($method, $path, [], $body));
    }

    /** Tabelle wie aus Excel gelesen: Titelzeilen, Kopfzeile, Daten, Leerzeile, Summenzeile. */
    private static function sheet(): string
    {
        $rows = [
            ['', 'Stand:', '01.11.2026'],
            ['', 'Jahr:', '2026', 'Beitrag:', '25'],
            [],
            ['Nr.', 'Nachname', 'Vorname', 'Strasse', 'PLZ', 'Wohnort', 'Telefon-Nr.', 'Geb-Datum', 'Email', 'Betreuer', 'Eintritt', 'Paypal', 'Bar', 'Überw.', 'Info', 'Bemerkung'],
            ['1', 'Muster', 'Erika', 'Musterstr. 12', '12345', 'Musterstadt', '01234 56789', '03.02.1950', 'erika@example.org', 'A. Beispiel', '2023', '25', '', '', '12.03. per PayPal', 'Will keine Post'],
            ['2', 'Probe', 'Otto', '', '', '', '- - - - -', '', '- - - - -', 'B. Doppel', 'vor 2014', '', '30', '', '', ''],
            ['3', 'Test', 'Theo', 'Weg 1a', '12345', 'Musterstadt', '', '', '', 'SEPA', '', '', '', '25', 'DE00 0000 0000 0000 0000 00', ''],
            ['4', 'Ohne', 'Olga', '', '', 'Town, USA', 'kein Telefon', '31.02.1950', '', '', '', '', '', '', '', ''],
            [],
            ['', 'Paypal', '', '', '', '', '', '', '', '', '', '25', '30', '25'],
            ['', 'Bar', '1'],
        ];
        return implode("\n", array_map(static fn (array $r): string => implode("\t", $r), $rows));
    }

    private function setMethods(): void
    {
        $res = $this->call('PATCH', '/passive-members/settings', ['methods' => ['Bar', 'SEPA', 'PayPal', 'Überweisung']]);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
    }

    public function testPreviewRecognisesColumnsCaretakersAndPayments(): void
    {
        $this->setMethods();
        $res = $this->call('POST', '/passive-members/import', ['csv' => self::sheet(), 'columns' => null]);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $d = $res->data;
        $this->assertSame(4, $d['header_line'], 'Kopfzeile unter dem Titel gefunden');
        $columns = (array) $d['columns'];
        $this->assertSame(1, $columns['last_name']);
        $this->assertSame(2, $columns['first_name']);
        $this->assertSame(9, $columns['caretaker']);
        $this->assertArrayNotHasKey('paid_on', $columns, '„Info“ wird nicht von selbst zugeordnet');
        $this->assertSame([['column' => 11, 'method' => 'PayPal'], ['column' => 12, 'method' => 'Bar'], ['column' => 13, 'method' => 'Überweisung']], $d['method_columns']);
        $this->assertSame(2026, $d['year']);
        $this->assertSame('2026-11-17', $d['paid_on']);

        [$erika, $otto, $theo, $olga, $sum] = $d['rows'];
        $this->assertSame('Erika Muster', $erika['name']);
        $this->assertSame(['id' => $this->caretaker, 'name' => 'Anna Beispiel'], $erika['caretaker']);
        $this->assertSame('Musterstr.', $erika['address']['street']);
        $this->assertSame('12', $erika['address']['house_number']);
        $this->assertSame('1950-02-03', $erika['birth_date']);
        $this->assertSame('2023', $erika['joined']);
        $this->assertSame('Will keine Post', $erika['note']);
        $this->assertSame(['year' => 2026, 'amount_cents' => 2500, 'paid_on' => '2026-11-17', 'method' => 'PayPal'], $erika['payment']);

        $this->assertNull($otto['caretaker']);
        $this->assertStringContainsString('mehreren Mitgliedern', $otto['warnings'][0]);
        $this->assertSame('', $otto['phone'], '„- - - - -“ gilt als leer');
        $this->assertSame('vor 2014', $otto['joined']);
        $this->assertSame('Bar', $otto['payment']['method']);
        $this->assertSame(3000, $otto['payment']['amount_cents']);

        $this->assertStringContainsString('kein Mitglied', $theo['warnings'][0]);
        $this->assertSame('Überweisung', $theo['payment']['method'], 'Abkürzung „Überw.“');
        $this->assertNull($olga['payment']);
        $this->assertArrayNotHasKey('error', $olga, 'ungültige persönliche Angaben sind nur Hinweise');
        $this->assertCount(3, $olga['warnings'], 'Adresse, Telefon, Geburtsdatum');
        $this->assertNull($olga['address']);
        $this->assertSame('', $olga['phone']);
        $this->assertArrayHasKey('error', $sum, 'Summenzeile ohne Vornamen');
        $this->assertStringContainsString('keine Buchstaben', $d['rows'][5]['error'], 'Summenzeile mit Zahl als Vorname');
        $this->assertSame(4, $d['new']);
        $this->assertSame(3, $d['payments']);
        $this->assertSame(2, $d['errors']);
        $this->assertSame([], $this->call('GET', '/passive-members')->data['passives'], 'Vorschau ändert nichts');
    }

    public function testApplyWithMappedDateColumnSkippingErrorsAndAgainSkipsExisting(): void
    {
        $this->setMethods();
        $preview = $this->call('POST', '/passive-members/import', ['csv' => self::sheet()])->data;
        $columns = (array) $preview['columns'];
        $columns['paid_on'] = 14;

        $blocked = $this->call('POST', '/passive-members/import', ['csv' => self::sheet(), 'columns' => $columns, 'apply' => true]);
        $this->assertSame(400, $blocked->status, 'mit Fehlern nur, wenn ausdrücklich übersprungen');
        $this->assertStringContainsString('Zeile 10', (string) json_encode($blocked->data, JSON_UNESCAPED_UNICODE));

        $res = $this->call('POST', '/passive-members/import', ['csv' => self::sheet(), 'columns' => $columns, 'apply' => true, 'skip_errors' => true, 'paid_on' => '2026-11-01']);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertTrue($res->data['applied']);
        $this->assertSame(4, $res->data['new']);

        $list = $this->call('GET', '/passive-members')->data['passives'];
        $this->assertSame(['Muster', 'Ohne', 'Probe', 'Test'], array_column($list, 'last_name'));
        $erika = $list[0];
        $this->assertSame('2026-03-12', $erika['payments'][0]['paid_on'], 'TT.MM. aus der Spalte Info im Jahr des Beitrags');
        $this->assertSame('2023', $erika['joined']);
        $this->assertSame('Will keine Post', $erika['note']);
        $this->assertSame($this->caretaker, $erika['caretaker']['id']);
        $theo = $list[3];
        $this->assertSame('2026-11-01', $theo['payments'][0]['paid_on'], 'IBAN ist kein Datum: das gewählte Datum');
        $this->assertSame('', $theo['note'], 'die IBAN wird nicht übernommen');
        $this->assertSame([], $list[1]['payments']);

        $again = $this->call('POST', '/passive-members/import', ['csv' => self::sheet()])->data;
        $this->assertSame(0, $again['new']);
        $this->assertSame(4, $again['existing'], 'zweiter Import legt niemanden doppelt an');
    }

    public function testDuplicatesUnknownMethodsAndRights(): void
    {
        $csv = "Vorname;Nachname;Zahlungsart;Betrag\nErika;Muster;Scheck;25\nerika;muster;;\nMax;Mann;bar;\n";
        $d = $this->call('POST', '/passive-members/import', ['csv' => $csv])->data;
        $this->assertStringContainsString('nicht eingerichtet', $d['rows'][0]['error']);
        $this->assertStringContainsString('Zeile 2', $d['rows'][1]['error']);
        $this->assertStringContainsString('kein Betrag', $d['rows'][2]['error'], 'ohne Jahresbeitrag kein Betrag');
        $this->call('PATCH', '/passive-members/settings', ['fee_cents' => 2500]);
        $d = $this->call('POST', '/passive-members/import', ['csv' => $csv])->data;
        $this->assertSame(['year' => 2026, 'amount_cents' => 2500, 'paid_on' => '2026-11-17', 'method' => 'Bar'], $d['rows'][2]['payment'], 'Jahresbeitrag als Betrag');

        // Ohne manage_personal werden persönliche Spalten übergangen; ohne manage_passive kein Import
        $key = $this->app->roles()->create(['label' => 'Kasse', 'capabilities' => [...Roles::TEMPLATES[Roles::MEMBER], 'manage_passive']])['key'];
        $this->users->current = $this->users->add('kasse', $key);
        $d = $this->call('POST', '/passive-members/import', ['csv' => "Vorname;Nachname;Telefon\nErika;Muster;01234 5678"])->data;
        $this->assertSame('Telefon', $d['ignored'][0]['column']);
        $this->assertArrayNotHasKey('phone', $d['rows'][0]);
        $this->users->current = $this->caretaker;
        $this->assertSame(403, $this->call('POST', '/passive-members/import', ['csv' => "Vorname;Nachname\nA;B"])->status);
    }

    public function testManualMappingIsChecked(): void
    {
        $csv = "Spalte A;Spalte B\nErika;Muster";
        $res = $this->call('POST', '/passive-members/import', ['csv' => $csv]);
        $this->assertSame(400, $res->status, 'ohne Namensspalte erst zuordnen');
        $res = $this->call('POST', '/passive-members/import', ['csv' => $csv, 'columns' => ['first_name' => 0, 'last_name' => 1]]);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertSame('Erika Muster', $res->data['rows'][0]['name']);
        $this->assertSame(400, $this->call('POST', '/passive-members/import', ['csv' => $csv, 'columns' => ['first_name' => 0, 'last_name' => 0]])->status);
        $this->assertSame(400, $this->call('POST', '/passive-members/import', ['csv' => $csv, 'columns' => ['first_name' => 5]])->status);
        $this->assertSame(400, $this->call('POST', '/passive-members/import', ['csv' => $csv, 'columns' => ['iban' => 1]])->status);
    }
}
