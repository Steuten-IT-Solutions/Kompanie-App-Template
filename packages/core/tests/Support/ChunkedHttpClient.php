<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests\Support;

use KompanieApp\Core\Port\HttpClient;
use KompanieApp\Core\Port\StreamingHttpClient;

/** HttpClient, der stream() kann: gibt die Antwort des inneren Clients in Stücken weiter und zählt sie. */
final class ChunkedHttpClient implements StreamingHttpClient
{
    /** Weitergegebene Stücke (über alle Aufrufe von stream) */
    public int $chunks = 0;

    public function __construct(private readonly HttpClient $inner, private readonly int $chunkSize = 1000)
    {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeoutSeconds = 15): array
    {
        return $this->inner->request($method, $url, $headers, $body, $timeoutSeconds);
    }

    public function stream(string $url, array $headers, \Closure $onHead, \Closure $onData, int $timeoutSeconds = 120): bool
    {
        $res = $this->inner->request('GET', $url, $headers, null, $timeoutSeconds);
        if (!$onHead($res['status'], $res['headers'])) {
            return false;
        }
        foreach ($res['body'] === '' ? [] : str_split($res['body'], $this->chunkSize) as $chunk) {
            $this->chunks++;
            if (!$onData($chunk)) {
                return false;
            }
        }
        return true;
    }
}
