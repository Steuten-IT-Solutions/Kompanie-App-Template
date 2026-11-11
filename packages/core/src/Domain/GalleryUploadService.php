<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Storage\GalleryRepository;
use KompanieApp\Core\Storage\GallerySubmissionRepository;

/**
 * Alben der App und hochgeladene Bilder (Issue #285, Quelle „eigener Server“), gepflegt unter Einstellungen → Galerie
 * (manage_settings).
 *
 * - Alben der App (albums.source = app, drive_id „app:<Kennung>“) gibt es mit beiden Quellen; der Abgleich mit Drive
 *   lässt sie in Ruhe.
 * - Bilder lassen sich in jedes Album hochladen, auch in eines aus Drive. Der Server prüft Format und Grenzen
 *   (ServerLimits), kodiert neu als JPEG ohne Metadaten (kein GPS-Ort) mit höchstens ORIGINAL_EDGE Pixeln und legt das
 *   Ergebnis im MediaStore ab (photos.source = upload, drive_file_id „upload:<Schlüssel>“). Aufbereitet werden sie wie
 *   Bilder aus Drive (thumb, large, Original).
 * - Videos (MP4, WebM, Issue #350) kommen in Stücken über VideoUploadService und bleiben unverändert.
 * - Der Abgleich mit Drive ändert und löscht keine hochgeladenen Bilder und keine Alben, in denen welche liegen.
 */
final class GalleryUploadService
{
    public const ALBUM_PREFIX = 'app:';
    public const TITLE_MAX = 120;
    /** Höchstens so viele Bilder auf einmal verschieben oder löschen. */
    public const BATCH_MAX = 500;
    /** Erlaubte Endungen und der Anfang der Datei. */
    private const TYPES = ['jpg' => "\xFF\xD8\xFF", 'jpeg' => "\xFF\xD8\xFF", 'png' => "\x89PNG\r\n\x1A\n", 'webp' => 'RIFF'];

    /** Einmalig: Die Standardrolle „Galerie verwalten“ (Schlüssel gallery) bekommt das Recht manage_gallery. */
    public const MANAGE_SETTING = 'gallery_manage_added';

    public function __construct(private readonly App $app)
    {
    }

    /** Nach dem Update: bestehende Standardrolle „Galerie verwalten“ einmalig um manage_gallery ergänzen. */
    public function initialize(): void
    {
        $this->app->roles()->grantOnce('gallery', Capability::ManageGallery, self::MANAGE_SETTING);
    }

    private function repo(): GalleryRepository
    {
        return new GalleryRepository($this->app->db);
    }

    /** @return list<array<string, mixed>> */
    public function albums(): array
    {
        return array_map(static fn (array $a): array => [
            'id' => (int) $a['id'],
            'title' => (string) $a['title'],
            'year' => (string) $a['year'],
            'source' => (string) $a['source'] === 'app' ? 'app' : 'drive',
            'uploads' => (int) $a['upload_cnt'],
            'drive_photos' => (int) $a['drive_cnt'],
        ], $this->repo()->adminAlbums());
    }

    /** @return array<string, mixed> Album mit seinen hochgeladenen Bildern */
    public function album(int $id): array
    {
        $album = $this->repo()->album($id) ?? throw ApiError::notFound('Album nicht gefunden.');
        $gallery = $this->app->gallery();
        return [
            'id' => (int) $album['id'],
            'title' => (string) $album['title'],
            'year' => (string) $album['year'],
            'source' => (string) $album['source'] === 'app' ? 'app' : 'drive',
            'uploads' => array_map(static fn (array $p): array => [
                'id' => (int) $p['id'],
                'type' => (string) $p['media'] === 'video' ? 'video' : 'image',
                'name' => (string) $p['name'],
                'intern' => (bool) (int) $p['is_intern'],
                // '' bei einem Video ohne Standbild
                'thumb' => (string) $gallery->thumbUrl($p),
                'status' => (string) $p['status'],
                'error' => (string) $p['error'],
            ], $this->repo()->uploads($id)),
        ];
    }

