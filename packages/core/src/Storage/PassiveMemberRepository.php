<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Passive (Bereich passive, Domain\PassiveMemberService): eigene Einträge ohne Konto. Name, Postanschrift (wie
 * MemberContactRepository), Telefon, E-Mail, Geburtsdatum und optional ein Mitglied als Betreuer.
 *
 * @phpstan-type AddressRow array{street: string, house_number: string, extra: string, postal_code: string, city: string, country: string, country_name: string}
 * @phpstan-type PassiveRow array{id: int, first_name: string, last_name: string, address: AddressRow|null, phone: string, email: string, birth_date: string|null, caretaker_id: int|null, updated_at: string}
 */
final class PassiveMemberRepository
{
    /** Feld der Adresse => Spalte (wie in member_contacts) */
    private const ADDRESS = [
        'street' => 'street',
        'house_number' => 'house_number',
        'extra' => 'address_extra',
        'postal_code' => 'postal_code',
        'city' => 'city',
        'country' => 'country',
        'country_name' => 'country_name',
    ];

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return list<PassiveRow> nach Nachname und Vorname */
    public function all(): array
    {
        return array_values(array_map(self::row(...), $this->db->select('SELECT * FROM {passive_members} ORDER BY last_name ASC, first_name ASC, id ASC')));
    }

    /** @return list<PassiveRow> die Passiven, die dieses Mitglied betreut */
    public function ofCaretaker(int $userId): array
    {
        return array_values(array_map(self::row(...), $this->db->select('SELECT * FROM {passive_members} WHERE caretaker_id = ? ORDER BY last_name ASC, first_name ASC, id ASC', [$userId])));
    }

    public function countOfCaretaker(int $userId): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM {passive_members} WHERE caretaker_id = ?', [$userId]);
    }

    /** @return PassiveRow|null */
    public function find(int $id): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM {passive_members} WHERE id = ?', [$id]);
        return $row === null ? null : self::row($row);
    }

    /** @param array<string, string|int|null> $values Spalte => Wert (columns()) */
    public function insert(array $values, int $by, string $now): int
    {
        return $this->db->insert('passive_members', $values + ['created_by' => $by, 'created_at' => $now, 'updated_by' => $by, 'updated_at' => $now]);
    }

    /** @param array<string, string|int|null> $values Spalte => Wert (columns()); leer ändert nichts */
    public function update(int $id, array $values, int $by, string $now): void
    {
        if ($values !== []) {
            $this->db->update('passive_members', $values + ['updated_by' => $by, 'updated_at' => $now], ['id' => $id]);
        }
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM {passive_members} WHERE id = ?', [$id]);
    }

    /**
     * Spalten für eine Adresse; null leert sie.
     *
     * @param AddressRow|null $address
     * @return array<string, string>
     */
    public static function addressColumns(?array $address): array
    {
        $out = [];
        foreach (self::ADDRESS as $field => $column) {
            $out[$column] = $address[$field] ?? '';
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $r
     * @return PassiveRow
     */
    private static function row(array $r): array
    {
        $address = [];
        foreach (self::ADDRESS as $field => $column) {
            $address[$field] = (string) ($r[$column] ?? '');
        }
        $birth = $r['birth_date'] ?? null;
        $caretaker = $r['caretaker_id'] ?? null;
        return [
            'id' => (int) $r['id'],
            'first_name' => (string) $r['first_name'],
            'last_name' => (string) $r['last_name'],
            'address' => $address['street'] === '' && $address['postal_code'] === '' && $address['city'] === '' ? null : $address,
            'phone' => (string) $r['phone'],
            'email' => (string) $r['email'],
            // MySQL liefert JJJJ-MM-TT, SQLite den gespeicherten Text
            'birth_date' => $birth === null || $birth === '' ? null : substr((string) $birth, 0, 10),
            'caretaker_id' => $caretaker === null || $caretaker === '' ? null : (int) $caretaker,
            'updated_at' => (string) $r['updated_at'],
        ];
    }
}
