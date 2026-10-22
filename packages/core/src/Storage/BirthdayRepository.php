<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Domain\ProfileVisibility;
use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Geburtstage (Issue #1): einer je Konto. updated_at in UTC ('Y-m-d H:i:s'); last_notified ist ein Tag in der Zeitzone
 * der Kompanie ('Y-m-d'), für den die Benachrichtigung schon beansprucht wurde. profile: Anzeige auf der Seite der Person
 * in der Mitgliederliste (ProfileVisibility), unabhängig von visible.
 *
 * @phpstan-type Birthday array{user_id: int, month: int, day: int, year: int|null, visible: bool, show_age: bool, profile: string, last_notified: string|null, updated_by: int, updated_at: string}
 */
final class BirthdayRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return Birthday|null */
    public function find(int $userId): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM {birthdays} WHERE user_id = ?', [$userId]);
        return $row === null ? null : self::birthday($row);
    }

    /** @return list<Birthday> alle, nach Monat und Tag */
    public function all(): array
    {
        return array_values(array_map(self::birthday(...), $this->db->select('SELECT * FROM {birthdays} ORDER BY month, day, user_id')));
    }

    /** @return list<Birthday> nur die, die die Person anderen zeigt */
    public function visible(): array
    {
        return array_values(array_map(self::birthday(...), $this->db->select('SELECT * FROM {birthdays} WHERE visible = 1 ORDER BY month, day, user_id')));
    }

    /**
     * @param list<string> $profiles
     * @return list<Birthday> nur die mit einer dieser Anzeigen auf der Seite der Person
     */
    public function withProfile(array $profiles): array
    {
        if ($profiles === []) {
            return [];
        }
        $sql = 'SELECT * FROM {birthdays} WHERE profile IN (' . implode(', ', array_fill(0, count($profiles), '?')) . ')';
        return array_values(array_map(self::birthday(...), $this->db->select($sql, $profiles)));
    }

    /**
     * Anlegen oder ändern. Ohne $visible, $showAge bzw. $profile bleiben die Schalter, wie sie sind (neu: sichtbar, ohne
     * Alter, auf der Seite in der Mitgliederliste nur für Mitglieder).
     */
    public function save(int $userId, int $month, int $day, ?int $year, ?bool $visible, ?bool $showAge, int $by, string $now, ?string $profile = null): void
    {
        $values = ['month' => $month, 'day' => $day, 'year' => $year, 'updated_by' => $by, 'updated_at' => $now];
        if ($visible !== null) {
            $values['visible'] = $visible ? 1 : 0;
        }
        if ($showAge !== null) {
            $values['show_age'] = $showAge ? 1 : 0;
        }
        if ($profile !== null) {
            $values['profile'] = $profile;
        }
        if ($this->find($userId) !== null) {
            $this->db->update('birthdays', $values, ['user_id' => $userId]);
            return;
        }
        try {
            $this->db->insert('birthdays', ['user_id' => $userId] + $values + ['visible' => 1, 'show_age' => 0, 'profile' => ProfileVisibility::MEMBERS]);
        } catch (\RuntimeException $e) {
            // Gleichzeitig angelegt: dann eben ändern.
            if ($this->find($userId) === null) {
                throw $e;
            }
            $this->db->update('birthdays', $values, ['user_id' => $userId]);
        }
    }

    public function delete(int $userId): bool
    {
        return $this->db->execute('DELETE FROM {birthdays} WHERE user_id = ?', [$userId]) > 0;
    }

    /**
     * Benachrichtigung für diesen Tag beanspruchen: true nur für den ersten Lauf (auch bei gleichzeitigen Läufen), wie
     * reminded_at bei den Umfragen.
     */
    public function claim(int $userId, string $date): bool
    {
        return $this->db->execute(
            'UPDATE {birthdays} SET last_notified = ? WHERE user_id = ? AND (last_notified IS NULL OR last_notified <> ?)',
            [$date, $userId, $date],
        ) === 1;
    }

    /**
     * @param array<string, mixed> $r
     * @return Birthday
     */
    private static function birthday(array $r): array
    {
        return [
            'user_id' => (int) $r['user_id'],
            'month' => (int) $r['month'],
            'day' => (int) $r['day'],
            'year' => $r['year'] === null || $r['year'] === '' ? null : (int) $r['year'],
            'visible' => (bool) (int) $r['visible'],
            'show_age' => (bool) (int) $r['show_age'],
            'profile' => ProfileVisibility::stored($r['profile'] ?? null),
            'last_notified' => $r['last_notified'] === null || $r['last_notified'] === '' ? null : substr((string) $r['last_notified'], 0, 10),
            'updated_by' => (int) $r['updated_by'],
            'updated_at' => (string) $r['updated_at'],
        ];
    }
}
