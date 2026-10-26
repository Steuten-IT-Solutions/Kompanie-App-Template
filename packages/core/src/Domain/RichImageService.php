<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Storage\RichImageRepository;

/**
 * Bilder in formatierten Inhalten (Issue #172), allgemeiner Baustein für Signaturen, das Schwarze Brett, E-Mails aus
 * der Verwaltung und später andere Bereiche.
 *
 * - Hochladen (POST /rich-images) legt ein Bild als Entwurf (OWNER_DRAFT) des Kontos an. Beim Speichern eines Inhalts
 *   übernimmt dessen Besitzer die Bilder, auf die er verweist (claim): eigene Entwürfe werden umgehängt, Bilder anderer
 *   Besitzer (z. B. der Signatur beim Einfügen in eine Nachricht) kopiert, damit spätere Änderungen dort nichts ändern.
 *   Entwürfe, die nie gespeichert wurden, räumt cleanupDrafts() nach DRAFT_KEEP_DAYS auf.
 * - Erlaubt sind JPG, PNG und WebP. Gespeichert wird nur eine neu erzeugte Fassung ohne Metadaten in Originalgröße
 *   (PNG bleibt PNG mit Transparenz, sonst JPEG). Es gibt keine eigene Grenze für Größe, Pixel oder Anzahl; geprüft
 *   werden nur die technischen Grenzen (ServerLimits): Arbeitsspeicher vor dem Dekodieren, max_allowed_packet vor dem
 *   Speichern. post_max_size kennt die App vorab (limits()) und prüft sie selbst.
 * - Im Inhalt stehen Bilder nur als <img data-image="Kennung">; ausgeliefert werden sie über Environment::richImageUrl
 *   an Angemeldete, die den Inhalt sehen dürfen (canView je Besitzer).
 */
final class RichImageService
{
    public const OWNER_DRAFT = 'draft';
    public const OWNER_SIGNATURE = 'signature';
    public const OWNER_BOARD = 'board';
    /** E-Mails aus der Verwaltung (MailingService) */
    public const OWNER_MAIL = 'mail';
    /** Nie gespeicherte Entwürfe so lange aufheben (ein begonnener Text kann über Nacht liegen bleiben). */
    public const DRAFT_KEEP_DAYS = 7;
    public const QUALITY = 88;
    /** Endung => [Signatur, MIME-Typ]; bei WebP steht „WEBP“ zusätzlich ab Byte 8. */
    public const TYPES = ['jpg' => "\xFF\xD8\xFF", 'jpeg' => "\xFF\xD8\xFF", 'png' => "\x89PNG\r\n\x1A\n", 'webp' => 'RIFF'];

    private ?RichImageRepository $repo = null;

    public function __construct(private readonly App $app)
    {
    }

    public function repository(): RichImageRepository
    {
        return $this->repo ??= new RichImageRepository($this->app->db);
    }

    /**
     * Grenzen des Servers für die App (vor dem Hochladen prüfen und anzeigen).
     *
     * @return array{post_max_size: int|null, upload_max_filesize: int|null, memory_limit: int|null, max_allowed_packet: int|null, max_image_bytes: int|null}
     */
    public function limits(): array
    {
        $post = ServerLimits::postMaxSize();
        $packet = ServerLimits::maxAllowedPacket($this->app->db);
        // Größte Datei, die als Base64 im JSON in die Anfrage passt (abzüglich eines Puffers für den Rest der Anfrage),
        // und deren Base64 die Datenbank in einer Anweisung annimmt.
        $candidates = [];
        if ($post !== null) {
            $candidates[] = intdiv(max(0, $post - 4096) * 3, 4);
        }
        if ($packet !== null) {
            $candidates[] = intdiv(max(0, $packet - 4096) * 3, 4);
        }
        return [
            'post_max_size' => $post,
            'upload_max_filesize' => ServerLimits::uploadMaxFilesize(),
            'memory_limit' => ServerLimits::memoryLimit(),
            'max_allowed_packet' => $packet,
            'max_image_bytes' => $candidates === [] ? null : min($candidates),
        ];
    }

