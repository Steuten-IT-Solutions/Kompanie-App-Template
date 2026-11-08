<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Push\PushService;
use KompanieApp\Core\Storage\GalleryRepository;
use KompanieApp\Core\Storage\GallerySubmissionRepository as Repo;

/**
 * Bilder für die Galerie einreichen und freigeben (Issue #286).
 *
 * - Wer submit_gallery hat, wählt ein Album und Bilder und bestätigt Rechte und Einverständnis der erkennbaren Personen.
 *   Der Server prüft und kodiert wie beim Hochladen der Admins (GalleryUploadService::store: neu als JPEG, ohne
 *   Metadaten samt GPS-Ort) und legt die Datei im MediaStore ab, dazu eine kleine Vorschau in der Tabelle.
 * - Alle mit review_gallery bekommen eine Benachrichtigung (je Person und Schub nur eine, siehe NOTIFY_GAP), geben frei
 *   (öffentlich oder intern; dann ein Bild mit source = upload wie beim Hochladen) oder lehnen mit Grund ab. Die
 *   einreichende Person erfährt das Ergebnis. Wer beide Rechte hat, darf auch eigene freigeben.
 * - Abgelehnte Bilder löscht der Server sofort (die Zeile mit dem Grund bleibt für die Person sichtbar). Offene
 *   Einreichungen bleiben, bis jemand sie bearbeitet; die Person kann sie zurückziehen.
 * - Videos (MP4, WebM bis 1 GB, Issue #350) kommen in Stücken über VideoUploadService, unverändert; die Vorschau
 *   ist das Standbild aus dem Browser. Prüfende sehen das Video vor der Freigabe (deliverVideo).
 * - Freigegebene lassen sich wieder entfernen (das Bild verschwindet, die Einreichung gilt als abgelehnt).
 * - Konto gelöscht (UserData::purge): alle Zeilen der Person verschwinden, freigegebene Bilder bleiben ohne Verknüpfung.
 *   Die Dateien offener Einreichungen löscht userDeleted(); was ohne App gelöscht wurde (WordPress-Netzwerk), räumt
 *   pruneOrphans() in der Aufgabe der Galerie auf.
 */
final class GallerySubmissionService
{
    /** Kantenlänge der Vorschau in der Tabelle (JPEG, Base64). */
    public const THUMB_EDGE = 480;
    public const REASON_MAX = 255;
    /** Wer innerhalb dieser Sekunden schon eingereicht hat, löst keine weitere Benachrichtigung aus (ein Schub Bilder). */
    public const NOTIFY_GAP = 600;
    /** Dateien ohne Verweis werden erst nach dieser Zeit gelöscht (ein Hochladen kann gerade laufen). */
    private const ORPHAN_AGE = 3600;
    /** Einmalig: Standardrolle „Galerie verwalten“ und die neuen Benachrichtigungen bei eingeschalteter Galerie. */
    public const SETUP_SETTING = 'gallery_submissions_setup';
    /** „Größe“ in Environment::photoUrl für das Video einer offenen Einreichung (Adresse foto/<Einreichung>/einreichung). */
    public const VIDEO_SIZE = 'einreichung';

    public function __construct(private readonly App $app)
    {
    }

    private function repo(): Repo
    {
        return new Repo($this->app->db);
    }

    /**
     * Einmalig nach dem Update: Installationen mit eingeschalteter Galerie bekommen die Standardrolle „Galerie verwalten“
     * (sonst entsteht sie nur beim Einschalten, Features::update) und bestehende Abos die neuen Arten.
     */
    public function initialize(bool $existingInstallation): void
    {
        if ($this->app->settings->get(self::SETUP_SETTING, false)) {
            return;
        }
        $this->app->settings->set(self::SETUP_SETTING, true);
        if (!$existingInstallation || !$this->app->features()->enabled('gallery')) {
            return;
        }
        $features = $this->app->features();
        $this->app->roles()->featureChanged('gallery', true, $features->roleLabel('gallery'), Features::DEFINITIONS['gallery']['role'] ?? []);
        foreach ([PushService::TYPE_GALLERY_REVIEW, PushService::TYPE_GALLERY_SUBMISSIONS] as $type) {
            try {
                $this->app->push()->offerTypeToExisting($type);
            } catch (\Throwable $e) {
                error_log('Kompanie-App: Benachrichtigungen „' . $type . '“ nicht eingeschaltet: ' . $e->getMessage());
            }
        }
    }

