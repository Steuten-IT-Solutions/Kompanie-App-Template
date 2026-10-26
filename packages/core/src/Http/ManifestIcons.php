<?php

declare(strict_types=1);

namespace KompanieApp\Core\Http;

/**
 * Icons im Web-App-Manifest. Jede Adresse trägt ?v=<Prüfsumme des Inhalts>: Chrome übernimmt bei einer installierten App
 * neue Icons (Startbildschirm, Icon auf dem Handy) nur, wenn sich der Eintrag icons im Manifest ändert, nicht schon, wenn
 * unter derselben Adresse ein anderes Bild liegt (seit Chrome 144). Unveränderte Icons behalten ihre Adresse, damit
 * nicht jedes Update eine Änderung meldet.
 */
final class ManifestIcons
{
    private const ICONS = [
        ['icon-192.png', '192x192', null],
        ['icon-512.png', '512x512', null],
        ['icon-maskable-512.png', '512x512', 'maskable'],
    ];

    /** @return list<array<string, string>> */
    public static function entries(string $baseUrl, string $dir): array
    {
        $out = [];
        foreach (self::ICONS as [$file, $sizes, $purpose]) {
            $hash = @hash_file('sha256', $dir . '/' . $file);
            $icon = ['src' => $baseUrl . $file . ($hash !== false ? '?v=' . substr($hash, 0, 12) : ''), 'sizes' => $sizes, 'type' => 'image/png'];
            if ($purpose !== null) {
                $icon['purpose'] = $purpose;
            }
            $out[] = $icon;
        }
        return $out;
    }
}
