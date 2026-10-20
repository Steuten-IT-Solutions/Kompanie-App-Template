<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Storage\RankRepository;

/**
 * Dienstgrade (Issue #9, Vorbild Lank #16).
 *
 * Eine Liste mit einer Reihenfolge (sort, der niedrigste zuerst). Den Dienstgrad eines Mitglieds legt fest, wer
 * „Dienstgrade verwalten“ darf; er ändert sich nie von selbst.
 *
 * - Ohne den Bereich Punkte: frei befördern und degradieren, mit optionaler Begründung und Verlauf.
 * - Mit Punkten (Features::ranksUsePoints): Punkteschwellen. Befördern nur, wenn die Schwelle des Ziels erreicht ist;
 *   degradieren nur, wenn die Punkte unter der Schwelle des aktuellen Dienstgrads liegen. Das gilt für alle, auch
 *   Admins. Dienstgrade ohne Schwelle sind frei (bis eine eingetragen ist). Dazu „noch N Punkte bis …“,
 *   „Schwelle erreicht seit“ und die Beförderungsübersicht.
 *
 * @phpstan-import-type Rank from RankRepository
 * @phpstan-import-type HistoryEntry from RankRepository
 */
final class RankService
{
    public const TITLE_MAX = 60;
    public const MAX_THRESHOLD = 1000000;
    public const KIND_PROMOTION = 'promotion';
    public const KIND_DEMOTION = 'demotion';
    public const KIND_INITIAL = 'initial';

    private ?RankRepository $repo = null;

    public function __construct(private readonly App $app)
    {
    }

    public function repository(): RankRepository
    {
        return $this->repo ??= new RankRepository($this->app->db);
    }

    public function usesPoints(): bool
    {
        return $this->app->features()->ranksUsePoints();
    }

    /* ---------------------------------------------------------------- */
    /* Liste                                                             */
    /* ---------------------------------------------------------------- */

    /**
     * Für die App. Ohne Punkte keine Schwellen; mit $all auch inaktive und wie oft vergeben (Pflege).
     *
     * @return list<array<string, mixed>>
     */
    public function list(bool $all): array
    {
        $points = $this->usesPoints();
        return array_map(fn (array $r): array => [
            'id' => $r['id'],
            'title' => $r['title'],
            'sort' => $r['sort'],
            'min_points' => $points || $all ? $r['min_points'] : null,
            'active' => $r['active'],
        ] + ($all ? ['assigned' => $this->repository()->assignedCount($r['id'])] : []), $this->repository()->all(!$all));
    }

    /**
     * @param array<string, mixed> $in title, min_points
     * @return array<string, mixed>
     */
    public function create(array $in): array
    {
        $repo = $this->repository();
        $title = $this->validTitle($in['title'] ?? null, null);
        $min = self::validThreshold($in['min_points'] ?? null);
        $sort = $repo->maxSort() + 10;
        $this->checkThresholds($repo->all(false), ['title' => $title, 'sort' => $sort, 'min_points' => $min, 'active' => true]);
        return $this->format((array) $repo->find($repo->insert($title, $sort, $min)));
    }

    /**
     * @param array<string, mixed> $in title, min_points, active
     * @return array<string, mixed>
     */
    public function update(int $id, array $in): array
    {
        $repo = $this->repository();
        $rank = $repo->find($id) ?? throw ApiError::notFound(sprintf('%s nicht gefunden.', $this->app->config->rankLabel()));
        $data = [];
        if (array_key_exists('title', $in)) {
            $data['title'] = $this->validTitle($in['title'], $id);
        }
        if (array_key_exists('min_points', $in)) {
            $data['min_points'] = self::validThreshold($in['min_points']);
        }
        if (array_key_exists('active', $in)) {
            if (!is_bool($in['active'])) {
                throw ApiError::invalid('Ungültiger Wert für „aktiv“.');
            }
            $data['active'] = $in['active'];
        }
        $next = array_merge($rank, $data);
        $others = array_values(array_filter($repo->all(false), static fn (array $r): bool => $r['id'] !== $id));
        $this->checkThresholds($others, $next);
        $repo->update($id, $data);
        return $this->format((array) $repo->find($id));
    }

