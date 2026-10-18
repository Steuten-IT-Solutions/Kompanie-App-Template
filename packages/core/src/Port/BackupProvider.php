<?php

declare(strict_types=1);

namespace KompanieApp\Core\Port;

/** Datensicherung im gemeinsamen Export-Format (siehe Storage\Backup). */
interface BackupProvider
{
    /** @return array<string, mixed> */
    public function export(): array;

    /** Ersetzt alle Daten durch die Sicherung. @throws \RuntimeException mit verständlicher Meldung */
    public function import(string $json): void;

    /** Ob import() hier möglich ist (WordPress: Benutzer kommen aus WordPress, s. Adapter). */
    public function canImport(): bool;
}
