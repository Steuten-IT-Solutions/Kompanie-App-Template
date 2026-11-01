<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\Http\FileCache;
use KompanieApp\Core\Port\Cache;
use KompanieApp\Core\Port\Environment;
use KompanieApp\Core\Port\FileStorage;
use KompanieApp\Core\Port\ImageResizer;
use KompanieApp\Core\Port\Scheduler;
use KompanieApp\Core\Port\Settings;
use KompanieApp\Core\Storage\GalleryRepository;

/**
 * Galerie aus Google Drive.
 *
 * Ordnerstruktur:  Galerie → Jahr → Album → Bilder und Videos
 *                                          └ intern → Bilder und Videos nur für Mitglieder
 *
 * Videos (MP4 und WebM, Issue #182) werden nicht kopiert: Die App reicht sie beim Abspielen in Ausschnitten aus Drive
 * durch (deliverVideo, Range), interne erst nach Prüfung der Mitgliedschaft. Der Ordner in Drive bleibt privat.
 * Aufbereitet wird nur ihr Vorschaubild (Drives thumbnailLink). Andere Videoformate stehen für Admins in der Liste der
 * fehlgeschlagenen Bilder („nicht unterstützt“).
 *
 * Ein Abgleich (stündlich oder per Knopf) aktualisiert den Index. Danach wird
 * jedes Bild einmalig heruntergeladen und in zwei Größen auf dem eigenen Server
 * abgelegt: öffentliche Bilder liefert der Webserver direkt aus, interne nur
 * die App nach Prüfung der Mitgliedschaft.
 *
 * Aufbereitet wird im Hintergrund (Aufgaben TASK und PROCESS_TASK, außerdem beim Abgleich von
 * Hand mit Zeitbudget) und, damit neue Bilder sofort erscheinen, auch beim Abruf eines noch nicht
 * fertigen Bildes. Damit ein Album-Raster nicht viele gleichzeitige Downloads und Bildberechnungen
 * auslöst, gilt dabei:
 *  - Sperre je Bild (flock): Vorschau und Großansicht desselben Bildes werden nicht doppelt erzeugt;
 *    wer auf ein gerade entstehendes Bild trifft, wartet darauf (höchstens WAIT_SECONDS).
 *  - Höchstens REQUEST_SLOTS Anfragen erzeugen gleichzeitig. Alle weiteren bekommen sofort einen
 *    Platzhalter (202 mit Retry-After) und stoßen die Aufbereitung im Hintergrund an. Ein 202 statt
 *    503, weil nichts kaputt ist; das Original statt des Platzhalters ginge nicht, es liegt nur in Drive.
 * Die Aufbereitung im Hintergrund belegt keinen dieser Plätze (sie läuft ohnehin nur einmal gleichzeitig).
 */
final class GalleryService
{
    public const ROOT_SETTING = 'gallery_root';
    public const STATUS_SETTING = 'gallery_status';
    public const TASK = 'gallery_sync';
    /** Nur aufbereiten (ohne Abgleich mit Drive); läuft bei ausstehenden Bildern bald wieder. */
    public const PROCESS_TASK = 'gallery_process';
    /** Nach so vielen Sekunden soll der Browser ein noch nicht fertiges Bild erneut laden. */
    public const RETRY_AFTER = 10;
    /** Höchstens so viele Anfragen erzeugen gleichzeitig Bilder (Download und Bildberechnung kosten Speicher und Prozesse). */
    public const REQUEST_SLOTS = 2;
    /** So lange wartet ein Prozess höchstens, während ein anderer dasselbe Bild erzeugt. */
    public const WAIT_SECONDS = 20;
    /** Sperren je Bild: feste Zahl an Dateien (Bild-ID modulo Anzahl), keine Datei je Bild. */
    private const IMAGE_LOCKS = 64;
    public const INTERN_FOLDER = 'intern';
    public const SIZES = ['thumb' => 400, 'large' => 1600];
    /**
     * Originalauflösung für die Großansicht (die 1600er erscheint sofort, diese wird nachgeladen). Erst erzeugt, wenn
     * jemand das Bild öffnet: aus dem Original in Drive, neu als JPEG ohne Metadaten (kein GPS-Ort), höchstens
     * ORIGINAL_EDGE Pixel. So belegen nur angesehene Bilder Platz, und der Abgleich bleibt so schnell wie bisher.
     */
    public const ORIGINAL = 'original';
    public const ORIGINAL_EDGE = 8192;
    public const ORIGINAL_QUALITY = 90;
    /** Merker „Original wird erzeugt oder ist gescheitert“; erst nach so vielen Sekunden wieder versuchen. */
    public const ORIGINAL_RETRY = 86400;
    /** Adresse zum Abspielen eines Videos (über die App, auch bei öffentlichen). */
    public const VIDEO = 'video';
    /** Höchstens so viele Bytes je Anfrage aus Drive: Jede Anfrage bleibt kurz, der Browser fordert den Rest nach. */
    public const VIDEO_CHUNK = 4 * 1024 * 1024;
    /** Höchstens so viele Ausschnitte gleichzeitig aus Drive; weitere warten kurz (VIDEO_WAIT Sekunden). */
    public const VIDEO_SLOTS = 6;
    public const VIDEO_WAIT = 5;
    public const UNSUPPORTED_VIDEO = 'Videoformat nicht unterstützt (nur MP4 und WebM).';
    /** So oft wird ein Bild aufbereitet, bevor es als dauerhaft fehlerhaft gilt. */
    public const MAX_ATTEMPTS = 5;
    /** Höchstens so viele fehlgeschlagene Bilder zeigt Einstellungen → Galerie. */
    public const FAILED_LIST_MAX = 200;

    /** Wartezeit auf die Sperre eines Bildes in Sekunden (Tests setzen 0). */
    public int $waitSeconds = self::WAIT_SECONDS;

    public function __construct(
        private readonly GalleryRepository $repo,
        private readonly DriveClient $drive,
        private readonly FileStorage $files,
        private readonly ImageResizer $images,
        private readonly Settings $settings,
        private readonly Environment $env,
        private readonly Time $time,
        private readonly Scheduler $scheduler,
        private readonly Cache $cache,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->root() !== '' && $this->drive->hasCredentials();
    }

