<?php

declare(strict_types=1);

namespace KompanieApp\WordPress;

use KompanieApp\Core\Port\ExtendedHttpClient;

/**
 * Ausgehende Anfragen über die WordPress-HTTP-API.
 *
 * Mehrere Anfragen gleichzeitig (requestAll) über WpOrg\Requests\Requests::request_multiple, mit
 * denselben Einstellungen und Filtern wie wp_remote_request (http_request_args, pre_http_request,
 * http_response, Zertifikate, Proxy, WP_HTTP_BLOCK_EXTERNAL). Beantwortet ein Filter die Anfrage
 * selbst (pre_http_request), gilt seine Antwort. Ohne Requests oder bei nur einer Anfrage nacheinander.
 *
 * Downloads (download) schreibt WordPress direkt in die Datei (stream), nicht in den Arbeitsspeicher.
 */
final class WpHttpClient implements ExtendedHttpClient
{
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeoutSeconds = 15): array
    {
        $res = wp_remote_request($url, self::args($method, $headers, $body, $timeoutSeconds));
        if (is_wp_error($res)) {
            throw new \RuntimeException($res->get_error_message());
        }
        return self::result($res);
    }

    public function requestAll(array $requests, int $timeoutSeconds = 15): array
    {
        $out = [];
        $parallel = [];
        foreach ($requests as $k => $r) {
            $args = self::args($r['method'], $r['headers'] ?? [], $r['body'] ?? null, $timeoutSeconds);
            if (count($requests) < 2 || !class_exists('\WpOrg\Requests\Requests') || !class_exists('\WP_Http')) {
                $out[$k] = $this->tryRequest($r['method'], $r['url'], $r['headers'] ?? [], $r['body'] ?? null, $timeoutSeconds);
                continue;
            }
            // Wie WP_Http::request: erst die Filter, dann (nur wenn keiner antwortet) über das Netz.
            $args = apply_filters('http_request_args', wp_parse_args($args, self::defaults($r['url'])), $r['url']);
            $pre = apply_filters('pre_http_request', false, $args, $r['url']);
            if ($pre !== false) {
                $out[$k] = is_wp_error($pre) ? new \RuntimeException($pre->get_error_message()) : (is_array($pre) ? self::result($pre) : new \RuntimeException('Keine Verbindung.'));
                continue;
            }
            if (!preg_match('#^https?://#i', $r['url'])) {
                $out[$k] = new \RuntimeException('Ungültige Adresse.');
                continue;
            }
            if ((new \WP_Http())->block_request($r['url'])) {
                $out[$k] = new \RuntimeException('Anfragen an fremde Server sind auf dieser Seite gesperrt.');
                continue;
            }
            $out[$k] = null;
            $parallel[$k] = $args;
        }
        if ($parallel !== []) {
            foreach ($this->multiple($requests, $parallel) as $k => $res) {
                $out[$k] = $res;
            }
        }
        // Reihenfolge wie übergeben
        $sorted = [];
        foreach (array_keys($requests) as $k) {
            $sorted[$k] = $out[$k] ?? new \RuntimeException('Die Anfrage wurde nicht abgeschlossen.');
        }
        return $sorted;
    }

    public function download(string $url, string $file, array $headers = [], int $timeoutSeconds = 90): array
    {
        $res = wp_remote_request($url, self::args('GET', $headers, null, $timeoutSeconds) + ['stream' => true, 'filename' => $file]);
        if (is_wp_error($res)) {
            throw new \RuntimeException($res->get_error_message());
        }
        $out = self::result($res);
        // Hat ein Filter (pre_http_request) geantwortet, steht der Inhalt im Body statt in der Datei.
        if ($out['body'] !== '' && (!is_file($file) || (int) filesize($file) === 0) && file_put_contents($file, $out['body']) === false) {
            throw new \RuntimeException('Die Datei ließ sich nicht speichern.');
        }
        return ['status' => $out['status'], 'headers' => $out['headers']];
    }

    /**
     * @param array<array-key, array{method: string, url: string, headers?: array<string, string>, body?: string|null}> $requests
     * @param array<array-key, array<string, mixed>> $parallel Argumente nach http_request_args, je Schlüssel
     * @return array<array-key, array{status: int, headers: array<string, string>, body: string}|\RuntimeException>
     */
    private function multiple(array $requests, array $parallel): array
    {
        $batch = [];
        foreach ($parallel as $k => $args) {
            $url = $requests[$k]['url'];
            $options = [
                'timeout' => $args['timeout'],
                'useragent' => $args['user-agent'],
                'hooks' => new \WP_HTTP_Requests_Hooks($url, $args),
                'verify' => apply_filters('https_ssl_verify', $args['sslverify'] ? $args['sslcertificates'] : false, $url),
            ];
            if (!$args['sslverify']) {
                $options['verifyname'] = false;
            }
            $options['hooks']->register('requests.before_redirect', ['\WP_Http', 'browser_redirect_compatibility']);
            if (empty($args['redirection'])) {
                $options['follow_redirects'] = false;
            } else {
                $options['redirects'] = $args['redirection'];
            }
            if (!in_array($args['method'], ['GET', 'HEAD'], true)) {
                $options['data_format'] = 'body';
            }
            $proxy = new \WP_HTTP_Proxy();
            if ($proxy->is_enabled() && $proxy->send_through_proxy($url)) {
                $options['proxy'] = new \WpOrg\Requests\Proxy\Http($proxy->host() . ':' . $proxy->port());
                if ($proxy->use_authentication()) {
                    $options['proxy']->use_authentication = true;
                    $options['proxy']->user = $proxy->username();
                    $options['proxy']->pass = $proxy->password();
                }
            }
            $batch[$k] = ['url' => $url, 'headers' => (array) $args['headers'], 'data' => $args['body'], 'type' => $args['method'], 'options' => $options];
        }
        mbstring_binary_safe_encoding();
        try {
            $responses = \WpOrg\Requests\Requests::request_multiple($batch);
        } catch (\Throwable $e) {
            $responses = array_fill_keys(array_keys($batch), $e);
        } finally {
            reset_mbstring_encoding();
        }
        $out = [];
        foreach ($batch as $k => $request) {
            $res = $responses[$k] ?? null;
            if (!$res instanceof \WpOrg\Requests\Response) {
                $out[$k] = new \RuntimeException($res instanceof \Throwable ? $res->getMessage() : 'Keine Verbindung.');
                continue;
            }
            $array = (new \WP_HTTP_Requests_Response($res, null))->to_array();
            $array = apply_filters('http_response', $array, $parallel[$k], $request['url']);
            $out[$k] = self::result($array);
        }
        return $out;
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private static function args(string $method, array $headers, ?string $body, int $timeoutSeconds): array
    {
        $args = ['method' => strtoupper($method), 'headers' => $headers, 'timeout' => $timeoutSeconds];
        if ($body !== null) {
            $args['body'] = $body;
        }
        return $args;
    }

    /** @return array<string, mixed> Standardwerte wie in WP_Http::request */
    private static function defaults(string $url): array
    {
        return [
            'method' => 'GET',
            'timeout' => 5,
            'redirection' => apply_filters('http_request_redirection_count', 5, $url),
            'httpversion' => '1.0',
            'user-agent' => apply_filters('http_headers_useragent', 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'), $url),
            'reject_unsafe_urls' => false,
            'blocking' => true,
            'headers' => [],
            'cookies' => [],
            'body' => null,
            'compress' => false,
            'decompress' => true,
            'sslverify' => true,
            'sslcertificates' => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
            'stream' => false,
            'filename' => null,
            'limit_response_size' => null,
        ];
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}|\RuntimeException
     */
    private function tryRequest(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): array|\RuntimeException
    {
        try {
            return $this->request($method, $url, $headers, $body, $timeoutSeconds);
        } catch (\RuntimeException $e) {
            return $e;
        }
    }

    /**
     * @param array<string, mixed> $res Antwort im Format von wp_remote_request
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private static function result(array $res): array
    {
        $out = [];
        // Bei echten Antworten ein CaseInsensitiveDictionary: durchlaufen, nicht in ein Array umwandeln
        // (ein (array)-Cast ergäbe dessen interne Eigenschaften statt der Kopfzeilen).
        $headers = wp_remote_retrieve_headers($res);
        foreach (is_iterable($headers) ? $headers : [] as $k => $v) {
            $out[strtolower((string) $k)] = is_array($v) ? implode(', ', $v) : (string) $v;
        }
        return ['status' => (int) wp_remote_retrieve_response_code($res), 'headers' => $out, 'body' => (string) wp_remote_retrieve_body($res)];
    }
}
