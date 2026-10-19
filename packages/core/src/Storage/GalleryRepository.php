<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Storage\Sql\Connection;

/** Index der Galerie: Alben und Bilder aus Google Drive. */
final class GalleryRepository
{
    public const PENDING = 'pending';
    public const READY = 'ready';
    public const FAILED = 'error';

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Legt ein Album an oder aktualisiert es, aber nur, wenn sich etwas geändert hat
     * ($always: Felder, die allein keinen Schreibzugriff auslösen, z. B. synced_at).
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $always
     */
    public function upsertAlbum(string $driveId, array $data, array $always = []): int
    {
        $row = $this->db->selectOne('SELECT * FROM {albums} WHERE drive_id = ?', [$driveId]);
        if ($row !== null) {
            if (self::changed($row, $data)) {
                $this->db->update('albums', $data + $always, ['id' => (int) $row['id']]);
            }
            return (int) $row['id'];
        }
        return $this->db->insert('albums', ['drive_id' => $driveId] + $data + $always);
    }

    /**
     * Unterscheidet sich ein Feld der Zeile? Zahlen und Texte vergleicht die Datenbank je nach
     * Treiber unterschiedlich typisiert; deshalb als Text verglichen.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $data
     */
    public static function changed(array $row, array $data): bool
    {
        foreach ($data as $k => $v) {
            if (!array_key_exists($k, $row) || ($row[$k] === null) !== ($v === null) || (string) $row[$k] !== (string) $v) {
                return true;
            }
        }
        return false;
    }

    /**
     * Vorhandene Bilder zu Drive-Dateien, mit wenigen Abfragen (in Blöcken zu 500).
     *
     * @param string[] $driveFileIds
     * @return array<string, array<string, mixed>> Drive-ID => Zeile
     */
    public function photosByDriveIds(array $driveFileIds): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($driveFileIds)), 500) as $chunk) {
            $in = implode(', ', array_fill(0, count($chunk), '?'));
            foreach ($this->db->select("SELECT * FROM {photos} WHERE drive_file_id IN ($in)", $chunk) as $row) {
                $out[(string) $row['drive_file_id']] = $row;
            }
        }
        return $out;
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        return $this->db->transaction($fn);
    }

    /** @return array<string, mixed>|null */
    public function photo(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM {photos} WHERE id = ?', [$id]);
    }

    /** @param array<string, mixed> $data */
    public function insertPhoto(array $data): int
    {
        return $this->db->insert('photos', $data);
    }

    /** @param array<string, mixed> $data */
    public function updatePhoto(int $id, array $data): void
    {
        $this->db->update('photos', $data, ['id' => $id]);
    }

    /**
     * @param int[] $keep
     * @return array<int, array<string, mixed>> Bilder, die nicht in $keep sind
     */
    public function photosExcept(array $keep): array
    {
        if ($keep === []) {
            return $this->db->select('SELECT * FROM {photos}');
        }
        $in = implode(', ', array_map('intval', $keep));
        return $this->db->select("SELECT * FROM {photos} WHERE id NOT IN ($in)");
    }

    public function deletePhoto(int $id): void
    {
        $this->db->execute('DELETE FROM {photos} WHERE id = ?', [$id]);
    }

    /**
     * Erst zählen, dann löschen: Ohne Treffer bleibt der Abgleich ohne Schreibzugriff
     * (in SQLite bräuchte auch ein DELETE ohne Treffer die Schreibsperre).
     *
     * @param int[] $keep
     */
    public function deleteAlbumsExcept(array $keep): void
    {
        $where = $keep === [] ? '' : ' WHERE id NOT IN (' . implode(', ', array_map('intval', $keep)) . ')';
        if ((int) $this->db->scalar('SELECT COUNT(*) FROM {albums}' . $where) > 0) {
            $this->db->execute('DELETE FROM {albums}' . $where);
        }
    }

    /** Fehlgeschlagene Bilder für einen neuen Versuch vormerken, solange es weniger als $max Versuche waren. */
    public function retryFailed(int $max): int
    {
        if ((int) $this->db->scalar('SELECT COUNT(*) FROM {photos} WHERE status = ? AND attempts < ?', [self::FAILED, $max]) === 0) {
            return 0;
        }
        return $this->db->execute('UPDATE {photos} SET status = ? WHERE status = ? AND attempts < ?', [self::PENDING, self::FAILED, $max]);
    }

    public function countFailed(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM {photos} WHERE status = ?', [self::FAILED]);
    }

    public function countPending(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM {photos} WHERE status = ?', [self::PENDING]);
    }

    /** @return array<string, mixed>|null nächstes aufzubereitendes Bild: wenigste Versuche, dann neueste Alben zuerst */
    public function nextPending(): ?array
    {
        return $this->db->selectOne(
            'SELECT p.* FROM {photos} p JOIN {albums} a ON a.id = p.album_id WHERE p.status = ?
             ORDER BY p.attempts ASC, a.sort_date DESC, p.taken_at ASC, p.id ASC LIMIT 1',
            [self::PENDING],
        );
    }

    /** @return array<int, array<string, mixed>> Alben mit Anzahl sichtbarer Bilder */
    public function albumSummaries(bool $member): array
    {
        $vis = $member ? '' : ' AND p.is_intern = 0';
        return $this->db->select(
            "SELECT a.id, a.year, a.title, a.sort_date, COUNT(p.id) AS cnt, SUM(p.is_intern) AS intern_cnt
             FROM {albums} a JOIN {photos} p ON p.album_id = a.id
             WHERE p.status <> ?$vis
             GROUP BY a.id, a.year, a.title, a.sort_date
             ORDER BY a.year DESC, a.sort_date DESC",
            [self::FAILED],
        );
    }

    /** @return array<string, mixed>|null Titelbild: öffentlich vor intern, fertige zuerst */
    public function cover(int $albumId, bool $member): ?array
    {
        $vis = $member ? '' : ' AND p.is_intern = 0';
        return $this->db->selectOne(
            "SELECT * FROM {photos} p WHERE p.album_id = ? AND p.status <> ?$vis
             ORDER BY p.is_intern ASC, (p.status = 'ready') DESC, p.taken_at ASC, p.name ASC LIMIT 1",
            [$albumId, self::FAILED],
        );
    }

    /** @return array<string, mixed>|null */
    public function album(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM {albums} WHERE id = ?', [$id]);
    }

    /** @return array<int, array<string, mixed>> sichtbare Bilder, nach Aufnahmezeit */
    public function albumPhotos(int $albumId, bool $member): array
    {
        $vis = $member ? '' : ' AND p.is_intern = 0';
        return $this->db->select(
            "SELECT * FROM {photos} p WHERE p.album_id = ? AND p.status <> ?$vis
             ORDER BY p.taken_at IS NULL, p.taken_at ASC, p.name ASC",
            [$albumId, self::FAILED],
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function allPhotos(): array
    {
        return $this->db->select('SELECT * FROM {photos}');
    }

    public function clear(): void
    {
        $this->db->execute('DELETE FROM {photos}');
        $this->db->execute('DELETE FROM {albums}');
    }
}
