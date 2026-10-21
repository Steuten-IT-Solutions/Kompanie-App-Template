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
 * Ordnerstruktur:  Galerie → Jahr → Album → Bilder
 *                                          └ intern → Bilder nur für Mitglieder
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
                        if (DriveClient::isImage($f)) {
                            $images[] = [$f, 0];
                        } elseif (DriveClient::isFolder($f) && mb_strtolower(trim((string) $f['name'])) === self::INTERN_FOLDER) {
                            foreach (array_filter($this->drive->listChildren($f['id']), [DriveClient::class, 'isImage']) as $img) {
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
            // Maße aus Drive nur für neue oder geänderte Bilder (bzw. solange keine bekannt sind):
            // Nach dem Aufbereiten stehen dort die Maße der Großansicht.
            $size = ['width' => (int) ($meta['width'] ?? 0), 'height' => (int) ($meta['height'] ?? 0)];
            if (!$row) {
                $plans[] = ['row' => null, 'renew' => false, 'data' => $data + $size + [
                    'drive_file_id' => (string) $f['id'],
                    'md5' => $md5,
                    'status' => GalleryRepository::PENDING,
                    'error' => '',
                ]];
                continue;
            }
            // Neuer Inhalt oder zwischen „intern“ und öffentlich bzw. in ein anderes Album verschoben: neu erzeugen.
            $renew = $row['md5'] !== $md5 || (int) $row['is_intern'] !== $intern || (int) $row['album_id'] !== $albumId;
            if ($renew) {
                $data += $size + ['md5' => $md5, 'status' => GalleryRepository::PENDING, 'error' => '', 'attempts' => 0];
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
     * der Datei in Google Drive (dort lässt sie sich prüfen oder ersetzen).
     *
     * @return list<array{id: int, name: string, album: string, intern: bool, error: string, attempts: int, drive_url: string}>
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
            while (microtime(true) - $start < $budgetSeconds) {
                $row = $this->repo->nextPending();
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
        $attempts = (int) ($photo['attempts'] ?? 0);
        if ($attempts >= self::MAX_ATTEMPTS) {
            return $this->markFailed($photo, 'Die Aufbereitung ist mehrfach abgebrochen (Bild zu groß für den Speicher des Servers?).');
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
            $size = null;
            foreach (self::SIZES as $name => $max) {
                $size = $this->images->resizeToJpeg($tmp, $this->path($photo, $name), $max, $name === 'thumb' ? 78 : 84);
            }
        } catch (\Throwable $e) {
            // auch Fehler von GD (z. B. ValueError), nicht nur RuntimeException
            return $this->markFailed($photo, $e->getMessage());
        } finally {
            @unlink($tmp);
        }
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
        foreach (array_keys(self::SIZES) as $size) {
            $this->files->delete(self::relativePath($photo, $size), !(int) $photo['is_intern']);
        }
    }

    /** Öffentliche fertige Bilder direkt als Datei, sonst über die App. */
    public function url(array $photo, string $size): string
    {
        if (!(int) $photo['is_intern'] && $photo['status'] === GalleryRepository::READY) {
            return $this->files->publicUrl(self::relativePath($photo, $size));
        }
        return $this->env->photoUrl((int) $photo['id'], $size);
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
        if (!isset(self::SIZES[$size])) {
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
        if ($photo['status'] === GalleryRepository::READY && !is_file($file)) {
            // Datei fehlt (z. B. gelöscht): neu aufbereiten.
            $this->repo->updatePhoto((int) $photo['id'], ['status' => GalleryRepository::PENDING, 'attempts' => 0]);
            $photo['status'] = GalleryRepository::PENDING;
            $photo['attempts'] = 0;
        }
        if ($photo['status'] !== GalleryRepository::READY) {
            $now = $this->generateNow($photo);
            if ($now === null) {
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
                ? ['id' => (int) $r['cover_id'], 'file_key' => (string) $r['cover_file_key'], 'is_intern' => (int) $r['cover_is_intern'], 'album_id' => (int) $r['cover_album_id'], 'status' => (string) $r['cover_status']]
                : null;
            $years[(string) $r['year']][] = [
                'id' => (int) $r['id'],
                'title' => (string) $r['title'],
                'count' => (int) $r['cnt'],
                'intern_count' => $member ? (int) $r['intern_cnt'] : 0,
                'cover' => $cover ? $this->url($cover, 'thumb') : null,
            ];
        }
        $out = [];
        foreach ($years as $year => $albums) {
            $out[] = ['year' => (string) $year, 'albums' => $albums];
        }
        usort($out, static fn (array $x, array $y): int => strnatcmp($y['year'], $x['year']));
        return $out;
    }

    /** @return array<string, mixed>|null Album mit sichtbaren Bildern */
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
            'photos' => array_map(fn (array $ph): array => [
                'id' => (int) $ph['id'],
                'thumb' => $this->url($ph, 'thumb'),
                'large' => $this->url($ph, 'large'),
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
