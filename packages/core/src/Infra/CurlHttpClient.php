<?php

declare(strict_types=1);

namespace KompanieApp\Core\Infra;

use KompanieApp\Core\Port\ExtendedHttpClient;

/**
 * Ausgehende Anfragen über cURL oder, falls nicht vorhanden, über PHP-Streams.
 * Mit cURL: komprimierte Antworten (gzip), mehrere Anfragen gleichzeitig, Downloads in eine Datei.
 */
final class CurlHttpClient implements ExtendedHttpClient
{
    /** @param int $maxBytes größte angenommene Antwort (Schutz des Arbeitsspeichers), Standard 64 MB */
    public function __construct(private readonly string $userAgent = 'Kompanie-App', private readonly int $maxBytes = 64 * 1024 * 1024)
    {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeoutSeconds = 15): array
    {
        self::checkUrl($url);
        $lines = self::lines($headers);
        return function_exists('curl_init')
            ? $this->curl($method, $url, $lines, $body, $timeoutSeconds)
            : $this->stream($method, $url, $lines, $body, $timeoutSeconds);
    }

    public function requestAll(array $requests, int $timeoutSeconds = 15): array
    {
        if (!function_exists('curl_multi_init') || count($requests) < 2) {
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
        $multi = curl_multi_init();
        $handles = [];
        $out = [];
        foreach ($requests as $k => $r) {
            try {
                self::checkUrl($r['url']);
            } catch (\RuntimeException $e) {
                $out[$k] = $e;
                continue;
            }
            $state = new \stdClass();
            $ch = $this->handle($r['method'], $r['url'], self::lines($r['headers'] ?? []), $r['body'] ?? null, $timeoutSeconds, $state);
            curl_multi_add_handle($multi, $ch);
            $handles[$k] = [$ch, $state];
        }
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running > 0 && curl_multi_select($multi, 1.0) === -1) {
                usleep(10000);
            }
        } while ($running > 0 && $status === CURLM_OK);
        $codes = [];
        while (($info = curl_multi_info_read($multi)) !== false) {
            $codes[spl_object_id($info['handle'])] = (int) $info['result'];
        }
        foreach ($handles as $k => [$ch, $state]) {
            $code = $codes[spl_object_id($ch)] ?? CURLE_OK;
            try {
                $out[$k] = $this->result($ch, $state, $code === CURLE_OK ? '' : (curl_error($ch) ?: curl_strerror($code) ?: 'Keine Verbindung.'));
            } catch (\RuntimeException $e) {
                $out[$k] = $e;
            }
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
        curl_multi_close($multi);
        // Reihenfolge wie übergeben
        $sorted = [];
        foreach (array_keys($requests) as $k) {
            $sorted[$k] = $out[$k];
        }
        return $sorted;
    }

    public function download(string $url, string $file, array $headers = [], int $timeoutSeconds = 90): array
    {
        self::checkUrl($url);
        if (!function_exists('curl_init')) {
            $res = $this->stream('GET', $url, self::lines($headers), null, $timeoutSeconds);
            if (file_put_contents($file, $res['body']) === false) {
                throw new \RuntimeException('Die Datei ließ sich nicht speichern.');
            }
            return ['status' => $res['status'], 'headers' => $res['headers']];
        }
        $fp = @fopen($file, 'wb');
        if ($fp === false) {
            throw new \RuntimeException('Die Datei ließ sich nicht speichern.');
        }
        $state = new \stdClass();
        $ch = $this->handle('GET', $url, self::lines($headers), null, $timeoutSeconds, $state, $fp);
        $ok = curl_exec($ch);
        fclose($fp);
        try {
            $res = $this->result($ch, $state, $ok === false ? (curl_error($ch) ?: 'Keine Verbindung.') : '');
        } finally {
            curl_close($ch);
        }
        return ['status' => $res['status'], 'headers' => $res['headers']];
    }

    private static function checkUrl(string $url): void
    {
        if (!preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException('Ungültige Adresse.');
        }
    }

    /**
     * @param array<string, string> $headers
     * @return string[]
     */
    private static function lines(array $headers): array
    {
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = $k . ': ' . $v;
        }
        return $lines;
    }

    private function curl(string $method, string $url, array $lines, ?string $body, int $timeout): array
    {
        $state = new \stdClass();
        $ch = $this->handle($method, $url, $lines, $body, $timeout, $state);
        $result = curl_exec($ch);
        try {
            return $this->result($ch, $state, $result === false ? (curl_error($ch) ?: 'Keine Verbindung.') : '');
        } finally {
            curl_close($ch);
        }
    }

    /**
     * Bereitet eine cURL-Anfrage vor. Die Antwort landet in $state->data bzw. in $sink,
     * die Kopfzeilen in $state->headers.
     *
     * @param resource|null $sink
     */
    private function handle(string $method, string $url, array $lines, ?string $body, int $timeout, \stdClass $state, $sink = null): \CurlHandle
    {
        $state->data = '';
        $state->size = 0;
        $state->headers = [];
        $state->tooLarge = false;
        $max = $this->maxBytes;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            // Stückweise lesen und abbrechen, sobald die Antwort zu groß wird.
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use ($state, $max, $sink): int {
                $state->size += strlen($chunk);
                if ($state->size > $max) {
                    $state->tooLarge = true;
                    return 0;
                }
                if ($sink !== null) {
                    return (int) fwrite($sink, $chunk);
                }
                $state->data .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => $lines,
            // Komprimierte Antworten annehmen (alle Verfahren, die cURL kann) und entpacken.
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use ($state): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $state->headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        return $ch;
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private function result(\CurlHandle $ch, \stdClass $state, string $error): array
    {
        if ($state->tooLarge) {
            throw new \RuntimeException(sprintf('Die Antwort ist zu groß (mehr als %d MB).', max(1, intdiv($this->maxBytes, 1024 * 1024))));
        }
        if ($error !== '') {
            throw new \RuntimeException($error);
        }
        return ['status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'headers' => $state->headers, 'body' => $state->data];
    }

    private function stream(string $method, string $url, array $lines, ?string $body, int $timeout): array
    {
        $context = stream_context_create(['http' => [
            'method' => strtoupper($method),
            'header' => implode("\r\n", array_merge($lines, ['User-Agent: ' . $this->userAgent])),
            'content' => $body ?? '',
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 1,
            'max_redirects' => 5,
        ]]);
        $result = @file_get_contents($url, false, $context, 0, $this->maxBytes + 1);
        if ($result === false) {
            throw new \RuntimeException('Keine Verbindung.');
        }
        if (strlen($result) > $this->maxBytes) {
            throw new \RuntimeException(sprintf('Die Antwort ist zu groß (mehr als %d MB).', max(1, intdiv($this->maxBytes, 1024 * 1024))));
        }
        $status = 0;
        $headers = [];
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
                $headers = [];
            } elseif (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }
        return ['status' => $status, 'headers' => $headers, 'body' => $result];
    }
}
