<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Umfragen (Issue #4). Zeiten in UTC ('Y-m-d H:i:s').
 *
 * - poll_votes: wer teilgenommen hat (für has_voted und die Erinnerung), nie mit der Antwort verknüpft ausgegeben.
 * - poll_answers: Antworten je Teilnahme unter einem Pseudonym (vote_ref); user_id nur bei namentlichen Umfragen.
 *   option_id 0 = Freitext (answer_text), sonst die gewählte Möglichkeit (answer_text leer).
 *
 * @phpstan-type Poll array{id: int, title: string, description: string, type: string, anonymous: bool, results: string, ends_at: string|null, closed_at: string|null, reminded_at: string|null, created_by: int, created_at: string, updated_at: string}
 * @phpstan-type PollOption array{id: int, poll_id: int, title: string, sort: int}
 * @phpstan-type PollAnswer array{poll_id: int, vote_ref: string, user_id: int|null, option_id: int, answer_text: string}
 */
final class PollRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return list<Poll> alle Umfragen, neueste zuerst */
    public function all(): array
    {
        return array_values(array_map(self::poll(...), $this->db->select('SELECT * FROM {polls} ORDER BY created_at DESC, id DESC')));
    }

    /** @return list<Poll> laufende Umfragen (nicht geschlossen, Ende nicht erreicht), neueste zuerst */
    public function running(string $now): array
    {
        return array_values(array_map(self::poll(...), $this->db->select(
            'SELECT * FROM {polls} WHERE closed_at IS NULL AND (ends_at IS NULL OR ends_at > ?) ORDER BY created_at DESC, id DESC',
            [$now],
        )));
    }

    /** @return Poll|null */
    public function find(int $id): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM {polls} WHERE id = ?', [$id]);
        return $row === null ? null : self::poll($row);
    }

    /** @param array<string, mixed> $row */
    public function insert(array $row): int
    {
        return $this->db->insert('polls', $row);
    }

    /** @param array<string, mixed> $values */
    public function update(int $id, array $values): void
    {
        $this->db->update('polls', $values, ['id' => $id]);
    }

    public function delete(int $id): void
    {
        foreach (['poll_answers', 'poll_votes', 'poll_options'] as $table) {
            $this->db->execute('DELETE FROM {' . $table . '} WHERE poll_id = ?', [$id]);
        }
        $this->db->execute('DELETE FROM {polls} WHERE id = ?', [$id]);
    }

    /**
     * Fällige Erinnerungen: laufend, Ende zwischen jetzt und $until, noch nicht erinnert.
     *
     * @return list<Poll>
     */
    public function dueReminders(string $now, string $until): array
    {
        return array_values(array_map(self::poll(...), $this->db->select(
            'SELECT * FROM {polls} WHERE closed_at IS NULL AND reminded_at IS NULL AND ends_at IS NOT NULL AND ends_at > ? AND ends_at <= ? ORDER BY id',
            [$now, $until],
        )));
    }

    /** Erinnerung beanspruchen: true nur für genau einen Lauf, auch wenn mehrere gleichzeitig laufen. */
    public function claimReminder(int $id, string $now): bool
    {
        return $this->db->execute('UPDATE {polls} SET reminded_at = ? WHERE id = ? AND reminded_at IS NULL', [$now, $id]) === 1;
    }

    /* Möglichkeiten */

    /**
     * @param list<int> $pollIds
     * @return array<int, list<PollOption>> je Umfrage, in der Reihenfolge
     */
    public function options(array $pollIds): array
    {
        $out = array_fill_keys($pollIds, []);
        if ($pollIds === []) {
            return $out;
        }
        $rows = $this->db->select(
            'SELECT * FROM {poll_options} WHERE poll_id IN (' . implode(',', array_fill(0, count($pollIds), '?')) . ') ORDER BY sort, id',
            $pollIds,
        );
        foreach ($rows as $r) {
            $out[(int) $r['poll_id']][] = ['id' => (int) $r['id'], 'poll_id' => (int) $r['poll_id'], 'title' => (string) $r['title'], 'sort' => (int) $r['sort']];
        }
        return $out;
    }

    /** @param list<string> $titles */
    public function replaceOptions(int $pollId, array $titles): void
    {
        $this->db->execute('DELETE FROM {poll_options} WHERE poll_id = ?', [$pollId]);
        foreach ($titles as $i => $title) {
            $this->db->insert('poll_options', ['poll_id' => $pollId, 'title' => $title, 'sort' => $i]);
        }
    }

    /* Teilnahmen und Antworten */

    /**
     * @param list<int> $pollIds
     * @return array<int, int> Umfrage => Zahl der Teilnehmenden
     */
    public function voteCounts(array $pollIds): array
    {
        $out = array_fill_keys($pollIds, 0);
        if ($pollIds === []) {
            return $out;
        }
        $rows = $this->db->select(
            'SELECT poll_id, COUNT(*) AS n FROM {poll_votes} WHERE poll_id IN (' . implode(',', array_fill(0, count($pollIds), '?')) . ') GROUP BY poll_id',
            $pollIds,
        );
        foreach ($rows as $r) {
            $out[(int) $r['poll_id']] = (int) $r['n'];
        }
        return $out;
    }

    /** @return list<int> Umfragen, an denen die Person teilgenommen hat */
    public function votedPolls(int $userId): array
    {
        return array_values(array_map(static fn (array $r): int => (int) $r['poll_id'], $this->db->select('SELECT poll_id FROM {poll_votes} WHERE user_id = ?', [$userId])));
    }

    /** @return list<int> Personen, die an der Umfrage teilgenommen haben */
    public function voters(int $pollId): array
    {
        return array_values(array_map(static fn (array $r): int => (int) $r['user_id'], $this->db->select('SELECT user_id FROM {poll_votes} WHERE poll_id = ?', [$pollId])));
    }

    public function hasVotes(int $pollId): bool
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM {poll_votes} WHERE poll_id = ?', [$pollId]) > 0;
    }

    /**
     * @param list<int> $pollIds
     * @return array<int, list<PollAnswer>> je Umfrage
     */
    public function answers(array $pollIds): array
    {
        $out = array_fill_keys($pollIds, []);
        if ($pollIds === []) {
            return $out;
        }
        $rows = $this->db->select(
            'SELECT * FROM {poll_answers} WHERE poll_id IN (' . implode(',', array_fill(0, count($pollIds), '?')) . ')',
            $pollIds,
        );
        foreach ($rows as $r) {
            $out[(int) $r['poll_id']][] = [
                'poll_id' => (int) $r['poll_id'],
                'vote_ref' => (string) $r['vote_ref'],
                'user_id' => $r['user_id'] === null ? null : (int) $r['user_id'],
                'option_id' => (int) $r['option_id'],
                'answer_text' => (string) $r['answer_text'],
            ];
        }
        return $out;
    }

    /**
     * Antwort setzen oder ersetzen.
     *
     * @param list<array{option_id: int, answer_text: string}> $answers
     */
    public function saveVote(int $pollId, int $userId, string $ref, ?int $answerUserId, array $answers, string $now): void
    {
        $this->db->transaction(function () use ($pollId, $userId, $ref, $answerUserId, $answers, $now): void {
            $updated = $this->db->execute('UPDATE {poll_votes} SET updated_at = ? WHERE poll_id = ? AND user_id = ?', [$now, $pollId, $userId]);
            if ($updated === 0 && (int) $this->db->scalar('SELECT COUNT(*) FROM {poll_votes} WHERE poll_id = ? AND user_id = ?', [$pollId, $userId]) === 0) {
                $this->db->insert('poll_votes', ['poll_id' => $pollId, 'user_id' => $userId, 'voted_at' => $now, 'updated_at' => $now]);
            }
            $this->db->execute('DELETE FROM {poll_answers} WHERE poll_id = ? AND vote_ref = ?', [$pollId, $ref]);
            foreach ($answers as $a) {
                $this->db->insert('poll_answers', ['poll_id' => $pollId, 'vote_ref' => $ref, 'user_id' => $answerUserId, 'option_id' => $a['option_id'], 'answer_text' => $a['answer_text']]);
            }
        });
    }

    public function deleteVote(int $pollId, int $userId, string $ref): void
    {
        $this->db->transaction(function () use ($pollId, $userId, $ref): void {
            $this->db->execute('DELETE FROM {poll_answers} WHERE poll_id = ? AND vote_ref = ?', [$pollId, $ref]);
            $this->db->execute('DELETE FROM {poll_votes} WHERE poll_id = ? AND user_id = ?', [$pollId, $userId]);
        });
    }

    /**
     * @param array<string, mixed> $r
     * @return Poll
     */
    private static function poll(array $r): array
    {
        $opt = static fn (mixed $v): ?string => $v === null || $v === '' ? null : (string) $v;
        return [
            'id' => (int) $r['id'],
            'title' => (string) $r['title'],
            'description' => (string) $r['description'],
            'type' => (string) $r['type'],
            'anonymous' => (bool) (int) $r['anonymous'],
            'results' => (string) $r['results'],
            'ends_at' => $opt($r['ends_at']),
            'closed_at' => $opt($r['closed_at']),
            'reminded_at' => $opt($r['reminded_at']),
            'created_by' => (int) $r['created_by'],
            'created_at' => (string) $r['created_at'],
            'updated_at' => (string) $r['updated_at'],
        ];
    }
}
