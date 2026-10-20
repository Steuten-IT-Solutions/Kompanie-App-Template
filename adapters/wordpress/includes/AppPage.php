<?php

declare(strict_types=1);

namespace KompanieApp\WordPress;

use KompanieApp\Core\App;

/**
 * Liefert die PWA unter /app/ aus.
 *
 * /app/                      → App (Gäste sehen die öffentliche Ansicht)
 * /app/manifest.webmanifest  → Web-App-Manifest
 * /app/sw.js                 → Service Worker (Bereich /app/)
 * /app/foto/<id>/<größe>     → Galeriebild (interne nur für Mitglieder)
 * /app/dokument/<id>?v=…     → Dokument (nur für Mitglieder, die es sehen dürfen)
 * /app/abzeichen/<id>?v=…&s=… → Abzeichen eines Dienstgrads (angemeldet mit Mitgliederbereich oder Kalender)
 * /app/csrf                  → neues CSRF-Token (REST-Nonce) für angemeldete Benutzer
 * /app/<anderes>             → App (z. B. /app/installieren/ zum Weitergeben)
 *
 * Die gebauten JS/CSS-Dateien liegen in app/ und werden direkt als statische Dateien ausgeliefert.
 */
final class AppPage
{
    public const QUERY_VAR = 'kompanie_app';

    public static function init(): void
    {
        add_action('init', [self::class, 'addRewriteRules']);
        add_filter('query_vars', static function (array $vars): array {
            $vars[] = self::QUERY_VAR;
            return $vars;
        });
        add_action('template_redirect', static fn () => self::dispatch(Plugin::app()), 0);
    }

    public const RULES = ['^app/?$' => 'index', '^app/(.+)$' => '$matches[1]'];

    public static function addRewriteRules(): void
    {
        foreach (self::RULES as $regex => $value) {
            add_rewrite_rule($regex, 'index.php?' . self::QUERY_VAR . '=' . $value, 'top');
        }
    }

    /** Beim Deaktivieren: Regeln entfernen, bevor sie neu geschrieben werden. */
    public static function removeRewriteRules(): void
    {
        global $wp_rewrite;
        foreach (array_keys(self::RULES) as $regex) {
            unset($wp_rewrite->extra_rules_top[$regex]);
        }
    }

    public static function distDir(): string
    {
        return KOMPANIE_APP_DIR . 'app/';
    }

    public static function dispatch(App $app): void
    {
        $path = (string) get_query_var(self::QUERY_VAR);
        if ($path === '') {
            return;
        }
        if ($path === 'manifest.webmanifest') {
            self::sendManifest($app);
        } elseif ($path === 'sw.js') {
            self::sendServiceWorker($app);
        } elseif ($path === 'csrf') {
            self::sendCsrf($app);
        } elseif (preg_match('#^foto/(\d+)/(thumb|large)$#', $path, $m)) {
            self::sendPhoto($app, (int) $m[1], $m[2]);
        } elseif (preg_match('#^dokument/(\d+)/?$#', $path, $m)) {
            self::sendDocument($app, (int) $m[1]);
        } elseif (preg_match('#^abzeichen/(\d+)/?$#', $path, $m)) {
            self::sendBadge($app, (int) $m[1]);
        } else {
            self::sendApp($app);
        }
        exit;
    }

