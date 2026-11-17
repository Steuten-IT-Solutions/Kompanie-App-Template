<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Storage\GalleryRepository;
use KompanieApp\Core\Storage\GallerySubmissionRepository;

/**
 * Galerie mit Google Drive als Ablage (Wunsch vom 17.11.2026): Ist Drive die Quelle der Galerie und erlaubt die
 * Verbindung vollen Drive-Zugriff (DriveClient::writeAccess), landen Einreichungen und in der App hochgeladene Bilder
 * und Videos in Drive statt auf dem Server.
 *
 * - Angekommen wird wie bisher auf dem Server (Prüfen, neu kodieren ohne Metadaten, Videos in Stücken). Die Aufgabe der
 *   Galerie (runTask) bringt die Dateien danach im Hintergrund nach Drive und löscht die Kopie auf dem Server. Große
 *   Videos gehen über mehrere Läufe (fortsetzbares Hochladen, Stand in UPLOAD_SETTING).
 * - Offene Einreichungen kommen in einen privaten Ordner „Einreichungen <Name der App>“ in „Meine Ablage“ des
 *   verbundenen Kontos, außerhalb des Galerie-Ordners (der Abgleich sieht ihn nicht). Bei Freigabe verschiebt die App
 *   die Datei in den Ordner des Albums (intern: in dessen Unterordner „intern“), bei Ablehnung oder Zurückziehen in den
 *   Papierkorb von Drive.
 * - Hochgeladene Bilder in Alben aus Drive ziehen nach dem Aufbereiten in den Ordner des Albums um und gelten danach als
 *   Bilder aus Drive (photos.source = drive). Die aufbereiteten Größen bleiben, nichts wird neu berechnet.
 * - Alben der App, die vor dem Umstieg nur auf dem Server lagen (albums.source = app), bekommen einmalig einen Ordner
 *   im Jahresordner und gelten danach als Alben aus Drive; ihre Bilder ziehen wie oben nach Drive um.
 */
final class GalleryDriveService
{
    public const TASK = 'gallery_drive';
    /** Ordner der offenen Einreichungen: {submissions_folder: Drive-ID} */
    public const SETTING = 'gallery_drive';
    /** Laufendes Hochladen eines großen Videos: {kind, id, location} */
    public const UPLOAD_SETTING = 'gallery_drive_upload';
    /** Zustand für die Einstellungen: {last_try: int, error: string} */
    public const STATE_SETTING = 'gallery_drive_state';
    /** Höchstens so viele Sekunden je Lauf (danach bald wieder, siehe GalleryService::continueSoon). */
    public const BUDGET = 20;

    public function __construct(private readonly App $app)
    {
    }

    /** Landen neue Dateien in Drive? Nur mit Drive als Quelle, eingerichtetem Ordner und vollem Drive-Zugriff. */
    public function active(): bool
    {
        return $this->app->gallery()->driveConfigured() && $this->app->drive()->writeAccess();
    }

    /**
     * Für die Einstellungen: aktiv, ob die Verbindung neu verbunden werden muss, wie viele Dateien noch auf dem Server
     * auf Drive warten, und der letzte Fehler.
     *
     * @return array{active: bool, needs_reconnect: bool, waiting: int, error: string}
     */
    public function status(): array
    {
        $gallery = $this->app->gallery();
        $state = $this->state();
        return [
            'active' => $this->active(),
            // Drive als Quelle, aber eine ältere Verbindung (nur lesen) oder ein Dienstkonto
            'needs_reconnect' => $gallery->driveConfigured() && !$this->app->drive()->writeAccess(),
            'waiting' => $gallery->driveConfigured() ? count($this->waitingSubmissions()) + count($this->waitingPhotos()) + $this->waitingAlbumPhotos() : 0,
            'error' => $state['error'],
        ];
    }

