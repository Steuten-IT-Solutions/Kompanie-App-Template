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

    /**
     * Adresse der App bzw. eines Unterpfads. Mit einfachen Permalinks (ohne URL-Umschreibung)
     * über ?kompanie_app=…, sonst /app/….
     */
    public function appUrl(string $path = ''): string
    {
        if (!self::prettyPermalinks()) {
            return add_query_arg(AppPage::QUERY_VAR, $path === '' ? 'index' : $path, home_url('/'));
        }
        return home_url('/app/' . $path);
    }

    public static function prettyPermalinks(): bool
    {
        return (string) get_option('permalink_structure') !== '';
    }

    public function serviceWorkerUrl(): string
    {
        return $this->appUrl('sw.js');
    }

    public function siteUrl(): ?string
    {
        return home_url('/');
    }

    public function photoUrl(int $id, string $size): string
    {
        return $this->appUrl('foto/' . $id . '/' . $size);
    }

    public function documentUrl(int $id, string $version): string
    {
        $url = $this->appUrl('dokument/' . $id);
        return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . rawurlencode($version);
    }

    public function badgeUrl(int $rankId, string $version, int $size): string
    {
        $url = $this->appUrl('abzeichen/' . $rankId);
        return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . rawurlencode($version) . '&s=' . $size;
    }
}
