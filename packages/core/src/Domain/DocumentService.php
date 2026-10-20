<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Http\FileCache;
use KompanieApp\Core\Storage\DocumentRepository;

/**
 * Dokumente (Issue #2): Dateien wie Satzung, Protokolle und Formulare für Mitglieder.
 *
 * - Je Dokument Titel, Kategorie (optional), Stand (Datum) und Sichtbarkeit: alle Mitglieder oder nur, wer Dokumente
 *   pflegen darf (manage_documents).
 * - Erlaubt sind PDF, JPG/PNG und Office-Dateien; geprüft werden Endung und Dateisignatur, höchstens 20 MB (weniger,
 *   wenn der Server kleinere Anfragen annimmt). Die Datei kommt Base64-kodiert im JSON (beide Adapter nehmen nur JSON an).
 * - Eine neue Fassung ersetzt die alte Datei; version wechselt, damit sich die Adresse ändert (Offline-Speicher).
 * - Dateien liegen geschützt in der Dateiablage und werden nur über documentUrl() nach Prüfung der Anmeldung ausgeliefert.
 *
 * @phpstan-import-type Document from DocumentRepository
 */
final class DocumentService
{
    public const MAX_BYTES = 20 * 1024 * 1024;
    public const TITLE_MAX = 191;
    public const VISIBLE_MEMBERS = 'members';
    public const VISIBLE_MANAGERS = 'managers';
    private const DIR = 'dokumente/';
    /** So heißen gespeicherte Dateien (store()); andere Werte, z. B. aus einer veränderten Sicherung, nie anfassen. */
    private const FILE_PATTERN = '/^dok-[0-9a-f]{24}\.[a-z]{3,4}$/';

