<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

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
 * Aufbereitet wird nur im Hintergrund (Aufgaben TASK und PROCESS_TASK), nie während einer
 * Anfrage: Ein Album-Raster würde sonst viele gleichzeitige Downloads und Bildberechnungen
 * auslösen. Noch nicht fertige Bilder liefert die App als Platzhalter (202 mit Retry-After)
 * und stößt die Aufbereitung an.
 */
final class GalleryService
{
    public const ROOT_SETTING = 'gallery_root';
    public const STATUS_SETTING = 'gallery_status';
    public const TASK = 'gallery_sync';
    /** Nur aufbereiten (ohne Abgleich mit Drive); läuft bei ausstehenden Bildern bald wieder. */
    public const PROCESS_TASK = 'gallery_process';
    /** Nach so vielen Sekunden soll der Browser ein noch nicht fertiges Bild erneut laden. */
    public const RETRY_AFTER = 30;
    public const INTERN_FOLDER = 'intern';
    public const SIZES = ['thumb' => 400, 'large' => 1600];
    /** So oft wird ein Bild aufbereitet, bevor es als dauerhaft fehlerhaft gilt. */
    public const MAX_ATTEMPTS = 5;

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
        return $this->root() !== '' && $this->drive->serviceAccount() !== null;
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
                    if ($images === []) {
                        continue;
                    }
                    // Je Album eine Transaktion (ein Commit statt einem je Bild) und eine Abfrage für
                    // die vorhandenen Bilder; geschrieben wird nur, was sich geändert hat. Die
                    // Anfragen an Drive liegen außerhalb, damit die Datenbank nicht lange gesperrt ist.
                    $this->repo->transaction(function () use ($album, $year, $images, &$seenAlbums, &$seenPhotos, &$stats): void {
                        $albumId = $this->upsertAlbum($album, (string) $year['name'], $images);
                        $seenAlbums[] = $albumId;
                        $stats['albums']++;
                        $existing = $this->repo->photosByDriveIds(array_map(static fn (array $i): string => (string) $i[0]['id'], $images));
                        foreach ($images as [$file, $intern]) {
                            [$photoId, $isNew] = $this->upsertPhoto($albumId, $file, $intern, $existing[(string) $file['id']] ?? null);
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

        $gone = $this->repo->photosExcept($seenPhotos);
        $this->repo->transaction(function () use ($gone, $seenAlbums): void {
            // Was in Drive nicht mehr vorhanden ist, verschwindet samt Dateien.
            foreach ($gone as $photo) {
                $this->repo->deletePhoto((int) $photo['id']);
            }
            $this->repo->deleteAlbumsExcept($seenAlbums);
            // Vorübergehende Fehler (Zeitüberschreitung, Störung bei Google) beim nächsten Mal erneut versuchen.
            $this->repo->retryFailed(self::MAX_ATTEMPTS);
        });
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

    private function upsertAlbum(array $folder, string $year, array $images): int
    {
        $dates = [];
        foreach ($images as [$file]) {
            $taken = self::driveTime($file['imageMediaMetadata']['time'] ?? '');
            if ($taken) {
                $dates[] = $taken;
            }
        }
        $sort = $dates ? min($dates) : self::driveTime($folder['createdTime'] ?? '');
        $now = $this->time->nowUtc();
        // synced_at allein ist keine Änderung: Ohne Änderung wird das Album nicht geschrieben.
        return $this->repo->upsertAlbum((string) $folder['id'], [
            'year' => mb_substr(trim($year), 0, 20),
            'title' => mb_substr(trim((string) $folder['name']), 0, 191),
            'sort_date' => $sort ?: $now,
        ], ['synced_at' => $now]);
    }

    /**
     * @param array<string, mixed>|null $row vorhandene Zeile zu dieser Drive-Datei
     * @return array{0: int, 1: bool} ID und ob neu
     */
    private function upsertPhoto(int $albumId, array $f, int $intern, ?array $row): array
    {
        $meta = $f['imageMediaMetadata'] ?? [];
        $md5 = $f['md5Checksum'] ?? md5($f['id'] . ($f['modifiedTime'] ?? ''));
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
            $id = $this->repo->insertPhoto($data + $size + [
                'drive_file_id' => (string) $f['id'],
                'md5' => (string) $md5,
                'file_key' => bin2hex(random_bytes(16)),
                'status' => GalleryRepository::PENDING,
                'error' => '',
            ]);
            return [$id, true];
        }
        // Neuer Inhalt oder zwischen „intern“ und öffentlich verschoben: neu erzeugen.
        if ($row['md5'] !== $md5 || (int) $row['is_intern'] !== $intern || (int) $row['album_id'] !== $albumId) {
            $this->deleteFiles($row);
            $data += $size + ['md5' => (string) $md5, 'file_key' => bin2hex(random_bytes(16)), 'status' => GalleryRepository::PENDING, 'error' => '', 'attempts' => 0];
        } elseif ((int) $row['width'] === 0 && $size['width'] > 0) {
            $data += $size;
        }
        // Nur schreiben, wenn sich etwas geändert hat (sonst bei 2.000 Bildern 2.000 UPDATEs pro Stunde).
        if (GalleryRepository::changed($row, $data)) {
            $this->repo->updatePhoto((int) $row['id'], $data);
        }
        return [(int) $row['id'], false];
    }

    public function countFailed(): int
    {
        return $this->repo->countFailed();
    }

    public function countPending(): int
    {
        return $this->repo->countPending();
    }

    /**
     * Erzeugt Bilder, bis das Zeitbudget aufgebraucht ist. Neueste Alben zuerst.
     * Es bereitet immer nur ein Prozess auf (Sperre); ein zweiter gibt sofort 0 zurück,
     * statt dieselben Bilder noch einmal zu laden.
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
                $this->generate($row);
                $done++;
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
        } catch (\RuntimeException $e) {
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
     * Noch nicht aufbereitete Bilder werden hier nicht erzeugt: Die Antwort ist 202 mit einem
     * Platzhalter (SVG im Seitenverhältnis des Fotos) und Retry-After; die Aufbereitung läuft im
     * Hintergrund (und wird hier angestoßen).
     *
     * @return array{status: int, file?: string, intern?: bool, placeholder?: string, retryAfter?: int}
     */
    public function photoForDelivery(int $id, string $size, bool $member, bool $loggedIn): array
    {
        $photo = $this->repo->photo($id);
        if (!$photo || !isset(self::SIZES[$size])) {
            return ['status' => 404];
        }
        $intern = (bool) (int) $photo['is_intern'];
        if ($intern && !$member) {
            return ['status' => $loggedIn ? 403 : 401];
        }
        $file = $this->path($photo, $size);
        // Fehlgeschlagene Bilder nicht bei jedem Aufruf erneut aus Drive laden lassen
        // (sonst könnten Besucher beliebig viele Downloads auslösen); der Abgleich versucht
        // sie erneut (retryFailed, höchstens MAX_ATTEMPTS-mal).
        if ($photo['status'] === GalleryRepository::FAILED) {
            return ['status' => 404];
        }
        if ($photo['status'] === GalleryRepository::READY && !is_file($file)) {
            // Datei fehlt (z. B. gelöscht): neu aufbereiten lassen.
            $this->repo->updatePhoto((int) $photo['id'], ['status' => GalleryRepository::PENDING, 'attempts' => 0]);
            $photo['status'] = GalleryRepository::PENDING;
        }
        if ($photo['status'] !== GalleryRepository::READY) {
            $this->nudge();
            return ['status' => 202, 'placeholder' => self::placeholder((int) $photo['width'], (int) $photo['height']), 'retryAfter' => self::RETRY_AFTER, 'intern' => $intern];
        }
        return ['status' => 200, 'file' => $file, 'intern' => $intern];
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
        foreach ($this->repo->albumSummaries($member) as $r) {
            $cover = $this->repo->cover((int) $r['id'], $member);
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