    public function root(): string
    {
        return (string) $this->settings->get(self::ROOT_SETTING, '');
    }

    /** Ordner aus einem Drive-Link setzen; leer löscht ihn. */
    public function setRoot(string $input): void
    {
        $id = DriveClient::parseFolderId($input);
        if (trim($input) !== '' && $id === '') {
            throw new \RuntimeException('Der Galerie-Ordner wurde nicht erkannt.');
        }
        $this->settings->set(self::ROOT_SETTING, $id);
    }

    /** @return array<string, mixed> Stand des letzten Abgleichs */
    public function status(): array
    {
        $s = $this->settings->get(self::STATUS_SETTING, []);
        return is_array($s) ? $s : [];
    }

    /** Abgleich und Aufbereitung als geplante Aufgabe. */
    public function runTask(int $budgetSeconds = 25): void
    {
        if (!$this->isConfigured()) {
            return;
        }
        try {
            $this->sync();
        } catch (\RuntimeException) {
            // Fehler steht im Status; die Aufbereitung läuft trotzdem weiter.
        }
        $this->processPending($budgetSeconds);
        $this->continueSoon();
    }

    /** Nur aufbereiten, als geplante Aufgabe; bei weiteren ausstehenden Bildern bald wieder. */
    public function runProcessTask(int $budgetSeconds = 25): void
    {
        if (!$this->isConfigured()) {
            return;
        }
        $this->processPending($budgetSeconds);
        $this->continueSoon();
    }

    /** Sind noch Bilder aufzubereiten, die Aufgabe dafür bald wieder laufen lassen. */
    private function continueSoon(): void
    {
        if ($this->repo->countPending() > 0) {
            $this->scheduler->runSoon(self::PROCESS_TASK);
        }
    }

    /**
     * @return array{albums: int, photos: int, new: int, pending: int}
     * @throws \RuntimeException mit verständlicher Meldung
     */
    public function sync(): array
    {
        $root = $this->root();
        if ($root === '') {
            throw $this->fail('Es ist noch kein Galerie-Ordner eingetragen.');
        }
        try {
            $seenAlbums = [];
            $seenPhotos = [];
            $stats = ['albums' => 0, 'photos' => 0, 'new' => 0];
            // Alle Alben mit einer Abfrage statt einer je Album.
            $albumRows = $this->repo->albumsByDriveId();

            foreach (array_filter($this->drive->listChildren($root), [DriveClient::class, 'isFolder']) as $year) {
                foreach (array_filter($this->drive->listChildren($year['id']), [DriveClient::class, 'isFolder']) as $album) {
                    $images = [];
                    foreach ($this->drive->listChildren($album['id']) as $f) {
                        if (self::isMedia($f)) {
                            $images[] = [$f, 0];
                        } elseif (DriveClient::isFolder($f) && mb_strtolower(trim((string) $f['name'])) === self::INTERN_FOLDER) {
                            foreach (array_filter($this->drive->listChildren($f['id']), [self::class, 'isMedia']) as $img) {
                                $images[] = [$img, 1];
                            }
                        }
                    }
                    // Dieselbe Datei kann zweimal kommen (mehrere Ordner in Drive): nur einmal anlegen.
                    $unique = [];
                    foreach ($images as $image) {
                        $unique[(string) $image[0]['id']] ??= $image;
                    }
                    $images = array_values($unique);
                    if ($images === []) {
                        continue;
                    }
                    $albumRow = $albumRows[(string) $album['id']] ?? null;
                    $albumData = $this->albumData($album, (string) $year['name'], $images);
                    // Eine Abfrage für die vorhandenen Bilder des Albums (auch aus anderen Alben verschobene).
                    $existing = $this->repo->photosByDriveIds(array_keys($unique));
                    $plans = $albumRow !== null ? $this->planPhotos((int) $albumRow['id'], $images, $existing) : null;
                    if ($albumRow !== null && $plans !== null && !GalleryRepository::changed($albumRow, $albumData) && !self::hasWrites($plans)) {
                        // Nichts geändert: keine Transaktion (in SQLite holt sie die Schreibsperre).
                        $seenAlbums[] = (int) $albumRow['id'];
                        $stats['albums']++;
                        foreach ($plans as $plan) {
                            $seenPhotos[] = (int) $plan['row']['id'];
                            $stats['photos']++;
                        }
                        continue;
                    }
                    // Je Album eine Transaktion (ein Commit statt einem je Bild); geschrieben wird nur, was sich
                    // geändert hat. Die Anfragen an Drive liegen außerhalb, damit die Datenbank nicht lange gesperrt ist.
                    // In der Transaktion neu gelesen: Ein gleichzeitiger zweiter Abgleich soll nichts doppelt anlegen.
                    $this->repo->transaction(function () use ($album, $albumData, $images, $unique, &$seenAlbums, &$seenPhotos, &$stats): void {
                        $albumRow = $this->repo->albumByDriveId((string) $album['id']);
                        $albumId = $this->repo->saveAlbum((string) $album['id'], $albumRow, $albumData, ['synced_at' => $this->time->nowUtc()]);
                        $seenAlbums[] = $albumId;
                        $stats['albums']++;
                        foreach ($this->planPhotos($albumId, $images, $this->repo->photosByDriveIds(array_keys($unique))) as $plan) {
                            [$photoId, $isNew] = $this->applyPlan($plan);
                            $seenPhotos[] = $photoId;
                            $stats['photos']++;
                            $stats['new'] += $isNew ? 1 : 0;
                        }
                    });
                }
            }
        } catch (\RuntimeException $e) {
            throw $this->fail($e->getMessage());
        }

        // Aufräumen nur, wenn es etwas zu tun gibt: Ein Abgleich ohne Änderungen braucht keine Transaktion.
        $gone = $this->repo->photosExcept($seenPhotos);
        $staleAlbums = array_values(array_diff(array_map(static fn (array $a): int => (int) $a['id'], $albumRows), $seenAlbums));
        if ($gone !== [] || $staleAlbums !== [] || $this->repo->countRetryable(self::MAX_ATTEMPTS) > 0) {
            $this->repo->transaction(function () use ($gone, $staleAlbums): void {
                // Was in Drive nicht mehr vorhanden ist, verschwindet samt Dateien.
                foreach ($gone as $photo) {
                    $this->repo->deletePhoto((int) $photo['id']);
                }
                $this->repo->deleteAlbums($staleAlbums);
                // Vorübergehende Fehler (Zeitüberschreitung, Störung bei Google) beim nächsten Mal erneut versuchen.
                $this->repo->retryFailed(self::MAX_ATTEMPTS);
            });
        }
        foreach ($gone as $photo) {
            $this->deleteFiles($photo);
        }

        $stats['pending'] = $this->repo->countPending();
        $this->settings->set(self::STATUS_SETTING, $stats + ['last_sync' => $this->time->timestamp(), 'error' => '']);
        return $stats;
    }