    /** @return array<string, mixed> Alben zur Auswahl und die eigenen Einreichungen */
    public function own(User $user): array
    {
        return [
            'albums' => array_map(static fn (array $a): array => ['id' => $a['id'], 'title' => $a['title'], 'year' => $a['year']], $this->app->galleryUploads()->albums()),
            'submissions' => array_map(fn (array $s): array => $this->out($s, false), $this->repo()->ofUser($user->id)),
        ];
    }

    /** @return array<string, mixed> offene und zuletzt freigegebene Einreichungen */
    public function review(): array
    {
        $repo = $this->repo();
        return [
            'pending' => array_map(fn (array $s): array => $this->out($s, true), $repo->withStatus(Repo::PENDING)),
            'approved' => array_map(fn (array $s): array => $this->out($s, true), $repo->withStatus(Repo::APPROVED, 50)),
        ];
    }

    public function countPending(): int
    {
        return $this->repo()->countPending();
    }

    /**
     * Ein Bild einreichen.
     *
     * @param mixed $file {name, data}
     * @return array<string, mixed> die Einreichung
     */
    public function submit(User $user, mixed $albumId, mixed $file, mixed $consent): array
    {
        if ($consent !== true) {
            throw ApiError::invalid('Bitte bestätigen, dass du die Rechte an den Bildern hast und die erkennbaren Personen einverstanden sind.');
        }
        $id = Input::integer($albumId) ?? 0;
        $this->album($id);
        return $this->insertStored($user, $id, $this->app->galleryUploads()->store($id, $file, self::THUMB_EDGE));
    }

    /** @return array<string, mixed> */
    public function album(int $id): array
    {
        return (new GalleryRepository($this->app->db))->album($id) ?? throw ApiError::notFound('Album nicht gefunden.');
    }

    /**
     * Eine abgelegte Datei (Bild aus store() oder Video aus VideoUploadService, Issue #350) als offene Einreichung
     * aufnehmen und die Prüfenden benachrichtigen. Klappt das nicht, wird die Datei gelöscht.
     *
     * @param array{key: string, name: string, width: int, height: int, md5: string, bytes: int, thumb: string|null, duration_ms?: int} $stored
     * @return array<string, mixed> die Einreichung
     */
    public function insertStored(User $user, int $id, array $stored): array
    {
        $album = $this->album($id);
        $now = $this->app->time()->nowUtc();
        $repo = $this->repo();
        try {
            $sid = $repo->insert([
                'user_id' => $user->id,
                'album_id' => $id,
                'file_key' => $stored['key'],
                'name' => $stored['name'],
                'width' => $stored['width'],
                'height' => $stored['height'],
                'bytes' => $stored['bytes'],
                'md5' => $stored['md5'],
                'thumb' => $stored['thumb'],
                'duration_ms' => max(0, (int) ($stored['duration_ms'] ?? 0)),
                'status' => Repo::PENDING,
                'consent_at' => $now,
                'created_at' => $now,
                'reason' => '',
            ]);
        } catch (\Throwable $e) {
            $this->app->gallery()->media()->delete($stored['key']);
            throw $e;
        }
        $since = gmdate('Y-m-d H:i:s', $this->app->time()->timestamp() - self::NOTIFY_GAP);
        if (!$repo->submittedSince($user->id, $since, $sid)) {
            $this->notifyReviewers($user, (string) $album['title'], $sid);
        }
        return $this->out($repo->find($sid) ?? [], false);
    }

