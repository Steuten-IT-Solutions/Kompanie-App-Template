<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Ämter (Issue #112): Gruppen, Ämter, Amtszeiten und ihre Inhaber, dazu gemeinsame Amtszeiten einer Gruppe (Issue #282).
 *
 * @phpstan-type Group array{id: int, title: string, singular: string, sort: int, joint_terms: bool}
 * @phpstan-type GroupTerm array{id: int, group_id: int, kind: string, start_year: int, end_year: int|null, note: string, image_uid: string|null}
 * @phpstan-type Office array{id: int, group_id: int|null, title: string, singular: string, sort: int, title_id: int|null}
 * @phpstan-type Term array{id: int, office_id: int, kind: string, start_year: int, end_year: int|null, note: string, honor: bool, ended_for: int|null, group_term_id: int|null}
 * @phpstan-type Holder array{id: int, term_id: int, user_id: int|null, name: string, sort: int, image_uid: string|null}
 */
final class OfficeRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return list<Group> nach Reihenfolge */
    public function groups(): array
    {
        return array_values(array_map(self::group(...), $this->db->select('SELECT * FROM {office_groups} ORDER BY sort ASC, id ASC')));
    }

    /** @return Group|null */
    public function findGroup(int $id): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM {office_groups} WHERE id = ?', [$id]);
        return $row === null ? null : self::group($row);
    }

    public function insertGroup(string $title, int $sort, string $singular = ''): int
    {
        return $this->db->insert('office_groups', ['title' => $title, 'singular' => $singular, 'sort' => $sort]);
    }

    /** @param array{title?: string, singular?: string, sort?: int, joint_terms?: int} $data */
    public function updateGroup(int $id, array $data): void
    {
        if ($data !== []) {
            $this->db->update('office_groups', $data, ['id' => $id]);
        }
    }

    /** Gruppe samt ihren gemeinsamen Amtszeiten; Amtszeiten der Ämter (inzwischen anderswo) verlieren nur die Bündelung. */
    public function deleteGroup(int $id): void
    {
        $this->db->transaction(function () use ($id): void {
            $this->db->execute('UPDATE {office_terms} SET group_term_id = NULL WHERE group_term_id IN (SELECT id FROM {office_group_terms} WHERE group_id = ?)', [$id]);
            $this->db->execute('DELETE FROM {office_group_terms} WHERE group_id = ?', [$id]);
            $this->db->execute('DELETE FROM {office_groups} WHERE id = ?', [$id]);
        });
    }

    // ---- Gemeinsame Amtszeiten (Issue #282) ----

    /** @return list<GroupTerm> */
    public function groupTerms(?int $groupId = null): array
    {
        $rows = $groupId === null
            ? $this->db->select('SELECT * FROM {office_group_terms} ORDER BY id ASC')
            : $this->db->select('SELECT * FROM {office_group_terms} WHERE group_id = ? ORDER BY id ASC', [$groupId]);
        return array_values(array_map(self::groupTerm(...), $rows));
    }

    /** @return GroupTerm|null */
    public function findGroupTerm(int $id): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM {office_group_terms} WHERE id = ?', [$id]);
        return $row === null ? null : self::groupTerm($row);
    }

    /** @param array{kind: string, start_year: int, end_year: int|null, note: string} $term */
    public function insertGroupTerm(int $groupId, array $term): int
    {
        return $this->db->insert('office_group_terms', ['group_id' => $groupId, 'image_uid' => null] + $term);
    }

    /** @param array{kind?: string, start_year?: int, end_year?: int|null, note?: string, image_uid?: string|null} $data */
    public function updateGroupTerm(int $id, array $data): void
    {
        if ($data !== []) {
            $this->db->update('office_group_terms', $data, ['id' => $id]);
        }
    }

    /** Gemeinsame Amtszeit samt den Amtszeiten der Ämter darin, in einer Transaktion. */
    public function deleteGroupTerm(int $id): void
    {
        $this->db->transaction(function () use ($id): void {
            $this->db->execute('DELETE FROM {office_holders} WHERE term_id IN (SELECT id FROM {office_terms} WHERE group_term_id = ?)', [$id]);
            $this->db->execute('DELETE FROM {office_terms} WHERE group_term_id = ?', [$id]);
            $this->db->execute('DELETE FROM {office_group_terms} WHERE id = ?', [$id]);
        });
    }

    public function setGroupTerm(int $termId, ?int $groupTermId): void
    {
        $this->db->update('office_terms', ['group_term_id' => $groupTermId], ['id' => $termId]);
    }

    public function maxGroupSort(): int
    {
        return (int) $this->db->scalar('SELECT COALESCE(MAX(sort), 0) FROM {office_groups}');
    }

    /** @return list<Office> nach Reihenfolge (die Gruppen ordnet der Dienst) */
    public function offices(): array
    {
        return array_values(array_map(self::office(...), $this->db->select('SELECT * FROM {offices} ORDER BY sort ASC, id ASC')));
    }

    /** @return Office|null */
    public function findOffice(int $id): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM {offices} WHERE id = ?', [$id]);
        return $row === null ? null : self::office($row);
    }

    public function insertOffice(?int $groupId, string $title, int $sort, string $singular = ''): int
    {
        return $this->db->insert('offices', ['group_id' => $groupId, 'title' => $title, 'singular' => $singular, 'sort' => $sort, 'title_id' => null]);
    }

    /** @param array{group_id?: int|null, title?: string, singular?: string, sort?: int, title_id?: int|null} $data */
    public function updateOffice(int $id, array $data): void
    {
        if ($data !== []) {
            $this->db->update('offices', $data, ['id' => $id]);
        }
    }

    /** Amt mit allen Amtszeiten und Inhabern, in einer Transaktion. */
    public function deleteOffice(int $id): void
    {
        $this->db->transaction(function () use ($id): void {
            $this->db->execute('DELETE FROM {office_holders} WHERE term_id IN (SELECT id FROM {office_terms} WHERE office_id = ?)', [$id]);
            $this->db->execute('DELETE FROM {office_terms} WHERE office_id = ?', [$id]);
            $this->db->execute('DELETE FROM {offices} WHERE id = ?', [$id]);
        });
    }

    public function maxOfficeSort(): int
    {
        return (int) $this->db->scalar('SELECT COALESCE(MAX(sort), 0) FROM {offices}');
    }

    public function officeCountInGroup(int $groupId): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM {offices} WHERE group_id = ?', [$groupId]);
    }

    /** Ein Titel wurde gelöscht: Ämter, die mit ihm verknüpft waren, verlieren die Verknüpfung. */
    public function unlinkTitle(int $titleId): void
    {
        $this->db->execute('UPDATE {offices} SET title_id = NULL WHERE title_id = ?', [$titleId]);
    }

    /** @return list<Term> */
    public function terms(?int $officeId = null): array
    {
        $rows = $officeId === null
            ? $this->db->select('SELECT * FROM {office_terms} ORDER BY id ASC')
            : $this->db->select('SELECT * FROM {office_terms} WHERE office_id = ? ORDER BY id ASC', [$officeId]);
        return array_values(array_map(self::term(...), $rows));
    }

    /** @return Term|null */
    public function findTerm(int $id): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM {office_terms} WHERE id = ?', [$id]);
        return $row === null ? null : self::term($row);
    }

    /**
     * Amtszeit mit Inhabern anlegen bzw. ersetzen, in einer Transaktion.
     *
     * @param array{kind: string, start_year: int, end_year: int|null, note: string, honor: bool} $term
     * @param list<array{user_id: int|null, name: string, image_uid?: string|null}> $holders Foto nur bei freien Namen
     */
    public function saveTerm(?int $id, int $officeId, array $term, array $holders): int
    {
        $row = ['honor' => $term['honor'] ? 1 : 0] + $term;
        return (int) $this->db->transaction(function () use ($id, $officeId, $row, $holders): int {
            if ($id === null) {
                $id = $this->db->insert('office_terms', ['office_id' => $officeId] + $row);
            } else {
                $this->db->update('office_terms', $row, ['id' => $id]);
                $this->db->execute('DELETE FROM {office_holders} WHERE term_id = ?', [$id]);
            }
            foreach ($holders as $i => $h) {
                $this->db->insert('office_holders', [
                    'term_id' => $id,
                    'user_id' => $h['user_id'],
                    'name' => $h['name'],
                    'sort' => $i,
                    'image_uid' => $h['user_id'] === null ? ($h['image_uid'] ?? null) : null,
                ]);
            }
            return $id;
        });
    }

    /**
     * Ende einer Amtszeit setzen, die die App für ein Konto beendet (Ausscheiden); $endedFor merkt sich das Konto, null
     * gibt die Amtszeit an die Pflege von Hand zurück.
     */
    public function setEnd(int $id, int $endYear, ?int $endedFor): void
    {
        $this->db->update('office_terms', ['end_year' => $endYear, 'ended_for' => $endedFor], ['id' => $id]);
    }

    /** Amtszeit gehört nicht mehr zu den automatisch beendeten (z. B. nach einer Änderung von Hand). */
    public function releaseEnd(int $termId): void
    {
        $this->db->execute('UPDATE {office_terms} SET ended_for = NULL WHERE id = ?', [$termId]);
    }

    /** Alle Amtszeiten, die die App für dieses Konto beendet hat, gehören wieder der Pflege von Hand. */
    public function releaseEndsFor(int $userId): void
    {
        $this->db->execute('UPDATE {office_terms} SET ended_for = NULL WHERE ended_for = ?', [$userId]);
    }

    public function deleteTerm(int $id): void
    {
        $this->db->transaction(function () use ($id): void {
            $this->db->execute('DELETE FROM {office_holders} WHERE term_id = ?', [$id]);
            $this->db->execute('DELETE FROM {office_terms} WHERE id = ?', [$id]);
        });
    }

    /** @return array<int, list<Holder>> Amtszeit-ID => Inhaber in ihrer Reihenfolge, in einer Abfrage für alle */
    public function holders(): array
    {
        $out = [];
        foreach ($this->db->select('SELECT * FROM {office_holders} ORDER BY term_id ASC, sort ASC, id ASC') as $r) {
            $h = self::holder($r);
            $out[$h['term_id']][] = $h;
        }
        return $out;
    }

    /** @return list<string> Fotos, die noch in einer Amtszeit stehen (Issue #283) */
    public function holderImageUids(): array
    {
        return array_values(array_unique(array_map(static fn (array $r): string => (string) $r['image_uid'], $this->db->select('SELECT image_uid FROM {office_holders} WHERE image_uid IS NOT NULL'))));
    }

    /** @return list<Holder> */
    public function holdersOf(int $termId): array
    {
        return array_values(array_map(self::holder(...), $this->db->select('SELECT * FROM {office_holders} WHERE term_id = ? ORDER BY sort ASC, id ASC', [$termId])));
    }

    /**
     * @param array<string, mixed> $r
     * @return Group
     */
    private static function group(array $r): array
    {
        return ['id' => (int) $r['id'], 'title' => (string) $r['title'], 'singular' => (string) ($r['singular'] ?? ''), 'sort' => (int) $r['sort'], 'joint_terms' => (bool) ($r['joint_terms'] ?? false)];
    }

    /**
     * @param array<string, mixed> $r
     * @return GroupTerm
     */
    private static function groupTerm(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'group_id' => (int) $r['group_id'],
            'kind' => (string) $r['kind'],
            'start_year' => (int) $r['start_year'],
            'end_year' => $r['end_year'] === null ? null : (int) $r['end_year'],
            'note' => (string) $r['note'],
            'image_uid' => ($r['image_uid'] ?? null) === null || $r['image_uid'] === '' ? null : (string) $r['image_uid'],
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @return Office
     */
    private static function office(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'group_id' => $r['group_id'] === null ? null : (int) $r['group_id'],
            'title' => (string) $r['title'],
            'singular' => (string) ($r['singular'] ?? ''),
            'sort' => (int) $r['sort'],
            'title_id' => $r['title_id'] === null ? null : (int) $r['title_id'],
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @return Term
     */
    private static function term(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'office_id' => (int) $r['office_id'],
            'kind' => (string) $r['kind'],
            'start_year' => (int) $r['start_year'],
            'end_year' => $r['end_year'] === null ? null : (int) $r['end_year'],
            'note' => (string) $r['note'],
            'honor' => (bool) ($r['honor'] ?? false),
            'ended_for' => ($r['ended_for'] ?? null) === null ? null : (int) $r['ended_for'],
            'group_term_id' => ($r['group_term_id'] ?? null) === null ? null : (int) $r['group_term_id'],
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @return Holder
     */
    private static function holder(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'term_id' => (int) $r['term_id'],
            'user_id' => $r['user_id'] === null ? null : (int) $r['user_id'],
            'name' => (string) $r['name'],
            'image_uid' => isset($r['image_uid']) && $r['image_uid'] !== '' ? (string) $r['image_uid'] : null,
            'sort' => (int) $r['sort'],
        ];
    }
}
