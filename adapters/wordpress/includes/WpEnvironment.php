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

    /**
     * admin-post.php ohne Parameter (Google vergleicht die Adresse genau): Dort ist der Admin über das Anmelde-Cookie
     * von WordPress angemeldet, anders als bei der REST-API, die ohne Nonce niemanden als angemeldet behandelt.
     * Dieselbe Adresse wie in der früheren App einer Kompanie; ein übernommener OAuth-Client passt so weiter.
     * Rückkehrer erkennt SettingsPage::googleCallback am state (DriveClient::STATE_PREFIX).
     */
    public function googleRedirectUri(): string
    {
        return admin_url('admin-post.php');
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

    public function memberPhotoUrl(int $userId, string $version, int $size): string
    {
        $url = $this->appUrl('mitgliedsfoto/' . $userId);
        return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . rawurlencode($version) . '&s=' . $size;
    }

    public function richImageUrl(string $uid): string
    {
        return $this->appUrl('bild/' . $uid);
    }

    public function attachmentUrl(string $uid): string
    {
        return $this->appUrl('anhang/' . $uid);
    }
}
