<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Port\Settings;
use KompanieApp\Core\Storage\PollRepository;

/**
 * Umfragen (Issue #4): eine Frage je Umfrage (Einfachauswahl, Mehrfachauswahl oder Freitext) für alle Mitglieder.
 *
 * - Teilnehmen und sehen: alle mit dem Recht member (Anwärter nicht). Anlegen, ändern, beenden, löschen: manage_polls
 *   (jede und jeder mit dem Recht, nicht nur, wer sie angelegt hat); wer das Recht hat, sieht die Ergebnisse immer.
 * - Beim Anlegen fest: namentlich oder anonym und wer die Ergebnisse sieht (after_vote: wer teilgenommen hat, nach dem
 *   Ende alle; after_end: alle nach dem Ende; managers: nur manage_polls). Nach der ersten Antwort nicht mehr änderbar,
 *   ebenso Art und Antwortmöglichkeiten.
 * - Eine Umfrage endet zum Ende (ends_at, Zeitzone der Kompanie, gespeichert in UTC) oder wenn sie vorzeitig beendet
 *   wird (closed_at); wieder öffnen geht nicht. Bis dahin kann jedes Mitglied seine Antwort ändern oder zurückziehen.
 * - Anonym und trotzdem änderbar: Antworten liegen unter einem Pseudonym (HMAC der Benutzer-ID mit dem Geheimnis der
 *   Installation, Einstellung polls_secret, in Sicherungen enthalten). poll_votes hält nur fest, wer teilgenommen hat.
 *   Ausgegeben werden Antworten nie mit Namen oder IDs; nur die eigene Antwort sieht man selbst. Namen gibt es nur bei
 *   namentlichen Umfragen und nur für die, die die Ergebnisse sehen dürfen.
 * - Laufende Umfragen stehen am Schwarzen Brett (boardItems); Benachrichtigung bei neuen Umfragen und eine Erinnerung
 *   24 Stunden vor dem Ende an alle, die noch nicht teilgenommen haben (Aufgabe poll_reminders, einmal je Umfrage).
 *
 * @phpstan-import-type Poll from PollRepository
 * @phpstan-import-type PollOption from PollRepository
 * @phpstan-import-type PollAnswer from PollRepository
 */
final class PollService
{
    public const TASK = 'poll_reminders';
    public const SECRET_SETTING = 'polls_secret';
    public const KIND_POLL = 'poll';
    public const TYPES = ['single', 'multiple', 'text'];
    public const RESULTS = ['after_vote', 'after_end', 'managers'];
    public const TITLE_MAX = 150;
    public const DESCRIPTION_MAX = 1000;
    public const OPTION_MAX = 100;
    public const OPTIONS_MIN = 2;
    public const OPTIONS_MAX = 20;
    public const TEXT_MAX = 1000;
    /** Erinnerung so lange vor dem Ende */
    public const REMIND_BEFORE = 86400;

    private ?PollRepository $repo = null;

    public function __construct(private readonly App $app)
    {
    }

    public function repository(): PollRepository
    {
        return $this->repo ??= new PollRepository($this->app->db);
    }

    /** Wer an Umfragen teilnimmt, sie sieht und Benachrichtigungen dazu bekommt: alle mit Mitgliederbereich. */
    public static function isParticipant(?User $user): bool
    {
        return $user !== null && $user->isMember();
    }

    public static function canManage(?User $user): bool
    {
        return $user !== null && $user->can(Capability::ManagePolls);
    }

    /* ---------------------------------------------------------------- */
    /* Pseudonym                                                        */
    /* ---------------------------------------------------------------- */

    /**
     * Geheimnis für die Pseudonyme, beim ersten Bedarf erzeugt. Es gehört zu den Daten (nicht Backup::LOCAL_SETTINGS):
     * Wird eine Sicherung auf einer anderen Installation eingespielt, passen die Pseudonyme sonst nicht mehr, und
     * Mitglieder könnten ihre anonymen Antworten weder ändern noch zurückziehen.
     */
    public function secret(): string
    {
        $valid = static fn (mixed $v): ?string => is_string($v) && preg_match('/^[0-9a-f]{64}$/', $v) === 1 ? $v : null;
        $secret = $valid($this->app->settings->get(self::SECRET_SETTING));
        if ($secret !== null) {
            return $secret;
        }
        // Hat eine gleichzeitige Anfrage gerade eins erzeugt, dieses nehmen (sonst passten ihre Pseudonyme nicht).
        $secret = $valid($this->app->settings->fresh(self::SECRET_SETTING)) ?? bin2hex(random_bytes(32));
        $this->app->settings->set(self::SECRET_SETTING, $secret);
        return $secret;
    }

