<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;

/**
 * Dateianhänge am Schwarzen Brett und an E-Mails aus der Verwaltung, allgemeiner Baustein wie RichImageService.
 *
 * - Hochladen (POST /attachments, Datei Base64 im JSON) legt einen Entwurf (OWNER_DRAFT) des Kontos an. Beim Speichern
 *   einer Nachricht bzw. Mail übernimmt sie die genannten Anhänge (assign); fremde Entwürfe gehen nicht. Entwürfe, die
 *   nie gespeichert wurden, räumt cleanupDrafts() nach DRAFT_KEEP_DAYS auf.
 * - Erlaubt ist jede Datei bis DocumentService::requestLimit() (20 MB oder weniger, wenn der Server kleinere Anfragen
 *   annimmt). Der Name bleibt (ohne Pfad und Steuerzeichen), der Typ kommt aus der Endung (MIME_TYPES, sonst
 *   application/octet-stream).
 * - Dateien liegen geschützt in der Dateiablage (anhaenge/anh-<Kennung>.bin) und werden nur über
 *   Environment::attachmentUrl an Angemeldete ausgeliefert, die den Inhalt sehen dürfen, immer als Download (auch
 *   HTML oder SVG werden so nie im Browser ausgeführt).
 */
final class AttachmentService
{
    public const OWNER_DRAFT = 'draft';
    public const OWNER_BOARD = 'board';
    public const OWNER_MAIL = 'mail';
    public const DRAFT_KEEP_DAYS = 7;
    public const NAME_MAX = 200;
    private const DIR = 'anhaenge/';
    private const FILE_PATTERN = '/^anh-[0-9a-f]{32}\.bin$/';

