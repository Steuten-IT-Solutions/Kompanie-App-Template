<?php

declare(strict_types=1);

namespace KompanieApp\WordPress;

use KompanieApp\Core\Port\Environment;

final class WpEnvironment implements Environment
{
    public const NAMESPACE = 'kompanie-app/v1';

    public function variant(): string
    {
        return 'wordpress';
    }

    public function version(): string
    {
        return KOMPANIE_APP_VERSION;
    }

    public function apiBase(): string
    {
        return rest_url(self::NAMESPACE . '/');
    }

    public function appUrl(): string
    {
        return home_url('/app/');
    }

    public function siteUrl(): ?string
    {
        return home_url('/');
    }

    public function photoUrl(int $id, string $size): string
    {
        return $this->appUrl() . 'foto/' . $id . '/' . $size;
    }
}