    /**
     * Dateien nach Drive bringen, bis nichts mehr wartet oder $budget Sekunden um sind.
     *
     * @return int übertragene Dateien
     */
    public function runTask(int $budget = self::BUDGET): int
    {
        if (!$this->active()) {
            return 0;
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(120); // ein Stück (8 MB) kann nach dem Budget noch unterwegs sein
        }
        $deadline = hrtime(true) / 1e9 + $budget;
        $done = 0;
        $state = ['last_try' => $this->app->time()->timestamp(), 'error' => ''];
        try {
            // Zuerst die Alben der App zu Ordnern in Drive machen: Danach warten ihre Bilder wie alle anderen.
            foreach ($this->waitingAlbums() as $album) {
                if (hrtime(true) / 1e9 >= $deadline) {
                    return $done;
                }
                $this->moveAlbum($album);
            }
            foreach ($this->waitingSubmissions() as $s) {
                if (hrtime(true) / 1e9 >= $deadline || !$this->pushSubmission($s, $deadline)) {
                    return $done;
                }
                $done++;
            }
            foreach ($this->waitingPhotos() as $photo) {
                if (hrtime(true) / 1e9 >= $deadline || !$this->pushPhoto($photo, $deadline)) {
                    return $done;
                }
                $done++;
            }
        } catch (\RuntimeException $e) {
            $state['error'] = $e->getMessage();
            error_log('Kompanie-App: Galerie nach Google Drive: ' . $e->getMessage());
        } finally {
            $this->app->settings->set(self::STATE_SETTING, $state);
        }
        return $done;
    }

    /** Wartet noch etwas auf Drive? (Dann läuft die Aufgabe bald wieder.) */
    public function hasWaiting(): bool
    {
        return $this->active() && ($this->waitingAlbums() !== [] || $this->waitingSubmissions() !== [] || $this->waitingPhotos() !== []);
    }

    /**
     * Alben der App, die noch keinen Ordner in Drive haben.
     *
     * @return list<array<string, mixed>>
     */
    private function waitingAlbums(): array
    {
        return array_values($this->app->db->select("SELECT * FROM {albums} WHERE source = 'app' ORDER BY id ASC"));
    }

    /** Bilder in Alben der App (für den Zähler in den Einstellungen; sie warten, bis ihr Album einen Ordner hat). */
    private function waitingAlbumPhotos(): int
    {
        $row = $this->app->db->selectOne(
            "SELECT COUNT(*) AS n FROM {photos} p JOIN {albums} a ON a.id = p.album_id WHERE p.source = 'upload' AND a.source = 'app'",
        );
        return (int) ($row['n'] ?? 0);
    }

    /**
     * Album der App bekommt einen Ordner im Jahresordner und gilt ab jetzt als Album aus Drive.
     *
     * @param array<string, mixed> $album
     */
    private function moveAlbum(array $album): void
    {
        $year = trim((string) $album['year']);
        if ($year === '') {
            $year = substr((string) $album['sort_date'], 0, 4);
        }
        $folder = $this->createAlbumFolder((string) $album['title'], $year);
        $this->takeOverFolder((int) $album['id'], $folder, $year);
    }

    /**
     * Ein Album gilt ab jetzt als Album aus Drive (Ordner $folder). Hat ein gleichzeitiger Abgleich den neuen Ordner
     * schon als eigenes Album angelegt, verschwindet dieses doppelte (seine Bilder kommen ins Album).
     */
    public function takeOverFolder(int $albumId, string $folder, ?string $year = null): void
    {
        $repo = new GalleryRepository($this->app->db);
        $repo->transaction(function () use ($repo, $albumId, $folder, $year): void {
            $twin = $repo->albumByDriveId($folder);
            if ($twin !== null && (int) $twin['id'] !== $albumId) {
                $this->app->db->execute('UPDATE {photos} SET album_id = ? WHERE album_id = ?', [$albumId, (int) $twin['id']]);
                $repo->deleteAlbum((int) $twin['id']);
            }
            $repo->updateAlbum($albumId, ['drive_id' => $folder, 'source' => 'drive'] + ($year !== null ? ['year' => $year] : []));
        });
    }

