<?php

declare(strict_types=1);

namespace KompanieApp\Core\Port;

/** Zusätzliche Prüfungen des Adapters für die Statusseite (z. B. ob Daten von außen erreichbar sind). */
interface HealthChecks
{
    /**
     * @return array<int, array{key: string, label: string, level: string, text: string}>
     *         level: ok, warn, error oder off
     */
    public function checks(): array;
}
