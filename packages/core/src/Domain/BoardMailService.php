<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Mail\Mail;
use KompanieApp\Core\Port\Mailer;
use KompanieApp\Core\Storage\UserSettings;

/**
 * Nachrichten vom Schwarzen Brett zusätzlich per E-Mail (Issue #172).
 *
 * - Nur beim ersten Senden, mit dem Haken „E-Mail senden“ (Standard an); Ändern verschickt keine neue Mail.
 * - Empfänger: dieselbe Regel wie fürs Brett (BoardService::isRecipient), nur Konten mit Mailadresse, die den Empfang
 *   unter Konto → Benachrichtigungen nicht abgeschaltet haben (user_settings board_mail = 0; Standard an), ohne den
 *   Verfasser (wie bei der Benachrichtigung).
 * - Jede Mail einzeln an einen Empfänger; Absender ist die Kompanieadresse (MailService), Betreff der Titel, HTML- und
 *   Text-Teil, Bilder eingebettet (cid:), weil die Bildadressen der App eine Anmeldung brauchen, Dateianhänge der
 *   Nachricht als Anhänge der Mail. Fußzeile mit Link zur App, Verfasser und Hinweis zum Abschalten.
 * - Versand im Hintergrund (Aufgabe board_mail, nach dem Einreihen vorgezogen, sonst alle 15 Minuten), in Portionen
 *   (MailService::batching) innerhalb eines Zeitbudgets je Lauf; zusammen mit den E-Mails aus der Verwaltung
 *   (MailingService) in einer Warteschlange (MailService::runQueue), damit Portionen und Pausen für alle Mails gelten. Vorübergehende Fehler (Verbindung, 4xx) werden bis zu
 *   MAX_ATTEMPTS Mal erneut versucht, dauerhafte (5xx, z. B. 552 „zu groß“) nicht. board_mails ist zugleich das
 *   Protokoll (gesendet / fehlgeschlagen mit Grund), sichtbar für post_board.
 * - Meldet der Mailserver eine Höchstgröße (SIZE), wird die fertige Mail vor dem Einreihen dagegen geprüft; sonst gibt
 *   es keine Prüfung vorab.
 */
final class BoardMailService
{
    public const TASK = 'board_mail';
    public const INTERVAL = 900;
    public const MAX_ATTEMPTS = 5;
    public const SETTING_OPT = 'board_mail';
    /** Einstellung: frühester nächster Lauf nach einer Portion (MailService::NEXT_SETTING). */
    public const NEXT_SETTING = MailService::NEXT_SETTING;

    public const PENDING = 'pending';
    public const SENT = 'sent';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';

    public function __construct(private readonly App $app)
    {
    }

    /** Ist der Versand möglich (Bereich an, Mail eingerichtet)? */
    public function available(): bool
    {
        return $this->app->features()->enabled('board') && $this->app->mail()->configured();
    }

    /** @return array{available: bool, recipients: int, reason: string|null} für das Formular „Neue Nachricht“ */
    public function info(User $author): array
    {
        $available = $this->available();
        return [
            'available' => $available,
            'recipients' => $available ? count($this->recipients($author->id)) : 0,
            'reason' => $available ? null : ($this->app->mail()->unavailable() ?? 'Der Mailversand ist noch nicht eingerichtet (Einstellungen → E-Mail).'),
            'server_max_size' => $this->app->mail()->serverMaxSize(),
        ];
    }

    /** Möchte das Konto Mails vom Brett (Standard ja)? */
    public function wants(int $userId): bool
    {
        return (new UserSettings($this->app->db))->get($userId, self::SETTING_OPT) !== '0';
    }

    public function setWants(int $userId, mixed $on): bool
    {
        if (!is_bool($on)) {
            throw ApiError::invalid('board muss true oder false sein.');
        }
        (new UserSettings($this->app->db))->set($userId, self::SETTING_OPT, $on ? '1' : '0');
        return $on;
    }