    /** Eigene offene Einreichung zurückziehen: Datei und Zeile verschwinden. */
    public function withdraw(User $user, int $id): void
    {
        $s = $this->repo()->find($id);
        if ($s === null || (int) $s['user_id'] !== $user->id) {
            throw ApiError::notFound('Einreichung nicht gefunden.');
        }
        if ((string) $s['status'] !== Repo::PENDING) {
            throw ApiError::invalid('Nur offene Einreichungen lassen sich zurückziehen.');
        }
        $this->app->gallery()->media()->delete((string) $s['file_key']);
        $this->repo()->delete($id);
    }

    /** @return array<string, mixed> die freigegebene Einreichung */
    public function approve(User $actor, int $id, bool $intern): array
    {
        $s = $this->pending($id);
        $media = $this->app->gallery()->media();
        if ($media->path((string) $s['file_key']) === null) {
            throw ApiError::invalid('Die Datei der Einreichung fehlt. Bitte ablehnen.');
        }
        $photoId = $this->app->galleryUploads()->insertPhoto((int) $s['album_id'], [
            'key' => (string) $s['file_key'],
            'name' => (string) $s['name'],
            'width' => (int) $s['width'],
            'height' => (int) $s['height'],
            'md5' => (string) $s['md5'],
            'bytes' => (int) $s['bytes'],
            'duration_ms' => (int) ($s['duration_ms'] ?? 0),
        ], $intern);
        $this->repo()->update($id, ['status' => Repo::APPROVED, 'reviewed_by' => $actor->id, 'reviewed_at' => $this->app->time()->nowUtc(), 'photo_id' => $photoId, 'reason' => '']);
        $this->notifySubmitter($s, true, '');
        return $this->out($this->repo()->find($id) ?? [], true);
    }

    /** Ablehnen: Die Datei verschwindet sofort, der Grund bleibt für die einreichende Person sichtbar. */
    public function reject(User $actor, int $id, mixed $reason): array
    {
        $s = $this->pending($id);
        $reason = $this->validReason($reason, true);
        $this->app->gallery()->media()->delete((string) $s['file_key']);
        $this->repo()->update($id, ['status' => Repo::REJECTED, 'reviewed_by' => $actor->id, 'reviewed_at' => $this->app->time()->nowUtc(), 'reason' => $reason, 'thumb' => null, 'file_key' => '']);
        $this->notifySubmitter($s, false, $reason);
        return $this->out($this->repo()->find($id) ?? [], true);
    }

    /** Freigegebene Einreichung wieder entfernen: Das Bild verschwindet aus der Galerie, die Einreichung gilt als abgelehnt. */
    public function remove(User $actor, int $id, mixed $reason): void
    {
        $s = $this->repo()->find($id);
        if ($s === null || (string) $s['status'] !== Repo::APPROVED) {
            throw ApiError::notFound('Freigegebene Einreichung nicht gefunden.');
        }
        $reason = $this->validReason($reason, false);
        $photo = $s['photo_id'] !== null ? (new GalleryRepository($this->app->db))->photo((int) $s['photo_id']) : null;
        if ($photo !== null) {
            $this->app->galleryUploads()->removePhoto($photo);
        } else {
            $this->app->gallery()->media()->delete((string) $s['file_key']);
        }
        $this->markRemoved($s, $actor->id, $reason);
    }

    /** Ein hochgeladenes Bild wurde gelöscht (Einstellungen → Galerie): eine Einreichung dazu gilt als wieder entfernt. */
    public function photoRemoved(int $photoId, ?User $actor): void
    {
        $s = $this->repo()->byPhoto($photoId);
        if ($s !== null && (string) $s['status'] === Repo::APPROVED) {
            $this->markRemoved($s, $actor?->id, '');
        }
    }

    /** Album gelöscht: offene Einreichungen darin samt Dateien und alle Zeilen dazu entfernen. */
    public function albumDeleted(int $albumId): void
    {
        $repo = $this->repo();
        foreach ($repo->ofAlbum($albumId) as $s) {
            if ((string) $s['status'] === Repo::PENDING) {
                $this->app->gallery()->media()->delete((string) $s['file_key']);
            }
            $repo->delete((int) $s['id']);
        }
    }

