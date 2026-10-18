<?php

declare(strict_types=1);

namespace KompanieApp\Core\Port;

/** Aktuelle Zeit in der Zeitzone der Kompanie. In Tests austauschbar. */
interface Clock
{
    public function now(): \DateTimeImmutable;

    public function timezone(): \DateTimeZone;
}
