<?php

declare(strict_types=1);

namespace KompanieApp\Standalone;

use KompanieApp\Core\Port\Environment;

/**
 * Adressen der eigenständigen Version. Mit URL-Umschreibung (.htaccess) schöne Pfade
 * wie api/v1/…, sonst index.php?r=/api/v1/… – das läuft auf jedem Server.
 */
final class StandaloneEnvironment implements Environment
{
    public function __construct(
        private readonly Config $config,
        private readonly HttpContext $http,
        private readonly string $version,
        private readonly ?string $siteUrl = null,
    ) {
    }

    public function variant(): string
    {
        return 'standalone';
    }

    public function version(): string
    {
        return $this->version;
    }

    public function appUrl(): string
    {
        return $this->config->baseUrl() ?: $this->http->baseUrl();
    }

    /** Adresse eines Pfads, der über index.php läuft, z. B. route('foto/3/thumb'). */
    public function route(string $path): string
    {
        $path = ltrim($path, '/');
        return $this->config->urlMode() === 'rewrite'
            ? $this->appUrl() . $path
            : $this->appUrl() . 'index.php?r=/' . $path;
    }

    public function apiBase(): string
    {
        return $this->route('api/v1/');
    }

    public function apiUrl(string $path): string
    {
        return $this->route('api/v1/' . ltrim($path, '/'));
    }

    /** Liegt als Datei neben index.php, damit sein Bereich die ganze App umfasst. */
    public function serviceWorkerUrl(): string
    {
        return $this->appUrl() . 'sw.js';
    }

    public function siteUrl(): ?string
    {
        return $this->siteUrl;
    }

    public function photoUrl(int $id, string $size): string
    {
        return $this->route('foto/' . $id . '/' . $size);
    }

    public function documentUrl(int $id, string $version): string
    {
        $url = $this->route('dokument/' . $id);
        return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . rawurlencode($version);
    }

    public function badgeUrl(int $rankId, string $version, int $size): string
    {
        $url = $this->route('abzeichen/' . $rankId);
        return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . rawurlencode($version) . '&s=' . $size;
    }
}
