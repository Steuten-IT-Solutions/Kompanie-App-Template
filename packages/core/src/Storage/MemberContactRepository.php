<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Kontaktangaben eines Kontos, die es in WordPress und der eigenständigen Benutzertabelle nicht gibt: Handynummer und
 * Postanschrift (Domain\Address). In der Datenbank der App, damit UserData::purge sie mit dem Konto löscht und die
 * Sicherung sie enthält. Eine Zeile je Konto; sie verschwindet, wenn Handynummer und Adresse beide leer sind.
 *
 * @phpstan-type AddressRow array{street: string, house_number: string, extra: string, postal_code: string, city: string, country: string, country_name: string}
 */
final class MemberContactRepository
{
    /** Feld der Adresse => Spalte */
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

    /** Handynummer oder '' */
    public function phone(int $userId): string
    {
        return (string) ($this->db->scalar('SELECT phone FROM {member_contacts} WHERE user_id = ?', [$userId]) ?? '');
    }

    /** Leer entfernt sie. */
    public function setPhone(int $userId, string $phone, string $now): void
    {
        $this->save($userId, ['phone' => $phone], $now);
    }

    /** @return AddressRow|null null, wenn keine hinterlegt ist */
    public function address(int $userId): ?array
    {
        return $this->contact($userId)['address'];
    }

    /**
     * Handynummer und Adresse mit einer Abfrage.
     *
     * @return array{phone: string, address: AddressRow|null}
     */
    public function contact(int $userId): array
    {
        $row = $this->db->selectOne('SELECT * FROM {member_contacts} WHERE user_id = ?', [$userId]);
        return ['phone' => (string) ($row['phone'] ?? ''), 'address' => $row !== null ? self::addressOf($row) : null];
    }

    /** @param AddressRow|null $address null entfernt sie */
    public function setAddress(int $userId, ?array $address, string $now): void
    {
        $values = [];
        foreach (self::ADDRESS as $field => $column) {
            $values[$column] = $address[$field] ?? '';
        }
        $this->save($userId, $values, $now);
    }

    /**
     * Spalten ändern, die übrigen bleiben; ist danach alles leer, fällt die Zeile weg.
     *
     * @param array<string, string> $values Spalte => Wert
     */
    private function save(int $userId, array $values, string $now): void
    {
        $this->db->transaction(function () use ($userId, $values, $now): void {
            $row = $this->db->selectOne('SELECT * FROM {member_contacts} WHERE user_id = ?', [$userId]);
            $next = ['phone' => (string) ($row['phone'] ?? '')];
            foreach (self::ADDRESS as $column) {
                $next[$column] = (string) ($row[$column] ?? '');
            }
            $next = array_replace($next, $values);
            $this->db->execute('DELETE FROM {member_contacts} WHERE user_id = ?', [$userId]);
            if (array_filter($next, static fn (string $v): bool => $v !== '') !== []) {
                $this->db->insert('member_contacts', ['user_id' => $userId] + $next + ['updated_at' => $now]);
            }
        });
    }

    /**
     * @param array<string, mixed> $row
     * @return AddressRow|null
     */
    private static function addressOf(array $row): ?array
    {
        $out = [];
        foreach (self::ADDRESS as $field => $column) {
            $out[$field] = (string) ($row[$column] ?? '');
        }
        return $out['street'] === '' && $out['postal_code'] === '' && $out['city'] === '' ? null : $out;
    }
}
