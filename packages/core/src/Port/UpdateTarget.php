<?php

declare(strict_types=1);

namespace KompanieApp\Core\Port;

/**
 * Spielt ein geprüftes Paket ein. WordPress: Plugin-Upgrader. Standalone: Dateien tauschen.
 * Die Prüfung von Signatur und SHA-256 macht vorher der Kern (UpdateService).
 */
interface UpdateTarget
{
    /** 'wordpress' oder 'standalone': welches Paket aus der Update-Liste passt. */
    public function variant(): string;

    /**
     * Gründe, warum das Paket hier nicht eingespielt werden kann (z. B. keine Schreibrechte,
     * zu alte WordPress-Version), in Alltagssprache; leer, wenn alles passt.
     *
     * @param array<string, mixed> $entry Eintrag der Version aus der Update-Liste
     * @return string[]
     */
    public function problems(array $entry): array;

    /**
     * Ob automatische Updates hier erlaubt sind (WordPress: z. B. AUTOMATIC_UPDATER_DISABLED oder ein
     * Filter des Admins). Von Hand bleibt ein Update möglich, sofern problems() leer ist.
     */
    public function autoUpdateAllowed(): bool;

    /**
     * Spielt das Paket ein. Scheitert das mittendrin, muss der vorige Stand wiederhergestellt sein.
     *
     * @throws \RuntimeException mit verständlicher Meldung
     */
    public function install(string $zipFile, string $version): void;
}
