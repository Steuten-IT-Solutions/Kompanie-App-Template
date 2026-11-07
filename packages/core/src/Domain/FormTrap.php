<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\Http\ApiError;

/**
 * Schutz öffentlicher Formulare vor Programmen, ohne Captcha (Fehlermeldungen, Issue #64; Selbstregistrierung, Issue #314):
 *
 * - Zeitfalle: Beim Öffnen gibt der Server eine signierte Zeitmarke aus; abgeschickt werden darf frühestens nach
 *   $minSeconds und höchstens $maxAge Sekunden später. Die Signatur gilt nur für den Zweck ($purpose), für den sie
 *   ausgegeben wurde.
 * - Honeypot: ein Feld „website“, das Menschen nicht sehen. Ist es ausgefüllt, antworten die Formulare wie bei Erfolg,
 *   damit Programme nichts lernen.
 */
final class FormTrap
{
    public function __construct(
        private readonly string $secret,
        private readonly string $purpose,
        private readonly int $minSeconds,
        private readonly int $maxAge,
    ) {
    }

    public function token(int $now): string
    {
        return $now . '.' . $this->signature($now);
    }

    /** Zeitmarke aus token(): nicht zu frisch, nicht zu alt, richtig signiert. */
    public function check(mixed $token, int $now): void
    {
        if (!is_string($token) || !preg_match('/^(\d{1,12})\.([0-9a-f]{64})$/D', $token, $m) || !hash_equals($this->signature((int) $m[1]), $m[2])
            || $now - (int) $m[1] > $this->maxAge || (int) $m[1] > $now + 60) {
            throw new ApiError(400, 'form_expired', 'Das Formular ist abgelaufen. Bitte öffne es neu.');
        }
        if ($now - (int) $m[1] < $this->minSeconds) {
            throw new ApiError(429, 'too_fast', 'Das ging sehr schnell. Bitte warte einen Moment und sende dann erneut.');
        }
    }

    /** Ist das versteckte Feld ausgefüllt (dann war es vermutlich ein Programm)? */
    public static function honeypot(array $in): bool
    {
        return trim(is_string($in['website'] ?? null) ? $in['website'] : '') !== '';
    }

    private function signature(int $at): string
    {
        return hash_hmac('sha256', $this->purpose . '|' . $at, $this->secret);
    }
}
