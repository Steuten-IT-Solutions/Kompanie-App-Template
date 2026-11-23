<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\I18n\I18n;
use KompanieApp\Core\Mail\Mail;
use KompanieApp\Core\Mail\MailResult;
use KompanieApp\Core\Port\Mailer;
use KompanieApp\Core\Push\PushService;
use KompanieApp\Core\Storage\UserSettings;

/**
 * Benachrichtigungen zusätzlich per E-Mail.
 *
 * - Einstellung je Konto (nicht je Gerät wie Push): user_settings notify_mail_off = abgeschaltete Arten, durch Kommas
 *   getrennt. Fehlt der Eintrag, sind alle Arten an; neue Arten (neuer Bereich, neues Recht) kommen ebenfalls an.
 * - Arten und Empfängerkreis wie bei Push (PushService::availableTypes und die Regeln der notify*-Aufrufe), aber ohne
 *   Abo eines Geräts: Mails bekommt jedes Konto mit Mailadresse, das die Art nicht abgeschaltet hat. Gäste ohne Konto
 *   bekommen keine Mails.
 * - Ohne die Arten des Schwarzen Bretts: Dafür gibt es die Mails vom Brett mit vollem Inhalt und Anhängen
 *   (BoardMailService, eigener Schalter board_mail).
 * - Inhalt wie die kurze Benachrichtigung (bei Strafen ohne Betrag und Grund, wie Push ohne „Details zeigen“), dazu ein
 *   Link in die App und der Hinweis zum Abschalten, Fußzeile in der Sprache des Empfängers.
 * - Warteschlange und kurzes Protokoll in notification_mails (nicht in Sicherungen); versendet von der Aufgabe board_mail
 *   zusammen mit den übrigen Mails (MailService::runQueue), mit denselben Portionen, Pausen und Wiederholungen.
 *   Erledigte Einträge werden nach KEEP_DAYS Tagen gelöscht (MailingService::CLEANUP_TASK).
 * - Vor dem Versand wird erneut geprüft, ob das Konto die Art noch bekommt; sonst wird die Mail abgebrochen.
 *
 * @phpstan-import-type Message from PushService
 */
final class NotificationMailService
{
    public const SETTING_OFF = 'notify_mail_off';
    /** Arten ohne Mail hier: die Nachrichten vom Brett gehen über BoardMailService. */
    public const EXCLUDED = [PushService::TYPE_BOARD, PushService::TYPE_BOARD_PUBLIC];
    public const KEEP_DAYS = 30;
    public const MAX_ATTEMPTS = BoardMailService::MAX_ATTEMPTS;

    /** @var array<int, list<string>>|null Benutzer-ID => abgeschaltete Arten, je Anfrage gemerkt */
    private ?array $off = null;
    /** @var array<int, list<string>> Benutzer-ID => Arten per Mail, je Anfrage gemerkt */
    private array $types = [];
    /** @var array<int, User>|null alle Konten, je Anfrage gemerkt */
    private ?array $accounts = null;

    public function __construct(private readonly App $app)
    {
    }

    /** Ist der Mailversand eingerichtet? */
    public function available(): bool
    {
        return $this->app->mail()->configured();
    }

    /** @return list<string> Arten, die dieses Konto per Mail bekommen kann (Gäste: keine) */
    public function availableTypes(?User $user): array
    {
        if ($user === null || $user->id <= 0) {
            return [];
        }
        return array_values(array_diff($this->app->push()->availableTypes($user), self::EXCLUDED));
    }

    /** @return list<string> abgeschaltete Arten des Kontos */
    public function offFor(int $userId): array
    {
        if ($this->off === null) {
            $this->off = [];
            foreach ((new UserSettings($this->app->db))->all(self::SETTING_OFF) as $uid => $value) {
                $this->off[$uid] = array_values(array_filter(explode(',', $value), static fn (string $t): bool => $t !== ''));
            }
        }
        return $this->off[$userId] ?? [];
    }

    /** @return list<string> Arten, die das Konto zurzeit per Mail bekommt */
    public function chosen(User $user): array
    {
        return $this->types[$user->id] ??= array_values(array_diff($this->availableTypes($user), $this->offFor($user->id)));
    }

    /**
     * Für Konto → Benachrichtigungen.
     *
     * @return array{available: bool, types: list<array{key: string, label: string}>, selected: list<string>}
     */
    public function settings(User $user): array
    {
        $labels = $this->app->push()->types();
        return [
            'available' => $this->available(),
            'types' => array_map(static fn (string $k): array => ['key' => $k, 'label' => $labels[$k]['label']], $this->availableTypes($user)),
            'selected' => $this->chosen($user),
        ];
    }

