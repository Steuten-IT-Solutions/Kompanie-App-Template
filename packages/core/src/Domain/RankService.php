<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Http\FileCache;
use KompanieApp\Core\I18n\I18n;
use KompanieApp\Core\Storage\RankRepository;

/**
 * Dienstgrade (Issue #9).
 *
 * Eine Liste mit einer Reihenfolge (sort, der niedrigste zuerst). Den Dienstgrad eines Mitglieds legt fest, wer
 * „Dienstgrade verwalten“ darf; er ändert sich nie von selbst.
 *
 * - Ohne den Bereich Punkte: frei befördern und degradieren, mit optionaler Begründung und Verlauf.
 * - Mit Punkten (Features::ranksUsePoints): Punkteschwellen. Befördern nur, wenn die Schwelle des Ziels erreicht ist;
 *   das gilt für alle, auch Admins. Degradieren geht immer, auch über der Schwelle (z. B. um eine zu hohe Einstufung
 *   zu korrigieren). Dienstgrade ohne Schwelle sind frei (bis eine eingetragen ist). Dazu „noch N Punkte bis …“,
 *   „Schwelle erreicht seit“ und die Beförderungsübersicht.
 * - Abzeichen (Issue #17): optional ein Bild je Dienstgrad (PNG, JPG, WebP oder SVG, höchstens 1 MB), hochladen und
 *   entfernen mit „Dienstgrade verwalten“. Rasterbilder werden zu PNG in 64/128/256 Pixeln (mit Transparenz), SVGs
 *   bereinigt (SvgSanitizer) gespeichert; die App schickt dazu eine PNG-Fassung für Benachrichtigungen mit. Die
 *   Dateien liegen geschützt in der Dateiablage (abzeichen/) und kommen nur über badgeUrl() nach Prüfung der Anmeldung.
 *
 * @phpstan-import-type Rank from RankRepository
 * @phpstan-import-type HistoryEntry from RankRepository
 */
final class RankService
{
    public const TITLE_MAX = 60;
    public const MAX_THRESHOLD = 1000000;
    public const KIND_PROMOTION = 'promotion';
    public const KIND_DEMOTION = 'demotion';
    public const KIND_INITIAL = 'initial';
    /**
     * „Bald zur Beförderung bereit“: Bis zur Schwelle des nächsten Dienstgrads fehlt höchstens so viel (wie bei den
     * Aufnahmen „noch eine Teilnahme“). Fest, nicht einstellbar.
     */
    public const SOON_POINTS = 1;

    public const BADGE_MAX_BYTES = 1024 * 1024;
    /** Größen der PNG-Fassungen (längere Kante); badge_url zeigt auf BADGE_DEFAULT_SIZE, die App ändert s bei Bedarf. */
    public const BADGE_SIZES = [64, 128, 256];
    public const BADGE_DEFAULT_SIZE = 128;
    /** Größte Kantenlänge eines hochgeladenen Rasterbilds (schützt den Arbeitsspeicher beim Verkleinern). */
    public const BADGE_MAX_EDGE = 4096;
    /** Endung => Signatur; bei WebP steht „WEBP“ zusätzlich ab Byte 8, SVG wird als XML geprüft. */
    public const BADGE_TYPES = ['png' => "\x89PNG\r\n\x1A\n", 'jpg' => "\xFF\xD8\xFF", 'jpeg' => "\xFF\xD8\xFF", 'webp' => 'RIFF', 'svg' => ''];
    private const BADGE_DIR = 'abzeichen/';
    /** So heißen gespeicherte Abzeichen (ohne Größe und Endung); andere Werte, z. B. aus einer veränderten Sicherung, nie anfassen. */
    private const BADGE_PATTERN = '/^abz-[0-9a-f]{24}$/';
    /** Strenge Richtlinie für SVGs: keine Skripte, keine Verweise nach außen, eigener Ursprung (sandbox). */
    public const SVG_CSP = "default-src 'none'; style-src 'unsafe-inline'; sandbox";

    private ?RankRepository $repo = null;

    public function __construct(private readonly App $app)
    {
    }

    public function repository(): RankRepository
    {
        return $this->repo ??= new RankRepository($this->app->db);
    }

    public function usesPoints(): bool
    {
        return $this->app->features()->ranksUsePoints();
    }

    /* ---------------------------------------------------------------- */
    /* Liste                                                             */
    /* ---------------------------------------------------------------- */

    /**
     * Für die App. Ohne Punkte keine Schwellen; mit $all auch inaktive und wie oft vergeben (Pflege).
     *
     * @return list<array<string, mixed>>
     */
    public function list(bool $all): array
    {
        // Zahl der Träger je Dienstgrad aus einer Abfrage (nicht eine je Dienstgrad)
        $assigned = $all ? array_count_values($this->forgetUnknownUsers()) : [];
        $points = $this->usesPoints();
        return array_map(fn (array $r): array => [
            'id' => $r['id'],
            'title' => $r['title'],
            'sort' => $r['sort'],
            'min_points' => $points || $all ? $r['min_points'] : null,
            'active' => $r['active'],
            'badge_url' => $this->badgeUrl($r),
        ] + ($all ? ['assigned' => $assigned[$r['id']] ?? 0] + $this->badgeInfo($r) : []), $this->repository()->all(!$all));
    }

    /**
     * @param array<string, mixed> $in title, min_points
     * @return array<string, mixed>
     */
    public function create(array $in): array
    {
        $repo = $this->repository();
        $title = $this->validTitle($in['title'] ?? null, null);
        $min = self::validThreshold($in['min_points'] ?? null);
        $sort = $repo->maxSort() + 10;
        $this->checkThresholds($repo->all(false), ['title' => $title, 'sort' => $sort, 'min_points' => $min, 'active' => true]);
        return $this->format($repo->find($repo->insert($title, $sort, $min)) ?? throw ApiError::notFound(I18n::t('{rank} nicht gefunden.', ['rank' => $this->app->config->rankLabel()])));
    }

