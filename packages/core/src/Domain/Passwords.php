<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

/** Startpasswörter und Benutzernamen. */
final class Passwords
{
    public const MIN_LENGTH = 8;

    /**
     * Zufallspasswort ohne leicht verwechselbare Zeichen (0/O, 1/l/I),
     * weil es abgeschrieben bzw. persönlich weitergegeben wird.
     */
    public static function readable(int $length = 12): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $max = strlen($chars) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $chars[random_int(0, $max)];
        }
        return $out;
    }

    /** „Max Müller“ → „max.mueller“; nur a–z, 0–9 und Punkte. */
    public static function loginBase(string $name): string
    {
        $s = strtr(mb_strtolower($name, 'UTF-8'), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        if (function_exists('transliterator_transliterate')) {
            $s = (string) transliterator_transliterate('Any-Latin; Latin-ASCII', $s);
        } elseif (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            $s = $converted === false ? $s : $converted;
        }
        $s = (string) preg_replace('/[^a-z0-9]+/', '.', strtolower($s));
        $s = substr(trim($s, '.'), 0, 50);
        return $s === '' ? 'mitglied' : $s;
    }

    public static function validLogin(string $login): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9._@-]{1,60}$/', $login);
    }
}
