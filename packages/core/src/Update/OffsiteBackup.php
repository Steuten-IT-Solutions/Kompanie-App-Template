<?php

declare(strict_types=1);

namespace KompanieApp\Core\Update;

/** Sicherung vor einem Update zusätzlich außerhalb des Servers ablegen (Domain\BackupUploadService: Google Drive). */
interface OffsiteBackup
{
    /** Ob hier eingerichtet und eingeschaltet. */
    public function active(): bool;

    /**
     * Die fertige Sicherung $path ablegen, ältere Sicherungen vor Updates aufräumen.
     *
     * @param (callable(int, int): void)|null $onProgress beim Hochladen: übertragene Bytes, Größe (Fortschrittsanzeige)
     * @throws \RuntimeException mit verständlicher Meldung
     */
    public function storeBeforeUpdate(string $path, string $version, ?callable $onProgress = null): void;
}
