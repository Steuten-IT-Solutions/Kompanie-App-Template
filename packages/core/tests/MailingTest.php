<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\BoardMailService;
use KompanieApp\Core\Domain\MailingService;
use KompanieApp\Core\Domain\RichImageService;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Mail\Mail;
use KompanieApp\Core\Mail\MailResult;
use KompanieApp\Core\Mail\PhpMailerMailer;
use KompanieApp\Core\Storage\Backup;
use KompanieApp\Core\Storage\UserData;
use KompanieApp\Core\Tests\Support\FakeMailer;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\NullScheduler;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** E-Mails aus der Verwaltung (Bereich mail, Recht send_mail), mit einem Test-Mailer: Es wird nichts verschickt. */
final class MailingTest extends TestCase
{
    private FakeUsers $users;
    private FixedClock $clock;
    private FakeMailer $mailer;
    private NullScheduler $scheduler;
    private App $app;
    private int $admin;
    private int $m;
    private int $m2;
    private int $cand;
    private int $guest;
    private int $noMail;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->clock = new FixedClock(new \DateTimeImmutable('2026-10-25 12:00:00', new \DateTimeZone('Europe/Berlin')));
        $this->mailer = new FakeMailer();
        $this->scheduler = new NullScheduler();
        $this->app = TestApp::create(TestApp::connection(), $this->users, ['name' => 'Musterkompanie'], null, null, null, $this->clock, ['board' => true, 'mail' => true], [], $this->scheduler, null, null, $this->mailer);
        $this->admin = $this->user('admin', Roles::ADMIN, 'admin@example.org', 'Anna Admin');
        $this->m = $this->user('mitglied', Roles::MEMBER, 'mitglied@example.org', 'Max Mitglied');
        $this->m2 = $this->user('zweites', Roles::MEMBER, 'zweites@example.org', 'Zora Zweites');
        $this->cand = $this->user('anwaerter', TestApp::candidateRole($this->app), 'anwaerter@example.org', 'Anton Anwärter');
        $this->guest = $this->user('gast', Roles::GUEST, 'gast@example.org', 'Gisela Gast');
        $this->noMail = $this->user('ohneadresse', Roles::MEMBER, '', 'Otto Ohneadresse');
        $this->users->current = $this->admin;
    }

    private function user(string $login, string $role, string $email, string $name): int
    {
        $id = $this->users->add($login, $role);
        $this->users->users[$id]['email'] = $email;
        $this->users->users[$id]['name'] = $name;
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

    private function configure(array $extra = []): void
    {
        $res = $this->call('PATCH', '/admin/mail', ['from' => 'info@musterkompanie.example', 'host' => 'smtp.example.org', 'port' => 587, 'secure' => 'starttls'] + $extra);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
    }

    private function png(): string
    {
        $img = imagecreatetruecolor(4, 3);
        ob_start();
        imagepng($img);
        return (string) ob_get_clean();
    }

    public function testAccessAndOptions(): void
    {
        $this->assertSame(403, $this->as($this->m)->call('GET', '/mails/options')->status, 'nur mit send_mail');
        $options = $this->as($this->admin)->call('GET', '/mails/options')->data;
        $this->assertFalse($options['available'], 'erst Mailversand einrichten');
        $this->assertSame(400, $this->call('POST', '/mails', ['subject' => 'A', 'text' => '<p>x</p>', 'to' => ['users' => [$this->m]]])->status);

        $this->configure();
        $options = $this->call('GET', '/mails/options')->data;
        $this->assertTrue($options['available']);
        $this->assertSame(['info@musterkompanie.example', 'Musterkompanie'], [$options['from'], $options['from_name']]);
        $this->assertNotContains(Roles::GUEST, array_column($options['roles'], 'key'), 'keine Gäste');
        $member = array_values(array_filter($options['roles'], static fn (array $r): bool => $r['key'] === Roles::MEMBER))[0];
        $this->assertSame(['members' => 3, 'with_email' => 2], ['members' => $member['members'], 'with_email' => $member['with_email']]);
        $names = array_column($options['members'], 'name');
        $this->assertNotContains('Gisela Gast', $names, 'Gäste nicht wählbar');
        // WordPress liefert Gäste mit gespeicherter Rolle auch ohne includeGuests: trotzdem nicht wählbar
        $this->users->listGuests = true;
        $this->assertNotContains('Gisela Gast', array_column($this->call('GET', '/mails/options')->data['members'], 'name'));
        $this->users->listGuests = false;
        $this->assertStringNotContainsString('mitglied@example.org', (string) json_encode($options), 'Adressen werden nicht ausgeliefert');

        // Recht über die Standardrolle des Bereichs als Zusatzrolle
        $this->assertSame(200, $this->call('PATCH', "/members/{$this->m}/role", ['extra_roles' => ['mail']])->status);
        $this->assertSame(200, $this->as($this->m)->call('GET', '/mails/options')->status);
        $this->assertSame(200, $this->call('GET', '/me/signature')->status, 'Signatur auch für send_mail');

        // Bereich aus: gesperrt
        $this->as($this->admin)->call('PUT', '/admin/features', ['mail' => false]);
        $this->assertSame('feature_disabled', $this->call('GET', '/mails/options')->data['code'] ?? null);
    }

    public function testResolveDeduplicatesAndSendsWithVisibleHeaders(): void
    {
        $this->configure();
        $selection = [
            'to' => ['roles' => [Roles::MEMBER]],
            'cc' => ['users' => [$this->cand, $this->m], 'emails' => ['extern@example.net']],
            'bcc' => ['emails' => ['MITGLIED@example.org', 'geheim@example.net']],
        ];
        $preview = $this->call('POST', '/mails/preview', $selection)->data;
        $this->assertSame(['recipients' => 5, 'to' => 2, 'cc' => 2, 'bcc' => 1, 'skipped' => ['Otto Ohneadresse']], $preview, 'je Adresse einmal, An vor Cc vor Bcc');

        $res = $this->call('POST', '/mails', ['subject' => 'Einladung: Generalversammlung', 'text' => '<p>Liebe <b>Kameraden</b></p>'] + $selection);
        $this->assertSame(201, $res->status, (string) json_encode($res->data));
        $this->assertSame(['recipients' => 5, 'sent' => 0, 'failed' => 0, 'pending' => 5, 'cancelled' => 0], $res->data['mail'], 'Versand im Hintergrund');
        $this->assertSame(['Anton Anwärter', 'Max Mitglied'], array_column($res->data['selection']['cc']['users'], 'name'), 'Auswahl mit Namen');
        $this->assertSame([Roles::MEMBER], array_column($res->data['selection']['to']['roles'], 'key'));
        $this->assertSame(['MITGLIED@example.org', 'geheim@example.net'], $res->data['selection']['bcc']['emails']);
        $this->assertContains(BoardMailService::TASK, $this->scheduler->soon, 'Versand angestoßen');

        $this->app->runTask(BoardMailService::TASK);
        $this->assertSame(['mitglied@example.org', 'zweites@example.org', 'anwaerter@example.org', 'extern@example.net', 'geheim@example.net'], $this->mailer->recipients(), 'jede Mail einzeln');
        foreach ($this->mailer->sent as $mail) {
            $this->assertSame('Einladung: Generalversammlung', $mail->subject);
            $this->assertSame(['mitglied@example.org', 'zweites@example.org'], array_column($mail->headerTo ?? [], 'email'), 'An: alle aus An sichtbar');
            $this->assertSame(['anwaerter@example.org', 'extern@example.net'], array_column($mail->headerCc, 'email'), 'Cc sichtbar');
            $this->assertStringNotContainsString('geheim@example.net', (string) json_encode([$mail->headerTo, $mail->headerCc]), 'Bcc nie sichtbar');
            $this->assertStringContainsString('<b>Kameraden</b>', $mail->html);
            $this->assertStringNotContainsString('Abschalten', $mail->html, 'keine Fußzeile');
        }
        $this->assertSame('Max Mitglied', $this->mailer->sent[0]->toName);

        $detail = $this->call('GET', '/mails/' . $res->data['id'])->data;
        $this->assertSame(['recipients' => 5, 'sent' => 5, 'failed' => 0, 'pending' => 0, 'cancelled' => 0], $detail['mail']);
        $this->assertSame(['to', 'to', 'cc', 'cc', 'bcc'], array_column($detail['log'], 'field'));
        $this->assertSame(['Max Mitglied', 'Zora Zweites', 'Anton Anwärter', null, null], array_column($detail['log'], 'name'));
        $list = $this->call('GET', '/mails')->data;
        $this->assertSame([['Einladung: Generalversammlung', 'Anna Admin']], array_map(static fn (array $i): array => [$i['subject'], $i['author_name']], $list['items']));
        $this->assertSame(MailingService::KEEP_DAYS, $list['keep_days']);
        $this->assertSame(404, $this->call('GET', '/mails/999')->status);
    }

    public function testBccOnlyAndValidation(): void
    {
        $this->configure();
        $res = $this->call('POST', '/mails', ['subject' => 'Nur Bcc', 'text' => '<p>x</p>', 'bcc' => ['users' => [$this->m, $this->m2]]]);
        $this->assertSame(201, $res->status);
        $this->app->runTask(BoardMailService::TASK);
        $this->assertSame([[], []], [$this->mailer->sent[0]->headerTo, $this->mailer->sent[0]->headerCc], 'nur Bcc: niemand sichtbar');
        $this->assertTrue($this->mailer->sent[0]->hasHeaderRecipients());

        foreach ([
            [['subject' => '', 'text' => '<p>x</p>', 'to' => ['users' => [$this->m]]], 'Bitte einen Betreff eingeben.'],
            [['subject' => 'A', 'text' => '<p> </p>', 'to' => ['users' => [$this->m]]], 'Bitte einen Text eingeben.'],
            [['subject' => 'A', 'text' => '<p>x</p>', 'to' => ['emails' => ['keine-adresse']]], 'Ungültige Adresse: „keine-adresse“.'],
            [['subject' => 'A', 'text' => '<p>x</p>', 'to' => ['roles' => ['unbekannt']]], 'Unbekannte Rolle: unbekannt.'],
            [['subject' => 'A', 'text' => '<p>x</p>', 'to' => ['roles' => [Roles::GUEST]]], 'Unbekannte Rolle: guest.'],
            [['subject' => 'A', 'text' => '<p>x</p>', 'to' => ['users' => [$this->guest]]], 'Dieses Mitglied gibt es nicht (mehr).'],
            [['subject' => 'A', 'text' => '<p>x</p>', 'to' => ['users' => [$this->noMail]]], 'Keiner der gewählten Empfänger hat eine Mailadresse. Bitte Empfänger auswählen.'],
            [['subject' => 'A', 'text' => '<p>x</p>'], 'Keiner der gewählten Empfänger hat eine Mailadresse. Bitte Empfänger auswählen.'],
            [['subject' => 'A', 'text' => '<p>x</p>', 'to' => 'alle'], 'to: Rollen, Mitglieder und Adressen erwartet.'],
        ] as [$body, $message]) {
            $r = $this->call('POST', '/mails', $body);
            $this->assertSame([400, $message], [$r->status, $r->data['message'] ?? null], (string) json_encode($body));
        }
    }

    public function testImagesAreEmbeddedAndOnlyVisibleWithSendMail(): void
    {
        $this->configure();
        $uid = $this->call('POST', '/rich-images', ['file' => ['name' => 'bild.png', 'data' => base64_encode($this->png())]])->data['uid'];
        $res = $this->call('POST', '/mails', ['subject' => 'Mit Bild', 'text' => '<p>Hallo</p><p><img data-image="' . $uid . '" width="100"></p>', 'to' => ['users' => [$this->m]]])->data;
        $img = $this->app->richImages()->repository()->find($uid);
        $this->assertSame([RichImageService::OWNER_MAIL, $res['id']], [$img['owner_type'], $img['owner_id']], 'Bild gehört der Mail');
        $this->assertStringContainsString('src="', $res['text'], 'im Verlauf mit Adresse');
        $this->assertSame(200, $this->app->richImages()->forDelivery($this->users->find($this->admin), $uid)['status']);
        $this->assertSame(403, $this->app->richImages()->forDelivery($this->users->find($this->m), $uid)['status'], 'Mitglieder ohne send_mail nicht');

        $this->app->runTask(BoardMailService::TASK);
        $mail = $this->mailer->sent[0];
        $this->assertStringContainsString('src="cid:' . $uid . '"', $mail->html, 'eingebettet');
        $this->assertSame([$uid], array_column($mail->images, 'cid'));
    }

    public function testSharedQueueBatchingAndRetries(): void
    {
        $this->configure(['batch_size' => 2, 'batch_pause' => 30]);
        $this->app->boardMail()->setWants($this->admin, false); // Verfasser ohne eigene Mail vom Brett
        $this->call('POST', '/board', ['title' => 'Brett', 'text' => '<p>x</p>', 'push' => false]); // 3 Empfänger: Mitglieder mit Adresse und Anwärter
        $this->call('POST', '/mails', ['subject' => 'Verwaltung', 'text' => '<p>y</p>', 'to' => ['users' => [$this->m, $this->m2]]]);
        $this->app->runTask(BoardMailService::TASK);
        $this->assertCount(2, $this->mailer->sent, 'Portion gilt für alle Mails zusammen');
        $this->app->runTask(BoardMailService::TASK);
        $this->assertCount(2, $this->mailer->sent, 'Pause gilt für alle Mails zusammen');
        $at = static fn (string $t): \DateTimeImmutable => new \DateTimeImmutable('2026-10-25 ' . $t, new \DateTimeZone('Europe/Berlin'));
        $this->clock->at = $at('12:31:00');
        $this->app->runTask(BoardMailService::TASK);
        $this->assertSame(['Brett', 'Brett', 'Brett', 'Verwaltung'], array_map(static fn (Mail $m): string => $m->subject, $this->mailer->sent), 'erst das Brett, dann die Verwaltung');
        // Vorübergehender Fehler: nach Pause erneut; dauerhafter Fehler: nicht mehr
        $this->mailer->results['zweites@example.org'] = [new MailResult(false, 'Verbindung weg'), new MailResult(false, 'Postfach unbekannt', true, 550)];
        $this->clock->at = $at('13:02:00');
        $this->app->runTask(BoardMailService::TASK);
        $this->assertSame(1, $this->call('GET', '/admin/mail')->data['pending']);
        $this->clock->at = $at('13:40:00');
        $this->app->runTask(BoardMailService::TASK);
        $detail = $this->call('GET', '/mails')->data['items'][0];
        $this->assertSame(['recipients' => 2, 'sent' => 1, 'failed' => 1, 'pending' => 0, 'cancelled' => 0], $detail['mail'], 'dauerhafter Fehler: nicht erneut');
        $this->assertSame('Postfach unbekannt', $this->call('GET', '/mails/' . $detail['id'])->data['log'][1]['error']);
        $this->assertSame(0, $this->call('GET', '/admin/mail')->data['pending']);
    }

    public function testQueueRunsWithoutBoardAndIsScheduled(): void
    {
        $this->call('PUT', '/admin/features', ['board' => false]);
        $this->configure();
        $this->assertArrayHasKey(BoardMailService::TASK, $this->scheduler->scheduled, 'Versand auch ohne Brett eingeplant');
        $this->assertArrayHasKey(MailingService::CLEANUP_TASK, $this->scheduler->scheduled);
        $this->call('POST', '/mails', ['subject' => 'Ohne Brett', 'text' => '<p>y</p>', 'to' => ['users' => [$this->m]]]);
        $this->app->runTask(BoardMailService::TASK);
        $this->assertSame(['mitglied@example.org'], $this->mailer->recipients());
    }

    public function testCleanupPurgeAndBackup(): void
    {
        $this->configure();
        $uid = $this->call('POST', '/rich-images', ['file' => ['name' => 'bild.png', 'data' => base64_encode($this->png())]])->data['uid'];
        $id = $this->call('POST', '/mails', ['subject' => 'Alt', 'text' => '<p><img data-image="' . $uid . '"></p>', 'to' => ['users' => [$this->m, $this->m2]]])->data['id'];

        UserData::purge($this->app->db, $this->m2);
        $this->assertSame(1, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {mailing_recipients} WHERE mailing_id = ?', [$id]), 'Protokoll mit Adresse der gelöschten Person entfernt');

        $this->assertContains('mailings', Backup::CORE_TABLES);
        $this->assertNotContains('mailing_recipients', Backup::CORE_TABLES, 'Protokoll mit Adressen nicht in Sicherungen');

        $this->clock->at = new \DateTimeImmutable('2027-10-25 12:00:00', new \DateTimeZone('Europe/Berlin'));
        $this->app->runTask(MailingService::CLEANUP_TASK);
        $this->assertSame(1, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {mailings}'), 'genau ein Jahr: bleibt');
        $this->clock->at = new \DateTimeImmutable('2027-10-26 13:00:00', new \DateTimeZone('Europe/Berlin'));
        $this->app->runTask(MailingService::CLEANUP_TASK);
        $this->assertSame(0, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {mailings}'));
        $this->assertSame(0, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {mailing_recipients}'));
        $this->assertNull($this->app->richImages()->repository()->find($uid), 'samt Bildern');
    }

    /**
     * Mit PHPMailer über einen kleinen SMTP-Server auf localhost: zugestellt (RCPT TO) wird nur an den einen Empfänger,
     * die Kopfzeilen zeigen An und Cc, nie Bcc.
     */
    public function testStandardGroupsReachAdminsAndOwnRolesButNotCandidatesOrGuests(): void
    {
        $this->configure();
        // Eigene Grundrolle mit Mitgliederbereich (z. B. Spieß): von der Rolle „Mitglied“ nicht erfasst
        $this->assertSame(201, $this->call('POST', '/admin/roles', ['label' => 'Spieß', 'capabilities' => ['member']])->status);
        $spiess = $this->user('spiess', (string) array_column($this->call('GET', '/admin/roles')->data, 'key', 'label')['Spieß'], 'spiess@example.org', 'Sepp Spieß');

        $groups = array_column($this->call('GET', '/mails/options')->data['groups'], null, 'key');
        $this->assertSame(['members', 'all'], array_keys($groups), 'Standardgruppen zuerst');
        $this->assertSame(['Alle Mitglieder', true, 5, 4], [$groups['members']['label'], $groups['members']['standard'], $groups['members']['members'], $groups['members']['with_email']], 'Admin, zwei Mitglieder, ohne Adresse, Spieß');
        $this->assertSame([6, 5], [$groups['all']['members'], $groups['all']['with_email']], 'dazu der Anwärter, keine Gäste');

        $preview = $this->call('POST', '/mails/preview', ['to' => ['groups' => ['members']]])->data;
        $this->assertSame([4, ['Otto Ohneadresse']], [$preview['recipients'], $preview['skipped']]);
        $this->assertSame(5, $this->call('POST', '/mails/preview', ['bcc' => ['groups' => ['all']]])->data['recipients']);
        $this->assertSame(2, $this->call('POST', '/mails/preview', ['to' => ['roles' => [Roles::MEMBER]]])->data['recipients'], 'Rolle allein: nur Grundrolle Mitglied');

        $res = $this->call('POST', '/mails', ['subject' => 'An alle', 'text' => '<p>x</p>', 'to' => ['groups' => ['members']]]);
        $this->assertSame(201, $res->status, (string) json_encode($res->data));
        $this->assertSame([['key' => 'members', 'label' => 'Alle Mitglieder']], $res->data['selection']['to']['groups']);
        $emails = array_column($res->data['log'], 'email');
        $this->assertContains('admin@example.org', $emails);
        $this->assertContains('spiess@example.org', $emails);
        $this->assertNotContains('anwaerter@example.org', $emails);
        $this->assertNotContains('gast@example.org', $emails);
        unset($spiess);

        // Validierung: unbekannte Gruppe; Auswahl ohne groups geht weiter
        $this->assertSame(400, $this->call('POST', '/mails/preview', ['to' => ['groups' => ['gibtsnicht']]])->status);
        $this->assertSame(200, $this->call('POST', '/mails/preview', ['to' => ['users' => [$this->m]]])->status);
    }

    public function testOwnGroupsCanBeManagedAndStandardGroupsOnlyRenamed(): void
    {
        $this->configure();
        $this->assertSame(403, $this->as($this->m)->call('GET', '/mails/groups')->status, 'nur mit send_mail');
        $this->as($this->admin);

        $res = $this->call('POST', '/mails/groups', ['label' => 'Vorstand', 'users' => [$this->m], 'roles' => [TestApp::candidateRole($this->app)], 'emails' => ['extern@example.net']]);
        $this->assertSame(201, $res->status, (string) json_encode($res->data));
        $own = array_values(array_filter($res->data, static fn (array $g): bool => !$g['standard']))[0];
        $this->assertSame(['Vorstand', [$this->m], ['extern@example.net'], 2, 2], [$own['label'], $own['users'], $own['emails'], $own['members'], $own['with_email']]);

        $this->assertSame(400, $this->call('POST', '/mails/groups', ['label' => 'vorstand'])->status, 'Bezeichnung eindeutig');
        $this->assertSame(400, $this->call('POST', '/mails/groups', ['label' => 'Alle Konten'])->status, 'auch gegenüber Standardgruppen');
        $this->assertSame(400, $this->call('POST', '/mails/groups', ['label' => ''])->status);
        $this->assertSame(400, $this->call('POST', '/mails/groups', ['label' => 'Gäste', 'users' => [$this->guest]])->status, 'keine Gäste');

        // Auflösung: Konto, Rolle und freie Adresse; Doppelte nur einmal, An vor Cc
        $preview = $this->call('POST', '/mails/preview', ['to' => ['users' => [$this->m]], 'cc' => ['groups' => [$own['key']]]])->data;
        $this->assertSame(['recipients' => 3, 'to' => 1, 'cc' => 2, 'bcc' => 0, 'skipped' => []], $preview);

        // Umbenennen und Inhalt ändern; Standardgruppe nur umbenennen, nicht löschen
        $this->assertSame(200, $this->call('PATCH', '/mails/groups/' . $own['key'], ['label' => 'Vorstand neu', 'emails' => []])->status);
        $group = array_column($this->call('GET', '/mails/groups')->data, null, 'key')[$own['key']];
        $this->assertSame(['Vorstand neu', [$this->m], []], [$group['label'], $group['users'], $group['emails']], 'nicht Mitgeschicktes bleibt');
        $this->assertSame(400, $this->call('PATCH', '/mails/groups/members', ['users' => [$this->m]])->status);
        $this->assertSame(200, $this->call('PATCH', '/mails/groups/members', ['label' => 'Ganze Kompanie'])->status);
        $this->assertSame('Ganze Kompanie', array_column($this->call('GET', '/mails/options')->data['groups'], 'label', 'key')['members']);
        $this->assertSame(400, $this->call('DELETE', '/mails/groups/all')->status);

        // Gesendete Mail mit gelöschter Gruppe
        $sent = $this->call('POST', '/mails', ['subject' => 'Vorstand', 'text' => '<p>x</p>', 'to' => ['groups' => [$own['key']]]])->data;
        $this->assertSame(200, $this->call('DELETE', '/mails/groups/' . $own['key'])->status);
        $this->assertSame(404, $this->call('DELETE', '/mails/groups/' . $own['key'])->status);
        $this->assertSame('Gelöschte Gruppe', $this->call('GET', '/mails/' . $sent['id'])->data['selection']['to']['groups'][0]['label']);
        $this->assertSame(400, $this->call('POST', '/mails/preview', ['to' => ['groups' => [$own['key']]]])->status, 'gelöschte Gruppe nicht mehr wählbar');

        // In der Datensicherung (Einstellung)
        $this->assertIsArray($this->app->settings->get('mail_groups'));
    }

    public function testFormerMembersNeverGetMailsThroughGroups(): void
    {
        $this->configure();
        $res = $this->call('POST', '/mails/groups', ['label' => 'Festausschuss', 'users' => [$this->m, $this->m2]]);
        $key = array_values(array_filter($res->data, static fn (array $g): bool => !$g['standard']))[0]['key'];

        $this->assertSame(200, $this->call('PUT', "/members/{$this->m}/former", ['reason' => 'left'])->status);

        $groups = array_column($this->call('GET', '/mails/groups')->data, null, 'key');
        $this->assertSame([$this->m2], $groups[$key]['users'], 'Ehemalige fallen aus dem Inhalt');
        $this->assertSame([3, 2], [$groups['members']['members'], $groups['members']['with_email']], 'Admin, zweites Mitglied, ohne Adresse');
        $this->assertSame(4, $groups['all']['members']);
        foreach ([['groups' => [$key]], ['groups' => ['members']], ['groups' => ['all']], ['roles' => [Roles::MEMBER]]] as $sel) {
            $res = $this->call('POST', '/mails', ['subject' => 'Test', 'text' => '<p>x</p>', 'to' => $sel]);
            $this->assertSame(201, $res->status, (string) json_encode($res->data));
            $this->assertNotContains('mitglied@example.org', array_column($res->data['log'], 'email'), (string) json_encode($sel));
        }

        // Gruppe mit eingetragenem Ehemaligen lässt sich weiter bearbeiten
        $this->assertSame(200, $this->call('PATCH', '/mails/groups/' . $key, ['label' => 'Festausschuss 2027'])->status);
    }

    public function testPhpMailerDeliversToOneRecipientWithVisibleHeaders(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'smtp');
        $port = $this->startSmtpServer($log);
        $mailer = new PhpMailerMailer(['host' => '127.0.0.1', 'port' => $port, 'secure' => 'none', 'user' => '', 'password' => '', 'from' => 'info@musterkompanie.example', 'from_name' => 'Musterkompanie']);
        $to = [['email' => 'a@example.org', 'name' => 'Anna'], ['email' => 'b@example.org', 'name' => 'Bert']];
        $cc = [['email' => 'c@example.org', 'name' => '']];
        $this->assertTrue($mailer->send(new Mail('geheim@example.org', '', 'Betreff', '<p>x</p>', 'x', [], $to, $cc))->ok, 'Bcc-Empfänger');
        $this->assertTrue($mailer->send(new Mail('b@example.org', 'Bert', 'Nur Bcc', '<p>x</p>', 'x', [], [], []))->ok);
        $mailer->close();
        $deadline = microtime(true) + 5;
        while (!str_contains((string) file_get_contents($log), 'QUIT') && microtime(true) < $deadline) {
            usleep(20000);
        }
        $session = (string) file_get_contents($log);
        @unlink($log);
        preg_match_all('/^RCPT TO:<([^>]+)>/mi', $session, $rcpt);
        $this->assertSame(['geheim@example.org', 'b@example.org'], $rcpt[1], 'je Mail genau ein Empfänger');
        [$first, $second] = array_slice(explode("\nDATA\n", str_replace("\r\n", "\n", $session)), 1, 2);
        $this->assertMatchesRegularExpression('/^To: Anna <a@example\.org>, Bert <b@example\.org>$/m', $first);
        $this->assertMatchesRegularExpression('/^Cc: c@example\.org$/m', $first);
        $this->assertStringNotContainsString('geheim@example.org', $first, 'Bcc nicht in den Kopfzeilen');
        $this->assertMatchesRegularExpression('/^To: undisclosed-recipients:;$/m', $second, 'nur Bcc');
        $this->assertStringNotContainsString('b@example.org', explode("\n\n", $second, 2)[0]);
    }

    /** Startet einen SMTP-Server für eine Verbindung in einem eigenen PHP-Prozess; Rückgabe: Port. */
    private function startSmtpServer(string $log): int
    {
        $script = <<<'PHP'
            <?php
            [$_, $log] = $argv;
            $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
            fwrite(STDOUT, (string) parse_url('tcp://' . stream_socket_get_name($server, false), PHP_URL_PORT) . "\n");
            fflush(STDOUT);
            $conn = stream_socket_accept($server, 10);
            $out = fopen($log, 'a');
            $say = static fn (string $s) => fwrite($conn, $s . "\r\n");
            $say('220 test ESMTP');
            $data = false;
            while (($line = fgets($conn)) !== false) {
                fwrite($out, $line);
                $cmd = strtoupper(substr(trim($line), 0, 4));
                if ($data) {
                    if (rtrim($line, "\r\n") === '.') {
                        $data = false;
                        $say('250 OK');
                    }
                    continue;
                }
                match (true) {
                    $cmd === 'EHLO' => $say("250-test\r\n250 8BITMIME"),
                    $cmd === 'DATA' => [$data = true, $say('354 go')],
                    $cmd === 'QUIT' => [$say('221 bye'), fclose($conn), exit(0)],
                    default => $say('250 OK'),
                };
            }
            PHP;
        $file = tempnam(sys_get_temp_dir(), 'smtpsrv') . '.php';
        file_put_contents($file, $script);
        $proc = proc_open([PHP_BINARY, $file, $log], [1 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);
        $port = (int) trim((string) fgets($pipes[1]));
        $this->assertGreaterThan(0, $port);
        register_shutdown_function(static function () use ($proc, $file): void {
            @proc_terminate($proc);
            @unlink($file);
        });
        return $port;
    }
}
