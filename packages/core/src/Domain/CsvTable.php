<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\Http\ApiError;

/**
 * Eingefügte oder hochgeladene Tabellen (Importe): CSV mit Semikolon, Tabulator (aus einer Tabellenkalkulation kopiert
 * oder von der App aus einer Excel-Datei gelesen) oder Komma. Eine Zeile je Datensatz; Zeilenumbrüche in Zellen gibt es nicht.
 */
final class CsvTable
{
    /**
     * Text prüfen und in Zeilen teilen: nicht leer, höchstens $maxBytes, UTF-8 (sonst Windows-1252, so speichert Excel
     * unter Windows „CSV (Trennzeichen-getrennt)“), ohne BOM.
     *
     * @return list<string>
     */
    public static function lines(mixed $csv, int $maxBytes): array
    {
        if (!is_string($csv) || trim($csv) === '') {
            throw ApiError::invalid('Bitte die Tabelle einfügen oder eine Datei wählen.');
        }
        if (strlen($csv) > $maxBytes) {
            throw ApiError::invalid(sprintf('Die Tabelle ist zu groß (höchstens %d KB).', intdiv($maxBytes, 1000)));
        }
        if (!mb_check_encoding($csv, 'UTF-8')) {
            $csv = (string) mb_convert_encoding($csv, 'UTF-8', 'Windows-1252');
        }
        $csv = (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        return preg_split('/\r\n|\r|\n/', $csv) ?: [];
    }

    /** Trennzeichen einer Zeile (z. B. der Kopfzeile): Semikolon, sonst Tabulator, sonst Komma. */
    public static function separator(string $line): string
    {
        return str_contains($line, ';') ? ';' : (str_contains($line, "\t") || !str_contains($line, ',') ? "\t" : ',');
    }

    /**
     * Zellen einer Zeile, ohne Leerraum am Rand.
     *
     * @return list<string>
     */
    public static function cells(string $line, string $separator): array
    {
        return array_map('trim', str_getcsv($line, $separator, '"', ''));
    }

    /** Ob die Zeile nur aus Trennzeichen und Leerraum besteht. */
    public static function blank(string $line): bool
    {
        return trim(str_replace([';', "\t", ','], '', $line)) === '';
    }
}