    /** Vorhandenes Geheimnis (ohne eins anzulegen), z. B. beim Löschen eines Kontos. */
    public static function existingSecret(Settings $settings): ?string
    {
        $v = $settings->get(self::SECRET_SETTING);
        return is_string($v) && $v !== '' ? $v : null;
    }

    public static function pseudonym(string $secret, int $userId): string
    {
        return hash_hmac('sha256', 'poll-vote:' . $userId, $secret);
    }

    private function ref(int $userId): string
    {
        return self::pseudonym($this->secret(), $userId);
    }

    /* ---------------------------------------------------------------- */
    /* Lesen                                                            */
    /* ---------------------------------------------------------------- */

    /** @return list<array<string, mixed>> alle Umfragen (laufende und beendete), neueste zuerst */
    public function list(User $user): array
    {
        return $this->formatAll($this->repository()->all(), $user);
    }

    /** @return array<string, mixed> */
    public function get(User $user, int $id): array
    {
        return $this->formatAll([$this->find($id)], $user)[0];
    }

    /**
     * Laufende Umfragen als Einträge fürs Schwarze Brett (nur für Mitglieder, nur mit eingeschaltetem Bereich).
     *
     * @return list<array{kind: string, id: int, title: string, type: string, ends_at: string|null, expires_at: string|null, has_voted: bool, created_at: string|null, updated_at: string|null}>
     */
    public function boardItems(?User $user): array
    {
        if (!self::isParticipant($user) || !$this->app->features()->enabled('polls') || $user === null) {
            return [];
        }
        $voted = array_flip($this->repository()->votedPolls($user->id));
        return array_map(static fn (array $p): array => [
            'kind' => self::KIND_POLL,
            'id' => $p['id'],
            'title' => $p['title'],
            'type' => $p['type'],
            'ends_at' => Time::utcToIso($p['ends_at']),
            'expires_at' => Time::utcToIso($p['ends_at']),
            'has_voted' => isset($voted[$p['id']]),
            'created_at' => Time::utcToIso($p['created_at']),
            'updated_at' => Time::utcToIso($p['updated_at']),
        ], $this->repository()->running($this->app->time()->nowUtc()));
    }

    /* ---------------------------------------------------------------- */
    /* Verwalten                                                        */
    /* ---------------------------------------------------------------- */

