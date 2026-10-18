<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests\Support;

use KompanieApp\Core\Port\Clock;

/** Uhr, die auf echter Zeit basiert, aber in Tests verschoben werden kann. */
final class FixedClock implements Clock
{
    private \DateTimeZone $tz;

    public function __construct(public ?\DateTimeImmutable $at = null, string $timezone = 'Europe/Berlin')
    {
        $this->tz = new \DateTimeZone($timezone);
    }

    public function now(): \DateTimeImmutable
    {
        return ($this->at ?? new \DateTimeImmutable('now'))->setTimezone($this->tz);
    }

    public function timezone(): \DateTimeZone
    {
        return $this->tz;
    }
}
