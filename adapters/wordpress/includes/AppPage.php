<?php

declare(strict_types=1);

namespace KompanieApp\WordPress;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Preview;
use KompanieApp\Core\Http\FileCache;
use KompanieApp\Core\Http\ManifestIcons;

/**
 * Liefert die PWA unter /app/ aus.
 *
 * /app/                      → App (Gäste sehen die öffentliche Ansicht)
 * /app/manifest.webmanifest  → Web-App-Manifest
 * /app/sw.js                 → Service Worker (Bereich /app/)
 * /app/foto/<id>/<größe>     → Galeriebild (interne nur für Mitglieder)
 * /app/foto/<id>/video       → Galerievideo in Ausschnitten aus Drive (Range; interne nur für Mitglieder)
 * /app/dokument/<id>?v=…     → Dokument (nur für Mitglieder, die es sehen dürfen)
 * /app/abzeichen/<id>?v=…&s=… → Abzeichen eines Dienstgrads (angemeldet mit Mitgliederbereich oder Kalender)
 * /app/mitgliedsfoto/<id>?v=…&s=… → Foto eines Mitglieds (die Person selbst und wer Mitglieder verwaltet)
 * /app/bild/<kennung>        → Bild in formatiertem Inhalt (Signatur, Schwarzes Brett; wer den Inhalt sehen darf)
 * /app/anhang/<kennung>      → Dateianhang (Schwarzes Brett, E-Mails; wer den Inhalt sehen darf), immer als Download
 * /app/sicherung             → Datensicherung herunterladen (nur Admins), Stück für Stück geschrieben
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
        if (preg_match('#^(foto|dokument|abzeichen|mitgliedsfoto|bild)/#', $path)) {
            // Vorschau „App als Rolle ansehen“ (Issue #63): Bilder, Dokumente und Abzeichen lädt der Browser ohne den Header
            // der App, ihre Adressen tragen deshalb ?vorschau=… (der Kern prüft, ob die Person Admin ist).
            $header = isset($_SERVER['HTTP_X_KOMPANIE_PREVIEW']) && is_string($_SERVER['HTTP_X_KOMPANIE_PREVIEW']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_KOMPANIE_PREVIEW'])) : null;
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur lesend, schränkt Rechte nur ein
            $query = isset($_GET[Preview::QUERY]) && is_string($_GET[Preview::QUERY]) ? sanitize_text_field(wp_unslash($_GET[Preview::QUERY])) : null;
            $app->preview()->begin(Preview::valueFrom($header, $query));
        }
        if ($path === 'manifest.webmanifest') {
            self::sendManifest($app);
        } elseif ($path === 'sw.js') {
            self::sendServiceWorker($app);
        } elseif ($path === 'csrf') {
            self::sendCsrf($app);
        } elseif (preg_match('#^foto/(\d+)/(thumb|large|original)$#', $path, $m)) {
            self::sendPhoto($app, (int) $m[1], $m[2]);
        } elseif (preg_match('#^foto/(\d+)/video/?$#', $path, $m)) {
            self::sendVideo($app, (int) $m[1]);
        } elseif (preg_match('#^dokument/(\d+)/?$#', $path, $m)) {
            self::sendDocument($app, (int) $m[1]);
        } elseif (preg_match('#^abzeichen/(\d+)/?$#', $path, $m)) {
            self::sendBadge($app, (int) $m[1]);
        } elseif (preg_match('#^mitgliedsfoto/(\d+)/?$#', $path, $m)) {
            self::sendMemberPhoto($app, (int) $m[1]);
        } elseif (preg_match('#^bild/([a-f0-9]{32})/?$#', $path, $m)) {
            self::sendBody($app->richImages()->forDelivery($app->authContext->currentUser(), $m[1]));
        } elseif ($path === 'sicherung') {
            self::sendBody($app->backupDownload()->forDelivery($app->authContext->currentUser(), ($_GET['verschluesselt'] ?? '') === '1'));
        } elseif (preg_match('#^anhang/([a-f0-9]{32})/?$#', $path, $m)) {
            self::sendBody($app->attachments()->forDelivery($app->authContext->currentUser(), $m[1]));
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
        // Immer neu prüfen (mit ETag meist 304): Chrome übernimmt Änderungen (Icons, Ausrichtung) in installierte Apps
        // nur, wenn es beim Abgleich das neue Manifest bekommt.
        header('Cache-Control: no-cache');
        $body = (string) wp_json_encode([
            'name' => $c->name(),
            'short_name' => $c->shortName(),
            'description' => $c->description(),
            'lang' => 'de',
            'start_url' => $app->env->appUrl(),
            'scope' => $app->env->appUrl(),
            'display' => 'standalone',
            'orientation' => 'any',
            'background_color' => '#ffffff',
            'theme_color' => $c->primaryColor(),
            'icons' => ManifestIcons::entries($icons, self::distDir() . 'icons'),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (self::notModified(FileCache::contentEtag($body))) {
            return;
        }
        echo $body;
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
        // Der Browser prüft sw.js bei jedem Start der App: mit ETag meist nur 304 ohne Inhalt (Issue #18).
        if (self::notModified(FileCache::etag('sw.js', $file))) {
            return;
        }
        header('Content-Length: ' . filesize($file));
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
        // Das Konto nur, wenn nötig (Wartungsmodus, interne Bilder).
        if ($app->maintenance()->active() && $app->maintenance()->blocks($app->authContext->currentUser())) {
            status_header(503);
            nocache_headers();
            return;
        }
        $res = self::photoResponse($app, $id, $size);
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
        header('X-Content-Type-Options: nosniff');
        // Über diese Adresse kommen auch noch nicht aufbereitete öffentliche Bilder; ein Bild kann
        // später nach „intern“ verschoben werden. Deshalb hier nie dauerhaft öffentlich speichern lassen.
        // In der Vorschau (Issue #63) nie speichern.
        header('Cache-Control: ' . $app->preview()->cacheControl($res['intern'] ? 'private, max-age=86400' : 'public, max-age=3600'));
        if (self::notModified($res['etag'] ?? null)) {
            return;
        }
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($res['file']));
        readfile($res['file']);
    }

    /** Galerievideo (Issue #182): Ausschnitt aus Drive, Status und Bereich wie im Kern festgelegt. */
    private static function sendVideo(App $app, int $id): void
    {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if ($app->maintenance()->active() && $app->maintenance()->blocks($app->authContext->currentUser())) {
            status_header(503);
            nocache_headers();
            return;
        }
        $res = self::videoResponse($app, $id);
        status_header($res['status']);
        header('X-Content-Type-Options: nosniff');
        header('Accept-Ranges: bytes');
        if (isset($res['contentRange'])) {
            header('Content-Range: ' . $res['contentRange']);
        }
        if ($res['status'] !== 206 || !isset($res['body'])) {
            nocache_headers();
            if (isset($res['retryAfter'])) {
                header('Retry-After: ' . (int) $res['retryAfter']);
            }
            return;
        }
        header('Cache-Control: ' . $app->preview()->cacheControl($res['intern'] ? 'private, max-age=86400' : 'public, max-age=3600'));
        header('ETag: ' . $res['etag']);
        header('Content-Type: ' . $res['mime']);
        header('Content-Length: ' . strlen($res['body']));
        echo $res['body']; // phpcs:ignore WordPress.Security.EscapeOutput -- Videodaten aus Drive
    }

    /**
     * Galerievideo: Rechte und Ausschnitt aus dem Kern (Konto nur bei internen Videos).
     *
     * @return array{status: int, body?: string, contentRange?: string, mime?: string, etag?: string, intern?: bool, retryAfter?: int}
     */
    public static function videoResponse(App $app, int $id): array
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- nur „bytes=a-b“, der Kern prüft das Format
        $range = isset($_SERVER['HTTP_RANGE']) && is_string($_SERVER['HTTP_RANGE']) ? wp_unslash($_SERVER['HTTP_RANGE']) : null;
        return $app->gallery()->deliverVideo($id, $range, static function () use ($app): array {
            $user = $app->authContext->currentUser();
            return [$app->can(\KompanieApp\Core\Auth\Capability::ViewGalleryInternal), $user !== null];
        });
    }

    /**
     * Galeriebild: Rechte, Datei und ETag aus dem Kern (Konto nur bei internen Bildern).
     *
     * @return array{status: int, file?: string, etag?: string|null, intern?: bool, placeholder?: string, retryAfter?: int}
     */
    public static function photoResponse(App $app, int $id, string $size): array
    {
        return $app->gallery()->deliverPhoto($id, $size, static function () use ($app): array {
            $user = $app->authContext->currentUser();
            return [$app->can(\KompanieApp\Core\Auth\Capability::ViewGalleryInternal), $user !== null];
        });
    }

    /** Wert von If-None-Match der laufenden Anfrage oder null. */
    public static function ifNoneMatch(): ?string
    {
        return isset($_SERVER['HTTP_IF_NONE_MATCH']) && is_string($_SERVER['HTTP_IF_NONE_MATCH'])
            ? sanitize_text_field(wp_unslash($_SERVER['HTTP_IF_NONE_MATCH']))
            : null;
    }

    /**
     * Bedingter Abruf: ETag setzen und bei passendem If-None-Match mit 304 ohne Inhalt antworten.
     * Erst aufrufen, wenn die Rechte geprüft sind und Cache-Control gesetzt ist.
     */
    private static function notModified(?string $etag): bool
    {
        if ($etag === null) {
            return false;
        }
        header('ETag: ' . $etag);
        if (!FileCache::matches($etag, self::ifNoneMatch())) {
            return false;
        }
        status_header(304);
        return true;
    }

    /** Dokument (Issue #2): PDFs und Bilder inline, Office-Dateien als Download. */
    private static function sendDocument(App $app, int $id): void
    {
        $res = $app->documents()->forDelivery($app->authContext->currentUser(), $id, is_string($_GET['v'] ?? null) ? wp_unslash($_GET['v']) : null);
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
        // Privat; die aktuelle Fassung ein Jahr ohne Nachfrage (die Adresse wechselt mit jeder Fassung), sonst jedes Mal
        // prüfen. Offline hält der Service Worker die Datei.
        $cacheControl = $res['cache_control'] ?? 'private, no-cache';
        header('Cache-Control: ' . $cacheControl);
        if ($cacheControl !== 'private, no-cache') {
            // nocache_headers() von oben zurücknehmen (Expires in der Vergangenheit)
            header_remove('Expires');
            header_remove('Pragma');
        }
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex');
        if (self::notModified($res['etag'] ?? null)) {
            return;
        }
        header('Content-Type: ' . $res['mime']);
        header('Content-Length: ' . filesize($res['path']));
        header('Content-Disposition: ' . $res['disposition']);
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
        if ($res['status'] === 200 && FileCache::matches($res['headers']['ETag'] ?? null, self::ifNoneMatch())) {
            status_header(304);
            return;
        }
        if ($res['status'] === 200 && isset($res['path'])) {
            header('Content-Length: ' . filesize($res['path']));
            readfile($res['path']);
        }
    }

    /**
     * Foto eines Mitglieds: Status, Header und Inhalt wie im Kern festgelegt.
     *
     * @return array{status: int, headers: array<string, string>, body?: string}
     */
    public static function memberPhotoResponse(App $app, int $id): array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur lesend, Rechte prüft der Kern
        $v = isset($_GET['v']) && is_string($_GET['v']) ? $_GET['v'] : null;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $s = isset($_GET['s']) && is_string($_GET['s']) ? $_GET['s'] : null;
        return $app->memberPhotos()->forDelivery($app->authContext->currentUser(), $id, $v, $s);
    }

    private static function sendMemberPhoto(App $app, int $id): void
    {
        self::sendBody(self::memberPhotoResponse($app, $id));
    }

    /**
     * Antwort mit Inhalt aus dem Kern (Fotos der Mitglieder, Bilder in formatierten Inhalten): Status, Header, Inhalt.
     *
     * @param array{status: int, headers: array<string, string>, body?: string} $res
     */
    private static function sendBody(array $res): void
    {
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
        if ($res['status'] === 200 && FileCache::matches($res['headers']['ETag'] ?? null, self::ifNoneMatch())) {
            status_header(304);
            return;
        }
        if ($res['status'] === 200 && isset($res['body'])) {
            header('Content-Length: ' . strlen($res['body']));
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JPEG bzw. PNG, vom Kern neu erzeugt
            echo $res['body'];
        } elseif ($res['status'] === 200 && isset($res['path'])) {
            // Dateianhang: immer als Download (Content-Disposition attachment, nosniff, CSP sandbox; AttachmentService)
            header('Content-Length: ' . filesize($res['path']));
            readfile($res['path']);
        } elseif ($res['status'] === 200 && isset($res['write'])) {
            // Datensicherung Stück für Stück an den Browser (Ausgabepuffer sind oben schon geleert).
            if (function_exists('set_time_limit')) {
                @set_time_limit(300);
            }
            $out = fopen('php://output', 'wb');
            try {
                ($res['write'])($out);
            } catch (\Throwable $e) {
                // Kopf ist schon gesendet: Die Datei bleibt unvollständig (ungültiges JSON, das Einspielen lehnt sie ab).
                error_log('Kompanie-App: Sicherung nicht vollständig heruntergeladen: ' . $e->getMessage());
            } finally {
                fclose($out);
            }
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
        self::securityHeaders();
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    /**
     * Wie die eigenständige Version: nicht in fremde Seiten einbetten (Klickbetrug, „Clickjacking“), Typ nicht raten,
     * Adresse nur gekürzt weitergeben. HSTS bleibt Sache von WordPress bzw. des Hosters (gilt für die ganze Seite).
     */
    private static function securityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        // Zusätzlich (false): Eine CSP der Webseite oder eines Sicherheits-Plugins bleibt; Browser beachten beide.
        header("Content-Security-Policy: frame-ancestors 'self'", false);
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }
}
