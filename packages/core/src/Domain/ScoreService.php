<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Port\UserDirectory;
use KompanieApp\Core\Storage\CatalogRepository;
use KompanieApp\Core\Storage\PenaltyRepository;
use KompanieApp\Core\Storage\PointRepository;

/**
 * Punkte, Strafen und Punktekatalog.
 * Geldbeträge sind immer ganze Cent; Einträge werden nur storniert, nie gelöscht.
 */
final class ScoreService
{
    public const MAX_POINTS = 10000;
    /** Höchstbetrag einer einzelnen Strafe: 1.000 € */
    public const MAX_CENTS = 100000;

    public function __construct(
        private readonly PointRepository $points,
        private readonly PenaltyRepository $penalties,
        private readonly CatalogRepository $catalog,
        private readonly UserDirectory $users,
        private readonly UserNames $names,
        private readonly Time $time,
        private readonly ?RoleService $roles = null,
    ) {
    }

    /** Punkte für die API: ganze Zahl, wenn möglich, sonst mit höchstens zwei Nachkommastellen. */
    public static function pointsValue(float|int $v): int|float
    {
        $v = round((float) $v, 2);
        return floor($v) === $v ? (int) $v : $v;
    }

    /* ---------------------------------------------------------------- */
    /* Punkte                                                            */
    /* ---------------------------------------------------------------- */

    /** @return array<int, array<string, mixed>> */
    public function pointsFor(int $userId): array
    {
        return array_map([$this, 'formatPoints'], $this->points->forUser($userId));
    }

    public function pointsTotal(int $userId): int|float
    {
        return self::pointsValue($this->points->total($userId));
    }

    /**
     * Der Punktefaktor kommt aus der Rolle der Person (Issue #28) und wird mit dem Eintrag gespeichert.
     * apply_factor = false bucht für alle mit Faktor 1 (die Leitung kann den Faktor so übergehen).
     *
     * attendance_id: die Anwesenheitsliste, aus deren Vorschlag gebucht wird (geprüft von AttendanceService::forBooking).
     *
     * @param array<string, mixed> $in user_ids, points, catalog_id, reason, date, apply_factor, attendance_id
     * @return array<int, array<string, mixed>>
     */
    public function givePoints(array $in, int $by): array
    {
        $users = $this->members($in['user_ids'] ?? null);
        $applyFactor = !array_key_exists('apply_factor', $in) || Input::bool($in['apply_factor']);
        $factors = [];
        foreach ($users as $id => $user) {
            $factors[$id] = $applyFactor && $this->roles !== null ? $this->roles->pointsFactor($user->role) : 1.0;
        }
        $date = Input::date($in['date'] ?? null, $this->time->today());

        $catalogId = Input::integer($in['catalog_id'] ?? null) ?: null;
        $item = $catalogId ? $this->catalog->find($catalogId) : null;
        if ($catalogId && !$item) {
            throw ApiError::invalid('Katalogeintrag nicht gefunden.');
        }

        $raw = $in['points'] ?? null;
        $points = ($raw === null || $raw === '') && $item ? (int) $item['points'] : self::parsePoints($raw);
        if ($points === 0) {
            throw ApiError::invalid('Die Punktzahl darf nicht 0 sein.');
        }

        $reason = Text::line($in['reason'] ?? '', 255);
        if ($reason === '' && $item) {
            $reason = (string) $item['title'];
        }
        if ($reason === '') {
            throw ApiError::invalid('Bitte einen Grund angeben.');
        }

        $attendanceId = Input::integer($in['attendance_id'] ?? null) ?: null;
        $ids = $this->points->add($factors, $points, $reason, $catalogId, $date, $by, $this->time->nowUtc(), $attendanceId);
        return array_map(fn (int $id): array => $this->formatPoints((array) $this->points->find($id)), $ids);
    }

    public function cancelPoints(int $id, int $by): void
    {
        $row = $this->points->find($id);
        if (!$row || $row['deleted_at']) {
            throw ApiError::notFound('Eintrag nicht gefunden.');
        }
        $this->points->cancel($id, $by, $this->time->nowUtc());
    }