    /**
     * Offene Einreichungen, deren Datei noch auf dem Server liegt.
     *
     * @return list<array<string, mixed>>
     */
    private function waitingSubmissions(): array
    {
        return array_values($this->app->db->select(
            "SELECT id, album_id, file_key, name FROM {gallery_submissions} WHERE status = ? AND drive_file_id = '' AND file_key <> '' ORDER BY id ASC",
            [GallerySubmissionRepository::PENDING],
        ));
    }

    /**
     * Fertig aufbereitete hochgeladene Bilder und Videos in Alben aus Drive.
     *
     * @return list<array<string, mixed>>
     */
    private function waitingPhotos(): array
    {
        return array_values($this->app->db->select(
            "SELECT p.* FROM {photos} p JOIN {albums} a ON a.id = p.album_id
             WHERE p.source = 'upload' AND p.status = ? AND a.source = 'drive' ORDER BY p.id ASC",
            [GalleryRepository::READY],
        ));
    }

    /** @param array<string, mixed> $s */
    private function pushSubmission(array $s, float $deadline): bool
    {
        $key = (string) $s['file_key'];
        $path = $this->app->gallery()->media()->path($key);
        if ($path === null) {
            return true; // Datei fehlt: beim Prüfen „Bitte ablehnen“, hier nichts zu tun
        }
        $id = $this->upload('submission', (int) $s['id'], $path, (string) $s['name'], self::mime($key), $this->submissionsFolder(), $deadline);
        if ($id === null) {
            return false;
        }
        $this->app->db->update('gallery_submissions', ['drive_file_id' => $id], ['id' => (int) $s['id']]);
        $this->app->gallery()->media()->delete($key);
        return true;
    }

    /** @param array<string, mixed> $photo */
    private function pushPhoto(array $photo, float $deadline): bool
    {
        $repo = new GalleryRepository($this->app->db);
        $album = $repo->album((int) $photo['album_id']);
        if ($album === null) {
            return true;
        }
        $key = GalleryService::uploadKey($photo);
        $path = $this->app->gallery()->media()->path($key);
        if ($path === null) {
            return true;
        }
        $folder = (string) $album['drive_id'];
        if ((int) $photo['is_intern'] === 1) {
            $folder = $this->internFolder($folder);
        }
        $id = $this->upload('photo', (int) $photo['id'], $path, (string) $photo['name'], (string) $photo['mime'] ?: self::mime($key), $folder, $deadline);
        if ($id === null) {
            return false;
        }
        $this->adoptDriveFile((int) $photo['id'], $id);
        $this->app->gallery()->media()->delete($key);
        return true;
    }

    /**
     * Ein Bild gilt ab jetzt als Bild aus Drive (Datei $driveId). Hat ein gleichzeitiger Abgleich die neue Datei schon
     * als eigenes Bild angelegt, verschwindet dieses doppelte.
     */
    private function adoptDriveFile(int $photoId, string $driveId): void
    {
        $repo = new GalleryRepository($this->app->db);
        $twin = $repo->photosByDriveIds([$driveId])[$driveId] ?? null;
        if ($twin !== null && (int) $twin['id'] !== $photoId) {
            $this->app->gallery()->deleteFiles($twin);
            $repo->deletePhoto((int) $twin['id']);
        }
        $repo->updatePhoto($photoId, ['drive_file_id' => $driveId, 'source' => 'drive']);
        $this->app->gallery()->notePushed($photoId);
    }

