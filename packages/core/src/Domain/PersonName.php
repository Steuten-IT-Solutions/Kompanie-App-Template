<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

/**
 * Vor- und Nachname. Der Anzeigename ist „Vorname Nachname“; der Nachname darf fehlen (z. B. Funktionskonten).
 * Konten aus der Zeit mit nur einem Namensfeld werden geteilt: das letzte Wort ist der Nachname.
 */
final class PersonName
{
    /** Länge des Anzeigenamens (Spalte name der eigenständigen Version). */
    public const MAX = 100;

    /**
     * „Hans Peter Schulz“ → [Hans Peter, Schulz]; ein Wort ist der Vorname.
     *
     * @return array{0: string, 1: string} Vorname, Nachname
     */
    public static function split(string $name): array
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        $pos = mb_strrpos($name, ' ');
        return $pos === false ? [$name, ''] : [mb_substr($name, 0, $pos), mb_substr($name, $pos + 1)];
    }

    public static function join(string $first, string $last): string
    {
        return trim($first . ' ' . $last);
    }
}
