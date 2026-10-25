<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Domain\GalleryService;
use KompanieApp\Core\Http\Api;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Route;
use KompanieApp\Core\Storage\Backup;
use KompanieApp\Core\Telemetry\Code;
use KompanieApp\Core\Telemetry\Detail;
use KompanieApp\Core\Telemetry\Telemetry;
use KompanieApp\Core\Tests\Support\DriveMock;
use KompanieApp\Core\Tests\Support\FakeHttpClient;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\MemorySettings;
use KompanieApp\Core\Tests\Support\NullScheduler;
use KompanieApp\Core\Tests\Support\TestApp;
use KompanieApp\Core\Update\StatusReporter;
use PHPUnit\Framework\TestCase;

/** Statusmeldungen Format 2 (Issue #20): Inhalt, Zustimmung, Fehlerzähler, Drosselung, Signatur. */
final class StatusReportTest extends TestCase
{
    private const URL = 'https://status.example.org/';

    private FakeHttpClient $http;
    private FixedClock $clock;
    private MemorySettings $settings;
    private NullScheduler $scheduler;
    /** @var array<int, array{status: int, body: string}> Antworten des Empfängers, der Reihe nach */
    private array $responses = [];

    protected function setUp(): void
    {
        $this->clock = new FixedClock(new \DateTimeImmutable('2026-10-01 12:00:00 UTC'));
        $this->settings = new MemorySettings();
        $this->scheduler = new NullScheduler();
        $this->http = new FakeHttpClient(fn () => array_shift($this->responses) ?? ['status' => 201, 'body' => '{}']);
    }

    private function app(bool $consent = true): App
    {
        $app = TestApp::create(TestApp::connection(), http: $this->http, settings: $this->settings, clock: $this->clock,
            packageConfig: ['statusUrl' => self::URL, 'kompanie' => 'org/kompanie-test'], scheduler: $this->scheduler);
        $app->settings->set(StatusReporter::SETTING, $consent);
        $app->scheduleTasks();
        return $app;
    }

    /** @return array<int, array<string, mixed>> gesendete Meldungen */
    private function sent(): array
    {
        $out = [];
        foreach ($this->http->calls as $c) {
            if ($c['url'] === self::URL) {
                $out[] = json_decode((string) $c['body'], true) + ['_signature' => $c['headers'][StatusReporter::HEADER] ?? ''];
            }
        }
        return $out;
    }

    private function later(int $seconds): void
    {
        $this->clock->at = $this->clock->now()->modify("+$seconds seconds");
    }

