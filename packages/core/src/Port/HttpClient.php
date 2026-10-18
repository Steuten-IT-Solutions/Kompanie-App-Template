<?php

declare(strict_types=1);

namespace KompanieApp\Core\Port;

/** Ausgehende HTTP-Anfragen (Google, iCal, News-Quellen). WordPress: wp_remote_*. Standalone: cURL. */
interface HttpClient
{
    /**
     * @param array<string, string> $headers
     * @param string|null $body Rohdaten; bei Formularen bereits kodiert
     * @return array{status: int, headers: array<string, string>, body: string}
     * @throws \RuntimeException bei Netzwerkfehlern
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeoutSeconds = 15): array;
}