    /** Endung => [MIME-Typ, Signatur, Art]; pdf und image zeigt die App an, file wird nur heruntergeladen. */
    public const TYPES = [
        'pdf' => ['application/pdf', '%PDF-', 'pdf'],
        'jpg' => ['image/jpeg', "\xFF\xD8\xFF", 'image'],
        'jpeg' => ['image/jpeg', "\xFF\xD8\xFF", 'image'],
        'png' => ['image/png', "\x89PNG\r\n\x1A\n", 'image'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', "PK\x03\x04", 'file'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', "PK\x03\x04", 'file'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', "PK\x03\x04", 'file'],
        'odt' => ['application/vnd.oasis.opendocument.text', "PK\x03\x04", 'file'],
        'ods' => ['application/vnd.oasis.opendocument.spreadsheet', "PK\x03\x04", 'file'],
        'odp' => ['application/vnd.oasis.opendocument.presentation', "PK\x03\x04", 'file'],
        'doc' => ['application/msword', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 'file'],
        'xls' => ['application/vnd.ms-excel', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 'file'],
        'ppt' => ['application/vnd.ms-powerpoint', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 'file'],
    ];

    private ?DocumentRepository $repo = null;

    public function __construct(private readonly App $app)
    {
    }

    public function repository(): DocumentRepository
    {
        return $this->repo ??= new DocumentRepository($this->app->db);
    }

    /* ---------------------------------------------------------------- */
    /* Lesen                                                             */
    /* ---------------------------------------------------------------- */

    public static function canSee(?User $user, string $visibility): bool
    {
        return $user !== null && $user->isMember() && ($visibility !== self::VISIBLE_MANAGERS || $user->can(Capability::ManageDocuments));
    }

    /**
     * Sichtbare Dokumente (nach Titel) und alle Kategorien; für die Pflege zusätzlich die Höchstgröße.
     *
     * @return array<string, mixed>
     */
    public function list(User $viewer): array
    {
        $docs = array_values(array_filter($this->repository()->all(), static fn (array $d): bool => self::canSee($viewer, $d['visibility'])));
        return [
            'categories' => $this->repository()->categories(),
            'documents' => array_map($this->format(...), $docs),
        ] + ($viewer->can(Capability::ManageDocuments) ? ['max_bytes' => self::maxBytes()] : []);
    }

    /**
     * Für die Auslieferung über documentUrl(): prüft Anmeldung und Sichtbarkeit.
     *
     * @return array{status: int, path?: string, etag?: string|null, mime?: string, disposition?: string}
     */
    public function forDelivery(?User $user, int $id): array
    {
        if (!$this->app->features()->enabled('documents')) {
            return ['status' => 404];
        }
        if ($this->app->maintenance()->blocks($user)) {
            return ['status' => 503];
        }
        if ($user === null) {
            return ['status' => 401];
        }
        if (!$user->isMember()) {
            return ['status' => 403];
        }
        $doc = $this->repository()->find($id);
        if ($doc === null || !self::canSee($user, $doc['visibility']) || !isset(self::TYPES[$doc['ext']]) || !preg_match(self::FILE_PATTERN, $doc['file'])) {
            return ['status' => 404];
        }
        $path = $this->app->files->path(self::DIR . $doc['file'], false);
        if (!is_file($path)) {
            return ['status' => 404];
        }
        [$mime, , $kind] = self::TYPES[$doc['ext']];
        $name = $doc['title'] . '.' . $doc['ext'];
        $mode = $kind === 'file' ? 'attachment' : 'inline';
        return [
            'status' => 200,
            'path' => $path,
            // Die Adresse bleibt je Fassung gleich, wird aber jedes Mal geprüft (no-cache): mit ETag meist nur 304.
            'etag' => FileCache::etag($doc['file'], $path),
            'mime' => $mime,
            'disposition' => $mode . '; filename="' . self::asciiName($doc['title']) . '.' . $doc['ext'] . '"; filename*=UTF-8\'\'' . rawurlencode($name),
        ];
    }

    /* ---------------------------------------------------------------- */
    /* Schreiben                                                         */
    /* ---------------------------------------------------------------- */

    /**
     * @param array<string, mixed> $in title, category_id, date, visibility, file: {name, data (Base64)}
     * @return array<string, mixed>
     */
    public function create(array $in, int $by): array
    {
        $meta = $this->validMeta($in, true);
        $stored = $this->store($in['file'] ?? null);
        $now = $this->app->time()->nowUtc();
        try {
            $id = $this->repository()->insert($meta + $stored + ['uploaded_by' => $by, 'created_at' => $now, 'updated_at' => $now]);
        } catch (\Throwable $e) {
            $this->app->files->delete(self::DIR . $stored['file'], false);
            throw $e;
        }
        return $this->format((array) $this->repository()->find($id));
    }

    /**
     * @param array<string, mixed> $in title, category_id, date, visibility
     * @return array<string, mixed>
     */
    public function update(int $id, array $in): array
    {
        $this->repository()->find($id) ?? throw ApiError::notFound('Dokument nicht gefunden.');
        $meta = $this->validMeta($in, false);
        if ($meta !== []) {
            $this->repository()->update($id, $meta + ['updated_at' => $this->app->time()->nowUtc()]);
        }
        return $this->format((array) $this->repository()->find($id));
    }

    /**
     * Neue Fassung: ersetzt die Datei (auch mit anderer Endung); die alte wird gelöscht.
     *
     * @return array<string, mixed>
     */
    public function replaceFile(int $id, mixed $file, int $by): array
    {
        $doc = $this->repository()->find($id) ?? throw ApiError::notFound('Dokument nicht gefunden.');
        $stored = $this->store($file);
        try {
            $this->repository()->update($id, $stored + ['uploaded_by' => $by, 'updated_at' => $this->app->time()->nowUtc()]);
        } catch (\Throwable $e) {
            $this->app->files->delete(self::DIR . $stored['file'], false);
            throw $e;
        }
        $this->removeFile($doc['file']);
        return $this->format((array) $this->repository()->find($id));
    }

    public function delete(int $id): void
    {
        $doc = $this->repository()->find($id) ?? throw ApiError::notFound('Dokument nicht gefunden.');
        $this->repository()->delete($id);
        $this->removeFile($doc['file']);
    }

    private function removeFile(string $file): void
    {
        if (preg_match(self::FILE_PATTERN, $file)) {
            $this->app->files->delete(self::DIR . $file, false);
        }
    }

    /* ---------------------------------------------------------------- */
    /* Kategorien                                                        */
    /* ---------------------------------------------------------------- */

    /** @return array{id: int, title: string, sort: int} */
    public function createCategory(mixed $title): array
    {
        $repo = $this->repository();
        $id = $repo->insertCategory($this->validCategoryTitle($title, null), $repo->maxCategorySort() + 10);
        return $repo->category($id) ?? throw ApiError::notFound('Kategorie nicht gefunden.');
    }

    /** @return array{id: int, title: string, sort: int} */
    public function renameCategory(int $id, mixed $title): array
    {
        $repo = $this->repository();
        $repo->category($id) ?? throw ApiError::notFound('Kategorie nicht gefunden.');
        $repo->updateCategory($id, ['title' => $this->validCategoryTitle($title, $id)]);
        return $repo->category($id) ?? throw ApiError::notFound('Kategorie nicht gefunden.');
    }

    public function deleteCategory(int $id): void
    {
        $this->repository()->category($id) ?? throw ApiError::notFound('Kategorie nicht gefunden.');
        $this->repository()->deleteCategory($id);
    }

    /**
     * Neue Reihenfolge der Kategorien: alle IDs.
     *
     * @return list<array{id: int, title: string, sort: int}>
     */
    public function reorderCategories(mixed $ids): array
    {
        $repo = $this->repository();
        $current = [];
        foreach ($repo->categories() as $c) {
            $current[$c['id']] = $c;
        }
        $order = is_array($ids) ? array_map(static fn (mixed $v): ?int => Input::integer($v), array_values($ids)) : [];
        $given = $order;
        sort($given);
        $known = array_keys($current);
        sort($known);
        if ($given !== $known) {
            throw ApiError::invalid('Bitte die Reihenfolge aller Kategorien angeben.');
        }
        foreach ($order as $i => $id) {
            if ($current[(int) $id]['sort'] !== ($i + 1) * 10) {
                $repo->updateCategory((int) $id, ['sort' => ($i + 1) * 10]);
            }
        }
        return $repo->categories();
    }

    /* ---------------------------------------------------------------- */

    /**
     * Höchstgröße einer Datei: 20 MB, weniger, wenn der Server kleinere Anfragen annimmt (post_max_size; Base64 macht
     * die Datei um ein Drittel größer) oder wenig Arbeitsspeicher hat.
     */
    public static function maxBytes(): int
    {
        $limits = [self::requestLimit()];
        $memory = self::iniBytes((string) ini_get('memory_limit'));
        if ($memory > 0) {
            // JSON, Base64 und die Datei liegen gleichzeitig im Speicher (gut das Vierfache).
            $limits[] = (int) floor(max(0, $memory - memory_get_usage()) / 4);
        }
        return max(0, min($limits));
    }

    /** 20 MB oder was post_max_size zulässt (Base64 macht die Datei um ein Drittel größer). */
    private static function requestLimit(): int
    {
        $post = self::iniBytes((string) ini_get('post_max_size'));
        return $post > 0 ? min(self::MAX_BYTES, (int) floor(max(0, $post - 64 * 1024) * 3 / 4)) : self::MAX_BYTES;
    }

    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return 0;
        }
        $n = (int) $value;
        return match (strtolower(substr($value, -1))) {
            'g' => $n * 1024 * 1024 * 1024,
            'm' => $n * 1024 * 1024,
            'k' => $n * 1024,
            default => $n,
        };
    }

    /**
     * @param Document $d
     * @return array<string, mixed>
     */
    private function format(array $d): array
    {
        [$mime, , $kind] = self::TYPES[$d['ext']] ?? ['application/octet-stream', '', 'file'];
        return [
            'id' => $d['id'],
            'title' => $d['title'],
            'category_id' => $d['category_id'],
            'date' => $d['doc_date'],
            'visibility' => $d['visibility'],
            'ext' => $d['ext'],
            'kind' => $kind,
            'mime' => $mime,
            'size' => $d['size'],
            'version' => $d['version'],
            'url' => $this->app->env->documentUrl($d['id'], $d['version']),
            'updated_at' => Time::utcToIso($d['updated_at']),
        ];
    }

    /**
     * @param array<string, mixed> $in
     * @return array<string, mixed> Spalten
     */
    private function validMeta(array $in, bool $creating): array
    {
        $out = [];
        if ($creating || array_key_exists('title', $in)) {
            $title = Text::line($in['title'] ?? '', self::TITLE_MAX + 1);
            if ($title === '') {
                throw ApiError::invalid('Bitte einen Titel angeben.');
            }
            if (mb_strlen($title) > self::TITLE_MAX) {
                throw ApiError::invalid(sprintf('Der Titel darf höchstens %d Zeichen lang sein.', self::TITLE_MAX));
            }
            $out['title'] = $title;
        }
        if ($creating || array_key_exists('category_id', $in)) {
            $cat = Input::integer($in['category_id'] ?? null) ?: null;
            if ($cat !== null && $this->repository()->category($cat) === null) {
                throw ApiError::invalid('Kategorie nicht gefunden.');
            }
            $out['category_id'] = $cat;
        }
        if ($creating || array_key_exists('date', $in)) {
            if (!Input::isDate($in['date'] ?? null)) {
                throw ApiError::invalid('Bitte einen gültigen Stand angeben.');
            }
            $out['doc_date'] = (string) $in['date'];
        }
        if ($creating || array_key_exists('visibility', $in)) {
            $vis = ($in['visibility'] ?? '') === '' ? self::VISIBLE_MEMBERS : $in['visibility'];
            if (!in_array($vis, [self::VISIBLE_MEMBERS, self::VISIBLE_MANAGERS], true)) {
                throw ApiError::invalid('Ungültige Sichtbarkeit.');
            }
            $out['visibility'] = $vis;
        }
        return $out;
    }

    /**
     * Datei prüfen und speichern.
     *
     * @return array{file: string, ext: string, size: int, version: string}
     */
    private function store(mixed $file): array
    {
        $name = is_array($file) && is_string($file['name'] ?? null) ? $file['name'] : '';
        $data = is_array($file) && is_string($file['data'] ?? null) ? $file['data'] : '';
        // Auch als data:-Adresse (FileReader.readAsDataURL)
        if (str_starts_with($data, 'data:') && ($comma = strpos($data, ',')) !== false) {
            $data = substr($data, $comma + 1);
        }
        $bytes = $data === '' ? '' : base64_decode($data, true);
        if ($bytes === false || $bytes === '') {
            throw ApiError::invalid(sprintf(
                'Es ist keine Datei angekommen. Ist sie größer als das, was der Server annimmt (%s)?',
                self::formatBytes(self::maxBytes()),
            ));
        }
        // Nicht maxBytes(): Hier liegt die Anfrage schon im Speicher; was angekommen ist, ließ sich auch dekodieren.
        $max = self::requestLimit();
        if (strlen($bytes) > $max) {
            throw ApiError::invalid(sprintf('Die Datei darf höchstens %s groß sein.', self::formatBytes($max)));
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$ext])) {
            throw ApiError::invalid('Erlaubt sind PDF, Word, Excel, PowerPoint, OpenDocument sowie JPG- und PNG-Bilder.');
        }
        $signature = self::TYPES[$ext][1];
        if (strncmp($bytes, $signature, strlen($signature)) !== 0) {
            throw ApiError::invalid(sprintf('Die Datei ist keine gültige .%s-Datei.', $ext));
        }
        $stored = 'dok-' . bin2hex(random_bytes(12)) . '.' . $ext;
        $path = $this->app->files->path(self::DIR . $stored, false);
        if (@file_put_contents($path, $bytes) === false) {
            throw new ApiError(500, 'storage_failed', 'Die Datei konnte nicht gespeichert werden.');
        }
        return ['file' => $stored, 'ext' => $ext, 'size' => strlen($bytes), 'version' => substr(hash('sha256', $bytes), 0, 16)];
    }

    private function validCategoryTitle(mixed $raw, ?int $id): string
    {
        $title = Text::line($raw ?? '', self::TITLE_MAX + 1);
        if ($title === '') {
            throw ApiError::invalid('Bitte einen Namen für die Kategorie angeben.');
        }
        if (mb_strlen($title) > self::TITLE_MAX) {
            throw ApiError::invalid(sprintf('Der Name darf höchstens %d Zeichen lang sein.', self::TITLE_MAX));
        }
        foreach ($this->repository()->categories() as $c) {
            if ($c['id'] !== $id && mb_strtolower($c['title']) === mb_strtolower($title)) {
                throw ApiError::invalid('Diese Kategorie gibt es schon.');
            }
        }
        return $title;
    }

    /** ASCII-Dateiname für ältere Browser, z. B. „Protokoll Maerz“. */
    private static function asciiName(string $title): string
    {
        $base = strtr($title, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss']);
        $base = trim((string) preg_replace('/[^A-Za-z0-9 ._-]+/', '', $base));
        return $base === '' ? 'Dokument' : $base;
    }

    private static function formatBytes(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? str_replace('.', ',', (string) round($bytes / 1024 / 1024, 1)) . ' MB'
            : (string) max(1, (int) round($bytes / 1024)) . ' KB';
    }
}
