<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Domain\ProfileVisibility;
use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Eintritt aktiver Mitglieder (Tabelle join_years): einer je Konto, mit der Anzeige auf der eigenen Seite in der
 * Mitgliederliste (profile, ProfileVisibility). year ist immer das Jahr, joined_on das ganze Datum (JJJJ-MM-TT) oder
 * leer, wenn nur das Jahr bekannt ist. updated_at in UTC ('Y-m-d H:i:s').
 *
 * @phpstan-type JoinYear array{user_id: int, year: int, joined_on: string, profile: string, updated_by: int, updated_at: string}
 */
final class JoinYearRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return JoinYear|null */
    public function find(int $userId): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM {join_years} WHERE user_id = ?', [$userId]);
        return $row === null ? null : self::joinYear($row);
    }

    /**
     * @param list<string> $profiles
     * @return array<int, array{year: int, date: string}> Benutzer-ID => Jahr und Eintritt (JJJJ-MM-TT oder JJJJ), nur mit
     *         einer dieser Anzeigen
     */
    public function withProfile(array $profiles): array
    {
        if ($profiles === []) {
            return [];
        }
        $rows = $this->db->select('SELECT user_id, year, joined_on FROM {join_years} WHERE profile IN (' . implode(', ', array_fill(0, count($profiles), '?')) . ')', $profiles);
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['user_id']] = ['year' => (int) $r['year'], 'date' => (string) $r['joined_on'] !== '' ? (string) $r['joined_on'] : (string) $r['year']];
        }
        return $out;
    }

    /** @return array<int, string> Benutzer-ID => Eintritt (JJJJ-MM-TT oder JJJJ), alle unabhängig von der Anzeige (Verwaltung) */
    public function all(): array
    {
        $out = [];
        foreach ($this->db->select('SELECT user_id, year, joined_on FROM {join_years}') as $r) {
            $out[(int) $r['user_id']] = (string) $r['joined_on'] !== '' ? (string) $r['joined_on'] : (string) $r['year'];
        }
        return $out;
    }

    /**
     * Anlegen oder ändern. $date: JJJJ-MM-TT im Jahr $year oder leer (nur das Jahr). Ohne $profile bleibt die Anzeige, wie
     * sie ist (neu: nur Mitgliedern zeigen).
     */
    public function save(int $userId, int $year, string $date, ?string $profile, int $by, string $now): void
    {
        $values = ['year' => $year, 'joined_on' => $date, 'updated_by' => $by, 'updated_at' => $now];
        if ($profile !== null) {
            $values['profile'] = $profile;
        }
        if ($this->find($userId) !== null) {
            $this->db->update('join_years', $values, ['user_id' => $userId]);
            return;
        }
        try {
            $this->db->insert('join_years', ['user_id' => $userId] + $values + ['profile' => ProfileVisibility::MEMBERS]);
        } catch (\RuntimeException $e) {
            // Gleichzeitig angelegt: dann eben ändern.
            if ($this->find($userId) === null) {
                throw $e;
            }
            $this->db->update('join_years', $values, ['user_id' => $userId]);
        }
    }

    public function delete(int $userId): bool
    {
        return $this->db->execute('DELETE FROM {join_years} WHERE user_id = ?', [$userId]) > 0;
    }

    /**
     * @param array<string, mixed> $r
     * @return JoinYear
     */
    private static function joinYear(array $r): array
    {
        return [
            'user_id' => (int) $r['user_id'],
            'year' => (int) $r['year'],
            'joined_on' => (string) ($r['joined_on'] ?? ''),
            'profile' => ProfileVisibility::stored($r['profile']),
            'updated_by' => (int) $r['updated_by'],
            'updated_at' => (string) $r['updated_at'],
        ];
    }
}