    private static function sendManifest(App $app): void
    {
        $c = $app->config;
        $icons = KOMPANIE_APP_URL . 'app/icons/';
        header('Content-Type: application/manifest+json; charset=utf-8');
        header('Cache-Control: public, max-age=86400');
        echo wp_json_encode([
            'name' => $c->name(),
            'short_name' => $c->shortName(),
            'description' => $c->description(),
            'lang' => 'de',
            'start_url' => $app->env->appUrl(),
            'scope' => $app->env->appUrl(),
            'display' => 'standalone',
            'orientation' => 'portrait',
            'background_color' => '#ffffff',
            'theme_color' => $c->primaryColor(),
            'icons' => [
                ['src' => $icons . 'icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => $icons . 'icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => $icons . 'icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function sendServiceWorker(App $app): void
    {
        $file = self::distDir() . 'sw.js';
        if (!is_readable($file)) {
            status_header(404);
            return;
        }
        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: no-cache');
        header('Service-Worker-Allowed: ' . (string) wp_parse_url($app->env->appUrl(), PHP_URL_PATH));
        readfile($file);
    }

    private static function sendCsrf(App $app): void
    {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        if (!is_user_logged_in()) {
            status_header(401);
            echo wp_json_encode(['code' => 'not_logged_in', 'message' => 'Bitte melde dich an.']);
            return;
        }
        echo wp_json_encode(['token' => $app->authenticator->csrfToken()]);
    }

    private static function sendPhoto(App $app, int $id, string $size): void
    {
        $user = $app->authContext->currentUser();
        if ($app->maintenance()->blocks($user)) {
            status_header(503);
            nocache_headers();
            return;
        }
        $res = $app->gallery()->photoForDelivery($id, $size, (bool) $user?->isMember(), $user !== null);
        if (isset($res['placeholder'])) {
            // Noch nicht aufbereitet: Platzhalter, nicht speichern, später erneut versuchen.
            status_header($res['status']);
            nocache_headers();
            header('Content-Type: image/svg+xml');
            header('X-Content-Type-Options: nosniff');
            header('Retry-After: ' . (int) ($res['retryAfter'] ?? 30));
            echo $res['placeholder']; // phpcs:ignore WordPress.Security.EscapeOutput -- festes SVG ohne Eingaben
            return;
        }
        if ($res['status'] !== 200) {
            status_header($res['status']);
            return;
        }
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($res['file']));
        header('X-Content-Type-Options: nosniff');
        // Über diese Adresse kommen auch noch nicht aufbereitete öffentliche Bilder; ein Bild kann
        // später nach „intern“ verschoben werden. Deshalb hier nie dauerhaft öffentlich speichern lassen.
        header($res['intern'] ? 'Cache-Control: private, max-age=86400' : 'Cache-Control: public, max-age=3600');
        readfile($res['file']);
    }

    /** Dokument (Issue #2): PDFs und Bilder inline, Office-Dateien als Download. */
    private static function sendDocument(App $app, int $id): void
    {
        $res = $app->documents()->forDelivery($app->authContext->currentUser(), $id);
        // Nie in einen Seiten-Cache, nicht in Ausgabepuffer (Dateien bis 20 MB).
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        nocache_headers();
        if ($res['status'] !== 200 || !isset($res['path'], $res['mime'], $res['disposition'])) {
            status_header($res['status']);
            return;
        }
        header('Content-Type: ' . $res['mime']);
        header('Content-Length: ' . filesize($res['path']));
        header('Content-Disposition: ' . $res['disposition']);
        // Privat und jedes Mal prüfen; offline hält der Service Worker die Datei (die Adresse wechselt mit der Fassung).
        header('Cache-Control: private, no-cache');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex');
        readfile($res['path']);
    }

    /**
     * Abzeichen (Issue #17): Status, Header und Datei wie im Kern festgelegt (SVG mit strenger CSP).
     *
     * @return array{status: int, headers: array<string, string>, path?: string}
     */
    public static function badgeResponse(App $app, int $id): array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur lesend, Rechte prüft der Kern
        $v = isset($_GET['v']) && is_string($_GET['v']) ? $_GET['v'] : null;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $s = isset($_GET['s']) && is_string($_GET['s']) ? $_GET['s'] : null;
        return $app->ranks()->badgeForDelivery($app->authContext->currentUser(), $id, $v, $s);
    }

    private static function sendBadge(App $app, int $id): void
    {
        $res = self::badgeResponse($app, $id);
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        status_header($res['status']);
        foreach ($res['headers'] as $name => $value) {
            header($name . ': ' . $value);
        }
        if ($res['status'] === 200 && isset($res['path'])) {
            header('Content-Length: ' . filesize($res['path']));
            readfile($res['path']);
        }
    }

    private static function sendApp(App $app): void
    {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        nocache_headers();
        $file = self::distDir() . 'index.html';
        if (!is_readable($file)) {
            status_header(500);
            echo 'Die App wurde noch nicht gebaut (app/index.html fehlt).';
            return;
        }
        // Wer die App öffnet, bleibt angemeldet: Anmeldung bei Bedarf verlängern.
        Session::maybeRenew();

        $head = sprintf(
            '<link rel="manifest" href="%s"><script>window.KOMPANIE_CONFIG=%s;</script>',
            esc_url((new WpEnvironment())->appUrl('manifest.webmanifest')),
            wp_json_encode($app->api()->config(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES),
        );
        $html = (string) file_get_contents($file);
        // Relative Pfade aus dem Vite-Build (base: './') auf das Plugin-Verzeichnis umbiegen.
        $base = KOMPANIE_APP_URL . 'app/';
        $html = str_replace(['"./', "'./"], ['"' . $base, "'" . $base], $html);
        $pos = strpos($html, '<head>');
        $html = $pos === false ? $head . $html : substr_replace($html, $head, $pos + strlen('<head>'), 0);
        header('Content-Type: text/html; charset=utf-8');
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput
    }
}
