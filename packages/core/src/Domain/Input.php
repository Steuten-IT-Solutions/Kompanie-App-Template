<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\Http\ApiError;

/** Gemeinsame Prüfungen für Eingaben. */
final class Input
{
    public static function isDate(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** Datum JJJJ-MM-TT; leer = heute. */
    public static function date(mixed $value, \DateTimeImmutable $today): string
    {
        if ($value === null || $value === '') {
            return $today->format('Y-m-d');
        }
        if (!self::isDate($value)) {
            throw ApiError::invalid('Ungültiges Datum.');
        }
        return $value;
    }

    /** Ganze Zahl (auch als Zeichenkette); null, wenn keine. */
    public static function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', trim($value))) {
            return (int) trim($value);
        }
        if (is_float($value) && floor($value) === $value && abs($value) < PHP_INT_MAX) {
            return (int) $value;
        }
        return null;
    }

    public static function bool(mixed $value): bool
    {
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }
        return (bool) $value;
    }

    /** http(s)-Adresse oder leer; sonst Fehler. */
    public static function url(mixed $value, string $message): string
    {
        $url = trim(is_string($value) ? $value : '');
        if ($url === '') {
            return '';
        }
        // Auch Umlaute in Adresse oder Pfad zulassen (z. B. https://bürger-verein.de/fest).
        $parts = parse_url($url);
        if ($parts === false) {
            throw ApiError::invalid($invalid);
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || ($parts['host'] ?? '') === '' || preg_match('/[\s"<>\x00-\x1F\x7F]/u', $url)) {
            throw ApiError::invalid($message);
        }
        if (mb_strlen($url) > 255) {
            throw ApiError::invalid('Der Link ist zu lang (höchstens 255 Zeichen).');
        }
        return $url;
    }

    /** Höchstlänge eines Links (Spalten VARCHAR(255)). */
    public const URL_MAX = 255;

    /**
     * Webadresse für einen Link nach außen (Sponsoren, Kompanien): nur https, leer bleibt leer. Ohne Schema wird
     * https:// ergänzt (beispiel.de/app → https://beispiel.de/app), wie das Feld in der App es beim Verlassen tut
     * (frontend/src/link.ts, dieselben Regeln). Der Rechner braucht eine Endung (beispiel.de, nicht beispiel), Umlaute
     * sind erlaubt. $field ist die Beschriftung des Felds für die Fehlermeldung.
     */
    public static function httpsUrl(mixed $value, string $field): string
    {
        $url = self::withScheme(trim(is_string($value) ? $value : ''));
        if ($url === '') {
            return '';
        }
        $invalid = sprintf('„%s“ ist keine gültige Webadresse. Beispiel: beispiel.de oder https://beispiel.de/seite', $field);
        if (preg_match('/[\s"<>\x00-\x1F\x7F]/u', $url)) {
            throw ApiError::invalid($invalid);
        }
        $parts = parse_url($url);
        if ($parts === false) {
            throw ApiError::invalid($invalid);
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme === 'http') {
            throw ApiError::invalid(sprintf('„%s“ muss mit https:// beginnen (sichere Verbindung), nicht mit http://.', $field));
        }
        if (
            $scheme !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || !self::isDomain((string) ($parts['host'] ?? ''))
        ) {
            throw ApiError::invalid($invalid);
        }
        if (mb_strlen($url) > self::URL_MAX) {
            throw ApiError::invalid(sprintf('„%s“ ist zu lang (höchstens %d Zeichen).', $field, self::URL_MAX));
        }
        return 'https' . substr($url, strlen($scheme));
    }

    /** https:// vor eine Adresse ohne Schema; „beispiel.de:8080“ ist eine Adresse mit Port, kein Schema. */
    private static function withScheme(string $url): string
    {
        if ($url === '' || preg_match('/^[a-z][a-z0-9+.-]*:(?!\d)/i', $url)) {
            return $url;
        }
        return 'https://' . ltrim($url, '/');
    }

    /** Rechnername mit Endung: Teile aus Buchstaben (auch Umlaute), Ziffern und Bindestrichen, Endung aus Buchstaben. */
    private static function isDomain(string $host): bool
    {
        if ($host === '' || mb_strlen($host) > 253) {
            return false;
        }
        $labels = explode('.', $host);
        if (count($labels) < 2) {
            return false;
        }
        foreach ($labels as $label) {
            if (mb_strlen($label) > 63 || preg_match('/^[\p{L}\p{N}](?:[\p{L}\p{N}-]*[\p{L}\p{N}])?$/u', $label) !== 1) {
                return false;
            }
        }
        return preg_match('/^(?:\p{L}{2,}|xn--[a-z0-9-]+)$/ui', $labels[count($labels) - 1]) === 1;
    }
}
