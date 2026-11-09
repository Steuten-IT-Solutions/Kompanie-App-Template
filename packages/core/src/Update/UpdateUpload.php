<?php

declare(strict_types=1);

namespace KompanieApp\Core\Update;

use KompanieApp\Core\Domain\Input;
use KompanieApp\Core\Domain\ServerLimits;
use KompanieApp\Core\Domain\Time;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Port\FileStorage;

/**
 * Update-Paket hochladen statt herunterladen (z. B. wenn der Server die Pakete nicht laden kann oder eine bestimmte
 * Version eingespielt werden soll), in Stücken, damit jede Anfrage unter post_max_size des Servers bleibt.
 *
 * Eingespielt wird nur ein Paket, das genau so in der signierten Update-Liste steht (SHA-256 und Größe,
 * UpdateService::identifyUpload): Ein Admin kann so keinen fremden oder veränderten Code auf den Server bringen.
 *
 * Die Sitzung liegt im geschützten Bereich der Dateiablage (DIR/<Kennung>.json und .zip). Liegengebliebene löscht
 * prune() nach STALE Sekunden.
 */
final class UpdateUpload
{
    /** Größtes Paket; die Pakete sind heute etwa 12 MB groß. */
    public const MAX_BYTES = 100 * 1024 * 1024;
    /** Größtes Stück (Rohdaten); kleiner, wenn post_max_size es verlangt (Base64 braucht ein Drittel mehr). */
    public const CHUNK_MAX = 4 * 1024 * 1024;
    private const CHUNK_MIN = 256 * 1024;
    public const DIR = '.update-uploads';
    public const STALE = 86400;

    public function __construct(private readonly FileStorage $files, private readonly Time $time)
    {
    }

    /**
     * Sitzung anlegen.
     *
     * @return array{id: string, chunk_size: int, max_bytes: int}
     */
    public function start(mixed $size): array
    {
        $size = Input::integer($size) ?? 0;
        if ($size < 1) {
            throw ApiError::invalid('Die Datei ist leer.');
        }
        if ($size > self::MAX_BYTES) {
            throw ApiError::invalid(sprintf('Die Datei ist %s groß; ein Update-Paket hat höchstens %s.', ServerLimits::format($size), ServerLimits::format(self::MAX_BYTES)));
        }
        $this->prune();
        $id = bin2hex(random_bytes(16));
        $free = @disk_free_space(dirname($this->path($id, 'zip')));
        if ($free !== false && $free < 3 * $size) {
            throw ApiError::invalid(sprintf('Auf dem Server ist nicht genug Platz für das Update (frei sind %s).', ServerLimits::format((int) $free)));
        }
        if (@file_put_contents($this->path($id, 'zip'), '') === false) {
            throw new \RuntimeException('Das Paket lässt sich nicht zwischenspeichern.');
        }
        $this->save($id, ['size' => $size, 'received' => 0, 'last' => 0]);
        return ['id' => $id, 'chunk_size' => self::chunkSize(), 'max_bytes' => self::MAX_BYTES];
    }

    /** @return array{received: int} */
    public function chunk(mixed $upload, mixed $offset, mixed $data): array
    {
        [$id, $session] = $this->load($upload);
        $offset = Input::integer($offset);
        $bytes = is_string($data) ? base64_decode($data, true) : false;
        if ($offset === null || $bytes === false || $bytes === '') {
            throw ApiError::invalid('Es ist kein Stück der Datei angekommen.');
        }
        $len = strlen($bytes);
        $received = $session['received'];
        if ($offset < $received && $offset + $len === $received && $len === $session['last']) {
            return ['received' => $received]; // das letzte Stück noch einmal (Antwort verloren): schon da
        }
        if ($offset !== $received) {
            throw ApiError::invalid(sprintf('Das Stück passt nicht (erwartet ab Byte %d). Bitte die Datei neu hochladen.', $received));
        }
        if ($len > self::CHUNK_MAX || $received + $len > $session['size']) {
            throw ApiError::invalid('Das Stück ist größer als angekündigt.');
        }
        if ($offset === 0 && strncmp($bytes, "PK\x03\x04", 4) !== 0) {
            throw ApiError::invalid('Die Datei ist kein Update-Paket (ZIP-Datei, z. B. kompanie-app-standalone.zip).');
        }
        if (@file_put_contents($this->path($id, 'zip'), $bytes, FILE_APPEND) !== $len) {
            throw ApiError::invalid('Das Stück lässt sich nicht speichern (Platte voll?).');
        }
        $session['received'] = $received + $len;
        $session['last'] = $len;
        $this->save($id, $session);
        return ['received' => $session['received']];
    }

    /** Pfad der vollständig angekommenen Datei. */
    public function file(mixed $upload): string
    {
        [$id, $session] = $this->load($upload);
        $path = $this->path($id, 'zip');
        clearstatcache(true, $path);
        if ($session['received'] !== $session['size'] || (int) @filesize($path) !== $session['size']) {
            throw ApiError::invalid('Die Datei ist noch nicht vollständig angekommen.');
        }
        return $path;
    }

    /** Sitzung und Datei löschen (fertig oder abgebrochen); unbekannte Kennungen still übergehen. */
    public function remove(mixed $upload): void
    {
        if (is_string($upload) && preg_match('/^[a-f0-9]{32}$/', $upload) === 1) {
            $this->files->delete(self::DIR . '/' . $upload . '.zip', false);
            $this->files->delete(self::DIR . '/' . $upload . '.json', false);
        }
    }

    /** Liegengebliebene Sitzungen löschen (ohne neues Stück seit STALE Sekunden). */
    public function prune(): void
    {
        $dir = dirname($this->path('x', 'json'));
        $limit = $this->time->timestamp() - self::STALE;
        foreach ([...(glob($dir . '/*.json') ?: []), ...(glob($dir . '/*.zip') ?: [])] as $file) {
            if (preg_match('/^[a-f0-9]{32}$/', pathinfo($file, PATHINFO_FILENAME)) === 1 && (int) @filemtime($file) < $limit) {
                @unlink($file);
            }
        }
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

    /** @return array{0: string, 1: array{size: int, received: int, last: int}} */
    private function load(mixed $upload): array
    {
        $id = is_string($upload) && preg_match('/^[a-f0-9]{32}$/', $upload) === 1 ? $upload : null;
        $raw = $id !== null ? @file_get_contents($this->path($id, 'json')) : false;
        $s = is_string($raw) ? json_decode($raw, true) : null;
        if ($id === null || !is_array($s) || !is_int($s['size'] ?? null) || !is_int($s['received'] ?? null) || !is_int($s['last'] ?? null)) {
            throw ApiError::notFound('Die Sitzung zum Hochladen gibt es nicht (mehr). Bitte die Datei neu hochladen.');
        }
        return [$id, ['size' => $s['size'], 'received' => $s['received'], 'last' => $s['last']]];
    }

    /** @param array{size: int, received: int, last: int} $session */
    private function save(string $id, array $session): void
    {
        if (@file_put_contents($this->path($id, 'json'), (string) json_encode($session)) === false) {
            throw new \RuntimeException('Die Sitzung zum Hochladen lässt sich nicht speichern.');
        }
    }

    private function path(string $id, string $ext): string
    {
        return $this->files->path(self::DIR . '/' . $id . '.' . $ext, false);
    }
}