    /** Vergebene Dienstgrade lassen sich nur deaktivieren, nicht löschen. */
    public function delete(int $id): void
    {
        $repo = $this->repository();
        $repo->find($id) ?? throw ApiError::notFound(sprintf('%s nicht gefunden.', $this->app->config->rankLabel()));
        if ($repo->assignedCount($id) > 0) {
            throw ApiError::invalid(sprintf('Dieser %s ist vergeben und lässt sich nur deaktivieren.', $this->app->config->rankLabel()));
        }
        $repo->delete($id);
    }

    /**
     * Neue Reihenfolge: alle IDs vom niedrigsten zum höchsten.
     *
     * @return list<array<string, mixed>>
     */
    public function reorder(mixed $ids): array
    {
        $repo = $this->repository();
        $ranks = [];
        foreach ($repo->all(false) as $r) {
            $ranks[$r['id']] = $r;
        }
        $order = is_array($ids) ? array_map(static fn (mixed $v): ?int => Input::integer($v), array_values($ids)) : [];
        $given = $order;
        sort($given);
        $known = array_keys($ranks);
        sort($known);
        if ($given !== $known) {
            throw ApiError::invalid('Bitte die Reihenfolge aller Einträge angeben.');
        }
        $next = [];
        foreach ($order as $i => $id) {
            $next[] = ['sort' => ($i + 1) * 10] + $ranks[(int) $id];
        }
        $this->checkThresholds($next, null);
        foreach ($next as $r) {
            if ($r['sort'] !== $ranks[$r['id']]['sort']) {
                $repo->update($r['id'], ['sort' => $r['sort']]);
            }
        }
        return $this->list(true);
    }

    /* ---------------------------------------------------------------- */
    /* Stand eines Mitglieds                                             */
    /* ---------------------------------------------------------------- */

    /**
     * Dienstgrad eines Mitglieds; mit Punkten zusätzlich nächster Dienstgrad, fehlende Punkte und Schwellen.
     *
     * @return array<string, mixed>
     */
    public function status(int $userId): array
    {
        $ranks = $this->repository()->all(false);
        $current = $this->byId($ranks, $this->repository()->rankOf($userId));
        $out = ['rank' => $current === null ? null : ['id' => $current['id'], 'title' => $current['title']]];
        if (!$this->usesPoints()) {
            return $out;
        }
        $entries = $this->repository()->pointEntries($userId);
        return $out + $this->progress($ranks, $current, $entries);
    }

    /** @return array<int, string> Benutzer-ID => Bezeichnung des Dienstgrads (für die Mitgliederliste) */
    public function titles(): array
    {
        $titles = array_column($this->repository()->all(false), 'title', 'id');
        $out = [];
        foreach ($this->repository()->assignments() as $userId => $rankId) {
            if (isset($titles[$rankId])) {
                $out[$userId] = $titles[$rankId];
            }
        }
        return $out;
    }

    /** @return list<array<string, mixed>> neueste zuerst */
    public function history(int $userId): array
    {
        $names = new UserNames($this->app->users);
        return array_map(fn (array $h): array => [
            'from' => $h['from_title'] !== '' ? $h['from_title'] : null,
            'to' => $h['to_title'] !== '' ? $h['to_title'] : null,
            'kind' => $h['kind'],
            'points_at_change' => $h['points_at_change'] === null ? null : ScoreService::pointsValue($h['points_at_change']),
            'reason' => $h['reason'],
            'changed_by_name' => $names->name($h['changed_by']),
            'changed_at' => $h['changed_at'],
        ], $this->repository()->history($userId));
    }

