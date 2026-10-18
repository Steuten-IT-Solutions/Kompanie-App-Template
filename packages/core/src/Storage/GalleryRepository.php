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

    /** @param array<string, mixed> $data */
    public function upsertAlbum(string $driveId, array $data): int
    {
        $id = $this->db->scalar('SELECT id FROM {albums} WHERE drive_id = ?', [$driveId]);
        if ($id !== null) {
            $this->db->update('albums', $data, ['id' => (int) $id]);
            return (int) $id;
        }
        return $this->db->insert('albums', ['drive_id' => $driveId] + $data);
    }

    /** @return array<string, mixed>|null */
    public function photoByDriveId(string $driveFileId): ?array
    {
        return $this->db->selectOne('SELECT * FROM {photos} WHERE drive_file_id = ?', [$driveFileId]);
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

    /** @param int[] $keep */
    public function deleteAlbumsExcept(array $keep): void
    {
        if ($keep === []) {
            $this->db->execute('DELETE FROM {albums}');
            return;
        }
        $in = implode(', ', array_map('intval', $keep));
        $this->db->execute("DELETE FROM {albums} WHERE id NOT IN ($in)");
    }

    public function countPending(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM {photos} WHERE status = ?', [self::PENDING]);
    }

    /** @return array<string, mixed>|null nächstes aufzubereitendes Bild, neueste Alben zuerst */
    public function nextPending(): ?array
    {
        return $this->db->selectOne(
            'SELECT p.* FROM {photos} p JOIN {albums} a ON a.id = p.album_id WHERE p.status = ?
             ORDER BY a.sort_date DESC, p.taken_at ASC, p.id ASC LIMIT 1',
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
