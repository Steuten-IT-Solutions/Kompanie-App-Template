<?php

declare(strict_types=1);

namespace KompanieApp\Core\Port;

/** Einstellungen der Installation. WordPress: Options. Standalone: Tabelle settings. */
interface Settings
{
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Wie get(), aber am Zwischenspeicher der Anfrage vorbei: für Werte, die andere Anfragen gleichzeitig
     * ändern können (z. B. die Warteschlange der Statusmeldungen).
     */
    public function fresh(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function delete(string $key): void;
}
