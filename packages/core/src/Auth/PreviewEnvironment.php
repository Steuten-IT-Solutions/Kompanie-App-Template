<?php

declare(strict_types=1);

namespace KompanieApp\Core\Auth;

use KompanieApp\Core\Port\Environment;

/**
 * Environment des Adapters, dessen Adressen geschützter Dateien (Galeriebilder, Dokumente, Abzeichen) in der Vorschau
 * (Issue #63) den Parameter ?vorschau=… tragen: Der Browser lädt sie ohne den Header der App, ausgeliefert werden sie
 * trotzdem nur mit den Rechten der gewählten Rolle. Alles andere reicht sie unverändert durch.
 */
final class PreviewEnvironment implements Environment
{
    public function __construct(private readonly Environment $inner, private readonly Preview $preview)
    {
    }

    public function variant(): string
    {
        return $this->inner->variant();
    }

    public function version(): string
    {
        return $this->inner->version();
    }

    public function apiBase(): string
    {
        return $this->inner->apiBase();
    }

    public function appUrl(): string
    {
        return $this->inner->appUrl();
    }

    public function serviceWorkerUrl(): string
    {
        return $this->inner->serviceWorkerUrl();
    }

    public function googleRedirectUri(): string
    {
        return $this->inner->googleRedirectUri();
    }

    public function siteUrl(): ?string
    {
        return $this->inner->siteUrl();
    }

    public function photoUrl(int $id, string $size): string
    {
        return $this->preview->url($this->inner->photoUrl($id, $size));
    }

    public function documentUrl(int $id, string $version): string
    {
        return $this->preview->url($this->inner->documentUrl($id, $version));
    }

    public function badgeUrl(int $rankId, string $version, int $size): string
    {
        return $this->preview->url($this->inner->badgeUrl($rankId, $version, $size));
    }
}
