<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests\Support;

use KompanieApp\Core\Port\Environment;

final class FakeEnvironment implements Environment
{
    public function variant(): string
    {
        return 'test';
    }

    public function version(): string
    {
        return '0.0.0';
    }

    public function apiBase(): string
    {
        return 'https://example.org/api/v1/';
    }

    public function appUrl(): string
    {
        return 'https://example.org/app/';
    }

    public function serviceWorkerUrl(): string
    {
        return 'https://example.org/app/sw.js';
    }

    public function googleRedirectUri(): string
    {
        return 'https://example.org/app/google/callback';
    }

    public function siteUrl(): ?string
    {
        return null;
    }

    public function photoUrl(int $id, string $size): string
    {
        return 'https://example.org/app/foto/' . $id . '/' . $size;
    }

    public function documentUrl(int $id, string $version): string
    {
        return 'https://example.org/app/dokument/' . $id . '?v=' . $version;
    }

    public function badgeUrl(int $rankId, string $version, int $size): string
    {
        return 'https://example.org/app/abzeichen/' . $rankId . '?v=' . $version . '&s=' . $size;
    }

    public function memberPhotoUrl(int $userId, string $version, int $size): string
    {
        return 'https://example.org/app/mitgliedsfoto/' . $userId . '?v=' . $version . '&s=' . $size;
    }

    public function richImageUrl(string $uid): string
    {
        return 'https://example.org/app/bild/' . $uid;
    }

    public function attachmentUrl(string $uid): string
    {
        return 'https://example.org/app/anhang/' . $uid;
    }

    public function backupUrl(): string
    {
        return 'https://example.org/app/sicherung';
    }
}