    /* ---------------------------------------------------------------- */
    /* Strafen                                                           */
    /* ---------------------------------------------------------------- */

    /** @return array<int, array<string, mixed>> */
    public function penaltiesFor(int $userId): array
    {
        return array_map([$this, 'formatPenalty'], $this->penalties->forUser($userId));
    }

    public function openCents(int $userId): int
    {
        return $this->penalties->openCents($userId);
    }

    /**
     * @param array<string, mixed> $in user_ids, amount_cents, reason, date
     * @return array<int, array<string, mixed>>
     */
    public function givePenalty(array $in, int $by): array
    {
        $userIds = $this->memberIds($in['user_ids'] ?? null);
        $date = Input::date($in['date'] ?? null, $this->time->today());
        $cents = Input::integer($in['amount_cents'] ?? null);
        if ($cents === null || $cents <= 0 || $cents > self::MAX_CENTS) {
            throw ApiError::invalid('Bitte einen Betrag zwischen 0,01 € und 1.000 € angeben.');
        }
        $reason = Text::line($in['reason'] ?? '', 255);
        if ($reason === '') {
            throw ApiError::invalid('Bitte einen Grund angeben.');
        }
        $ids = $this->penalties->add($userIds, $cents, $reason, $date, $by, $this->time->nowUtc());
        return array_map(fn (int $id): array => $this->formatPenalty((array) $this->penalties->find($id)), $ids);
    }

    public function setPenaltyStatus(int $id, mixed $status, int $by): array
    {
        $row = $this->penalties->find($id);
        if (!$row || $row['deleted_at']) {
            throw ApiError::notFound('Strafe nicht gefunden.');
        }
        if (!in_array($status, [PenaltyRepository::OPEN, PenaltyRepository::PAID], true)) {
            throw ApiError::invalid('Ungültiger Status.');
        }
        $this->penalties->setStatus($id, $status, $by, $this->time->nowUtc());
        return $this->formatPenalty((array) $this->penalties->find($id));
    }

    public function cancelPenalty(int $id, int $by): void
    {
        $row = $this->penalties->find($id);
        if (!$row || $row['deleted_at']) {
            throw ApiError::notFound('Strafe nicht gefunden.');
        }
        $this->penalties->cancel($id, $by, $this->time->nowUtc());
    }

    /** @return array<int, array{points: int, open_cents: int}> */
    public function totalsByUser(): array
    {
        $out = [];
        foreach ($this->points->totals() as $uid => $total) {
            $out[$uid]['points'] = self::pointsValue($total);
        }
        foreach ($this->penalties->openTotals() as $uid => $total) {
            $out[$uid]['open_cents'] = $total;
        }
        return array_map(static fn (array $t): array => ['points' => $t['points'] ?? 0, 'open_cents' => $t['open_cents'] ?? 0], $out);
    }

    /* ---------------------------------------------------------------- */
    /* Katalog                                                           */
    /* ---------------------------------------------------------------- */

    /** @return array<int, array<string, mixed>> */
    public function catalog(bool $all): array
    {
        return array_map([self::class, 'formatCatalog'], $this->catalog->all(!$all));
    }

    /** @param array<string, mixed> $in */
    public function createCatalogItem(array $in): array
    {
        $title = Text::line($in['title'] ?? '', 191);
        if ($title === '') {
            throw ApiError::invalid('Bitte einen Titel angeben.');
        }
        $points = self::parsePoints($in['points'] ?? null);
        $id = $this->catalog->insert($title, $points, self::sort($in['sort'] ?? 0));
        return self::formatCatalog((array) $this->catalog->find($id));
    }

