<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;

/**
 * Wem eine Angabe auf der Seite einer Person in der Mitgliederliste (Bereich directory) erscheint: nicht (none), nur
 * angemeldeten Mitgliedern (members, Recht member wie bei den Geburtstagen) oder allen (public, auch ohne Anmeldung).
 * Stellt nur die Person selbst ein; Standard: nicht zeigen.
 */
final class ProfileVisibility
{
    public const NONE = 'none';
    public const MEMBERS = 'members';
    public const PUBLIC = 'public';
    public const ALL = [self::NONE, self::MEMBERS, self::PUBLIC];

    /** Eingabe prüfen. */
    public static function parse(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::ALL, true)) {
            throw ApiError::invalid('Ungültige Auswahl für die Anzeige (none, members oder public erwartet).');
        }
        return $value;
    }

    /** Gespeicherter Wert; Unbekanntes gilt als nicht zeigen. */
    public static function stored(mixed $value): string
    {
        return is_string($value) && in_array($value, self::ALL, true) ? $value : self::NONE;
    }

    /** @return list<string> die Stufen, die $viewer sieht (ohne Anmeldung oder ohne Recht member: nur public) */
    public static function shownTo(?User $viewer): array
    {
        return $viewer !== null && $viewer->isMember() ? [self::MEMBERS, self::PUBLIC] : [self::PUBLIC];
    }
}
