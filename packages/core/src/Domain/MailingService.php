<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Mail\Mail;
use KompanieApp\Core\Mail\MailResult;
use KompanieApp\Core\Port\Mailer;

/**
 * E-Mails aus der Verwaltung (Bereich mail, Recht send_mail).
 *
 * - Geschrieben wird wie am Schwarzen Brett: Betreff und formatierter Inhalt (RichText, Bilder über RichImageService,
 *   eingebettet als cid:), die eigene Signatur lässt sich einfügen; dazu beliebige Dateianhänge (AttachmentService).
 * - Empfänger je An, Cc und Bcc: Mailgruppen (MailGroupService, z. B. „Alle Mitglieder“ mit Admins und Spieß), Rollen
 *   (Grund- und Zusatzrollen, ohne Gäste), einzelne Konten und freie Adressen. Jede Adresse bekommt die Mail einmal;
 *   steht sie in mehreren Feldern, gilt An vor Cc vor Bcc. Konten ohne gültige Adresse werden übersprungen (die Vorschau
 *   nennt sie). Ehemalige (Rolle Gast) bekommen nie eine Mail. Wer schreibt, bekommt die Mail nur, wenn er sich selbst
 *   auswählt (oder in einer gewählten Gruppe ist).
 * - Zugestellt wird jede Mail einzeln an einen Empfänger, die Kopfzeilen zeigen aber wie in einem Mailprogramm alle
 *   Empfänger in An und Cc (Bcc nie, Mail::headerTo). So gibt es einen Stand je Empfänger, erneute Versuche und den
 *   Versand in Portionen wie bei den Mails vom Brett (gemeinsame Warteschlange, MailService::runQueue).
 * - Absender ist die Kompanieadresse; Antworten gehen dorthin. Keine Fußzeile, keine Abmeldung: Die Mail kommt so an,
 *   wie sie geschrieben wurde.
 * - Verlauf mit Versandprotokoll für alle mit send_mail; nach KEEP_DAYS Tagen löscht die Aufgabe CLEANUP_TASK die Mail
 *   samt Protokoll und Bildern.
 */
final class MailingService
{
    public const FEATURE = 'mail';
    public const CLEANUP_TASK = 'mailing_cleanup';
    public const KEEP_DAYS = 365;
    public const FIELDS = ['to', 'cc', 'bcc'];
    public const SUBJECT_MAX = 255;
    /** Höchstzahl freier Adressen je Feld (Schutz vor versehentlich eingefügten Adresslisten ohne Ende). */
    public const EMAILS_MAX = 500;

    public function __construct(private readonly App $app)
    {
    }

    /** Bereich an und Mailversand eingerichtet? */
    public function available(): bool
    {
        return $this->app->features()->enabled(self::FEATURE) && $this->app->mail()->configured();
    }

    /**
     * Für das Formular: eingerichtet? Absender, Mailgruppen und Rollen mit Zahl der erreichbaren Personen, Konten (ohne
     * Gäste) mit ihren Rollen und ob eine Adresse hinterlegt ist. Adressen der Konten liefert die App nicht aus.
     *
     * @return array<string, mixed>
     */
    public function options(): array
    {
        $available = $this->available();
        $config = $this->app->mail()->config();
        $users = $this->accounts();
        $emails = $this->emailsOf($users);
        $members = [];
        $counts = [];
        foreach ($users as $u) {
            $keys = $this->rolesOf($u);
            $hasEmail = isset($emails[$u->id]);
            foreach ($keys as $key) {
                $counts[$key] ??= ['members' => 0, 'with_email' => 0];
                $counts[$key]['members']++;
                $counts[$key]['with_email'] += $hasEmail ? 1 : 0;
            }
            $members[] = ['id' => $u->id, 'name' => $u->name, 'roles' => $keys, 'email' => $hasEmail];
        }
        $roles = [];
        foreach ($this->app->roles()->all() as $key => $r) {
            if ($key === Roles::GUEST) {
                continue;
            }
            $roles[] = ['key' => $key, 'label' => $r['label'], 'members' => $counts[$key]['members'] ?? 0, 'with_email' => $counts[$key]['with_email'] ?? 0];
        }
        return [
            'available' => $available,
            'reason' => $available ? null : ($this->app->mail()->unavailable() ?? 'Der Mailversand ist noch nicht eingerichtet (Einstellungen → E-Mail).'),
            'from' => $config['from'],
            'from_name' => $config['from_name'],
            'groups' => $this->groups(),
            'roles' => $roles,
            'members' => $members,
            'server_max_size' => $this->app->mail()->serverMaxSize(),
        ];
    }

