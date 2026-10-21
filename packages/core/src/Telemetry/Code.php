<?php

declare(strict_types=1);

namespace KompanieApp\Core\Telemetry;

/**
 * Feste Fehlercodes für die Statusmeldungen (Issue #20). Gesendet werden nur diese Codes mit ihrer Ursache
 * (Detail), nie Fehlertexte: Die können Pfade, Namen oder E-Mail-Adressen enthalten und stehen nur im
 * Fehlerprotokoll der Installation.
 */
enum Code: string
{
    /** Ein Update ist gescheitert (die bisherige Version läuft weiter). */
    case UpdateFailed = 'UPDATE_FAILED';
    /** Zurückgerollt oder wegen einer zurückgezogenen Version gewechselt. */
    case Rollback = 'ROLLBACK';
    /** Die Update-Liste war nicht abrufbar oder ungültig. */
    case UpdateCheck = 'UPDATE_CHECK';
    /** Der Kalender ließ sich nicht abrufen. */
    case CalendarFetch = 'CALENDAR_FETCH';
    /** Der Galerie-Abgleich meldet einen Fehler. */
    case GallerySync = 'GALLERY_SYNC';
    /** Eine Hintergrundaufgabe ist mit einem unerwarteten Fehler abgebrochen. */
    case TaskFailed = 'TASK_FAILED';
    /** Eine Anfrage endete mit einem unerwarteten Fehler (HTTP 500). */
    case ServerError = 'SERVER_ERROR';
    /** Die Datenbank meldete einen Fehler. */
    case DbError = 'DB_ERROR';
    /** PHP ist in der App mit einem schweren Fehler abgebrochen. */
    case PhpFatal = 'PHP_FATAL';
    /** Wenig freier Speicherplatz auf dem Webspace. */
    case DiskLow = 'DISK_LOW';
    /** Eine Ampel der Statusseite ist auf Rot gesprungen. */
    case CheckError = 'CHECK_ERROR';

    /** Schwere Fehler werden sofort gemeldet (je Code höchstens einmal pro Stunde), die übrigen nur gezählt. */
    public function severe(): bool
    {
        return match ($this) {
            self::UpdateFailed, self::Rollback, self::DbError, self::PhpFatal, self::DiskLow, self::CheckError => true,
            default => false,
        };
    }
}
