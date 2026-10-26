<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Storage\BoardRepository;

/**
 * Schwarzes Brett (Issue #5, Umbau #172): dringende Nachrichten für alle Mitglieder und Anwärter.
 *
 * - Schreiben, ändern und vorzeitig löschen darf, wer das Recht post_board hat (nicht nur, wer sie geschrieben hat).
 * - Jede Nachricht hat einen Titel (reiner Text, ohne Längengrenze; Banner, Push und Betreff der Mail) und formatierten
 *   Inhalt (RichText, bereinigt nach Allowlist, mit Bildern über RichImageService; ohne Längengrenze), dazu beliebige
 *   Dateianhänge (AttachmentService), die alle sehen, die die Nachricht sehen, und die an der Mail hängen.
 * - Sichtbar bis zu einem frei wählbaren Enddatum (expires_at, UTC), standardmäßig LIFETIME Sekunden ab dem Schreiben;
 *   es muss nur in der Zukunft liegen und lässt sich ändern (verlängern und verkürzen), solange die Nachricht gilt.
 *   Danach liefert die API sie nicht mehr.
 * - Ändern sich Titel oder Inhalt, bekommt die Nachricht ein späteres updated_at (sie erscheint auf Geräten wieder, die
 *   sie ausgeblendet hatten, Issue #68); ändert sich nur das Enddatum, bleibt updated_at.
 * - Änderungsverfolgung: Erstellen, jede Änderung von Titel, Inhalt, Anhängen und Enddatum, der Mailversand und das vorzeitige
 *   Löschen werden mit Konto und Zeitpunkt (UTC) samt vorherigem und neuem Stand erfasst (board_message_revisions).
 *   Sichtbar nur mit post_board. Abgelaufene und gelöschte Nachrichten bleiben mit ihrem Verlauf HISTORY_KEEP_DAYS
 *   einsehbar; dann entfernt die Aufgabe board_cleanup sie samt Verlauf, Mailprotokoll und Bildern endgültig.
 * - Beim Schreiben bekommen alle Empfänger auf Wunsch eine Benachrichtigung (PushTriggers::boardPosted) und eine Mail
 *   (BoardMailService), beim Ändern nicht.
 * - Die Liste besteht aus Einträgen mit kind: „message“ und für Mitglieder „poll“ (laufende Umfragen, Issue #4,
 *   PollService::boardItems); Clients überspringen unbekannte Arten.
 *
 * @phpstan-import-type BoardMessage from BoardRepository
 * @phpstan-import-type BoardRevision from BoardRepository
 * @phpstan-type BoardItem array<string, mixed>
 */
final class BoardService
{
    public const LIFETIME = 86400;
    public const HISTORY_KEEP_DAYS = 30;
    public const TASK = 'board_cleanup';
    public const KIND_MESSAGE = 'message';

    public const REV_CREATED = 'created';
    public const REV_TITLE = 'title';
    public const REV_CONTENT = 'content';
    public const REV_EXPIRES = 'expires';
    public const REV_DELETED = 'deleted';
    public const REV_MAILED = 'mailed';
    public const REV_ATTACHMENTS = 'attachments';

    private ?BoardRepository $repo = null;

    public function __construct(private readonly App $app)
    {
    }

    public function repository(): BoardRepository
    {
        return $this->repo ??= new BoardRepository($this->app->db);
    }

    /**
     * Wer das Brett sieht und Benachrichtigungen dazu bekommt: alle mit view_board und einem Konto (anfangs Mitglieder
     * und Anwärter). Die einzige Stelle für diese Regel (API, Push, Mail, Typen der Benachrichtigungen).
     */
    public static function isRecipient(?User $user): bool
    {
        return $user !== null && $user->id > 0 && $user->can(Capability::ViewBoard);
    }

