<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Storage\TitleRepository;

/**
 * Titel (Issue #22): Ehrentitel und Ämter wie Ehrenmitglied oder Vorsitz.
 *
 * - Unabhängig von Dienstgrad und Punkten; sie werden zusätzlich zum Dienstgrad angezeigt.
 * - Die Liste pflegen und Titel zuweisen darf, wer „Titel verwalten“ darf.
 * - Ein Mitglied kann mehrere Titel haben; angezeigt in der Reihenfolge der Liste.
 * - Nur Mitglieder bekommen Titel; wer keines mehr ist, behält sie, bis sie entfernt werden.
 * - Zugewiesene Titel lassen sich nicht löschen, nur umbenennen oder deaktivieren. Deaktivierte Titel werden nicht
 *   mehr neu vergeben; wer sie hat, behält sie.
 *
 * @phpstan-import-type Title from TitleRepository
 */
final class TitleService
{
    public const TITLE_MAX = 60;

    private ?TitleRepository $repo = null;

    public function __construct(private readonly App $app)
    {
    }

    public function repository(): TitleRepository
    {
        return $this->repo ??= new TitleRepository($this->app->db);
    }

    /**
     * Die Titelliste für die Pflege: alle, mit wie vielen Mitgliedern sie zugewiesen sind.
     *
     * @return list<array{id: int, title: string, sort: int, active: bool, assigned: int}>
     */
    public function list(): array
    {
        $this->forgetUnknownUsers();
        $counts = $this->repository()->assignedCounts();
        return array_map(static fn (array $t): array => $t + ['assigned' => $counts[$t['id']] ?? 0], $this->repository()->all(false));
    }

    /**
     * @param array<string, mixed> $in title
     * @return array{id: int, title: string, sort: int, active: bool, assigned: int}
     */
    public function create(array $in): array
    {
        $repo = $this->repository();
        $id = $repo->insert($this->validTitle($in['title'] ?? null, null), $repo->maxSort() + 10);
        return $this->one($id);
    }

    /**
     * @param array<string, mixed> $in title, active
     * @return array{id: int, title: string, sort: int, active: bool, assigned: int}
     */
    public function update(int $id, array $in): array
    {
        $repo = $this->repository();
        $repo->find($id) ?? throw ApiError::notFound('Titel nicht gefunden.');
        $data = [];
        if (array_key_exists('title', $in)) {
            $data['title'] = $this->validTitle($in['title'], $id);
        }
        if (array_key_exists('active', $in)) {
            if (!is_bool($in['active'])) {
                throw ApiError::invalid('Ungültiger Wert für „aktiv“.');
            }
            $data['active'] = $in['active'];
        }
        $repo->update($id, $data);
        return $this->one($id);
    }

    /** Nur Titel, die niemand hat. */
    public function delete(int $id): void
    {
        $repo = $this->repository();
        $repo->find($id) ?? throw ApiError::notFound('Titel nicht gefunden.');
        $this->forgetUnknownUsers();
        $count = $repo->assignedCount($id);
        if ($count > 0) {
            throw ApiError::invalid(sprintf(
                'Der Titel ist noch %s zugewiesen und lässt sich nur umbenennen oder deaktivieren.',
                $count === 1 ? 'einem Mitglied' : $count . ' Mitgliedern',
            ));
        }
        $repo->delete($id);
    }

    /**
     * Neue Reihenfolge: alle IDs in der gewünschten Anzeigereihenfolge.
     *
     * @return list<array{id: int, title: string, sort: int, active: bool, assigned: int}>
     */
    public function reorder(mixed $ids): array
    {
        $repo = $this->repository();
        $titles = [];
        foreach ($repo->all(false) as $t) {
            $titles[$t['id']] = $t;
        }
        $order = is_array($ids) ? array_map(static fn (mixed $v): ?int => Input::integer($v), array_values($ids)) : [];
        $given = $order;
        sort($given);
        $known = array_keys($titles);
        sort($known);
        if ($given !== $known) {
            throw ApiError::invalid('Bitte die Reihenfolge aller Einträge angeben.');
        }
        foreach ($order as $i => $id) {
            $sort = ($i + 1) * 10;
            if ($titles[(int) $id]['sort'] !== $sort) {
                $repo->update((int) $id, ['sort' => $sort]);
            }
        }
        return $this->list();
    }

    /**
     * Titel eines Mitglieds in der Reihenfolge der Liste.
     *
     * @return list<array{id: int, title: string}>
     */
    public function of(int $userId): array
    {
        return self::refs($this->repository()->all(false), $this->repository()->titleIdsOf($userId));
    }