    /** Bild oder Video (auch nicht abspielbare: die kommen in die Liste für Admins). */
    public static function isMedia(array $f): bool
    {
        return DriveClient::isImage($f) || DriveClient::isVideo($f);
    }

    private function fail(string $message): \RuntimeException
    {
        $status = $this->status();
        $status['error'] = $message;
        $status['last_sync'] = $this->time->timestamp();
        $this->settings->set(self::STATUS_SETTING, $status);
        return new \RuntimeException($message);
    }

    /** EXIF-Zeit „2026:06:27 18:45:10“ oder RFC 3339 → UTC 'Y-m-d H:i:s'. */
    private static function driveTime(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $value = (string) preg_replace('/^(\d{4}):(\d{2}):(\d{2})/', '$1-$2-$3', $value);
        $ts = strtotime($value);
        return $ts ? gmdate('Y-m-d H:i:s', $ts) : null;
    }

    /**
     * Felder eines Albums aus Drive; synced_at allein ist keine Änderung (siehe saveAlbum).
     *
     * @return array{year: string, title: string, sort_date: string}
     */
    private function albumData(array $folder, string $year, array $images): array
    {
        $dates = [];
        foreach ($images as [$file]) {
            $taken = self::driveTime($file['imageMediaMetadata']['time'] ?? '');
            if ($taken) {
                $dates[] = $taken;
            }
        }
        $sort = $dates ? min($dates) : self::driveTime($folder['createdTime'] ?? '');
        return [
            'year' => mb_substr(trim($year), 0, 20),
            'title' => mb_substr(trim((string) $folder['name']), 0, 191),
            'sort_date' => $sort ?: $this->time->nowUtc(),
        ];
    }

    /**
     * Was je Bild eines Albums zu schreiben ist, ohne zu schreiben.
     *
     * @param array<int, array{0: array<string, mixed>, 1: int}> $images Drive-Datei und intern (0/1)
     * @param array<string, array<string, mixed>> $existing vorhandene Zeilen nach Drive-ID
     * @return array<int, array{row: array<string, mixed>|null, data: array<string, mixed>|null, renew: bool}>
     *         row null: neu anlegen (data); data null: unverändert; renew: Inhalt neu, Dateien löschen
     */
    private function planPhotos(int $albumId, array $images, array $existing): array
    {
        $plans = [];
        foreach ($images as [$f, $intern]) {
            $row = $existing[(string) $f['id']] ?? null;
            $meta = $f['imageMediaMetadata'] ?? [];
            $md5 = (string) ($f['md5Checksum'] ?? md5($f['id'] . ($f['modifiedTime'] ?? '')));
            $data = [
                'album_id' => $albumId,
                'name' => mb_substr((string) $f['name'], 0, 255),
                'is_intern' => $intern,
                'taken_at' => self::driveTime($meta['time'] ?? ''),
            ];
            $video = DriveClient::isVideo($f);
            if ($video) {
                $meta = $f['videoMediaMetadata'] ?? [];
                $data += [
                    'media' => 'video',
                    'mime' => mb_substr((string) $f['mimeType'], 0, 100),
                    'bytes' => max(0, (int) ($f['size'] ?? 0)),
                    'duration_ms' => max(0, (int) ($meta['durationMillis'] ?? 0)),
                ];
            } else {
                $data['media'] = 'image';
            }
            // Nicht abspielbare Videos: gleich als fehlgeschlagen (Liste für Admins), ohne weitere Versuche.
            $state = $video && !DriveClient::isPlayableVideo((string) $f['mimeType'])
                ? ['status' => GalleryRepository::FAILED, 'error' => self::UNSUPPORTED_VIDEO, 'attempts' => self::MAX_ATTEMPTS]
                : ['status' => GalleryRepository::PENDING, 'error' => '', 'attempts' => 0];
            // Maße aus Drive nur für neue oder geänderte Bilder (bzw. solange keine bekannt sind):
            // Nach dem Aufbereiten stehen dort die Maße der Großansicht.
            $size = ['width' => (int) ($meta['width'] ?? 0), 'height' => (int) ($meta['height'] ?? 0)];
            if (!$row) {
                $plans[] = ['row' => null, 'renew' => false, 'data' => $data + $size + $state + [
                    'drive_file_id' => (string) $f['id'],
                    'md5' => $md5,
                ]];
                continue;
            }
            // Neuer Inhalt oder zwischen „intern“ und öffentlich bzw. in ein anderes Album verschoben: neu erzeugen.
            $renew = $row['md5'] !== $md5 || (int) $row['is_intern'] !== $intern || (int) $row['album_id'] !== $albumId;
            if ($renew) {
                $data += $size + ['md5' => $md5] + $state;
            } elseif ((int) $row['width'] === 0 && $size['width'] > 0) {
                $data += $size;
            }
            // Nur schreiben, wenn sich etwas geändert hat (sonst bei 2.000 Bildern 2.000 UPDATEs pro Stunde).
            $plans[] = ['row' => $row, 'renew' => $renew, 'data' => $renew || GalleryRepository::changed($row, $data) ? $data : null];
        }
        return $plans;
    }

