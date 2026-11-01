<?php

declare(strict_types=1);

namespace KompanieApp\Core\Port;

/**
 * Zusatz für HttpClient: GET, dessen Antwort stückweise weitergegeben wird, sobald sie ankommt (z. B. ein Video aus
 * Google Drive direkt an den Browser, ohne es erst ganz zu laden). Optional: Wer den HttpClient nutzt, prüft mit
 * instanceof und lädt sonst mit request(). Standalone: cURL. WordPress: cURL, sonst (Proxy, Filter, ohne cURL) über
 * die WordPress-HTTP-API in einem Stück (Infra\BufferedStream).
 */
interface StreamingHttpClient extends HttpClient
{
    /**
     * $onHead bekommt Status und Kopfzeilen (Namen klein), bevor Daten weitergegeben werden; false bricht ab, ohne
     * Daten weiterzugeben. $onData bekommt die Daten in Stücken; false bricht ab (z. B. der Browser hat aufgelegt).
     *
     * @param array<string, string> $headers
     * @param \Closure(int, array<string, string>): bool $onHead
     * @param \Closure(string): bool $onData
     * @return bool true, wenn die Antwort ganz weitergegeben wurde; false bei Abbruch oder Netzwerkfehler nach $onHead
     * @throws \RuntimeException bei Netzwerkfehlern, bevor $onHead aufgerufen wurde
     */
    public function stream(string $url, array $headers, \Closure $onHead, \Closure $onData, int $timeoutSeconds = 120): bool;
}
