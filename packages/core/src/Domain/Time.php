<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\Port\Clock;

/** Zeitangaben für die Datenbank (UTC) und für die Kompanie (Ortszeit). */
final class Time
{
    public function __construct(private readonly Clock $clock)
    {
    }

    /** Jetzt in UTC als 'Y-m-d H:i:s' für die Datenbank. */
    public function nowUtc(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** Heute in der Zeitzone der Kompanie. */
    public function today(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone($this->clock->timezone())->setTime(0, 0);
    }

    public function timezone(): \DateTimeZone
    {
        return $this->clock->timezone();
    }

    public function timestamp(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    /** UTC-Datenbankzeit als ISO 8601. */
    public static function utcToIso(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format('c');
    }
}