    /**
     * Beförderungsübersicht (nur mit Punkten): erreichte Schwellen zuerst (die ältesten oben), dann nach fehlenden
     * Punkten; ohne nächsten Dienstgrad bzw. ohne Schwelle am Ende.
     *
     * @return list<array<string, mixed>>
     */
    public function promotions(): array
    {
        if (!$this->usesPoints()) {
            throw new ApiError(403, 'feature_disabled', sprintf('Die Beförderungsübersicht gibt es nur zusammen mit dem Bereich %s.', $this->app->config->sectionLabel('points', 'Punkte')));
        }
        $repo = $this->repository();
        $ranks = $repo->all(false);
        $assigned = $repo->assignments();
        $byUser = [];
        foreach ($repo->pointEntries() as $e) {
            $byUser[$e['user_id']][] = $e;
        }
        $out = [];
        foreach ($this->app->users->list(false) as $u) {
            if (!$u->isMember()) {
                continue;
            }
            $current = $this->byId($ranks, $assigned[$u->id] ?? null);
            $out[] = ['user_id' => $u->id, 'name' => $u->name, 'rank' => $current === null ? null : ['id' => $current['id'], 'title' => $current['title']]]
                + $this->progress($ranks, $current, $byUser[$u->id] ?? []);
        }
        usort($out, static function (array $a, array $b): int {
            $group = static fn (array $r): int => $r['points_to_next'] === null ? 2 : ($r['points_to_next'] <= 0 ? 0 : 1);
            return [$group($a), $group($a) === 0 ? (string) $a['threshold_reached_since'] : $a['points_to_next'], $a['name']]
                <=> [$group($b), $group($b) === 0 ? (string) $b['threshold_reached_since'] : $b['points_to_next'], $b['name']];
        });
        return $out;
    }

    /* ---------------------------------------------------------------- */
    /* Befördern und degradieren                                         */
    /* ---------------------------------------------------------------- */

    /**
     * Dienstgrad setzen (null = keiner) nach den Regeln; schreibt den Verlauf.
     *
     * @return array<string, mixed> neuer Stand wie status()
     */
    public function change(User $actor, int $userId, mixed $rankId, mixed $reason): array
    {
        $user = $this->app->users->find($userId) ?? throw ApiError::notFound('Mitglied nicht gefunden.');
        if (!$user->isMember()) {
            throw ApiError::invalid(sprintf('Einen %s haben nur Mitglieder.', $this->app->config->rankLabel()));
        }
        $repo = $this->repository();
        $ranks = $repo->all(false);
        $current = $this->byId($ranks, $repo->rankOf($userId));
        $target = $this->target($ranks, $rankId);
        if (($current['id'] ?? null) === ($target['id'] ?? null)) {
            throw ApiError::invalid(sprintf('Das ist schon der %s dieser Person.', $this->app->config->rankLabel()));
        }
        $total = $this->usesPoints() ? $this->app->scores()->pointsTotal($userId) : null;
        $kind = $this->checkChange($ranks, $current, $target, $total === null ? null : (float) $total);
        $entry = [
            'from_rank_id' => $current['id'] ?? null,
            'to_rank_id' => $target['id'] ?? null,
            'from_title' => $current['title'] ?? '',
            'to_title' => $target['title'] ?? '',
            'kind' => $kind,
            'points_at_change' => $total === null ? null : (float) $total,
            'reason' => Text::line($reason ?? '', 255),
            'changed_by' => $actor->id,
        ];
        $repo->change($userId, $entry, $this->app->time()->nowUtc());
        try {
            $this->app->pushTriggers()->rankChanged($userId, $entry);
        } catch (\Throwable $e) {
            error_log('Kompanie-App: Benachrichtigung zum Dienstgrad nicht eingereiht: ' . $e->getMessage());
        }
        return $this->status($userId);
    }

    /**
     * Anlegen mit Anfangspunkten und Dienstgrad (Lank #16): vor dem Anlegen des Kontos prüfen.
     *
     * @param array<string, mixed> $in initial_points, initial_points_date, rank_id
     * @return array{points: int, date: string, rank: Rank|null}
     */
    public function prepareInitial(User $actor, array $in): array
    {
        $raw = $in['initial_points'] ?? null;
        $points = $raw === null || $raw === '' ? 0 : (Input::integer($raw) ?? -1);
        if ($points < 0 || $points > ScoreService::MAX_POINTS) {
            throw ApiError::invalid('Anfangspunkte: bitte eine ganze Zahl ab 0 angeben.');
        }
        if ($points > 0 && (!$this->app->features()->enabled('points') || !$actor->can(Capability::ManagePoints))) {
            throw ApiError::forbidden(sprintf('Anfangspunkte bucht, wer %s verwalten darf.', $this->app->config->sectionLabel('points', 'Punkte')));
        }
        $date = Input::date($in['initial_points_date'] ?? null, $this->app->time()->today());
        $rank = null;
        if (($in['rank_id'] ?? null) !== null && $in['rank_id'] !== '') {
            if (!$this->app->features()->enabled('ranks') || !$actor->can(Capability::ManageRanks)) {
                throw ApiError::forbidden(sprintf('Den %s vergibt, wer %s verwalten darf.', $this->app->config->rankLabel(), $this->app->config->sectionLabel('ranks', 'Dienstgrade')));
            }
            $rank = $this->target($this->repository()->all(false), $in['rank_id']);
            if ($rank !== null && $this->usesPoints() && $rank['min_points'] !== null && $points < $rank['min_points']) {
                throw ApiError::invalid(sprintf('Für „%s“ sind mindestens %d Punkte nötig.', $rank['title'], $rank['min_points']));
            }
        }
        return ['points' => $points, 'date' => $date, 'rank' => $rank];
    }

