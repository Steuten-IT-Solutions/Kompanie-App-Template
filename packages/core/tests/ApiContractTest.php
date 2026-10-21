<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Contract\ApiScenario;
use KompanieApp\Contract\Checks;
use KompanieApp\Core\Tests\Support\CoreDriver;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Das gemeinsame API-Szenario gegen den Kern, mit beiden Rollenmodellen und beiden Datenbanken. */
final class ApiContractTest extends TestCase
{
    public static function variants(): array
    {
        $out = [
            'sqlite, Admins ernennbar' => ['sqlite', true],
            'sqlite, Admins nur extern' => ['sqlite', false],
        ];
        if (getenv('KOMPANIE_TEST_MYSQL')) {
            $out['mysql, Admins ernennbar'] = ['mysql', true];
        }
        return $out;
    }

    #[DataProvider('variants')]
    public function testScenario(string $driver, bool $adminsAssignable): void
    {
        $db = TestApp::connection($driver);
        try {
            $users = new FakeUsers($adminsAssignable);
            // Mit Empfänger der Fehlermeldungen (Issue #64); die eigenständige Version prüft den Fall ohne.
            $app = TestApp::create($db, $users, packageConfig: ['reportUrl' => 'https://status.example.org/reports', 'kompanie' => 'org/kompanie-test']);
            $checks = new Checks();
            (new ApiScenario(new CoreDriver($app, $users), $checks))->run();
            $this->assertSame([], $checks->failed, 'Fehlgeschlagen: ' . implode('; ', $checks->failed));
            $this->assertGreaterThan(80, $checks->passed);
        } finally {
            TestApp::dropTables($db);
        }
    }
}
