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
    public const LOGO_MAX_BYTES = LogoImage::MAX_BYTES;

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
        $logo = LogoImage::fromUpload($this->app, $file);
        $this->repository()->saveLogo($id, $logo['type'], $logo['logo'], $logo['version'], $this->app->time()->nowUtc());
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
        if (!$this->app->features()->enabled(self::FEATURE)) {
            return LogoImage::deny(404);
        }
        if ($this->app->maintenance()->blocks($viewer)) {
            return LogoImage::deny(503);
        }
        if (!($viewer ?? $this->app->anonymous())->can(Capability::ViewSponsors)) {
            return LogoImage::deny($viewer === null ? 401 : 403);
        }
        $sponsor = $this->repository()->find($id);
        $body = $sponsor === null ? null : $this->repository()->logo($id);
        if ($sponsor === null || $body === null) {
            return LogoImage::deny(404);
        }
        $public = $this->app->anonymous()->can(Capability::ViewSponsors);
        return ['status' => 200, 'headers' => LogoImage::headers($this->app, $sponsor['logo_type'], $sponsor['logo_version'], $public, $version), 'body' => $body];
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
        return Input::httpsUrl($raw, 'Link');
    }
}
