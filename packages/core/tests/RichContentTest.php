<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\RichImageService;
use KompanieApp\Core\Domain\RichText;
use KompanieApp\Core\Domain\ServerLimits;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Storage\Backup;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** Formatierte Inhalte, Bilder und Signaturen (Issue #172, Teil B). */
final class RichContentTest extends TestCase
{
    private FakeUsers $users;
    private FixedClock $clock;
    private App $app;
    private int $admin;
    private int $m;
    private int $cand;
    private int $guest;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->clock = new FixedClock(new \DateTimeImmutable('2026-10-20 12:00:00', new \DateTimeZone('Europe/Berlin')));
        $this->app = TestApp::create(TestApp::connection(), $this->users, [], null, null, null, $this->clock, ['board' => true]);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->m = $this->users->add('mitglied', Roles::MEMBER);
        $this->cand = $this->users->add('anwaerter', TestApp::candidateRole($this->app));
        $this->guest = $this->users->add('gast', Roles::GUEST);
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

    private static function png(int $w = 4, int $h = 3, bool $alpha = true): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, (int) imagecolorallocatealpha($img, 255, 0, 0, $alpha ? 100 : 0));
        ob_start();
        imagepng($img);
        return (string) ob_get_clean();
    }

    /** JPEG mit einem EXIF-Block (APP1) mit „GPS“-Kennung */
    private static function jpegWithExif(): string
    {
        $img = imagecreatetruecolor(5, 5);
        ob_start();
        imagejpeg($img);
        $jpeg = (string) ob_get_clean();
        $exif = "Exif\0\0GPSLatitude-geheim";
        return "\xFF\xD8" . "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif . substr($jpeg, 2);
    }

    /** @return array{uid: string, url: string, width: int, height: int} */
    private function upload(string $bytes, string $name = 'bild.png'): array
    {
        $res = $this->call('POST', '/rich-images', ['file' => ['name' => $name, 'data' => base64_encode($bytes)]]);
        $this->assertSame(201, $res->status, (string) json_encode($res->data));
        return $res->data;
    }

    public function testSanitizerAllowlistAndXss(): void
    {
        $cases = [
            '<p>a<script>alert(1)</script>b</p>' => '<p>ab</p>',
            '<img src=x onerror=alert(1)>' => '',
            '<p onclick="x()" class="c" id="i">t</p>' => '<p>t</p>',
            '<a href="javascript:alert(1)">x</a>' => 'x',
            '<a href=" JaVaScRiPt:alert(1)">x</a>' => 'x',
            '<a href="data:text/html,<script>">x</a>' => 'x',
            '<a href="http://example.org">unsicher</a>' => 'unsicher',
            '<a href="tel:+49 211 123">t</a>' => '<a href="tel:+49 211 123" target="_blank" rel="noopener noreferrer">t</a>',
            '<iframe src="https://x"></iframe><form><input></form><object></object><svg onload=1><circle/></svg>' => '',
            '<p style="background: url(https://evil/x.png); color: blue">t</p>' => '<p style="color: blue">t</p>',
            '<p style="color: expression(alert(1))">t</p>' => '<p>t</p>',
            '<p style="position: absolute; top: 0; font-size: 14px">t</p>' => '<p style="font-size: 14px">t</p>',
            '<p style="font-family: \\61 rial">t</p>' => '<p>t</p>',
            '<p style="color: red&quot;; onmouseover=&quot;x">t</p>' => '<p>t</p>',
            '<img data-image="../../etc/passwd">' => '',
            '<math><mi xlink:href="javascript:alert(1)">x</mi></math>' => '',
            '<p>&lt;script&gt;</p>' => '<p>&lt;script&gt;</p>',
            '<!-- Kommentar --><p>t</p>' => '<p>t</p>',
            '</div><script>alert(1)</script>' => '',
            '<table><tr><td colspan="2" onclick="x" style="width: 50%">a</td></tr></table>' => '<table><tr><td colspan="2" style="width: 50%">a</td></tr></table>',
        ];
        foreach ($cases as $in => $expected) {
            $out = RichText::sanitize($in);
            $this->assertSame($expected, $out, $in);
            $this->assertSame($out, RichText::sanitize($out), 'stabil: ' . $in);
        }
    }

    public function testPasteFromWordAndOutlookIsCleanedOnTheServer(): void
    {
        // Ausschnitt, wie Word/Outlook (Windows) ihn in die Zwischenablage legt
        $word = <<<'HTML'
            <html xmlns:o="urn:schemas-microsoft-com:office:office"><head><style>p.MsoNormal{margin:0}</style></head><body>
            <!--[if gte mso 9]><xml><o:OfficeDocumentSettings></o:OfficeDocumentSettings></xml><![endif]-->
            <p class="MsoNormal" style="margin-left:0cm;mso-line-height-rule:exactly;font-family:&quot;Calibri&quot;,sans-serif;color:#1F497D"><b>Wichtig:</b> Treffen um 19 Uhr<o:p></o:p></p>
            <p class="MsoListParagraph" style="text-indent:-18.0pt;mso-list:l0 level1 lfo1"><span style="mso-list:Ignore">·<span style="font:7.0pt &quot;Times New Roman&quot;">&nbsp;&nbsp;</span></span>Uniform</p>
            <p class="MsoNormal"><img width="120" height="40" src="file:///C:/Users/x/AppData/Local/Temp/msohtmlclip1/01/clip_image001.png"></p>
            </body></html>
            HTML;
        $out = RichText::sanitize($word);
        $this->assertStringNotContainsString('mso-', $out);
        $this->assertStringNotContainsString('class=', $out);
        $this->assertStringNotContainsString('o:p', $out);
        $this->assertStringNotContainsString('file:', $out, 'lokale Bilder nie übernommen');
        $this->assertStringNotContainsString('OfficeDocumentSettings', $out);
        $this->assertStringContainsString('<b>Wichtig:</b> Treffen um 19 Uhr', $out);
        $this->assertStringContainsString('color: #1F497D', $out, 'Formatierung bleibt');
        $this->assertStringContainsString('font-family: "Calibri",sans-serif', html_entity_decode($out));
        // Gmail / Outlook im Web: Formatierung über span-Styles
        $gmail = '<div dir="ltr"><span style="font-size:large;background-color:rgb(255,255,0)">Gelb</span><br><font color="#ff0000">rot</font></div>';
        $this->assertSame('<div><span style="font-size: large; background-color: rgb(255,255,0)">Gelb</span><br>rot</div>', RichText::sanitize($gmail));
    }

    public function testExcerpt(): void
    {
        $html = '<h2>Termin</h2><p>Am <b>Samstag</b>&nbsp;um 10 Uhr.<br>Treffpunkt: Halle</p><ol start="2"><li>Erstens</li><li>Zweitens</li></ol><p><a href="mailto:info@example.org">Schreib uns</a></p><img data-image="0123456789abcdef0123456789abcdef" alt="Logo"><hr><p>Ende</p>';
        $this->assertSame("Termin\n\nAm Samstag um 10 Uhr.\nTreffpunkt: Halle\n\n2. Erstens\n3. Zweitens\n\nSchreib uns (info@example.org)\n[Logo]\n----------\n\nEnde", RichText::excerpt($html));
        $this->assertTrue(RichText::isEmpty('<p> </p><p><br></p>'));
        $this->assertFalse(RichText::isEmpty('<img data-image="0123456789abcdef0123456789abcdef">'), 'nur ein Bild ist Inhalt');
        $this->assertSame('<p>a &amp; b<br>c</p>', RichText::fromPlain("a & b\nc"));
    }

    public function testServerLimitsAreReadAndExplained(): void
    {
        $this->assertSame(8 * 1024 * 1024, ServerLimits::bytes('8M'));
        $this->assertSame(512 * 1024, ServerLimits::bytes('512K'));
        $this->assertSame(2 * 1024 ** 3, ServerLimits::bytes('2G'));
        $this->assertNull(ServerLimits::bytes('-1'), 'unbegrenzt');
        $this->assertNull(ServerLimits::bytes('0'));
        $this->assertSame('8 MB', ServerLimits::format(8 * 1024 * 1024));
        $this->assertSame('1,5 MB', ServerLimits::format(1536 * 1024));
        $limits = $this->call('GET', '/rich-images/limits')->data;
        $this->assertSame(ServerLimits::postMaxSize(), $limits['post_max_size']);
        $this->assertSame(ServerLimits::memoryLimit(), $limits['memory_limit']);
        $this->assertNull($limits['max_allowed_packet'], 'SQLite hat keine solche Grenze');
        $this->assertSame(401, $this->as(null)->call('GET', '/rich-images/limits')->status);

        // Arbeitsspeicher reicht nicht: verständliche Meldung statt Abbruch
        $before = ini_get('memory_limit');
        $this->as($this->m);
        try {
            ini_set('memory_limit', (string) (memory_get_usage(true) + 4 * 1024 * 1024));
            $res = $this->call('POST', '/rich-images', ['file' => ['name' => 'gross.png', 'data' => base64_encode(self::png(3000, 3000, false))]]);
        } finally {
            ini_set('memory_limit', (string) $before);
        }
        $this->assertSame(400, $res->status);
        $this->assertStringContainsString('3000 × 3000 Pixel', $res->data['message']);
        $this->assertStringContainsString('memory_limit', $res->data['message']);
    }

    public function testImageUploadReencodesWithoutMetadataAndWithoutAppLimits(): void
    {
        $this->as($this->cand);
        $png = $this->upload(self::png(4, 3));
        $this->assertSame([4, 3], [$png['width'], $png['height']]);
        $this->assertSame('https://example.org/app/bild/' . $png['uid'], $png['url']);
        $this->assertMatchesRegularExpression(RichText::IMAGE_UID, $png['uid']);
        $stored = $this->app->richImages()->bytes($png['uid']);
        $this->assertSame('image/png', $stored['mime'] ?? null, 'PNG bleibt PNG (Transparenz)');
        $jpeg = $this->upload(self::jpegWithExif(), 'foto.jpg');
        $bytes = $this->app->richImages()->bytes($jpeg['uid'])['bytes'] ?? '';
        $this->assertStringStartsWith("\xFF\xD8", $bytes);
        $this->assertStringNotContainsString('GPSLatitude', $bytes, 'ohne Metadaten neu kodiert');
        // Größer als die Grenzen der Mitgliederfotos (5000 Pixel): keine eigene Grenze der App
        $big = $this->upload(self::png(5000, 20, false));
        $this->assertSame(5000, $big['width']);

        foreach ([['x.gif', 'GIF89a'], ['x.png', 'kein Bild'], ['x.png', '']] as [$name, $data]) {
            $this->assertSame(400, $this->call('POST', '/rich-images', ['file' => ['name' => $name, 'data' => base64_encode($data)]])->status, $name);
        }
        $this->assertSame(403, $this->as($this->guest)->call('POST', '/rich-images', ['file' => ['name' => 'a.png', 'data' => base64_encode(self::png())]])->status, 'Gäste nicht');
    }

    public function testImageDeliveryRights(): void
    {
        $this->as($this->m);
        $draft = $this->upload(self::png())['uid'];
        $d = fn (?int $u, string $uid): int => $this->app->richImages()->forDelivery($u === null ? null : $this->users->find($u), $uid)['status'];
        $this->assertSame(200, $d($this->m, $draft), 'eigener Entwurf');
        $this->assertSame(403, $d($this->admin, $draft), 'fremder Entwurf');
        $this->assertSame(401, $d(null, $draft));
        $this->assertSame(404, $d($this->m, str_repeat('0', 32)));
        $this->assertSame(404, $d($this->m, '../etc'));

        // Bild in einer Nachricht: wie die Nachricht (Mitglieder und Anwärter), nach dem Löschen nur post_board
        $this->as($this->admin);
        $own = $this->upload(self::png())['uid'];
        $item = $this->call('POST', '/board', ['title' => 'Mit Bild', 'text' => '<p>Hallo</p><img data-image="' . $own . '" width="2">', 'push' => false])->data;
        $this->assertSame('<p>Hallo</p><img src="https://example.org/app/bild/' . $own . '" data-image="' . $own . '" width="2">', $item['text'], 'mit Adresse ausgeliefert');
        $this->assertSame(200, $d($this->m, $own));
        $this->assertSame(200, $d($this->cand, $own));
        $this->assertSame(403, $d($this->guest, $own));
        $res = $this->app->richImages()->forDelivery($this->users->find($this->m), $own);
        $this->assertSame(['image/png', 'nosniff', 'private, max-age=86400'], [$res['headers']['Content-Type'], $res['headers']['X-Content-Type-Options'], $res['headers']['Cache-Control']]);
        $this->call('DELETE', "/board/{$item['id']}");
        $this->assertSame(403, $d($this->m, $own), 'gelöscht: nur noch im Verlauf');
        $this->assertSame(200, $d($this->admin, $own));

        // Fremde Entwürfe lassen sich nicht in eine Nachricht schmuggeln
        $foreign = $this->call('POST', '/board', ['title' => 'Fremd', 'text' => '<p>x</p><img data-image="' . $draft . '">', 'push' => false])->data;
        $this->assertSame('<p>x</p>', $foreign['text']);
    }

    public function testSignatureLifecycleAndInsertingIntoMessages(): void
    {
        $this->as($this->cand);
        $this->assertSame(['signature' => null], $this->call('GET', '/me/signature')->data, 'Anwärter: noch keine');
        $logo = $this->upload(self::png())['uid'];
        $sig = $this->call('PUT', '/me/signature', ['content' => '<table><tr><td><img data-image="' . $logo . '" width="40"></td><td><b>Anna Anwärter</b><br>Telefon <a href="tel:0211123">0211 123</a></td></tr></table><script>x</script>'])->data['signature'];
        $this->assertStringContainsString('<img src="https://example.org/app/bild/' . $logo . '" data-image="' . $logo . '" width="40">', $sig['content']);
        $this->assertStringNotContainsString('script', $sig['content']);
        $this->assertSame('2026-10-20T10:00:00+00:00', $sig['updated_at']);
        $this->assertSame('signature', $this->app->richImages()->repository()->find($logo)['owner_type'] ?? null, 'Bild gehört jetzt der Signatur');
        $this->assertSame(200, $this->app->richImages()->forDelivery($this->users->find($this->cand), $logo)['status']);
        $this->assertSame(403, $this->app->richImages()->forDelivery($this->users->find($this->m), $logo)['status'], 'Signaturen sehen andere erst in einer Nachricht');

        // Am Brett schreibt, wer post_board hat: Er fügt seine Signatur als Kopie ein (die App setzt den Inhalt ein).
        $this->as($this->admin);
        $adminLogo = $this->upload(self::png())['uid'];
        $this->call('PUT', '/me/signature', ['content' => '<p>Gruß, Admin</p><img data-image="' . $adminLogo . '">']);
        $signature = $this->call('GET', '/me/signature')->data['signature']['content'];
        $item = $this->call('POST', '/board', ['title' => 'Mit Signatur', 'text' => '<p>Text</p>' . $signature, 'push' => false])->data;
        $copied = RichText::imageUids($item['text']);
        $this->assertCount(1, $copied);
        $this->assertNotSame($adminLogo, $copied[0], 'Bild der Signatur wurde für die Nachricht kopiert');
        $this->assertSame(['board', $item['id']], [$this->app->richImages()->repository()->find($copied[0])['owner_type'], $this->app->richImages()->repository()->find($copied[0])['owner_id']]);

        // Signatur ändern oder löschen wirkt nicht auf die Nachricht
        $this->call('PUT', '/me/signature', ['content' => '<p>Neue Signatur</p>']);
        $this->assertNull($this->app->richImages()->repository()->find($adminLogo), 'nicht mehr benutztes Bild der Signatur gelöscht');
        $this->call('DELETE', '/me/signature');
        $this->assertSame(['signature' => null], $this->call('GET', '/me/signature')->data);
        $this->assertSame($item['text'], $this->as($this->m)->call('GET', '/board')->data['items'][0]['text'], 'Nachricht unverändert');
        $this->assertSame(200, $this->app->richImages()->forDelivery($this->users->find($this->m), $copied[0])['status']);

        // Leere Signatur = keine; Gäste haben keine
        $this->as($this->m);
        $this->assertSame(['signature' => null], $this->call('PUT', '/me/signature', ['content' => '<p> </p>'])->data);
        $this->assertSame(['signature' => null], $this->as($this->guest)->call('GET', '/me/signature')->data);
        $this->assertSame(403, $this->call('PUT', '/me/signature', ['content' => '<p>x</p>'])->status);
    }

    public function testAccountDeletionAndBackup(): void
    {
        $this->as($this->m);
        $logo = $this->upload(self::png())['uid'];
        $draft = $this->upload(self::png())['uid'];
        $this->call('PUT', '/me/signature', ['content' => '<p>Sig</p><img data-image="' . $logo . '">']);
        $this->call('PUT', '/me/mail', ['board' => false]);
        foreach (['user_signatures', 'rich_images', 'board_message_revisions', 'user_settings'] as $t) {
            $this->assertContains($t, Backup::CORE_TABLES, $t);
        }
        $this->assertNotContains('board_mails', Backup::CORE_TABLES, 'Warteschlange der Mails nicht');
        $dump = (new Backup($this->app->db))->dump(['user_signatures', 'rich_images']);
        $this->assertCount(1, $dump['user_signatures']);
        $this->assertCount(2, $dump['rich_images']);

        unset($this->users->users[$this->m]);
        $this->app->userDeleted($this->m);
        $this->assertSame(0, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {user_signatures}'));
        $this->assertSame(0, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {user_settings}'));
        $this->assertNull($this->app->richImages()->repository()->find($logo));
        $this->assertNull($this->app->richImages()->repository()->find($draft));
    }

    public function testDraftsAreCleanedUp(): void
    {
        $this->as($this->m);
        $draft = $this->upload(self::png())['uid'];
        // hochgeladen 2026-10-20 10:00 UTC
        $this->clock->at = new \DateTimeImmutable('2026-10-27 09:59:00', new \DateTimeZone('UTC'));
        $this->assertSame(7, RichImageService::DRAFT_KEEP_DAYS);
        $this->app->board()->cleanup();
        $this->assertNotNull($this->app->richImages()->repository()->find($draft));
        $this->clock->at = $this->clock->now()->modify('+2 minutes');
        $this->app->board()->cleanup();
        $this->assertNull($this->app->richImages()->repository()->find($draft));
    }
}
