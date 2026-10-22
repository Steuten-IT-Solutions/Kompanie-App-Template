<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Push\PushService;
use KompanieApp\Core\Storage\Backup;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** Datensicherung im Kern: Dateien der Dokumente und Abzeichen, Push-Abos. */
final class BackupTest extends TestCase
{
    private App $app;

    protected function setUp(): void
    {
        $this->app = TestApp::create(TestApp::connection());
    }

    private function put(string $path, string $content): void
    {
        file_put_contents($this->app->files->path($path, false), $content);
    }

    public function testFilesOfDocumentsAndBadgesAreIncluded(): void
    {
        $tables = [
            'documents' => [['file' => 'dok-aa.pdf']],
            'ranks' => [['badge_file' => 'abz-bb'], ['badge_file' => '']],
        ];
        $this->put('dokumente/dok-aa.pdf', '%PDF-1.4 Inhalt');
        $this->put('dokumente/dok-verwaist.pdf', '%PDF alt');
        $this->put('abzeichen/abz-bb-64.png', 'PNG64');
        $this->put('abzeichen/abz-bb.svg', '<svg/>');
        $this->put('abzeichen/abz-bbx-64.png', 'fremd');
        $files = Backup::dumpFiles($this->app->files, $tables);
        $this->assertSame(['abzeichen/abz-bb-64.png', 'abzeichen/abz-bb.svg', 'dokumente/dok-aa.pdf'], array_keys($files), 'nur Dateien, auf die eine Zeile verweist; kein index.php');
        $this->assertSame('%PDF-1.4 Inhalt', base64_decode($files['dokumente/dok-aa.pdf']));

        // Auf einem anderen Server (leere Ablage) wiederherstellen.
        $other = TestApp::create(TestApp::connection());
        Backup::restoreFiles($other->files, Backup::parseFiles(json_decode((string) json_encode($files), true)));
        $this->assertSame('%PDF-1.4 Inhalt', file_get_contents($other->files->path('dokumente/dok-aa.pdf', false)));
        $this->assertSame('<svg/>', file_get_contents($other->files->path('abzeichen/abz-bb.svg', false)));
        $this->assertSame([], Backup::parseFiles(null), 'ältere Sicherung ohne Dateien');
    }

    /** @return array<string, array{0: mixed}> */
    public static function brokenFiles(): array
    {
        return [
            'Pfad nach oben' => [['dokumente/../../config.php' => base64_encode('x')]],
            'fremder Ordner' => [['sicherungen/a.json' => base64_encode('x')]],
            'Unterordner' => [['dokumente/a/b.pdf' => base64_encode('x')]],
            'versteckte Datei' => [['dokumente/.htaccess' => base64_encode('x')]],
            'Ordnerschutz' => [['abzeichen/index.php' => base64_encode('<?php')]],
            'kein Base64' => [['dokumente/a.pdf' => '%%%']],
            'keine Liste' => ['dokumente'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('brokenFiles')]
    public function testBrokenFilesAreRejected(mixed $files): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('beschädigt (Dateien)');
        Backup::parseFiles($files);
    }

    private function subscription(string $endpoint): array
    {
        return ['user_id' => 1, 'endpoint' => $endpoint, 'endpoint_hash' => hash('sha256', $endpoint), 'p256dh' => 'p', 'auth' => 'a', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'];
    }

    public function testPushSubscriptionsAndKeyAreBackedUp(): void
    {
        $this->assertContains('push_subscriptions', Backup::CORE_TABLES);
        $this->assertNotContains('push_queue', Backup::CORE_TABLES);
        $this->assertNotContains(PushService::VAPID_SETTING, Backup::LOCAL_SETTINGS, 'Schlüssel kommt mit, sonst passen die Abos nicht');
        $backup = new Backup($this->app->db);
        $this->app->db->insert('push_subscriptions', $this->subscription('https://push.example/alt'));
        $tables = $backup->dump(Backup::CORE_TABLES);

        // Danach ein anderes Gerät und eine wartende Nachricht: Wiederherstellen ersetzt die Abos und leert die Warteschlange.
        $this->app->db->execute('DELETE FROM {push_subscriptions}');
        $sub = $this->app->db->insert('push_subscriptions', $this->subscription('https://push.example/neu'));
        $this->app->db->insert('push_queue', ['subscription_id' => $sub, 'payload' => '{}', 'next_try' => '2026-01-01 00:00:00', 'created_at' => '2026-01-01 00:00:00']);
        $backup->restore($tables, Backup::CORE_TABLES);
        $this->assertSame(['https://push.example/alt'], array_column($this->app->db->select('SELECT endpoint FROM {push_subscriptions}'), 'endpoint'));
        $this->assertSame(0, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {push_queue}'));

        // Ältere Sicherung ohne Push-Abos: Die Geräte bleiben angemeldet.
        unset($tables['push_subscriptions']);
        $backup->restore($tables, Backup::CORE_TABLES);
        $this->assertSame(1, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {push_subscriptions}'));
    }
}
