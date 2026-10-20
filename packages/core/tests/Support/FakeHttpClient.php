<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests\Support;

use KompanieApp\Core\Infra\SequentialHttp;
use KompanieApp\Core\Port\ExtendedHttpClient;

/**
 * Beantwortet Anfragen über eine Funktion; protokolliert alle Aufrufe.
 * Mehrere Anfragen (requestAll) nacheinander, je Aufruf in $batches vermerkt.
 */
final class FakeHttpClient implements ExtendedHttpClient
{
    use SequentialHttp {
        requestAll as private sequentialRequestAll;
    }

    /** @var array<int, array{method: string, url: string, headers: array, body: ?string}> */
    public array $calls = [];

    /** @var \Closure(string, string, array, ?string): array{status: int, body: string, headers?: array} */
    public \Closure $handler;

    /** @var array<int, string[]> Adressen je Aufruf von requestAll */
    public array $batches = [];

    public function __construct(?callable $handler = null)
    {
        $this->handler = \Closure::fromCallable($handler ?? static fn () => ['status' => 404, 'body' => '']);
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeoutSeconds = 15): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $res = ($this->handler)($method, $url, $headers, $body);
        return ['status' => $res['status'], 'headers' => $res['headers'] ?? [], 'body' => $res['body']];
    }

    public function requestAll(array $requests, int $timeoutSeconds = 15): array
    {
        $this->batches[] = array_values(array_column($requests, 'url'));
        return $this->sequentialRequestAll($requests, $timeoutSeconds);
    }
}