    /**
     * Gültige Nachrichten, neueste zuerst; danach für Mitglieder die laufenden Umfragen (Issue #4, kind „poll“, mit
     * has_voted), sofern der Bereich Umfragen an ist. Mit post_board zusätzlich, wer zuletzt geändert hat, und der Stand
     * des Mailversands.
     *
     * @return array{items: list<array<string, mixed>>}
     */
    public function list(?User $viewer = null): array
    {
        $active = $this->repository()->active($this->app->time()->nowUtc());
        $editor = $viewer !== null && $viewer->can(Capability::PostBoard);
        $ids = array_column($active, 'id');
        $edits = $editor ? $this->repository()->lastEdits($ids) : [];
        $mails = $editor ? $this->app->boardMail()->summaries($ids) : [];
        $names = (new UserNames($this->app->users))->prime([...array_column($active, 'author_id'), ...array_column($edits, 'user_id')]);
        $files = $this->app->attachments()->many(AttachmentService::OWNER_BOARD, $ids);
        $messages = array_map(fn (array $m): array => $this->format($m, $names, $editor, $edits[$m['id']] ?? null, $mails[$m['id']] ?? null, $files[$m['id']] ?? []), $active);
        return ['items' => [...$messages, ...$this->app->polls()->boardItems($viewer)]];
    }

    /**
     * Neue Nachricht.
     *
     * @param array<string, mixed> $in title, text (formatierter Inhalt), expires_at (optional, ISO 8601)
     * @return BoardItem
     */
    public function create(User $author, array $in): array
    {
        $title = self::validTitle($in['title'] ?? null);
        $body = self::validBody($in['text'] ?? null);
        $nowTs = $this->app->time()->timestamp();
        $now = gmdate('Y-m-d H:i:s', $nowTs);
        $expires = array_key_exists('expires_at', $in) && $in['expires_at'] !== null && $in['expires_at'] !== ''
            ? $this->validExpires($in['expires_at'])
            : gmdate('Y-m-d H:i:s', $nowTs + self::LIFETIME);
        $files = $this->app->attachments()->resolve($author, $in['attachments'] ?? null, AttachmentService::OWNER_BOARD);
        $id = $this->app->db->transaction(function () use ($author, $title, $body, $now, $expires, $files): int {
            $id = $this->repository()->insert($title, $body, $author->id, $now, $expires);
            $claimed = $this->app->richImages()->claim($author, $body, RichImageService::OWNER_BOARD, $id);
            if ($claimed !== $body) {
                $this->repository()->update($id, ['body' => $claimed]);
            }
            $this->app->attachments()->assign($files, AttachmentService::OWNER_BOARD, $id);
            $snapshot = ['title' => $title, 'body' => $claimed, 'expires_at' => $expires];
            if ($files !== []) {
                $snapshot['attachments'] = array_map(static fn (array $f): string => (string) $f['name'], $files);
            }
            $this->repository()->addRevision($id, $author->id, $now, self::REV_CREATED, null, self::json($snapshot));
            return $id;
        });
        return $this->one($id, $author);
    }