    /**
     * @param array<string, mixed> $in title, description, type, options, anonymous, results, ends_at
     * @return array<string, mixed>
     */
    public function create(User $author, array $in): array
    {
        $type = self::validType($in['type'] ?? null);
        $title = self::validTitle($in['title'] ?? null);
        $description = self::validDescription($in['description'] ?? null);
        $options = $type === 'text' ? [] : self::validOptions($in['options'] ?? null);
        $anonymous = self::validBool($in['anonymous'] ?? false, 'anonymous');
        $results = self::validResults($in['results'] ?? 'after_vote');
        $endsAt = $this->validEnd($in['ends_at'] ?? null);
        $now = $this->app->time()->nowUtc();
        $id = $this->app->db->transaction(function () use ($title, $description, $type, $anonymous, $results, $endsAt, $author, $now, $options): int {
            $id = $this->repository()->insert([
                'title' => $title,
                'description' => $description,
                'type' => $type,
                'anonymous' => $anonymous ? 1 : 0,
                'results' => $results,
                'ends_at' => $endsAt,
                'closed_at' => null,
                // Endet sie in weniger als 24 Stunden, reicht die Benachrichtigung über die neue Umfrage.
                'reminded_at' => $this->remindNow($endsAt) ? $now : null,
                'created_by' => $author->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->repository()->replaceOptions($id, $options);
            return $id;
        });
        $this->secret(); // gleich anlegen, nicht erst bei der ersten (womöglich gleichzeitigen) Antwort
        return $this->get($author, $id);
    }

    /**
     * Ändern, solange die Umfrage läuft. Titel, Beschreibung und Ende immer; Art, Möglichkeiten, Anonymität und
     * Sichtbarkeit nur bis zur ersten Antwort (danach → 400, außer der Wert bleibt gleich).
     *
     * @param array<string, mixed> $in
     * @return array<string, mixed>
     */
    public function update(User $user, int $id, array $in): array
    {
        $poll = $this->find($id);
        if ($this->ended($poll)) {
            throw ApiError::invalid('Die Umfrage ist beendet und lässt sich nicht mehr ändern.');
        }
        $locked = $this->repository()->hasVotes($id);
        $values = [];
        if (array_key_exists('title', $in)) {
            $values['title'] = self::validTitle($in['title']);
        }
        if (array_key_exists('description', $in)) {
            $values['description'] = self::validDescription($in['description']);
        }
        if (array_key_exists('ends_at', $in)) {
            $values['ends_at'] = $this->validEnd($in['ends_at']);
        }
        $lockedMessage = 'Es gibt schon Antworten: Art, Antwortmöglichkeiten, Anonymität und Sichtbarkeit der Ergebnisse lassen sich nicht mehr ändern.';
        if (array_key_exists('anonymous', $in)) {
            $values['anonymous'] = self::validBool($in['anonymous'], 'anonymous') ? 1 : 0;
        }
        if (array_key_exists('results', $in)) {
            $values['results'] = self::validResults($in['results']);
        }
        $type = $poll['type'];
        if (array_key_exists('type', $in)) {
            $values['type'] = $type = self::validType($in['type']);
        }
        $current = array_column($this->repository()->options([$id])[$id], 'title');
        $options = null;
        if (array_key_exists('options', $in) || $type !== $poll['type']) {
            $options = $type === 'text' ? [] : self::validOptions($in['options'] ?? $current);
        }
        if ($locked && (
            (isset($values['anonymous']) && (bool) $values['anonymous'] !== $poll['anonymous'])
            || (isset($values['results']) && $values['results'] !== $poll['results'])
            || $type !== $poll['type']
            || ($options !== null && $options !== $current)
        )) {
            throw ApiError::invalid($lockedMessage);
        }
        $changed = array_filter($values, static fn (mixed $v, string $k): bool => $v !== match ($k) {
            'anonymous' => $poll['anonymous'] ? 1 : 0,
            default => $poll[$k],
        }, ARRAY_FILTER_USE_BOTH);
        $optionsChanged = $options !== null && $options !== $current;
        if ($changed !== [] || $optionsChanged) {
            $now = $this->app->time()->nowUtc();
            $changed['updated_at'] = $now;
            if (array_key_exists('ends_at', $changed)) {
                // Neues Ende: Erinnerung neu, wenn es noch mehr als 24 Stunden hin ist.
                $changed['reminded_at'] = $this->remindNow($changed['ends_at']) ? $poll['reminded_at'] : null;
            }
            $this->app->db->transaction(function () use ($id, $changed, $optionsChanged, $options): void {
                $this->repository()->update($id, $changed);
                if ($optionsChanged && $options !== null) {
                    $this->repository()->replaceOptions($id, $options);
                }
            });
        }
        return $this->get($user, $id);
    }

    /** @return array<string, mixed> */
    public function close(User $user, int $id): array
    {
        $poll = $this->find($id);
        if ($this->ended($poll)) {
            throw ApiError::invalid('Die Umfrage ist schon beendet.');
        }
        $now = $this->app->time()->nowUtc();
        $this->repository()->update($id, ['closed_at' => $now, 'updated_at' => $now]);
        return $this->get($user, $id);
    }

    /** Löschen mit allen Antworten. */
    public function delete(int $id): void
    {
        $this->find($id);
        $this->app->db->transaction(fn () => $this->repository()->delete($id));
    }

    /* ---------------------------------------------------------------- */
    /* Teilnehmen                                                       */
    /* ---------------------------------------------------------------- */

    /**
     * Antwort abgeben oder ändern: option_ids (Liste) bei Einfach- und Mehrfachauswahl, text bei Freitext.
     *
     * @param array<string, mixed> $in
     * @return array<string, mixed>
     */
    public function vote(User $user, int $id, array $in): array
    {
        $poll = $this->running($id);
        $answers = [];
        if ($poll['type'] === 'text') {
            $text = $in['text'] ?? null;
            $text = is_string($text) ? Text::multiline($text) : '';
            if ($text === '') {
                throw ApiError::invalid('Bitte eine Antwort eingeben.');
            }
            if (mb_strlen($text) > self::TEXT_MAX) {
                throw ApiError::invalid('Die Antwort ist zu lang (höchstens ' . self::TEXT_MAX . ' Zeichen).');
            }
            $answers[] = ['option_id' => 0, 'answer_text' => $text];
        } else {
            $raw = $in['option_ids'] ?? null;
            if (!is_array($raw) || $raw === []) {
                throw ApiError::invalid($poll['type'] === 'single' ? 'Bitte eine Antwort auswählen.' : 'Bitte mindestens eine Antwort auswählen.');
            }
            $valid = array_column($this->repository()->options([$id])[$id], 'id');
            $chosen = [];
            foreach ($raw as $value) {
                $optionId = Input::integer($value);
                if ($optionId === null || !in_array($optionId, $valid, true)) {
                    throw ApiError::invalid('Diese Antwortmöglichkeit gehört nicht zu der Umfrage.');
                }
                $chosen[$optionId] = true;
            }
            if ($poll['type'] === 'single' && count($chosen) !== 1) {
                throw ApiError::invalid('Bitte genau eine Antwort auswählen.');
            }
            foreach (array_keys($chosen) as $optionId) {
                $answers[] = ['option_id' => $optionId, 'answer_text' => ''];
            }
        }
        $this->repository()->saveVote($id, $user->id, $this->ref($user->id), $poll['anonymous'] ? null : $user->id, $answers, $this->app->time()->nowUtc());
        return $this->get($user, $id);
    }

    /** @return array<string, mixed> */
    public function withdraw(User $user, int $id): array
    {
        $this->running($id);
        $this->repository()->deleteVote($id, $user->id, $this->ref($user->id));
        return $this->get($user, $id);
    }

    /* ---------------------------------------------------------------- */
    /* Erinnerung                                                       */
    /* ---------------------------------------------------------------- */

    /**
     * Aufgabe poll_reminders (stündlich): Umfragen, die in höchstens 24 Stunden enden, einmal erinnern – an alle
     * Mitglieder, die noch nicht teilgenommen haben. reminded_at wird vor dem Versand gesetzt (nur ein Lauf gewinnt),
     * damit wiederholte oder gleichzeitige Läufe nicht doppelt erinnern.
     *
     * @return int Zahl der eingereihten Benachrichtigungen
     */
    public function remind(): int
    {
        $ts = $this->app->time()->timestamp();
        $now = gmdate('Y-m-d H:i:s', $ts);
        $count = 0;
        foreach ($this->repository()->dueReminders($now, gmdate('Y-m-d H:i:s', $ts + self::REMIND_BEFORE)) as $poll) {
            if (!$this->repository()->claimReminder($poll['id'], $now)) {
                continue;
            }
            $count += $this->app->pushTriggers()->pollReminder($this->boardShape($poll), $this->repository()->voters($poll['id']));
        }
        return $count;
    }

    /**
     * Kurzform für Benachrichtigungen.
     *
     * @param Poll $poll
     * @return array{id: int, title: string, ends_at: string|null}
     */
    private function boardShape(array $poll): array
    {
        return ['id' => $poll['id'], 'title' => $poll['title'], 'ends_at' => Time::utcToIso($poll['ends_at'])];
    }

    /* ---------------------------------------------------------------- */
    /* Ausgabe                                                          */
    /* ---------------------------------------------------------------- */

    /**
     * @param list<Poll> $polls
     * @return list<array<string, mixed>>
     */
    private function formatAll(array $polls, User $user): array
    {
        $ids = array_column($polls, 'id');
        $options = $this->repository()->options($ids);
        $counts = $this->repository()->voteCounts($ids);
        $answers = $this->repository()->answers($ids);
        $voted = array_flip($this->repository()->votedPolls($user->id));
        $ref = $this->ref($user->id);
        // Alle Namen (Ersteller, Teilnehmende namentlicher Umfragen) mit einer Abfrage; ausgegeben wird nur, was sichtbar ist.
        $people = array_column($polls, 'created_by');
        foreach ($polls as $p) {
            if (!$p['anonymous']) {
                array_push($people, ...array_column($answers[$p['id']] ?? [], 'user_id'));
            }
        }
        $names = (new UserNames($this->app->users))->prime($people);
        $manager = self::canManage($user);
        $out = [];
        foreach ($polls as $p) {
            $id = $p['id'];
            $ended = $this->ended($p);
            $hasVoted = isset($voted[$id]);
            $visible = $manager || match ($p['results']) {
                'after_vote' => $hasVoted || $ended,
                'after_end' => $ended,
                default => false,
            };
            $own = array_values(array_filter($answers[$id], static fn (array $a): bool => $a['vote_ref'] === $ref));
            $out[] = [
                'id' => $id,
                'title' => $p['title'],
                'description' => $p['description'],
                'type' => $p['type'],
                'anonymous' => $p['anonymous'],
                'results' => $p['results'],
                'status' => $ended ? 'ended' : 'running',
                'ends_at' => Time::utcToIso($p['ends_at']),
                'closed_at' => Time::utcToIso($p['closed_at']),
                'created_at' => Time::utcToIso($p['created_at']),
                'updated_at' => Time::utcToIso($p['updated_at']),
                'created_by_name' => $names->name($p['created_by']),
                'options' => array_map(static fn (array $o): array => ['id' => $o['id'], 'title' => $o['title']], $options[$id]),
                // Art, Möglichkeiten, Anonymität und Sichtbarkeit sind fest, sobald es Antworten gibt.
                'locked' => $counts[$id] > 0,
                'has_voted' => $hasVoted,
                'my_answer' => $hasVoted ? [
                    'option_ids' => array_values(array_filter(array_column($own, 'option_id'))),
                    'text' => $p['type'] === 'text' ? ($own[0]['answer_text'] ?? '') : null,
                ] : null,
                'results_visible' => $visible,
                'results_data' => $visible ? $this->results($p, $options[$id], $answers[$id], $counts[$id], $names) : null,
            ];
        }
        return $out;
    }

    /**
     * Ergebnisse: Anzahl und Prozent je Möglichkeit (Prozent der Teilnehmenden; bei Mehrfachauswahl zusammen mehr als
     * 100), Freitexte als Liste. Namen nur bei namentlichen Umfragen; bei anonymen weder Namen noch IDs, Freitexte
     * alphabetisch (nicht in der Reihenfolge der Abgabe).
     *
     * @param Poll $p
     * @param list<PollOption> $options
     * @param list<PollAnswer> $answers
     * @return array<string, mixed>
     */
    private function results(array $p, array $options, array $answers, int $voters, UserNames $names): array
    {
        $named = !$p['anonymous'];
        if ($p['type'] === 'text') {
            $texts = [];
            foreach ($answers as $a) {
                if ($a['option_id'] === 0) {
                    $texts[] = $named ? ['text' => $a['answer_text'], 'name' => $names->name((int) $a['user_id']) ?? UserNames::DELETED] : ['text' => $a['answer_text']];
                }
            }
            usort($texts, static fn (array $x, array $y): int => $named
                ? [mb_strtolower((string) ($x['name'] ?? '')), $x['text']] <=> [mb_strtolower((string) ($y['name'] ?? '')), $y['text']]
                : [mb_strtolower($x['text']), $x['text']] <=> [mb_strtolower($y['text']), $y['text']]);
            return ['voters' => $voters, 'texts' => $texts];
        }
        $rows = [];
        foreach ($options as $o) {
            $who = array_values(array_filter($answers, static fn (array $a): bool => $a['option_id'] === $o['id']));
            $row = [
                'id' => $o['id'],
                'title' => $o['title'],
                'count' => count($who),
                'percent' => $voters > 0 ? (int) round(count($who) * 100 / $voters) : 0,
            ];
            if ($named) {
                $list = array_map(static fn (array $a): string => $names->name((int) $a['user_id']) ?? UserNames::DELETED, $who);
                usort($list, static fn (string $x, string $y): int => strcasecmp($x, $y));
                $row['names'] = $list;
            }
            $rows[] = $row;
        }
        return ['voters' => $voters, 'options' => $rows];
    }

    /* ---------------------------------------------------------------- */
    /* Hilfen                                                           */
    /* ---------------------------------------------------------------- */

    /** @return Poll */
    private function find(int $id): array
    {
        return $this->repository()->find($id) ?? throw ApiError::notFound('Diese Umfrage gibt es nicht (mehr).');
    }

    /** @return Poll */
    private function running(int $id): array
    {
        $poll = $this->find($id);
        if ($this->ended($poll)) {
            throw ApiError::invalid('Die Umfrage ist beendet. Antworten lassen sich nicht mehr abgeben, ändern oder zurückziehen.');
        }
        return $poll;
    }

    /** @param Poll $poll */
    public function ended(array $poll): bool
    {
        return $poll['closed_at'] !== null || ($poll['ends_at'] !== null && $poll['ends_at'] <= $this->app->time()->nowUtc());
    }

    /** Endet die Umfrage in höchstens 24 Stunden (dann gleich als erinnert vermerken bzw. Erinnerung nicht neu)? */
    private function remindNow(?string $endsAt): bool
    {
        return $endsAt !== null && (int) strtotime($endsAt . ' UTC') <= $this->app->time()->timestamp() + self::REMIND_BEFORE;
    }

    private static function validType(mixed $type): string
    {
        if (!is_string($type) || !in_array($type, self::TYPES, true)) {
            throw ApiError::invalid('Bitte die Art der Umfrage wählen (Einfachauswahl, Mehrfachauswahl oder Freitext).');
        }
        return $type;
    }

    private static function validResults(mixed $results): string
    {
        if (!is_string($results) || !in_array($results, self::RESULTS, true)) {
            throw ApiError::invalid('Bitte wählen, wer die Ergebnisse sieht.');
        }
        return $results;
    }

    private static function validBool(mixed $value, string $field): bool
    {
        if (!is_bool($value)) {
            throw ApiError::invalid('Ungültiger Wert für ' . $field . ' (ja oder nein erwartet).');
        }
        return $value;
    }

    private static function validTitle(mixed $title): string
    {
        $t = is_string($title) ? Text::line($title) : '';
        if ($t === '') {
            throw ApiError::invalid('Bitte eine Frage eingeben.');
        }
        if (mb_strlen($t) > self::TITLE_MAX) {
            throw ApiError::invalid('Die Frage ist zu lang (höchstens ' . self::TITLE_MAX . ' Zeichen).');
        }
        return $t;
    }

    private static function validDescription(mixed $description): string
    {
        if ($description === null) {
            return '';
        }
        if (!is_string($description)) {
            throw ApiError::invalid('Ungültige Beschreibung.');
        }
        $d = Text::multiline($description);
        if (mb_strlen($d) > self::DESCRIPTION_MAX) {
            throw ApiError::invalid('Die Beschreibung ist zu lang (höchstens ' . self::DESCRIPTION_MAX . ' Zeichen).');
        }
        return $d;
    }

    /** @return list<string> */
    private static function validOptions(mixed $options): array
    {
        if (!is_array($options)) {
            throw ApiError::invalid('Bitte mindestens ' . self::OPTIONS_MIN . ' Antwortmöglichkeiten eingeben.');
        }
        $out = [];
        $seen = [];
        foreach ($options as $o) {
            $t = is_string($o) ? Text::line($o) : '';
            if ($t === '') {
                continue; // leere Zeilen im Formular überspringen
            }
            if (mb_strlen($t) > self::OPTION_MAX) {
                throw ApiError::invalid('Eine Antwortmöglichkeit ist zu lang (höchstens ' . self::OPTION_MAX . ' Zeichen).');
            }
            $key = mb_strtolower($t);
            if (isset($seen[$key])) {
                throw ApiError::invalid('Die Antwortmöglichkeit „' . $t . '“ steht doppelt da.');
            }
            $seen[$key] = true;
            $out[] = $t;
        }
        if (count($out) < self::OPTIONS_MIN) {
            throw ApiError::invalid('Bitte mindestens ' . self::OPTIONS_MIN . ' Antwortmöglichkeiten eingeben.');
        }
        if (count($out) > self::OPTIONS_MAX) {
            throw ApiError::invalid('Höchstens ' . self::OPTIONS_MAX . ' Antwortmöglichkeiten.');
        }
        return $out;
    }

    /**
     * Ende: leer = offen (bis zum vorzeitigen Beenden); „JJJJ-MM-TTTHH:MM“ (auch mit Sekunden oder Leerzeichen) in der
     * Zeitzone der Kompanie oder ISO 8601 mit Zeitzone. Muss in der Zukunft liegen. Gespeichert in UTC.
     */
    private function validEnd(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw ApiError::invalid('Ungültiges Ende.');
        }
        $utc = $this->app->time()->parseToUtc($value);
        if ($utc === null) {
            throw ApiError::invalid('Ungültiges Ende (Datum und Uhrzeit erwartet).');
        }
        if ($utc <= $this->app->time()->nowUtc()) {
            throw ApiError::invalid('Das Ende muss in der Zukunft liegen.');
        }
        return $utc;
    }
}