    /**
     * @param array<string, mixed> $in title, min_points, active
     * @return array<string, mixed>
     */
    public function update(int $id, array $in): array
    {
        $repo = $this->repository();
        $rank = $repo->find($id) ?? throw ApiError::notFound(I18n::t('{rank} nicht gefunden.', ['rank' => $this->app->config->rankLabel()]));
        $data = [];
        if (array_key_exists('title', $in)) {
            $data['title'] = $this->validTitle($in['title'], $id);
        }
        if (array_key_exists('min_points', $in)) {
            $data['min_points'] = self::validThreshold($in['min_points']);
        }
        if (array_key_exists('active', $in)) {
            if (!is_bool($in['active'])) {
                throw ApiError::invalid(I18n::t('Ungültiger Wert für „aktiv“.'));
            }
            $data['active'] = $in['active'];
        }
        $next = array_merge($rank, $data);
        $others = array_values(array_filter($repo->all(false), static fn (array $r): bool => $r['id'] !== $id));
        $this->checkThresholds($others, $next);
        $repo->update($id, $data);
        return $this->format($repo->find($id) ?? $rank);
    }

    /** Vergebene Dienstgrade lassen sich nur deaktivieren, nicht löschen. Das Abzeichen wird mit gelöscht. */
    public function delete(int $id): void
    {
        $repo = $this->repository();
        $rank = $repo->find($id) ?? throw ApiError::notFound(I18n::t('{rank} nicht gefunden.', ['rank' => $this->app->config->rankLabel()]));
        $this->forgetUnknownUsers();
        if ($repo->assignedCount($id) > 0) {
            throw ApiError::invalid(I18n::t('Dieser {rank} ist vergeben und lässt sich nur deaktivieren.', ['rank' => $this->app->config->rankLabel()]));
        }
        $repo->delete($id);
        $this->app->translations()->repository()->deleteRef('rank', (string) $id);
        $this->removeBadgeFiles($rank['badge_file']);
    }

    /**
     * Bezeichnung in der Sprache der Anfrage („Einrichten je Sprache“): eingetragene Übersetzung, sonst die deutsche.
     * Gespeichert (Verlauf, Änderungsprotokoll) wird immer die deutsche.
     */
    public function displayTitle(int $id, string $german): string
    {
        return $this->app->translations()->name('rank', (string) $id, $german);
    }

    /**
     * Neue Reihenfolge: alle IDs vom niedrigsten zum höchsten.
     *
     * @return list<array<string, mixed>>
     */
    public function reorder(mixed $ids): array
    {
        $repo = $this->repository();
        $ranks = [];
        foreach ($repo->all(false) as $r) {
            $ranks[$r['id']] = $r;
        }
        $order = is_array($ids) ? array_map(static fn (mixed $v): ?int => Input::integer($v), array_values($ids)) : [];
        $given = $order;
        sort($given);
        $known = array_keys($ranks);
        sort($known);
        if ($given !== $known) {
            throw ApiError::invalid(I18n::t('Bitte die Reihenfolge aller Einträge angeben.'));
        }
        $next = [];
        foreach ($order as $i => $id) {
            $next[] = ['sort' => ($i + 1) * 10] + $ranks[(int) $id];
        }
        $this->checkThresholds($next, null);
        foreach ($next as $r) {
            if ($r['sort'] !== $ranks[$r['id']]['sort']) {
                $repo->update($r['id'], ['sort' => $r['sort']]);
            }
        }
        return $this->list(true);
    }

    /* ---------------------------------------------------------------- */
    /* Abzeichen (Issue #17)                                             */
    /* ---------------------------------------------------------------- */

