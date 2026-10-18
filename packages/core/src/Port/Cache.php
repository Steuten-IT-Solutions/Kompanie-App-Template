<?php

declare(strict_types=1);

namespace KompanieApp\Core\Port;

/** Zwischenspeicher mit Ablaufzeit. WordPress: Transients. */
interface Cache
{
    /** null, wenn nicht vorhanden oder abgelaufen. */
    public function get(string $key): mixed;

    public function set(string $key, mixed $value, int $ttlSeconds): void;

    public function delete(string $key): void;

    /**
     * Zählt einen Vorgang im aktuellen Zeitfenster atomar (auch bei gleichzeitigen Anfragen)
     * und gibt den neuen Stand zurück. Das Fenster beginnt alle $windowSeconds neu.
     */
    public function increment(string $key, int $windowSeconds): int;
}