    /** @return array<string, mixed> */
    public function createAlbum(mixed $in): array
    {
        $in = is_array($in) ? $in : [];
        $title = $this->validTitle($in['title'] ?? null);
        $year = $this->validYear($in['year'] ?? null);
        $now = $this->app->time()->nowUtc();
        $id = $this->repo()->insertAlbum([
            'drive_id' => self::ALBUM_PREFIX . bin2hex(random_bytes(12)),
            'year' => $year,
            'title' => $title,
            'sort_date' => $now,
            'synced_at' => $now,
            'source' => 'app',
        ]);
        return $this->album($id);
    }

    /** @return array<string, mixed> */
    public function updateAlbum(int $id, mixed $in): array
    {
        $album = $this->appAlbum($id);
        $in = is_array($in) ? $in : [];
        $data = [];
        if (array_key_exists('title', $in)) {
            $data['title'] = $this->validTitle($in['title']);
        }
        if (array_key_exists('year', $in)) {
            $data['year'] = $this->validYear($in['year']);
        }
        if ($data !== []) {
            $this->repo()->updateAlbum((int) $album['id'], $data);
        }
        return $this->album($id);
    }

    /** Album der App mit allen Bildern darin löschen. */
    public function deleteAlbum(int $id): void
    {
        $this->appAlbum($id);
        $repo = $this->repo();
        foreach ($repo->photosOfAlbum($id) as $photo) {
            $this->removePhoto($photo);
        }
        $this->app->gallerySubmissions()->albumDeleted($id);
        $repo->deleteAlbum($id);
    }

    /**
     * Ein Bild hochladen.
     *
     * @param mixed $file {name, data (Base64, auch als data:-Adresse)}
     * @return array<string, mixed> das Album mit seinen hochgeladenen Bildern
     */
    public function upload(int $albumId, mixed $file, bool $intern): array
    {
        $this->repo()->album($albumId) ?? throw ApiError::notFound('Album nicht gefunden.');
        $this->insertPhoto($albumId, $this->store($albumId, $file), $intern);
        return $this->album($albumId);
    }