    /**
     * Titel, Inhalt und/oder Enddatum ändern, solange die Nachricht gilt (einzeln oder zusammen). Unverändertes ändert
     * nichts. Geänderter Titel oder Inhalt bekommt immer ein späteres updated_at als vorher, auch innerhalb derselben
     * Sekunde (Issue #68): Ausgeblendet merkt sich die App die Fassung über updated_at. Nur ein neues Enddatum lässt
     * updated_at unverändert (ausgeblendet bleibt ausgeblendet).
     *
     * @param array<string, mixed> $in title, text, expires_at, attachments (alle Anhänge danach: Kennungen)
     * @return BoardItem
     */
    public function update(User $actor, int $id, array $in): array
    {
        $message = $this->active($id);
        $values = [];
        if (array_key_exists('title', $in)) {
            $title = self::validTitle($in['title']);
            if ($title !== $message['title']) {
                $values['title'] = $title;
            }
        }
        if (array_key_exists('text', $in)) {
            $body = $this->app->richImages()->claim($actor, self::validBody($in['text']), RichImageService::OWNER_BOARD, $id);
            if ($body !== $message['body']) {
                $values['body'] = $body;
            }
        }
        if (array_key_exists('expires_at', $in)) {
            $expires = $this->validExpires($in['expires_at']);
            if ($expires !== $message['expires_at']) {
                $values['expires_at'] = $expires;
            }
        }
        if (!array_key_exists('title', $in) && !array_key_exists('text', $in) && !array_key_exists('expires_at', $in) && !array_key_exists('attachments', $in)) {
            throw ApiError::invalid('Bitte Titel, Text, Anhänge oder Enddatum angeben.');
        }
        // Anhänge: die genannten Kennungen sind danach genau die Anhänge (neue Entwürfe kommen dazu, fehlende gehen).
        $files = null;
        $before = [];
        if (array_key_exists('attachments', $in)) {
            $files = $this->app->attachments()->resolve($actor, $in['attachments'], AttachmentService::OWNER_BOARD, $id);
            $current = $this->app->attachments()->of(AttachmentService::OWNER_BOARD, $id);
            $before = array_map(static fn (array $f): string => (string) $f['name'], $current);
            if (array_column($files, 'uid') === array_column($current, 'uid')) {
                $files = null;
            }
        }
        if ($values === [] && $files === null) {
            return $this->one($id, $actor);
        }
        $now = $this->app->time()->nowUtc();
        if (isset($values['title']) || isset($values['body']) || $files !== null) {
            $updated = $now;
            if ($updated <= $message['updated_at']) {
                $updated = gmdate('Y-m-d H:i:s', (int) strtotime($message['updated_at'] . ' UTC') + 1);
            }
            $values['updated_at'] = $updated;
        }
        $this->app->db->transaction(function () use ($id, $values, $message, $actor, $now, $files, $before): void {
            if ($values !== []) {
                $this->repository()->update($id, $values);
            }
            if ($files !== null) {
                $this->app->attachments()->assign($files, AttachmentService::OWNER_BOARD, $id);
                $after = array_map(static fn (array $f): string => (string) $f['name'], $files);
                $this->repository()->addRevision($id, $actor->id, $now, self::REV_ATTACHMENTS, self::json($before), self::json($after));
            }
            if (isset($values['title'])) {
                $this->repository()->addRevision($id, $actor->id, $now, self::REV_TITLE, $message['title'], $values['title']);
            }
            if (isset($values['body'])) {
                $this->repository()->addRevision($id, $actor->id, $now, self::REV_CONTENT, $message['body'], $values['body']);
            }
            if (isset($values['expires_at'])) {
                $this->repository()->addRevision($id, $actor->id, $now, self::REV_EXPIRES, $message['expires_at'], $values['expires_at']);
            }
        });
        return $this->one($id, $actor);
    }

    /** Vorzeitig löschen: mit Konto und Zeitpunkt im Verlauf; ausstehende Mails gehen nicht mehr raus. */
    public function delete(User $actor, int $id): void
    {
        $message = $this->active($id);
        $now = $this->app->time()->nowUtc();
        $this->app->db->transaction(function () use ($id, $actor, $now, $message): void {
            $this->repository()->markDeleted($id, $now, $actor->id);
            $this->repository()->addRevision($id, $actor->id, $now, self::REV_DELETED, self::json(['expires_at' => $message['expires_at']]), null);
        });
        $this->app->boardMail()->cancel($id);
    }

    /** Mailversand im Verlauf vermerken (BoardMailService::queue). */
    public function noteMailed(int $id, int $userId, int $recipients): void
    {
        $this->repository()->addRevision($id, $userId, $this->app->time()->nowUtc(), self::REV_MAILED, null, self::json(['recipients' => $recipients]));
    }

    /**
     * Verlauf einer Nachricht (auch abgelaufen oder gelöscht, solange aufbewahrt). Nur für post_board (Route).
     *
     * @return array{message: array<string, mixed>, revisions: list<array<string, mixed>>, mail: array<string, mixed>|null}
     */
    public function history(int $id): array
    {
        $message = $this->repository()->find($id) ?? throw ApiError::notFound('Diese Nachricht gibt es nicht mehr.');
        $revisions = $this->repository()->revisions([$id])[$id] ?? [];
        $names = (new UserNames($this->app->users))->prime([$message['author_id'], $message['deleted_by'], ...array_column($revisions, 'user_id')]);
        return [
            'message' => $this->formatEnded($message, $names),
            'revisions' => array_map(fn (array $r): array => $this->formatRevision($r, $names), $revisions),
            'mail' => $this->app->boardMail()->summaries([$id])[$id] ?? null,
            'mail_log' => $this->app->boardMail()->log($id),
        ];
    }