    /** Endung => MIME-Typ für die Mail; unbekannte Endungen: application/octet-stream. */
    public const MIME_TYPES = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'heic' => 'image/heic', 'svg' => 'image/svg+xml',
        'doc' => 'application/msword', 'xls' => 'application/vnd.ms-excel', 'ppt' => 'application/vnd.ms-powerpoint',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt' => 'application/vnd.oasis.opendocument.text', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'odp' => 'application/vnd.oasis.opendocument.presentation', 'rtf' => 'application/rtf',
        'txt' => 'text/plain', 'csv' => 'text/csv', 'ics' => 'text/calendar', 'vcf' => 'text/vcard', 'html' => 'text/html', 'htm' => 'text/html',
        'zip' => 'application/zip', '7z' => 'application/x-7z-compressed', 'gz' => 'application/gzip',
        'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'wav' => 'audio/wav', 'mp4' => 'video/mp4', 'mov' => 'video/quicktime',
    ];

    public function __construct(private readonly App $app)
    {
    }

    /** Größte Datei, die der Server annimmt (für die App, vor dem Hochladen). */
    public static function maxBytes(): int
    {
        return DocumentService::maxBytes();
    }

    /**
     * Anhang hochladen (Entwurf des Kontos).
     *
     * @param mixed $file {name, data (Base64, auch als data:-Adresse)}
     * @return array{uid: string, name: string, size: int, mime: string, url: string}
     */
    public function upload(User $actor, mixed $file): array
    {
        $name = self::cleanName(is_array($file) && is_string($file['name'] ?? null) ? $file['name'] : '');
        $data = is_array($file) && is_string($file['data'] ?? null) ? $file['data'] : '';
        if (str_starts_with($data, 'data:') && ($comma = strpos($data, ',')) !== false) {
            $data = substr($data, $comma + 1);
        }
        $bytes = $data === '' ? '' : base64_decode($data, true);
        unset($data);
        if ($bytes === false || $bytes === '') {
            throw ApiError::invalid(sprintf(
                'Es ist keine Datei angekommen (leer oder größer als das, was der Server annimmt: %s).',
                DocumentService::formatBytes(self::maxBytes()),
            ));
        }
        $max = DocumentService::requestLimit();
        if (strlen($bytes) > $max) {
            throw ApiError::invalid(sprintf('„%s“ ist zu groß: Anhänge dürfen höchstens %s groß sein.', $name, DocumentService::formatBytes($max)));
        }
        $uid = bin2hex(random_bytes(16));
        $file = 'anh-' . $uid . '.bin';
        $path = $this->app->files->path(self::DIR . $file, false);
        if (@file_put_contents($path, $bytes) !== strlen($bytes)) {
            @unlink($path);
            throw new ApiError(500, 'storage_failed', 'Der Anhang konnte nicht gespeichert werden (Speicherplatz, Schreibrechte?).');
        }
        $row = [
            'uid' => $uid, 'owner_type' => self::OWNER_DRAFT, 'owner_id' => $actor->id, 'user_id' => $actor->id, 'name' => $name,
            'mime' => self::mimeOf($name), 'bytes' => strlen($bytes), 'file' => $file, 'created_at' => $this->app->time()->nowUtc(),
        ];
        $this->app->db->insert('attachments', $row);
        return $this->format($row);
    }

    /**
     * Anhänge für einen Inhalt prüfen: jede Kennung ein eigener Entwurf von $actor oder schon ein Anhang dieses Inhalts.
     *
     * @return list<array<string, mixed>> Zeilen in der angegebenen Reihenfolge
     */
    public function resolve(User $actor, mixed $uids, string $ownerType, ?int $ownerId = null): array
    {
        if ($uids === null) {
            return [];
        }
        if (!is_array($uids)) {
            throw ApiError::invalid('attachments: Liste von Kennungen erwartet.');
        }
        $out = [];
        foreach (array_values(array_unique(array_map(static fn (mixed $u): string => is_string($u) ? strtolower($u) : '', $uids))) as $uid) {
            $row = preg_match('/^[a-f0-9]{32}$/', $uid) === 1 ? $this->find($uid) : null;
            $own = $row !== null && (($row['owner_type'] === self::OWNER_DRAFT && $row['owner_id'] === $actor->id)
                || ($ownerId !== null && $row['owner_type'] === $ownerType && $row['owner_id'] === $ownerId));
            if (!$own) {
                throw ApiError::invalid('Ein Anhang ist nicht mehr vorhanden. Bitte die Datei erneut anhängen.');
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Die Anhänge eines Inhalts auf genau diese setzen: Entwürfe übernehmen, nicht mehr genannte löschen.
     *
     * @param list<array<string, mixed>> $rows aus resolve()
     * @return array{added: list<string>, removed: list<string>} Namen
     */
    public function assign(array $rows, string $ownerType, int $ownerId): array
    {
        $keep = [];
        $added = [];
        foreach ($rows as $row) {
            $keep[$row['uid']] = true;
            if ($row['owner_type'] !== $ownerType || $row['owner_id'] !== $ownerId) {
                $this->app->db->update('attachments', ['owner_type' => $ownerType, 'owner_id' => $ownerId], ['uid' => $row['uid']]);
                $added[] = (string) $row['name'];
            }
        }
        $removed = [];
        foreach ($this->of($ownerType, $ownerId) as $row) {
            if (!isset($keep[$row['uid']])) {
                $this->deleteRow($row);
                $removed[] = (string) $row['name'];
            }
        }
        return ['added' => $added, 'removed' => $removed];
    }

    /**
     * Anhänge eines Inhalts, nach dem Hochladen sortiert.
     *
     * @return list<array<string, mixed>>
     */
    public function of(string $ownerType, int $ownerId): array
    {
        return $this->many($ownerType, [$ownerId])[$ownerId] ?? [];
    }

    /**
     * Anhänge mehrerer Inhalte mit einer Abfrage.
     *
     * @param list<int> $ownerIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function many(string $ownerType, array $ownerIds): array
    {
        if ($ownerIds === []) {
            return [];
        }
        $rows = $this->app->db->select(
            'SELECT * FROM {attachments} WHERE owner_type = ? AND owner_id IN (' . implode(',', array_fill(0, count($ownerIds), '?')) . ') ORDER BY id',
            [$ownerType, ...$ownerIds],
        );
        $out = [];
        foreach ($rows as $r) {
            $r = self::typed($r);
            $out[$r['owner_id']][] = $r;
        }
        return $out;
    }

    /**
     * Für die App.
     *
     * @param array<string, mixed> $row
     * @return array{uid: string, name: string, size: int, mime: string, url: string}
     */
    public function format(array $row): array
    {
        return ['uid' => (string) $row['uid'], 'name' => (string) $row['name'], 'size' => (int) $row['bytes'], 'mime' => (string) $row['mime'], 'url' => $this->app->env->attachmentUrl((string) $row['uid'])];
    }

    /**
     * Für die Mail: Name, Typ und Pfad der Datei (fehlende Dateien fehlen).
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array{name: string, mime: string, path: string}>
     */
    public function forMail(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $path = $this->pathOf($row);
            if ($path !== null) {
                $out[] = ['name' => (string) $row['name'], 'mime' => (string) $row['mime'], 'path' => $path];
            }
        }
        return $out;
    }

    /** Alle Anhänge eines oder mehrerer Inhalte löschen (samt Dateien). */
    public function deleteOwners(string $ownerType, array $ownerIds): void
    {
        foreach ($this->many($ownerType, array_values($ownerIds)) as $rows) {
            foreach ($rows as $row) {
                $this->deleteRow($row);
            }
        }
    }

    /** Nie gespeicherte Entwürfe aufräumen (Aufgabe board_cleanup). */
    public function cleanupDrafts(): int
    {
        $before = gmdate('Y-m-d H:i:s', $this->app->time()->timestamp() - self::DRAFT_KEEP_DAYS * 86400);
        $rows = $this->app->db->select('SELECT * FROM {attachments} WHERE owner_type = ? AND created_at < ?', [self::OWNER_DRAFT, $before]);
        foreach ($rows as $row) {
            $this->deleteRow(self::typed($row));
        }
        return count($rows);
    }

    /** Entwürfe eines Kontos samt Dateien löschen (Konto gelöscht, App::userDeleted). */
    public function deleteDraftsOf(int $userId): void
    {
        foreach ($this->app->db->select('SELECT * FROM {attachments} WHERE owner_type = ? AND owner_id = ?', [self::OWNER_DRAFT, $userId]) as $row) {
            $this->deleteRow(self::typed($row));
        }
    }

    /**
     * Darf $viewer den Anhang sehen? Entwürfe nur ihr Konto, am Brett wie die Nachricht, an Mails wer sie versendet.
     *
     * @param array<string, mixed> $row
     */
    public function canView(?User $viewer, array $row): bool
    {
        if ($viewer === null) {
            return false;
        }
        return match ($row['owner_type']) {
            self::OWNER_DRAFT => $viewer->id === $row['owner_id'],
            self::OWNER_BOARD => $this->app->board()->canSeeImage($viewer, (int) $row['owner_id']),
            self::OWNER_MAIL => $this->app->mailings()->canSeeImage($viewer, (int) $row['owner_id']),
            default => false,
        };
    }

    /**
     * Für die Auslieferung über attachmentUrl(): Status, Header und Pfad der Datei (beide Adapter gleich).
     *
     * @return array{status: int, headers: array<string, string>, path?: string}
     */
    public function forDelivery(?User $viewer, mixed $uid): array
    {
        $deny = static fn (int $status): array => ['status' => $status, 'headers' => ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']];
        if ($this->app->maintenance()->blocks($viewer)) {
            return $deny(503);
        }
        if ($viewer === null) {
            return $deny(401);
        }
        $uid = is_string($uid) ? strtolower($uid) : '';
        $row = preg_match('/^[a-f0-9]{32}$/', $uid) === 1 ? $this->find($uid) : null;
        if ($row === null) {
            return $deny(404);
        }
        if (!$this->canView($viewer, $row)) {
            return $deny(403);
        }
        $path = $this->pathOf($row);
        if ($path === null) {
            return $deny(404);
        }
        $name = (string) $row['name'];
        // Bekannte harmlose Typen mit ihrem Typ (der Browser bietet „Öffnen“ passend an), alles andere als Bytes.
        $safe = in_array($row['mime'], ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
        return ['status' => 200, 'headers' => [
            'Content-Type' => $safe ? (string) $row['mime'] : 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="' . self::asciiName($name) . '"; filename*=UTF-8\'\'' . rawurlencode($name),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => 'sandbox; default-src \'none\'',
            // Eine Kennung hat immer denselben Inhalt; Rechte können sich ändern, deshalb nur prüfen lassen (ETag).
            'Cache-Control' => $this->app->preview()->cacheControl('private, no-cache'),
            'X-Robots-Tag' => 'noindex',
            'ETag' => '"' . $uid . '"',
        ], 'path' => $path];
    }

    /** @return array<string, mixed>|null */
    public function find(string $uid): ?array
    {
        $row = $this->app->db->selectOne('SELECT * FROM {attachments} WHERE uid = ?', [$uid]);
        return $row === null ? null : self::typed($row);
    }

    public static function mimeOf(string $name): string
    {
        return self::MIME_TYPES[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    }

    /** Dateiname ohne Pfad, Steuer- und Trennzeichen, höchstens NAME_MAX Zeichen (Endung bleibt). */
    public static function cleanName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F"<>:|?*]+/u', '', $name));
        $name = trim($name, ". \t");
        if ($name === '' || !mb_check_encoding($name, 'UTF-8')) {
            return 'Anhang';
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $keep = $ext !== '' && mb_strlen($ext) < 12 ? '.' . $ext : '';
            $name = mb_substr($name, 0, self::NAME_MAX - mb_strlen($keep)) . $keep;
        }
        return $name;
    }

    /** @param array<string, mixed> $row */
    private function pathOf(array $row): ?string
    {
        if (preg_match(self::FILE_PATTERN, (string) $row['file']) !== 1) {
            return null;
        }
        $path = $this->app->files->path(self::DIR . $row['file'], false);
        return is_file($path) ? $path : null;
    }

    /** @param array<string, mixed> $row */
    private function deleteRow(array $row): void
    {
        $this->app->db->execute('DELETE FROM {attachments} WHERE uid = ?', [$row['uid']]);
        if (preg_match(self::FILE_PATTERN, (string) $row['file']) === 1) {
            $this->app->files->delete(self::DIR . $row['file'], false);
        }
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private static function typed(array $r): array
    {
        $r['owner_id'] = (int) $r['owner_id'];
        $r['user_id'] = (int) $r['user_id'];
        $r['bytes'] = (int) $r['bytes'];
        return $r;
    }

    private static function asciiName(string $name): string
    {
        $base = strtr($name, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss']);
        $base = trim((string) preg_replace('/[^A-Za-z0-9 ._-]+/', '', $base));
        return $base === '' ? 'Anhang' : $base;
    }
}