    /** @param array<string, mixed> $in */
    public function updateCatalogItem(int $id, array $in): array
    {
        if (!$this->catalog->find($id)) {
            throw ApiError::notFound('Eintrag nicht gefunden.');
        }
        $data = [];
        if (($in['title'] ?? null) !== null) {
            $data['title'] = Text::line($in['title'], 191);
            if ($data['title'] === '') {
                throw ApiError::invalid('Bitte einen Titel angeben.');
            }
        }
        if (($in['points'] ?? null) !== null) {
            $data['points'] = self::parsePoints($in['points']);
        }
        if (($in['active'] ?? null) !== null) {
            $data['active'] = Input::bool($in['active']) ? 1 : 0;
        }
        if (($in['sort'] ?? null) !== null) {
            $data['sort'] = self::sort($in['sort']);
        }
        $this->catalog->update($id, $data);
        return self::formatCatalog((array) $this->catalog->find($id));
    }

    /* ---------------------------------------------------------------- */
    /* Prüfung und Format                                                */
    /* ---------------------------------------------------------------- */

    /** @return int[] */
    private function memberIds(mixed $raw): array
    {
        return array_keys($this->members($raw));
    }

    /**
     * Ausgewählte Mitglieder prüfen (keine Gäste).
     *
     * @return array<int, User> ID => Benutzer
     */
    private function members(mixed $raw): array
    {
        if (!is_array($raw) || $raw === []) {
            throw ApiError::invalid('Bitte mindestens ein Mitglied auswählen.');
        }
        $ids = [];
        foreach ($raw as $v) {
            $id = Input::integer($v);
            if ($id === null || $id <= 0) {
                throw ApiError::invalid('Unbekanntes Mitglied.');
            }
            $ids[$id] = $id;
        }
        $users = [];
        foreach ($ids as $id) {
            $user = $this->users->find($id);
            if (!$user) {
                throw ApiError::invalid('Unbekanntes Mitglied.');
            }
            if (!$user->isMember()) {
                throw ApiError::invalid('Gäste können keine Punkte oder Strafen bekommen.');
            }
            $users[$id] = $user;
        }
        return $users;
    }

    /** Reihenfolge im Katalog; begrenzt, damit sie in jede Datenbankspalte passt. */
    private static function sort(mixed $raw): int
    {
        return max(-1000000, min(1000000, Input::integer($raw) ?? 0));
    }

    private static function parsePoints(mixed $raw): int
    {
        $points = Input::integer($raw);
        if ($points === null || abs($points) > self::MAX_POINTS) {
            throw ApiError::invalid('Bitte eine ganze Punktzahl angeben.');
        }
        return $points;
    }

    /** @param array<string, mixed> $r */
    private function formatPoints(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'user_id' => (int) $r['user_id'],
            // was zählt (Punkte × Faktor); dazu die gebuchte Punktzahl und der Faktor der Rolle
            'points' => self::pointsValue((int) $r['points'] * (float) ($r['factor'] ?? 1)),
            'base_points' => (int) $r['points'],
            'factor' => (float) ($r['factor'] ?? 1),
            'reason' => (string) $r['reason'],
            'catalog_id' => $r['catalog_id'] ? (int) $r['catalog_id'] : null,
            'attendance_id' => ($r['attendance_id'] ?? null) ? (int) $r['attendance_id'] : null,
            'date' => (string) $r['entry_date'],
            'created_by_name' => $this->names->name((int) $r['created_by']),
        ];
    }

    /** @param array<string, mixed> $r */
    private function formatPenalty(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'user_id' => (int) $r['user_id'],
            'amount_cents' => (int) $r['amount_cents'],
            'reason' => (string) $r['reason'],
            'date' => (string) $r['entry_date'],
            'status' => (string) $r['status'],
            'paid_at' => Time::utcToIso($r['paid_at'] ?? null),
            'created_by_name' => $this->names->name((int) $r['created_by']),
        ];
    }

    /** @param array<string, mixed> $r */
    private static function formatCatalog(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'title' => (string) $r['title'],
            'points' => (int) $r['points'],
            'active' => (bool) (int) $r['active'],
            'sort' => (int) $r['sort'],
        ];
    }
}
