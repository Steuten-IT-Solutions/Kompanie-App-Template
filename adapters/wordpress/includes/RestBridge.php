<?php

declare(strict_types=1);

namespace KompanieApp\WordPress;

use KompanieApp\Core\App;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Route;

/**
 * Registriert die Routen des Kerns in der REST-API von WordPress.
 * Bereich und Rechte prüft der Kern; WordPress übernimmt die Anmeldung per Cookie
 * und prüft den Nonce (ohne gültigen Nonce gilt eine Anfrage als nicht angemeldet).
 */
final class RestBridge
{
    /**
     * @param string $namespace Namensraum; ein anderer als der eigene dient als Alias, etwa für die Adressen einer
     *                          früheren App nach einer Übernahme (noch geladene alte Fassungen laufen nicht ins Leere).
     */
    public static function register(App $app, string $namespace = WpEnvironment::NAMESPACE): void
    {
        $api = $app->api();
        $byPath = [];
        foreach ($api->routes() as $route) {
            $byPath[$route->path][] = $route;
        }
        foreach ($byPath as $path => $routes) {
            $endpoints = [];
            foreach ($routes as $route) {
                $endpoints[] = [
                    'methods' => $route->method,
                    'callback' => static fn (\WP_REST_Request $wp) => self::handle($app, $route, $wp),
                    'permission_callback' => '__return_true',
                ];
            }
            register_rest_route($namespace, self::wpPattern($path), $endpoints);
        }
    }

    /** '/events/{id}' → '/events/(?P<id>\d+)' */
    public static function wpPattern(string $path): string
    {
        return strtr($path, ['{id}' => '(?P<id>\d+)', '{key}' => '(?P<key>[A-Za-z0-9_-]+)']);
    }

    private static function handle(App $app, Route $route, \WP_REST_Request $wp): \WP_REST_Response
    {
        $body = $wp->get_json_params();
        if (!is_array($body)) {
            $body = $wp->get_body_params();
        }
        $params = [];
        foreach (['id', 'key'] as $p) {
            if (isset($wp->get_url_params()[$p])) {
                $params[$p] = (string) $wp->get_url_params()[$p];
            }
        }
        // REMOTE_ADDR nur für Begrenzungen (z. B. neue Gast-Abos für Push); Weiterleitungs-Header kann jeder setzen.
        $client = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        $request = new Request($wp->get_method(), $route->path, $wp->get_query_params(), is_array($body) ? $body : [], $params, $client);
        $res = $app->api()->dispatch($route, $request);
        $out = new \WP_REST_Response($res->data, $res->status);
        foreach ($res->headers as $name => $value) {
            $out->header($name, $value);
        }
        // Persönliche Daten nicht in Caches oder CDNs ablegen.
        $out->header('Cache-Control', 'no-store, private');
        return $out;
    }
}