    /** @param array<int, array{row: array<string, mixed>|null, data: array<string, mixed>|null, renew: bool}> $plans */
    private static function hasWrites(array $plans): bool
    {
        foreach ($plans as $plan) {
            if ($plan['data'] !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array{row: array<string, mixed>|null, data: array<string, mixed>|null, renew: bool} $plan
     * @return array{0: int, 1: bool} ID und ob neu
     */
    private function applyPlan(array $plan): array
    {
        $row = $plan['row'];
        if ($row === null) {
            return [$this->repo->insertPhoto((array) $plan['data'] + ['file_key' => bin2hex(random_bytes(16))]), true];
        }
        if ($plan['renew']) {
            $this->deleteFiles($row);
            $plan['data'] = (array) $plan['data'] + ['file_key' => bin2hex(random_bytes(16))];
        }
        if ($plan['data'] !== null) {
            $this->repo->updatePhoto((int) $row['id'], $plan['data']);
        }
        return [(int) $row['id'], false];
    }

    public function countFailed(): int
    {
        return $this->repo->countFailed();
    }

    /**
     * Bilder, die sich nicht laden ließen, für Admins: Dateiname, Album, Fehlermeldung und Versuche, dazu die Adresse
     * der Datei in Google Drive (dort lässt sie sich prüfen oder ersetzen). unsupported: Video in einem Format, das die
     * Galerie nicht abspielt (ohne Versuche).
     *
     * @return list<array{id: int, name: string, album: string, intern: bool, error: string, attempts: int, unsupported: bool, drive_url: string}>
     */
    public function failedPhotos(int $limit = self::FAILED_LIST_MAX): array
    {
        return array_values(array_map(static fn (array $p): array => [
            'id' => (int) $p['id'],
            'name' => (string) $p['name'],
            'album' => trim((string) ($p['album_year'] ?? '') . ' · ' . (string) ($p['album_title'] ?? ''), ' ·'),
            'intern' => (bool) (int) $p['is_intern'],
            'error' => (string) $p['error'],
            'attempts' => (int) $p['attempts'],
            'unsupported' => (string) $p['error'] === self::UNSUPPORTED_VIDEO,
            'drive_url' => 'https://drive.google.com/file/d/' . rawurlencode((string) $p['drive_file_id']) . '/view',
        ], $this->repo->failedPhotos($limit)));
    }

    /**
     * Alle fehlgeschlagenen Bilder erneut aufbereiten (auch nach MAX_ATTEMPTS Versuchen), z. B. nachdem der Hoster mehr
     * Speicher gibt. Die Aufbereitung selbst läuft wie sonst (Aufgabe bzw. „Jetzt abgleichen“).
     */
    public function retryFailedNow(): int
    {
        $n = $this->repo->resetFailed();
        if ($n > 0) {
            $status = $this->status();
            $status['pending'] = $this->repo->countPending();
            $this->settings->set(self::STATUS_SETTING, $status);
        }
        return $n;
    }

    public function countPending(): int
    {
        return $this->repo->countPending();
    }

    /**
     * Erzeugt Bilder, bis das Zeitbudget aufgebraucht ist. Neueste Alben zuerst.
     * Es bereitet immer nur ein Prozess auf (Sperre); ein zweiter gibt sofort 0 zurück,
     * statt dieselben Bilder noch einmal zu laden. Erzeugt gerade eine Anfrage ein Bild
     * (Sperre je Bild), wartet die Aufbereitung darauf und nimmt danach das nächste.
     */
    public function processPending(int $budgetSeconds = 20): int
    {
        $lock = @fopen($this->files->path('.aufbereitung.lock', false), 'c');
        if ($lock !== false && !flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return 0;
        }
        try {
            $start = microtime(true);
            $done = 0;
            // Videos, deren Vorschaubild Drive noch nicht hat, bleiben ausstehend: in diesem Lauf nicht noch einmal.
            $tried = [];
            while (microtime(true) - $start < $budgetSeconds) {
                $row = $this->repo->nextPending($tried);
                if (!$row) {
                    break;
                }
                $imageLock = $this->lockFile(self::imageLockName((int) $row['id']), $this->waitSeconds);
                if ($imageLock === null) {
                    break; // Eine Anfrage hängt an diesem Bild fest: beim nächsten Lauf weiter.
                }
                try {
                    // Inzwischen von einer Anfrage erzeugt? Dann nicht noch einmal.
                    $row = $this->repo->photo((int) $row['id']);
                    if ($row && $row['status'] === GalleryRepository::PENDING) {
                        $this->generate($row);
                        $tried[] = (int) $row['id'];
                        $done++;
                    }
                } finally {
                    self::unlock($imageLock);
                }
            }
            $status = $this->status();
            $pending = $this->repo->countPending();
            if (($status['pending'] ?? null) !== $pending) {
                $status['pending'] = $pending;
                $this->settings->set(self::STATUS_SETTING, $status);
            }
            return $done;
        } finally {
            if ($lock !== false) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    public function generate(array $photo): bool
    {
        if (self::isVideoRow($photo)) {
            return $this->generateVideo($photo);
        }
        $attempts = (int) ($photo['attempts'] ?? 0);
        if ($attempts >= self::MAX_ATTEMPTS) {
            // Das Original bringt PHP jedes Mal zum Abbruch (Speicher, Zeitlimit); Googles kleineres Vorschaubild geht oft noch.
            // Vorher als fehlgeschlagen vermerken: Bricht auch das ab, wird es nicht bei jedem Lauf wieder versucht.
            $this->markFailed($photo, 'Die Aufbereitung ist mehrfach abgebrochen (Bild zu groß für den Speicher des Servers?).');
            $size = $this->fromDriveThumbnail($photo);
            return $size !== null && $this->markReady($photo, $size);
        }
        // Den Versuch vorab vermerken: Bricht PHP mittendrin ab (Speicher, Zeitlimit), kommt das Bild
        // beim nächsten Lauf nach den anderen dran (nextPending) und hält sie nicht auf.
        $this->repo->updatePhoto((int) $photo['id'], ['attempts' => $attempts + 1]);
        try {
            $tmp = $this->drive->download((string) $photo['drive_file_id']);
        } catch (\RuntimeException $e) {
            return $this->markFailed($photo, $e->getMessage());
        }
        try {
            $size = $this->render($photo, $tmp);
        } catch (\Throwable $e) {
            // auch Fehler von GD (z. B. ValueError), nicht nur RuntimeException.
            // Formate, die der Server nicht lesen kann (RAW, TIFF, oft auch HEIC): erst das eingebettete JPEG
            // einer RAW-Datei, dann das Vorschaubild von Google.
            $size = $this->fromRawPreview($photo, $tmp) ?? $this->fromDriveThumbnail($photo);
            if ($size === null) {
                return $this->markFailed($photo, $e->getMessage());
            }
        } finally {
            @unlink($tmp);
        }
        return $this->markReady($photo, $size);
    }

    /** @param array<string, mixed> $photo */
    private static function isVideoRow(array $photo): bool
    {
        return ($photo['media'] ?? 'image') === 'video';
    }

    /**
     * Video: nur das Vorschaubild (beide Größen aus Drives thumbnailLink), das Video selbst bleibt in Drive. Hat Drive
     * noch keines (gerade hochgeladen), bleibt es ausstehend; nach MAX_ATTEMPTS Versuchen gilt es ohne Vorschaubild als
     * fertig (die App zeigt dann eine Kachel mit ▶).
     */
    private function generateVideo(array $photo): bool
    {
        if (!DriveClient::isPlayableVideo((string) ($photo['mime'] ?? ''))) {
            $this->repo->updatePhoto((int) $photo['id'], ['status' => GalleryRepository::FAILED, 'error' => self::UNSUPPORTED_VIDEO, 'attempts' => self::MAX_ATTEMPTS]);
            return false;
        }
        $attempts = (int) ($photo['attempts'] ?? 0) + 1;
        $this->repo->updatePhoto((int) $photo['id'], ['attempts' => $attempts]);
        $size = $this->fromDriveThumbnail($photo);
        if ($size === null && $attempts < self::MAX_ATTEMPTS) {
            return false;
        }
        // Maße des Videos aus Drive behalten; nur ohne sie die des Vorschaubilds.
        return $this->markReady($photo, (int) $photo['width'] > 0 ? null : $size);
    }

    /**
     * Beide Größen aus einer Bilddatei.
     *
     * @return array{width: int, height: int}|null Größe der Großansicht
     */
    private function render(array $photo, string $file): ?array
    {
        $size = null;
        foreach (self::SIZES as $name => $max) {
            $size = $this->images->resizeToJpeg($file, $this->path($photo, $name), $max, $name === 'thumb' ? 78 : 84);
        }
        return $size;
    }

    /** @return array{width: int, height: int}|null */
    private function fromRawPreview(array $photo, string $file): ?array
    {
        $preview = $this->files->tempFile();
        try {
            return RawPreview::extract($file, $preview, self::SIZES['thumb']) ? $this->render($photo, $preview) : null;
        } catch (\Throwable) {
            return null;
        } finally {
            @unlink($preview);
        }
    }

    /** @return array{width: int, height: int}|null */
    private function fromDriveThumbnail(array $photo): ?array
    {
        try {
            $thumb = $this->drive->thumbnail((string) $photo['drive_file_id'], self::SIZES['large']);
        } catch (\RuntimeException) {
            return null;
        }
        try {
            return $this->render($photo, $thumb);
        } catch (\Throwable) {
            return null;
        } finally {
            @unlink($thumb);
        }
    }

    /** @param array{width: int, height: int}|null $size */
    private function markReady(array $photo, ?array $size): bool
    {
        $this->repo->updatePhoto((int) $photo['id'], [
            'status' => GalleryRepository::READY,
            'error' => '',
            'attempts' => 0,
            'width' => $size['width'] ?? (int) $photo['width'],
            'height' => $size['height'] ?? (int) $photo['height'],
        ]);
        return true;
    }

    private function markFailed(array $photo, string $message): bool
    {
        $this->repo->updatePhoto((int) $photo['id'], [
            'status' => GalleryRepository::FAILED,
            'error' => mb_substr($message, 0, 255),
            'attempts' => (int) ($photo['attempts'] ?? 0) + 1,
        ]);
        return false;
    }

    /** Relativer Pfad: öffentlich „<album>/<key>-<größe>.jpg“, intern „<key>-<größe>.jpg“. */
    public static function relativePath(array $photo, string $size): string
    {
        $file = $photo['file_key'] . '-' . $size . '.jpg';
        return (int) $photo['is_intern'] ? $file : (int) $photo['album_id'] . '/' . $file;
    }

    public function path(array $photo, string $size): string
    {
        return $this->files->path(self::relativePath($photo, $size), !(int) $photo['is_intern']);
    }

    public function deleteFiles(array $photo): void
    {
        foreach ([...array_keys(self::SIZES), self::ORIGINAL] as $size) {
            $this->files->delete(self::relativePath($photo, $size), !(int) $photo['is_intern']);
        }
        $this->files->delete(self::markerPath($photo), false);
    }

    /** Merker für die Originalauflösung, immer im geschützten Verzeichnis (nie öffentlich abrufbar). */
    private static function markerPath(array $photo): string
    {
        return '.original-' . $photo['file_key'] . '.merker';
    }

    /**
     * Öffentliche fertige Bilder direkt als Datei, sonst über die App (das Original erst, wenn es erzeugt ist). Über die
     * App mit Fassung (?v=, aus der Prüfsumme in Drive): Ein geändertes Bild hat so eine neue Adresse, und der Service
     * Worker kann interne Bilder auf dem Gerät behalten, ohne je eine alte Fassung zu zeigen.
     */
    public function url(array $photo, string $size): string
    {
        if (!(int) $photo['is_intern'] && $photo['status'] === GalleryRepository::READY
            && ($size !== self::ORIGINAL || is_file($this->path($photo, $size)))) {
            return $this->files->publicUrl(self::relativePath($photo, $size));
        }
        return self::withVersion($this->env->photoUrl((int) $photo['id'], $size), $photo);
    }

    /** Fassung eines Bilds an seiner Adresse (ohne bekannte Prüfsumme unverändert). */
    private static function withVersion(string $url, array $photo): string
    {
        $md5 = (string) ($photo['md5'] ?? '');
        if ($md5 === '') {
            return $url;
        }
        return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . rawurlencode(substr($md5, 0, 12));
    }

    /**
     * Ein Bild für die Auslieferung über die App (interne oder noch nicht erzeugte).
     *
     * Noch nicht aufbereitete Bilder werden hier erzeugt (beide Größen), mit Sperre je Bild und
     * höchstens REQUEST_SLOTS gleichzeitig. Ist kein Platz frei, ist die Antwort 202 mit einem
     * Platzhalter (SVG im Seitenverhältnis des Fotos) und Retry-After; dann übernimmt die
     * Aufbereitung im Hintergrund (sie wird hier angestoßen).
     *
     * @return array{status: int, file?: string, etag?: string|null, intern?: bool, placeholder?: string, retryAfter?: int}
     */
    public function photoForDelivery(int $id, string $size, bool $member, bool $loggedIn): array
    {
        return $this->deliverPhoto($id, $size, static fn (): array => [$member, $loggedIn]);
    }

    /**
     * Wie photoForDelivery, für die Adapter: Wer anfragt, wird nur bei internen Bildern ermittelt
     * (Sitzung und Konto kosten Abfragen; öffentliche Bilder brauchen sie nicht). Fertige Bilder
     * bekommen einen ETag (FileCache), damit der Browser mit If-None-Match nachfragen kann (304).
     *
     * @param \Closure(): array{0: bool, 1: bool} $access [Mitglied, angemeldet]
     * @return array{status: int, file?: string, etag?: string|null, intern?: bool, placeholder?: string, retryAfter?: int}
     */
    public function deliverPhoto(int $id, string $size, \Closure $access): array
    {
        if (!isset(self::SIZES[$size]) && $size !== self::ORIGINAL) {
            return ['status' => 404];
        }
        $photo = $this->repo->photo($id);
        if (!$photo) {
            return ['status' => 404];
        }
        $intern = (bool) (int) $photo['is_intern'];
        if ($intern) {
            [$member, $loggedIn] = $access();
            if (!$member) {
                return ['status' => $loggedIn ? 403 : 401];
            }
        }
        $file = $this->path($photo, $size);
        // Fehlgeschlagene Bilder nicht bei jedem Aufruf erneut aus Drive laden lassen
        // (sonst könnten Besucher beliebig viele Downloads auslösen); der Abgleich versucht
        // sie erneut (retryFailed, höchstens MAX_ATTEMPTS-mal).
        if ($photo['status'] === GalleryRepository::FAILED) {
            return ['status' => 404];
        }
        $video = self::isVideoRow($photo);
        if ($video && ($size === self::ORIGINAL || ($photo['status'] === GalleryRepository::READY && !is_file($file)))) {
            return ['status' => 404]; // Video: kein Original; fertig ohne Vorschaubild (Drive hatte keines)
        }
        if ($size === self::ORIGINAL) {
            // Ohne Original zeigt die Großansicht einfach die 1600er weiter (404 statt Platzhalter).
            $file = $photo['status'] === GalleryRepository::READY ? $this->original($photo) : null;
            return $file === null ? ['status' => 404]
                : ['status' => 200, 'file' => $file, 'etag' => FileCache::etag(self::relativePath($photo, $size), $file), 'intern' => $intern];
        }
        if ($photo['status'] === GalleryRepository::READY && !is_file($file)) {
            // Datei fehlt (z. B. gelöscht): neu aufbereiten.
            $this->repo->updatePhoto((int) $photo['id'], ['status' => GalleryRepository::PENDING, 'attempts' => 0]);
            $photo['status'] = GalleryRepository::PENDING;
            $photo['attempts'] = 0;
        }
        if ($photo['status'] !== GalleryRepository::READY) {
            $now = $this->generateNow($photo);
            if ($now === null || $now['status'] === GalleryRepository::PENDING) {
                $this->nudge();
                return ['status' => 202, 'placeholder' => self::placeholder((int) $photo['width'], (int) $photo['height']), 'retryAfter' => self::RETRY_AFTER, 'intern' => $intern];
            }
            if ($now['status'] !== GalleryRepository::READY) {
                return ['status' => 404]; // Erzeugung gescheitert (Fehler steht am Bild)
            }
            $photo = $now;
            $file = $this->path($photo, $size);
        }
        return ['status' => 200, 'file' => $file, 'etag' => FileCache::etag(self::relativePath($photo, $size), $file), 'intern' => $intern];
    }

    /**
     * Ein Ausschnitt eines Videos zum Abspielen, aus Drive durchgereicht (nichts wird gespeichert). Interne Videos nur für
     * Mitglieder, Rechte wie bei deliverPhoto. Antwort immer 206 mit höchstens VIDEO_CHUNK Bytes (auch ohne Range: dann
     * ab dem Anfang); der Browser fordert den Rest mit Range nach. 416, wenn der Ausschnitt hinter dem Ende beginnt.
     *
     * @param string|null $range Wert der Kopfzeile Range („bytes=0-“)
     * @param \Closure(): array{0: bool, 1: bool} $access [Mitglied, angemeldet]
     * @return array{status: int, body?: string, contentRange?: string, mime?: string, etag?: string, intern?: bool, retryAfter?: int}
     */
    public function deliverVideo(int $id, ?string $range, \Closure $access): array
    {
        $photo = $this->repo->photo($id);
        if (!$photo || !self::isVideoRow($photo) || $photo['status'] === GalleryRepository::FAILED) {
            return ['status' => 404];
        }
        $intern = (bool) (int) $photo['is_intern'];
        if ($intern) {
            [$member, $loggedIn] = $access();
            if (!$member) {
                return ['status' => $loggedIn ? 403 : 401];
            }
        }
        $total = (int) $photo['bytes'];
        $span = self::byteRange($range, $total);
        if ($span === null) {
            return ['status' => 416, 'contentRange' => 'bytes */' . $total];
        }
        [$start, $end] = $span;
        $end = min($end, $start + self::VIDEO_CHUNK - 1);
        $slot = $this->videoSlot();
        if ($slot === null) {
            return ['status' => 503, 'retryAfter' => 1];
        }
        try {
            $part = $this->drive->downloadRange((string) $photo['drive_file_id'], $start, $end);
        } catch (\RuntimeException) {
            return ['status' => 502];
        } finally {
            self::unlock($slot);
        }
        return [
            'status' => 206,
            'body' => $part['body'],
            'contentRange' => 'bytes ' . $part['start'] . '-' . $part['end'] . '/' . $part['total'],
            'mime' => (string) $photo['mime'],
            'etag' => '"v' . md5((string) $photo['md5']) . '"',
            'intern' => $intern,
        ];
    }

    /**
     * Erster Bereich aus „bytes=a-b“, „bytes=a-“ oder „bytes=-n“ (die letzten n Bytes); ohne oder mit unlesbarer Angabe
     * ab dem Anfang. $total 0: Größe unbekannt (dann ohne „-n“).
     *
     * @return array{0: int, 1: int}|null Anfang und Ende (einschließlich); null, wenn der Anfang hinter dem Ende liegt
     */
    public static function byteRange(?string $header, int $total): ?array
    {
        $last = $total > 0 ? $total - 1 : PHP_INT_MAX;
        if ($header === null || !preg_match('/^\s*bytes\s*=\s*(\d*)\s*-\s*(\d*)\s*(?:,|$)/i', $header, $m) || ($m[1] === '' && $m[2] === '')) {
            return [0, $last];
        }
        if ($m[1] === '') {
            // Die letzten n Bytes
            return $total > 0 && (int) $m[2] > 0 ? [max(0, $total - (int) $m[2]), $last] : [0, $last];
        }
        $start = (int) $m[1];
        $end = $m[2] === '' ? $last : min((int) $m[2], $last);
        if (($total > 0 && $start >= $total) || $end < $start) {
            return null;
        }
        return [$start, $end];
    }

    /** @return resource|false|null einer der VIDEO_SLOTS Plätze; wartet höchstens VIDEO_WAIT Sekunden (false: ohne Sperre) */
    private function videoSlot()
    {
        $until = microtime(true) + ($this->waitSeconds > 0 ? self::VIDEO_WAIT : 0);
        do {
            for ($i = 0; $i < self::VIDEO_SLOTS; $i++) {
                $slot = $this->lockFile('.video-' . $i . '.lock', 0);
                if ($slot !== null) {
                    return $slot;
                }
            }
            usleep(100000);
        } while (microtime(true) < $until);
        return null;
    }

    /**
     * Pfad der Fassung in Originalauflösung; erzeugt sie bei Bedarf (Sperre je Bild, höchstens REQUEST_SLOTS
     * gleichzeitig). null, wenn es sie nicht gibt: gerade kein Platz frei, oder der letzte Versuch ist keinen Tag her
     * und gescheitert (Format, Speicher, Zeitlimit). Der Merker wird vor dem Versuch geschrieben und erst nach Erfolg
     * gelöscht: Bricht PHP mittendrin ab, lädt nicht jeder weitere Klick das Original erneut aus Drive.
     */
    private function original(array $photo): ?string
    {
        $file = $this->path($photo, self::ORIGINAL);
        $marker = $this->files->path(self::markerPath($photo), false);
        if (is_file($file)) {
            return $file;
        }
        if (is_file($marker) && (int) @filemtime($marker) > $this->time->timestamp() - self::ORIGINAL_RETRY) {
            return null;
        }
        $imageLock = $this->lockFile(self::imageLockName((int) $photo['id']), $this->waitSeconds);
        if (!is_resource($imageLock)) {
            return null;
        }
        try {
            if (is_file($file)) {
                return $file; // inzwischen von einem anderen Prozess erzeugt
            }
            $slot = $this->requestSlot();
            if ($slot === null) {
                return null;
            }
            try {
                @file_put_contents($marker, (string) $this->time->timestamp());
                @touch($marker, $this->time->timestamp());
                if (!$this->renderOriginal($photo, $file)) {
                    return null;
                }
                @unlink($marker);
                return $file;
            } finally {
                self::unlock($slot);
            }
        } finally {
            self::unlock($imageLock);
        }
    }

    private function renderOriginal(array $photo, string $target): bool
    {
        try {
            $tmp = $this->drive->download((string) $photo['drive_file_id']);
        } catch (\RuntimeException) {
            return false;
        }
        try {
            $this->images->resizeToJpeg($tmp, $target, self::ORIGINAL_EDGE, self::ORIGINAL_QUALITY);
            return true;
        } catch (\Throwable) {
            // RAW: das eingebettete JPEG der Kamera, meist in voller Größe (kleiner als die 1600er lohnt nicht).
            $preview = $this->files->tempFile();
            try {
                return RawPreview::extract($tmp, $preview, self::SIZES['large'] + 1)
                    && $this->images->resizeToJpeg($preview, $target, self::ORIGINAL_EDGE, self::ORIGINAL_QUALITY) !== [];
            } catch (\Throwable) {
                @unlink($target);
                return false;
            } finally {
                @unlink($preview);
            }
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Ein noch nicht fertiges Bild während der Anfrage erzeugen.
     *
     * @param array<string, mixed> $photo
     * @return array<string, mixed>|null aktuelle Zeile (fertig oder fehlgeschlagen); null, wenn es jetzt
     *         nicht geht (kein Platz frei, Bild wird zu lange von einem anderen Prozess erzeugt, keine Sperre möglich)
     */
    private function generateNow(array $photo): ?array
    {
        $id = (int) $photo['id'];
        // Erst die Sperre des Bildes, dann einen Platz: Wer nur auf ein Bild wartet, belegt keinen.
        $imageLock = $this->lockFile(self::imageLockName($id), $this->waitSeconds);
        if (!is_resource($imageLock)) {
            return null;
        }
        try {
            $row = $this->repo->photo($id);
            if (!$row || $row['status'] !== GalleryRepository::PENDING) {
                return $row; // inzwischen erzeugt (oder fehlgeschlagen)
            }
            $slot = $this->requestSlot();
            if ($slot === null) {
                return null;
            }
            try {
                $this->generate($row);
            } finally {
                self::unlock($slot);
            }
            return $this->repo->photo($id);
        } finally {
            self::unlock($imageLock);
        }
    }

    /** @return resource|null einer der REQUEST_SLOTS Plätze zum Erzeugen; null, wenn alle belegt sind */
    private function requestSlot()
    {
        for ($i = 0; $i < self::REQUEST_SLOTS; $i++) {
            $slot = $this->lockFile('.erzeugen-' . $i . '.lock', 0);
            if (is_resource($slot)) {
                return $slot;
            }
        }
        return null;
    }

    private static function imageLockName(int $id): string
    {
        return '.bild-' . ($id % self::IMAGE_LOCKS) . '.lock';
    }

    /**
     * Exklusive Dateisperre im geschützten Verzeichnis, mit Wartezeit.
     *
     * @return resource|false|null die gesperrte Datei; null, wenn sie nach $waitSeconds noch belegt ist;
     *         false, wenn sich die Datei nicht anlegen lässt (dann geht es ohne Sperre weiter oder gar nicht)
     */
    private function lockFile(string $name, int $waitSeconds)
    {
        try {
            $fp = @fopen($this->files->path($name, false), 'c');
        } catch (\RuntimeException) {
            return false;
        }
        if ($fp === false) {
            return false;
        }
        $until = microtime(true) + $waitSeconds;
        while (!flock($fp, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $until) {
                fclose($fp);
                return null;
            }
            usleep(100000);
        }
        return $fp;
    }

    /** @param resource|false $fp */
    private static function unlock($fp): void
    {
        if (is_resource($fp)) {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /** Aufbereitung im Hintergrund anstoßen, höchstens einmal pro Minute (nicht bei jedem Bild des Rasters). */
    private function nudge(): void
    {
        if ($this->cache->get('gallery_nudged') === null) {
            $this->cache->set('gallery_nudged', 1, 60);
            $this->scheduler->runSoon(self::PROCESS_TASK);
        }
    }

    /** Grauer Platzhalter im Seitenverhältnis des Fotos, höchstens 400 px (ohne Maße: quadratisch). */
    public static function placeholder(int $width, int $height): string
    {
        [$w, $h] = $width > 0 && $height > 0 ? [$width, $height] : [1, 1];
        $scale = 400 / max($w, $h);
        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" height="%d"><rect width="100%%" height="100%%" fill="#e5e7eb"/></svg>',
            $w,
            $h,
            max(1, (int) round($w * $scale)),
            max(1, (int) round($h * $scale)),
        );
    }

    /** @return array<int, array{year: string, albums: array<int, array<string, mixed>>}> */
    public function overview(bool $member): array
    {
        $years = [];
        // Eine Abfrage für alle Alben samt Titelbild (keine je Album).
        foreach ($this->repo->albumSummaries($member) as $r) {
            $cover = $r['cover_id'] !== null
                ? ['id' => (int) $r['cover_id'], 'file_key' => (string) $r['cover_file_key'], 'is_intern' => (int) $r['cover_is_intern'], 'album_id' => (int) $r['cover_album_id'], 'status' => (string) $r['cover_status'], 'media' => (string) $r['cover_media'], 'md5' => (string) ($r['cover_md5'] ?? '')]
                : null;
            $years[(string) $r['year']][] = [
                'id' => (int) $r['id'],
                'title' => (string) $r['title'],
                // Bilder und Videos zusammen; davon Videos
                'count' => (int) $r['cnt'],
                'video_count' => (int) $r['video_cnt'],
                'intern_count' => $member ? (int) $r['intern_cnt'] : 0,
                'cover' => $cover ? $this->thumbUrl($cover) : null,
            ];
        }
        $out = [];
        foreach ($years as $year => $albums) {
            $out[] = ['year' => (string) $year, 'albums' => $albums];
        }
        usort($out, static fn (array $x, array $y): int => strnatcmp($y['year'], $x['year']));
        return $out;
    }

    /** Vorschaubild; null bei einem fertigen Video ohne Vorschaubild (Drive hatte keines). */
    private function thumbUrl(array $photo, string $size = 'thumb'): ?string
    {
        if (self::isVideoRow($photo) && $photo['status'] === GalleryRepository::READY && !is_file($this->path($photo, $size))) {
            return null;
        }
        return $this->url($photo, $size);
    }

    /** @return array<string, mixed>|null Album mit sichtbaren Bildern und Videos */
    public function album(int $id, bool $member): ?array
    {
        $album = $this->repo->album($id);
        if (!$album) {
            return null;
        }
        $photos = $this->repo->albumPhotos($id, $member);
        if ($photos === []) {
            return null;
        }
        return [
            'id' => (int) $album['id'],
            'title' => (string) $album['title'],
            'year' => (string) $album['year'],
            'photos' => array_map(fn (array $ph): array => self::isVideoRow($ph) ? [
                'id' => (int) $ph['id'],
                'type' => 'video',
                // Vorschaubild; '' ohne (dann eine Kachel mit ▶)
                'thumb' => (string) $this->thumbUrl($ph),
                'large' => (string) $this->thumbUrl($ph, 'large'),
                // Abspielen über die App, in Ausschnitten aus Drive (Range)
                'video' => $this->env->photoUrl((int) $ph['id'], self::VIDEO),
                'mime' => (string) $ph['mime'],
                'duration' => (int) round((int) $ph['duration_ms'] / 1000),
                'width' => (int) $ph['width'],
                'height' => (int) $ph['height'],
                'intern' => (bool) (int) $ph['is_intern'],
                'name' => (string) $ph['name'],
                'ready' => $ph['status'] === GalleryRepository::READY,
            ] : [
                'id' => (int) $ph['id'],
                'type' => 'image',
                'thumb' => $this->url($ph, 'thumb'),
                'large' => $this->url($ph, 'large'),
                // Großansicht: erst large, dann diese nachladen (404, wenn es kein Original gibt: large bleibt)
                'original' => $this->url($ph, self::ORIGINAL),
                'width' => (int) $ph['width'],
                'height' => (int) $ph['height'],
                'intern' => (bool) (int) $ph['is_intern'],
                'name' => (string) $ph['name'],
                // noch nicht aufbereitet: Die Adressen erzeugen das Bild oder liefern vorerst einen Platzhalter (202)
                'ready' => $ph['status'] === GalleryRepository::READY,
            ], $photos),
        ];
    }

    /** Alles entfernen (Index und Dateien), z. B. beim Wechsel des Ordners. */
    public function reset(): void
    {
        foreach ($this->repo->allPhotos() as $photo) {
            $this->deleteFiles($photo);
        }
        $this->repo->clear();
        $this->settings->delete(self::STATUS_SETTING);
    }
}