    /**
     * Abgelaufene und gelöschte Nachrichten der letzten HISTORY_KEEP_DAYS Tage (Bereich „Verlauf“ auf der Seite des
     * Bretts), zuletzt beendete zuerst. Nur für post_board (Route).
     *
     * @return array{items: list<array<string, mixed>>, keep_days: int}
     */
    public function ended(): array
    {
        $nowTs = $this->app->time()->timestamp();
        $ended = $this->repository()->ended(gmdate('Y-m-d H:i:s', $nowTs), gmdate('Y-m-d H:i:s', $nowTs - self::HISTORY_KEEP_DAYS * 86400));
        $names = (new UserNames($this->app->users))->prime([...array_column($ended, 'author_id'), ...array_column($ended, 'deleted_by')]);
        $files = $this->app->attachments()->many(AttachmentService::OWNER_BOARD, array_column($ended, 'id'));
        return ['items' => array_map(fn (array $m): array => $this->formatEnded($m, $names, $files[$m['id']] ?? []), $ended), 'keep_days' => self::HISTORY_KEEP_DAYS];
    }

    /**
     * Bild einer Nachricht ansehen (RichImageService::canView): solange sie gilt wie die Nachricht selbst, danach (im
     * Verlauf) nur mit post_board.
     */
    public function canSeeImage(User $viewer, int $messageId): bool
    {
        if (!$this->app->features()->enabled('board')) {
            return false;
        }
        if ($viewer->can(Capability::PostBoard)) {
            return $this->repository()->find($messageId) !== null;
        }
        return self::isRecipient($viewer) && $this->repository()->findActive($messageId, $this->app->time()->nowUtc()) !== null;
    }

    /**
     * Aufgabe board_cleanup: Nachrichten, die vor mehr als HISTORY_KEEP_DAYS Tagen endeten (abgelaufen oder gelöscht),
     * samt Verlauf, Mailprotokoll und Bildern endgültig löschen; dazu nie gespeicherte Bildentwürfe.
     */
    public function cleanup(): int
    {
        $before = gmdate('Y-m-d H:i:s', $this->app->time()->timestamp() - self::HISTORY_KEEP_DAYS * 86400);
        $ids = $this->repository()->endedBefore($before);
        $orphans = $this->repository()->deleteOrphanRevisions($before);
        $deleted = $this->repository()->deleteMany($ids);
        $this->app->richImages()->repository()->deleteOwners(RichImageService::OWNER_BOARD, array_values(array_unique([...$ids, ...$orphans])));
        $this->app->attachments()->deleteOwners(AttachmentService::OWNER_BOARD, array_values(array_unique([...$ids, ...$orphans])));
        $this->app->richImages()->cleanupDrafts();
        $this->app->attachments()->cleanupDrafts();
        return $deleted;
    }

    /** @return BoardMessage */
    public function active(int $id): array
    {
        return $this->repository()->findActive($id, $this->app->time()->nowUtc())
            ?? throw ApiError::notFound('Diese Nachricht gibt es nicht mehr (abgelaufen oder gelöscht).');
    }

    /** @return BoardItem */
    public function one(int $id, ?User $viewer = null): array
    {
        $editor = $viewer !== null && $viewer->can(Capability::PostBoard);
        $edit = $editor ? ($this->repository()->lastEdits([$id])[$id] ?? null) : null;
        $mail = $editor ? ($this->app->boardMail()->summaries([$id])[$id] ?? null) : null;
        return $this->format($this->active($id), new UserNames($this->app->users), $editor, $edit, $mail, $this->app->attachments()->of(AttachmentService::OWNER_BOARD, $id));
    }

    /**
     * @param BoardMessage $m
     * @param array{user_id: int, created_at: string}|null $edit
     * @param array<string, mixed>|null $mail
     * @param list<array<string, mixed>> $files Anhänge
     * @return BoardItem
     */
    private function format(array $m, UserNames $names, bool $editor = false, ?array $edit = null, ?array $mail = null, array $files = []): array
    {
        $out = [
            'kind' => self::KIND_MESSAGE,
            'id' => $m['id'],
            'title' => $m['title'],
            'text' => $this->app->richImages()->render($m['body']),
            'excerpt' => RichText::excerpt($m['body']),
            'author_name' => $names->name($m['author_id']),
            'created_at' => Time::utcToIso($m['created_at']),
            'updated_at' => Time::utcToIso($m['updated_at']),
            'expires_at' => Time::utcToIso($m['expires_at']),
            'attachments' => array_map(fn (array $f): array => $this->app->attachments()->format($f), $files),
        ];
        if ($editor) {
            $out['last_edit'] = $edit === null ? null : ['name' => $names->name($edit['user_id']), 'at' => Time::utcToIso($edit['created_at'])];
            $out['mail'] = $mail;
        }
        return $out;
    }

