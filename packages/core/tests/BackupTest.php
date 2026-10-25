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

    /** write(): gleiches Ergebnis wie dump() und dumpFiles(), Portionen ohne Lücken oder Doppelte, Kopf und Ende an ihrem Platz. */
    public function testStreamedBackupEqualsTheFullOne(): void
    {
        $db = $this->app->db;
        // Mehr als eine Portion (200 Zeilen) in einer Tabelle mit zusammengesetztem Schlüssel, deren erste Spalte sich wiederholt
        for ($u = 1; $u <= 90; $u++) {
            foreach (['r1', 'r2', 'r3'] as $role) {
                $db->insert('member_roles', ['user_id' => $u, 'role' => $role, 'assigned_at' => '2026-10-25 12:00:00']);
            }
        }
        // Tabelle mit großen Zeilen (zeilenweise gelesen)
        for ($i = 1; $i <= 3; $i++) {
            $this->app->richImages()->repository()->insert(str_repeat((string) $i, 32), 'board', $i, 1, 'image/png', 1, 1, str_repeat("\x89PNG-$i", 1000), '2026-10-25 12:00:00');
        }
        // Dateien: Dokument (Größe kein Vielfaches von 3 und über einem Block), Abzeichen, Anhang, Entwurf (nicht gesichert)
        $doc = random_bytes(3 * 1024 * 1024 + 2);
        $this->put('dokumente/dok-aa.pdf', $doc);
        $db->insert('documents', ['title' => 'Satzung', 'category_id' => null, 'file' => 'dok-aa.pdf', 'ext' => 'pdf', 'size' => strlen($doc), 'version' => 'v1', 'visibility' => 'members', 'doc_date' => '2026-10-25', 'uploaded_by' => 1, 'created_at' => '2026-10-25 12:00:00', 'updated_at' => '2026-10-25 12:00:00']);
        $this->put('abzeichen/abz-bb.svg', '<svg/>');
        $db->insert('ranks', ['title' => 'Gefreiter', 'sort' => 1, 'badge_file' => 'abz-bb']);
        foreach (['board' => 'a', 'draft' => 'b'] as $owner => $c) {
            $uid = str_repeat($c, 32);
            $this->put('anhaenge/anh-' . $uid . '.bin', "Anhang $owner");
            $db->insert('attachments', ['uid' => $uid, 'owner_type' => $owner, 'owner_id' => 1, 'user_id' => 1, 'name' => "$owner.txt", 'mime' => 'text/plain', 'bytes' => 10, 'file' => 'anh-' . $uid . '.bin', 'created_at' => '2026-10-25 12:00:00']);
        }

        $backup = new Backup($db);
        $out = fopen('php://temp', 'w+b');
        $backup->write($out, Backup::CORE_TABLES, $this->app->files, ['format' => Backup::FORMAT, 'formatVersion' => Backup::FORMAT_VERSION], ['settings' => ['a' => 1]]);
        rewind($out);
        $json = (string) stream_get_contents($out);
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $tables = $backup->dump(Backup::CORE_TABLES);
        $this->assertSame(['format', 'formatVersion', 'tables', 'files', 'settings'], array_keys($data));
        $this->assertSame($tables, $data['tables'], 'dieselben Zeilen wie dump()');
        $this->assertCount(270, $data['tables']['member_roles'], 'über Portionsgrenzen ohne Lücken oder Doppelte');
        $this->assertSame(Backup::dumpFiles($this->app->files, $tables), $data['files'], 'dieselben Dateien wie dumpFiles()');
        $this->assertSame(['abzeichen/abz-bb.svg', 'anhaenge/anh-' . str_repeat('a', 32) . '.bin', 'dokumente/dok-aa.pdf'], array_keys($data['files']), 'Anhänge gespeicherter Inhalte, keine Entwürfe');
        $this->assertSame($doc, base64_decode($data['files']['dokumente/dok-aa.pdf'], true), 'Base64 in Blöcken = Base64 der Datei');
        $this->assertSame(['a' => 1], $data['settings']);
        $this->assertSame(Backup::parse($json)['tables'], $tables, 'Backup::parse nimmt die Sicherung an');
    }

    /** write() braucht kaum mehr Arbeitsspeicher als eine große Zeile bzw. ein Block, unabhängig von der Größe der Sicherung. */
    public function testStreamedBackupNeedsLittleMemory(): void
    {
        if (!function_exists('memory_reset_peak_usage')) {
            $this->markTestSkipped('memory_reset_peak_usage gibt es erst ab PHP 8.2.');
        }
        $db = $this->app->db;
        for ($i = 0; $i < 30; $i++) {
            $this->app->richImages()->repository()->insert(sprintf('%032x', $i), 'board', $i, 1, 'image/png', 1, 1, random_bytes(1024 * 1024), '2026-10-25 12:00:00');
        }
        $file = tempnam(sys_get_temp_dir(), 'sicherung');
        $out = fopen($file, 'wb');
        gc_collect_cycles();
        $before = memory_get_usage();
        memory_reset_peak_usage();
        (new Backup($db))->write($out, Backup::CORE_TABLES, $this->app->files, ['format' => Backup::FORMAT], []);
        $peak = memory_get_peak_usage() - $before;
        fclose($out);
        $size = (int) filesize($file);
        @unlink($file);
        $this->assertGreaterThan(40 * 1024 * 1024, $size, 'Sicherung mit 30 Bildern zu je 1 MB (Base64)');
        $this->assertLessThan(12 * 1024 * 1024, $peak, sprintf('Arbeitsspeicher: %.1f MB für eine Sicherung von %.1f MB', $peak / 1048576, $size / 1048576));
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
