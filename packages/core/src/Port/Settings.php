<?php

declare(strict_types=1);

namespace KompanieApp\Core\Port;

/** Einstellungen der Installation. WordPress: Options. Standalone: Tabelle settings. */
interface Settings
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;
}