    /**
     * Empfänger: Konto-ID => [Name, Adresse].
     *
     * @return array<int, array{name: string, email: string}>
     */
    public function recipients(int $authorId): array
    {
        $optOut = array_filter((new UserSettings($this->app->db))->all(self::SETTING_OPT), static fn (string $v): bool => $v === '0');
        $users = array_filter(
            $this->app->users->list(false),
            static fn (User $u): bool => $u->id !== $authorId && !isset($optOut[$u->id]) && BoardService::isRecipient($u),
        );
        $emails = $this->app->users->emails(array_values(array_map(static fn (User $u): int => $u->id, $users)));
        $out = [];
        foreach ($users as $u) {
            $email = trim($emails[$u->id] ?? '');
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $out[$u->id] = ['name' => $u->name, 'email' => $email];
            }
        }
        return $out;
    }

    /**
     * Vor dem Senden: Passt die fertige Mail (mit eingebetteten Bildern) zur gemeldeten Höchstgröße des Servers? Ohne
     * gemeldetes Limit keine Prüfung. Wirft eine verständliche Meldung, bevor etwas veröffentlicht oder verschickt wird.
     */
    /** @param list<array<string, mixed>> $files Anhänge (AttachmentService::resolve) */
    public function checkSize(string $title, string $body, User $author, array $files = []): void
    {
        $max = $this->serverMaxSize();
        if ($max === null) {
            return;
        }
        $mail = $this->compose(0, $title, $body, $author->name, ['name' => 'Empfänger', 'email' => 'empfaenger@example.org'], $this->app->attachments()->forMail($files));
        $size = $this->app->mailer()->measure($mail);
        if ($size > $max) {
            throw ApiError::invalid(sprintf(
                'Die Mail ist mit Bildern und Anhängen %s groß, der Mailserver nimmt höchstens %s an. Bilder oder Anhänge verkleinern oder ohne E-Mail senden.',
                ServerLimits::format($size),
                ServerLimits::format($max),
            ));
        }
    }

    /**
     * Mails für eine neue Nachricht einreihen und den Versand anstoßen; vermerkt den Versand im Verlauf.
     *
     * @return int Zahl der Empfänger
     */
    public function queue(int $messageId, User $author): int
    {
        if (!$this->available()) {
            throw ApiError::invalid('Der Mailversand ist nicht eingerichtet.');
        }
        $recipients = $this->recipients($author->id);
        $now = $this->app->time()->nowUtc();
        $this->app->db->transaction(function () use ($recipients, $messageId, $now): void {
            foreach ($recipients as $userId => $r) {
                $this->app->db->insert('board_mails', [
                    'message_id' => $messageId, 'user_id' => $userId, 'email' => $r['email'], 'status' => self::PENDING,
                    'attempts' => 0, 'error' => null, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        });
        $this->app->board()->noteMailed($messageId, $author->id, count($recipients));
        if ($recipients !== []) {
            $this->app->scheduler->runSoon(self::TASK);
        }
        return count($recipients);
    }

    /** Nachricht gelöscht: Was noch nicht verschickt ist, geht nicht mehr raus. */
    public function cancel(int $messageId): void
    {
        $this->app->db->execute(
            'UPDATE {board_mails} SET status = ?, updated_at = ? WHERE message_id = ? AND status = ?',
            [self::CANCELLED, $this->app->time()->nowUtc(), $messageId, self::PENDING],
        );
    }

    /**
     * Aufgabe board_mail: wartende Mails versenden, die vom Brett und die aus der Verwaltung (MailService::runQueue).
     *
     * @return array{sent: int, failed: int, pending: int}
     */
    public function runTask(): array
    {
        return $this->app->mail()->runQueue();
    }

    /**
     * Wartende Mails vom Brett versenden (Teil von MailService::runQueue): höchstens $limit Versuche (0 = ohne
     * Grenze), solange das Zeitbudget ab $start reicht.
     *
     * @return array{sent: int, failed: int, processed: int}
     */
    public function sendDue(Mailer $mailer, int $limit, float $start, int $nowTs): array
    {
        $stats = ['sent' => 0, 'failed' => 0, 'processed' => 0];
        $messages = [];
        $files = [];
        $names = new UserNames($this->app->users);
        foreach ($this->due($nowTs, $limit) as $row) {
            if (microtime(true) - $start > MailService::BUDGET) {
                break;
            }
            $mid = (int) $row['message_id'];
            $message = $messages[$mid] ??= $this->app->board()->repository()->find($mid);
            if ($message === null || $message['deleted_at'] !== null) {
                $this->mark((int) $row['id'], self::CANCELLED, (int) $row['attempts'], null);
                continue;
            }
            $recipient = ['name' => (string) ($this->app->users->find((int) $row['user_id'])?->name ?? ''), 'email' => (string) $row['email']];
            $files[$mid] ??= $this->app->attachments()->forMail($this->app->attachments()->of(AttachmentService::OWNER_BOARD, $mid));
            $mail = $this->compose($mid, $message['title'], $message['body'], (string) $names->name($message['author_id']), $recipient, $files[$mid]);
            $result = $mailer->send($mail);
            $attempts = (int) $row['attempts'] + 1;
            $stats['processed']++;
            if ($result->ok) {
                $this->mark((int) $row['id'], self::SENT, $attempts, null);
                $stats['sent']++;
            } else {
                $final = $result->permanent || $attempts >= self::MAX_ATTEMPTS;
                $this->mark((int) $row['id'], $final ? self::FAILED : self::PENDING, $attempts, $result->error);
                $stats['failed'] += $final ? 1 : 0;
                error_log('Kompanie-App: Mail vom Schwarzen Brett an ' . $row['email'] . ' nicht gesendet: ' . $result->error);
            }
        }
        return $stats;
    }

    public function pendingCount(): int
    {
        return (int) $this->app->db->scalar('SELECT COUNT(*) FROM {board_mails} WHERE status = ?', [self::PENDING]);
    }

    /**
     * Stand des Versands je Nachricht (für post_board).
     *
     * @param list<int> $messageIds
     * @return array<int, array{recipients: int, sent: int, failed: int, pending: int, cancelled: int}>
     */
    public function summaries(array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }
        $out = [];
        $rows = $this->app->db->select(
            'SELECT message_id, status, COUNT(*) AS n FROM {board_mails} WHERE message_id IN (' . implode(',', array_fill(0, count($messageIds), '?')) . ') GROUP BY message_id, status',
            $messageIds,
        );
        foreach ($rows as $r) {
            $id = (int) $r['message_id'];
            $out[$id] ??= ['recipients' => 0, 'sent' => 0, 'failed' => 0, 'pending' => 0, 'cancelled' => 0];
            $status = (string) $r['status'];
            if (isset($out[$id][$status])) {
                $out[$id][$status] = (int) $r['n'];
            }
            $out[$id]['recipients'] += (int) $r['n'];
        }
        return $out;
    }

    /**
     * Protokoll je Empfänger (für post_board): Name, Adresse, Stand, Versuche, Grund.
     *
     * @return list<array{name: string|null, email: string, status: string, attempts: int, error: string|null, at: string|null}>
     */
    public function log(int $messageId): array
    {
        $rows = $this->app->db->select('SELECT * FROM {board_mails} WHERE message_id = ? ORDER BY status, id', [$messageId]);
        $names = (new UserNames($this->app->users))->prime(array_column($rows, 'user_id'));
        return array_map(static fn (array $r): array => [
            'name' => $names->name((int) $r['user_id']),
            'email' => (string) $r['email'],
            'status' => (string) $r['status'],
            'attempts' => (int) $r['attempts'],
            'error' => $r['error'] === null || $r['error'] === '' ? null : (string) $r['error'],
            'at' => Time::utcToIso((string) $r['updated_at']),
        ], $rows);
    }

    /**
     * Die fertige Mail für einen Empfänger.
     *
     * @param array{name: string, email: string} $recipient
     * @param list<array{name: string, mime: string, path: string}> $attachments
     */
    public function compose(int $messageId, string $title, string $body, string $authorName, array $recipient, array $attachments = []): Mail
    {
        $images = [];
        $html = (string) preg_replace_callback('/<img data-image="([a-f0-9]{32})"([^>]*)>/', function (array $m) use (&$images): string {
            $img = $this->app->richImages()->bytes($m[1]);
            if ($img === null) {
                return '';
            }
            $images[$m[1]] = ['cid' => $m[1], 'mime' => $img['mime'], 'bytes' => $img['bytes'], 'name' => $m[1] . ($img['mime'] === 'image/png' ? '.png' : '.jpg')];
            return '<img src="cid:' . $m[1] . '"' . $m[2] . '>';
        }, $body);
        $board = $this->app->config->sectionLabel('board', 'Schwarzes Brett');
        $url = $this->app->env->appUrl() . '#/brett' . ($messageId > 0 ? '/' . $messageId : '');
        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $footer = sprintf('%s: Nachricht von %s', $board, $authorName === '' ? UserNames::DELETED : $authorName);
        $optOut = 'Du bekommst diese Mail, weil du in der App „' . $this->app->config->name() . '“ Nachrichten aus dem Bereich „' . $board
            . '“ per E-Mail erhältst. Abschalten kannst du das in der App unter Konto → Benachrichtigungen.';
        $document = '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>' . $esc($title) . '</title></head>'
            . '<body style="margin: 0; padding: 16px; font-family: Arial, Helvetica, sans-serif; font-size: 15px; line-height: 1.5; color: #222222; background-color: #ffffff">'
            . '<h1 style="font-size: 20px; line-height: 1.3; margin: 0 0 12px 0">' . $esc($title) . '</h1>'
            . '<div>' . $html . '</div>'
            . '<hr style="border: none; border-top: 1px solid #cccccc; margin: 24px 0 12px 0">'
            . '<p style="font-size: 13px; color: #555555; margin: 0 0 8px 0">' . $esc($footer) . ' – <a href="' . $esc($url) . '">in der App ansehen</a></p>'
            . '<p style="font-size: 12px; color: #777777; margin: 0">' . $esc($optOut) . '</p>'
            . '</body></html>';
        $text = $title . "\n\n" . RichText::excerpt($body) . "\n\n-- \n" . $footer . "\nIn der App ansehen: " . $url . "\n\n" . $optOut . "\n";
        return new Mail($recipient['email'], $recipient['name'], $title, $document, $text, array_values($images), attachments: $attachments);
    }

    /**
     * Limit des Servers; ist es noch nie gelesen worden, einmal verbinden. Scheitert das, wird „kein Limit bekannt“
     * gemerkt (keine Prüfung vorab, kein erneutes Warten bei der nächsten Nachricht); der nächste Versandlauf und
     * „Verbindung prüfen“ lesen es neu.
     */
    public function serverMaxSize(): ?int
    {
        $mail = $this->app->mail();
        if ($this->app->settings->get(MailService::SIZE_SETTING) === null) {
            $check = $this->app->mailer()->check();
            $mail->rememberServerSize($check['ok'] ? $check['size'] : null);
            return $check['ok'] ? $check['size'] : null;
        }
        return $mail->serverMaxSize();
    }

    /**
     * Fällige Mails: wartend, nach einem Fehler erst nach einer Pause (MailService::isDue).
     *
     * @return list<array<string, mixed>>
     */
    private function due(int $nowTs, int $limit): array
    {
        $rows = $this->app->db->select('SELECT id, message_id, user_id, email, attempts, updated_at FROM {board_mails} WHERE status = ? ORDER BY id', [self::PENDING]);
        $out = [];
        foreach ($rows as $r) {
            if (!MailService::isDue((int) $r['attempts'], (string) $r['updated_at'], $nowTs)) {
                continue;
            }
            $out[] = $r;
            if ($limit > 0 && count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    private function mark(int $id, string $status, int $attempts, ?string $error): void
    {
        $this->app->db->update('board_mails', [
            'status' => $status, 'attempts' => $attempts, 'error' => $error === null ? null : mb_substr($error, 0, 1000), 'updated_at' => $this->app->time()->nowUtc(),
        ], ['id' => $id]);
    }
}
