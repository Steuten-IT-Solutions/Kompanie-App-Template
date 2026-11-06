<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Sponsoren (Issue #279): Name, Link, Reihenfolge und das Logo (in der Datenbank, wie die Fotos der Mitglieder; so
 * enthält es die Sicherung). Listen laden das Logo nicht mit, nur seine Art und Fassung.
 *
 * @phpstan-type Sponsor array{id: int, name: string, url: string, sort: int, logo_type: string, logo_version: string}
 */
final class SponsorRepository
{
    private const FIELDS = 'id, name, url, sort, logo_type, logo_version';

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return list<Sponsor> nach Reihenfolge */
    public function all(): array
    {
        return array_values(array_map(self::sponsor(...), $this->db->select('SELECT ' . self::FIELDS . ' FROM {sponsors} ORDER BY sort ASC, id ASC')));
    }

    /** @return Sponsor|null */
    public function find(int $id): ?array
    {
        $row = $this->db->selectOne('SELECT ' . self::FIELDS . ' FROM {sponsors} WHERE id = ?', [$id]);
        return $row === null ? null : self::sponsor($row);
    }

    /** Logo: PNG-Bytes bzw. SVG-Text, oder null ohne. */
    public function logo(int $id): ?string
    {
        $row = $this->db->selectOne('SELECT logo_type, logo FROM {sponsors} WHERE id = ?', [$id]);
        if ($row === null || !is_string($row['logo'] ?? null) || $row['logo'] === '') {
            return null;
        }
        if ($row['logo_type'] === 'png') {
            $bytes = base64_decode($row['logo'], true);
            return $bytes === false || $bytes === '' ? null : $bytes;
        }
        return $row['logo_type'] === 'svg' ? $row['logo'] : null;
    }

    public function insert(string $name, string $url, int $sort, string $now): int
    {
        return $this->db->insert('sponsors', ['name' => $name, 'url' => $url, 'sort' => $sort, 'logo_type' => '', 'logo_version' => '', 'logo' => null, 'updated_at' => $now]);
    }

    /** @param array{name?: string, url?: string, sort?: int} $data */
    public function update(int $id, array $data, string $now): void
    {
        if ($data !== []) {
            $this->db->update('sponsors', $data + ['updated_at' => $now], ['id' => $id]);
        }
    }

    /** Logo setzen ($type png mit PNG-Bytes, svg mit SVG-Text) oder mit $type '' entfernen. */
    public function saveLogo(int $id, string $type, string $logo, string $version, string $now): void
    {
        $this->db->update('sponsors', [
            'logo_type' => $type,
            'logo_version' => $type === '' ? '' : $version,
            'logo' => $type === '' ? null : ($type === 'png' ? base64_encode($logo) : $logo),
            'updated_at' => $now,
        ], ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM {sponsors} WHERE id = ?', [$id]);
    }

    public function maxSort(): int
    {
        return (int) $this->db->scalar('SELECT COALESCE(MAX(sort), 0) FROM {sponsors}');
    }

    /**
     * @param array<string, mixed> $r
     * @return Sponsor
     */
    private static function sponsor(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'url' => (string) $r['url'],
            'sort' => (int) $r['sort'],
            'logo_type' => (string) $r['logo_type'],
            'logo_version' => (string) $r['logo_version'],
        ];
    }
}
