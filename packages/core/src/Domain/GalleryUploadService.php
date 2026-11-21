<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\I18n\I18n;
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
 * - Mit Google Drive als Ablage (GalleryDriveService::active) gilt dasselbe für Bilder und Alben aus Drive: Die App
 *   ändert sie dort (Ordner anlegen, umbenennen, ins Jahr verschieben, Bilder verschieben, intern über den Ordner
 *   „intern“, Löschen in den Papierkorb von Drive). Neue Alben bekommen dann einen Ordner in Drive.
 */
final class GalleryUploadService
{
    public const ALBUM_PREFIX = 'app:';
    public const TITLE_MAX = 120;
    /** Art in der Tabelle translations: Titel eines Albums je Sprache (Plan „Mehrsprachigkeit“, PR 9e) */
    public const KIND = 'album';
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
        $rows = $this->repo()->adminAlbums();
        $content = $this->app->contentTranslations();
        $tr = $content->locales() !== [] ? $content->ofMany(self::KIND, array_map(static fn (array $a): int => (int) $a['id'], $rows)) : [];
        return array_map(fn (array $a): array => $this->withTranslations((int) $a['id'], $tr[(int) $a['id']] ?? [], [
            'id' => (int) $a['id'],
            'title' => (string) $a['title'],
            'year' => (string) $a['year'],
            'source' => (string) $a['source'] === 'app' ? 'app' : 'drive',
            'editable' => (string) $a['source'] === 'app' || $this->app->galleryDrive()->active(),
            'uploads' => (int) $a['upload_cnt'],
            'drive_photos' => (int) $a['drive_cnt'],
        ]), $rows);
    }

    /**
     * Zum Bearbeiten: deutscher Titel, mit weiteren Sprachen dazu translations ({Sprache: {title}}, '' = deutsch).
     *
     * @param array<string, array<string, string>> $tr
     * @param array<string, mixed> $album
     * @return array<string, mixed>
     */
    private function withTranslations(int $id, array $tr, array $album): array
    {
        $content = $this->app->contentTranslations();
        return $content->locales() !== [] ? $album + ['translations' => $content->titlesSource($tr)] : $album;
    }

    /** @return array<string, mixed> Album mit seinen hochgeladenen Bildern */
    public function album(int $id): array
    {
        $album = $this->repo()->album($id) ?? throw ApiError::notFound(I18n::t('Album nicht gefunden.'));
        $gallery = $this->app->gallery();
        return $this->withTranslations($id, $this->app->contentTranslations()->of(self::KIND, $id), [
            'id' => (int) $album['id'],
            'title' => (string) $album['title'],
            'year' => (string) $album['year'],
            'source' => (string) $album['source'] === 'app' ? 'app' : 'drive',
            'editable' => (string) $album['source'] === 'app' || $this->app->galleryDrive()->active(),
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
        ]);
    }

    /** Lässt sich das Bild in der App ändern? Hochgeladene immer, aus Drive mit Drive als Ablage. */
    public function editable(array $photo): bool
    {
        return GalleryService::isUpload($photo) || $this->app->galleryDrive()->active();
    }

    /** @return array<string, mixed> */
    public function createAlbum(mixed $in, int $by = 0): array
    {
        $in = is_array($in) ? $in : [];
        $title = $this->validTitle($in['title'] ?? null);
        $year = $this->validYear($in['year'] ?? null);
        $translations = $this->app->contentTranslations()->titlesInput($in['translations'] ?? null, self::TITLE_MAX);
        $now = $this->app->time()->nowUtc();
        $drive = $this->app->galleryDrive();
        $folder = null;
        if ($drive->active()) {
            // Mit Drive als Ablage: Ordner im Jahresordner, das Album gilt als Album aus Drive
            try {
                $folder = $drive->createAlbumFolder($title, $year);
            } catch (\RuntimeException $e) {
                throw ApiError::invalid(I18n::t('Das Album ließ sich in Google Drive nicht anlegen: {error}', ['error' => $e->getMessage()]));
            }
        }
        $data = ['year' => $year, 'title' => $title, 'sort_date' => $now, 'synced_at' => $now, 'source' => $folder !== null ? 'drive' : 'app'];
        $repo = $this->repo();
        $id = $repo->transaction(static function () use ($repo, $folder, $data): int {
            // Hat ein gleichzeitiger Abgleich den neuen Ordner schon als Album angelegt: dieses übernehmen.
            $twin = $folder !== null ? $repo->albumByDriveId($folder) : null;
            if ($twin !== null) {
                $repo->updateAlbum((int) $twin['id'], $data);
                return (int) $twin['id'];
            }
            return $repo->insertAlbum(['drive_id' => $folder ?? self::ALBUM_PREFIX . bin2hex(random_bytes(12))] + $data);
        });
        $this->app->contentTranslations()->saveAll(self::KIND, $id, $translations, $by);
        return $this->album($id);
    }

    /** @return array<string, mixed> */
    public function updateAlbum(int $id, mixed $in, int $by = 0): array
    {
        $album = $this->appAlbum($id);
        $in = is_array($in) ? $in : [];
        $translations = $this->app->contentTranslations()->titlesInput($in['translations'] ?? null, self::TITLE_MAX);
        $data = [];
        if (array_key_exists('title', $in)) {
            $data['title'] = $this->validTitle($in['title']);
        }
        if (array_key_exists('year', $in)) {
            $data['year'] = $this->validYear($in['year']);
        }
        if ($data !== [] && (string) $album['source'] === 'drive') {
            try {
                $this->app->galleryDrive()->updateAlbumFolder($album, $data['title'] ?? null, $data['year'] ?? null);
            } catch (\RuntimeException $e) {
                throw ApiError::invalid(I18n::t('Das Album ließ sich in Google Drive nicht ändern: {error}', ['error' => $e->getMessage()]));
            }
        }
        if ($data !== []) {
            $this->repo()->updateAlbum((int) $album['id'], $data);
        }
        $this->app->contentTranslations()->saveAll(self::KIND, (int) $album['id'], $translations, $by);
        return $this->album($id);
    }

    /** Album mit allen Bildern darin löschen; ein Album aus Drive kommt samt Ordner in den Papierkorb von Drive. */
    public function deleteAlbum(int $id): void
    {
        $album = $this->appAlbum($id);
        $repo = $this->repo();
        if ((string) $album['source'] === 'drive') {
            try {
                $this->app->drive()->trashFile((string) $album['drive_id']);
            } catch (\RuntimeException $e) {
                throw ApiError::invalid(I18n::t('Das Album ließ sich in Google Drive nicht löschen: {error}', ['error' => $e->getMessage()]));
            }
        }
        $gallery = $this->app->gallery();
        foreach ($repo->photosOfAlbum($id) as $photo) {
            if (GalleryService::isUpload($photo)) {
                $this->removePhoto($photo);
                continue;
            }
            // Die Datei liegt im Ordner, der schon im Papierkorb ist
            $gallery->deleteFiles($photo);
            $repo->deletePhoto((int) $photo['id']);
        }
        $this->app->gallerySubmissions()->albumDeleted($id);
        $repo->deleteAlbum($id);
        $this->app->contentTranslations()->delete(self::KIND, $id);
    }

    /**
     * Ein Bild hochladen.
     *
     * @param mixed $file {name, data (Base64, auch als data:-Adresse)}
     * @return array<string, mixed> das Album mit seinen hochgeladenen Bildern
     */
    public function upload(int $albumId, mixed $file, bool $intern): array
    {
        $this->repo()->album($albumId) ?? throw ApiError::notFound(I18n::t('Album nicht gefunden.'));
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
            throw ApiError::invalid($this->postTooLarge() ?? I18n::t('Es ist kein Bild angekommen.'));
        }
        if (str_starts_with($data, 'data:') && ($comma = strpos($data, ',')) !== false) {
            $data = substr($data, $comma + 1);
        }
        $bytes = base64_decode($data, true);
        unset($data);
        if ($bytes === false || $bytes === '') {
            throw ApiError::invalid(I18n::t('Es ist kein Bild angekommen.'));
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$ext])) {
            throw ApiError::invalid(I18n::t('Erlaubt sind Bilder als JPG, PNG oder WebP.'));
        }
        $sig = self::TYPES[$ext];
        $ok = strncmp($bytes, $sig, strlen($sig)) === 0 && ($ext !== 'webp' || substr($bytes, 8, 4) === 'WEBP');
        $info = $ok ? @getimagesizefromstring($bytes) : false;
        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            throw ApiError::invalid(I18n::t('Die Datei ist keine gültige .{extension}-Datei.', ['extension' => $ext]));
        }
        $this->checkMemory((int) $info[0], (int) $info[1], strlen($bytes));
        $files = $this->app->files;
        $src = $files->tempFile();
        $jpeg = $files->tempFile();
        $small = $thumbEdge !== null ? $files->tempFile() : null;
        try {
            if (@file_put_contents($src, $bytes) === false) {
                throw new \RuntimeException(I18n::t('Die Datei lässt sich nicht zwischenspeichern.'));
            }
            unset($bytes);
            try {
                // Neu kodiert: ohne Metadaten (auch kein GPS-Ort), nach EXIF gedreht
                $size = $this->app->images->resizeToJpeg($src, $jpeg, GalleryService::ORIGINAL_EDGE, GalleryService::ORIGINAL_QUALITY);
                if ($small !== null) {
                    $this->app->images->resizeToJpeg($jpeg, $small, (int) $thumbEdge, 80);
                }
            } catch (\RuntimeException $e) {
                throw ApiError::invalid(I18n::t('Das Bild lässt sich nicht lesen: {error}', ['error' => $e->getMessage()]));
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
     * Eine Datei, die schon im Ordner eines Albums in Drive liegt (freigegebene Einreichung, GalleryDriveService), als
     * Bild aus Drive aufnehmen, ohne auf den nächsten Abgleich zu warten. Der Abgleich findet sie danach unter derselben
     * Drive-ID; die Prüfsumme ist die der hochgeladenen Datei (gleich der von Drive).
     *
     * @param string $typeKey Schlüssel der Einreichung (die Endung zeigt Video oder Bild)
     * @param array{name: string, width: int, height: int, md5: string, bytes: int, duration_ms?: int} $stored
     * @return int ID des Bilds
     */
    public function insertDrivePhoto(int $albumId, string $driveFileId, string $typeKey, array $stored, bool $intern): int
    {
        $mime = VideoUploadService::TYPES[strtolower(pathinfo($typeKey, PATHINFO_EXTENSION))] ?? null;
        $id = $this->repo()->insertPhoto([
            'album_id' => $albumId,
            'drive_file_id' => $driveFileId,
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
            'source' => 'drive',
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
            throw ApiError::invalid(I18n::t('Bitte angeben, ob das Bild intern sein soll (intern: true oder false).'));
        }
        $repo = $this->repo();
        $photo = $repo->photo($id) ?? throw ApiError::notFound(I18n::t('Bild nicht gefunden.'));
        if (!$this->editable($photo)) {
            throw ApiError::invalid(I18n::t('Bilder aus Google Drive stellst du in Drive um: in den Ordner „intern“ des Albums verschieben oder heraus.'));
        }
        if ((bool) (int) $photo['is_intern'] !== $intern && !GalleryService::isUpload($photo)) {
            // Aus Drive: in den Ordner „intern“ des Albums oder heraus
            $album = $repo->album((int) $photo['album_id']) ?? throw ApiError::notFound(I18n::t('Album nicht gefunden.'));
            try {
                $this->app->galleryDrive()->movePhotoFile($photo, $album, $album, $intern);
            } catch (\RuntimeException $e) {
                throw ApiError::invalid(I18n::t('Das ließ sich in Google Drive nicht umstellen: {error}', ['error' => $e->getMessage()]));
            }
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

    /**
     * Titelbild eines Albums in der Galerie-Übersicht wählen (auch Alben aus Drive) oder mit null zurück zur automatischen
     * Wahl. Nur Bilder dieses Albums, keine Videos. Ist das gewählte Bild intern, sehen Gäste weiter ein öffentliches
     * (GalleryRepository::albumSummaries).
     *
     * @return array{id: int, cover_id: int|null}
     */
    public function setCover(int $albumId, mixed $photoId): array
    {
        $repo = $this->repo();
        $repo->album($albumId) ?? throw ApiError::notFound(I18n::t('Album nicht gefunden.'));
        if ($photoId !== null && !is_int($photoId)) {
            throw ApiError::invalid(I18n::t('Bitte ein Bild angeben (photo_id) oder null für die automatische Wahl.'));
        }
        if ($photoId !== null) {
            $photo = $repo->photo($photoId);
            if ($photo === null || (int) $photo['album_id'] !== $albumId || $photo['status'] === GalleryRepository::FAILED) {
                throw ApiError::notFound(I18n::t('Bild nicht in diesem Album gefunden.'));
            }
            if (($photo['media'] ?? 'image') === 'video') {
                throw ApiError::invalid(I18n::t('Als Titelbild geht nur ein Bild, kein Video.'));
            }
        }
        $repo->updateAlbum($albumId, ['cover_photo_id' => $photoId]);
        return ['id' => $albumId, 'cover_id' => $photoId];
    }

    /** Ein Bild löschen: hochgeladen vom Server, aus Drive (mit Drive als Ablage) in den Papierkorb von Drive. */
    public function deletePhoto(int $id, ?User $actor = null): void
    {
        $photo = $this->repo()->photo($id);
        if ($photo === null || !$this->editable($photo)) {
            throw ApiError::notFound(I18n::t('Hochgeladenes Bild nicht gefunden.'));
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
            throw ApiError::invalid(I18n::t('Bitte das Album angeben, in das verschoben werden soll.'));
        }
        $repo = $this->repo();
        $targetAlbum = $repo->album($target) ?? throw ApiError::notFound(I18n::t('Album nicht gefunden.'));
        $photos = $this->uploadedPhotos($ids);
        foreach ($photos as $photo) {
            if (!GalleryService::isUpload($photo) && (string) $targetAlbum['source'] !== 'drive' && (int) $photo['album_id'] !== $target) {
                throw ApiError::invalid(I18n::t('„{name}“ liegt in Google Drive und kann nur in ein Album mit Ordner in Drive.', ['name' => (string) $photo['name']]));
            }
        }
        $gallery = $this->app->gallery();
        $submissions = new GallerySubmissionRepository($this->app->db);
        $moved = 0;
        $renew = false;
        foreach ($photos as $photo) {
            if ((int) $photo['album_id'] === $target) {
                continue;
            }
            $data = ['album_id' => $target];
            if (!GalleryService::isUpload($photo)) {
                // Aus Drive: Datei in den Ordner des neuen Albums (bzw. dessen „intern“)
                $from = $repo->album((int) $photo['album_id']) ?? throw ApiError::notFound(I18n::t('Album nicht gefunden.'));
                try {
                    $this->app->galleryDrive()->movePhotoFile($photo, $from, $targetAlbum, (int) $photo['is_intern'] === 1);
                } catch (\RuntimeException $e) {
                    throw ApiError::invalid(I18n::t('„{name}“ ließ sich in Google Drive nicht verschieben: {error}', ['name' => (string) $photo['name'], 'error' => $e->getMessage()]));
                }
            } else {
                try {
                    $data['drive_file_id'] = GalleryService::UPLOAD_PREFIX . $gallery->media()->move(GalleryService::uploadKey($photo), $target);
                } catch (\RuntimeException | \InvalidArgumentException) {
                    // Original fehlt oder lässt sich nicht umbenennen: Es bleibt unter seinem Schlüssel, das Bild zieht trotzdem um.
                }
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
            throw ApiError::invalid(I18n::t('Bitte mindestens ein Bild oder Video angeben (ids).'));
        }
        if (count($ids) > self::BATCH_MAX) {
            throw ApiError::invalid(I18n::t('Höchstens {max} Bilder oder Videos auf einmal.', ['max' => self::BATCH_MAX]));
        }
        $repo = $this->repo();
        $photos = [];
        foreach ($ids as $raw) {
            $id = is_int($raw) ? $raw : null;
            if ($id === null || $id < 1) {
                throw ApiError::invalid(I18n::t('Die Angabe ids muss eine Liste von IDs sein.'));
            }
            $photo = $repo->photo($id);
            if ($photo === null) {
                throw ApiError::notFound(I18n::t('Bild oder Video nicht gefunden.'));
            }
            if (!$this->editable($photo)) {
                throw ApiError::invalid(I18n::t('„{name}“ kommt aus Google Drive. Das verschiebst oder löschst du in Drive.', ['name' => (string) $photo['name']]));
            }
            $photos[$id] = $photo;
        }
        return array_values($photos);
    }

    /**
     * Bild samt Dateien löschen; ein Bild aus Drive (z. B. eine freigegebene Einreichung, die schon nach Drive
     * umgezogen ist) kommt dort in den Papierkorb, sonst brächte der nächste Abgleich es zurück.
     *
     * @param array<string, mixed> $photo
     */
    public function removePhoto(array $photo): void
    {
        $gallery = $this->app->gallery();
        $gallery->deleteFiles($photo);
        if (GalleryService::isUpload($photo)) {
            $gallery->media()->delete(GalleryService::uploadKey($photo));
        } elseif ($this->app->drive()->writeAccess()) {
            $this->app->drive()->trashFile((string) $photo['drive_file_id']);
        }
        $this->repo()->deletePhoto((int) $photo['id']);
    }

    /** @return array<string, mixed> */
    private function appAlbum(int $id): array
    {
        $album = $this->repo()->album($id) ?? throw ApiError::notFound(I18n::t('Album nicht gefunden.'));
        if ((string) $album['source'] !== 'app' && !$this->app->galleryDrive()->active()) {
            throw ApiError::invalid(I18n::t('Alben aus Google Drive änderst du in Drive.'));
        }
        return $album;
    }

    private function validTitle(mixed $raw): string
    {
        $title = Text::line(is_string($raw) ? $raw : '', self::TITLE_MAX + 1);
        if ($title === '') {
            throw ApiError::invalid(I18n::t('Bitte einen Titel für das Album angeben.'));
        }
        if (mb_strlen($title) > self::TITLE_MAX) {
            throw ApiError::invalid(I18n::t('Der Titel darf höchstens {max} Zeichen lang sein.', ['max' => self::TITLE_MAX]));
        }
        return $title;
    }

    private function validYear(mixed $raw): string
    {
        $year = Input::integer($raw);
        $max = (int) $this->app->time()->today()->format('Y') + 1;
        if ($year === null || $year < 1800 || $year > $max) {
            throw ApiError::invalid(I18n::t('Bitte ein Jahr zwischen 1800 und {max} angeben.', ['max' => $max]));
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
        return I18n::t(
            'Das Bild ist zu groß für den Server: Er nimmt höchstens {max} je Anfrage an (PHP-Einstellung post_max_size), das Bild braucht {size}.',
            ['max' => ServerLimits::format($post), 'size' => ServerLimits::format($length)],
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
            throw ApiError::invalid(I18n::t(
                'Das Bild hat {width} × {height} Pixel. Zum Verarbeiten braucht der Server rund {needed} Arbeitsspeicher, frei sind {available}. Bitte das Bild verkleinern oder den Hoster bitten, memory_limit zu erhöhen.',
                ['width' => $width, 'height' => $height, 'needed' => ServerLimits::format($needed), 'available' => ServerLimits::format($available)],
            ));
        }
    }
}
