<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Bilder in formatierten Inhalten (Issue #172), als Base64 in der Datenbank wie die Fotos der Mitglieder: So enthält
 * die Sicherung sie, und das Löschen des Besitzers nimmt sie sicher mit. Besitzer: owner_type + owner_id
 * (RichImageService::OWNER_*), user_id hat das Bild hochgeladen. Zeiten in UTC ('Y-m-d H:i:s').
 *
 * @phpstan-type RichImage array{id: int, uid: string, owner_type: string, owner_id: int, user_id: int, mime: string, width: int, height: int, bytes: int, created_at: string}
 */
final class RichImageRepository
{
    private const INFO = 'id, uid, owner_type, owner_id, user_id, mime, width, height, bytes, created_at';

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return RichImage|null ohne die Bilddaten */
    public function find(string $uid): ?array
    {
        $row = $this->db->selectOne('SELECT ' . self::INFO . ' FROM {rich_images} WHERE uid = ?', [$uid]);
        return $row === null ? null : self::info($row);
    }

    /**
     * @param list<string> $uids
     * @return array<string, RichImage> Kennung => Bild (ohne Daten)
     */
    public function findMany(array $uids): array
    {
        if ($uids === []) {
            return [];
        }
        $out = [];
        $rows = $this->db->select('SELECT ' . self::INFO . ' FROM {rich_images} WHERE uid IN (' . implode(',', array_fill(0, count($uids), '?')) . ')', $uids);
        foreach ($rows as $r) {
            $out[(string) $r['uid']] = self::info($r);
        }
        return $out;
    }

    /** Bilddaten (Bytes) oder null. */
    public function data(string $uid): ?string
    {
        $data = $this->db->scalar('SELECT data FROM {rich_images} WHERE uid = ?', [$uid]);
        $bytes = is_string($data) ? base64_decode($data, true) : false;
        return $bytes === false ? null : $bytes;
    }

    public function insert(string $uid, string $ownerType, int $ownerId, int $userId, string $mime, int $width, int $height, string $bytes, string $now): void
    {
        $this->db->insert('rich_images', [
            'uid' => $uid, 'owner_type' => $ownerType, 'owner_id' => $ownerId, 'user_id' => $userId, 'mime' => $mime,
            'width' => $width, 'height' => $height, 'bytes' => strlen($bytes), 'data' => base64_encode($bytes), 'created_at' => $now,
        ]);
    }

    /** Kopie unter neuer Kennung für einen anderen Besitzer (ohne die Daten durch PHP zu reichen). */
    public function copy(string $uid, string $newUid, string $ownerType, int $ownerId, int $userId, string $now): bool
    {
        return $this->db->execute(
            'INSERT INTO {rich_images} (uid, owner_type, owner_id, user_id, mime, width, height, bytes, data, created_at)
             SELECT ?, ?, ?, ?, mime, width, height, bytes, data, ? FROM {rich_images} WHERE uid = ?',
            [$newUid, $ownerType, $ownerId, $userId, $now, $uid],
        ) > 0;
    }

    public function setOwner(string $uid, string $ownerType, int $ownerId): void
    {
        $this->db->update('rich_images', ['owner_type' => $ownerType, 'owner_id' => $ownerId], ['uid' => $uid]);
    }

    /** @return list<string> Kennungen der Bilder eines Besitzers */
    public function uidsOf(string $ownerType, int $ownerId): array
    {
        return array_map(static fn (array $r): string => (string) $r['uid'], $this->db->select('SELECT uid FROM {rich_images} WHERE owner_type = ? AND owner_id = ?', [$ownerType, $ownerId]));
    }

    public function deleteUid(string $uid): void
    {
        $this->db->execute('DELETE FROM {rich_images} WHERE uid = ?', [$uid]);
    }

    public function deleteOwner(string $ownerType, int $ownerId): int
    {
        return $this->db->execute('DELETE FROM {rich_images} WHERE owner_type = ? AND owner_id = ?', [$ownerType, $ownerId]);
    }

    /** @param list<int> $ownerIds */
    public function deleteOwners(string $ownerType, array $ownerIds): int
    {
        if ($ownerIds === []) {
            return 0;
        }
        return $this->db->execute('DELETE FROM {rich_images} WHERE owner_type = ? AND owner_id IN (' . implode(',', array_fill(0, count($ownerIds), '?')) . ')', [$ownerType, ...$ownerIds]);
    }

    /** Entwürfe (hochgeladen, nie gespeichert), älter als $before. */
    public function deleteDraftsBefore(string $ownerType, string $before): int
    {
        return $this->db->execute('DELETE FROM {rich_images} WHERE owner_type = ? AND created_at < ?', [$ownerType, $before]);
    }

    /**
     * @param array<string, mixed> $r
     * @return RichImage
     */
    private static function info(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'uid' => (string) $r['uid'],
            'owner_type' => (string) $r['owner_type'],
            'owner_id' => (int) $r['owner_id'],
            'user_id' => (int) $r['user_id'],
            'mime' => (string) $r['mime'],
            'width' => (int) $r['width'],
            'height' => (int) $r['height'],
            'bytes' => (int) $r['bytes'],
            'created_at' => (string) $r['created_at'],
        ];
    }
}