    /** @param array{points: int, date: string, rank: Rank|null} $plan */
    public function applyInitial(User $actor, int $userId, array $plan): void
    {
        if ($plan['points'] > 0) {
            $this->app->scores()->givePoints(['user_ids' => [$userId], 'points' => $plan['points'], 'reason' => 'Anfangsbestand bei Übernahme', 'date' => $plan['date'], 'apply_factor' => false], $actor->id);
        }
        if ($plan['rank'] !== null) {
            $this->repository()->change($userId, [
                'from_rank_id' => null,
                'to_rank_id' => $plan['rank']['id'],
                'from_title' => '',
                'to_title' => $plan['rank']['title'],
                'kind' => self::KIND_INITIAL,
                'points_at_change' => $this->usesPoints() ? (float) $plan['points'] : null,
                'reason' => 'Ersteinstufung bei Anlage',
                'changed_by' => $actor->id,
            ], $this->app->time()->nowUtc());
        }
    }

    /* ---------------------------------------------------------------- */
    /* Regeln                                                            */
    /* ---------------------------------------------------------------- */

    /**
     * @param list<Rank> $ranks
     * @param Rank|null $current
     * @param Rank|null $target
     * @return string promotion | demotion
     */
    private function checkChange(array $ranks, ?array $current, ?array $target, ?float $total): string
    {
        $promotion = $target !== null && ($current === null || $this->position($ranks, $target) > $this->position($ranks, $current));
        if ($total === null) {
            return $promotion ? self::KIND_PROMOTION : self::KIND_DEMOTION;
        }
        if ($promotion && $target['min_points'] !== null && $total < $target['min_points']) {
            throw ApiError::invalid(sprintf(
                'Schwelle für „%s“ noch nicht erreicht (fehlen %s Punkte).',
                $target['title'],
                self::number($target['min_points'] - $total),
            ));
        }
        if (!$promotion && $current !== null && $current['min_points'] !== null && $total >= $current['min_points']) {
            throw ApiError::invalid(sprintf('Degradieren ist nur möglich, wenn die Punkte unter der Schwelle von „%s“ liegen.', $current['title']));
        }
        return $promotion ? self::KIND_PROMOTION : self::KIND_DEMOTION;
    }

    /**
     * Nächster Dienstgrad, fehlende Punkte, erreichbarer Dienstgrad, „Schwelle erreicht seit“ (Lank #16).
     *
     * @param list<Rank> $ranks
     * @param Rank|null $current
     * @param list<array{user_id: int, entry_date: string, value: float}> $entries Buchungen des Mitglieds in Zählreihenfolge
     * @return array<string, mixed>
     */
    private function progress(array $ranks, ?array $current, array $entries): array
    {
        $total = round(array_sum(array_column($entries, 'value')), 2);
        $next = null;
        $reachable = null;
        foreach ($ranks as $r) {
            if (!$r['active']) {
                continue;
            }
            if ($next === null && ($current === null || $this->position($ranks, $r) > $this->position($ranks, $current))) {
                $next = $r;
            }
            if ($r['min_points'] !== null && $r['min_points'] <= $total) {
                $reachable = $r;
            }
        }
        $threshold = $next['min_points'] ?? null;
        $since = null;
        if ($threshold !== null) {
            $sum = 0.0;
            foreach ($entries as $e) {
                $sum = round($sum + $e['value'], 2);
                if ($sum >= $threshold) {
                    $since ??= $e['entry_date'];
                } else {
                    $since = null;
                }
            }
        }
        return [
            'points' => ScoreService::pointsValue($total),
            'next_rank' => $next === null ? null : ['id' => $next['id'], 'title' => $next['title'], 'min_points' => $next['min_points']],
            'points_to_next' => $threshold === null ? null : ScoreService::pointsValue($threshold - $total),
            'reachable_rank' => $reachable === null ? null : ['id' => $reachable['id'], 'title' => $reachable['title']],
            'threshold_reached_since' => $since,
            'below_current_threshold' => $current !== null && $current['min_points'] !== null && $total < $current['min_points'],
        ];
    }

