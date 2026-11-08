<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain\Blocks;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Domain\SponsorService;

/**
 * Sponsoren: alle Sponsoren aus dem Bereich Sponsoren in ihrer Reihenfolge, mit Logo und Link. Nur für alle, die die
 * Sponsoren sehen dürfen (view_sponsors, ohne Anmeldung mit den Rechten von Gast); ohne Bereich Sponsoren, für andere
 * und ohne Sponsoren fällt der Block weg (die Pflege sieht ihn mit leerer Liste).
 */
final class SponsorsBlock implements BlockType
{
    public const TYPE = 'sponsors';
    public const TITLE_MAX = 100;

    public function __construct(private readonly App $app)
    {
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function validate(array $in): array
    {
        return ['title' => Field::line($in['title'] ?? '', self::TITLE_MAX, 'Die Überschrift der Sponsoren')];
    }

    public function render(array $data, ?User $viewer, bool $manager): ?array
    {
        $sponsors = $this->sponsors($viewer);
        if ($sponsors === [] && !$manager) {
            return null;
        }
        return ['title' => (string) ($data['title'] ?? ''), 'sponsors' => $sponsors];
    }

    /** Überschrift und je Sponsor eine Zeile „Name – Link“. */
    public function plain(array $data, ?User $viewer = null): string
    {
        $lines = array_map(static fn (array $s): string => $s['name'] . ($s['url'] !== '' ? ' – ' . $s['url'] : ''), $this->sponsors($viewer));
        if ($lines === []) {
            return '';
        }
        $title = (string) ($data['title'] ?? '');
        return ($title !== '' ? $title . "\n" : '') . implode("\n", $lines);
    }

    /** @return list<array{id: int, name: string, url: string, logo_url: string|null, logo_type: string|null}> */
    private function sponsors(?User $viewer): array
    {
        if (!$this->app->features()->enabled(SponsorService::FEATURE) || !($viewer ?? $this->app->anonymous())->can(Capability::ViewSponsors)) {
            return [];
        }
        return $this->app->sponsors()->list();
    }
}
