<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;

/**
 * Videos in die Galerie hochladen (Issue #350): MP4 und WebM bis MAX_BYTES, in Stücken, damit jede Anfrage unter
 * post_max_size des Servers bleibt. Die Datei bleibt unverändert (keine Umwandlung). Als Vorschau schickt der Browser
 * ein Standbild mit (JPEG, aus dem Video gezeichnet), aus dem die Galerie ihre Größen erzeugt.
 *
 * Ablauf: start() legt eine Sitzung an (im geschützten Bereich der Dateiablage, DIR/<Kennung>.json und .part), chunk()
 * hängt Stück für Stück an (in Reihenfolge; das letzte Stück darf noch einmal kommen, falls die Antwort verloren
 * ging), finish() übernimmt die Datei in den MediaStore und legt sie an:
 * - Zweck „album“ (manage_settings): direkt als Video ins Album (wie GalleryUploadService::upload).
 * - Zweck „submission“ (submit_gallery): als offene Einreichung (GallerySubmissionService, mit Einverständnis).
 * Abgebrochene Sitzungen löscht cancel(), liegengebliebene prune() nach STALE Sekunden.
 */
final class VideoUploadService
{
    public const MAX_BYTES = 1024 * 1024 * 1024;
    /** Größtes Stück (Rohdaten); kleiner, wenn post_max_size es verlangt (Base64 braucht ein Drittel mehr). */
    public const CHUNK_MAX = 4 * 1024 * 1024;
    private const CHUNK_MIN = 256 * 1024;
    public const DIR = '.video-uploads';
    /** Sitzungen ohne neues Stück seit dieser Zeit gelten als abgebrochen. */
    public const STALE = 86400;
    /** Erlaubte Endungen und ihr Typ. */
    public const TYPES = ['mp4' => 'video/mp4', 'webm' => 'video/webm'];
    /** Kantenlänge des Standbilds, das abgelegt wird. */
    private const POSTER_EDGE = 1600;
    /** Was neben dem Video auf der Platte frei bleiben soll. */
    private const DISK_RESERVE = 50 * 1024 * 1024;
    public const PURPOSES = ['album', 'submission'];

    public function __construct(private readonly App $app)
    {
    }