    /**
     * Arten per Mail festlegen: $types = die gewünschten; alle anderen angebotenen werden abgeschaltet. Abgeschaltete
     * Arten, die das Konto zurzeit nicht angeboten bekommt (z. B. Bereich aus), bleiben abgeschaltet.
     *
     * @return list<string> Arten per Mail
     */
    public function choose(User $user, mixed $types): array
    {
        if (!is_array($types) || array_filter($types, static fn (mixed $t): bool => !is_string($t)) !== []) {
            throw ApiError::invalid(I18n::t('types muss eine Liste von Arten sein.'));
        }
        $available = $this->availableTypes($user);
        $wanted = array_values(array_intersect($available, $types));
        $hidden = array_diff($this->offFor($user->id), $available);
        $off = array_values(array_unique([...$hidden, ...array_diff($available, $wanted)]));
        $settings = new UserSettings($this->app->db);
        if ($off === []) {
            $settings->delete($user->id, self::SETTING_OFF);
        } else {
            $settings->set($user->id, self::SETTING_OFF, implode(',', $off));
        }
        $this->off = null;
        unset($this->types[$user->id]);
        return $this->chosen($user);
    }

    /** @return array<int, User> alle Konten (ohne Gäste ohne Konto) */
    private function accounts(): array
    {
        if ($this->accounts === null) {
            $this->accounts = [];
            foreach ($this->app->users->list(false) as $u) {
                $this->accounts[$u->id] = $u;
            }
        }
        return $this->accounts;
    }

    /**
     * Je Konto eine eigene Nachricht (PushService::notifyUsers).
     *
     * @param list<array{0: int, 1: Message, 2?: Message|null}> $messages
     */
    public function toUsers(string $type, array $messages): int
    {
        if ($messages === [] || !$this->available() || in_array($type, self::EXCLUDED, true)) {
            return 0;
        }
        $accounts = $this->app->users->findMany(array_values(array_unique(array_map(static fn (array $m): int => $m[0], $messages))));
        $items = [];
        foreach ($messages as $m) {
            $user = $accounts[$m[0]] ?? null;
            if ($user !== null && in_array($type, $this->chosen($user), true)) {
                $items[] = [$user->id, $m[1]];
            }
        }
        return $this->enqueue($type, $items);
    }

    /**
     * Eine Nachricht an alle Konten, die die Art per Mail bekommen und die $include zulässt (PushService::notifyAll,
     * notifyAudience: Sprache, Kalender oder nicht, z. B. nicht der Verfasser).
     *
     * @param Message $message
     * @param \Closure(User): bool $include
     */
    public function toAudience(string $type, array $message, \Closure $include): int
    {
        if (!$this->available() || in_array($type, self::EXCLUDED, true)) {
            return 0;
        }
        $items = [];
        foreach ($this->accounts() as $user) {
            if (in_array($type, $this->chosen($user), true) && $include($user)) {
                $items[] = [$user->id, $message];
            }
        }
        return $this->enqueue($type, $items);
    }

