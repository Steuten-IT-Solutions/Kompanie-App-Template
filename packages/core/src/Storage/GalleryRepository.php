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
     * Alle Alben mit einer Abfrage, für den Abgleich.
     *
     * @return array<string, array<string, mixed>> Drive-ID => Zeile
     */
    public function albumsByDriveId(): array
    {
        $out = [];
        foreach ($this->db->select('SELECT * FROM {albums}') as $row) {
            $out[(string) $row['drive_id']] = $row;
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    public function albumByDriveId(string $driveId): ?array
    {
        return $this->db->selectOne('SELECT * FROM {albums} WHERE drive_id = ?', [$driveId]);
    }

    /**
     * Legt ein Album an oder aktualisiert es, aber nur, wenn sich etwas geändert hat
     * ($always: Felder, die allein keinen Schreibzugriff auslösen, z. B. synced_at).
     *
     * @param array<string, mixed>|null $row vorhandene Zeile (albumsByDriveId), null = neu
     * @param array<string, mixed> $data
     * @param array<string, mixed> $always
     */
    public function saveAlbum(string $driveId, ?array $row, array $data, array $always = []): int
    {
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

    /** @param int[] $ids Alben, die es in Drive nicht mehr gibt */
    public function deleteAlbums(array $ids): void
    {
        if ($ids !== []) {
            $this->db->execute('DELETE FROM {albums} WHERE id IN (' . implode(', ', array_map('intval', $ids)) . ')');
        }
    }

    /** Wie viele fehlgeschlagene Bilder retryFailed erneut vormerken würde. */
    public function countRetryable(int $max): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM {photos} WHERE status = ? AND attempts < ?', [self::FAILED, $max]);
    }

    /** Fehlgeschlagene Bilder für einen neuen Versuch vormerken, solange es weniger als $max Versuche waren. */
    public function retryFailed(int $max): int
    {
        return $this->db->execute('UPDATE {photos} SET status = ? WHERE status = ? AND attempts < ?', [self::PENDING, self::FAILED, $max]);
    }

    /**
     * Bilder, die sich nicht aufbereiten ließen, mit Album und Fehlermeldung (Einstellungen → Galerie), neueste Alben
     * zuerst.
     *
     * @return array<int, array<string, mixed>>
     */
    public function failedPhotos(int $limit): array
    {
        return $this->db->select(
            'SELECT p.id, p.name, p.drive_file_id, p.is_intern, p.error, p.attempts, a.year AS album_year, a.title AS album_title
             FROM {photos} p LEFT JOIN {albums} a ON a.id = p.album_id WHERE p.status = ?
             ORDER BY a.year DESC, a.sort_date DESC, p.name ASC, p.id ASC LIMIT ' . max(1, $limit),
            [self::FAILED],
        );
    }

    /** Alle fehlgeschlagenen Bilder wieder einreihen, auch nach den Versuchen (Knopf „Erneut versuchen“). */
    public function resetFailed(): int
    {
        return $this->db->execute('UPDATE {photos} SET status = ?, attempts = 0, error = ? WHERE status = ?', [self::PENDING, '', self::FAILED]);
    }

    public function countFailed(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM {photos} WHERE status = ?', [self::FAILED]);
    }

    public function countPending(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM {photos} WHERE status = ?', [self::PENDING]);
    }

    /**
     * @param int[] $skip in diesem Lauf schon versucht
     * @return array<string, mixed>|null nächstes aufzubereitendes Bild: wenigste Versuche, dann neueste Alben zuerst
     */
    public function nextPending(array $skip = []): ?array
    {
        $not = $skip === [] ? '' : ' AND p.id NOT IN (' . implode(', ', array_map('intval', $skip)) . ')';
        return $this->db->selectOne(
            "SELECT p.* FROM {photos} p JOIN {albums} a ON a.id = p.album_id WHERE p.status = ?$not
             ORDER BY p.attempts ASC, a.sort_date DESC, p.taken_at ASC, p.id ASC LIMIT 1",
            [self::PENDING],
        );
    }

    /**
     * Alben mit Anzahl sichtbarer Bilder und Videos (video_cnt) und Titelbild, in einer Abfrage (keine je Album).
     * Titelbild: öffentlich vor intern, Bilder vor Videos, fertige zuerst, dann nach Aufnahmezeit und Name.
     * Ohne Fensterfunktionen (ältere MySQL-Versionen): Die ID kommt aus einer Unterabfrage je
     * Zeile, die Spalten des Titelbilds aus einem Join darauf.
     *
     * @return array<int, array<string, mixed>> mit cover_id, cover_file_key, cover_is_intern,
     *         cover_album_id, cover_status, cover_media (NULL ohne Titelbild)
     */
    public function albumSummaries(bool $member): array
    {
        $vis = $member ? '' : ' AND p.is_intern = 0';
        $coverVis = $member ? '' : ' AND c.is_intern = 0';
        return $this->db->select(
            "SELECT s.*, ph.file_key AS cover_file_key, ph.is_intern AS cover_is_intern, ph.album_id AS cover_album_id, ph.status AS cover_status, ph.media AS cover_media
             FROM (
                SELECT a.id, a.year, a.title, a.sort_date, COUNT(p.id) AS cnt, SUM(p.is_intern) AS intern_cnt,
                    SUM(CASE WHEN p.media = 'video' THEN 1 ELSE 0 END) AS video_cnt,
                    (SELECT c.id FROM {photos} c WHERE c.album_id = a.id AND c.status <> ?$coverVis
                     ORDER BY c.is_intern ASC, (c.media = 'image') DESC, (c.status = 'ready') DESC, c.taken_at ASC, c.name ASC LIMIT 1) AS cover_id
                FROM {albums} a JOIN {photos} p ON p.album_id = a.id
                WHERE p.status <> ?$vis
                GROUP BY a.id, a.year, a.title, a.sort_date
             ) s
             LEFT JOIN {photos} ph ON ph.id = s.cover_id
             ORDER BY s.year DESC, s.sort_date DESC",
            [self::FAILED, self::FAILED],
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
