<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Storage\SponsorRepository;

/**
 * Sponsoren (Issue #279, Bereich sponsors): eine sortierbare Liste mit Name, Logo und Link, für alle mit view_sponsors
 * (anfangs auch Gast, also ohne Anmeldung). Pflegen darf, wer „Sponsoren verwalten“ (manage_sponsors) hat.
 *
 * - Name: Pflicht, zugleich Alternativtext des Logos.
 * - Link: optional, nur https; die App setzt rel="sponsored noopener noreferrer" (bezahlter Link).
 * - Logo: PNG, JPG, WebP oder SVG, höchstens 2 MB. Rasterbilder werden zu PNG mit höchstens 512 Pixeln (mit
 *   Transparenz), SVGs bereinigt (SvgSanitizer) und mit strenger CSP ausgeliefert. Es steht in der Datenbank.
 * - Reihenfolge per Ziehen (PUT /sponsors/order), neue kommen ans Ende.
 * Keine Stufen und keine Laufzeit (Entscheidung vom 05.11.2026).
 *
 * @phpstan-import-type Sponsor from SponsorRepository
 */
final class SponsorService
{
    public const FEATURE = 'sponsors';
    public const NAME_MAX = 100;
    public const LOGO_MAX_BYTES = 2 * 1024 * 1024;
    /** Längere Kante der PNG-Fassung: scharf auch in der Kachel auf Handys mit hoher Pixeldichte. */
    public const LOGO_EDGE = 512;
    /** Größte Kantenlänge eines hochgeladenen Rasterbilds (schützt den Arbeitsspeicher beim Verkleinern). */
    public const LOGO_MAX_EDGE = 4096;
    /** Endung => Signatur; bei WebP steht „WEBP“ zusätzlich ab Byte 8, SVG wird als XML geprüft. */
    public const LOGO_TYPES = ['png' => "\x89PNG\r\n\x1A\n", 'jpg' => "\xFF\xD8\xFF", 'jpeg' => "\xFF\xD8\xFF", 'webp' => 'RIFF', 'svg' => ''];

    private ?SponsorRepository $repo = null;

    public function __construct(private readonly App $app)
    {
    }

    public function repository(): SponsorRepository
    {
        return $this->repo ??= new SponsorRepository($this->app->db);
    }

    /**
     * Alle Sponsoren in ihrer Reihenfolge.
     *
     * @return list<array{id: int, name: string, url: string, logo_url: string|null, logo_type: string|null}>
     */
    public function list(): array
    {
        return array_map($this->format(...), $this->repository()->all());
    }

    /**
     * @param array<string, mixed> $in name, url
     * @return array{id: int, name: string, url: string, logo_url: string|null, logo_type: string|null}
     */
    public function create(array $in): array
    {
        $repo = $this->repository();
        $id = $repo->insert(self::validName($in['name'] ?? null), self::validUrl($in['url'] ?? null), $repo->maxSort() + 10, $this->app->time()->nowUtc());
        return $this->one($id);
    }

    /**
     * @param array<string, mixed> $in name, url (jeweils optional)
     * @return array{id: int, name: string, url: string, logo_url: string|null, logo_type: string|null}
     */
    public function update(int $id, array $in): array
    {
        $this->find($id);
        $data = [];
        if (array_key_exists('name', $in)) {
            $data['name'] = self::validName($in['name']);
        }
        if (array_key_exists('url', $in)) {
            $data['url'] = self::validUrl($in['url']);
        }
        $this->repository()->update($id, $data, $this->app->time()->nowUtc());
        return $this->one($id);
    }

    public function delete(int $id): void
    {
        $this->find($id);
        $this->repository()->delete($id);
    }

    /**
     * Neue Reihenfolge: alle IDs in der gewünschten Anzeigereihenfolge.
     *
     * @return list<array{id: int, name: string, url: string, logo_url: string|null, logo_type: string|null}>
     */
    public function reorder(mixed $ids): array
    {
        $repo = $this->repository();
        $sponsors = [];
        foreach ($repo->all() as $s) {
            $sponsors[$s['id']] = $s;
        }
        $order = is_array($ids) ? array_map(static fn (mixed $v): ?int => Input::integer($v), array_values($ids)) : [];
        $given = $order;
        sort($given);
        $known = array_keys($sponsors);
        sort($known);
        if ($given !== $known) {
            throw ApiError::invalid('Bitte die Reihenfolge aller Einträge angeben.');
        }
        $now = $this->app->time()->nowUtc();
        foreach ($order as $i => $id) {
            $sort = ($i + 1) * 10;
            if ($sponsors[(int) $id]['sort'] !== $sort) {
                $repo->update((int) $id, ['sort' => $sort], $now);
            }
        }
        return $this->list();
    }

