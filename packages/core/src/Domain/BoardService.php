<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Storage\BoardRepository;

/**
 * Schwarzes Brett (Issue #5): dringende Nachrichten für alle Mitglieder und Anwärter.
 *
 * - Schreiben, ändern und vorzeitig löschen darf, wer das Recht post_board hat (nicht nur, wer sie geschrieben hat).
 * - Reiner Text, höchstens TEXT_MAX Zeichen, Zeilenumbrüche bleiben; nie HTML.
 * - Jede Nachricht gilt genau LIFETIME Sekunden ab dem Schreiben; Ändern verlängert nicht. Danach liefert die API sie
 *   nicht mehr, die Aufgabe board_cleanup löscht sie (kein Verlauf).
 * - Beim Schreiben bekommen alle Empfänger eine Benachrichtigung (PushTriggers::boardPosted), beim Ändern nicht.
 * - Die Liste besteht aus Einträgen mit kind: „message“ und für Mitglieder „poll“ (laufende Umfragen, Issue #4,
 *   PollService::boardItems); Clients überspringen unbekannte Arten.
 *
 * @phpstan-import-type BoardMessage from BoardRepository
 * @phpstan-type BoardItem array{kind: string, id: int, text: string, author_name: string|null, created_at: string|null, updated_at: string|null, expires_at: string|null}
 */
final class BoardService
{
    public const TEXT_MAX = 500;
    public const LIFETIME = 86400;
    public const TASK = 'board_cleanup';
    public const KIND_MESSAGE = 'message';

    private ?BoardRepository $repo = null;

    public function __construct(private readonly App $app)
    {
    }

    public function repository(): BoardRepository
    {
        return $this->repo ??= new BoardRepository($this->app->db);
    }

    /**
     * Wer das Brett sieht und Benachrichtigungen dazu bekommt: Mitglieder und Anwärter, also alle mit Mitgliederbereich
     * oder internem Kalender (wie GET /calendar). Die einzige Stelle für diese Regel (API, Push, Typen der Benachrichtigungen).
     */
    public static function isRecipient(?User $user): bool
    {
        return $user !== null && ($user->isMember() || $user->can(Capability::Calendar));
    }

    /**
     * Gültige Nachrichten, neueste zuerst; danach für Mitglieder die laufenden Umfragen (Issue #4, kind „poll“, mit
     * has_voted), sofern der Bereich Umfragen an ist.
     *
     * @return array{items: list<array<string, mixed>>}
     */
    public function list(?User $viewer = null): array
    {
        $active = $this->repository()->active($this->app->time()->nowUtc());
        $names = (new UserNames($this->app->users))->prime(array_column($active, 'author_id'));
        $messages = array_map(fn (array $m): array => $this->format($m, $names), $active);
        return ['items' => [...$messages, ...$this->app->polls()->boardItems($viewer)]];
    }

    /** @return BoardItem */
    public function create(User $author, mixed $text): array
    {
        $body = self::validText($text);
        $ts = $this->app->time()->timestamp();
        $id = $this->repository()->insert($body, $author->id, gmdate('Y-m-d H:i:s', $ts), gmdate('Y-m-d H:i:s', $ts + self::LIFETIME));
        return $this->one($id);
    }

    /**
     * Text ändern, solange die Nachricht gilt; die Ablaufzeit bleibt. Unveränderter Text ändert nichts (auch nicht
     * updated_at: Wer sie auf dem Gerät ausgeblendet hat, bekommt sie dann nicht erneut angezeigt). Geänderter Text
     * bekommt immer ein späteres updated_at als vorher, auch innerhalb derselben Sekunde (Issue #68): Ausgeblendet
     * merkt sich die App die Fassung über updated_at.
     *
     * @return BoardItem
     */
    public function update(int $id, mixed $text): array
    {
        $message = $this->active($id);
        $body = self::validText($text);
        if ($body !== $message['body']) {
            $now = $this->app->time()->nowUtc();
            if ($now <= $message['updated_at']) {
                $now = gmdate('Y-m-d H:i:s', (int) strtotime($message['updated_at'] . ' UTC') + 1);
            }
            $this->repository()->update($id, $body, $now);
        }
        return $this->one($id);
    }

    public function delete(int $id): void
    {
        $this->active($id);
        $this->repository()->delete($id);
    }

    /** Abgelaufene Nachrichten löschen (Aufgabe board_cleanup). */
    public function cleanup(): int
    {
        return $this->repository()->deleteExpired($this->app->time()->nowUtc());
    }

    /** @return BoardMessage */
    public function active(int $id): array
    {
        return $this->repository()->findActive($id, $this->app->time()->nowUtc())
            ?? throw ApiError::notFound('Diese Nachricht gibt es nicht mehr (abgelaufen oder gelöscht).');
    }

    /** @return BoardItem */
    private function one(int $id): array
    {
        return $this->format($this->active($id), new UserNames($this->app->users));
    }

    /**
     * @param BoardMessage $m
     * @return BoardItem
     */
    private function format(array $m, UserNames $names): array
    {
        return [
            'kind' => self::KIND_MESSAGE,
            'id' => $m['id'],
            'text' => $m['body'],
            'author_name' => $names->name($m['author_id']),
            'created_at' => Time::utcToIso($m['created_at']),
            'updated_at' => Time::utcToIso($m['updated_at']),
            'expires_at' => Time::utcToIso($m['expires_at']),
        ];
    }

    private static function validText(mixed $text): string
    {
        if (!is_string($text)) {
            throw ApiError::invalid('Bitte einen Text eingeben.');
        }
        $body = Text::multiline($text);
        if ($body === '') {
            throw ApiError::invalid('Bitte einen Text eingeben.');
        }
        if (mb_strlen($body) > self::TEXT_MAX) {
            throw ApiError::invalid(sprintf('Die Nachricht darf höchstens %d Zeichen lang sein.', self::TEXT_MAX));
        }
        return $body;
    }
}
