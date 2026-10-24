<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Storage\MemberPhotoRepository;

/**
 * Fotos der Mitglieder.
 *
 * - Hochladen, ersetzen und entfernen nur, wer Konten anlegen darf (create_users), und nur bei Konten, die er verwalten
 *   darf (MemberService::guardAccount); optional schon beim Anlegen (auch mehrere auf einmal).
 * - Erlaubt sind JPG, PNG und WebP bis 5 MB und 4096 × 4096 Pixel (die App verkleinert Fotos vorher). Gespeichert werden nur neu erzeugte JPEGs (nach EXIF
 *   gedreht, ohne Metadaten wie GPS) in 128, 480 und 1024 Pixeln an der längeren Kante (nie vergrößert) und, soweit
 *   möglich, in Originalauflösung (MAX_EDGE), in der Datenbank (MemberPhotoRepository).
 * - Sehen: die Person selbst (Konto), alle, die die Mitglieder in der Verwaltung sehen (VIEWERS), und mit dem Bereich
 *   directory alle, auch ohne Anmeldung, die Fotos der Personen in der Mitgliederliste und der Ehemaligen (Issue #112,
 *   inDirectory).
 *   Ausgeliefert über Environment::memberPhotoUrl() nach Prüfung der Anmeldung; die Header setzt der Kern, damit beide
 *   Adapter gleich antworten.
 */
