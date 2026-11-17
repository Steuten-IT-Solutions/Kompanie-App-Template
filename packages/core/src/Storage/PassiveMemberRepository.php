<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Passive (Bereich passive, Domain\PassiveMemberService): eigene Einträge ohne Konto. Name, Postanschrift (wie
 * MemberContactRepository), Telefon, E-Mail, Geburtsdatum, Eintritt (freier Text), Bemerkung und optional ein Mitglied als
 * Betreuer.
 *
 * @phpstan-type AddressRow array{street: string, house_number: string, extra: string, postal_code: string, city: string, country: string, country_name: string}
 * @phpstan-type PassiveRow array{id: int, first_name: string, last_name: string, address: AddressRow|null, phone: string, email: string, birth_date: string|null, caretaker_id: int|null, joined: string, note: string, birthday_reminded: string, updated_at: string}
 * @phpstan-type PaymentRow array{id: int, passive_id: int, year: int, amount_cents: int, paid_on: string, method: string}
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

    /**
     * Zahlungen mehrerer Passiver auf einmal eintragen (Import), zusammen mit dem Anlegen in einer Transaktion.
     *
     * @param list<array{values: array<string, string|int|null>, payment: array{year: int, amount_cents: int, paid_on: string, method: string}|null}> $entries
     * @return list<int> IDs der neuen Passiven
     */
    public function insertMany(array $entries, int $by, string $now): array
    {
        $ids = $this->db->transaction(function () use ($entries, $by, $now): array {
            $ids = [];
            foreach ($entries as $e) {
                $id = $this->insert($e['values'], $by, $now);
                if ($e['payment'] !== null) {
                    $p = $e['payment'];
                    $this->insertPayment($id, $p['year'], $p['amount_cents'], $p['paid_on'], $p['method'], $by, $now);
                }
                $ids[] = $id;
            }
            return $ids;
        });
        /** @var list<int> $ids */
        return $ids;
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

    /**
     * Erinnerung an den Geburtstag an diesem Tag (JJJJ-MM-TT) beanspruchen: nur der erste Lauf bekommt true, damit
     * wiederholte oder gleichzeitige Läufe nicht doppelt erinnern.
     */
    public function claimBirthday(int $id, string $date): bool
    {
        return $this->db->execute('UPDATE {passive_members} SET birthday_reminded = ? WHERE id = ? AND birthday_reminded <> ?', [$date, $id, $date]) > 0;
    }

    /** Löscht den Passiven mit seinen Zahlungen. */
    public function delete(int $id): void
    {
        $this->db->transaction(function () use ($id): void {
            $this->db->execute('DELETE FROM {passive_payments} WHERE passive_id = ?', [$id]);
            $this->db->execute('DELETE FROM {passive_members} WHERE id = ?', [$id]);
        });
    }

    /**
     * Zahlungen der Beiträge, je Passivem nach Jahr absteigend.
     *
     * @param list<int>|null $passiveIds null = alle
     * @return array<int, list<PaymentRow>> ID des Passiven => Zahlungen
     */
    public function payments(?array $passiveIds = null): array
    {
        if ($passiveIds === []) {
            return [];
        }
        $where = $passiveIds === null ? '' : ' WHERE passive_id IN (' . implode(', ', array_fill(0, count($passiveIds), '?')) . ')';
        $out = [];
        foreach ($this->db->select('SELECT * FROM {passive_payments}' . $where . ' ORDER BY year DESC, id ASC', $passiveIds ?? []) as $r) {
            $row = self::paymentRow($r);
            $out[$row['passive_id']][] = $row;
        }
        return $out;
    }

    /** @return PaymentRow|null */
    public function findPayment(int $id): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM {passive_payments} WHERE id = ?', [$id]);
        return $row === null ? null : self::paymentRow($row);
    }

    public function hasPayment(int $passiveId, int $year, ?int $exceptId = null): bool
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM {passive_payments} WHERE passive_id = ? AND year = ? AND id <> ?', [$passiveId, $year, $exceptId ?? 0]) > 0;
    }

    public function insertPayment(int $passiveId, int $year, int $cents, string $paidOn, string $method, int $by, string $now): int
    {
        return $this->db->insert('passive_payments', [
            'passive_id' => $passiveId, 'year' => $year, 'amount_cents' => $cents, 'paid_on' => $paidOn, 'method' => $method,
            'created_by' => $by, 'created_at' => $now, 'updated_by' => $by, 'updated_at' => $now,
        ]);
    }

    /** @param array<string, string|int> $values year, amount_cents, paid_on, method */
    public function updatePayment(int $id, array $values, int $by, string $now): void
    {
        if ($values !== []) {
            $this->db->update('passive_payments', $values + ['updated_by' => $by, 'updated_at' => $now], ['id' => $id]);
        }
    }

    public function deletePayment(int $id): void
    {
        $this->db->execute('DELETE FROM {passive_payments} WHERE id = ?', [$id]);
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
     * @return PaymentRow
     */
    private static function paymentRow(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'passive_id' => (int) $r['passive_id'],
            'year' => (int) $r['year'],
            'amount_cents' => (int) $r['amount_cents'],
            'paid_on' => substr((string) $r['paid_on'], 0, 10),
            'method' => (string) ($r['method'] ?? ''),
        ];
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
            'joined' => (string) ($r['joined'] ?? ''),
            'note' => (string) ($r['note'] ?? ''),
            'birthday_reminded' => (string) ($r['birthday_reminded'] ?? ''),
            'updated_at' => (string) $r['updated_at'],
        ];
    }
}