    /**
     * Abzeichen hochladen oder ersetzen; das alte wird gelöscht.
     *
     * @param mixed $file {name, data (Base64)}: PNG, JPG, WebP oder SVG
     * @param mixed $png bei SVG optional: PNG-Fassung (Base64, 256 Pixel) für Benachrichtigungen, in der App gezeichnet
     * @return array<string, mixed> der Dienstgrad wie in list(true)
     */
    public function uploadBadge(int $id, mixed $file, mixed $png = null): array
    {
        $repo = $this->repository();
        $rank = $repo->find($id) ?? throw ApiError::notFound(I18n::t('{rank} nicht gefunden.', ['rank' => $this->app->config->rankLabel()]));
        $name = is_array($file) && is_string($file['name'] ?? null) ? $file['name'] : '';
        $bytes = self::decode(is_array($file) ? ($file['data'] ?? null) : null, I18n::t('Es ist keine Datei angekommen.'));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset(self::BADGE_TYPES[$ext])) {
            throw ApiError::invalid(I18n::t('Erlaubt sind PNG, JPG, WebP und SVG.'));
        }
        $base = 'abz-' . bin2hex(random_bytes(12));
        try {
            if ($ext === 'svg') {
                try {
                    $svg = SvgSanitizer::sanitize($bytes);
                } catch (\InvalidArgumentException $e) {
                    throw ApiError::invalid($e->getMessage());
                }
                $this->writeBadgeFile($base . '.svg', $svg);
                $hash = $svg;
                if ($png !== null && $png !== '') {
                    $raster = self::decode($png, I18n::t('Die PNG-Fassung des Abzeichens ist nicht angekommen.'));
                    $this->checkRaster($raster, 'png');
                    $this->rasterize($raster, 'png', $base);
                    $hash .= $raster;
                }
            } else {
                $this->checkRaster($bytes, $ext);
                $this->rasterize($bytes, $ext, $base);
                $hash = $bytes;
            }
            $repo->update($id, ['badge_file' => $base, 'badge_ext' => $ext === 'jpeg' ? 'jpg' : $ext, 'badge_version' => substr(hash('sha256', $hash), 0, 16)]);
        } catch (\Throwable $e) {
            $this->removeBadgeFiles($base);
            throw $e;
        }
        $this->removeBadgeFiles($rank['badge_file']);
        return $this->format($repo->find($id) ?? $rank);
    }

    /** @return array<string, mixed> der Dienstgrad wie in list(true) */
    public function removeBadge(int $id): array
    {
        $repo = $this->repository();
        $rank = $repo->find($id) ?? throw ApiError::notFound(I18n::t('{rank} nicht gefunden.', ['rank' => $this->app->config->rankLabel()]));
        $repo->update($id, ['badge_file' => '', 'badge_ext' => '', 'badge_version' => '']);
        $this->removeBadgeFiles($rank['badge_file']);
        return $this->format($repo->find($id) ?? $rank);
    }

    /**
     * Für die Auslieferung über badgeUrl(): sehen darf jede angemeldete Person mit Mitgliederbereich oder internem
     * Kalender (auch Anwärter) und jede, die die Mitgliederliste sieht (dort stehen die Abzeichen auch). Mit dem Bereich
     * directory steht die Mitgliederliste mit den Abzeichen öffentlich (Issue #112): dann alle, auch ohne Anmeldung.
     * Die Header setzt der Kern, damit beide Adapter gleich antworten.
     *
     * @return array{status: int, headers: array<string, string>, path?: string}
     */
    public function badgeForDelivery(?User $user, int $rankId, mixed $version, mixed $size): array
    {
        $deny = static fn (int $status): array => ['status' => $status, 'headers' => ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']];
        if (!$this->app->features()->enabled('ranks')) {
            return $deny(404);
        }
        if ($this->app->maintenance()->blocks($user)) {
            return $deny(503);
        }
        // Abzeichen stehen in der Mitgliederliste, bei den Dienstgraden und beim eigenen Dienstgrad; ohne Anmeldung mit
        // den Rechten von Gast (App::anonymous).
        $viewer = $user ?? $this->app->anonymous();
        $viewers = [Capability::ViewRanks, Capability::ViewOwnRank, ...Capability::memberList()];
        if ($this->app->features()->enabled('directory')) {
            $viewers[] = Capability::ViewDirectory;
        }
        $sees = static fn (User $u): bool => array_filter($viewers, static fn (Capability $c): bool => $u->can($c)) !== [];
        if (!$sees($viewer)) {
            return $deny($user === null ? 401 : 403);
        }
        // Sehen sie auch Besucher ohne Anmeldung, darf jeder Zwischenspeicher sie behalten.
        $public = $sees($this->app->anonymous());
        $rank = $this->repository()->find($rankId);
        if ($rank === null || ($path = $this->badgePath($rank, Input::integer($size) ?? self::BADGE_DEFAULT_SIZE)) === null) {
            return $deny(404);
        }
        $svg = $rank['badge_ext'] === 'svg';
        $headers = [
            'Content-Type' => $svg ? 'image/svg+xml' : 'image/png',
            'X-Content-Type-Options' => 'nosniff',
            // Die Adresse wechselt mit jeder Fassung: lange speichern, ohne öffentliche Mitgliederliste nur im Browser. Eine
            // veraltete Fassung (alte Adresse) jedes Mal neu prüfen lassen; in der Vorschau (Issue #63) nie speichern.
            'Cache-Control' => $this->app->preview()->cacheControl(($public ? 'public' : 'private') . (is_string($version) && hash_equals($rank['badge_version'], $version) ? ', max-age=31536000, immutable' : ', no-cache')),
            'X-Robots-Tag' => 'noindex',
        ];
        if ($svg) {
            $headers['Content-Security-Policy'] = self::SVG_CSP;
        }
        $etag = FileCache::etag(basename($path), $path);
        if ($etag !== null) {
            $headers['ETag'] = $etag;
        }
        return ['status' => 200, 'headers' => $headers, 'path' => $path];
    }

    /** Vollständige Adresse der 256-Pixel-PNG-Fassung (für Benachrichtigungen) oder null. */
    public function badgePushImage(int $rankId): ?string
    {
        $rank = $this->repository()->find($rankId);
        if ($rank === null || !$this->badgeValid($rank) || !is_file($this->badgeFile($rank['badge_file'] . '-256.png'))) {
            return null;
        }
        return $this->app->env->badgeUrl($rank['id'], $rank['badge_version'], 256);
    }

    /**
     * Adresse des Abzeichens (128 Pixel) oder null; die App ersetzt s=128 bei Bedarf durch 64 oder 256.
     *
     * @param Rank $rank
     */
    private function badgeUrl(array $rank): ?string
    {
        return $this->badgePath($rank, self::BADGE_DEFAULT_SIZE) === null ? null : $this->app->env->badgeUrl($rank['id'], $rank['badge_version'], self::BADGE_DEFAULT_SIZE);
    }

    /**
     * Für die Pflege: Format (svg, png, jpg, webp) und ob es ein Bild für Benachrichtigungen gibt.
     *
     * @param Rank $rank
     * @return array{badge_type: string|null, badge_push: bool}
     */
    private function badgeInfo(array $rank): array
    {
        $ok = $this->badgePath($rank, self::BADGE_DEFAULT_SIZE) !== null;
        return [
            'badge_type' => $ok ? $rank['badge_ext'] : null,
            'badge_push' => $ok && is_file($this->badgeFile($rank['badge_file'] . '-256.png')),
        ];
    }

    /**
     * Pfad der Datei für eine Größe, wenn es sie gibt; SVG hat für alle Größen dieselbe Datei.
     *
     * @param Rank $rank
     */
    private function badgePath(array $rank, int $size): ?string
    {
        if (!$this->badgeValid($rank) || !in_array($size, self::BADGE_SIZES, true)) {
            return null;
        }
        $path = $this->badgeFile($rank['badge_file'] . ($rank['badge_ext'] === 'svg' ? '.svg' : '-' . $size . '.png'));
        return is_file($path) ? $path : null;
    }

    /** @param Rank $rank */
    private function badgeValid(array $rank): bool
    {
        return preg_match(self::BADGE_PATTERN, $rank['badge_file']) === 1 && isset(self::BADGE_TYPES[$rank['badge_ext']]) && $rank['badge_version'] !== '';
    }

    private function badgeFile(string $name): string
    {
        return $this->app->files->path(self::BADGE_DIR . $name, false);
    }

    private function writeBadgeFile(string $name, string $bytes): void
    {
        if (@file_put_contents($this->badgeFile($name), $bytes) === false) {
            throw new ApiError(500, 'storage_failed', I18n::t('Das Abzeichen konnte nicht gespeichert werden.'));
        }
    }

    /** Alle Dateien eines Abzeichens löschen (nur gültige Namen). */
    private function removeBadgeFiles(string $base): void
    {
        if (preg_match(self::BADGE_PATTERN, $base) !== 1) {
            return;
        }
        foreach (['.svg', ...array_map(static fn (int $s): string => '-' . $s . '.png', self::BADGE_SIZES)] as $suffix) {
            $this->app->files->delete(self::BADGE_DIR . $base . $suffix, false);
        }
    }

    /** Base64 (auch als data:-Adresse) dekodieren und die Größe prüfen. */
    private static function decode(mixed $data, string $missing): string
    {
        $data = is_string($data) ? $data : '';
        if (str_starts_with($data, 'data:') && ($comma = strpos($data, ',')) !== false) {
            $data = substr($data, $comma + 1);
        }
        $bytes = $data === '' ? '' : base64_decode($data, true);
        if ($bytes === false || $bytes === '') {
            throw ApiError::invalid($missing);
        }
        if (strlen($bytes) > self::BADGE_MAX_BYTES) {
            throw ApiError::invalid(I18n::t('Das Abzeichen darf höchstens 1 MB groß sein.'));
        }
        return $bytes;
    }

    /** Rasterbild: Signatur passend zur Endung und eine vernünftige Größe in Pixeln. */
    private function checkRaster(string $bytes, string $ext): void
    {
        $signature = self::BADGE_TYPES[$ext];
        $ok = strncmp($bytes, $signature, strlen($signature)) === 0 && ($ext !== 'webp' || substr($bytes, 8, 4) === 'WEBP');
        $info = $ok ? @getimagesizefromstring($bytes) : false;
        if ($info === false) {
            throw ApiError::invalid(I18n::t('Die Datei ist keine gültige .{ext}-Datei.', ['ext' => $ext]));
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] > self::BADGE_MAX_EDGE || $info[1] > self::BADGE_MAX_EDGE) {
            throw ApiError::invalid(I18n::t('Das Bild darf höchstens {width} × {height} Pixel groß sein.', ['width' => self::BADGE_MAX_EDGE, 'height' => self::BADGE_MAX_EDGE]));
        }
    }

    /** PNG-Fassungen in allen Größen erzeugen (mit Transparenz, nie vergrößert). */
    private function rasterize(string $bytes, string $ext, string $base): void
    {
        $tmp = $this->app->files->tempFile();
        // Mit Endung: Der Bildeditor von WordPress erkennt das Format daran.
        $source = $tmp . '.' . $ext;
        try {
            if (@file_put_contents($source, $bytes) === false) {
                throw new ApiError(500, 'storage_failed', I18n::t('Das Abzeichen konnte nicht gespeichert werden.'));
            }
            foreach (self::BADGE_SIZES as $size) {
                try {
                    $this->app->images->resizeToPng($source, $this->badgeFile($base . '-' . $size . '.png'), $size);
                } catch (\RuntimeException $e) {
                    throw ApiError::invalid(I18n::t('Das Bild kann nicht verarbeitet werden: {error}', ['error' => $e->getMessage()]));
                }
            }
        } finally {
            @unlink($source);
            @unlink($tmp);
        }
    }

    /**
     * Kurzform eines Dienstgrads für Antworten: ID, Bezeichnung, Abzeichen.
     *
     * @param Rank|null $rank
     * @return array{id: int, title: string, badge_url: string|null}|null
     */
    private function ref(?array $rank): ?array
    {
        return $rank === null ? null : ['id' => $rank['id'], 'title' => $rank['title'], 'badge_url' => $this->badgeUrl($rank)];
    }

    /* ---------------------------------------------------------------- */
    /* Stand eines Mitglieds                                             */
    /* ---------------------------------------------------------------- */

    /**
     * Nur der aktuelle Dienstgrad (z. B. für /me): ohne die Buchungen für Schwellen und Fortschritt, die status()
     * dafür liest.
     *
     * @return array{id: int, title: string, badge_url: string|null}|null
     */
    public function current(int $userId): ?array
    {
        $rankId = $this->repository()->rankOf($userId);
        return $rankId === null ? null : $this->ref($this->byId($this->repository()->all(false), $rankId));
    }

    /**
     * Dienstgrad eines Mitglieds; mit Punkten zusätzlich nächster Dienstgrad, fehlende Punkte und Schwellen.
     *
     * @return array<string, mixed>
     */
    public function status(int $userId): array
    {
        $ranks = $this->repository()->all(false);
        $current = $this->byId($ranks, $this->repository()->rankOf($userId));
        $out = ['rank' => $this->ref($current)];
        if (!$this->usesPoints()) {
            return $out;
        }
        $entries = $this->repository()->pointEntries($userId);
        return $out + $this->progress($ranks, $current, $entries);
    }

    /** @return array<int, string> Benutzer-ID => Bezeichnung des Dienstgrads in der Sprache der Anfrage (für Listen) */
    public function titles(): array
    {
        return array_map(fn (array $r): string => $this->displayTitle($r['id'], $r['title']), $this->assigned());
    }

    /** @return array<int, array{id: int, title: string, badge_url: string|null}> Benutzer-ID => Dienstgrad (für die Mitgliederliste) */
    public function assigned(): array
    {
        $ranks = [];
        foreach ($this->repository()->all(false) as $r) {
            $ranks[$r['id']] = $r;
        }
        $out = [];
        foreach ($this->repository()->assignments() as $userId => $rankId) {
            if (isset($ranks[$rankId])) {
                $out[$userId] = $this->ref($ranks[$rankId]) ?? throw new \LogicException('Dienstgrad fehlt');
            }
        }
        return $out;
    }

    /** @return list<array<string, mixed>> neueste zuerst */
    public function history(int $userId): array
    {
        $rows = $this->repository()->history($userId);
        $names = (new UserNames($this->app->users))->prime(array_column($rows, 'changed_by'));
        $ranks = [];
        foreach ($this->repository()->all(false) as $r) {
            $ranks[$r['id']] = $r;
        }
        // Der Verlauf speichert nur die Bezeichnungen; das Abzeichen ist das heutige des Dienstgrads (falls es ihn noch gibt).
        return array_map(fn (array $h): array => [
            'id' => $h['id'],
            'from' => $h['from_title'] !== '' ? $h['from_title'] : null,
            'to' => $h['to_title'] !== '' ? $h['to_title'] : null,
            // Zum Anzeigen in einer weiteren Sprache (Übersetzung des Dienstgrads, sonst die gespeicherte Bezeichnung)
            'from_rank_id' => $h['from_rank_id'],
            'to_rank_id' => $h['to_rank_id'],
            'to_badge_url' => $h['to_rank_id'] !== null && isset($ranks[$h['to_rank_id']]) ? $this->badgeUrl($ranks[$h['to_rank_id']]) : null,
            'kind' => $h['kind'],
            'points_at_change' => $h['points_at_change'] === null ? null : ScoreService::pointsValue($h['points_at_change']),
            'reason' => $h['reason'],
            'changed_by_name' => $names->name($h['changed_by']),
            'changed_at' => $h['changed_at'],
        ], $rows);
    }

    /**
     * Beförderungsübersicht (nur mit Punkten): erreichte Schwellen zuerst (die ältesten oben), dann nach fehlenden
     * Punkten; ohne nächsten Dienstgrad bzw. ohne Schwelle am Ende.
     *
     * @return list<array<string, mixed>>
     */
    public function promotions(): array
    {
        if (!$this->usesPoints()) {
            throw new ApiError(403, 'feature_disabled', I18n::t('Die Beförderungsübersicht gibt es nur zusammen mit dem Bereich {points}.', ['points' => $this->app->config->sectionLabel('points', 'Punkte')]));
        }
        $repo = $this->repository();
        $ranks = $repo->all(false);
        $assigned = $repo->assignments();
        $byUser = [];
        foreach ($repo->pointEntries() as $e) {
            $byUser[$e['user_id']][] = $e;
        }
        $out = [];
        foreach ($this->app->users->list(false) as $u) {
            if (!$u->isMember()) {
                continue;
            }
            $current = $this->byId($ranks, $assigned[$u->id] ?? null);
            $out[] = ['user_id' => $u->id, 'name' => $u->name, 'rank' => $this->ref($current)]
                + $this->progress($ranks, $current, $byUser[$u->id] ?? []);
        }
        usort($out, static function (array $a, array $b): int {
            $group = static fn (array $r): int => $r['points_to_next'] === null ? 2 : ($r['points_to_next'] <= 0 ? 0 : 1);
            // Ohne Datum (z. B. Schwelle 0 ohne Buchungen) ans Ende der Gruppe.
            $key = static fn (array $r): mixed => $group($r) === 0 ? ($r['threshold_reached_since'] ?? '9999-12-31') : $r['points_to_next'];
            return [$group($a), $key($a), $a['name']] <=> [$group($b), $key($b), $b['name']];
        });
        return $out;
    }

    /* ---------------------------------------------------------------- */
    /* Befördern und degradieren                                         */
    /* ---------------------------------------------------------------- */

    /**
     * Dienstgrad setzen (null = keiner) nach den Regeln; schreibt den Verlauf.
     *
     * @return array<string, mixed> neuer Stand wie status()
     */
    public function change(User $actor, int $userId, mixed $rankId, mixed $reason, mixed $date = null): array
    {
        $user = $this->app->users->find($userId) ?? throw ApiError::notFound(I18n::t('Mitglied nicht gefunden.'));
        // Wer kein Mitglied (mehr) ist, z. B. nach einem Rollenwechsel zum Gast, kann den Dienstgrad nur verlieren.
        if (!$user->isMember() && $rankId !== null && $rankId !== '' && $rankId !== 0) {
            throw ApiError::invalid(I18n::t('Einen {rank} haben nur Mitglieder.', ['rank' => $this->app->config->rankLabel()]));
        }
        $repo = $this->repository();
        $ranks = $repo->all(false);
        $current = $this->byId($ranks, $repo->rankOf($userId));
        $target = $this->target($ranks, $rankId);
        if (($current['id'] ?? null) === ($target['id'] ?? null)) {
            throw ApiError::invalid(I18n::t('Das ist schon der {rank} dieser Person.', ['rank' => $this->app->config->rankLabel()]));
        }
        [$day, $moment] = $this->changeMoment($userId, $date);
        // Zählen die Punkte bis zum Tag des Wechsels: Bei einem früheren Datum nicht die, die erst danach dazukamen.
        $total = !$this->usesPoints() ? null
            : ($day === $this->app->time()->today()->format('Y-m-d') ? $this->app->scores()->pointsTotal($userId) : $this->pointsOn($userId, $day));
        // Punkteregeln nur für Mitglieder; frühere Mitglieder verlieren den Dienstgrad ohne Prüfung.
        $past = $day === $this->app->time()->today()->format('Y-m-d') ? null : $day;
        $kind = $user->isMember() ? $this->checkChange($ranks, $current, $target, $total === null ? null : (float) $total, $past) : self::KIND_DEMOTION;
        $entry = [
            'from_rank_id' => $current['id'] ?? null,
            'to_rank_id' => $target['id'] ?? null,
            'from_title' => $current['title'] ?? '',
            'to_title' => $target['title'] ?? '',
            'kind' => $kind,
            'points_at_change' => $total === null ? null : (float) $total,
            'reason' => Text::line($reason ?? '', 255),
            'changed_by' => $actor->id,
        ];
        $repo->change($userId, $entry, $moment);
        try {
            $this->app->pushTriggers()->rankChanged($userId, $entry);
        } catch (\Throwable $e) {
            error_log('Kompanie-App: Benachrichtigung zum Dienstgrad nicht eingereiht: ' . $e->getMessage());
        }
        return $this->status($userId);
    }

    /**
     * Datum eines Eintrags im Verlauf nachträglich ändern: nicht in der Zukunft, nicht vor dem vorigen und nicht nach dem
     * nächsten Eintrag dieser Person (Reihenfolge bleibt). Mit Punkten zählen die Punkte bis zum neuen Tag: für
     * points_at_change und, bei einer Beförderung, für die Schwelle des neuen Dienstgrads. Die Ersteinstufung behält ihren
     * Stand (Anfangsbestand).
     *
     * @return list<array<string, mixed>> Verlauf wie history()
     */
    public function changeDate(int $userId, int $entryId, mixed $date): array
    {
        $repo = $this->repository();
        $rows = $repo->history($userId);
        $index = null;
        foreach ($rows as $i => $h) {
            if ($h['id'] === $entryId) {
                $index = $i;
            }
        }
        if ($index === null) {
            throw ApiError::notFound(I18n::t('Eintrag im Verlauf nicht gefunden.'));
        }
        $entry = $rows[$index];
        $time = $this->app->time();
        $today = $time->today()->format('Y-m-d');
        if ($date === null || $date === '') {
            throw ApiError::invalid(I18n::t('Bitte ein Datum angeben.'));
        }
        $day = Input::date($date, $time->today());
        if ($day > $today) {
            throw ApiError::invalid(I18n::t('Das Datum darf nicht in der Zukunft liegen.'));
        }
        // Neueste zuerst: davor = der ältere Eintrag (Index + 1), danach = der neuere (Index - 1).
        $prevTs = isset($rows[$index + 1]) ? (int) strtotime($rows[$index + 1]['changed_at'] . ' UTC') : null;
        $nextTs = $index > 0 ? (int) strtotime($rows[$index - 1]['changed_at'] . ' UTC') : null;
        if ($prevTs !== null && $day < $time->format($prevTs, 'Y-m-d')) {
            throw ApiError::invalid(I18n::t('Der Wechsel davor war am {date}. Ein früheres Datum geht nicht, sonst stimmt die Reihenfolge im Verlauf nicht.', ['date' => $time->format($prevTs, 'd.m.Y')]));
        }
        if ($nextTs !== null && $day > $time->format($nextTs, 'Y-m-d')) {
            throw ApiError::invalid(I18n::t('Der Wechsel danach war am {date}. Ein späteres Datum geht nicht, sonst stimmt die Reihenfolge im Verlauf nicht.', ['date' => $time->format($nextTs, 'd.m.Y')]));
        }
        $ts = (new \DateTimeImmutable($day . ' 12:00:00', $time->timezone()))->getTimestamp();
        if ($day === $today) {
            $ts = min($ts, $time->timestamp());
        }
        if ($prevTs !== null && $ts <= $prevTs) {
            $ts = $prevTs + 1;
        }
        if ($nextTs !== null && $ts >= $nextTs) {
            $ts = $nextTs - 1;
        }
        $points = $entry['points_at_change'];
        if ($this->usesPoints() && $entry['kind'] !== self::KIND_INITIAL) {
            $points = $this->pointsOn($userId, $day);
            $target = $entry['to_rank_id'] === null ? null : $repo->find($entry['to_rank_id']);
            if ($entry['kind'] === self::KIND_PROMOTION && $target !== null && $target['min_points'] !== null && $points < $target['min_points']) {
                throw ApiError::invalid(I18n::t('Schwelle für „{rank}“ am {date} noch nicht erreicht (fehlen {missing} Punkte).', [
                    'rank' => $this->displayTitle($target['id'], $target['title']),
                    'date' => implode('.', array_reverse(explode('-', $day))),
                    'missing' => self::number($target['min_points'] - $points),
                ]));
            }
        }
        $repo->changeDate($userId, $entryId, gmdate('Y-m-d H:i:s', $ts), $points, $index === 0);
        return $this->history($userId);
    }

    /**
     * Einen Eintrag im Verlauf löschen (falsch gebucht). Beim neuesten Eintrag gilt wieder der Dienstgrad davor, als hätte
     * es den Wechsel nie gegeben: „Dienstgrad seit“ ist dann der Zeitpunkt des Eintrags davor. Ältere Einträge ändern den
     * aktuellen Dienstgrad nicht. Ohne Punkteprüfung (es wird nur rückgängig gemacht).
     *
     * @return array{rank: array<string, mixed>, history: list<array<string, mixed>>} neuer Stand wie status() und Verlauf
     */
    public function deleteHistoryEntry(int $userId, int $entryId): array
    {
        $user = $this->app->users->find($userId) ?? throw ApiError::notFound(I18n::t('Mitglied nicht gefunden.'));
        $repo = $this->repository();
        $rows = $repo->history($userId);
        $index = null;
        foreach ($rows as $i => $h) {
            if ($h['id'] === $entryId) {
                $index = $i;
            }
        }
        if ($index === null) {
            throw ApiError::notFound(I18n::t('Eintrag im Verlauf nicht gefunden.'));
        }
        $entry = $rows[$index];
        $restore = null;
        // Nur wenn der Eintrag den heutigen Stand ergeben hat (sonst passt der Verlauf ohnehin nicht zum Dienstgrad).
        $revert = $index === 0 && $repo->rankOf($userId) === $entry['to_rank_id'];
        if ($revert) {
            $label = $this->app->config->rankLabel();
            if ($entry['from_rank_id'] !== null) {
                if ($repo->find($entry['from_rank_id']) === null) {
                    throw ApiError::invalid(I18n::t('Den {rank} davor („{title}“) gibt es nicht mehr. Ändere den {rank} stattdessen.', ['rank' => $label, 'title' => $entry['from_title']]));
                }
                if (!$user->isMember()) {
                    throw ApiError::invalid(I18n::t('Einen {rank} haben nur Mitglieder.', ['rank' => $label]));
                }
            }
            $restore = ['rank_id' => $entry['from_rank_id'], 'since' => isset($rows[1]) ? $rows[1]['changed_at'] : $this->app->time()->nowUtc()];
        }
        $repo->deleteEntry($userId, $entryId, $restore);
        return ['rank' => $this->status($userId), 'history' => $this->history($userId)];
    }

    /**
     * Tag und Zeitpunkt (UTC) eines Wechsels. Leer oder heute = jetzt; ein früherer Tag = 12:00 Uhr Ortszeit, mindestens
     * eine Sekunde nach dem letzten Wechsel. Nicht in der Zukunft (der Dienstgrad gilt sofort) und nicht vor dem letzten
     * Wechsel der Person, damit der Verlauf in seiner Reihenfolge stimmt.
     *
     * @return array{0: string, 1: string} [JJJJ-MM-TT, 'Y-m-d H:i:s' in UTC]
     */
    private function changeMoment(int $userId, mixed $raw): array
    {
        $time = $this->app->time();
        $today = $time->today()->format('Y-m-d');
        $day = Input::date($raw, $time->today());
        if ($day > $today) {
            throw ApiError::invalid(I18n::t('Das Datum darf nicht in der Zukunft liegen.'));
        }
        $last = $this->repository()->lastChange($userId);
        $lastTs = $last === null ? null : strtotime($last . ' UTC');
        if ($lastTs !== null && $lastTs !== false && $day < $time->format($lastTs, 'Y-m-d')) {
            throw ApiError::invalid(I18n::t(
                'Der letzte Wechsel dieser Person war am {date}. Ein früheres Datum geht nicht, sonst stimmt die Reihenfolge im Verlauf nicht.',
                ['date' => $time->format($lastTs, 'd.m.Y')],
            ));
        }
        if ($day === $today) {
            return [$day, $time->nowUtc()];
        }
        $ts = (new \DateTimeImmutable($day . ' 12:00:00', $time->timezone()))->getTimestamp();
        if ($lastTs !== null && $lastTs !== false && $ts <= $lastTs) {
            $ts = $lastTs + 1;
        }
        return [$day, gmdate('Y-m-d H:i:s', $ts)];
    }

    /** Punkte bis einschließlich zu einem Tag. */
    private function pointsOn(int $userId, string $day): float
    {
        $sum = 0.0;
        foreach ($this->repository()->pointEntries($userId) as $e) {
            if ($e['entry_date'] <= $day) {
                $sum += $e['value'];
            }
        }
        return round($sum, 2);
    }

    /**
     * Anlegen mit Anfangspunkten und Dienstgrad: vor dem Anlegen des Kontos prüfen.
     *
     * @param array<string, mixed> $in initial_points, initial_points_date, rank_id
     * @return array{points: int, date: string, rank: Rank|null}
     */
    public function prepareInitial(User $actor, array $in): array
    {
        $raw = $in['initial_points'] ?? null;
        $points = $raw === null || $raw === '' ? 0 : (Input::integer($raw) ?? -1);
        if ($points < 0 || $points > ScoreService::MAX_POINTS) {
            throw ApiError::invalid(I18n::t('Anfangspunkte: bitte eine ganze Zahl ab 0 angeben.'));
        }
        if ($points > 0 && (!$this->app->features()->enabled('points') || !$actor->can(Capability::ManagePoints))) {
            throw ApiError::forbidden(I18n::t('Anfangspunkte bucht, wer {points} verwalten darf.', ['points' => $this->app->config->sectionLabel('points', 'Punkte')]));
        }
        $date = Input::date($in['initial_points_date'] ?? null, $this->app->time()->today());
        $rank = null;
        if (($in['rank_id'] ?? null) !== null && $in['rank_id'] !== '') {
            if (!$this->app->features()->enabled('ranks') || !$actor->can(Capability::ManageRanks)) {
                throw ApiError::forbidden(I18n::t('Den {rank} vergibt, wer {ranks} verwalten darf.', ['rank' => $this->app->config->rankLabel(), 'ranks' => $this->app->config->sectionLabel('ranks', 'Dienstgrade')]));
            }
            $rank = $this->target($this->repository()->all(false), $in['rank_id']);
            if ($rank !== null && $this->usesPoints() && $rank['min_points'] !== null && $points < $rank['min_points']) {
                throw ApiError::invalid(I18n::t('Für „{rank}“ sind mindestens {min} Punkte nötig.', ['rank' => $this->displayTitle($rank['id'], $rank['title']), 'min' => $rank['min_points']]));
            }
        }
        return ['points' => $points, 'date' => $date, 'rank' => $rank];
    }

    /** @param array{points: int, date: string, rank: Rank|null} $plan */
    public function applyInitial(User $actor, int $userId, array $plan): void
    {
        if ($plan['points'] > 0) {
            $this->app->scores()->givePoints(['user_ids' => [$userId], 'points' => $plan['points'], 'reason' => 'Anfangsbestand bei Übernahme', 'date' => $plan['date'], 'apply_factor' => false], $actor->id);
        }
        if ($plan['rank'] !== null) {
            $this->repository()->change($userId, [
                'from_rank_id' => null,
                'to_rank_id' => $plan['rank']['id'],
                'from_title' => '',
                'to_title' => $plan['rank']['title'],
                'kind' => self::KIND_INITIAL,
                'points_at_change' => $this->usesPoints() ? (float) $plan['points'] : null,
                'reason' => 'Ersteinstufung bei Anlage',
                'changed_by' => $actor->id,
            ], $this->app->time()->nowUtc());
        }
    }

    /* ---------------------------------------------------------------- */
    /* Regeln                                                            */
    /* ---------------------------------------------------------------- */

    /**
     * @param list<Rank> $ranks
     * @param Rank|null $current
     * @param Rank|null $target
     * @return string promotion | demotion
     */
    private function checkChange(array $ranks, ?array $current, ?array $target, ?float $total, ?string $pastDay = null): string
    {
        $promotion = $target !== null && ($current === null || $this->position($ranks, $target) > $this->position($ranks, $current));
        if ($total === null) {
            return $promotion ? self::KIND_PROMOTION : self::KIND_DEMOTION;
        }
        if ($promotion && $target['min_points'] !== null && $total < $target['min_points']) {
            $missing = self::number($target['min_points'] - $total);
            throw ApiError::invalid($pastDay === null
                ? I18n::t('Schwelle für „{rank}“ noch nicht erreicht (fehlen {missing} Punkte).', ['rank' => $this->displayTitle($target['id'], $target['title']), 'missing' => $missing])
                : I18n::t('Schwelle für „{rank}“ am {date} noch nicht erreicht (fehlen {missing} Punkte).', [
                    'rank' => $this->displayTitle($target['id'], $target['title']),
                    'date' => implode('.', array_reverse(explode('-', $pastDay))),
                    'missing' => $missing,
                ]));
        }
        return $promotion ? self::KIND_PROMOTION : self::KIND_DEMOTION;
    }

    /**
     * Nächster Dienstgrad, fehlende Punkte, erreichbarer Dienstgrad, „Schwelle erreicht seit“.
     *
     * @param list<Rank> $ranks
     * @param Rank|null $current
     * @param list<array{user_id: int, entry_date: string, value: float}> $entries Buchungen des Mitglieds in Zählreihenfolge
     * @return array<string, mixed>
     */
    private function progress(array $ranks, ?array $current, array $entries): array
    {
        $total = round(array_sum(array_column($entries, 'value')), 2);
        $above = null;
        $next = null;
        $reachable = null;
        foreach ($ranks as $r) {
            if (!$r['active'] || ($current !== null && $this->position($ranks, $r) <= $this->position($ranks, $current))) {
                continue;
            }
            $above ??= $r;
            // Nächster mit Schwelle: Dienstgrade ohne Schwelle (frei vergeben) überspringen, sonst verdeckten sie
            // eine anstehende Beförderung.
            if ($next === null && $r['min_points'] !== null) {
                $next = $r;
            }
        }
        foreach ($ranks as $r) {
            if ($r['active'] && $r['min_points'] !== null && $r['min_points'] <= $total) {
                $reachable = $r;
            }
        }
        $next ??= $above;
        $threshold = $next['min_points'] ?? null;
        $since = null;
        if ($threshold !== null) {
            // Je Tag zusammenfassen: Zählt die Summe am Ende des Tages, nicht die Reihenfolge der Buchungen eines Tages.
            $byDay = [];
            foreach ($entries as $e) {
                $byDay[$e['entry_date']] = ($byDay[$e['entry_date']] ?? 0.0) + $e['value'];
            }
            $sum = 0.0;
            foreach ($byDay as $day => $value) {
                $sum = round($sum + $value, 2);
                if ($sum >= $threshold) {
                    $since ??= (string) $day;
                } else {
                    $since = null;
                }
            }
        }
        $toNext = $threshold === null ? null : round($threshold - $total, 2);
        return [
            'points' => ScoreService::pointsValue($total),
            'next_rank' => $next === null ? null : ['id' => $next['id'], 'title' => $next['title'], 'min_points' => $next['min_points'], 'badge_url' => $this->badgeUrl($next)],
            'points_to_next' => $toNext === null ? null : ScoreService::pointsValue($toNext),
            'reachable_rank' => $this->ref($reachable),
            'threshold_reached_since' => $since,
            'below_current_threshold' => $current !== null && $current['min_points'] !== null && $total < $current['min_points'],
            'soon' => $next !== null && $toNext !== null && $toNext > 0 && $toNext <= self::SOON_POINTS,
        ];
    }

    /**
     * Gesetzte Schwellen aktiver Dienstgrade müssen mit der Reihenfolge streng steigen.
     *
     * @param list<Rank> $ranks
     * @param array{title: string, sort: int, min_points: int|null, active: bool}|null $changed geänderter bzw. neuer Eintrag
     */
    private function checkThresholds(array $ranks, ?array $changed): void
    {
        if ($changed !== null) {
            $ranks[] = $changed + ['id' => PHP_INT_MAX];
        }
        usort($ranks, static fn (array $a, array $b): int => [$a['sort'], $a['id']] <=> [$b['sort'], $b['id']]);
        $prev = null;
        foreach ($ranks as $r) {
            if (!$r['active'] || $r['min_points'] === null) {
                continue;
            }
            if ($prev !== null && $r['min_points'] <= $prev['min_points']) {
                throw ApiError::invalid(I18n::t('Die Punkteschwellen müssen mit der Reihenfolge steigen: „{rank}“ ({points}) steht über „{prev}“ ({prevPoints}).', [
                    'rank' => $this->displayTitle($r['id'], $r['title']),
                    'points' => (int) $r['min_points'],
                    'prev' => $this->displayTitle($prev['id'], $prev['title']),
                    'prevPoints' => (int) $prev['min_points'],
                ]));
            }
            $prev = $r;
        }
    }

    /**
     * Zuweisungen an Konten, die es nicht mehr gibt (z. B. bei WordPress gelöscht, während das Plugin aus war), entfernen:
     * Sie ließen sich nirgends lösen und blockierten das Löschen des Dienstgrads. Die Konten werden gemeinsam geprüft.
     *
     * @return array<int, int> die verbleibenden Zuweisungen, Benutzer-ID => Dienstgrad-ID
     */
    private function forgetUnknownUsers(): array
    {
        $assignments = $this->repository()->assignments();
        $known = $this->app->users->findMany(array_keys($assignments));
        foreach (array_keys($assignments) as $userId) {
            if (!isset($known[$userId])) {
                $this->app->db->execute('DELETE FROM {member_ranks} WHERE user_id = ?', [$userId]);
                unset($assignments[$userId]);
            }
        }
        return $assignments;
    }

    /**
     * @param list<Rank> $ranks
     * @return Rank|null
     */
    private function target(array $ranks, mixed $rankId): ?array
    {
        if ($rankId === null || $rankId === '' || $rankId === 0) {
            return null;
        }
        $rank = $this->byId($ranks, Input::integer($rankId));
        if ($rank === null || !$rank['active']) {
            throw ApiError::invalid(I18n::t('Unbekannter oder deaktivierter {rank}.', ['rank' => $this->app->config->rankLabel()]));
        }
        return $rank;
    }

    /**
     * @param list<Rank> $ranks
     * @return Rank|null
     */
    private function byId(array $ranks, ?int $id): ?array
    {
        foreach ($ranks as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }
        return null;
    }

    /**
     * @param list<Rank> $ranks nach Reihenfolge
     * @param Rank $rank
     */
    private function position(array $ranks, array $rank): int
    {
        foreach ($ranks as $i => $r) {
            if ($r['id'] === $rank['id']) {
                return $i;
            }
        }
        return -1;
    }

    private function validTitle(mixed $raw, ?int $id): string
    {
        $title = Text::line($raw ?? '', self::TITLE_MAX + 1);
        if ($title === '') {
            throw ApiError::invalid(I18n::t('Bitte eine Bezeichnung angeben.'));
        }
        if (mb_strlen($title) > self::TITLE_MAX) {
            throw ApiError::invalid(I18n::t('Die Bezeichnung darf höchstens {max} Zeichen lang sein.', ['max' => self::TITLE_MAX]));
        }
        foreach ($this->repository()->all(false) as $r) {
            if ($r['id'] !== $id && mb_strtolower($r['title']) === mb_strtolower($title)) {
                throw ApiError::invalid(I18n::t('Diese Bezeichnung gibt es schon.'));
            }
        }
        return $title;
    }

    private static function validThreshold(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $v = Input::integer($raw);
        if ($v === null || $v < 0 || $v > self::MAX_THRESHOLD) {
            throw ApiError::invalid(I18n::t('Die Punkteschwelle muss eine ganze Zahl ab 0 sein (oder leer).'));
        }
        return $v;
    }

    /**
     * @param Rank $r
     * @return array<string, mixed>
     */
    private function format(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'title' => (string) $r['title'],
            'sort' => (int) $r['sort'],
            'min_points' => $r['min_points'] === null ? null : (int) $r['min_points'],
            'active' => (bool) $r['active'],
            'badge_url' => $this->badgeUrl($r),
            'assigned' => $this->repository()->assignedCount((int) $r['id']),
        ] + $this->badgeInfo($r);
    }

    private static function number(float $v): string
    {
        $v = round($v, 2);
        return str_replace('.', ',', floor($v) === $v ? (string) (int) $v : (string) $v);
    }
}
