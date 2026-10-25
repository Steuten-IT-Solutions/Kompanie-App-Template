<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\BoardMailService;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Mail\Mail;
use KompanieApp\Core\Mail\MailResult;
use KompanieApp\Core\Mail\PhpMailerMailer;
use KompanieApp\Core\Tests\Support\FakeMailer;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\NullScheduler;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** Mailversand vom Schwarzen Brett (Issue #172, Teil D), mit einem Test-Mailer: Es wird nichts verschickt. */
final class BoardMailTest extends TestCase
{
    private FakeUsers $users;
    private FixedClock $clock;
    private FakeMailer $mailer;
    private NullScheduler $scheduler;
    private App $app;
    private int $admin;
    private int $m;
    private int $cand;
    private int $guest;
    private int $noMail;
    private int $optOut;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->clock = new FixedClock(new \DateTimeImmutable('2026-10-20 12:00:00', new \DateTimeZone('Europe/Berlin')));
        $this->mailer = new FakeMailer();
        $this->scheduler = new NullScheduler();
        $this->app = TestApp::create(TestApp::connection(), $this->users, ['name' => 'Musterkompanie'], null, null, null, $this->clock, ['board' => true], [], $this->scheduler, null, null, $this->mailer);
        $this->admin = $this->user('admin', Roles::ADMIN, 'admin@example.org');
        $this->m = $this->user('mitglied', Roles::MEMBER, 'mitglied@example.org');
        $this->cand = $this->user('anwaerter', TestApp::candidateRole($this->app), 'anwaerter@example.org');
        $this->guest = $this->user('gast', Roles::GUEST, 'gast@example.org');
        $this->noMail = $this->user('ohneadresse', Roles::MEMBER, '');
        $this->optOut = $this->user('abgemeldet', Roles::MEMBER, 'abgemeldet@example.org');
        $this->app->boardMail()->setWants($this->optOut, false);
        $this->users->current = $this->admin;
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

    private function configure(): void
    {
        $res = $this->call('PATCH', '/admin/mail', ['from' => 'info@musterkompanie.example', 'host' => 'smtp.example.org', 'port' => 587, 'secure' => 'starttls', 'user' => 'info', 'password' => 'geheim']);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
    }

    public function testSettingsNeverReturnThePassword(): void
    {
        $this->assertSame(['from' => '', 'configured' => false], array_intersect_key($this->call('GET', '/admin/mail')->data, ['from' => 1, 'configured' => 1]));
        $this->assertFalse($this->as($this->admin)->call('GET', '/board/mail')->data['available']);
        $this->configure();
        $data = $this->call('GET', '/admin/mail')->data;
        $this->assertTrue($data['password_set']);
        $this->assertArrayNotHasKey('password', $data);
        $this->assertStringNotContainsString('geheim', (string) json_encode($data));
        $this->assertSame('Musterkompanie', $data['from_name_default'], 'Absendername: Name der Kompanie');
        $this->assertTrue($data['configured']);
        $this->assertSame(BoardMailService::INTERVAL, $this->scheduler->scheduled[BoardMailService::TASK] ?? null, 'Versandaufgabe eingeplant');
        // Ohne Passwort-Feld bleibt es, leer löscht es
        $this->call('PATCH', '/admin/mail', ['user' => 'neu']);
        $this->assertSame('geheim', $this->app->mail()->config()['password']);
        $this->call('PATCH', '/admin/mail', ['password' => '']);
        $this->assertFalse($this->call('GET', '/admin/mail')->data['password_set']);
        foreach ([['from' => 'keine-adresse'], ['host' => 'https://smtp.example.org'], ['port' => 70000], ['secure' => 'tls1.3'], ['batch_size' => -1]] as $bad) {
            $this->assertSame(400, $this->call('PATCH', '/admin/mail', $bad)->status, (string) json_encode($bad));
        }
        $this->assertSame(403, $this->as($this->m)->call('GET', '/admin/mail')->status);
    }

    public function testCheckReportsServerSizeAndTestMail(): void
    {
        $this->assertSame(400, $this->call('POST', '/admin/mail/check')->status, 'erst einrichten');
        $this->configure();
        $this->mailer->size = 10 * 1024 * 1024;
        $check = $this->call('POST', '/admin/mail/check')->data;
        $this->assertSame([true, 10485760], [$check['ok'], $check['size']]);
        $this->assertSame(10485760, $check['settings']['server_max_size'], 'Limit angezeigt und gemerkt');
        $res = $this->call('POST', '/admin/mail/test', []);
        $this->assertSame(['sent' => true, 'message' => 'Testmail an admin@example.org gesendet.'], $res->data, 'Standard: eigene Adresse');
        $this->assertSame('Testmail: Musterkompanie', $this->mailer->sent[0]->subject);
        $this->mailer->results['x@example.org'] = [new MailResult(false, 'Relay denied', true, 550)];
        $this->assertFalse($this->call('POST', '/admin/mail/test', ['to' => 'x@example.org'])->data['sent']);
    }

    public function testRecipientsOptOutAndSending(): void
    {
        $this->configure();
        $this->as($this->optOut);
        $this->assertSame(['board' => true, 'available' => true, 'email' => true], $this->call('PUT', '/me/mail', ['board' => true])->data);
        $this->assertSame(['board' => false, 'available' => true, 'email' => true], $this->call('PUT', '/me/mail', ['board' => false])->data);
        $this->assertSame(['board' => true, 'available' => true, 'email' => true], $this->as($this->m)->call('GET', '/me/mail')->data, 'Standard an');
        $this->assertSame(400, $this->call('PUT', '/me/mail', ['board' => 'nein'])->status);
        $this->assertSame(403, $this->as($this->guest)->call('PUT', '/me/mail', ['board' => true])->status, 'Gäste bekommen keine Mails vom Brett');
        $this->assertSame(['board' => true, 'available' => true, 'email' => false], $this->as($this->noMail)->call('GET', '/me/mail')->data, 'ohne Adresse: die App weist darauf hin');

        $this->as($this->admin);
        $this->assertSame(2, $this->call('GET', '/board/mail')->data['recipients'], 'Mitglied und Anwärter; ohne Verfasser, Gäste, Konten ohne Adresse und Abgemeldete');
        $item = $this->call('POST', '/board', ['title' => 'Schützenfest: Änderung', 'text' => '<p>Treffen <b>früher</b></p>', 'push' => false])->data;
        $this->assertSame(['recipients' => 2, 'sent' => 0, 'failed' => 0, 'pending' => 2, 'cancelled' => 0], $item['mail'], 'Mail standardmäßig an, im Hintergrund');
        $this->assertSame([], $this->mailer->sent, 'nicht in der Anfrage verschickt');
        $this->assertContains(BoardMailService::TASK, $this->scheduler->soon, 'Versand angestoßen');

        $this->app->runTask(BoardMailService::TASK);
        $this->assertSame(['anwaerter@example.org', 'mitglied@example.org'], $this->mailer->recipients(), 'jede Mail einzeln an einen Empfänger');
        $mail = $this->mailer->sent[1];
        $this->assertSame(['Schützenfest: Änderung', 'Mitglied'], [$mail->subject, $mail->toName], 'Betreff = Titel');
        $this->assertStringContainsString('<p>Treffen <b>früher</b></p>', $mail->html);
        $this->assertStringContainsString('https://example.org/app/#/brett/' . $item['id'], $mail->html, 'Link zur App');
        $this->assertStringContainsString('Schwarzes Brett: Nachricht von Admin', $mail->text, 'Verfasser');
        $this->assertStringContainsString('Treffen früher', $mail->text, 'Text-Teil aus dem Textauszug');
        $this->assertStringContainsString('Konto → Benachrichtigungen', $mail->text, 'Hinweis zum Abschalten');
        $this->assertGreaterThan(0, $this->mailer->closed);

        $history = $this->call('GET', "/board/{$item['id']}/history")->data;
        $this->assertSame(['created', 'mailed'], array_column($history['revisions'], 'action'));
        $this->assertSame(['recipients' => 2], $history['revisions'][1]['after']);
        $this->assertSame(['sent', 'sent'], array_column($history['mail_log'], 'status'));
        $this->assertSame(403, $this->as($this->m)->call('GET', "/board/{$item['id']}/history")->status);

        // Ändern verschickt keine neue Mail
        $this->as($this->admin)->call('PATCH', "/board/{$item['id']}", ['text' => '<p>anders</p>']);
        $this->app->runTask(BoardMailService::TASK);
        $this->assertCount(2, $this->mailer->sent);
        // Ohne Haken keine Mail
        $quiet = $this->call('POST', '/board', ['title' => 'Leise', 'text' => 'x', 'push' => false, 'mail' => false])->data;
        $this->assertNull($quiet['mail']);
        $this->assertSame(400, $this->call('POST', '/board', ['title' => 'T', 'text' => 'x', 'mail' => 'ja'])->status);
    }

    public function testEmbeddedImagesAndRealMimeStructure(): void
    {
        $this->configure();
        $img = imagecreatetruecolor(3, 3);
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        $uid = $this->call('POST', '/rich-images', ['file' => ['name' => 'logo.png', 'data' => base64_encode($png)]])->data['uid'];
        $item = $this->call('POST', '/board', ['title' => 'Grüße mit Bild', 'text' => '<p>Hallo</p><img data-image="' . $uid . '" width="3">', 'push' => false])->data;
        $this->app->runTask(BoardMailService::TASK);
        $mail = $this->mailer->sent[1];
        $copy = \KompanieApp\Core\Domain\RichText::imageUids($item['text'])[0];
        $this->assertStringContainsString('<img src="cid:' . $copy . '" width="3">', $mail->html, 'eingebettet statt Adresse der App');
        $this->assertStringNotContainsString('/app/bild/', $mail->html);
        $this->assertSame([[$copy, 'image/png']], array_map(static fn (array $i): array => [$i['cid'], $i['mime']], $mail->images));

        // Aufbau mit PHPMailer (ohne Versand): multipart/related mit HTML und Text als alternative, Betreff kodiert
        $mime = (new PhpMailerMailer($this->app->mail()->config()))->mime($mail);
        $this->assertMatchesRegularExpression('/^Subject: =\?utf-8\?Q\?Gr=C3=BC=C3=9Fe_mit_Bild\?=/mi', $mime, 'Umlaute im Betreff kodiert');
        $this->assertMatchesRegularExpression('/^From: Musterkompanie <info@musterkompanie\.example>/m', $mime, 'Absender: Kompanieadresse');
        $this->assertMatchesRegularExpression('/^To: Mitglied <mitglied@example\.org>/m', $mime);
        $this->assertStringContainsString('multipart/related', $mime);
        $this->assertStringContainsString('multipart/alternative', $mime);
        $this->assertStringContainsString('Content-ID: <' . $copy . '>', $mime);
        $this->assertStringContainsString('Content-Type: text/plain; charset=utf-8', $mime);
        $this->assertStringNotContainsString('X-Mailer: PHPMailer', $mime);
        // Gleich aufgebaut, nur Trennzeilen und Message-ID sind zufällig
        $this->assertEqualsWithDelta(strlen($mime), (new PhpMailerMailer($this->app->mail()->config()))->measure($mail), 64);
    }

    public function testServerSizeLimit(): void
    {
        $this->assertSame(10240000, PhpMailerMailer::parseSize('10240000'));
        $this->assertNull(PhpMailerMailer::parseSize('0'), 'SIZE 0: kein Limit');
        $this->assertNull(PhpMailerMailer::parseSize(0), 'SIZE ohne Zahl');
        $this->assertNull(PhpMailerMailer::parseSize(false), 'kein SIZE');
        $this->assertNull(PhpMailerMailer::parseSize(null), 'keine Verbindung');

        $this->configure();
        $this->mailer->size = 1000;
        $this->mailer->measured = 1536 * 1024;
        $before = (int) $this->app->db->scalar('SELECT COUNT(*) FROM {board_messages}');
        $res = $this->call('POST', '/board', ['title' => 'Groß', 'text' => '<p>x</p>', 'push' => false]);
        $this->assertSame(400, $res->status);
        $this->assertSame('Die Mail ist mit Bildern 1,5 MB groß, der Mailserver nimmt höchstens 1 KB an. Bilder verkleinern oder ohne E-Mail senden.', $res->data['message']);
        $this->assertSame($before, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {board_messages}'), 'nichts veröffentlicht, nichts verschickt');
        $this->assertSame(1, $this->mailer->checks, 'Limit einmal gelesen und gemerkt');
        $this->assertSame(201, $this->call('POST', '/board', ['title' => 'Groß', 'text' => '<p>x</p>', 'push' => false, 'mail' => false])->status, 'ohne E-Mail geht es');

        // Ohne gemeldetes Limit keine Prüfung vorab
        $this->mailer->size = null;
        $this->call('POST', '/admin/mail/check');
        $this->assertSame(201, $this->call('POST', '/board', ['title' => 'Groß', 'text' => '<p>x</p>', 'push' => false])->status);
    }

    public function testRejectionRetryAndBatching(): void
    {
        $this->configure();
        $this->call('PATCH', '/admin/mail', ['batch_size' => 1, 'batch_pause' => 0]);
        // Reihenfolge der Warteschlange: nach Namen (Anwärter vor Mitglied)
        $this->mailer->results['anwaerter@example.org'] = [new MailResult(false, '552 Message size exceeds fixed maximum message size', true, 552)];
        $this->mailer->results['mitglied@example.org'] = [new MailResult(false, 'Keine Verbindung', false), new MailResult(false, '451 Try again later', false, 451)];
        $item = $this->call('POST', '/board', ['title' => 'T', 'text' => 'x', 'push' => false])->data;

        $this->scheduler->soon = [];
        $this->app->runTask(BoardMailService::TASK); // Portion 1: 552 → dauerhaft fehlgeschlagen
        $this->assertSame(['failed' => 1, 'pending' => 1], array_intersect_key($this->app->boardMail()->summaries([$item['id']])[$item['id']], ['failed' => 1, 'pending' => 1]));
        $this->assertContains(BoardMailService::TASK, $this->scheduler->soon, 'ohne Pause gleich weiter');
        $this->app->runTask(BoardMailService::TASK); // Portion 2: Verbindung weg → später erneut
        $this->app->runTask(BoardMailService::TASK); // noch in der Wartezeit
        $this->assertSame([], $this->mailer->sent);
        $this->clock->at = $this->clock->now()->modify('+5 minutes');
        $this->app->runTask(BoardMailService::TASK); // 451 → noch einmal
        $this->clock->at = $this->clock->now()->modify('+10 minutes');
        $this->app->runTask(BoardMailService::TASK);
        $this->assertSame(['mitglied@example.org'], $this->mailer->recipients(), 'beim dritten Versuch zugestellt');
        $log = $this->call('GET', "/board/{$item['id']}/history")->data['mail_log'];
        $this->assertSame([['anwaerter@example.org', 'failed', 1], ['mitglied@example.org', 'sent', 3]], array_map(static fn (array $l): array => [$l['email'], $l['status'], $l['attempts']], $log));
        $this->assertStringContainsString('552', (string) $log[0]['error'], 'Grund protokolliert');

        // Nach MAX_ATTEMPTS vorübergehenden Fehlern ist Schluss
        $this->mailer->results['mitglied@example.org'] = array_fill(0, 10, new MailResult(false, '421 busy', false, 421));
        $this->mailer->results['anwaerter@example.org'] = array_fill(0, 10, new MailResult(false, '421 busy', false, 421));
        $second = $this->call('POST', '/board', ['title' => 'Zwei', 'text' => 'x', 'push' => false])->data;
        $this->call('PATCH', '/admin/mail', ['batch_size' => 0]);
        for ($i = 0; $i < 8; $i++) {
            $this->app->runTask(BoardMailService::TASK);
            $this->clock->at = $this->clock->now()->modify('+2 hours');
        }
        $this->assertSame(['recipients' => 2, 'sent' => 0, 'failed' => 2, 'pending' => 0, 'cancelled' => 0], $this->app->boardMail()->summaries([$second['id']])[$second['id']]);
        $this->assertSame([5, 5], array_column($this->call('GET', "/board/{$second['id']}/history")->data['mail_log'], 'attempts'));
    }

    public function testPauseBetweenBatchesAndCancelOnDelete(): void
    {
        $this->configure();
        $this->call('PATCH', '/admin/mail', ['batch_size' => 1, 'batch_pause' => 30]);
        $item = $this->call('POST', '/board', ['title' => 'T', 'text' => 'x', 'push' => false])->data;
        $this->app->runTask(BoardMailService::TASK);
        $this->assertCount(1, $this->mailer->sent);
        $this->clock->at = $this->clock->now()->modify('+29 minutes');
        $this->app->runTask(BoardMailService::TASK);
        $this->assertCount(1, $this->mailer->sent, 'Pause zwischen den Portionen');
        $this->clock->at = $this->clock->now()->modify('+1 minute');
        $this->app->runTask(BoardMailService::TASK);
        $this->assertCount(2, $this->mailer->sent);

        $this->call('PATCH', '/admin/mail', ['batch_pause' => 0]);
        $second = $this->call('POST', '/board', ['title' => 'Zwei', 'text' => 'x', 'push' => false])->data;
        $this->call('DELETE', "/board/{$second['id']}");
        $this->app->runTask(BoardMailService::TASK);
        $this->assertCount(2, $this->mailer->sent, 'gelöscht: geht nicht mehr raus');
        $this->assertSame(2, $this->app->boardMail()->summaries([$second['id']])[$second['id']]['cancelled']);
    }

    public function testAccountDeletionRemovesMailRows(): void
    {
        $this->configure();
        $this->call('POST', '/board', ['title' => 'T', 'text' => 'x', 'push' => false]);
        unset($this->users->users[$this->m]);
        $this->app->userDeleted($this->m);
        $this->assertSame(0, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {board_mails} WHERE user_id = ?', [$this->m]), 'mit Adresse entfernt');
        $this->assertSame(1, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {board_mails}'));
    }

    public function testWithoutConfigurationMailIsOff(): void
    {
        $item = $this->call('POST', '/board', ['title' => 'T', 'text' => 'x', 'push' => false]);
        $this->assertSame(201, $item->status, 'Standard ohne Einrichtung: keine Mail');
        $this->assertNull($item->data['mail']);
        $res = $this->call('POST', '/board', ['title' => 'T', 'text' => 'x', 'push' => false, 'mail' => true]);
        $this->assertSame(400, $res->status);
        $this->assertStringContainsString('Einstellungen → E-Mail', $res->data['message']);
        $this->assertArrayNotHasKey(BoardMailService::TASK, $this->scheduler->scheduled);
        $this->assertNull(PhpMailerMailer::unavailable(), 'PHPMailer ist über Composer vorhanden');
    }
}
