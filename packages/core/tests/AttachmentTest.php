<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\AttachmentService;
use KompanieApp\Core\Domain\BoardMailService;
use KompanieApp\Core\Domain\BoardService;
use KompanieApp\Core\Domain\MailingService;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Mail\PhpMailerMailer;
use KompanieApp\Core\Storage\Backup;
use KompanieApp\Core\Tests\Support\FakeMailer;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\NullScheduler;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** Dateianhänge am Schwarzen Brett und an E-Mails aus der Verwaltung (AttachmentService), mit Test-Mailer. */
final class AttachmentTest extends TestCase
{
    private FakeUsers $users;
    private FixedClock $clock;
    private FakeMailer $mailer;
    private App $app;
    private string $files;
    private int $admin;
    private int $m;
    private int $cand;
    private int $guest;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->clock = new FixedClock(new \DateTimeImmutable('2026-10-25 12:00:00', new \DateTimeZone('Europe/Berlin')));
        $this->mailer = new FakeMailer();
        $this->files = sys_get_temp_dir() . '/kompanie-anhang-' . bin2hex(random_bytes(4));
        $this->app = TestApp::create(TestApp::connection(), $this->users, ['name' => 'Musterkompanie'], null, $this->files, null, $this->clock, ['board' => true, 'mail' => true], [], new NullScheduler(), null, null, $this->mailer);
        $this->admin = $this->user('admin', Roles::ADMIN, 'admin@example.org');
        $this->m = $this->user('mitglied', Roles::MEMBER, 'mitglied@example.org');
        $this->cand = $this->user('anwaerter', TestApp::candidateRole($this->app), 'anwaerter@example.org');
        $this->guest = $this->user('gast', Roles::GUEST, 'gast@example.org');
        $this->users->current = $this->admin;
        $this->call('PATCH', '/admin/mail', ['from' => 'info@musterkompanie.example', 'host' => 'smtp.example.org']);
    }

    private function user(string $login, string $role, string $email): int
    {
        $id = $this->users->add($login, $role);
        $this->users->users[$id]['email'] = $email;
        return $id;
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

    /** @return array{uid: string, name: string, size: int, mime: string, url: string} */
    private function upload(string $name, string $bytes): array
    {
        $res = $this->call('POST', '/attachments', ['file' => ['name' => $name, 'data' => base64_encode($bytes)]]);
        $this->assertSame(201, $res->status, (string) json_encode($res->data));
        return $res->data;
    }

    /** @return list<string> gespeicherte Dateien */
    private function stored(): array
    {
        return array_map('basename', glob($this->files . '/private/anhaenge/anh-*.bin') ?: []);
    }

    public function testUploadAnyFileTypeAsDraft(): void
    {
        $this->assertSame(403, $this->as($this->m)->call('POST', '/attachments', ['file' => ['name' => 'a.txt', 'data' => base64_encode('x')]])->status, 'nur mit post_board oder send_mail');
        $this->assertSame(401, $this->as(null)->call('GET', '/attachments/limits')->status);
        $this->as($this->admin);
        $this->assertGreaterThan(0, $this->call('GET', '/attachments/limits')->data['max_bytes']);
        $exe = $this->upload('C:\\Programme\\tool.exe', "MZ\x90\x00");
        $this->assertSame(['tool.exe', 4, 'application/octet-stream'], [$exe['name'], $exe['size'], $exe['mime']], 'jeder Dateityp, ohne Pfad');
        $this->assertSame('application/pdf', $this->upload('Protokoll März.PDF', '%PDF-1.4')['mime']);
        $this->assertSame('Anhang', AttachmentService::cleanName('../'));
        $this->assertSame('ab.txt', AttachmentService::cleanName("a\x00b.txt"));
        $this->assertSame(200, mb_strlen(AttachmentService::cleanName(str_repeat('x', 300) . '.pdf')));
        $this->assertStringEndsWith('.pdf', AttachmentService::cleanName(str_repeat('x', 300) . '.pdf'));
        $this->assertSame(400, $this->call('POST', '/attachments', ['file' => ['name' => 'leer.txt', 'data' => '']])->status);
        $this->assertCount(2, $this->stored());
        $this->assertSame('https://example.org/app/anhang/' . $exe['uid'], $exe['url']);
    }

    public function testBoardAttachmentsVisibleDeliveredMailedAndTracked(): void
    {
        $pdf = $this->upload('Einladung.pdf', '%PDF-1.4 Einladung');
        $html = $this->upload('seite.html', '<script>alert(1)</script>');
        // Fremde Entwürfe gehen nicht
        $this->assertSame(200, $this->call('PATCH', "/members/{$this->m}/role", ['extra_roles' => ['board']])->status);
        $other = $this->as($this->m)->call('POST', '/board', ['title' => 'X', 'text' => '<p>x</p>', 'push' => false, 'mail' => false, 'attachments' => [$pdf['uid']]]);
        $this->assertSame([400, 'Ein Anhang ist nicht mehr vorhanden. Bitte die Datei erneut anhängen.'], [$other->status, $other->data['message'] ?? null]);
        $this->assertSame(200, $this->as($this->admin)->call('PATCH', "/members/{$this->m}/role", ['extra_roles' => []])->status);

        $item = $this->as($this->admin)->call('POST', '/board', ['title' => 'Mit Anhang', 'text' => '<p>siehe Anhang</p>', 'push' => false, 'attachments' => [$pdf['uid'], $html['uid']]])->data;
        $this->assertSame(['Einladung.pdf', 'seite.html'], array_column($item['attachments'], 'name'));
        $listed = $this->as($this->cand)->call('GET', '/board')->data['items'][0];
        $this->assertSame([$pdf['uid'], $html['uid']], array_column($listed['attachments'], 'uid'), 'Anwärter sehen die Anhänge');

        // Auslieferung: wer die Nachricht sieht, immer als Download; HTML nie als HTML
        $deliver = fn (?int $u, string $uid): array => $this->app->attachments()->forDelivery($u === null ? null : $this->users->find($u), $uid);
        $res = $deliver($this->cand, $html['uid']);
        $this->assertSame(200, $res['status']);
        $this->assertSame('application/octet-stream', $res['headers']['Content-Type']);
        $this->assertSame('attachment; filename="seite.html"; filename*=UTF-8\'\'seite.html', $res['headers']['Content-Disposition']);
        $this->assertSame('nosniff', $res['headers']['X-Content-Type-Options']);
        $this->assertStringContainsString('sandbox', $res['headers']['Content-Security-Policy']);
        $this->assertSame('<script>alert(1)</script>', file_get_contents($res['path']));
        $this->assertSame('application/pdf', $deliver($this->m, $pdf['uid'])['headers']['Content-Type']);
        $this->assertSame(403, $deliver($this->guest, $pdf['uid'])['status'], 'Gäste nicht');
        $this->assertSame(401, $deliver(null, $pdf['uid'])['status']);
        $this->assertSame(404, $deliver($this->m, str_repeat('0', 32))['status']);
        $draft = $this->as($this->admin)->upload('entwurf.txt', 'geheim');
        $this->assertSame(403, $deliver($this->m, $draft['uid'])['status'], 'Entwurf nur für das eigene Konto');
        $this->assertSame(200, $deliver($this->admin, $draft['uid'])['status']);

        // Die Mail vom Brett hat die Anhänge
        $this->app->runTask(BoardMailService::TASK);
        $this->assertNotEmpty($this->mailer->sent);
        $this->assertSame(['Einladung.pdf', 'seite.html'], array_column($this->mailer->sent[0]->attachments, 'name'));
        $this->assertSame(['application/pdf', 'text/html'], array_column($this->mailer->sent[0]->attachments, 'mime'));

        // Ändern: einen entfernen, einen dazu; Verlauf mit vorher/nachher, Datei weg, Nachricht erscheint wieder
        $this->clock->at = new \DateTimeImmutable('2026-10-25 12:05:00', new \DateTimeZone('Europe/Berlin'));
        $plan = $this->upload('Plan.xlsx', "PK\x03\x04");
        $updated = $this->call('PATCH', "/board/{$item['id']}", ['attachments' => [$pdf['uid'], $plan['uid']]])->data;
        $this->assertSame(['Einladung.pdf', 'Plan.xlsx'], array_column($updated['attachments'], 'name'));
        $this->assertNotSame($item['updated_at'], $updated['updated_at'], 'erscheint auf Geräten wieder, die sie ausgeblendet hatten');
        $this->assertNull($this->app->attachments()->find($html['uid']), 'entfernter Anhang gelöscht');
        $rev = array_values(array_filter($this->call('GET', "/board/{$item['id']}/history")->data['revisions'], static fn (array $r): bool => $r['action'] === BoardService::REV_ATTACHMENTS));
        $this->assertSame([['Einladung.pdf', 'seite.html'], ['Einladung.pdf', 'Plan.xlsx']], [$rev[0]['before'], $rev[0]['after']]);
        $created = $this->call('GET', "/board/{$item['id']}/history")->data['revisions'][0];
        $this->assertSame(['Einladung.pdf', 'seite.html'], $created['after']['attachments'] ?? null, 'erstellt: mit Anhängen');
        $same = $this->call('PATCH', "/board/{$item['id']}", ['attachments' => [$pdf['uid'], $plan['uid']]]);
        $this->assertSame(200, $same->status);
        $this->assertCount(1, array_filter($this->call('GET', "/board/{$item['id']}/history")->data['revisions'], static fn (array $r): bool => $r['action'] === BoardService::REV_ATTACHMENTS), 'unverändert: kein neuer Eintrag');

        // Sicherung: Anhänge gespeicherter Nachrichten, keine Entwürfe
        $tables = (new Backup($this->app->db))->dump(['attachments']);
        $files = Backup::dumpFiles($this->app->files, $tables);
        $want = ['anhaenge/anh-' . $pdf['uid'] . '.bin', 'anhaenge/anh-' . $plan['uid'] . '.bin'];
        sort($want);
        $this->assertSame($want, array_values(array_filter(array_keys($files), static fn (string $p): bool => str_starts_with($p, 'anhaenge/'))));
        $this->assertArrayNotHasKey('anhaenge/anh-' . $draft['uid'] . '.bin', $files, 'Entwürfe nicht');

        // Aufräumen: Entwürfe nach 7 Tagen, Nachricht samt Anhängen 30 Tage nach dem Ende
        $this->clock->at = new \DateTimeImmutable('2026-11-26 12:00:00', new \DateTimeZone('Europe/Berlin'));
        $this->app->runTask(BoardService::TASK);
        $this->assertSame([], $this->stored(), 'alle Dateien gelöscht');
        $this->assertSame(0, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {attachments}'));
    }

    public function testMailingAttachments(): void
    {
        $doc = $this->upload('Satzung.docx', "PK\x03\x04 Satzung");
        $res = $this->call('POST', '/mails', ['subject' => 'Satzung', 'text' => '<p>anbei</p>', 'to' => ['users' => [$this->m]], 'attachments' => [$doc['uid']]]);
        $this->assertSame(201, $res->status, (string) json_encode($res->data));
        $this->assertSame(['Satzung.docx'], array_column($res->data['attachments'], 'name'));
        $this->assertSame(400, $this->call('POST', '/mails', ['subject' => 'Nochmal', 'text' => '<p>x</p>', 'to' => ['users' => [$this->m]], 'attachments' => [$doc['uid']]])->status, 'ein gespeicherter Anhang gehört der ersten Mail');
        $this->app->runTask(BoardMailService::TASK);
        $mail = $this->mailer->sent[0];
        $this->assertSame([['Satzung.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']], array_map(static fn (array $a): array => [$a['name'], $a['mime']], $mail->attachments));
        $this->assertSame(403, $this->app->attachments()->forDelivery($this->users->find($this->m), $doc['uid'])['status'], 'Anhang einer Mail nur mit send_mail');
        $this->assertSame(200, $this->app->attachments()->forDelivery($this->users->find($this->admin), $doc['uid'])['status']);

        // Aufbau mit PHPMailer: multipart/mixed mit dem Anhang
        $mime = (new PhpMailerMailer($this->app->mail()->config()))->mime($mail);
        $this->assertStringContainsString('multipart/mixed', $mime);
        $this->assertStringContainsString('filename=Satzung.docx', $mime);

        // Größe: Anhänge zählen mit
        $this->mailer->size = 100;
        $this->app->settings->set('mail_server_size', 100);
        $big = $this->upload('gross.bin', str_repeat('x', 5000));
        $r = $this->call('POST', '/mails', ['subject' => 'Groß', 'text' => '<p>x</p>', 'to' => ['users' => [$this->m]], 'attachments' => [$big['uid']]]);
        $this->assertSame(400, $r->status);
        $this->assertStringContainsString('mit Bildern und Anhängen', $r->data['message']);

        // Nach einem Jahr: Mail samt Anhängen gelöscht
        $this->clock->at = new \DateTimeImmutable('2027-10-27 12:00:00', new \DateTimeZone('Europe/Berlin'));
        $this->app->runTask(MailingService::CLEANUP_TASK);
        $this->assertSame([], $this->stored(), 'Anhänge der Mail und alter Entwurf gelöscht');
    }

    public function testDeletedAccountLosesDrafts(): void
    {
        $this->upload('a.txt', 'a');
        $this->assertCount(1, $this->stored());
        $this->app->userDeleted($this->admin);
        $this->assertSame([], $this->stored());
    }
}