    /**
     * Sitzung anlegen.
     *
     * @param mixed $in {album_id, name, size, purpose: album|submission}
     * @return array{id: string, chunk_size: int, max_bytes: int}
     */
    public function start(User $user, mixed $in, bool $mayManage, bool $maySubmit): array
    {
        $in = is_array($in) ? $in : [];
        $purpose = in_array($in['purpose'] ?? null, self::PURPOSES, true) ? (string) $in['purpose'] : 'album';
        if (!($purpose === 'album' ? $mayManage : $maySubmit)) {
            throw ApiError::forbidden($purpose === 'album' ? 'Videos in Alben lädt nur hoch, wer die Galerie verwaltet.' : 'Du darfst keine Videos einreichen.');
        }
        if ($purpose !== 'album') {
            $this->app->gallerySubmissions()->assertEnabled();
        }
        $albumId = Input::integer($in['album_id'] ?? null) ?? 0;
        $this->app->gallerySubmissions()->album($albumId);
        $name = Text::line(is_string($in['name'] ?? null) ? $in['name'] : '', 255);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$ext])) {
            throw ApiError::invalid('Erlaubt sind Videos als MP4 oder WebM.');
        }
        $size = Input::integer($in['size'] ?? null) ?? 0;
        if ($size < 1) {
            throw ApiError::invalid('Das Video ist leer.');
        }
        if ($size > self::MAX_BYTES) {
            throw ApiError::invalid(sprintf('Das Video ist %s groß, erlaubt sind höchstens %s.', ServerLimits::format($size), ServerLimits::format(self::MAX_BYTES)));
        }
        $this->prune();
        $part = $this->path(bin2hex(random_bytes(16)), 'part');
        $free = @disk_free_space(dirname($part));
        if ($free !== false && $free < $size + self::DISK_RESERVE) {
            throw ApiError::invalid(sprintf('Auf dem Server ist nicht genug Platz für das Video (frei sind %s).', ServerLimits::format((int) $free)));
        }
        $id = basename($part, '.part');
        $session = [
            'user' => $user->id,
            'album' => $albumId,
            'purpose' => $purpose,
            'name' => $name,
            'ext' => $ext,
            'size' => $size,
            'received' => 0,
            'last' => 0,
        ];
        if (@file_put_contents($part, '') === false) {
            throw new \RuntimeException('Das Video lässt sich nicht zwischenspeichern.');
        }
        $this->save($id, $session);
        return ['id' => $id, 'chunk_size' => self::chunkSize(), 'max_bytes' => self::MAX_BYTES];
    }

    /**
     * Ein Stück anhängen.
     *
     * @return array{received: int}
     */
    public function chunk(User $user, mixed $upload, mixed $offset, mixed $data): array
    {
        [$id, $session] = $this->load($user, $upload);
        $offset = Input::integer($offset);
        $bytes = is_string($data) ? base64_decode($data, true) : false;
        if ($offset === null || $bytes === false || $bytes === '') {
            throw ApiError::invalid('Es ist kein Stück des Videos angekommen.');
        }
        $len = strlen($bytes);
        $received = (int) $session['received'];
        if ($offset < $received && $offset + $len === $received && $len === (int) $session['last']) {
            return ['received' => $received]; // das letzte Stück noch einmal (Antwort verloren): schon da
        }
        if ($offset !== $received) {
            throw ApiError::invalid(sprintf('Das Stück passt nicht (erwartet ab Byte %d). Bitte das Video neu hochladen.', $received));
        }
        if ($len > self::CHUNK_MAX || $received + $len > (int) $session['size']) {
            throw ApiError::invalid('Das Stück ist größer als angekündigt.');
        }
        if ($offset === 0 && !self::signatureOk((string) $session['ext'], $bytes)) {
            throw ApiError::invalid(sprintf('Die Datei ist kein gültiges %s-Video.', strtoupper((string) $session['ext'])));
        }
        $fp = @fopen($this->path($id, 'part'), 'ab');
        if ($fp === false) {
            throw ApiError::invalid('Die Sitzung zum Hochladen ist abgelaufen. Bitte das Video neu hochladen.');
        }
        try {
            if (@fwrite($fp, $bytes) !== $len) {
                throw ApiError::invalid('Das Stück lässt sich nicht speichern (Platte voll?).');
            }
        } finally {
            fclose($fp);
        }
        $session['received'] = $received + $len;
        $session['last'] = $len;
        $this->save($id, $session);
        return ['received' => $received + $len];
    }

    /**
     * Hochladen abschließen.
     *
     * @param mixed $in {upload, poster (JPEG als Base64, auch als data:-Adresse; leer ohne), width, height, duration (Sekunden), intern, consent}
     * @return array<string, mixed> Zweck album: das Album mit seinen hochgeladenen Bildern (wie GalleryUploadService::upload); Zweck submission: die Einreichung
     */
    public function finish(User $user, mixed $in): array
    {
        $in = is_array($in) ? $in : [];
        [$id, $session] = $this->load($user, $in['upload'] ?? null);
        $submission = $session['purpose'] === 'submission';
        if ($submission) {
            // Inzwischen ausgeschaltet: das Video nicht mehr als Einreichung aufnehmen (abbrechen räumt es auf)
            $this->app->gallerySubmissions()->assertEnabled();
        }
        if ($submission && ($in['consent'] ?? null) !== true) {
            throw ApiError::invalid('Bitte bestätigen, dass du die Rechte an dem Video hast und die erkennbaren Personen einverstanden sind.');
        }
        $part = $this->path($id, 'part');
        clearstatcache(true, $part);
        if ((int) $session['received'] !== (int) $session['size'] || (int) @filesize($part) !== (int) $session['size']) {
            throw ApiError::invalid('Das Video ist noch nicht vollständig angekommen.');
        }
        $head = (string) @file_get_contents($part, false, null, 0, 64);
        if (!self::signatureOk((string) $session['ext'], $head)) {
            throw ApiError::invalid(sprintf('Die Datei ist kein gültiges %s-Video.', strtoupper((string) $session['ext'])));
        }
        $albumId = (int) $session['album'];
        $this->app->gallerySubmissions()->album($albumId);
        $poster = $this->poster($in['poster'] ?? null);
        $media = $this->app->gallery()->media();
        try {
            $md5 = (string) md5_file($part);
            $key = $media->adopt($albumId, $part, (string) $session['ext']);
            $thumb = null;
            if ($poster !== null) {
                $media->putPoster($key, $poster);
                if ($submission) {
                    $small = $this->app->files->tempFile();
                    try {
                        $this->app->images->resizeToJpeg($poster, $small, GallerySubmissionService::THUMB_EDGE, 80);
                        $thumb = base64_encode((string) file_get_contents($small));
                    } finally {
                        @unlink($small);
                    }
                }
            }
        } finally {
            if ($poster !== null) {
                @unlink($poster);
            }
        }
        $this->app->files->delete(self::DIR . '/' . $id . '.json', false);
        $width = Input::integer($in['width'] ?? null) ?? 0;
        $height = Input::integer($in['height'] ?? null) ?? 0;
        $duration = is_numeric($in['duration'] ?? null) ? (float) $in['duration'] : 0.0;
        $stored = [
            'key' => $key,
            'name' => mb_substr((string) $session['name'], 0, 255),
            'width' => $width > 0 && $width <= 16384 ? $width : 0,
            'height' => $height > 0 && $height <= 16384 ? $height : 0,
            'md5' => $md5,
            'bytes' => (int) $session['size'],
            'duration_ms' => $duration > 0 && $duration < 86400 ? (int) round($duration * 1000) : 0,
            'thumb' => $thumb,
        ];
        if ($submission) {
            return $this->app->gallerySubmissions()->insertStored($user, $albumId, $stored);
        }
        try {
            $this->app->galleryUploads()->insertPhoto($albumId, $stored, Input::bool($in['intern'] ?? false));
        } catch (\Throwable $e) {
            $media->delete($key);
            throw $e;
        }
        return $this->app->galleryUploads()->album($albumId);
    }

    /** Hochladen abbrechen: Sitzung und das bisher Angekommene löschen. */
    public function cancel(User $user, mixed $upload): void
    {
        [$id] = $this->load($user, $upload);
        $this->remove($id);
    }

    /**
     * Liegengebliebene Sitzungen löschen (ohne neues Stück seit STALE Sekunden).
     *
     * @return int gelöschte Sitzungen
     */
    public function prune(): int
    {
        $dir = dirname($this->path('x', 'json'));
        $limit = $this->app->time()->timestamp() - self::STALE;
        $removed = 0;
        foreach ([...(glob($dir . '/*.json') ?: []), ...(glob($dir . '/*.part') ?: [])] as $file) {
            $id = pathinfo($file, PATHINFO_FILENAME);
            if (preg_match('/^[a-f0-9]{32}$/', $id) === 1 && (int) @filemtime($file) < $limit) {
                @unlink($file);
                $removed++;
            }
        }
        return $removed;
    }

    /** Größe eines Stücks: höchstens CHUNK_MAX, als Base64 in JSON unter post_max_size. */
    public static function chunkSize(): int
    {
        $post = ServerLimits::postMaxSize();
        $size = self::CHUNK_MAX;
        if ($post !== null) {
            $size = min($size, (int) floor(($post - 64 * 1024) * 3 / 4));
        }
        $size -= $size % (64 * 1024);
        return max(self::CHUNK_MIN, $size);
    }

    /** Beginnt die Datei wie ein Video dieses Formats? MP4: Kasten „ftyp“ (nicht QuickTime), WebM: EBML-Kopf. */
    public static function signatureOk(string $ext, string $bytes): bool
    {
        if ($ext === 'webm') {
            return strncmp($bytes, "\x1A\x45\xDF\xA3", 4) === 0;
        }
        return $ext === 'mp4' && substr($bytes, 4, 4) === 'ftyp' && substr($bytes, 8, 4) !== 'qt  ';
    }

    /** Standbild prüfen und neu kodieren; temporäre Datei, die der Aufrufer löscht, oder null ohne. */
    private function poster(mixed $raw): ?string
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        if (str_starts_with($raw, 'data:') && ($comma = strpos($raw, ',')) !== false) {
            $raw = substr($raw, $comma + 1);
        }
        $bytes = base64_decode($raw, true);
        $info = $bytes !== false && strncmp($bytes, "\xFF\xD8\xFF", 3) === 0 ? @getimagesizefromstring($bytes) : false;
        if ($info === false || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 40_000_000) {
            throw ApiError::invalid('Das Standbild des Videos ist kein gültiges JPEG.');
        }
        $files = $this->app->files;
        $src = $files->tempFile();
        $out = $files->tempFile();
        try {
            file_put_contents($src, $bytes);
            $this->app->images->resizeToJpeg($src, $out, self::POSTER_EDGE, 85);
        } catch (\RuntimeException $e) {
            @unlink($out);
            throw ApiError::invalid('Das Standbild des Videos lässt sich nicht lesen: ' . $e->getMessage());
        } finally {
            @unlink($src);
        }
        return $out;
    }

    /** @return array{0: string, 1: array<string, mixed>} Kennung und Sitzung der Person */
    private function load(User $user, mixed $upload): array
    {
        $id = is_string($upload) && preg_match('/^[a-f0-9]{32}$/', $upload) === 1 ? $upload : null;
        $raw = $id !== null ? @file_get_contents($this->path($id, 'json')) : false;
        $session = is_string($raw) ? json_decode($raw, true) : null;
        if ($id === null || !is_array($session) || (int) ($session['user'] ?? 0) !== $user->id) {
            throw ApiError::notFound('Die Sitzung zum Hochladen gibt es nicht (mehr). Bitte das Video neu hochladen.');
        }
        return [$id, $session];
    }

    /** @param array<string, mixed> $session */
    private function save(string $id, array $session): void
    {
        if (@file_put_contents($this->path($id, 'json'), (string) json_encode($session)) === false) {
            throw new \RuntimeException('Die Sitzung zum Hochladen lässt sich nicht speichern.');
        }
    }

    private function remove(string $id): void
    {
        $this->app->files->delete(self::DIR . '/' . $id . '.part', false);
        $this->app->files->delete(self::DIR . '/' . $id . '.json', false);
    }

    private function path(string $id, string $ext): string
    {
        return $this->app->files->path(self::DIR . '/' . $id . '.' . $ext, false);
    }
}