    /**
     * Gesetzte Schwellen aktiver Dienstgrade müssen mit der Reihenfolge streng steigen.
     *
     * @param list<Rank> $ranks
     * @param array{title: string, sort: int, min_points: int|null, active: bool}|null $changed geänderter bzw. neuer Eintrag
     */
    private function checkThresholds(array $ranks, ?array $changed): void
    {
        if ($changed !== null) {
            $ranks[] = $changed + ['id' => PHP_INT_MAX];
        }
        usort($ranks, static fn (array $a, array $b): int => [$a['sort'], $a['id']] <=> [$b['sort'], $b['id']]);
        $prev = null;
        foreach ($ranks as $r) {
            if (!$r['active'] || $r['min_points'] === null) {
                continue;
            }
            if ($prev !== null && $r['min_points'] <= $prev['min_points']) {
                throw ApiError::invalid(sprintf(
                    'Die Punkteschwellen müssen mit der Reihenfolge steigen: „%s“ (%d) steht über „%s“ (%d).',
                    $r['title'],
                    $r['min_points'],
                    $prev['title'],
                    $prev['min_points'],
                ));
            }
            $prev = $r;
        }
    }

    /**
     * @param list<Rank> $ranks
     * @return Rank|null
     */
    private function target(array $ranks, mixed $rankId): ?array
    {
        if ($rankId === null || $rankId === '' || $rankId === 0) {
            return null;
        }
        $rank = $this->byId($ranks, Input::integer($rankId));
        if ($rank === null || !$rank['active']) {
            throw ApiError::invalid(sprintf('Unbekannter oder deaktivierter %s.', $this->app->config->rankLabel()));
        }
        return $rank;
    }

    /**
     * @param list<Rank> $ranks
     * @return Rank|null
     */
    private function byId(array $ranks, ?int $id): ?array
    {
        foreach ($ranks as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }
        return null;
    }

    /**
     * @param list<Rank> $ranks nach Reihenfolge
     * @param Rank $rank
     */
    private function position(array $ranks, array $rank): int
    {
        foreach ($ranks as $i => $r) {
            if ($r['id'] === $rank['id']) {
                return $i;
            }
        }
        return -1;
    }

    private function validTitle(mixed $raw, ?int $id): string
    {
        $title = Text::line($raw ?? '', self::TITLE_MAX + 1);
        if ($title === '') {
            throw ApiError::invalid('Bitte eine Bezeichnung angeben.');
        }
        if (mb_strlen($title) > self::TITLE_MAX) {
            throw ApiError::invalid(sprintf('Die Bezeichnung darf höchstens %d Zeichen lang sein.', self::TITLE_MAX));
        }
        foreach ($this->repository()->all(false) as $r) {
            if ($r['id'] !== $id && mb_strtolower($r['title']) === mb_strtolower($title)) {
                throw ApiError::invalid('Diese Bezeichnung gibt es schon.');
            }
        }
        return $title;
    }

    private static function validThreshold(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $v = Input::integer($raw);
        if ($v === null || $v < 0 || $v > self::MAX_THRESHOLD) {
            throw ApiError::invalid('Die Punkteschwelle muss eine ganze Zahl ab 0 sein (oder leer).');
        }
        return $v;
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function format(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'title' => (string) $r['title'],
            'sort' => (int) $r['sort'],
            'min_points' => $r['min_points'] === null ? null : (int) $r['min_points'],
            'active' => (bool) $r['active'],
            'assigned' => $this->repository()->assignedCount((int) $r['id']),
        ];
    }

    private static function number(float $v): string
    {
        $v = round($v, 2);
        return str_replace('.', ',', floor($v) === $v ? (string) (int) $v : (string) $v);
    }
}
