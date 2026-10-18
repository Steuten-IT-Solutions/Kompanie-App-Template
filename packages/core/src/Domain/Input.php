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
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw ApiError::invalid($message);
        }
        return $url;
    }
}