    /**
     * Lädt eine Datei hoch, auch über mehrere Läufe. null: noch nicht fertig (Stand gespeichert).
     *
     * @param 'submission'|'photo' $kind
     */
    private function upload(string $kind, int $id, string $path, string $name, string $mime, string $folder, float $deadline): ?string
    {
        $drive = $this->app->drive();
        $saved = $this->app->settings->get(self::UPLOAD_SETTING);
        $location = is_array($saved) && ($saved['kind'] ?? '') === $kind && (int) ($saved['id'] ?? 0) === $id ? (string) ($saved['location'] ?? '') : '';
        for ($attempt = 0; $attempt < 2; $attempt++) {
            if ($location === '') {
                $location = $drive->beginUpload($folder, $name, $mime, (int) filesize($path));
                $this->app->settings->set(self::UPLOAD_SETTING, ['kind' => $kind, 'id' => $id, 'location' => $location]);
            }
            try {
                $res = $drive->continueUpload($location, $path, $mime, $deadline);
            } catch (\OutOfBoundsException) {
                $location = ''; // Sitzung abgelaufen: neu beginnen
                continue;
            }
            if ($res['id'] === null) {
                return null;
            }
            $this->app->settings->delete(self::UPLOAD_SETTING);
            return $res['id'];
        }
        throw new \RuntimeException('Google Drive: Das Hochladen ließ sich nicht beginnen.');
    }

    /** Ordner der offenen Einreichungen; legt ihn (neu) an, wenn es ihn nicht (mehr) gibt. */
    public function submissionsFolder(): string
    {
        $drive = $this->app->drive();
        $config = $this->config();
        $id = (string) ($config['submissions_folder'] ?? '');
        if ($id === '' || !$drive->folderExists($id)) {
            $id = $drive->createFolder('Einreichungen ' . $this->app->config->name());
            $this->app->settings->set(self::SETTING, ['submissions_folder' => $id] + $config);
        }
        return $id;
    }

    /** Unterordner „intern“ eines Album-Ordners (wie der Abgleich ihn erkennt); legt ihn an, wenn es keinen gibt. */
    public function internFolder(string $albumFolder): string
    {
        $drive = $this->app->drive();
        foreach ($drive->listChildren($albumFolder) as $f) {
            if (DriveClient::isFolder($f) && mb_strtolower(trim((string) $f['name'])) === GalleryService::INTERN_FOLDER) {
                return (string) $f['id'];
            }
        }
        return $drive->createFolder(GalleryService::INTERN_FOLDER, $albumFolder);
    }

    /**
     * Freigabe einer Einreichung, die in Drive liegt: Datei in den Ordner des Albums verschieben (intern: in „intern“)
     * und als Bild aus Drive aufnehmen. Alben der App haben keinen Ordner in Drive: Dann kommt die Datei zurück auf den
     * Server (wie bisher) und die in Drive in den Papierkorb.
     *
     * @param array<string, mixed> $s Einreichung
     * @param array<string, mixed> $album
     * @return int ID des Bilds
     */
    public function approve(array $s, array $album, bool $intern): int
    {
        $drive = $this->app->drive();
        $fileId = (string) $s['drive_file_id'];
        $stored = [
            'name' => (string) $s['name'],
            'width' => (int) $s['width'],
            'height' => (int) $s['height'],
            'md5' => (string) $s['md5'],
            'bytes' => (int) $s['bytes'],
            'duration_ms' => (int) ($s['duration_ms'] ?? 0),
        ];
        $uploads = $this->app->galleryUploads();
        if ((string) $album['source'] !== 'drive') {
            $tmp = $drive->download($fileId);
            try {
                $ext = strtolower(pathinfo((string) $s['file_key'], PATHINFO_EXTENSION)) ?: 'jpg';
                $key = $this->app->gallery()->media()->adopt((int) $album['id'], $tmp, $ext);
            } finally {
                @unlink($tmp);
            }
            $photoId = $uploads->insertPhoto((int) $album['id'], ['key' => $key] + $stored, $intern);
            $this->trash($fileId);
            return $photoId;
        }
        $folder = (string) $album['drive_id'];
        $drive->moveFile($fileId, $this->submissionsFolder(), $intern ? $this->internFolder($folder) : $folder);
        $photoId = $uploads->insertDrivePhoto((int) $album['id'], $fileId, (string) $s['file_key'], $stored, $intern);
        $this->app->gallery()->notePushed($photoId);
        return $photoId;
    }