    /**
     * Bild hochladen (Entwurf des Kontos).
     *
     * @param mixed $file {name, data (Base64, auch als data:-Adresse)}
     * @return array{uid: string, url: string, width: int, height: int}
     */
    public function upload(User $actor, mixed $file): array
    {
        $name = is_array($file) && is_string($file['name'] ?? null) ? $file['name'] : '';
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
        $signature = self::TYPES[$ext];
        $ok = strncmp($bytes, $signature, strlen($signature)) === 0 && ($ext !== 'webp' || substr($bytes, 8, 4) === 'WEBP');
        $info = $ok ? @getimagesizefromstring($bytes) : false;
        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            throw ApiError::invalid(sprintf('Die Datei ist keine gültige .%s-Datei.', $ext));
        }
        $png = $ext === 'png';
        $this->checkMemory((int) $info[0], (int) $info[1], strlen($bytes), !$png && $ext !== 'webp');
        $encoded = $this->encode($bytes, $ext, max((int) $info[0], (int) $info[1]), $png);
        unset($bytes);
        $this->checkPacket(strlen($encoded['bytes']));
        $uid = self::newUid();
        $this->repository()->insert($uid, self::OWNER_DRAFT, $actor->id, $actor->id, $png ? 'image/png' : 'image/jpeg', $encoded['width'], $encoded['height'], $encoded['bytes'], $this->app->time()->nowUtc());
        return ['uid' => $uid, 'url' => $this->app->env->richImageUrl($uid), 'width' => $encoded['width'], 'height' => $encoded['height']];
    }

    /**
     * Ein Inhalt wird gespeichert: Seine Bilder gehören danach $ownerType/$ownerId. Eigene Entwürfe werden umgehängt,
     * Bilder anderer Besitzer kopiert (wenn $actor sie sehen darf), unbekannte oder fremde Bilder entfernt. Bilder, die
     * dem Besitzer gehören, aber nicht mehr im Inhalt stehen, bleiben (Verlauf, s. BoardService) bzw. werden mit
     * $dropUnused gelöscht (Signatur).
     *
     * @return string Inhalt mit den gültigen Kennungen
     */
    public function claim(User $actor, string $html, string $ownerType, int $ownerId, bool $dropUnused = false): string
    {
        $uids = RichText::imageUids($html);
        $images = $this->repository()->findMany($uids);
        $map = [];
        $now = $this->app->time()->nowUtc();
        foreach ($uids as $uid) {
            $img = $images[$uid] ?? null;
            if ($img === null) {
                continue;
            }
            if ($img['owner_type'] === $ownerType && $img['owner_id'] === $ownerId) {
                $map[$uid] = $uid;
            } elseif ($img['owner_type'] === self::OWNER_DRAFT && $img['owner_id'] === $actor->id) {
                $this->repository()->setOwner($uid, $ownerType, $ownerId);
                $map[$uid] = $uid;
            } elseif ($this->canView($actor, $img)) {
                $new = self::newUid();
                if ($this->repository()->copy($uid, $new, $ownerType, $ownerId, $actor->id, $now)) {
                    $map[$uid] = $new;
                }
            }
        }
        if ($dropUnused) {
            $keep = array_flip(array_values($map));
            foreach ($this->repository()->uidsOf($ownerType, $ownerId) as $uid) {
                if (!isset($keep[$uid])) {
                    $this->repository()->deleteUid($uid);
                }
            }
        }
        return RichText::replaceImageUids($html, $map, true);
    }

    /** Inhalt für die Ausgabe: Bilder mit Adresse (src). */
    public function render(string $html): string
    {
        return RichText::withImageUrls($html, fn (string $uid): string => $this->app->env->richImageUrl($uid));
    }

    public function deleteOwner(string $ownerType, int $ownerId): void
    {
        $this->repository()->deleteOwner($ownerType, $ownerId);
    }

    /** Nie gespeicherte Entwürfe aufräumen (Aufgabe board_cleanup). */
    public function cleanupDrafts(): int
    {
        $before = gmdate('Y-m-d H:i:s', $this->app->time()->timestamp() - self::DRAFT_KEEP_DAYS * 86400);
        return $this->repository()->deleteDraftsBefore(self::OWNER_DRAFT, $before);
    }

    /**
     * Darf $viewer das Bild sehen? Entwürfe und Signaturen nur ihr Konto, Bilder am Brett wie die Nachricht
     * (BoardService::canSeeImage), Bilder in E-Mails alle mit send_mail (MailingService::canSeeImage).
     *
     * @param array{owner_type: string, owner_id: int, user_id: int} $img
     */
    public function canView(?User $viewer, array $img): bool
    {
        if ($viewer === null) {
            return false;
        }
        return match ($img['owner_type']) {
            self::OWNER_DRAFT, self::OWNER_SIGNATURE => $viewer->id === $img['owner_id'],
            self::OWNER_BOARD => $this->app->board()->canSeeImage($viewer, $img['owner_id']),
            self::OWNER_MAIL => $this->app->mailings()->canSeeImage($viewer, $img['owner_id']),
            default => false,
        };
    }

    /** Bilddaten und Typ für eingebettete Bilder (Mail). */
    public function bytes(string $uid): ?array
    {
        $img = $this->repository()->find($uid);
        $data = $img === null ? null : $this->repository()->data($uid);
        return $img === null || $data === null ? null : ['mime' => $img['mime'], 'bytes' => $data];
    }

    /**
     * Für die Auslieferung über richImageUrl(): Status, Header und Inhalt (beide Adapter gleich).
     *
     * @return array{status: int, headers: array<string, string>, body?: string}
     */
    public function forDelivery(?User $viewer, mixed $uid): array
    {
        $deny = static fn (int $status): array => ['status' => $status, 'headers' => ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']];
        if ($this->app->maintenance()->blocks($viewer)) {
            return $deny(503);
        }
        if ($viewer === null) {
            return $deny(401);
        }
        $uid = is_string($uid) ? strtolower($uid) : '';
        $img = preg_match(RichText::IMAGE_UID, $uid) === 1 ? $this->repository()->find($uid) : null;
        if ($img === null) {
            return $deny(404);
        }
        if (!$this->canView($viewer, $img)) {
            return $deny(403);
        }
        $body = $this->repository()->data($uid);
        if ($body === null) {
            return $deny(404);
        }
        return ['status' => 200, 'headers' => [
            'Content-Type' => $img['mime'],
            'X-Content-Type-Options' => 'nosniff',
            // Eine Kennung hat immer denselben Inhalt; Rechte können sich ändern, deshalb nur im Browser und höchstens
            // einen Tag. In der Vorschau (Issue #63) nie speichern.
            'Cache-Control' => $this->app->preview()->cacheControl('private, max-age=86400'),
            'X-Robots-Tag' => 'noindex',
            'ETag' => '"' . $uid . '"',
        ], 'body' => $body];
    }

    /** Neue Kennung: 32 Hex-Zeichen (RichText::IMAGE_UID). */
    public static function newUid(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** Meldung, wenn die Anfrage größer war als post_max_size (PHP verwirft dann den ganzen Inhalt). */
    private function postTooLarge(): ?string
    {
        $length = isset($_SERVER['CONTENT_LENGTH']) && is_numeric($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
        $post = ServerLimits::postMaxSize();
        if ($post === null || $length <= $post) {
            return null;
        }
        return sprintf(
            'Das Bild ist zu groß für den Server: Er nimmt höchstens %s je Anfrage an (PHP-Einstellung post_max_size), das Bild braucht %s. Bitte ein kleineres Bild wählen oder den Hoster bitten, den Wert zu erhöhen.',
            ServerLimits::format($post),
            ServerLimits::format($length),
        );
    }

    /**
     * Reicht der Arbeitsspeicher, um das Bild zu dekodieren? GD hält je Pixel 4 Bytes, beim Umrechnen Quelle und Ziel
     * (bei JPEG in Originalgröße nur eine Kopie); dazu ein Puffer.
     */
    private function checkMemory(int $width, int $height, int $fileBytes, bool $jpeg): void
    {
        $available = ServerLimits::memoryAvailable();
        if ($available === null) {
            return;
        }
        $needed = (int) ($width * $height * 4 * ($jpeg ? 1 : 2) * 1.25) + 3 * $fileBytes + 2 * 1024 * 1024;
        if ($needed > $available) {
            throw ApiError::invalid(sprintf(
                'Das Bild hat %d × %d Pixel. Zum Verarbeiten braucht der Server rund %s Arbeitsspeicher, frei sind %s (PHP-Einstellung memory_limit: %s). Bitte das Bild verkleinern oder den Hoster bitten, den Wert zu erhöhen.',
                $width,
                $height,
                ServerLimits::format($needed),
                ServerLimits::format($available),
                ServerLimits::format((int) ServerLimits::memoryLimit()),
            ));
        }
    }

    /** Nimmt die Datenbank den Wert in einer Anweisung an (max_allowed_packet, Base64 + Rest der Anweisung)? */
    private function checkPacket(int $bytes): void
    {
        $packet = ServerLimits::maxAllowedPacket($this->app->db);
        $needed = intdiv($bytes + 2, 3) * 4 + 4096;
        if ($packet !== null && $needed > $packet) {
            throw ApiError::invalid(sprintf(
                'Das Bild ist nach dem Verarbeiten %s groß; die Datenbank nimmt höchstens %s auf einmal an (Einstellung max_allowed_packet). Bitte ein kleineres Bild wählen oder den Hoster bitten, den Wert zu erhöhen.',
                ServerLimits::format($needed),
                ServerLimits::format($packet),
            ));
        }
    }

    /**
     * Neu kodieren, ohne Metadaten, in Originalgröße (nie verkleinert).
     *
     * @return array{bytes: string, width: int, height: int}
     */
    private function encode(string $bytes, string $ext, int $edge, bool $png): array
    {
        $tmp = $this->app->files->tempFile();
        // Mit Endung: Der Bildeditor von WordPress erkennt das Format daran.
        $source = $tmp . '.' . $ext;
        $target = $tmp . '-out.' . ($png ? 'png' : 'jpg');
        try {
            if (@file_put_contents($source, $bytes) === false) {
                throw new ApiError(500, 'storage_failed', 'Das Bild konnte nicht gespeichert werden.');
            }
            try {
                $size = $png
                    ? $this->app->images->resizeToPng($source, $target, $edge)
                    : $this->app->images->resizeToJpeg($source, $target, $edge, self::QUALITY);
            } catch (\RuntimeException $e) {
                throw ApiError::invalid('Das Bild kann nicht verarbeitet werden: ' . $e->getMessage());
            }
            $out = @file_get_contents($target);
            if ($out === false || $out === '') {
                throw new ApiError(500, 'storage_failed', 'Das Bild konnte nicht gespeichert werden.');
            }
            return ['bytes' => $out, 'width' => $size['width'], 'height' => $size['height']];
        } finally {
            @unlink($source);
            @unlink($target);
            @unlink($tmp);
        }
    }
}
