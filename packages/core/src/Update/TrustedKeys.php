<?php

declare(strict_types=1);

namespace KompanieApp\Core\Update;

/**
 * Öffentliche Schlüssel, mit denen Pakete und Update-Listen signiert sein dürfen.
 *
 * Den privaten Schlüssel gibt es nur als Secret KOMPANIE_SIGNING_KEY der Environment „veroeffentlichen“
 * im Kern-Repo (und im Passwort-Tresor der Organisation); erzeugt wird
 * das Paar mit `php scripts/keygen.php`. Für einen Schlüsselwechsel den neuen Schlüssel hier
 * ergänzen, mit einer Version ausliefern und erst danach mit dem neuen Schlüssel signieren.
 * Der alte Eintrag bleibt, bis alle Installationen die Version mit dem neuen haben.
 */
final class TrustedKeys
{
    /** @var array<string, string> keyId => öffentlicher Schlüssel (Base64) */
    public const KEYS = [
        // Erster Schlüssel der Organisation (erzeugt am 18.10.2026)
        'aebdd356' => 'T6zjtb7wIuorlpwev2Z1o7XPPf/1gorAr18W2LZGLeo=',
    ];

    /** @return array<string, string> */
    public static function all(): array
    {
        return self::KEYS;
    }
}
