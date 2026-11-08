<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain\Blocks;

use KompanieApp\Core\Domain\Text;
use KompanieApp\Core\Http\ApiError;

/** Gemeinsame Prüfungen für die Felder der Blöcke. */
final class Field
{
    /** Eine Zeile reiner Text, höchstens $max Zeichen (sonst 400 mit „$what darf höchstens …“). */
    public static function line(mixed $value, int $max, string $what): string
    {
        $s = Text::line($value, $max + 1);
        if (mb_strlen($s) > $max) {
            throw ApiError::invalid(sprintf('%s darf höchstens %d Zeichen lang sein.', $what, $max));
        }
        return $s;
    }

    /** Ganze Zahl von $min bis $max; fehlt sie: $default. */
    public static function int(mixed $value, int $min, int $max, int $default, string $what): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        $n = filter_var($value, FILTER_VALIDATE_INT);
        if ($n === false || $n < $min || $n > $max) {
            throw ApiError::invalid(sprintf('%s: eine Zahl von %d bis %d.', $what, $min, $max));
        }
        return $n;
    }

    /**
     * Ziel eines Links: https://, mailto: oder tel: (ohne Leerzeichen gespeichert; wie Links im Text) oder eine Seite der App (#/…); sonst 400.
     */
    public static function link(mixed $value): string
    {
        $url = is_string($value) ? trim($value) : '';
        if ($url === '') {
            throw ApiError::invalid('Bitte ein Ziel für den Knopf angeben.');
        }
        if (preg_match('~^tel:~i', $url) === 1) {
            $url = 'tel:' . preg_replace('/\s+/', '', substr($url, 4));
        }
        if (strlen($url) > 2000 || preg_match('/[\s<>"]/', $url) === 1) {
            throw ApiError::invalid('Ungültiges Ziel für den Knopf.');
        }
        $ok = preg_match('~^https://[^/\s]+~i', $url) === 1
            || preg_match('~^mailto:[^@\s]+@[^@\s]+$~i', $url) === 1
            || preg_match('~^tel:\+?[0-9()/-]{3,}$~', $url) === 1
            || preg_match('~^#/[A-Za-z0-9/_\-.?=&%]*$~', $url) === 1;
        if (!$ok) {
            throw ApiError::invalid('Das Ziel des Knopfs muss mit https://, mailto: oder tel: beginnen oder eine Seite der App sein (#/…).');
        }
        return $url;
    }
}