    /**
     * Ordner eines Jahres im Galerie-Ordner (Name = Jahr, wie der Abgleich ihn liest); mit $create angelegt, wenn es
     * keinen gibt.
     */
    public function yearFolder(string $year, bool $create = true): ?string
    {
        $drive = $this->app->drive();
        $root = $this->app->gallery()->root();
        foreach ($drive->listChildren($root) as $f) {
            if (DriveClient::isFolder($f) && trim((string) $f['name']) === $year) {
                return (string) $f['id'];
            }
        }
        return $create ? $drive->createFolder($year, $root) : null;
    }

    /** Neues Album als Ordner im Jahresordner; Rückgabe: ID des Ordners. */
    public function createAlbumFolder(string $title, string $year): string
    {
        return $this->app->drive()->createFolder($title, (string) $this->yearFolder($year));
    }

    /**
     * Ordner eines Albums umbenennen bzw. in einen anderen Jahresordner verschieben.
     *
     * @param array<string, mixed> $album Zeile vor der Änderung
     */
    public function updateAlbumFolder(array $album, ?string $title, ?string $year): void
    {
        $drive = $this->app->drive();
        $folder = (string) $album['drive_id'];
        if ($year !== null && $year !== (string) $album['year']) {
            $from = $this->yearFolder((string) $album['year'], false)
                ?? throw new \RuntimeException(sprintf('Der Ordner „%s“ des Albums ist im Galerie-Ordner nicht zu finden.', (string) $album['year']));
            $drive->moveFile($folder, $from, (string) $this->yearFolder($year), $title);
            return;
        }
        if ($title !== null && $title !== (string) $album['title']) {
            $drive->renameFile($folder, $title);
        }
    }

    /**
     * Datei eines Bilds aus Drive in einen anderen Ordner: anderes Album und/oder zwischen Album und „intern“.
     *
     * @param array<string, mixed> $photo
     * @param array<string, mixed> $from Album, in dem es liegt
     * @param array<string, mixed> $to Ziel-Album (Album aus Drive)
     */
    public function movePhotoFile(array $photo, array $from, array $to, bool $intern): void
    {
        $source = (int) $photo['is_intern'] === 1 ? $this->internFolder((string) $from['drive_id']) : (string) $from['drive_id'];
        $target = $intern ? $this->internFolder((string) $to['drive_id']) : (string) $to['drive_id'];
        if ($source !== $target) {
            $this->app->drive()->moveFile((string) $photo['drive_file_id'], $source, $target);
        }
        $this->app->gallery()->notePushed((int) $photo['id']);
    }

    /** Datei in den Papierkorb von Drive; Fehler stehen nur im Protokoll (die Zeile in der App ist dann schon weg). */
    public function trash(string $fileId): void
    {
        if ($fileId === '') {
            return;
        }
        try {
            $this->app->drive()->trashFile($fileId);
        } catch (\RuntimeException $e) {
            error_log('Kompanie-App: Datei ' . $fileId . ' nicht in den Papierkorb von Drive gelegt: ' . $e->getMessage());
        }
    }

    private static function mime(string $key): string
    {
        return VideoUploadService::TYPES[strtolower(pathinfo($key, PATHINFO_EXTENSION))] ?? 'image/jpeg';
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        $c = $this->app->settings->get(self::SETTING);
        return is_array($c) ? $c : [];
    }

    /** @return array{last_try: int, error: string} */
    private function state(): array
    {
        $s = $this->app->settings->get(self::STATE_SETTING);
        return [
            'last_try' => is_array($s) ? (int) ($s['last_try'] ?? 0) : 0,
            'error' => is_array($s) && is_string($s['error'] ?? null) ? $s['error'] : '',
        ];
    }
}
