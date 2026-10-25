<?php

declare(strict_types=1);

namespace KompanieApp\Core\Port;

/** Datensicherung im gemeinsamen Export-Format (siehe Storage\Backup). */
interface BackupProvider
{
    /** @return array<string, mixed> */
    public function export(): array;

    /**
     * Dieselbe Sicherung wie export(), Stück für Stück als JSON in $out geschrieben (Storage\Backup::write): braucht nur
     * wenig Arbeitsspeicher, auch bei großen Sicherungen (vor einem Update, Hochladen nach Google Drive).
     *
     * @param resource $out
     * @throws \RuntimeException|\JsonException
     */
    public function exportTo($out): void;

    /** Ersetzt alle Daten durch die Sicherung. @throws \RuntimeException mit verständlicher Meldung */
    public function import(string $json): void;

    /** Ob import() hier möglich ist (WordPress: Benutzer kommen aus WordPress, s. Adapter). */
    public function canImport(): bool;
}