    /**
     * Logo hochladen oder ersetzen.
     *
     * @param mixed $file {name, data (Base64, auch als data:-Adresse)}: PNG, JPG, WebP oder SVG
     * @return array{id: int, name: string, url: string, logo_url: string|null, logo_type: string|null}
     */
    public function uploadLogo(int $id, mixed $file): array
    {
        $this->find($id);
        $name = is_array($file) && is_string($file['name'] ?? null) ? $file['name'] : '';
        $bytes = self::decode(is_array($file) ? ($file['data'] ?? null) : null);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset(self::LOGO_TYPES[$ext])) {
            throw ApiError::invalid('Erlaubt sind PNG, JPG, WebP und SVG.');
        }
        if ($ext === 'svg') {
            try {
                $logo = SvgSanitizer::sanitize($bytes);
            } catch (\InvalidArgumentException $e) {
                throw ApiError::invalid($e->getMessage());
            }
            $type = 'svg';
        } else {
            $this->checkRaster($bytes, $ext);
            $logo = $this->toPng($bytes, $ext);
            $type = 'png';
        }
        $version = substr(hash('sha256', $logo . random_bytes(8)), 0, 16);
        $this->repository()->saveLogo($id, $type, $logo, $version, $this->app->time()->nowUtc());
        return $this->one($id);
    }

    /** @return array{id: int, name: string, url: string, logo_url: string|null, logo_type: string|null} */
    public function removeLogo(int $id): array
    {
        $this->find($id);
        $this->repository()->saveLogo($id, '', '', '', $this->app->time()->nowUtc());
        return $this->one($id);
    }

    /**
     * Für die Auslieferung über sponsorLogoUrl(): wer die Sponsoren sehen darf (ohne Anmeldung mit den Rechten von Gast).
     * Die Header setzt der Kern, damit beide Adapter gleich antworten.
     *
     * @return array{status: int, headers: array<string, string>, body?: string}
     */
    public function logoForDelivery(?User $viewer, int $id, mixed $version): array
    {
        $deny = static fn (int $status): array => ['status' => $status, 'headers' => ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']];
        if (!$this->app->features()->enabled(self::FEATURE)) {
            return $deny(404);
        }
        if ($this->app->maintenance()->blocks($viewer)) {
            return $deny(503);
        }
        if (!($viewer ?? $this->app->anonymous())->can(Capability::ViewSponsors)) {
            return $deny($viewer === null ? 401 : 403);
        }
        $sponsor = $this->repository()->find($id);
        $body = $sponsor === null ? null : $this->repository()->logo($id);
        if ($sponsor === null || $body === null) {
            return $deny(404);
        }
        // Sehen sie auch Besucher ohne Anmeldung, darf jeder Zwischenspeicher sie behalten. Die Adresse wechselt mit
        // jedem Logo: lange speichern; eine veraltete Fassung jedes Mal neu prüfen lassen; in der Vorschau nie speichern.
        $public = $this->app->anonymous()->can(Capability::ViewSponsors);
        $current = is_string($version) && hash_equals($sponsor['logo_version'], $version);
        $svg = $sponsor['logo_type'] === 'svg';
        $headers = [
            'Content-Type' => $svg ? 'image/svg+xml' : 'image/png',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => $this->app->preview()->cacheControl(($public ? 'public' : 'private') . ($current ? ', max-age=31536000, immutable' : ', no-cache')),
            'X-Robots-Tag' => 'noindex',
            'ETag' => '"' . $sponsor['logo_version'] . '"',
        ];
        if ($svg) {
            $headers['Content-Security-Policy'] = RankService::SVG_CSP;
        }
        return ['status' => 200, 'headers' => $headers, 'body' => $body];
    }

    /** @return Sponsor */
    private function find(int $id): array
    {
        return $this->repository()->find($id) ?? throw ApiError::notFound('Sponsor nicht gefunden.');
    }

    /** @return array{id: int, name: string, url: string, logo_url: string|null, logo_type: string|null} */
    private function one(int $id): array
    {
        return $this->format($this->find($id));
    }

    /**
     * @param Sponsor $s
     * @return array{id: int, name: string, url: string, logo_url: string|null, logo_type: string|null}
     */
    private function format(array $s): array
    {
        $logo = in_array($s['logo_type'], ['png', 'svg'], true) && $s['logo_version'] !== '';
        return [
            'id' => $s['id'],
            'name' => $s['name'],
            'url' => $s['url'],
            'logo_url' => $logo ? $this->app->env->sponsorLogoUrl($s['id'], $s['logo_version']) : null,
            'logo_type' => $logo ? $s['logo_type'] : null,
        ];
    }

    private static function validName(mixed $raw): string
    {
        $name = Text::line($raw);
        if ($name === '') {
            throw ApiError::invalid('Bitte den Namen des Sponsors angeben.');
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            throw ApiError::invalid(sprintf('Der Name darf höchstens %d Zeichen lang sein.', self::NAME_MAX));
        }
        return $name;
    }

    /** Leer oder eine https-Adresse. */
    private static function validUrl(mixed $raw): string
    {
        $message = 'Bitte einen Link angeben, der mit https:// beginnt, oder das Feld leer lassen.';
        $url = Input::url($raw, $message);
        if ($url !== '' && strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw ApiError::invalid($message);
        }
        return $url;
    }

    /** Base64 (auch als data:-Adresse) dekodieren und die Größe prüfen. */
    private static function decode(mixed $data): string
    {
        $data = is_string($data) ? $data : '';
        if (str_starts_with($data, 'data:') && ($comma = strpos($data, ',')) !== false) {
            $data = substr($data, $comma + 1);
        }
        // Vor dem Dekodieren: Base64 ist ein Drittel größer als die Datei.
        if (strlen($data) > intdiv(self::LOGO_MAX_BYTES * 4, 3) + 4) {
            throw ApiError::invalid('Das Logo darf höchstens 2 MB groß sein.');
        }
        $bytes = $data === '' ? false : base64_decode($data, true);
        if ($bytes === false || $bytes === '') {
            throw ApiError::invalid('Es ist keine Datei angekommen.');
        }
        if (strlen($bytes) > self::LOGO_MAX_BYTES) {
            throw ApiError::invalid('Das Logo darf höchstens 2 MB groß sein.');
        }
        return $bytes;
    }

    /** Rasterbild: Signatur passend zur Endung und eine vernünftige Größe in Pixeln. */
    private function checkRaster(string $bytes, string $ext): void
    {
        $signature = self::LOGO_TYPES[$ext];
        $ok = strncmp($bytes, $signature, strlen($signature)) === 0 && ($ext !== 'webp' || substr($bytes, 8, 4) === 'WEBP');
        $info = $ok ? @getimagesizefromstring($bytes) : false;
        if ($info === false) {
            throw ApiError::invalid(sprintf('Die Datei ist keine gültige .%s-Datei.', $ext));
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] > self::LOGO_MAX_EDGE || $info[1] > self::LOGO_MAX_EDGE) {
            throw ApiError::invalid(sprintf('Das Bild darf höchstens %d × %d Pixel groß sein.', self::LOGO_MAX_EDGE, self::LOGO_MAX_EDGE));
        }
    }

    /** PNG-Fassung mit höchstens LOGO_EDGE Pixeln (mit Transparenz, nie vergrößert). */
    private function toPng(string $bytes, string $ext): string
    {
        $tmp = $this->app->files->tempFile();
        // Mit Endung: Der Bildeditor von WordPress erkennt das Format daran.
        $source = $tmp . '.' . $ext;
        $target = $tmp . '-logo.png';
        try {
            if (@file_put_contents($source, $bytes) === false) {
                throw new ApiError(500, 'storage_failed', 'Das Logo konnte nicht gespeichert werden.');
            }
            try {
                $this->app->images->resizeToPng($source, $target, self::LOGO_EDGE);
            } catch (\RuntimeException $e) {
                throw ApiError::invalid('Das Bild kann nicht verarbeitet werden: ' . $e->getMessage());
            }
            $png = @file_get_contents($target);
            if ($png === false || $png === '') {
                throw new ApiError(500, 'storage_failed', 'Das Logo konnte nicht gespeichert werden.');
            }
            return $png;
        } finally {
            @unlink($source);
            @unlink($target);
            @unlink($tmp);
        }
    }
}
