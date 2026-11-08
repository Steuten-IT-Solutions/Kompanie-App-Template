<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Domain\Blocks\Blocks;
use KompanieApp\Core\Domain\Blocks\TextBlock;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Storage\BoardRepository;

/**
 * Schwarzes Brett (Issue #5, Umbau #172): dringende Nachrichten für alle Mitglieder und Anwärter, auf Wunsch auch öffentlich.
 *
 * - Jede Nachricht ist intern (audience members: wer das Brett liest, isRecipient) oder öffentlich (audience public:
 *   alle, auch ohne Anmeldung, wie bei eigenen Seiten). Das wählt, wer schreibt; ändern lässt es sich, solange sie gilt.
 *   Bestehende Nachrichten sind intern. Öffentlich sichtbar sind nur Titel, Inhalt, Anhänge, Zeiten und der Name des
 *   Verfassers; wer zuletzt geändert hat, der Mailversand und der Verlauf bleiben bei post_board.
 * - Enger als intern: nur für bestimmte Kompanien (audience company: wer das Brett liest und einer davon angehört,
 *   Kompanieliste) oder für bestimmte Personen (audience person: nur diese, sofern sie das Brett lesen). Die IDs stehen in
 *   audience_ids (mindestens eine). Alle mit post_board sehen auch diese Nachrichten (sie dürfen sie ändern und löschen).
 *   Benachrichtigung und Mail gehen nur an die, die die Nachricht lesen dürfen (readerIds).
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
 *   (BoardMailService), beim Ändern nicht. Bei öffentlichen Nachrichten kommen zur Benachrichtigung die Geräte ohne
 *   Brett dazu (Gäste und Konten ohne view_board, Art board_public); die Mail geht weiter nur an die Empfänger.
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
    /** Höchstlänge des Texts aller Textblöcke zusammen (Bytes; mit Blöcken, Domain\Blocks\Blocks). */
    public const BODY_MAX = 1000000;
    public const TASK = 'board_cleanup';
    public const KIND_MESSAGE = 'message';
    public const AUDIENCE_PUBLIC = 'public';
    public const AUDIENCE_MEMBERS = 'members';
    public const AUDIENCE_COMPANY = 'company';
    public const AUDIENCE_PERSON = 'person';
    public const AUDIENCES = [self::AUDIENCE_MEMBERS, self::AUDIENCE_PUBLIC, self::AUDIENCE_COMPANY, self::AUDIENCE_PERSON];
    /** Sichtbarkeiten mit ausgewählten Kompanien bzw. Personen (audience_ids) */
    public const TARGETED = [self::AUDIENCE_COMPANY, self::AUDIENCE_PERSON];

    public const REV_CREATED = 'created';
    public const REV_TITLE = 'title';
    public const REV_CONTENT = 'content';
    public const REV_EXPIRES = 'expires';
    public const REV_DELETED = 'deleted';
    public const REV_MAILED = 'mailed';
    public const REV_ATTACHMENTS = 'attachments';
    public const REV_AUDIENCE = 'audience';

    private ?BoardRepository $repo = null;
    /** @var array<int, list<int>> Konto => Kompanien (für canRead, je Anfrage einmal gelesen) */
    private array $companiesOf = [];

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
     * Darf $viewer (null = ohne Anmeldung) diese Nachricht lesen? Öffentliche alle, interne die Empfänger (isRecipient),
     * die für Kompanien die Empfänger, die einer davon angehören, die für Personen nur diese (sofern Empfänger); alle mit
     * post_board ohnehin. Die einzige Stelle für diese Regel (Liste, Bilder, Anhänge).
     *
     * @param array{audience: string, audience_ids: list<int>} $message
     */
    public function canRead(?User $viewer, array $message): bool
    {
        if ($message['audience'] === self::AUDIENCE_PUBLIC || ($viewer !== null && $viewer->can(Capability::PostBoard))) {
            return true;
        }
        if (!self::isRecipient($viewer)) {
            return false;
        }
        /** @var User $viewer */
        return match ($message['audience']) {
            self::AUDIENCE_PERSON => in_array($viewer->id, $message['audience_ids'], true),
            self::AUDIENCE_COMPANY => array_intersect($message['audience_ids'], $this->companiesOf[$viewer->id] ??= $this->app->companies()->repository()->companyIdsOf($viewer->id)) !== [],
            default => true,
        };
    }

    /**
     * Wer eine Nachricht für Kompanien oder Personen bekommt (Benachrichtigung, Mail): Konto-IDs vor der Prüfung auf
     * isRecipient, die Push und Mail ohnehin machen; null bei intern und öffentlich (keine Einschränkung).
     *
     * @param array{audience: string, audience_ids: list<int>} $message
     * @return list<int>|null
     */
    public function readerIds(array $message): ?array
    {
        return match ($message['audience']) {
            self::AUDIENCE_PERSON => $message['audience_ids'],
            self::AUDIENCE_COMPANY => array_values(array_unique(array_merge([], ...array_map(fn (int $id): array => $this->app->companies()->repository()->memberIdsOf($id), $message['audience_ids'])))),
            default => null,
        };
    }

    /** readerIds einer gültigen Nachricht über ihre ID (null auch, wenn es sie nicht mehr gibt). @return list<int>|null */
    public function readerIdsOf(int $id): ?array
    {
        $message = $this->repository()->find($id);
        return $message === null ? null : $this->readerIds($message);
    }

    /**
     * Auswahl beim Schreiben (GET /board/audiences, post_board): Kompanien (nur bei eingeschaltetem Bereich Kompanien,
     * ohne aufgelöste) und Personen, die das Brett lesen (isRecipient), nach Namen.
     *
     * @return array{companies: list<array{id: int, name: string, short_name: string}>, people: list<array{id: int, name: string}>}
     */
    public function audiences(): array
    {
        $companies = [];
        if ($this->app->features()->enabled(CompanyService::FEATURE)) {
            foreach ($this->app->companies()->repository()->all() as $c) {
                if ($c['active']) {
                    $companies[] = ['id' => $c['id'], 'name' => $c['name'], 'short_name' => $c['short_name']];
                }
            }
        }
        $people = [];
        foreach ($this->app->users->list(false) as $u) {
            if (self::isRecipient($u)) {
                $people[] = ['id' => $u->id, 'name' => $u->name];
            }
        }
        return ['companies' => $companies, 'people' => $people];
    }

    /**
     * Gültige Nachrichten, die $viewer lesen darf (canRead; ohne Anmeldung nur öffentliche), neueste zuerst; danach für Mitglieder die laufenden Umfragen (Issue #4, kind „poll“, mit
     * has_voted), sofern der Bereich Umfragen an ist. Mit post_board zusätzlich, wer zuletzt geändert hat, und der Stand
     * des Mailversands.
     *
     * @return array{items: list<array<string, mixed>>}
     */
    public function list(?User $viewer = null): array
    {
        $active = array_values(array_filter($this->repository()->active($this->app->time()->nowUtc()), fn (array $m): bool => $this->canRead($viewer, $m)));
        $editor = $viewer !== null && $viewer->can(Capability::PostBoard);
        $ids = array_column($active, 'id');
        $edits = $editor ? $this->repository()->lastEdits($ids) : [];
        $mails = $editor ? $this->app->boardMail()->summaries($ids) : [];
        $names = (new UserNames($this->app->users))->prime([...array_column($active, 'author_id'), ...array_column($edits, 'user_id')]);
        $files = $this->app->attachments()->many(AttachmentService::OWNER_BOARD, $ids);
        $targets = $this->targetNames($active, $names);
        $messages = array_map(fn (array $m): array => $this->format($m, $names, $editor, $edits[$m['id']] ?? null, $mails[$m['id']] ?? null, $files[$m['id']] ?? [], $targets, $viewer), $active);
        return ['items' => [...$messages, ...$this->app->polls()->boardItems($viewer)]];
    }

    /**
     * Neue Nachricht.
     *
     * @param array<string, mixed> $in title, blocks (Inhalt aus Blöcken, Domain\Blocks\Blocks) oder text (formatierter
     *                                 Inhalt, ältere App), expires_at (optional, ISO 8601), audience (members Standard,
     *                                 public, company oder person), audience_ids (bei company und person)
     * @return BoardItem
     */
    public function create(User $author, array $in): array
    {
        $title = self::validTitle($in['title'] ?? null);
        $blocks = $this->validContent($in);
        $audience = self::validAudience($in['audience'] ?? self::AUDIENCE_MEMBERS);
        $audienceIds = $this->validTargets($audience, $in['audience_ids'] ?? null);
        $nowTs = $this->app->time()->timestamp();
        $now = gmdate('Y-m-d H:i:s', $nowTs);
        $expires = array_key_exists('expires_at', $in) && $in['expires_at'] !== null && $in['expires_at'] !== ''
            ? $this->validExpires($in['expires_at'])
            : gmdate('Y-m-d H:i:s', $nowTs + self::LIFETIME);
        $files = $this->app->attachments()->resolve($author, $in['attachments'] ?? null, AttachmentService::OWNER_BOARD);
        $id = $this->app->db->transaction(function () use ($author, $title, $blocks, $now, $expires, $files, $audience, $audienceIds): int {
            $id = $this->repository()->insert($title, Blocks::publicBody($blocks), $author->id, $now, $expires, $audience, $audienceIds);
            $columns = $this->app->blocks()->columns($blocks, $author, RichImageService::OWNER_BOARD, $id, false);
            $this->repository()->update($id, $columns);
            $this->app->attachments()->assign($files, AttachmentService::OWNER_BOARD, $id);
            $snapshot = ['title' => $title, 'body' => self::contentValue($columns), 'expires_at' => $expires, 'audience' => $audience];
            if ($audienceIds !== []) {
                $snapshot['audience_ids'] = $audienceIds;
            }
            if ($files !== []) {
                $snapshot['attachments'] = array_map(static fn (array $f): string => (string) $f['name'], $files);
            }
            $this->repository()->addRevision($id, $author->id, $now, self::REV_CREATED, null, self::json($snapshot));
            return $id;
        });
        return $this->one($id, $author);
    }

    /**
     * Titel, Inhalt, Enddatum und/oder Sichtbarkeit (audience) ändern, solange die Nachricht gilt (einzeln oder zusammen). Unverändertes ändert
     * nichts. Geänderter Titel oder Inhalt bekommt immer ein späteres updated_at als vorher, auch innerhalb derselben
     * Sekunde (Issue #68): Ausgeblendet merkt sich die App die Fassung über updated_at. Nur ein neues Enddatum lässt
     * updated_at unverändert (ausgeblendet bleibt ausgeblendet), ebenso eine neue Sichtbarkeit. Eine Benachrichtigung
     * gibt es beim Ändern nie, auch nicht, wenn eine Nachricht öffentlich wird.
     *
     * @param array<string, mixed> $in title, blocks oder text, expires_at, audience, audience_ids (bei company und person; ohne
     *                                 audience für die bisherige), attachments (alle Anhänge danach: Kennungen)
     * @return BoardItem
     */
    public function update(User $actor, int $id, array $in): array
    {
        $message = $this->active($id);
        $values = [];
        $audienceIds = $message['audience_ids'];
        if (array_key_exists('title', $in)) {
            $title = self::validTitle($in['title']);
            if ($title !== $message['title']) {
                $values['title'] = $title;
            }
        }
        $content = array_key_exists('blocks', $in) || array_key_exists('text', $in);
        $contentBefore = null;
        if ($content) {
            // Bilder nicht mehr verwendeter Fassungen bleiben (Verlauf), bis die Nachricht gelöscht wird (cleanup).
            $columns = $this->app->blocks()->columns($this->validContent($in), $actor, RichImageService::OWNER_BOARD, $id, false);
            if (self::contentValue($columns) !== self::contentValue($message)) {
                $values['body'] = $columns['body'];
                $values['blocks'] = $columns['blocks'];
                $contentBefore = self::contentValue($message);
            }
        }
        if (array_key_exists('expires_at', $in)) {
            $expires = $this->validExpires($in['expires_at']);
            if ($expires !== $message['expires_at']) {
                $values['expires_at'] = $expires;
            }
        }
        if (array_key_exists('audience', $in) || array_key_exists('audience_ids', $in)) {
            $audience = array_key_exists('audience', $in) ? self::validAudience($in['audience']) : $message['audience'];
            // Gleiche Sichtbarkeit ohne neue Auswahl: die bisherigen Kompanien bzw. Personen bleiben.
            $ids = !array_key_exists('audience_ids', $in) && $audience === $message['audience'] ? $message['audience_ids'] : $this->validTargets($audience, $in['audience_ids'] ?? null);
            if ($audience !== $message['audience'] || $ids !== $message['audience_ids']) {
                $values['audience'] = $audience;
                $values['audience_ids'] = BoardRepository::ids($ids);
                $audienceIds = $ids;
            }
        }
        if (!array_key_exists('title', $in) && !$content && !array_key_exists('expires_at', $in) && !array_key_exists('attachments', $in) && !array_key_exists('audience', $in) && !array_key_exists('audience_ids', $in)) {
            throw ApiError::invalid('Bitte Titel, Inhalt, Anhänge, Enddatum oder Sichtbarkeit angeben.');
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
        $this->app->db->transaction(function () use ($id, $values, $message, $actor, $now, $files, $before, $audienceIds, $contentBefore): void {
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
                $this->repository()->addRevision($id, $actor->id, $now, self::REV_CONTENT, $contentBefore, self::contentValue(['body' => (string) $values['body'], 'blocks' => $values['blocks'] ?? null]));
            }
            if (isset($values['expires_at'])) {
                $this->repository()->addRevision($id, $actor->id, $now, self::REV_EXPIRES, $message['expires_at'], $values['expires_at']);
            }
            if (isset($values['audience'])) {
                $after = ['audience' => (string) $values['audience'], 'audience_ids' => $audienceIds];
                $this->repository()->addRevision($id, $actor->id, $now, self::REV_AUDIENCE, self::audienceValue($message), self::audienceValue($after));
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
    public function history(int $id, ?User $viewer = null): array
    {
        $message = $this->repository()->find($id) ?? throw ApiError::notFound('Diese Nachricht gibt es nicht mehr.');
        $revisions = $this->repository()->revisions([$id])[$id] ?? [];
        $names = (new UserNames($this->app->users))->prime([$message['author_id'], $message['deleted_by'], ...array_column($revisions, 'user_id')]);
        // Namen auch für die Sichtbarkeiten im Verlauf
        $shown = [$message];
        foreach ($revisions as $r) {
            if ($r['action'] === self::REV_AUDIENCE) {
                $shown[] = self::parseAudience($r['before_value']);
                $shown[] = self::parseAudience($r['after_value']);
            } elseif ($r['action'] === self::REV_CREATED) {
                $created = json_decode((string) $r['after_value'], true);
                if (is_array($created) && is_string($created['audience'] ?? null) && is_array($created['audience_ids'] ?? null)) {
                    $shown[] = ['audience' => $created['audience'], 'audience_ids' => array_values(array_filter($created['audience_ids'], 'is_int'))];
                }
            }
        }
        $targets = $this->targetNames($shown, $names);
        return [
            'message' => $this->formatEnded($message, $names, null, $targets, $viewer),
            'revisions' => array_map(fn (array $r): array => $this->formatRevision($r, $names, $targets, $viewer), $revisions),
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
    public function ended(?User $viewer = null): array
    {
        $nowTs = $this->app->time()->timestamp();
        $ended = $this->repository()->ended(gmdate('Y-m-d H:i:s', $nowTs), gmdate('Y-m-d H:i:s', $nowTs - self::HISTORY_KEEP_DAYS * 86400));
        $names = (new UserNames($this->app->users))->prime([...array_column($ended, 'author_id'), ...array_column($ended, 'deleted_by')]);
        $files = $this->app->attachments()->many(AttachmentService::OWNER_BOARD, array_column($ended, 'id'));
        $targets = $this->targetNames($ended, $names);
        return ['items' => array_map(fn (array $m): array => $this->formatEnded($m, $names, $files[$m['id']] ?? [], $targets, $viewer), $ended), 'keep_days' => self::HISTORY_KEEP_DAYS];
    }

    /**
     * Bild oder Anhang einer Nachricht ansehen (RichImageService::canView, AttachmentService::canView): solange sie gilt
     * wie die Nachricht selbst (öffentliche auch ohne Anmeldung, $viewer null), danach (im Verlauf) nur mit post_board.
     */
    public function canSeeImage(?User $viewer, int $messageId, ?string $uid = null): bool
    {
        if (!$this->app->features()->enabled('board')) {
            return false;
        }
        if ($viewer !== null && $viewer->can(Capability::PostBoard)) {
            return $this->repository()->find($messageId) !== null;
        }
        $message = $this->repository()->findActive($messageId, $this->app->time()->nowUtc());
        if ($message === null || !$this->canRead($viewer, $message)) {
            return false;
        }
        // Bilder in Blöcken nur für Mitglieder: auch bei öffentlichen Nachrichten nur für Empfänger
        return $uid === null || self::isRecipient($viewer) || !Blocks::imageOnlyForMembers(Blocks::decode($message['blocks'], $message['body']), $uid);
    }

    /**
     * Formatierter Inhalt einer Nachricht in einem Stück für die Mail an $recipient (alle Blöcke, auch die nur für
     * Mitglieder: Mails gehen nur an Empfänger, isRecipient), Bilder als data-image.
     *
     * @param array{body: string, blocks: string|null} $message
     */
    public function mailHtml(array $message, ?User $recipient = null): string
    {
        return $this->app->blocks()->html(Blocks::decode($message['blocks'], $message['body']), true, $recipient);
    }

    /**
     * Wie mailHtml für eine Anfrage vor dem Speichern (Prüfung der Mailgröße); null bei ungültigem Inhalt (den meldet
     * create).
     *
     * @param array<string, mixed> $in
     */
    public function inputMailHtml(array $in, User $author): ?string
    {
        try {
            return $this->app->blocks()->html($this->validContent($in), true, $author);
        } catch (ApiError) {
            return null;
        }
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
        $message = $this->active($id);
        $names = new UserNames($this->app->users);
        return $this->format($message, $names, $editor, $edit, $mail, $this->app->attachments()->of(AttachmentService::OWNER_BOARD, $id), $this->targetNames([$message], $names), $viewer);
    }

    /**
     * @param BoardMessage $m
     * @param array{user_id: int, created_at: string}|null $edit
     * @param array<string, mixed>|null $mail
     * @param list<array<string, mixed>> $files Anhänge
     * @param array{companies: array<int, string>} $targets Namen der Kompanien (targetNames)
     * @return BoardItem
     */
    private function format(array $m, UserNames $names, bool $editor = false, ?array $edit = null, ?array $mail = null, array $files = [], array $targets = ['companies' => []], ?User $viewer = null): array
    {
        $member = $editor || self::isRecipient($viewer);
        $blocks = Blocks::decode($m['blocks'], $m['body']);
        $out = [
            'kind' => self::KIND_MESSAGE,
            'id' => $m['id'],
            'title' => $m['title'],
            // text: Textblöcke für alle (ältere App); blocks: alle Blöcke, die $viewer sieht
            'text' => $this->app->richImages()->render($m['body']),
            'blocks' => $this->app->blocks()->forViewer($blocks, $viewer, $member, $editor),
            'excerpt' => $this->app->blocks()->plain($blocks, $member, $viewer),
            'author_name' => $names->name($m['author_id']),
            'created_at' => Time::utcToIso($m['created_at']),
            'updated_at' => Time::utcToIso($m['updated_at']),
            'expires_at' => Time::utcToIso($m['expires_at']),
            'audience' => $m['audience'],
            'attachments' => array_map(fn (array $f): array => $this->app->attachments()->format($f), $files),
        ];
        // Für wen: Kompanien sehen alle, die die Nachricht lesen; ausgewählte Personen nur post_board (Empfänger sehen nur,
        // dass sie für sie bestimmt ist).
        if ($m['audience'] === self::AUDIENCE_COMPANY || ($editor && $m['audience'] === self::AUDIENCE_PERSON)) {
            $out['audience_names'] = $this->namesOf($m, $names, $targets);
        }
        if ($editor && in_array($m['audience'], self::TARGETED, true)) {
            $out['audience_ids'] = $m['audience_ids'];
        }
        if ($editor) {
            $out['last_edit'] = $edit === null ? null : ['name' => $names->name($edit['user_id']), 'at' => Time::utcToIso($edit['created_at'])];
            $out['mail'] = $mail;
        }
        return $out;
    }

    /**
     * @param BoardMessage $m
     * @param list<array<string, mixed>>|null $files Anhänge (ohne Angabe gelesen)
     * @param array{companies: array<int, string>} $targets Namen der Kompanien (targetNames)
     * @return array<string, mixed>
     */
    private function formatEnded(array $m, UserNames $names, ?array $files = null, array $targets = ['companies' => []], ?User $viewer = null): array
    {
        $files ??= $this->app->attachments()->of(AttachmentService::OWNER_BOARD, $m['id']);
        $blocks = Blocks::decode($m['blocks'], $m['body']);
        $out = [
            'id' => $m['id'],
            'title' => $m['title'],
            'text' => $this->app->richImages()->render($m['body']),
            'blocks' => $this->app->blocks()->forViewer($blocks, $viewer, true, true),
            'excerpt' => $this->app->blocks()->plain($blocks, true, $viewer),
            'author_name' => $names->name($m['author_id']),
            'created_at' => Time::utcToIso($m['created_at']),
            'updated_at' => Time::utcToIso($m['updated_at']),
            'expires_at' => Time::utcToIso($m['expires_at']),
            'deleted_at' => Time::utcToIso($m['deleted_at']),
            'deleted_by_name' => $m['deleted_by'] !== null ? $names->name($m['deleted_by']) : null,
            'active' => $m['deleted_at'] === null && $m['expires_at'] > $this->app->time()->nowUtc(),
            'audience' => $m['audience'],
            'attachments' => array_map(fn (array $f): array => $this->app->attachments()->format($f), $files),
        ];
        // Nur für post_board (Verlauf)
        if (in_array($m['audience'], self::TARGETED, true)) {
            $out['audience_ids'] = $m['audience_ids'];
            $out['audience_names'] = $this->namesOf($m, $names, $targets);
        }
        return $out;
    }

    /**
     * Namen der Kompanien aus den Nachrichten in einer Abfrage (nur wenn eine für Kompanien dabei ist); die Namen der
     * Personen holt $names in einer Abfrage dazu.
     *
     * @param list<array{audience: string, audience_ids: list<int>}> $messages
     * @return array{companies: array<int, string>}
     */
    private function targetNames(array $messages, UserNames $names): array
    {
        $companies = false;
        $people = [];
        foreach ($messages as $m) {
            if ($m['audience'] === self::AUDIENCE_COMPANY) {
                $companies = true;
            } elseif ($m['audience'] === self::AUDIENCE_PERSON) {
                $people = [...$people, ...$m['audience_ids']];
            }
        }
        if ($people !== []) {
            $names->prime($people);
        }
        if (!$companies) {
            return ['companies' => []];
        }
        return ['companies' => array_column($this->app->companies()->repository()->all(), 'name', 'id')];
    }

    /**
     * Namen der ausgewählten Kompanien bzw. Personen (gelöschte fehlen).
     *
     * @param array{audience: string, audience_ids: list<int>} $m
     * @param array{companies: array<int, string>} $targets
     * @return list<string>
     */
    private function namesOf(array $m, UserNames $names, array $targets): array
    {
        $out = [];
        foreach ($m['audience_ids'] as $id) {
            $name = $m['audience'] === self::AUDIENCE_COMPANY ? ($targets['companies'][$id] ?? null) : $names->name($id);
            if ($name !== null && $name !== '') {
                $out[] = $name;
            }
        }
        return $out;
    }

    /**
     * Sichtbarkeit im Verlauf: members und public wie bisher, für Kompanien und Personen „company:1,2“ bzw. „person:5“.
     *
     * @param array{audience: string, audience_ids?: list<int>} $m
     */
    private static function audienceValue(array $m): string
    {
        $ids = $m['audience_ids'] ?? [];
        return in_array($m['audience'], self::TARGETED, true) ? $m['audience'] . ':' . implode(',', $ids) : $m['audience'];
    }

    /** @return array{audience: string, audience_ids: list<int>} Gegenstück zu audienceValue */
    private static function parseAudience(?string $value): array
    {
        [$audience, $ids] = array_pad(explode(':', (string) $value, 2), 2, '');
        return [
            'audience' => in_array($audience, self::AUDIENCES, true) ? $audience : self::AUDIENCE_MEMBERS,
            'audience_ids' => array_values(array_map('intval', array_filter(explode(',', $ids), static fn (string $v): bool => ctype_digit($v)))),
        ];
    }

    /**
     * Ein Verlaufseintrag für die App: Inhalte mit Bildadressen, Zeiten als ISO 8601.
     *
     * Sichtbarkeit (audience): members, public, company oder person; bei den beiden letzten mit den Namen dahinter
     * („company: Erste, Zweite“), damit der Verlauf auch nach dem Löschen einer Kompanie lesbar bleibt, solange es geht.
     *
     * @param BoardRevision $r
     * @param array{companies: array<int, string>} $targets
     * @return array<string, mixed>
     */
    private function formatRevision(array $r, UserNames $names, array $targets = ['companies' => []], ?User $viewer = null): array
    {
        $value = function (?string $v) use ($r, $names, $targets, $viewer): mixed {
            if ($v === null) {
                return null;
            }
            return match ($r['action']) {
                self::REV_CONTENT => $this->contentHtml($v, $viewer),
                self::REV_EXPIRES => Time::utcToIso($v),
                self::REV_TITLE => $v,
                self::REV_AUDIENCE => $this->audienceText(self::parseAudience($v), $names, $targets),
                default => $this->decode($v, $names, $targets, $viewer),
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

    /**
     * @param array{audience: string, audience_ids: list<int>} $m
     * @param array{companies: array<int, string>} $targets
     */
    private function audienceText(array $m, UserNames $names, array $targets): string
    {
        if (!in_array($m['audience'], self::TARGETED, true)) {
            return $m['audience'];
        }
        return $m['audience'] . ': ' . implode(', ', $this->namesOf($m, $names, $targets));
    }

    /**
     * @param array{companies: array<int, string>} $targets
     * @return array<string, mixed>|null JSON-Momentaufnahme (erstellt, gelöscht, versendet) für die App
     */
    private function decode(string $json, UserNames $names, array $targets, ?User $viewer = null): ?array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        if (is_string($data['audience'] ?? null) && in_array($data['audience'], self::TARGETED, true) && is_array($data['audience_ids'] ?? null)) {
            $data['audience_names'] = $this->namesOf(['audience' => $data['audience'], 'audience_ids' => array_values(array_filter($data['audience_ids'], 'is_int'))], $names, $targets);
        }
        if (isset($data['body']) && is_string($data['body'])) {
            $data['text'] = $this->contentHtml($data['body'], $viewer);
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

    /**
     * Inhalt aus einer Anfrage: blocks (hat Vorrang) oder text (ältere App, ein Textblock); mindestens ein Block mit
     * Inhalt.
     *
     * @param array<string, mixed> $in
     * @return list<array<string, mixed>>
     */
    private function validContent(array $in): array
    {
        if (!array_key_exists('blocks', $in)) {
            return Blocks::fromBody(self::validBody($in['text'] ?? null));
        }
        $blocks = $this->app->blocks()->validate($in['blocks'], self::BODY_MAX);
        foreach ($blocks as $b) {
            if ($b['type'] !== TextBlock::TYPE || $b['html'] !== '') {
                return $blocks;
            }
        }
        throw ApiError::invalid('Bitte einen Text eingeben oder einen Block einfügen.');
    }

    /**
     * Inhalt für den Verlauf (REV_CONTENT, Momentaufnahme bei REV_CREATED): nur ein Textblock für alle wie bisher als
     * formatierter Text, sonst die Blöcke (Blocks::encode).
     *
     * @param array{body: string, blocks: string|null} $m
     */
    private static function contentValue(array $m): string
    {
        $blocks = Blocks::decode($m['blocks'], $m['body']);
        return $blocks === Blocks::fromBody($m['body']) ? $m['body'] : Blocks::encode($blocks, $m['body']);
    }

    /** Inhalt aus dem Verlauf (contentValue) als formatierter Text mit Bildern. */
    private function contentHtml(string $value, ?User $viewer): string
    {
        $blocks = Blocks::stored($value);
        return $this->app->richImages()->render($blocks === null ? $value : $this->app->blocks()->html($blocks, true, $viewer, true));
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

    private static function validAudience(mixed $audience): string
    {
        if (!is_string($audience) || !in_array($audience, self::AUDIENCES, true)) {
            throw ApiError::invalid('Sichtbarkeit muss „members“ (intern), „public“ (öffentlich), „company“ (Kompanie) oder „person“ (einzeln) sein.');
        }
        return $audience;
    }

    /**
     * Ausgewählte Kompanien (company: vorhanden, Bereich Kompanien an) bzw. Personen (person: lesen das Brett) als
     * sortierte, eindeutige Liste; bei intern und öffentlich immer leer (eine Angabe wird ignoriert).
     *
     * @return list<int>
     */
    private function validTargets(string $audience, mixed $ids): array
    {
        if (!in_array($audience, self::TARGETED, true)) {
            return [];
        }
        $company = $audience === self::AUDIENCE_COMPANY;
        $what = $company ? 'mindestens eine Kompanie' : 'mindestens eine Person';
        if (!is_array($ids) || $ids === []) {
            throw ApiError::invalid('Bitte ' . $what . ' auswählen.');
        }
        $out = [];
        foreach ($ids as $id) {
            if (!is_int($id) || $id <= 0) {
                throw ApiError::invalid('audience_ids muss eine Liste von IDs sein.');
            }
            $out[$id] = $id;
        }
        $out = array_values($out);
        sort($out);
        if ($company) {
            if (!$this->app->features()->enabled(CompanyService::FEATURE)) {
                throw ApiError::invalid('Der Bereich Kompanien ist ausgeschaltet.');
            }
            $known = array_column($this->app->companies()->repository()->all(), 'id');
            if (array_diff($out, $known) !== []) {
                throw ApiError::invalid('Diese Kompanie gibt es nicht.');
            }
        } else {
            $users = $this->app->users->findMany($out);
            foreach ($out as $id) {
                if (!self::isRecipient($users[$id] ?? null)) {
                    throw ApiError::invalid('Nachrichten am Brett lassen sich nur Personen freigeben, die das Brett lesen.');
                }
            }
        }
        return $out;
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