    /**
     * Prüft ein Bild, kodiert es neu als JPEG ohne Metadaten (auch ohne GPS-Ort, nach EXIF gedreht, höchstens
     * ORIGINAL_EDGE Pixel) und legt es im MediaStore ab; auch für Einreichungen (Issue #286), dann mit kleiner Vorschau.
     *
     * @param mixed $file {name, data (Base64, auch als data:-Adresse)}
     * @param int|null $thumbEdge Kantenlänge einer Vorschau (JPEG als Base64), null ohne
     * @return array{key: string, name: string, width: int, height: int, md5: string, bytes: int, thumb: string|null}
     */
    public function store(int $albumId, mixed $file, ?int $thumbEdge = null): array
    {
        $name = is_array($file) && is_string($file['name'] ?? null) ? trim($file['name']) : '';
        $data = is_array($file) && is_string($file['data'] ?? null) ? $file['data'] : '';
        if ($data === '') {
            throw ApiError::invalid($this->postTooLarge() ?? 'Es ist kein Bild angekommen.');
        }
        if (str_starts_with($data, 'data:') && ($comma = strpos($data, ',')) !== false) {
            $data = substr($data, $comma + 1);
        }
        $bytes = base64_decode($data, true);
        unset($data);
        if ($bytes === false || $bytes === '') {
            throw ApiError::invalid('Es ist kein Bild angekommen.');
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$ext])) {
            throw ApiError::invalid('Erlaubt sind Bilder als JPG, PNG oder WebP.');
        }
        $sig = self::TYPES[$ext];
        $ok = strncmp($bytes, $sig, strlen($sig)) === 0 && ($ext !== 'webp' || substr($bytes, 8, 4) === 'WEBP');
        $info = $ok ? @getimagesizefromstring($bytes) : false;
        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            throw ApiError::invalid(sprintf('Die Datei ist keine gültige .%s-Datei.', $ext));
        }
        $this->checkMemory((int) $info[0], (int) $info[1], strlen($bytes));
        $files = $this->app->files;
        $src = $files->tempFile();
        $jpeg = $files->tempFile();
        $small = $thumbEdge !== null ? $files->tempFile() : null;
        try {
            if (@file_put_contents($src, $bytes) === false) {
                throw new \RuntimeException('Die Datei lässt sich nicht zwischenspeichern.');
            }
            unset($bytes);
            try {
                // Neu kodiert: ohne Metadaten (auch kein GPS-Ort), nach EXIF gedreht
                $size = $this->app->images->resizeToJpeg($src, $jpeg, GalleryService::ORIGINAL_EDGE, GalleryService::ORIGINAL_QUALITY);
                if ($small !== null) {
                    $this->app->images->resizeToJpeg($jpeg, $small, (int) $thumbEdge, 80);
                }
            } catch (\RuntimeException $e) {
                throw ApiError::invalid('Das Bild lässt sich nicht lesen: ' . $e->getMessage());
            }
            $key = $this->app->gallery()->media()->put($albumId, $jpeg, 'jpg');
            $md5 = (string) md5_file($jpeg);
            $bytesOut = (int) filesize($jpeg);
            $thumb = $small !== null ? base64_encode((string) file_get_contents($small)) : null;
        } finally {
            @unlink($src);
            @unlink($jpeg);
            if ($small !== null) {
                @unlink($small);
            }
        }
        $base = pathinfo($name, PATHINFO_FILENAME);
        return [
            'key' => $key,
            'name' => mb_substr(($base !== '' ? $base : 'Bild') . '.jpg', 0, 255),
            'width' => (int) $size['width'],
            'height' => (int) $size['height'],
            'md5' => $md5,
            'bytes' => $bytesOut,
            'thumb' => $thumb,
        ];
    }

    /**
     * Ein abgelegtes Bild ins Album aufnehmen; aufbereitet wird es danach im Hintergrund wie Bilder aus Drive.
     *
     * Videos (Issue #350) erkennt es an der Endung des Schlüssels (mp4, webm).
     *
     * @param array{key: string, name: string, width: int, height: int, md5: string, bytes: int, duration_ms?: int} $stored aus store() oder VideoUploadService
     * @return int ID des Bilds
     */
    public function insertPhoto(int $albumId, array $stored, bool $intern): int
    {
        $mime = VideoUploadService::TYPES[strtolower(pathinfo($stored['key'], PATHINFO_EXTENSION))] ?? null;
        $id = $this->repo()->insertPhoto([
            'album_id' => $albumId,
            'drive_file_id' => GalleryService::UPLOAD_PREFIX . $stored['key'],
            'name' => $stored['name'],
            'is_intern' => $intern ? 1 : 0,
            'width' => $stored['width'],
            'height' => $stored['height'],
            'taken_at' => null,
            'md5' => $stored['md5'],
            'file_key' => bin2hex(random_bytes(16)),
            'status' => GalleryRepository::PENDING,
            'error' => '',
            'media' => $mime !== null ? 'video' : 'image',
            'mime' => $mime ?? 'image/jpeg',
            'bytes' => $stored['bytes'],
            'duration_ms' => $mime !== null ? max(0, (int) ($stored['duration_ms'] ?? 0)) : 0,
            'source' => 'upload',
        ]);
        $this->app->scheduler->runSoon(GalleryService::PROCESS_TASK);
        return $id;
    }

    /**
     * Ein hochgeladenes Bild oder Video nachträglich intern (nur für Mitglieder) oder öffentlich stellen. Die
     * aufbereiteten Größen liegen bei öffentlichen Bildern im öffentlichen Verzeichnis: Sie werden gelöscht und mit
     * neuem file_key im Hintergrund neu erzeugt, damit alte öffentliche Adressen sofort nichts mehr liefern. Das
     * Original im MediaStore bleibt, wo es ist. Bilder aus Drive stellt der Ordner „intern“ in Drive um.
     *
     * @return array{id: int, intern: bool}
     */
    public function setIntern(int $id, mixed $intern): array
    {
        if (!is_bool($intern)) {
            throw ApiError::invalid('Bitte angeben, ob das Bild intern sein soll (intern: true oder false).');
        }
        $repo = $this->repo();
        $photo = $repo->photo($id) ?? throw ApiError::notFound('Bild nicht gefunden.');
        if (!GalleryService::isUpload($photo)) {
            throw ApiError::invalid('Bilder aus Google Drive stellst du in Drive um: in den Ordner „intern“ des Albums verschieben oder heraus.');
        }
        if ((bool) (int) $photo['is_intern'] !== $intern) {
            $this->app->gallery()->deleteFiles($photo);
            $repo->updatePhoto($id, [
                'is_intern' => $intern ? 1 : 0,
                'file_key' => bin2hex(random_bytes(16)),
                'status' => GalleryRepository::PENDING,
                'error' => '',
                'attempts' => 0,
            ]);
            $this->app->scheduler->runSoon(GalleryService::PROCESS_TASK);
        }
        return ['id' => $id, 'intern' => $intern];
    }

    /** Ein hochgeladenes Bild löschen (Bilder aus Drive kämen beim nächsten Abgleich wieder). */
    public function deletePhoto(int $id, ?User $actor = null): void
    {
        $photo = $this->repo()->photo($id);
        if ($photo === null || !GalleryService::isUpload($photo)) {
            throw ApiError::notFound('Hochgeladenes Bild nicht gefunden.');
        }
        $this->removePhoto($photo);
        // Kam es aus einer Einreichung (Issue #286), gilt diese als wieder entfernt.
        $this->app->gallerySubmissions()->photoRemoved($id, $actor);
    }

    /**
     * Hochgeladene Bilder und Videos in ein anderes Album verschieben, auch in eines aus Drive. Erst wird alles geprüft,
     * dann verschoben: Ist ein Bild nicht (mehr) da oder aus Drive, bleibt alles, wie es war. Das Original zieht in der
     * Ablage mit um, die aufbereiteten Größen öffentlicher Bilder ins Verzeichnis des neuen Albums (alte Adressen
     * liefern danach nichts mehr). Eine freigegebene Einreichung zum Bild zählt danach zum neuen Album.
     *
     * @param mixed $ids Liste der IDs
     * @return array{moved: int, album_id: int}
     */
    public function movePhotos(mixed $ids, mixed $albumId): array
    {
        $target = Input::integer($albumId);
        if ($target === null || $target < 1) {
            throw ApiError::invalid('Bitte das Album angeben, in das verschoben werden soll.');
        }
        $repo = $this->repo();
        $repo->album($target) ?? throw ApiError::notFound('Album nicht gefunden.');
        $photos = $this->uploadedPhotos($ids);
        $gallery = $this->app->gallery();
        $submissions = new GallerySubmissionRepository($this->app->db);
        $moved = 0;
        $renew = false;
        foreach ($photos as $photo) {
            if ((int) $photo['album_id'] === $target) {
                continue;
            }
            $data = ['album_id' => $target];
            try {
                $data['drive_file_id'] = GalleryService::UPLOAD_PREFIX . $gallery->media()->move(GalleryService::uploadKey($photo), $target);
            } catch (\RuntimeException | \InvalidArgumentException) {
                // Original fehlt oder lässt sich nicht umbenennen: Es bleibt unter seinem Schlüssel, das Bild zieht trotzdem um.
            }
            if (!$gallery->moveFiles($photo, $target)) {
                $gallery->deleteFiles($photo);
                $data += ['file_key' => bin2hex(random_bytes(16)), 'status' => GalleryRepository::PENDING, 'error' => '', 'attempts' => 0];
                $renew = true;
            }
            $repo->updatePhoto((int) $photo['id'], $data);
            $s = $submissions->byPhoto((int) $photo['id']);
            if ($s !== null) {
                $submissions->update((int) $s['id'], ['album_id' => $target]);
            }
            $moved++;
        }
        if ($renew) {
            $this->app->scheduler->runSoon(GalleryService::PROCESS_TASK);
        }
        return ['moved' => $moved, 'album_id' => $target];
    }

    /**
     * Mehrere hochgeladene Bilder und Videos löschen; erst geprüft, dann gelöscht (alles oder nichts).
     *
     * @param mixed $ids Liste der IDs
     * @return array{deleted: int}
     */
    public function deletePhotos(mixed $ids, ?User $actor): array
    {
        $photos = $this->uploadedPhotos($ids);
        foreach ($photos as $photo) {
            $this->removePhoto($photo);
            $this->app->gallerySubmissions()->photoRemoved((int) $photo['id'], $actor);
        }
        return ['deleted' => count($photos)];
    }

    /**
     * @param mixed $ids Liste der IDs, höchstens BATCH_MAX
     * @return list<array<string, mixed>> die hochgeladenen Bilder dazu
     */
    private function uploadedPhotos(mixed $ids): array
    {
        if (!is_array($ids) || !array_is_list($ids) || $ids === []) {
            throw ApiError::invalid('Bitte mindestens ein Bild oder Video angeben (ids).');
        }
        if (count($ids) > self::BATCH_MAX) {
            throw ApiError::invalid(sprintf('Höchstens %d Bilder oder Videos auf einmal.', self::BATCH_MAX));
        }
        $repo = $this->repo();
        $photos = [];
        foreach ($ids as $raw) {
            $id = is_int($raw) ? $raw : null;
            if ($id === null || $id < 1) {
                throw ApiError::invalid('Die Angabe ids muss eine Liste von IDs sein.');
            }
            $photo = $repo->photo($id);
            if ($photo === null) {
                throw ApiError::notFound('Bild oder Video nicht gefunden.');
            }
            if (!GalleryService::isUpload($photo)) {
                throw ApiError::invalid(sprintf('„%s“ kommt aus Google Drive. Das verschiebst oder löschst du in Drive.', (string) $photo['name']));
            }
            $photos[$id] = $photo;
        }
        return array_values($photos);
    }

    /** @param array<string, mixed> $photo */
    public function removePhoto(array $photo): void
    {
        $gallery = $this->app->gallery();
        $gallery->deleteFiles($photo);
        if (GalleryService::isUpload($photo)) {
            $gallery->media()->delete(GalleryService::uploadKey($photo));
        }
        $this->repo()->deletePhoto((int) $photo['id']);
    }

    /** @return array<string, mixed> */
    private function appAlbum(int $id): array
    {
        $album = $this->repo()->album($id) ?? throw ApiError::notFound('Album nicht gefunden.');
        if ((string) $album['source'] !== 'app') {
            throw ApiError::invalid('Alben aus Google Drive änderst du in Drive.');
        }
        return $album;
    }

    private function validTitle(mixed $raw): string
    {
        $title = Text::line(is_string($raw) ? $raw : '', self::TITLE_MAX + 1);
        if ($title === '') {
            throw ApiError::invalid('Bitte einen Titel für das Album angeben.');
        }
        if (mb_strlen($title) > self::TITLE_MAX) {
            throw ApiError::invalid(sprintf('Der Titel darf höchstens %d Zeichen lang sein.', self::TITLE_MAX));
        }
        return $title;
    }

    private function validYear(mixed $raw): string
    {
        $year = Input::integer($raw);
        $max = (int) $this->app->time()->today()->format('Y') + 1;
        if ($year === null || $year < 1800 || $year > $max) {
            throw ApiError::invalid(sprintf('Bitte ein Jahr zwischen 1800 und %d angeben.', $max));
        }
        return (string) $year;
    }

    private function postTooLarge(): ?string
    {
        $length = isset($_SERVER['CONTENT_LENGTH']) && is_numeric($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
        $post = ServerLimits::postMaxSize();
        if ($post === null || $length <= $post) {
            return null;
        }
        return sprintf(
            'Das Bild ist zu groß für den Server: Er nimmt höchstens %s je Anfrage an (PHP-Einstellung post_max_size), das Bild braucht %s.',
            ServerLimits::format($post),
            ServerLimits::format($length),
        );
    }

    /** Reicht der Arbeitsspeicher zum Umrechnen (Quelle und Ziel, je Pixel 4 Bytes, dazu ein Puffer)? */
    private function checkMemory(int $width, int $height, int $fileBytes): void
    {
        $available = ServerLimits::memoryAvailable();
        if ($available === null) {
            return;
        }
        $needed = (int) ($width * $height * 4 * 2 * 1.25) + 3 * $fileBytes + 2 * 1024 * 1024;
        if ($needed > $available) {
            throw ApiError::invalid(sprintf(
                'Das Bild hat %d × %d Pixel. Zum Verarbeiten braucht der Server rund %s Arbeitsspeicher, frei sind %s. Bitte das Bild verkleinern oder den Hoster bitten, memory_limit zu erhöhen.',
                $width,
                $height,
                ServerLimits::format($needed),
                ServerLimits::format($available),
            ));
        }
    }
}