    /**
     * Titel aller Mitglieder in zwei Abfragen (für die Mitgliederliste).
     *
     * @return array<int, list<array{id: int, title: string}>> Benutzer-ID => Titel
     */
    public function byUser(): array
    {
        $all = $this->repository()->all(false);
        $out = [];
        foreach ($this->repository()->assignments() as $userId => $ids) {
            $out[$userId] = self::refs($all, $ids);
        }
        return $out;
    }

    /**
     * Titel eines Mitglieds ersetzen. Neu vergeben werden nur aktive Titel; deaktivierte, die es schon hat, darf es
     * behalten. Benachrichtigt über neu verliehene Titel.
     *
     * @return list<array{id: int, title: string}>
     */
    public function assign(int $userId, mixed $titleIds): array
    {
        $user = $this->app->users->find($userId) ?? throw ApiError::notFound('Mitglied nicht gefunden.');
        if (!is_array($titleIds)) {
            throw ApiError::invalid('Bitte die Titel als Liste angeben.');
        }
        $repo = $this->repository();
        $titles = [];
        foreach ($repo->all(false) as $t) {
            $titles[$t['id']] = $t;
        }
        $current = $repo->titleIdsOf($userId);
        $ids = [];
        foreach ($titleIds as $raw) {
            $id = Input::integer($raw);
            if ($id === null || !isset($titles[$id])) {
                throw ApiError::invalid('Unbekannter Titel.');
            }
            // Wer kein Mitglied (mehr) ist, z. B. nach einem Rollenwechsel zum Gast, kann Titel nur verlieren.
            if (!$user->isMember() && !in_array($id, $current, true)) {
                throw ApiError::invalid(sprintf('%s haben nur Mitglieder.', $this->app->config->sectionLabel('titles', 'Titel')));
            }
            if (!$titles[$id]['active'] && !in_array($id, $current, true)) {
                throw ApiError::invalid(sprintf('Der Titel „%s“ ist deaktiviert und wird nicht mehr vergeben.', $titles[$id]['title']));
            }
            $ids[$id] = $id;
        }
        $ids = array_values($ids);
        $repo->replace($userId, $ids, $this->app->time()->nowUtc());
        $added = array_values(array_diff($ids, $current));
        try {
            $this->app->pushTriggers()->titlesAdded($userId, array_column(self::refs(array_values($titles), $added), 'title'));
        } catch (\Throwable $e) {
            error_log('Kompanie-App: Benachrichtigung zum Titel nicht eingereiht: ' . $e->getMessage());
        }
        return $this->of($userId);
    }

    /**
     * Zuweisungen an Konten, die es nicht mehr gibt (z. B. bei WordPress gelöscht, während das Plugin aus war, oder
     * eine Sicherung von einer anderen Seite), entfernen: Sie ließen sich sonst nirgends lösen und blockierten das
     * Löschen des Titels.
     */
    private function forgetUnknownUsers(): void
    {
        $assignments = $this->repository()->assignments();
        // Alle Konten gemeinsam prüfen (eine Abfrage statt einer je Träger)
        $known = $this->app->users->findMany(array_keys($assignments));
        foreach (array_keys($assignments) as $userId) {
            if (!isset($known[$userId])) {
                $this->app->db->execute('DELETE FROM {member_titles} WHERE user_id = ?', [$userId]);
            }
        }
    }

    /**
     * @param list<Title> $all nach Reihenfolge
     * @param list<int> $ids
     * @return list<array{id: int, title: string}>
     */
    private static function refs(array $all, array $ids): array
    {
        $out = [];
        foreach ($all as $t) {
            if (in_array($t['id'], $ids, true)) {
                $out[] = ['id' => $t['id'], 'title' => $t['title']];
            }
        }
        return $out;
    }

    /** @return array{id: int, title: string, sort: int, active: bool, assigned: int} */
    private function one(int $id): array
    {
        $t = $this->repository()->find($id) ?? throw ApiError::notFound('Titel nicht gefunden.');
        return $t + ['assigned' => $this->repository()->assignedCount($id)];
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
        foreach ($this->repository()->all(false) as $t) {
            if ($t['id'] !== $id && mb_strtolower($t['title']) === mb_strtolower($title)) {
                throw ApiError::invalid(sprintf('Den Titel „%s“ gibt es schon.', $t['title']));
            }
        }
        return $title;
    }
}
