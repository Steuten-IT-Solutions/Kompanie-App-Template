<?php

declare(strict_types=1);

namespace KompanieApp\Core\Port;

/**
 * Zusatz für HttpClient: mehrere Anfragen gleichzeitig (z. B. mehrere Google-Kalender) und
 * Downloads direkt in eine Datei (große Fotos, ohne sie ganz im Arbeitsspeicher zu halten).
 * Optional: Wer den HttpClient nutzt, prüft mit instanceof und fragt sonst nacheinander bzw.
 * in den Speicher. Standalone: cURL. WordPress: nicht umgesetzt.
 */
interface ExtendedHttpClient extends HttpClient
{
    /**
     * @param array<array-key, array{method: string, url: string, headers?: array<string, string>, body?: string|null}> $requests
     * @return array<array-key, array{status: int, headers: array<string, string>, body: string}|\RuntimeException>
     *         je Anfrage (gleicher Schlüssel) die Antwort oder der Netzwerkfehler
     */
    public function requestAll(array $requests, int $timeoutSeconds = 15): array;

    /**
     * GET mit der Antwort direkt in $file; bei Netzwerkfehlern bleibt der Inhalt der Datei unbestimmt.
     *
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>}
     * @throws \RuntimeException bei Netzwerkfehlern
     */
    public function download(string $url, string $file, array $headers = [], int $timeoutSeconds = 90): array;
}