    /** Konto wird gelöscht: Dateien offener Einreichungen löschen (die Zeilen löscht UserData::purge). */
    public function userDeleted(int $userId): void
    {
        foreach ($this->repo()->pendingOfUser($userId) as $s) {
            $this->app->gallery()->media()->delete((string) $s['file_key']);
        }
    }

    /**
     * Dateien in der Ablage ohne Bild und ohne offene Einreichung löschen (z. B. nach dem Löschen eines Kontos ohne App),
     * erst nach ORPHAN_AGE, damit ein gerade laufendes Hochladen nichts verliert.
     *
     * @return int gelöschte Dateien
     */
    public function pruneOrphans(): int
    {
        $media = $this->app->gallery()->media();
        $keys = $media->keys();
        if ($keys === []) {
            return 0;
        }
        $used = array_flip($this->repo()->pendingKeys());
        foreach ($this->app->db->select("SELECT drive_file_id FROM {photos} WHERE source = 'upload'") as $r) {
            $used[substr((string) $r['drive_file_id'], strlen(GalleryService::UPLOAD_PREFIX))] = true;
        }
        $limit = $this->app->time()->timestamp() - self::ORPHAN_AGE;
        $removed = 0;
        foreach ($keys as $key => $mtime) {
            if (!isset($used[$key]) && $mtime < $limit) {
                $media->delete($key);
                $removed++;
            }
        }
        return $removed;
    }

    /** @param array<string, mixed> $s */
    private static function isVideo(array $s): bool
    {
        return isset(VideoUploadService::TYPES[strtolower(pathinfo((string) ($s['file_key'] ?? ''), PATHINFO_EXTENSION))]);
    }

    /**
     * Video einer offenen Einreichung zum Ansehen vor der Freigabe (Issue #350), Adresse photoUrl(<Einreichung>,
     * VIDEO_SIZE), Antwort wie GalleryService::deliverVideo. Nur für Prüfende ($mayReview: [darf prüfen, angemeldet]).
     *
     * @param \Closure(): array{0: bool, 1: bool} $mayReview
     * @return array<string, mixed>
     */
    public function deliverVideo(int $id, ?string $range, \Closure $mayReview, ?\Closure $begin = null, ?\Closure $write = null): array
    {
        [$allowed, $loggedIn] = $mayReview();
        if (!$allowed) {
            return ['status' => $loggedIn ? 403 : 401];
        }
        $s = $this->repo()->find($id);
        if ($s === null || (string) $s['status'] !== Repo::PENDING || !self::isVideo($s)) {
            return ['status' => 404];
        }
        $key = (string) $s['file_key'];
        $file = $this->app->gallery()->media()->path($key);
        if ($file === null) {
            return ['status' => 404];
        }
        $mime = VideoUploadService::TYPES[strtolower(pathinfo($key, PATHINFO_EXTENSION))];
        return $this->app->gallery()->deliverFile($file, $mime, '"s' . md5($key) . '"', true, $range, $begin, $write);
    }

    /** @return array<string, mixed> */
    private function pending(int $id): array
    {
        $s = $this->repo()->find($id);
        if ($s === null || (string) $s['status'] !== Repo::PENDING) {
            throw ApiError::notFound('Offene Einreichung nicht gefunden.');
        }
        return $s;
    }

    /** @param array<string, mixed> $s */
    private function markRemoved(array $s, ?int $actorId, string $reason): void
    {
        $this->repo()->update((int) $s['id'], [
            'status' => Repo::REJECTED,
            'reviewed_by' => $actorId,
            'reviewed_at' => $this->app->time()->nowUtc(),
            'reason' => $reason !== '' ? $reason : 'Wieder entfernt.',
            'photo_id' => null,
            'file_key' => '',
            'thumb' => null,
        ]);
    }

    private function validReason(mixed $raw, bool $required): string
    {
        $reason = Text::line(is_string($raw) ? $raw : '', self::REASON_MAX + 1);
        if ($required && $reason === '') {
            throw ApiError::invalid('Bitte kurz begründen, warum das Bild nicht freigegeben wird.');
        }
        if (mb_strlen($reason) > self::REASON_MAX) {
            throw ApiError::invalid(sprintf('Der Grund darf höchstens %d Zeichen lang sein.', self::REASON_MAX));
        }
        return $reason;
    }

