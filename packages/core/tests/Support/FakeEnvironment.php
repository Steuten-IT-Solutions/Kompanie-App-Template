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

    public function siteUrl(): ?string
    {
        return null;
    }

    public function photoUrl(int $id, string $size): string
    {
        return 'https://example.org/app/foto/' . $id . '/' . $size;
    }
}
