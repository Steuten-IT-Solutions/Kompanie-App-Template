<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Fotos der Mitglieder: eines je Konto, als JPEG in drei Größen und optional in Originalauflösung (Base64) in der Datenbank. So löscht UserData::purge sie
 * mit dem Konto (auch auf allen Seiten eines WordPress-Netzwerks), und die Sicherung enthält sie. version wechselt mit
 * jedem Hochladen (Adresse des Fotos); updated_at in UTC ('Y-m-d H:i:s').
 *
 * @phpstan-type PhotoInfo array{user_id: int, version: string, updated_by: int, updated_at: string}
 */
final class MemberPhotoRepository
{
    /** Größen (längere Kante in Pixeln) => Spalte; xlarge ist NULL bei Fotos, die vor Datenbankversion 18 hochgeladen wurden. */
    public const COLUMNS = [128 => 'small', 480 => 'large', 1024 => 'xlarge'];
    /** Originalauflösung (Datenbankversion 24): eigene Spalte, eigenes UPDATE; NULL, wenn es sie nicht gibt. */
    public const ORIGINAL_COLUMN = 'original';

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return PhotoInfo|null ohne die Bilder */
    public function find(int $userId): ?array
    {
        $row = $this->db->selectOne('SELECT user_id, version, updated_by, updated_at FROM {member_photos} WHERE user_id = ?', [$userId]);
        return $row === null ? null : self::info($row);
    }

    /** @return array<int, string> Benutzer-ID => Fassung, für Listen (ohne die Bilder) */
    public function versions(): array
    {
        $out = [];
        foreach ($this->db->select('SELECT user_id, version FROM {member_photos}') as $r) {
            $out[(int) $r['user_id']] = (string) $r['version'];
        }
        return $out;
    }

    /** Das Bild in einer Größe (JPEG-Bytes) oder null. */
    public function image(int $userId, int $size): ?string
    {
        $column = self::COLUMNS[$size] ?? null;
        if ($column === null) {
            return null;
        }
        return $this->column($userId, $column);
    }

    /** Das Foto in Originalauflösung (JPEG-Bytes) oder null. */
    public function original(int $userId): ?string
    {
        return $this->column($userId, self::ORIGINAL_COLUMN);
    }

    private function column(int $userId, string $column): ?string
    {
        $data = $this->db->scalar('SELECT ' . $column . ' FROM {member_photos} WHERE user_id = ?', [$userId]);
        $bytes = is_string($data) && $data !== '' ? base64_decode($data, true) : false;
        return $bytes === false || $bytes === '' ? null : $bytes;
    }

    /**
     * Anlegen oder ersetzen.
     *
     * @param array<int, string> $images Größe => JPEG-Bytes, für jede Größe aus COLUMNS
     */
    public function save(int $userId, array $images, string $version, int $by, string $now): void
    {
        $values = ['version' => $version, 'updated_by' => $by, 'updated_at' => $now];
        foreach (self::COLUMNS as $size => $column) {
            $values[$column] = base64_encode($images[$size]);
        }
        if ($this->find($userId) !== null) {
            $this->db->update('member_photos', $values, ['user_id' => $userId]);
            return;
        }
        try {
            $this->db->insert('member_photos', ['user_id' => $userId] + $values);
        } catch (\RuntimeException $e) {
            // Gleichzeitig angelegt: dann eben ersetzen.
            if ($this->find($userId) === null) {
                throw $e;
            }
            $this->db->update('member_photos', $values, ['user_id' => $userId]);
        }
    }

    /**
     * Originalauflösung setzen (null entfernt sie). Eigenes UPDATE nach save(): Die Anweisung mit dem großen Foto bleibt
     * so für sich, und scheitert sie (z. B. max_allowed_packet), sind die übrigen Größen schon gespeichert.
     */
    public function saveOriginal(int $userId, ?string $jpeg): void
    {
        $this->db->update('member_photos', [self::ORIGINAL_COLUMN => $jpeg === null ? null : base64_encode($jpeg)], ['user_id' => $userId]);
    }

    public function delete(int $userId): bool
    {
        return $this->db->execute('DELETE FROM {member_photos} WHERE user_id = ?', [$userId]) > 0;
    }

    /**
     * @param array<string, mixed> $r
     * @return PhotoInfo
     */
    private static function info(array $r): array
    {
        return [
            'user_id' => (int) $r['user_id'],
            'version' => (string) $r['version'],
            'updated_by' => (int) $r['updated_by'],
            'updated_at' => (string) $r['updated_at'],
        ];
    }
}
