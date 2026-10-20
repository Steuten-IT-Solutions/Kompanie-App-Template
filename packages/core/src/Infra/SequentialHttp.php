<?php

declare(strict_types=1);

namespace KompanieApp\Core\Infra;

/**
 * ExtendedHttpClient auf Basis von request(), ohne echte Gleichzeitigkeit: requestAll fragt
 * nacheinander, download schreibt die Antwort in die Datei. Für HttpClients, die nichts Besseres
 * können (z. B. Test-Doubles), damit sie dieselben Wege wie cURL nehmen.
 */
trait SequentialHttp
{
    /**
     * @param array<array-key, array{method: string, url: string, headers?: array<string, string>, body?: string|null}> $requests
     * @return array<array-key, array{status: int, headers: array<string, string>, body: string}|\RuntimeException>
     */
    public function requestAll(array $requests, int $timeoutSeconds = 15): array
    {
        $out = [];
        foreach ($requests as $k => $r) {
            try {
                $out[$k] = $this->request($r['method'], $r['url'], $r['headers'] ?? [], $r['body'] ?? null, $timeoutSeconds);
            } catch (\RuntimeException $e) {
                $out[$k] = $e;
            }
        }
        return $out;
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>}
     */
    public function download(string $url, string $file, array $headers = [], int $timeoutSeconds = 90): array
    {
        $res = $this->request('GET', $url, $headers, null, $timeoutSeconds);
        if (file_put_contents($file, $res['body']) === false) {
            throw new \RuntimeException('Die Datei ließ sich nicht speichern.');
        }
        return ['status' => $res['status'], 'headers' => $res['headers']];
    }
}