    /**
     * Wer die Mail bekäme: Zahl je Feld und Konten ohne Adresse (für die Bestätigung vor dem Senden).
     *
     * @return array{recipients: int, to: int, cc: int, bcc: int, skipped: list<string>}
     */
    public function preview(mixed $selection): array
    {
        ['recipients' => $recipients, 'skipped' => $skipped] = $this->resolve($this->validSelection($selection));
        $out = ['recipients' => count($recipients), 'to' => 0, 'cc' => 0, 'bcc' => 0, 'skipped' => $skipped];
        foreach ($recipients as $r) {
            $out[$r['field']]++;
        }
        return $out;
    }

    /**
     * Mail schreiben und zum Versand einreihen.
     *
     * @param array<string, mixed> $in subject, text, to, cc, bcc
     * @return array<string, mixed> wie detail()
     */
    public function send(User $author, array $in): array
    {
        if (!$this->available()) {
            throw ApiError::invalid($this->options()['reason'] ?? 'Der Mailversand ist nicht eingerichtet.');
        }
        $subject = is_string($in['subject'] ?? null) ? Text::line($in['subject'], self::SUBJECT_MAX) : '';
        if ($subject === '') {
            throw ApiError::invalid('Bitte einen Betreff eingeben.');
        }
        $text = $in['text'] ?? null;
        $body = is_string($text) ? RichText::sanitize($text) : '';
        if (RichText::isEmpty($body)) {
            throw ApiError::invalid('Bitte einen Text eingeben.');
        }
        $selection = $this->validSelection(['to' => $in['to'] ?? null, 'cc' => $in['cc'] ?? null, 'bcc' => $in['bcc'] ?? null]);
        ['recipients' => $recipients] = $this->resolve($selection);
        if ($recipients === []) {
            throw ApiError::invalid('Keiner der gewählten Empfänger hat eine Mailadresse. Bitte Empfänger auswählen.');
        }
        $files = $this->app->attachments()->resolve($author, $in['attachments'] ?? null, AttachmentService::OWNER_MAIL);
        $this->checkSize($subject, $body, $recipients, $files);
        $now = $this->app->time()->nowUtc();
        $id = $this->app->db->transaction(function () use ($author, $subject, $body, $selection, $recipients, $now, $files): int {
            $id = $this->app->db->insert('mailings', [
                'author_id' => $author->id, 'subject' => $subject, 'body' => $body,
                'selection' => (string) json_encode($selection, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'created_at' => $now,
            ]);
            $claimed = $this->app->richImages()->claim($author, $body, RichImageService::OWNER_MAIL, $id);
            if ($claimed !== $body) {
                $this->app->db->update('mailings', ['body' => $claimed], ['id' => $id]);
            }
            $this->app->attachments()->assign($files, AttachmentService::OWNER_MAIL, $id);
            foreach ($recipients as $r) {
                $this->app->db->insert('mailing_recipients', [
                    'mailing_id' => $id, 'user_id' => $r['user_id'], 'field' => $r['field'], 'name' => mb_substr($r['name'], 0, 191),
                    'email' => $r['email'], 'status' => BoardMailService::PENDING, 'attempts' => 0, 'error' => null, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            return $id;
        });
        $this->app->scheduler->runSoon(BoardMailService::TASK);
        return $this->detail($id);
    }

    /**
     * Verlauf: alle aufbewahrten Mails, neueste zuerst, mit Stand des Versands.
     *
     * @return array{items: list<array<string, mixed>>, keep_days: int}
     */
    public function list(): array
    {
        $rows = $this->app->db->select('SELECT id, author_id, subject, created_at FROM {mailings} ORDER BY created_at DESC, id DESC');
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $summaries = $this->summaries($ids);
        $names = (new UserNames($this->app->users))->prime(array_column($rows, 'author_id'));
        return ['items' => array_map(fn (array $r): array => [
            'id' => (int) $r['id'],
            'subject' => (string) $r['subject'],
            'author_name' => $names->name((int) $r['author_id']),
            'created_at' => Time::utcToIso((string) $r['created_at']),
            'mail' => $summaries[(int) $r['id']] ?? self::emptySummary(),
        ], $rows), 'keep_days' => self::KEEP_DAYS];
    }

    /**
     * Eine Mail mit Inhalt, Auswahl der Empfänger und Protokoll je Empfänger.
     *
     * @return array<string, mixed>
     */
    public function detail(int $id): array
    {
        $row = $this->find($id) ?? throw ApiError::notFound('Diese E-Mail gibt es nicht (mehr).');
        $names = new UserNames($this->app->users);
        $selection = json_decode((string) $row['selection'], true);
        $selection = is_array($selection) ? $selection : [];
        $roles = $this->app->roles()->all();
        $described = [];
        foreach (self::FIELDS as $field) {
            $s = is_array($selection[$field] ?? null) ? $selection[$field] : [];
            $userIds = array_values(array_filter((array) ($s['users'] ?? []), 'is_int'));
            $names->prime($userIds);
            $described[$field] = [
                'groups' => array_map(fn (string $k): array => ['key' => $k, 'label' => $this->app->mailGroups()->find($k)['label'] ?? 'Gelöschte Gruppe'], array_values(array_filter((array) ($s['groups'] ?? []), 'is_string'))),
                'roles' => array_map(static fn (string $k): array => ['key' => $k, 'label' => $roles[$k]['label'] ?? $k], array_values(array_filter((array) ($s['roles'] ?? []), 'is_string'))),
                'users' => array_map(static fn (int $u): array => ['id' => $u, 'name' => $names->name($u)], $userIds),
                'emails' => array_values(array_filter((array) ($s['emails'] ?? []), 'is_string')),
            ];
        }
        $log = $this->app->db->select('SELECT field, name, email, status, attempts, error, updated_at FROM {mailing_recipients} WHERE mailing_id = ? ORDER BY id', [$id]);
        return [
            'id' => (int) $row['id'],
            'subject' => (string) $row['subject'],
            'text' => $this->app->richImages()->render((string) $row['body']),
            'author_name' => $names->name((int) $row['author_id']),
            'created_at' => Time::utcToIso((string) $row['created_at']),
            'selection' => $described,
            'attachments' => array_map(fn (array $f): array => $this->app->attachments()->format($f), $this->app->attachments()->of(AttachmentService::OWNER_MAIL, $id)),
            'mail' => $this->summaries([$id])[$id] ?? self::emptySummary(),
            'log' => array_map(static fn (array $r): array => [
                'field' => (string) $r['field'],
                'name' => $r['name'] === '' ? null : (string) $r['name'],
                'email' => (string) $r['email'],
                'status' => (string) $r['status'],
                'attempts' => (int) $r['attempts'],
                'error' => $r['error'] === null || $r['error'] === '' ? null : (string) $r['error'],
                'at' => Time::utcToIso((string) $r['updated_at']),
            ], $log),
        ];
    }

    /** Bild einer Mail ansehen (RichImageService::canView): wer Mails versendet und den Verlauf sieht. */
    public function canSeeImage(User $viewer, int $mailingId): bool
    {
        return $this->app->features()->enabled(self::FEATURE) && $viewer->can(Capability::SendMail) && $this->find($mailingId) !== null;
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
        $rows = $this->app->db->select('SELECT id, mailing_id, email, name, attempts, updated_at FROM {mailing_recipients} WHERE status = ? ORDER BY id', [BoardMailService::PENDING]);
        $mailings = [];
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
            $mid = (int) $row['mailing_id'];
            if (!array_key_exists($mid, $mailings)) {
                $m = $this->find($mid);
                $mailings[$mid] = $m === null ? null : [
                    'row' => $m,
                    'header' => $this->headerRecipients($mid),
                    'files' => $this->app->attachments()->forMail($this->app->attachments()->of(AttachmentService::OWNER_MAIL, $mid)),
                ];
            }
            if ($mailings[$mid] === null) {
                $this->mark((int) $row['id'], BoardMailService::CANCELLED, (int) $row['attempts'], null);
                continue;
            }
            $m = $mailings[$mid]['row'];
            $mail = $this->compose((string) $m['subject'], (string) $m['body'], ['email' => (string) $row['email'], 'name' => (string) $row['name']], $mailings[$mid]['header'], $mailings[$mid]['files']);
            $result = $mailer->send($mail);
            $attempts = (int) $row['attempts'] + 1;
            $stats['processed']++;
            if ($result->ok) {
                $this->mark((int) $row['id'], BoardMailService::SENT, $attempts, null);
                $stats['sent']++;
            } else {
                $final = $result->permanent || $attempts >= BoardMailService::MAX_ATTEMPTS;
                $this->mark((int) $row['id'], $final ? BoardMailService::FAILED : BoardMailService::PENDING, $attempts, $result->error);
                $stats['failed'] += $final ? 1 : 0;
                // Ohne Adresse: das Protokoll des Servers soll keine personenbezogenen Daten sammeln.
                error_log('Kompanie-App: E-Mail (Versand ' . $mid . ', Empfänger ' . $row['id'] . ') nicht gesendet: ' . MailResult::redactAddresses($result->error));
            }
        }
        return $stats;
    }

    public function pendingCount(): int
    {
        return (int) $this->app->db->scalar('SELECT COUNT(*) FROM {mailing_recipients} WHERE status = ?', [BoardMailService::PENDING]);
    }

    /**
     * Die fertige Mail für einen Empfänger: HTML mit eingebetteten Bildern und ein Text-Teil.
     *
     * @param array{email: string, name: string} $recipient
     * @param array{to: list<array{email: string, name: string}>, cc: list<array{email: string, name: string}>} $header
     * @param list<array{name: string, mime: string, path: string}> $attachments
     */
    public function compose(string $subject, string $body, array $recipient, array $header, array $attachments = []): Mail
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
        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $document = '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>' . $esc($subject) . '</title></head>'
            . '<body style="margin: 0; padding: 16px; font-family: Arial, Helvetica, sans-serif; font-size: 15px; line-height: 1.5; color: #222222; background-color: #ffffff">'
            . $html
            . '</body></html>';
        return new Mail($recipient['email'], $recipient['name'], $subject, $document, RichText::excerpt($body) . "\n", array_values($images), $header['to'], $header['cc'], $attachments);
    }

    /**
     * Aufgabe mailing_cleanup: Mails, die älter als KEEP_DAYS Tage sind, samt Protokoll, Bildern und Anhängen löschen;
     * dazu nie gespeicherte Entwürfe von Anhängen (auch ohne Schwarzes Brett, dessen Aufgabe sie sonst aufräumt).
     */
    public function cleanup(): int
    {
        $this->app->attachments()->cleanupDrafts();
        $before = gmdate('Y-m-d H:i:s', $this->app->time()->timestamp() - self::KEEP_DAYS * 86400);
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $this->app->db->select('SELECT id FROM {mailings} WHERE created_at < ?', [$before]));
        if ($ids === []) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $this->app->db->transaction(function () use ($ids, $in): void {
            $this->app->db->execute('DELETE FROM {mailing_recipients} WHERE mailing_id IN (' . $in . ')', $ids);
            $this->app->db->execute('DELETE FROM {mailings} WHERE id IN (' . $in . ')', $ids);
            $this->app->richImages()->repository()->deleteOwners(RichImageService::OWNER_MAIL, $ids);
        });
        $this->app->attachments()->deleteOwners(AttachmentService::OWNER_MAIL, $ids);
        return count($ids);
    }

    /**
     * Auswahl prüfen und vereinheitlichen: je Feld Mailgruppen, Rollen (bestehende, ohne Gäste), Konten (ohne Gäste) und
     * gültige Adressen, ohne Doppelte.
     *
     * @return array<string, array{groups: list<string>, roles: list<string>, users: list<int>, emails: list<string>}>
     */
    public function validSelection(mixed $in): array
    {
        if (!is_array($in)) {
            throw ApiError::invalid('Bitte Empfänger angeben (to, cc, bcc).');
        }
        $out = [];
        foreach (self::FIELDS as $field) {
            $out[$field] = $this->validField($field, $in[$field] ?? null, true);
        }
        return $out;
    }

    /**
     * Ein Empfängerfeld oder der Inhalt einer Mailgruppe prüfen: Rollen, Konten, Adressen und (nur in Feldern) Gruppen.
     *
     * @return ($withGroups is true ? array{groups: list<string>, roles: list<string>, users: list<int>, emails: list<string>} : array{roles: list<string>, users: list<int>, emails: list<string>})
     */
    public function validField(string $field, mixed $f, bool $withGroups): array
    {
        if ($f === null) {
            $f = [];
        }
        if (!is_array($f)) {
            throw ApiError::invalid($field . ': Rollen, Mitglieder und Adressen erwartet.');
        }
        $roles = $this->app->roles()->all();
        $sel = ['roles' => [], 'users' => [], 'emails' => []];
        if ($withGroups) {
            $groups = [];
            foreach ((array) ($f['groups'] ?? []) as $key) {
                if (!is_string($key) || $this->app->mailGroups()->find($key) === null) {
                    throw ApiError::invalid('Unbekannte Mailgruppe: ' . (is_scalar($key) ? (string) $key : '?') . '.');
                }
                $groups[] = $key;
            }
            $sel = ['groups' => array_values(array_unique($groups))] + $sel;
        }
        foreach ((array) ($f['roles'] ?? []) as $key) {
            if (!is_string($key) || !isset($roles[$key]) || $key === Roles::GUEST) {
                throw ApiError::invalid('Unbekannte Rolle: ' . (is_scalar($key) ? (string) $key : '?') . '.');
            }
            $sel['roles'][] = $key;
        }
        foreach ((array) ($f['users'] ?? []) as $id) {
            $id = Input::integer($id);
            if ($id === null || $id < 1) {
                throw ApiError::invalid('Ungültiges Mitglied in ' . $field . '.');
            }
            $sel['users'][] = $id;
        }
        $emails = (array) ($f['emails'] ?? []);
        if (count($emails) > self::EMAILS_MAX) {
            throw ApiError::invalid(sprintf('Höchstens %d Adressen je Feld.', self::EMAILS_MAX));
        }
        foreach ($emails as $email) {
            $email = is_string($email) ? trim($email) : '';
            if ($email === '' || strlen($email) > 191 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw ApiError::invalid('Ungültige Adresse: „' . $email . '“.');
            }
            $sel['emails'][] = $email;
        }
        $sel['roles'] = array_values(array_unique($sel['roles']));
        $sel['users'] = array_values(array_unique($sel['users']));
        $sel['emails'] = array_values(array_unique($sel['emails']));
        $found = $this->app->users->findMany($sel['users']);
        foreach ($sel['users'] as $id) {
            if (!isset($found[$id]) || $found[$id]->role === Roles::GUEST) {
                throw ApiError::invalid('Dieses Mitglied gibt es nicht (mehr).');
            }
        }
        return $sel;
    }

    /**
     * Mailgruppen für Formular und Verwaltung, mit Zahl der erreichbaren Konten (members, with_email) und freien Adressen.
     * Inhalte eigener Gruppen nur mit Rollen und Konten, die es noch gibt (Ehemalige und Gelöschte fallen weg).
     *
     * @return list<array<string, mixed>>
     */
    public function groups(): array
    {
        $users = $this->accounts();
        $emails = $this->emailsOf($users);
        $roles = $this->app->roles()->all();
        $ids = array_map(static fn (User $u): int => $u->id, $users);
        $out = [];
        foreach ($this->app->mailGroups()->all() as $g) {
            $g['roles'] = array_values(array_filter($g['roles'], static fn (string $k): bool => isset($roles[$k]) && $k !== Roles::GUEST));
            $g['users'] = array_values(array_intersect($g['users'], $ids));
            $members = 0;
            $withEmail = 0;
            foreach ($users as $u) {
                if ($this->inGroup($u, $g)) {
                    $members++;
                    $withEmail += isset($emails[$u->id]) ? 1 : 0;
                }
            }
            $out[] = $g + ['members' => $members, 'with_email' => $withEmail];
        }
        return $out;
    }

    /**
     * Gehört das Konto zur Gruppe? „Alle Mitglieder“: Grundrolle mit Mitgliederbereich (Admin hat alle Rechte), „Alle
     * Konten“: jedes wählbare Konto, eigene Gruppen: über Rolle oder Konto.
     *
     * @param array{key: string, roles: list<string>, users: list<int>} $group
     */
    private function inGroup(User $u, array $group): bool
    {
        return match ($group['key']) {
            MailGroupService::MEMBERS => in_array(Capability::Member, $this->app->roles()->capabilities($u->role), true),
            MailGroupService::ALL => true,
            default => in_array($u->id, $group['users'], true) || array_intersect($this->rolesOf($u), $group['roles']) !== [],
        };
    }

    /**
     * Empfänger aus der Auswahl: je Adresse einmal, An vor Cc vor Bcc; Konten ohne gültige Adresse als skipped.
     *
     * @param array<string, array{groups?: list<string>, roles: list<string>, users: list<int>, emails: list<string>}> $selection
     * @return array{recipients: list<array{user_id: int, name: string, email: string, field: string}>, skipped: list<string>}
     */
    public function resolve(array $selection): array
    {
        $users = [];
        foreach ($this->accounts() as $u) {
            $users[$u->id] = $u;
        }
        $emails = $this->emailsOf(array_values($users));
        $seen = [];
        $recipients = [];
        $skipped = [];
        foreach (self::FIELDS as $field) {
            $sel = $selection[$field];
            $groups = array_values(array_filter(array_map(fn (string $k): ?array => $this->app->mailGroups()->find($k), $sel['groups'] ?? [])));
            $accounts = [];
            foreach ($users as $u) {
                if (in_array($u->id, $sel['users'], true) || array_intersect($this->rolesOf($u), $sel['roles']) !== []
                    || array_filter($groups, fn (array $g): bool => $this->inGroup($u, $g)) !== []) {
                    $accounts[] = $u;
                }
            }
            foreach ($accounts as $u) {
                $email = $emails[$u->id] ?? null;
                if ($email === null) {
                    $skipped[$u->id] = $u->name;
                    continue;
                }
                $key = strtolower($email);
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $recipients[] = ['user_id' => $u->id, 'name' => $u->name, 'email' => $email, 'field' => $field];
                }
            }
            foreach (array_merge($sel['emails'], ...array_column($groups, 'emails')) as $email) {
                $key = strtolower($email);
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $recipients[] = ['user_id' => 0, 'name' => '', 'email' => $email, 'field' => $field];
                }
            }
        }
        $skipped = array_values($skipped);
        sort($skipped, SORT_NATURAL | SORT_FLAG_CASE);
        return ['recipients' => $recipients, 'skipped' => $skipped];
    }

    /**
     * Passt die fertige Mail zur gemeldeten Höchstgröße des Mailservers? Gemessen mit allen Empfängern in den Kopfzeilen.
     *
     * @param list<array{user_id: int, name: string, email: string, field: string}> $recipients
     * @param list<array<string, mixed>> $files Anhänge
     */
    private function checkSize(string $subject, string $body, array $recipients, array $files): void
    {
        $max = $this->app->boardMail()->serverMaxSize();
        if ($max === null) {
            return;
        }
        $header = self::header($recipients);
        $mail = $this->compose($subject, $body, ['email' => $recipients[0]['email'], 'name' => $recipients[0]['name']], $header, $this->app->attachments()->forMail($files));
        $size = $this->app->mailer()->measure($mail);
        if ($size > $max) {
            throw ApiError::invalid(sprintf(
                'Die Mail ist mit Bildern und Anhängen %s groß, der Mailserver nimmt höchstens %s an. Bitte Bilder oder Anhänge verkleinern.',
                ServerLimits::format($size),
                ServerLimits::format($max),
            ));
        }
    }

    /**
     * @param list<array{name: string, email: string, field: string}> $recipients
     * @return array{to: list<array{email: string, name: string}>, cc: list<array{email: string, name: string}>}
     */
    private static function header(array $recipients): array
    {
        $out = ['to' => [], 'cc' => []];
        foreach ($recipients as $r) {
            if ($r['field'] === 'to' || $r['field'] === 'cc') {
                $out[$r['field']][] = ['email' => $r['email'], 'name' => $r['name']];
            }
        }
        return $out;
    }

    /** @return array{to: list<array{email: string, name: string}>, cc: list<array{email: string, name: string}>} */
    private function headerRecipients(int $mailingId): array
    {
        $rows = $this->app->db->select("SELECT name, email, field FROM {mailing_recipients} WHERE mailing_id = ? AND field IN ('to', 'cc') ORDER BY id", [$mailingId]);
        return self::header(array_map(static fn (array $r): array => ['name' => (string) $r['name'], 'email' => (string) $r['email'], 'field' => (string) $r['field']], $rows));
    }

    /**
     * Wählbare Konten: alle mit einer Rolle außer Gast, also auch nie Ehemalige (sie haben die Rolle Gast,
     * FormerMemberService). list(false) lässt Gäste je nach Adapter noch durch (WordPress: Konten mit gespeicherter
     * Rolle „guest“), deshalb hier ausdrücklich.
     *
     * @return list<User>
     */
    private function accounts(): array
    {
        return array_values(array_filter($this->app->users->list(false), static fn (User $u): bool => $u->role !== Roles::GUEST));
    }

    /** @return array<string, mixed>|null */
    private function find(int $id): ?array
    {
        return $this->app->db->selectOne('SELECT * FROM {mailings} WHERE id = ?', [$id]);
    }

    /**
     * Grund- und wirksame Zusatzrollen eines Kontos.
     *
     * @return list<string>
     */
    private function rolesOf(User $u): array
    {
        return array_values(array_unique([$u->role, ...$this->app->roles()->extraRolesOf($u)]));
    }

    /**
     * Gültige Adressen der Konten.
     *
     * @param list<User> $users
     * @return array<int, string>
     */
    private function emailsOf(array $users): array
    {
        $raw = $this->app->users->emails(array_map(static fn (User $u): int => $u->id, $users));
        $out = [];
        foreach ($raw as $id => $email) {
            $email = trim($email);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $out[(int) $id] = $email;
            }
        }
        return $out;
    }

    /**
     * Stand des Versands je Mail.
     *
     * @param list<int> $ids
     * @return array<int, array{recipients: int, sent: int, failed: int, pending: int, cancelled: int}>
     */
    private function summaries(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        $rows = $this->app->db->select(
            'SELECT mailing_id, status, COUNT(*) AS n FROM {mailing_recipients} WHERE mailing_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') GROUP BY mailing_id, status',
            $ids,
        );
        foreach ($rows as $r) {
            $id = (int) $r['mailing_id'];
            $out[$id] ??= self::emptySummary();
            $status = (string) $r['status'];
            if (isset($out[$id][$status])) {
                $out[$id][$status] = (int) $r['n'];
            }
            $out[$id]['recipients'] += (int) $r['n'];
        }
        return $out;
    }

    /** @return array{recipients: int, sent: int, failed: int, pending: int, cancelled: int} */
    private static function emptySummary(): array
    {
        return ['recipients' => 0, 'sent' => 0, 'failed' => 0, 'pending' => 0, 'cancelled' => 0];
    }

    private function mark(int $id, string $status, int $attempts, ?string $error): void
    {
        $this->app->db->update('mailing_recipients', [
            'status' => $status, 'attempts' => $attempts, 'error' => $error === null ? null : mb_substr($error, 0, 1000), 'updated_at' => $this->app->time()->nowUtc(),
        ], ['id' => $id]);
    }
}
