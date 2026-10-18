<?php

declare(strict_types=1);

namespace KompanieApp\Core\Update;

/**
 * Öffentliche Schlüssel, mit denen Pakete und Update-Listen signiert sein dürfen.
 *
 * Den privaten Schlüssel hat nur die Organisation (Secret KOMPANIE_SIGNING_KEY); erzeugt wird
 * das Paar mit `php scripts/keygen.php`. Für einen Schlüsselwechsel den neuen Schlüssel hier
 * ergänzen, mit einer Version ausliefern und erst danach mit dem neuen Schlüssel signieren.
 * Der alte Eintrag bleibt, bis alle Installationen die Version mit dem neuen haben.
 */
final class TrustedKeys
{
    /** @var array<string, string> keyId => öffentlicher Schlüssel (Base64) */
    public const KEYS = [
        // Noch kein Schlüssel hinterlegt: siehe docs/RELEASE.md.
    ];

    /** @return array<string, string> */
    public static function all(): array
    {
        return self::KEYS;
    }
}