    /** @param list<array{0: int, 1: Message}> $items */
    private function enqueue(string $type, array $items): int
    {
        if ($items === []) {
            return 0;
        }
        $emails = $this->app->users->emails(array_values(array_unique(array_map(static fn (array $i): int => $i[0], $items))));
        $now = $this->app->time()->nowUtc();
        $count = 0;
        $this->app->db->transaction(function () use ($items, $emails, $type, $now, &$count): void {
            foreach ($items as [$userId, $message]) {
                $email = trim($emails[$userId] ?? '');
                if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    continue;
                }
                $this->app->db->insert('notification_mails', [
                    'user_id' => $userId, 'type' => $type, 'email' => $email,
                    'title' => mb_substr($message['title'], 0, 200), 'body' => $message['body'], 'route' => $message['route'],
                    'status' => BoardMailService::PENDING, 'attempts' => 0, 'error' => null, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $count++;
            }
        });
        if ($count > 0) {
            $this->app->scheduler->runSoon(BoardMailService::TASK);
        }
        return $count;
    }

    public function pendingCount(): int
    {
        return (int) $this->app->db->scalar('SELECT COUNT(*) FROM {notification_mails} WHERE status = ?', [BoardMailService::PENDING]);
    }

    /**
     * Wartende Mails versenden (Teil von MailService::runQueue): höchstens $limit Versuche (0 = ohne Grenze), solange
     * das Zeitbudget ab $start reicht.
     *
     * @return array{sent: int, failed: int, processed: int}
     */
    public function sendDue(Mailer $mailer, int $limit, float $start, int $nowTs): array
    {
        $stats = ['sent' => 0, 'failed' => 0, 'processed' => 0];
        $rows = $this->app->db->select('SELECT * FROM {notification_mails} WHERE status = ? ORDER BY id', [BoardMailService::PENDING]);
        foreach ($rows as $row) {
            if ($limit > 0 && $stats['processed'] >= $limit) {
                break;
            }
            if (microtime(true) - $start > MailService::BUDGET) {
                break;
            }
            if (!MailService::isDue((int) $row['attempts'], (string) $row['updated_at'], $nowTs)) {
                continue;
            }
            $id = (int) $row['id'];
            $user = $this->accounts()[(int) $row['user_id']] ?? null;
            // Inzwischen abgeschaltet, Recht oder Bereich weg, Konto gelöscht: nicht mehr zustellen.
            if ($user === null || !in_array((string) $row['type'], $this->chosen($user), true)) {
                $this->mark($id, BoardMailService::CANCELLED, (int) $row['attempts'], null);
                continue;
            }
            $mail = I18n::in(
                $this->app->languages()->forRecipient($user->id),
                fn (): Mail => $this->compose((string) $row['type'], (string) $row['title'], (string) $row['body'], (string) $row['route'], ['name' => $user->name, 'email' => (string) $row['email']]),
            );
            $result = $mailer->send($mail);
            $attempts = (int) $row['attempts'] + 1;
            $stats['processed']++;
            if ($result->ok) {
                $this->mark($id, BoardMailService::SENT, $attempts, null);
                $stats['sent']++;
            } else {
                $final = $result->permanent || $attempts >= self::MAX_ATTEMPTS;
                $this->mark($id, $final ? BoardMailService::FAILED : BoardMailService::PENDING, $attempts, $result->error);
                $stats['failed'] += $final ? 1 : 0;
                // Ohne Adresse: das Protokoll des Servers soll keine personenbezogenen Daten sammeln.
                error_log('Kompanie-App: Benachrichtigung per Mail (Eintrag ' . $id . ') nicht gesendet: ' . MailResult::redactAddresses($result->error));
            }
        }
        return $stats;
    }

    /**
     * Die fertige Mail: Betreff = Titel der Benachrichtigung, Text, Link in die App, Hinweis zum Abschalten.
     *
     * @param array{name: string, email: string} $recipient
     */
    public function compose(string $type, string $title, string $body, string $route, array $recipient): Mail
    {
        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $url = $this->app->env->appUrl() . '#/' . ltrim($route, '#/');
        $label = $this->app->push()->types()[$type]['label'] ?? $type;
        $optOut = I18n::t(
            'Du bekommst diese Mail, weil in der App „{app}“ für dich Benachrichtigungen zu „{type}“ per E-Mail eingeschaltet sind. Abschalten kannst du das in der App unter Konto → Benachrichtigungen.',
            ['app' => $this->app->config->name(), 'type' => $label],
        );
        $paragraphs = implode('', array_map(static fn (string $line): string => '<p style="margin: 0 0 8px 0">' . $esc($line) . '</p>', array_values(array_filter(explode("\n", $body), static fn (string $l): bool => trim($l) !== ''))));
        $document = '<!DOCTYPE html><html lang="' . $esc(I18n::locale()) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>' . $esc($title) . '</title></head>'
            . '<body style="margin: 0; padding: 16px; font-family: Arial, Helvetica, sans-serif; font-size: 15px; line-height: 1.5; color: #222222; background-color: #ffffff">'
            . '<h1 style="font-size: 20px; line-height: 1.3; margin: 0 0 12px 0">' . $esc($title) . '</h1>'
            . '<div>' . $paragraphs . '</div>'
            . '<p style="margin: 16px 0 0 0"><a href="' . $esc($url) . '">' . $esc(I18n::t('in der App ansehen')) . '</a></p>'
            . '<hr style="border: none; border-top: 1px solid #cccccc; margin: 24px 0 12px 0">'
            . '<p style="font-size: 12px; color: #777777; margin: 0">' . $esc($optOut) . '</p>'
            . '</body></html>';
        $text = $title . "\n\n" . ($body !== '' ? $body . "\n\n" : '') . I18n::t('In der App ansehen: {url}', ['url' => $url]) . "\n\n-- \n" . $optOut . "\n";
        return new Mail($recipient['email'], $recipient['name'], $title, $document, $text);
    }

    /** Erledigte Einträge (gesendet, fehlgeschlagen, abgebrochen) nach KEEP_DAYS Tagen löschen. */
    public function cleanup(): int
    {
        $before = gmdate('Y-m-d H:i:s', $this->app->time()->timestamp() - self::KEEP_DAYS * 86400);
        return $this->app->db->execute('DELETE FROM {notification_mails} WHERE status <> ? AND updated_at < ?', [BoardMailService::PENDING, $before]);
    }

    private function mark(int $id, string $status, int $attempts, ?string $error): void
    {
        $this->app->db->update('notification_mails', [
            'status' => $status, 'attempts' => $attempts, 'error' => $error === null ? null : mb_substr($error, 0, 1000), 'updated_at' => $this->app->time()->nowUtc(),
        ], ['id' => $id]);
    }
}