final class MemberPhotoService
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    /**
     * Größte Kantenlänge eines hochgeladenen Bilds (schützt den Arbeitsspeicher beim Verkleinern); zugleich die
     * Fassung in Originalauflösung (s=4096): Die Großansicht lädt sie nach der 1024er nach.
     */
    public const MAX_EDGE = 4096;
    public const ORIGINAL_QUALITY = 90;
    /**
     * Höchstens so groß wird die Originalauflösung gespeichert (sonst mit geringerer Qualität, notfalls gar nicht):
     * Base64 rund 4 MB, das nimmt auch eine Datenbank mit knapper Grenze für eine Anweisung (max_allowed_packet) an.
     */
    public const ORIGINAL_MAX_BYTES = 3 * 1024 * 1024;
    /** 1024: vergrößerte Ansicht in der App, scharf auch auf Handys mit hoher Pixeldichte. */
    public const SIZES = [128, 480, 1024];
    /** photo_url zeigt auf diese Größe; die App ändert s bei Bedarf. */
    public const DEFAULT_SIZE = 128;
    public const QUALITY = 82;
    /** Endung => Signatur; bei WebP steht „WEBP“ zusätzlich ab Byte 8. */
    public const TYPES = ['jpg' => "\xFF\xD8\xFF", 'jpeg' => "\xFF\xD8\xFF", 'png' => "\x89PNG\r\n\x1A\n", 'webp' => 'RIFF'];
    /** Wer die Mitgliederliste der Verwaltung sieht (wie dort), sieht auch die Fotos. */
    public const VIEWERS = [Capability::ManagePoints, Capability::ManagePenalties, Capability::ManageRanks, Capability::ManageTitles, Capability::CreateUsers, Capability::PromoteUsers];

    private ?MemberPhotoRepository $repo = null;

    public function __construct(private readonly App $app)
    {
    }

    public function repository(): MemberPhotoRepository
    {
        return $this->repo ??= new MemberPhotoRepository($this->app->db);
    }

    /**
     * Foto hochladen oder ersetzen (PUT /members/{id}/photo).
     *
     * @param mixed $file {name, data (Base64)}
     * @return array{photo_url: string|null}
     */
    public function upload(User $actor, int $userId, mixed $file): array
    {
        $this->guard($actor, $userId, 'Das Foto dieser Person darfst du nicht ändern.');
        $this->store($actor, $userId, $this->parse($file));
        return ['photo_url' => $this->url($userId)];
    }

    /** @return array{photo_url: null} */
    public function remove(User $actor, int $userId): array
    {
        $this->guard($actor, $userId, 'Das Foto dieser Person darfst du nicht entfernen.');
        $this->repository()->delete($userId);
        return ['photo_url' => null];
    }

    /**
     * Hochgeladene Datei prüfen, ohne etwas zu speichern (auch vor dem Anlegen eines Kontos).
     *
     * @param mixed $file {name, data (Base64, auch als data:-Adresse)}
     * @return array{bytes: string, ext: string}
     */
    public function parse(mixed $file): array
    {
        $name = is_array($file) && is_string($file['name'] ?? null) ? $file['name'] : '';
        $data = is_array($file) && is_string($file['data'] ?? null) ? $file['data'] : '';
        if (str_starts_with($data, 'data:') && ($comma = strpos($data, ',')) !== false) {
            $data = substr($data, $comma + 1);
        }
        // Vor dem Dekodieren: Base64 ist ein Drittel größer als die Datei.
        if (strlen($data) > intdiv(self::MAX_BYTES * 4, 3) + 4) {
            throw ApiError::invalid('Das Foto darf höchstens 5 MB groß sein.');
        }
        $bytes = $data === '' ? false : base64_decode($data, true);
        if ($bytes === false || $bytes === '') {
            throw ApiError::invalid('Es ist kein Foto angekommen.');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw ApiError::invalid('Das Foto darf höchstens 5 MB groß sein.');
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$ext])) {
            throw ApiError::invalid('Erlaubt sind Fotos als JPG, PNG oder WebP.');
        }
        $signature = self::TYPES[$ext];
        $ok = strncmp($bytes, $signature, strlen($signature)) === 0 && ($ext !== 'webp' || substr($bytes, 8, 4) === 'WEBP');
        $info = $ok ? @getimagesizefromstring($bytes) : false;
        if ($info === false) {
            throw ApiError::invalid(sprintf('Die Datei ist keine gültige .%s-Datei.', $ext));
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] > self::MAX_EDGE || $info[1] > self::MAX_EDGE) {
            throw ApiError::invalid(sprintf('Das Foto darf höchstens %d × %d Pixel groß sein.', self::MAX_EDGE, self::MAX_EDGE));
        }
        return ['bytes' => $bytes, 'ext' => $ext === 'jpeg' ? 'jpg' : $ext];
    }

    /**
     * Geprüftes Foto (parse) in allen Größen erzeugen und speichern; ein vorhandenes wird ersetzt.
     *
     * @param array{bytes: string, ext: string} $photo
     */
    public function store(User $actor, int $userId, array $photo): void
    {
        $images = [];
        $tmp = $this->app->files->tempFile();
        // Mit Endung: Der Bildeditor von WordPress erkennt das Format daran.
        $source = $tmp . '.' . $photo['ext'];
        $target = $tmp . '-out.jpg';
        try {
            if (@file_put_contents($source, $photo['bytes']) === false) {
                throw new ApiError(500, 'storage_failed', 'Das Foto konnte nicht gespeichert werden.');
            }
            foreach (self::SIZES as $size) {
                try {
                    $this->app->images->resizeToJpeg($source, $target, $size, self::QUALITY);
                } catch (\RuntimeException $e) {
                    throw ApiError::invalid('Das Foto kann nicht verarbeitet werden: ' . $e->getMessage());
                }
                $jpeg = @file_get_contents($target);
                if ($jpeg === false || $jpeg === '') {
                    throw new ApiError(500, 'storage_failed', 'Das Foto konnte nicht gespeichert werden.');
                }
                $images[$size] = $jpeg;
            }
        } finally {
            @unlink($source);
            @unlink($target);
            @unlink($tmp);
        }
        $version = substr(hash('sha256', implode('', $images) . random_bytes(8)), 0, 16);
        $this->repository()->save($userId, $images, $version, $actor->id, $this->app->time()->nowUtc());
        $original = $this->original($photo);
        try {
            $this->repository()->saveOriginal($userId, $original);
        } catch (\RuntimeException) {
            // Datenbank nimmt das große Foto nicht an: Die Großansicht bleibt bei 1024 Pixeln.
            $this->repository()->saveOriginal($userId, null);
        }
    }

    /**
     * Originalauflösung (höchstens MAX_EDGE, nie vergrößert, ohne Metadaten) als JPEG, höchstens ORIGINAL_MAX_BYTES;
     * null, wenn das nicht geht (dann bleibt es bei 1024 Pixeln).
     *
     * @param array{bytes: string, ext: string} $photo
     */
    private function original(array $photo): ?string
    {
        $tmp = $this->app->files->tempFile();
        $source = $tmp . '.' . $photo['ext'];
        $target = $tmp . '-original.jpg';
        try {
            if (@file_put_contents($source, $photo['bytes']) === false) {
                return null;
            }
            foreach ([self::ORIGINAL_QUALITY, 82, 74] as $quality) {
                $this->app->images->resizeToJpeg($source, $target, self::MAX_EDGE, $quality);
                $jpeg = @file_get_contents($target);
                if (is_string($jpeg) && $jpeg !== '' && strlen($jpeg) <= self::ORIGINAL_MAX_BYTES) {
                    return $jpeg;
                }
            }
            return null;
        } catch (\RuntimeException) {
            return null;
        } finally {
            @unlink($source);
            @unlink($target);
            @unlink($tmp);
        }
    }

    /** Adresse des Fotos (128 Pixel) oder null. */
    public function url(int $userId): ?string
    {
        $photo = $this->repository()->find($userId);
        return $photo === null ? null : $this->app->env->memberPhotoUrl($userId, $photo['version'], self::DEFAULT_SIZE);
    }

    /**
     * @return array<int, string> Benutzer-ID => Adresse (128 Pixel oder eine andere aus SIZES), für Listen. Fotos von vor
     *   Datenbankversion 18 haben keine 1024er: Für sie die nächstkleinere Größe (Issue #109). Die Auslieferung bleibt
     *   genau (s=1024 liefert 1024 Pixel oder 404), so stimmen ETag und srcset.
     */
    public function urls(int $size = self::DEFAULT_SIZE): array
    {
        $size = in_array($size, [...self::SIZES, self::MAX_EDGE], true) ? $size : self::DEFAULT_SIZE;
        // Nur innerhalb von SIZES ausweichen; die Originalauflösung (MAX_EDGE) lädt die Großansicht nach und bleibt bei 404
        // bei der Fassung davor.
        $index = array_search($size, self::SIZES, true);
        $missing = is_int($index) && $index > 0 ? $this->repository()->withoutImage($size) : [];
        $out = [];
        foreach ($this->repository()->versions() as $userId => $version) {
            $out[$userId] = $this->app->env->memberPhotoUrl($userId, $version, isset($missing[$userId]) ? self::SIZES[$index - 1] : $size);
        }
        return $out;
    }

    /** Ob $viewer Fotos anderer sieht (sonst nur das eigene). */
    public static function canSeeOthers(User $viewer): bool
    {
        foreach (self::VIEWERS as $cap) {
            if ($viewer->can($cap)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Mitgliederliste (Bereich directory, Issue #112: öffentlich): Die Fotos der Personen darin und der Ehemaligen (Tab
     * daneben) sieht jeder, auch ohne Anmeldung; die anderer Gäste nicht.
     */
    private function inDirectory(int $userId): bool
    {
        if (!$this->app->features()->enabled('directory')) {
            return false;
        }
        $target = $this->app->users->find($userId);
        return $target !== null && (MemberService::inDirectory($target) || $this->app->formerMembers()->previousRole($target) !== null);
    }

    /** Ob $actor das Foto dieses Kontos ändern darf (für die App: Knöpfe zeigen oder nicht). */
    public function canEdit(User $actor, int $userId): bool
    {
        if (!$actor->can(Capability::CreateUsers)) {
            return false;
        }
        // Admins verwalten jedes Konto (ohne das Konto noch einmal zu laden).
        if ($actor->isAdmin()) {
            return true;
        }
        try {
            $this->guard($actor, $userId, '');
            return true;
        } catch (ApiError) {
            return false;
        }
    }

    /**
     * Für die Auslieferung über memberPhotoUrl(): Status, Header und Inhalt.
     *
     * @return array{status: int, headers: array<string, string>, body?: string}
     */
    public function forDelivery(?User $viewer, int $userId, mixed $version, mixed $size): array
    {
        $deny = static fn (int $status): array => ['status' => $status, 'headers' => ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']];
        if ($this->app->maintenance()->blocks($viewer)) {
            return $deny(503);
        }
        $public = $this->inDirectory($userId);
        if ($viewer === null && !$public) {
            return $deny(401);
        }
        if ($viewer !== null && $viewer->id !== $userId && !self::canSeeOthers($viewer) && !$public) {
            return $deny(403);
        }
        $size = Input::integer($size) ?? self::DEFAULT_SIZE;
        $photo = $this->repository()->find($userId);
        // Originalauflösung (MAX_EDGE) fehlt bei älteren Fotos: 404, die Großansicht zeigt dann die 1024er weiter.
        $body = $photo === null ? null : match (true) {
            in_array($size, self::SIZES, true) => $this->repository()->image($userId, $size),
            $size === self::MAX_EDGE => $this->repository()->original($userId),
            default => null,
        };
        if ($photo === null || $body === null) {
            return $deny(404);
        }
        $current = is_string($version) && hash_equals($photo['version'], $version);
        return ['status' => 200, 'headers' => [
            'Content-Type' => 'image/jpeg',
            'X-Content-Type-Options' => 'nosniff',
            // Die Adresse wechselt mit jedem Foto. Höchstens einen Tag speichern, nur im Browser, solange das Foto nicht
            // öffentlich in der Mitgliederliste steht: Ein entferntes Foto oder entzogene Rechte gelten so bald auch auf
            // Geräten, die es schon geladen haben. In der Vorschau (Issue #63) nie speichern.
            'Cache-Control' => $this->app->preview()->cacheControl(($public ? 'public' : 'private') . ($current ? ', max-age=86400' : ', no-cache')),
            'X-Robots-Tag' => 'noindex',
            'ETag' => '"' . $photo['version'] . '-' . $size . '"',
        ], 'body' => $body];
    }

    private function guard(User $actor, int $userId, string $message): void
    {
        $target = $this->app->users->find($userId) ?? throw ApiError::notFound('Mitglied nicht gefunden.');
        if (!$actor->can(Capability::CreateUsers)) {
            throw ApiError::forbidden('Fotos ändert, wer Mitglieder anlegen darf.');
        }
        if ($actor->id !== $userId) {
            $this->app->members()->guardAccount($actor, $target, $message);
        }
    }
}