    /**
     * @param array<string, mixed> $s Zeile mit album_title und album_year
     * @return array<string, mixed>
     */
    private function out(array $s, bool $forReview): array
    {
        $out = [
            'id' => (int) ($s['id'] ?? 0),
            'album_id' => (int) ($s['album_id'] ?? 0),
            'album' => trim(($s['album_year'] ?? '') . ' · ' . ($s['album_title'] ?? ''), ' ·'),
            'name' => (string) ($s['name'] ?? ''),
            'status' => (string) ($s['status'] ?? ''),
            'reason' => (string) ($s['reason'] ?? ''),
            'created_at' => (string) ($s['created_at'] ?? ''),
            'reviewed_at' => $s['reviewed_at'] ?? null,
            'thumb' => is_string($s['thumb'] ?? null) && $s['thumb'] !== '' ? 'data:image/jpeg;base64,' . $s['thumb'] : null,
            'photo_id' => isset($s['photo_id']) ? (int) $s['photo_id'] : null,
            'type' => self::isVideo($s) ? 'video' : 'image',
        ];
        if ($forReview && self::isVideo($s) && (string) ($s['status'] ?? '') === Repo::PENDING) {
            // Zum Ansehen vor der Freigabe (nur mit review_gallery, siehe deliverVideo)
            $out['video'] = $this->app->env->photoUrl((int) $s['id'], self::VIDEO_SIZE);
        }
        if ($forReview) {
            // Freigegebene: ob das Bild in der Galerie intern ist (umstellbar mit PATCH /gallery/photos/{id})
            $out['intern'] = isset($s['photo_is_intern']) ? (bool) (int) $s['photo_is_intern'] : null;
        }
        if ($forReview) {
            $user = $this->app->users->find((int) ($s['user_id'] ?? 0));
            $out['user_id'] = (int) ($s['user_id'] ?? 0);
            $out['user_name'] = $user?->name ?? 'Gelöschtes Konto';
        }
        return $out;
    }

    private function notifyReviewers(User $user, string $album, int $submissionId): void
    {
        $gallery = $this->app->config->sectionLabel('gallery', 'Galerie');
        try {
            $this->app->push()->notifyAudience(
                PushService::TYPE_GALLERY_REVIEW,
                PushService::message($gallery . ': neue Bilder', sprintf('%s hat Bilder für „%s“ eingereicht.', $user->name, $album), 'galerie/pruefen', 'galerie-pruefen'),
                // Wer selbst einreicht, braucht keinen Hinweis auf die eigenen Bilder.
                static fn (int $uid): bool => $uid !== $user->id,
            );
        } catch (\Throwable $e) {
            error_log('Kompanie-App: Benachrichtigung zu Einreichung ' . $submissionId . ' nicht eingereiht: ' . $e->getMessage());
        }
    }

    /** @param array<string, mixed> $s */
    private function notifySubmitter(array $s, bool $approved, string $reason): void
    {
        $gallery = $this->app->config->sectionLabel('gallery', 'Galerie');
        $album = (string) ($s['album_title'] ?? '');
        $body = $approved
            ? sprintf('Dein Bild „%s“ ist jetzt im Album „%s“.', $s['name'], $album)
            : sprintf('Dein Bild „%s“ wurde nicht freigegeben: %s', $s['name'], $reason);
        try {
            $this->app->push()->notifyUser(
                (int) $s['user_id'],
                PushService::TYPE_GALLERY_SUBMISSIONS,
                PushService::message($gallery . ($approved ? ': Bild freigegeben' : ': Bild abgelehnt'), $body, 'galerie/einreichen', 'galerie-einreichung-' . $s['id']),
            );
        } catch (\Throwable $e) {
            error_log('Kompanie-App: Benachrichtigung zu Einreichung ' . $s['id'] . ' nicht eingereiht: ' . $e->getMessage());
        }
    }
}
