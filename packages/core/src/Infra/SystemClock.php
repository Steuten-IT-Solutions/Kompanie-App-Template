<?php

declare(strict_types=1);

namespace KompanieApp\Core\Infra;

use KompanieApp\Core\Port\Clock;

final class SystemClock implements Clock
{
    private readonly \DateTimeZone $tz;

    public function __construct(string|\DateTimeZone $timezone = 'Europe/Berlin')
    {
        $this->tz = $timezone instanceof \DateTimeZone ? $timezone : new \DateTimeZone($timezone);
    }

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', $this->tz);
    }

    public function timezone(): \DateTimeZone
    {
        return $this->tz;
    }
}
