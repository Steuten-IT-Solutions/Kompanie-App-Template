<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Domain\LogoImage;
use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Kompanieliste (Issue #280): Kompanien mit Logo (in der Datenbank wie bei den Sponsoren, so enthält es die Sicherung)
 * und die Zuordnung von Personen (member_companies, mehrere je Person möglich). Listen laden das Logo nicht mit.
 *
 * @phpstan-type Company array{id: int, name: string, short_name: string, founded_year: int|null, description: string, website_url: string, app_url: string, office_group_id: int|null, sort: int, active: bool, logo_type: string, logo_version: string}
 */
final class CompanyRepository
{
    private const FIELDS = 'id, name, short_name, founded_year, description, website_url, app_url, office_group_id, sort, active, logo_type, logo_version';

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return list<Company> nach Reihenfolge */
    public function all(): array
    {
        return array_values(array_map(self::company(...), $this->db->select('SELECT ' . self::FIELDS . ' FROM {companies} ORDER BY sort ASC, id ASC')));
    }

    /** @return Company|null */
    public function find(int $id): ?array
    {
        $row = $this->db->selectOne('SELECT ' . self::FIELDS . ' FROM {companies} WHERE id = ?', [$id]);
        return $row === null ? null : self::company($row);
    }

    /** Logo: PNG-Bytes bzw. SVG-Text, oder null ohne. */
    public function logo(int $id): ?string
    {
        $row = $this->db->selectOne('SELECT logo_type, logo FROM {companies} WHERE id = ?', [$id]);
        return $row === null ? null : LogoImage::decodeStored($row['logo_type'], $row['logo'] ?? null);
    }

    /** @param array<string, mixed> $data Felder außer Logo */
    public function insert(array $data, string $now): int
    {
        return $this->db->insert('companies', $data + ['logo_type' => '', 'logo_version' => '', 'logo' => null, 'updated_at' => $now]);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data, string $now): void
    {
        if (isset($data['active'])) {
            $data['active'] = $data['active'] ? 1 : 0;
        }
        if ($data !== []) {
            $this->db->update('companies', $data + ['updated_at' => $now], ['id' => $id]);
        }
    }

    /** Logo setzen ($type png mit PNG-Bytes, svg mit SVG-Text) oder mit $type '' entfernen. */
    public function saveLogo(int $id, string $type, string $logo, string $version, string $now): void
    {
        $this->db->update('companies', [
            'logo_type' => $type,
            'logo_version' => $type === '' ? '' : $version,
            'logo' => LogoImage::encode($type, $logo),
            'updated_at' => $now,
        ], ['id' => $id]);
    }

    /** Kompanie samt Zuordnungen löschen. */
    public function delete(int $id): void
    {
        $this->db->transaction(function () use ($id): void {
            $this->db->execute('DELETE FROM {member_companies} WHERE company_id = ?', [$id]);
            $this->db->execute('DELETE FROM {companies} WHERE id = ?', [$id]);
        });
    }

    public function maxSort(): int
    {
        return (int) $this->db->scalar('SELECT COALESCE(MAX(sort), 0) FROM {companies}');
    }

    /** Verknüpfungen mit einer gelöschten Gruppe der Ämter lösen. */
    public function unlinkOfficeGroup(int $groupId): void
    {
        $this->db->execute('UPDATE {companies} SET office_group_id = NULL WHERE office_group_id = ?', [$groupId]);
    }

    /** @return array<int, list<int>> Benutzer-ID => Kompanie-IDs, in einer Abfrage für alle */
    public function assignments(): array
    {
        $out = [];
        foreach ($this->db->select('SELECT user_id, company_id FROM {member_companies} ORDER BY user_id, company_id') as $r) {
            $out[(int) $r['user_id']][] = (int) $r['company_id'];
        }
        return $out;
    }

    /** @return list<int> */
    public function companyIdsOf(int $userId): array
    {
        return array_values(array_map(static fn (array $r): int => (int) $r['company_id'], $this->db->select('SELECT company_id FROM {member_companies} WHERE user_id = ?', [$userId])));
    }

    /**
     * Kompanien einer Person in der Reihenfolge der Liste, in einer Abfrage.
     *
     * @return list<array{id: int, name: string, short_name: string, active: bool}>
     */
    public function refsOf(int $userId): array
    {
        return array_values(array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'short_name' => (string) $r['short_name'],
            'active' => (bool) $r['active'],
        ], $this->db->select('SELECT c.id, c.name, c.short_name, c.active FROM {companies} c JOIN {member_companies} m ON m.company_id = c.id WHERE m.user_id = ? ORDER BY c.sort ASC, c.id ASC', [$userId])));
    }

    /** @return list<int> */
    public function memberIdsOf(int $companyId): array
    {
        return array_values(array_map(static fn (array $r): int => (int) $r['user_id'], $this->db->select('SELECT user_id FROM {member_companies} WHERE company_id = ?', [$companyId])));
    }

    /**
     * Kompanien einer Person ersetzen, in einer Transaktion. Bestehende Zuordnungen behalten ihr Datum.
     *
     * @param list<int> $companyIds
     */
    public function replaceOfUser(int $userId, array $companyIds, string $now): void
    {
        $this->db->transaction(function () use ($userId, $companyIds, $now): void {
            $current = $this->companyIdsOf($userId);
            foreach (array_diff($current, $companyIds) as $id) {
                $this->db->execute('DELETE FROM {member_companies} WHERE user_id = ? AND company_id = ?', [$userId, $id]);
            }
            foreach (array_diff($companyIds, $current) as $id) {
                $this->db->insert('member_companies', ['user_id' => $userId, 'company_id' => $id, 'assigned_at' => $now]);
            }
        });
    }

    /**
     * Personen einer Kompanie ersetzen (gesammelt auf der Seite der Kompanie), in einer Transaktion.
     *
     * @param list<int> $userIds
     */
    public function replaceOfCompany(int $companyId, array $userIds, string $now): void
    {
        $this->db->transaction(function () use ($companyId, $userIds, $now): void {
            $current = $this->memberIdsOf($companyId);
            foreach (array_diff($current, $userIds) as $id) {
                $this->db->execute('DELETE FROM {member_companies} WHERE user_id = ? AND company_id = ?', [$id, $companyId]);
            }
            foreach (array_diff($userIds, $current) as $id) {
                $this->db->insert('member_companies', ['user_id' => $id, 'company_id' => $companyId, 'assigned_at' => $now]);
            }
        });
    }

    /** Zuordnungen von Konten, die es nicht mehr gibt. */
    public function forgetUser(int $userId): void
    {
        $this->db->execute('DELETE FROM {member_companies} WHERE user_id = ?', [$userId]);
    }

    /**
     * @param array<string, mixed> $r
     * @return Company
     */
    private static function company(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'short_name' => (string) $r['short_name'],
            'founded_year' => $r['founded_year'] === null ? null : (int) $r['founded_year'],
            'description' => (string) ($r['description'] ?? ''),
            'website_url' => (string) $r['website_url'],
            'app_url' => (string) $r['app_url'],
            'office_group_id' => $r['office_group_id'] === null ? null : (int) $r['office_group_id'],
            'sort' => (int) $r['sort'],
            'active' => (bool) $r['active'],
            'logo_type' => (string) $r['logo_type'],
            'logo_version' => (string) $r['logo_version'],
        ];
    }
}
