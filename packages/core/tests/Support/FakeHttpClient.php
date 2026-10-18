<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests\Support;

use KompanieApp\Core\Port\HttpClient;

/** Beantwortet Anfragen über eine Funktion; protokolliert alle Aufrufe. */
final class FakeHttpClient implements HttpClient
{
    /** @var array<int, array{method: string, url: string, headers: array, body: ?string}> */
    public array $calls = [];

    /** @var \Closure(string, string, array, ?string): array{status: int, body: string, headers?: array} */
    public \Closure $handler;

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
}
