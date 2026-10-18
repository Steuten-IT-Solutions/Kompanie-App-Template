<?php

declare(strict_types=1);

namespace KompanieApp\WordPress;

use KompanieApp\Core\Port\HttpClient;

/** Ausgehende Anfragen über die WordPress-HTTP-API. */
final class WpHttpClient implements HttpClient
{
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeoutSeconds = 15): array
    {
        $args = ['method' => strtoupper($method), 'headers' => $headers, 'timeout' => $timeoutSeconds];
        if ($body !== null) {
            $args['body'] = $body;
        }
        $res = wp_remote_request($url, $args);
        if (is_wp_error($res)) {
            throw new \RuntimeException($res->get_error_message());
        }
        $out = [];
        foreach ((array) wp_remote_retrieve_headers($res) as $k => $v) {
            $out[strtolower((string) $k)] = is_array($v) ? implode(', ', $v) : (string) $v;
        }
        return ['status' => (int) wp_remote_retrieve_response_code($res), 'headers' => $out, 'body' => (string) wp_remote_retrieve_body($res)];
    }
}