    /**
     * @param BoardMessage $m
     * @param list<array<string, mixed>>|null $files Anhänge (ohne Angabe gelesen)
     * @return array<string, mixed>
     */
    private function formatEnded(array $m, UserNames $names, ?array $files = null): array
    {
        $files ??= $this->app->attachments()->of(AttachmentService::OWNER_BOARD, $m['id']);
        return [
            'id' => $m['id'],
            'title' => $m['title'],
            'text' => $this->app->richImages()->render($m['body']),
            'excerpt' => RichText::excerpt($m['body']),
            'author_name' => $names->name($m['author_id']),
            'created_at' => Time::utcToIso($m['created_at']),
            'updated_at' => Time::utcToIso($m['updated_at']),
            'expires_at' => Time::utcToIso($m['expires_at']),
            'deleted_at' => Time::utcToIso($m['deleted_at']),
            'deleted_by_name' => $m['deleted_by'] !== null ? $names->name($m['deleted_by']) : null,
            'active' => $m['deleted_at'] === null && $m['expires_at'] > $this->app->time()->nowUtc(),
            'attachments' => array_map(fn (array $f): array => $this->app->attachments()->format($f), $files),
        ];
    }

    /**
     * Ein Verlaufseintrag für die App: Inhalte mit Bildadressen, Zeiten als ISO 8601.
     *
     * @param BoardRevision $r
     * @return array<string, mixed>
     */
    private function formatRevision(array $r, UserNames $names): array
    {
        $value = function (?string $v) use ($r): mixed {
            if ($v === null) {
                return null;
            }
            return match ($r['action']) {
                self::REV_CONTENT => $this->app->richImages()->render($v),
                self::REV_EXPIRES => Time::utcToIso($v),
                self::REV_TITLE => $v,
                default => $this->decode($v),
            };
        };
        return [
            'id' => $r['id'],
            'action' => $r['action'],
            'user_name' => $names->name($r['user_id']),
            'at' => Time::utcToIso($r['created_at']),
            'before' => $value($r['before_value']),
            'after' => $value($r['after_value']),
        ];
    }

    /** @return array<string, mixed>|null JSON-Momentaufnahme (erstellt, gelöscht, versendet) für die App */
    private function decode(string $json): ?array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        if (isset($data['body']) && is_string($data['body'])) {
            $data['text'] = $this->app->richImages()->render($data['body']);
            unset($data['body']);
        }
        if (isset($data['expires_at']) && is_string($data['expires_at'])) {
            $data['expires_at'] = Time::utcToIso($data['expires_at']);
        }
        return $data;
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function validTitle(mixed $title): string
    {
        $title = is_string($title) ? Text::line($title) : '';
        if ($title === '') {
            throw ApiError::invalid('Bitte einen Titel eingeben.');
        }
        return $title;
    }

    private static function validBody(mixed $text): string
    {
        if (!is_string($text)) {
            throw ApiError::invalid('Bitte einen Text eingeben.');
        }
        $body = RichText::sanitize($text);
        if (RichText::isEmpty($body)) {
            throw ApiError::invalid('Bitte einen Text eingeben.');
        }
        return $body;
    }

    /** Enddatum: gültiger Zeitpunkt (Time::parseToUtc) in der Zukunft; keine Obergrenze. */
    private function validExpires(mixed $value): string
    {
        $utc = $this->app->time()->parseToUtc($value);
        if ($utc === null) {
            throw ApiError::invalid('Ungültiges Enddatum (Datum und Uhrzeit erwartet).');
        }
        if ($utc <= $this->app->time()->nowUtc()) {
            throw ApiError::invalid('Das Enddatum muss in der Zukunft liegen.');
        }
        return $utc;
    }
}