    public function testHeartbeatContainsOnlyAllowedFieldsWithoutTexts(): void
    {
        $app = $this->app();
        $app->runTask(StatusReporter::TASK);
        $sent = $this->sent();
        $this->assertCount(1, $sent);
        $hb = $sent[0];
        $allowed = ['format', 'installation', 'kompanie', 'version', 'variant', 'php', 'database', 'kind', 'checks', 'errors', 'details', 'cron',
            'features', 'usage', 'update', 'updateCheckOk', 'gallery', 'maintenance', 'sent', 'nonce', 'key', '_signature'];
        $this->assertSame([], array_diff(array_keys($hb), $allowed), 'nur Felder der Positivliste');
        $this->assertSame(3, $hb['format']);
        $this->assertSame('heartbeat', $hb['kind']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $hb['installation']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $hb['nonce']);
        $this->assertSame('org/kompanie-test', $hb['kompanie']);
        $this->assertNotSame([], $hb['checks']);
        foreach ($hb['checks'] as $key => $level) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $key);
            $this->assertContains($level, ['ok', 'warn', 'error', 'off'], 'nur Stufen, keine Texte');
        }
        $this->assertSame(['kind' => 'real', 'last' => $this->clock->now()->getTimestamp()], $hb['cron']);
        $this->assertContains('points', $hb['features']);
        $this->assertSame([], $hb['errors']);
        $this->assertSame([], $hb['details']);
        $this->assertStringContainsString('"details":{}', (string) $this->http->calls[0]['body'], 'leer als Objekt, nicht als Liste');
    }

    public function testCausesAreSentButNeverTexts(): void
    {
        $app = $this->app();
        $t = $app->telemetry();
        $t->record(Code::CalendarFetch, 'ics_http_404', 'Der Kalender-Link von max@example.org lieferte HTTP 404.');
        $t->record(Code::CalendarFetch, 'ics_http_404');
        $t->record(Code::CalendarFetch, 'ICS Timeout');
        $t->record(Code::GallerySync);
        $t->record(Code::UpdateFailed, 'download_http_503', 'Update auf 1.0.0.2 für max@example.org gescheitert');
        $this->assertSame(['CALENDAR_FETCH' => ['ics_http_404' => 2, 'ics_timeout' => 1], 'UPDATE_FAILED' => ['download_http_503' => 1]], $t->details());
        $this->assertSame(['UPDATE_FAILED' => 'download_http_503'], $t->pendingDetails());

        $app->runTask(StatusReporter::TASK);
        [$event, $hb] = $this->sent();
        $this->assertSame(['UPDATE_FAILED', 'download_http_503'], [$event['event'], $event['detail']]);
        $this->assertArrayNotHasKey('detail', $hb);
        $this->assertSame(['CALENDAR_FETCH' => 3, 'GALLERY_SYNC' => 1, 'UPDATE_FAILED' => 1], $hb['errors']);
        $this->assertSame(['CALENDAR_FETCH' => ['ics_http_404' => 2, 'ics_timeout' => 1], 'UPDATE_FAILED' => ['download_http_503' => 1]], $hb['details']);
        foreach ($this->http->calls as $c) {
            $this->assertStringNotContainsString('example.org', (string) $c['body'], 'keine Fehlertexte');
        }
        $this->later(3600 * 25);
        $this->assertSame([], $t->details(), 'älter als 24 Stunden: verworfen');
    }

    public function testCausesAreLimitedPerCodeAndHour(): void
    {
        $t = $this->app()->telemetry();
        for ($i = 1; $i <= 12; $i++) {
            $t->record(Code::ServerError, 'route_' . $i);
        }
        $details = $t->details()['SERVER_ERROR'];
        $this->assertCount(11, $details);
        $this->assertSame(2, $details['other']);
        $t->record(Code::ServerError, 'route_1');
        $this->assertSame(2, $t->details()['SERVER_ERROR']['route_1'], 'bekannte Ursachen zählen weiter');
        // Von Hand verfälschte Einstellung: nur gültige Ursachen
        $hour = (string) ($this->clock->now()->getTimestamp() - 60);
        $this->settings->set(Telemetry::DETAILS_SETTING, [$hour => ['SERVER_ERROR' => ['max@example.org' => 1, 'ok' => 1], 'UNBEKANNT' => ['x' => 1]]]);
        $this->assertSame(['SERVER_ERROR' => ['ok' => 1]], $t->details());
        $this->settings->set(Telemetry::PENDING_SETTING, ['DB_ERROR:max@example.org', 'PHP_FATAL', 'UNBEKANNT:x']);
        $this->assertSame(['DB_ERROR' => '', 'PHP_FATAL' => ''], $t->pendingDetails());
    }

    public function testDetailIsBuiltOnlyFromFixedParts(): void
    {
        $this->assertSame('typeerror_get_events_id', Detail::of('TypeError', 'GET', '/events/{id}'));
        $this->assertSame('sqlstate_42s02_get_points', Detail::of('sqlstate_42S02', 'GET', '/points'));
        $this->assertSame('n_404', Detail::of('404'));
        $this->assertSame('ics', Detail::of('ics', ''));
        $this->assertSame('', Detail::of('', '—'));
        $this->assertSame(64, strlen(Detail::of(str_repeat('a', 100))));
        $this->assertSame('http_404', Detail::fromMessage('Der Kalender-Link lieferte HTTP 404.'));
        $this->assertSame('http_403', Detail::fromMessage('Google Kalender: Forbidden (HTTP 403) – Ist der Kalender freigegeben?'));
        $this->assertSame('timeout', Detail::fromMessage('cURL error 28: Operation timed out after 15001 milliseconds'));
        $this->assertSame('dns', Detail::fromMessage('cURL error 6: Could not resolve host: calendar.example.org'));
        $this->assertSame('tls', Detail::fromMessage('cURL error 60: SSL certificate problem'));
        $this->assertSame('connect', Detail::fromMessage('cURL error 7: Failed to connect to host port 443'));
        $this->assertSame('', Detail::fromMessage('Der Ordner von max@example.org fehlt'));
        foreach (['typeerror_get_events_id', 'n_404', 'http_404'] as $d) {
            $this->assertMatchesRegularExpression(Detail::PATTERN, $d);
        }
    }

    public function testServerErrorsCarryClassAndRoute(): void
    {
        $app = $this->app();
        $api = new Api($app);
        $fail = static fn () => throw new \TypeError('Argument für max@example.org');
        $res = $api->dispatch(new Route('GET', '/events/{id}', Route::PUBLIC, $fail), new Request('GET', '/events/5'));
        $this->assertSame(500, $res->status);
        $res = $api->dispatch(new Route('POST', '/points', Route::PUBLIC, static fn () => throw new \PDOException('kaputt')), new Request('POST', '/points'));
        $this->assertSame(500, $res->status);
        $this->assertSame(['DB_ERROR' => ['sqlstate_0_post_points' => 1], 'SERVER_ERROR' => ['typeerror_get_events_id' => 1]], $app->telemetry()->details());
        $log = $app->telemetry()->log();
        $this->assertSame('DB_ERROR', $log[0]['code']);
        $this->assertSame('SERVER_ERROR', $log[1]['code']);
        $this->assertStringStartsWith('TypeError: Argument für max@example.org (StatusReportTest.php:', $log[1]['message'], 'Text nur im Protokoll, Datei ohne Verzeichnis');
    }

    public function testErrorLogKeepsTextsOnTheServerEvenWithoutConsent(): void
    {
        $app = $this->app(false);
        $t = $app->telemetry();
        $t->record(Code::CalendarFetch, 'ics_timeout', "Zeitüberschreitung bei https://user:pw@calendar.google.com/calendar/ical/x%40group/private-geheim/basic.ics?key=abc\nin /var/www/html/app/core/src/Http/Api.php:85");
        $t->record(Code::CalendarFetch, 'ics_timeout', "Zeitüberschreitung bei https://user:pw@calendar.google.com/calendar/ical/x%40group/private-geheim/basic.ics?key=abc\nin /var/www/html/app/core/src/Http/Api.php:85");
        $this->assertSame([], $t->errors(), 'ohne Zustimmung nichts gezählt');
        $log = $t->log();
        $this->assertCount(1, $log, 'derselbe Fehler direkt hintereinander: zusammengefasst');
        $this->assertSame(2, $log[0]['count']);
        $this->assertSame('ics_timeout', $log[0]['detail']);
        $this->assertSame('Zeitüberschreitung bei https://calendar.google.com/… in Api.php:85', $log[0]['message'], 'Adresse nur bis zum Server, Datei ohne Verzeichnis');

        $this->later(60);
        $t->record(Code::GallerySync, '', str_repeat('x', 2000));
        $this->assertSame(['GALLERY_SYNC', 'CALENDAR_FETCH'], array_column($t->log(), 'code'), 'neueste zuerst');
        $this->assertSame(1000, mb_strlen($t->log()[0]['message']));
        for ($i = 0; $i < 60; $i++) {
            $t->record(Code::ServerError, '', 'Fehler ' . $i);
        }
        $this->assertCount(50, $t->log());
        $this->assertSame('Fehler 59', $t->log()[0]['message']);

        // Widerruf der Zustimmung lässt das Protokoll stehen; nach 14 Tagen ist es weg.
        $t->forget();
        $this->assertCount(50, $t->log());
        $this->later(14 * 86400);
        $this->assertSame([], $t->log());
        $t->record(Code::DiskLow);
        $t->clearLog();
        $this->assertNull($this->settings->get(Telemetry::LOG_SETTING));
        $this->assertSame([], $this->sent());
    }

    public function testNothingWithoutConsent(): void
    {
        $app = $this->app(false);
        $app->telemetry()->record(Code::UpdateFailed);
        $app->runTask(StatusReporter::TASK);
        $this->assertSame([], $this->sent());
        $this->assertSame([], $app->telemetry()->errors(), 'ohne Zustimmung wird auch nicht gezählt');
        $this->assertSame([], $app->telemetry()->pending());
        $this->assertSame([], $this->scheduler->soon);
    }

    public function testErrorsAreCountedForTwentyFourHours(): void
    {
        $app = $this->app();
        $t = $app->telemetry();
        $t->record(Code::CalendarFetch);
        $t->record(Code::CalendarFetch);
        $this->later(3600 * 5);
        $t->record(Code::GallerySync);
        $this->assertSame(['CALENDAR_FETCH' => 2, 'GALLERY_SYNC' => 1], $t->errors());
        $this->assertSame([], $t->pending(), 'leichte Fehler werden nur gezählt');
        $this->later(3600 * 20);
        $this->assertSame(['GALLERY_SYNC' => 1], $t->errors(), 'älter als 24 Stunden: verworfen');
        // Unbekannte Codes in der Einstellung (z. B. von Hand) werden nie gesendet.
        $this->settings->set(Telemetry::ERRORS_SETTING, [(string) ($this->clock->now()->getTimestamp() - 60) => ['Fehler mit max@example.org' => 3]]);
        $this->assertSame([], $t->errors());
    }

    public function testSevereErrorsAreSentAtOnceButThrottledPerCode(): void
    {
        $app = $this->app();
        $t = $app->telemetry();
        $t->record(Code::UpdateFailed);
        $t->record(Code::UpdateFailed);
        $t->record(Code::DbError);
        $this->assertSame([Code::UpdateFailed, Code::DbError], $t->pending());
        $this->assertSame([StatusReporter::TASK, StatusReporter::TASK], $this->scheduler->soon, 'je Code einmal vorgezogen');
        $this->assertSame(['DB_ERROR' => 1, 'UPDATE_FAILED' => 2], $t->errors(), 'gezählt wird trotzdem jeder');

        $app->runTask(StatusReporter::TASK);
        $kinds = array_map(static fn (array $r): string => $r['kind'] . ':' . ($r['event'] ?? ''), $this->sent());
        $this->assertSame(['event:UPDATE_FAILED', 'event:DB_ERROR', 'heartbeat:'], $kinds);
        $this->assertSame([], $t->pending());

        // Innerhalb der Stunde: derselbe Code löst kein weiteres Ereignis aus.
        $this->later(1800);
        $t->record(Code::UpdateFailed);
        $this->assertSame([], $t->pending());
        // Ein vorgezogener Lauf sendet auch ein Lebenszeichen, außer kurz nach dem letzten.
        $t->record(Code::PhpFatal);
        $app->runTask(StatusReporter::TASK);
        $this->assertSame(['event', 'heartbeat'], array_column(array_slice($this->sent(), 3), 'kind'));
        $this->later(300);
        $t->record(Code::DiskLow);
        $app->runTask(StatusReporter::TASK);
        $this->assertSame(['event'], array_column(array_slice($this->sent(), 5), 'kind'), 'kein zweites Lebenszeichen nach 5 Minuten');
        // Nach einer Stunde wieder.
        $this->later(3600);
        $t->record(Code::UpdateFailed);
        $this->assertSame([Code::UpdateFailed], $t->pending());
    }

    public function testSignatureAndTrustOnFirstUse(): void
    {
        $app = $this->app();
        $app->runTask(StatusReporter::TASK);
        $first = $this->http->calls[array_key_last($this->http->calls)];
        $secret = (string) $this->settings->get(StatusReporter::SECRET_SETTING);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret);
        $this->assertSame(StatusReporter::sign((string) $first['body'], $secret), $first['headers'][StatusReporter::HEADER]);
        $this->assertSame($secret, json_decode((string) $first['body'], true)['key'], 'erste Meldung bringt das Geheimnis mit');

        // Bestätigt: danach nie wieder mitgesendet.
        $this->later(3600);
        $app->runTask(StatusReporter::TASK);
        $second = json_decode((string) $this->http->calls[array_key_last($this->http->calls)]['body'], true);
        $this->assertArrayNotHasKey('key', $second);

        // Das Cockpit kennt die Installation nicht mehr: beim nächsten Mal wieder mit Geheimnis.
        $this->later(3600);
        $this->responses = [['status' => 409, 'body' => '{"code":"unknown_installation"}']];
        $app->runTask(StatusReporter::TASK);
        $this->assertFalse($app->statusReporter()->last()['ok']);
        $this->later(3600);
        $app->runTask(StatusReporter::TASK);
        $this->assertArrayHasKey('key', json_decode((string) $this->http->calls[array_key_last($this->http->calls)]['body'], true));

        // Abgelehnt aus anderem Grund: Geheimnis nicht erneut senden.
        $this->later(3600);
        $this->responses = [['status' => 401, 'body' => '{"code":"bad_signature"}']];
        $app->runTask(StatusReporter::TASK);
        $this->later(3600);
        $app->runTask(StatusReporter::TASK);
        $this->assertArrayNotHasKey('key', json_decode((string) $this->http->calls[array_key_last($this->http->calls)]['body'], true));
    }

    public function testFailedSendIsRetriedNextRunAndEventsKept(): void
    {
        $app = $this->app();
        $app->telemetry()->record(Code::Rollback);
        $this->responses = [['status' => 503, 'body' => '']];
        $app->runTask(StatusReporter::TASK); // wirft nicht (App::runTask protokolliert)
        $this->assertSame([Code::Rollback], $app->telemetry()->pending(), 'Ereignis bleibt für den nächsten Lauf');
        $this->assertSame([], $app->telemetry()->errors()['TASK_FAILED'] ?? [], 'das eigene Scheitern wird nicht gezählt');
        $this->later(3600);
        $app->runTask(StatusReporter::TASK);
        $this->assertSame([], $app->telemetry()->pending());
        $this->assertTrue($app->statusReporter()->last()['ok']);
    }

    public function testRejectedEventDoesNotBlockHeartbeat(): void
    {
        $app = $this->app();
        $app->telemetry()->record(Code::DbError);
        $app->telemetry()->record(Code::PhpFatal);
        // Das erste Ereignis lehnt das Cockpit endgültig ab, das zweite scheitert vorübergehend.
        $this->responses = [['status' => 400, 'body' => '{"code":"invalid"}'], ['status' => 503, 'body' => '']];
        $app->runTask(StatusReporter::TASK);
        $this->assertSame(['event', 'event', 'heartbeat'], array_column($this->sent(), 'kind'), 'Lebenszeichen trotzdem gesendet');
        $this->assertSame([Code::PhpFatal], $app->telemetry()->pending(), 'nur das vorübergehend gescheiterte bleibt');
    }

    public function testEventsQueuedDuringSendAreKept(): void
    {
        $app = $this->app();
        $t = $app->telemetry();
        $t->record(Code::DbError);
        // Während das erste Ereignis unterwegs ist, reiht eine andere Anfrage ein weiteres ein.
        $this->http->handler = function () use ($t) {
            static $once = false;
            if (!$once) {
                $once = true;
                $t->record(Code::PhpFatal);
            }
            return ['status' => 201, 'body' => '{}'];
        };
        $app->runTask(StatusReporter::TASK);
        $this->assertSame([Code::PhpFatal], $t->pending(), 'nicht überschrieben');
    }

    public function testStatusUrlMustBeHttps(): void
    {
        foreach (['http://status.example.org/', 'http://localhost:1@evil.example/', 'https://user:pw@status.example.org/', 'ftp://x/'] as $url) {
            $app = TestApp::create(TestApp::connection(), settings: new MemorySettings(), packageConfig: ['statusUrl' => $url]);
            $app->settings->set(StatusReporter::SETTING, true);
            $this->assertFalse($app->statusReporter()->enabled(), $url);
        }
        $app = TestApp::create(TestApp::connection(), settings: new MemorySettings(), packageConfig: ['statusUrl' => 'http://127.0.0.1:8080/s.php']);
        $app->settings->set(StatusReporter::SETTING, true);
        $this->assertTrue($app->statusReporter()->enabled(), 'für Tests: http auf dem eigenen Rechner');
    }

    public function testCheckTurningRedIsAnEvent(): void
    {
        $app = $this->app();
        // Galerie eingerichtet und in Ordnung
        $app->drive()->saveServiceAccount((new DriveMock())->serviceAccountJson());
        $this->settings->set(GalleryService::ROOT_SETTING, 'ordner');
        $this->settings->set(GalleryService::STATUS_SETTING, ['last_sync' => $this->clock->now()->getTimestamp(), 'error' => '']);
        $app->runTask(StatusReporter::TASK);
        $this->assertSame('ok', $this->sent()[0]['checks']['gallery']);
        // Abgleich fehlgeschlagen → Ampel rot → sofort ein Ereignis, der Text bleibt draußen
        $this->settings->set(GalleryService::STATUS_SETTING, ['last_sync' => $this->clock->now()->getTimestamp(), 'error' => 'Ordner von max@example.org fehlt']);
        $this->later(3600);
        $app->runTask(StatusReporter::TASK);
        $events = array_column($this->sent(), 'event');
        $this->assertSame(['CHECK_ERROR'], $events);
        $this->assertSame('gallery', $this->sent()[1]['detail'], 'Ursache: welche Ampel');
        $this->assertSame(['CHECK_ERROR', 'gallery'], [$app->telemetry()->log()[0]['code'], $app->telemetry()->log()[0]['detail']]);
        $this->assertStringContainsString('example.org fehlt', $app->telemetry()->log()[0]['message'], 'der Text steht nur im Protokoll');
        $this->assertSame('error', $this->sent()[2]['checks']['gallery']);
        foreach ($this->http->calls as $c) {
            $this->assertStringNotContainsString('example.org fehlt', (string) $c['body'], 'keine Fehlertexte');
        }
        // Bleibt sie rot, kein neues Ereignis
        $this->later(3600);
        $app->runTask(StatusReporter::TASK);
        $this->assertSame(['CHECK_ERROR'], array_column($this->sent(), 'event'));
    }

    public function testRevokingConsentForgetsCounters(): void
    {
        $app = $this->app();
        $app->telemetry()->record(Code::DbError);
        $app->telemetry()->forget();
        $this->assertNull($this->settings->get(Telemetry::ERRORS_SETTING));
        $this->assertNull($this->settings->get(Telemetry::PENDING_SETTING));
    }

    public function testInstallationSettingsStayOutOfBackups(): void
    {
        foreach ([StatusReporter::ID_SETTING, StatusReporter::SECRET_SETTING, StatusReporter::REGISTERED_SETTING, StatusReporter::LAST_SETTING,
            StatusReporter::HEARTBEAT_SETTING, StatusReporter::CHECKS_SETTING, Telemetry::ERRORS_SETTING, Telemetry::DETAILS_SETTING,
            Telemetry::PENDING_SETTING, Telemetry::EVENTS_SETTING, Telemetry::LOG_SETTING, App::TASK_RUN_SETTING] as $key) {
            $this->assertContains($key, Backup::LOCAL_SETTINGS, $key);
        }
    }
}
