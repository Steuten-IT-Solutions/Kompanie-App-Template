<?php

declare(strict_types=1);

namespace KompanieApp\Core\Update;

/**
 * Versionen der Kompanie-Pakete: Kern-Version (SemVer) plus Build-Nummer, z. B. 1.4.0.3.
 * Die Hauptversion (erste Zahl) entscheidet, ob ein Update automatisch kommt.
 */
final class Version
{
    public static function valid(string $v): bool
    {
        return (bool) preg_match('/^\d{1,4}\.\d{1,4}\.\d{1,4}(\.\d{1,6})?$/', $v);
    }

    /** -1, 0 oder 1 wie version_compare, aber nur für gültige Versionen. */
    public static function compare(string $a, string $b): int
    {
        if (!self::valid($a) || !self::valid($b)) {
            throw new \InvalidArgumentException("Ungültige Version: $a / $b");
        }
        $pa = array_map('intval', explode('.', $a)) + [0, 0, 0, 0];
        $pb = array_map('intval', explode('.', $b)) + [0, 0, 0, 0];
        for ($i = 0; $i < 4; $i++) {
            if ($pa[$i] !== $pb[$i]) {
                return $pa[$i] <=> $pb[$i];
            }
        }
        return 0;
    }

    public static function major(string $v): int
    {
        return (int) explode('.', $v)[0];
    }
}
