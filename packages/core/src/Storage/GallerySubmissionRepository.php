<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Storage\Sql\Connection;

/** Eingereichte Bilder der Galerie (Issue #286), Tabelle gallery_submissions. */
final class GallerySubmissionRepository
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    /** Spalten für Listen: ohne die Vorschau (thumb), die nur einzeln mitkommt. */
    private const LIST_COLUMNS = 's.id, s.user_id, s.album_id, s.file_key, s.name, s.width, s.height, s.bytes, s.status, s.consent_at, s.created_at, s.reviewed_by, s.reviewed_at, s.reason, s.photo_id, a.title AS album_title, a.year AS album_year';

    public function __construct(private readonly Connection $db)
    {
    }

    /** @param array<string, mixed> $data */
    public function insert(array $data): int
    {
        return $this->db->insert('gallery_submissions', $data);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->db->update('gallery_submissions', $data, ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM {gallery_submissions} WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null mit Vorschau */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT s.*, a.title AS album_title, a.year AS album_year FROM {gallery_submissions} s LEFT JOIN {albums} a ON a.id = s.album_id WHERE s.id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> Einreichungen einer Person, neueste zuerst, mit Vorschau */
    public function ofUser(int $userId, int $limit = 200): array
    {
        return array_values($this->db->select(
            'SELECT s.*, a.title AS album_title, a.year AS album_year FROM {gallery_submissions} s LEFT JOIN {albums} a ON a.id = s.album_id
             WHERE s.user_id = ? ORDER BY s.id DESC LIMIT ' . $limit,
            [$userId],
        ));
    }

    /**
     * Einreichungen mit diesem Status, mit Vorschau; offene älteste zuerst, sonst neueste. photo_is_intern: ob das
     * Bild in der Galerie intern ist (NULL ohne Bild).
     *
     * @return list<array<string, mixed>>
     */
    public function withStatus(string $status, int $limit = 200): array
    {
        $order = $status === self::PENDING ? 'ASC' : 'DESC';
        return array_values($this->db->select(
            'SELECT s.*, a.title AS album_title, a.year AS album_year, p.is_intern AS photo_is_intern FROM {gallery_submissions} s
             LEFT JOIN {albums} a ON a.id = s.album_id LEFT JOIN {photos} p ON p.id = s.photo_id
             WHERE s.status = ? ORDER BY s.id ' . $order . ' LIMIT ' . $limit,
            [$status],
        ));
    }

    public function countPending(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM {gallery_submissions} WHERE status = ?', [self::PENDING]);
    }

    /** Hat die Person seit $since schon etwas eingereicht (außer $exceptId)? */
    public function submittedSince(int $userId, string $since, int $exceptId): bool
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM {gallery_submissions} WHERE user_id = ? AND created_at >= ? AND id <> ?', [$userId, $since, $exceptId]) > 0;
    }

    /** @return list<array<string, mixed>> ohne Vorschau */
    public function pendingOfUser(int $userId): array
    {
        return array_values($this->db->select('SELECT ' . self::LIST_COLUMNS . ' FROM {gallery_submissions} s LEFT JOIN {albums} a ON a.id = s.album_id WHERE s.user_id = ? AND s.status = ?', [$userId, self::PENDING]));
    }

    /** @return list<array<string, mixed>> ohne Vorschau */
    public function ofAlbum(int $albumId): array
    {
        return array_values($this->db->select('SELECT ' . self::LIST_COLUMNS . ' FROM {gallery_submissions} s LEFT JOIN {albums} a ON a.id = s.album_id WHERE s.album_id = ?', [$albumId]));
    }

    /** @return array<string, mixed>|null die freigegebene Einreichung zu einem Bild */
    public function byPhoto(int $photoId): ?array
    {
        return $this->db->selectOne('SELECT ' . self::LIST_COLUMNS . ' FROM {gallery_submissions} s LEFT JOIN {albums} a ON a.id = s.album_id WHERE s.photo_id = ?', [$photoId]);
    }

    /** @return list<string> Schlüssel der Dateien offener Einreichungen */
    public function pendingKeys(): array
    {
        return array_map(static fn (array $r): string => (string) $r['file_key'], $this->db->select('SELECT file_key FROM {gallery_submissions} WHERE status = ?', [self::PENDING]));
    }
}
