<?php

declare(strict_types=1);

namespace KompanieApp\Standalone;

use KompanieApp\Core\App;
use KompanieApp\Core\Config\KompanieConfig;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Infra\CurlHttpClient;
use KompanieApp\Core\Infra\GdImageResizer;
use KompanieApp\Core\Infra\LocalFileStorage;
use KompanieApp\Core\Infra\RemoteNewsSource;
use KompanieApp\Core\Infra\SystemClock;
use KompanieApp\Core\Port\HttpClient;
use KompanieApp\Core\Storage\Sql\Migrator;
use KompanieApp\Core\Storage\Sql\PdoConnection;

/**
 * Setzt die eigenständige Version zusammen und beantwortet HTTP-Anfragen.
 *
 * Verzeichnisse:
 *   <root>/index.php, sw.js, app/ (gebaute App), core/, src/, kompanie.json, VERSION
 *   <root>/media/      öffentliche Galeriebilder (vom Webserver ausgeliefert)
 *   <data>/            config.php, Datenbank, geschützte Dateien; standardmäßig <root>/data,
 *                      außerhalb des Web-Verzeichnisses über <root>/data-dir.php
 *
 * Adressen (in Klammern ohne URL-Umschreibung):
 *   /                        App              (index.php)
 *   /api/v1/…                API              (index.php?r=/api/v1/…)
 *   /manifest.webmanifest    Manifest         (index.php?r=/manifest.webmanifest)
 *   /foto/<id>/<größe>       Galeriebild      (index.php?r=/foto/…)
 *   /cron?key=…              Aufgaben         (index.php?r=/cron&key=…)
 */
final class Kernel
{
    public const DEV_VERSION = '0.0.0-dev';

    private ?App $app = null;
    private ?Config $config = null;
    private ?PdoConnection $db = null;
    private ?Sessions $sessions = null;
    private ?SessionAuth $auth = null;
    private ?PseudoScheduler $scheduler = null;
    private ?StandaloneEnvironment $env = null;
    private HttpContext $http;

    /** @param HttpClient|null $httpClient für Tests (z. B. simuliertes Google Drive) */
    public function __construct(public readonly string $root, ?HttpContext $http = null, private readonly ?HttpClient $httpClient = null)
    {
        $this->http = $http ?? HttpContext::fromGlobals(self::basePath($_SERVER));
    }

    /** Pfad der Installation in der Adresse, z. B. /app/ (aus SCRIPT_NAME von index.php). */
    public static function basePath(array $server): string
    {
        $script = str_replace('\\', '/', (string) ($server['SCRIPT_NAME'] ?? '/index.php'));
        // dirname() liefert unter Windows für „/index.php“ einen Backslash.
        $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
        return $dir . '/';
    }

    public function dataDir(): string
    {
        // Für Tests und besondere Server: Umgebungsvariable vor data-dir.php vor data/.
        $env = getenv('KOMPANIE_DATA_DIR');
        if (is_string($env) && $env !== '') {
            return rtrim($env, '/');
        }
        $pointer = $this->root . '/data-dir.php';
        if (is_file($pointer)) {
            $dir = require $pointer;
            if (is_string($dir) && $dir !== '') {
                return rtrim($dir, '/');
            }
        }
        return $this->root . '/data';
    }

    public function version(): string
    {
        $v = @file_get_contents($this->root . '/VERSION');
        return is_string($v) && trim($v) !== '' ? trim($v) : self::DEV_VERSION;
    }

    public function installed(): bool
    {
        return Config::exists($this->dataDir());
    }

    public function config(): Config
    {
        return $this->config ??= Config::load($this->dataDir());
    }

    public function db(): PdoConnection
    {
        return $this->db ??= $this->config()->connect();
    }

    public function http(): HttpContext
    {
        return $this->http;
    }

    public function sessions(): Sessions
    {
        return $this->sessions ??= new Sessions($this->db(), $this->http);
    }

    public function auth(): SessionAuth
    {
        $this->app();
        return $this->auth;
    }

    public function scheduler(): PseudoScheduler
    {
        return $this->scheduler ??= new PseudoScheduler($this->db());
    }

    public function users(): PdoUsers
    {
        return new PdoUsers($this->db(), $this->sessions());
    }

    /** kompanie.json der Installation; während der Entwicklung das Beispiel aus dem Repo. */
    public function kompanieConfig(): KompanieConfig
    {
        $file = $this->root . '/kompanie.json';
        if (!is_file($file)) {
            $file = dirname($this->root, 2) . '/config/kompanie.example.json';
        }
        return KompanieConfig::fromFile($file);
    }

    public function app(): App
    {
        if ($this->app !== null) {
            return $this->app;
        }
        $config = $this->config();
        $db = $this->db();
        $settings = new SqlSettings($db);
        $cache = new SqlCache($db);
        $httpClient = $this->httpClient ?? new CurlHttpClient('Kompanie-App/' . $this->version());
        $siteUrl = trim((string) $settings->get('site_url', ''));
        $this->env = new StandaloneEnvironment($config, $this->http, $this->version(), $siteUrl !== '' ? $siteUrl : null);
        $users = $this->users();
        $this->auth = new SessionAuth(
            $this->sessions(),
            $users,
            new LoginThrottle($db, $config->secret()),
            $this->http,
            $this->env,
            $config->secret(),
        );
        $data = $this->dataDir();
        $this->app = new App(
            db: $db,
            settings: $settings,
            cache: $cache,
            http: $httpClient,
            files: new LocalFileStorage($this->root . '/media', $this->env->appUrl() . 'media', $data . '/files', self::tempDir($data)),
            images: new GdImageResizer(),
            scheduler: $this->scheduler(),
            news: new RemoteNewsSource($settings, $httpClient, $cache),
            clock: new SystemClock($config->timezone()),
            env: $this->env,
            authContext: $this->auth,
            authenticator: $this->auth,
            users: $users,
            config: $this->kompanieConfig(),
        );
        return $this->app;
    }

    /** Tabellen anlegen bzw. ergänzen: erst die eigenen (darin stehen die Einstellungen), dann die des Kerns. */
    public function upgrade(): void
    {
        $app = $this->app();
        $own = new Migrator($this->db(), $app->settings, Migrations::all(), Migrations::SETTING);
        if ($own->pending()) {
            $own->migrate();
        }
        $app->upgrade();
    }

    /** Führt die fälligen Aufgaben aus (jede mit eigenem Zeitbudget, siehe App::runTask). */
    public function runDueTasks(): void
    {
        $app = $this->app();
        $app->scheduleTasks();
        foreach ($this->scheduler()->claimDue() as $task) {
            $app->runTask($task);
        }
    }

    public function cronKey(): string
    {
        return substr(hash_hmac('sha256', 'cron|key', $this->config()->secret()), 0, 32);
    }

    /** Adresse für einen Cronjob beim Hoster (Alternative zu bin/kompanie.php cron). */
    public function cronUrl(): string
    {
        $this->app();
        $url = $this->env->route('cron');
        return $url . (str_contains($url, '?') ? '&' : '?') . 'key=' . $this->cronKey();
    }

    // ---------------------------------------------------------------- HTTP

    public function handleHttp(): void
    {
        $path = $this->requestPath();
        try {
            $this->route($path);
        } catch (\Throwable $e) {
            // Nie Einzelheiten (Pfade, SQL) an Besucher; sie stehen im Fehlerprotokoll des Servers.
            error_log('Kompanie-App: ' . $e::class . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            if (!headers_sent()) {
                $this->sendError($path, 500, 'internal_error', 'Es ist ein Fehler aufgetreten. Bitte später erneut versuchen.');
            }
        }
    }

    private function route(string $path): void
    {
        if (!$this->installed()) {
            $this->sendNotInstalled($path);
            return;
        }
        try {
            $this->config()->secret();
        } catch (\Throwable $e) {
            error_log('Kompanie-App: config.php: ' . $e->getMessage());
            $this->sendError($path, 503, 'config_invalid', 'Die Einrichtung ist unvollständig oder beschädigt (data/config.php). Bitte install.php erneut hochladen und „Reparieren“ wählen.');
            return;
        }
        try {
            $this->upgrade();
        } catch (\Throwable $e) {
            error_log('Kompanie-App: Datenbank nicht erreichbar: ' . $e->getMessage());
            $this->sendError($path, 503, 'database_unavailable', 'Die Datenbank ist gerade nicht erreichbar. Bitte später erneut versuchen.');
            return;
        }

        if (str_starts_with($path, '/api/v1/') || $path === '/api/v1') {
            $this->sendApi(substr($path, strlen('/api/v1')) ?: '/');
        } elseif ($path === '/manifest.webmanifest') {
            $this->sendManifest();
        } elseif ($path === '/sw.js') {
            $this->sendServiceWorker();
        } elseif (preg_match('#^/foto/(\d+)/(thumb|large)$#', $path, $m)) {
            $this->sendPhoto((int) $m[1], $m[2]);
        } elseif ($path === '/cron') {
            $this->sendCron();
            return;
        } else {
            $this->sendApp();
        }
        $this->afterResponse();
    }

    /** Pfad der Anfrage, z. B. /api/v1/me – aus ?r= oder aus der umgeschriebenen Adresse. */
    public function requestPath(): string
    {
        if (isset($_GET['r']) && is_string($_GET['r'])) {
            $path = $_GET['r'];
        } else {
            // Erst dekodieren, dann vergleichen: SCRIPT_NAME ist dekodiert (z. B. „/mein verein/“).
            $uri = rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
            $base = $this->http->basePath;
            $path = str_starts_with($uri, $base) ? substr($uri, strlen($base)) : $uri;
            $path = (string) preg_replace('#^index\.php#', '', $path);
        }
        $path = '/' . ltrim($path, '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private function sendApi(string $path): void
    {
        $method = $this->http->method();
        header('Cache-Control: no-store');
        $this->securityHeaders();
        try {
            // Erst Herkunft, Inhaltstyp und CSRF-Token prüfen, dann den Inhalt lesen.
            $this->auth()->verifyRequest();
            $body = [];
            $raw = (string) file_get_contents('php://input');
            if ($raw !== '') {
                $body = json_decode($raw, true);
                if (!is_array($body)) {
                    throw ApiError::invalid('Die Anfrage enthält kein gültiges JSON.');
                }
            }
            if ($path === '/config') {
                // Vor dem Erzeugen der Antwort, damit das CSRF-Token zur neuen Sitzung passt.
                $this->sessions()->maybeRenew();
            }
            $query = $_GET;
            unset($query['r']);
            $res = $this->app()->api()->handle(new Request($method, $path, $query, $body));
        } catch (ApiError $e) {
            $res = Response::error($e);
        }
        http_response_code($res->status);
        header('Content-Type: application/json; charset=utf-8');
        foreach ($res->headers as $k => $v) {
            header($k . ': ' . $v);
        }
        echo json_encode($res->data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function sendManifest(): void
    {
        $app = $this->app();
        $c = $app->config;
        $icons = $app->env->appUrl() . 'app/icons/';
        header('Content-Type: application/manifest+json; charset=utf-8');
        // Adressen hängen ohne fest eingetragene base_url von der Anfrage ab: nicht in fremden Caches speichern.
        header('Cache-Control: private, max-age=3600');
        $this->securityHeaders();
        echo json_encode([
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

    /** Normalerweise liefert der Webserver sw.js direkt; das hier ist der Weg über index.php. */
    private function sendServiceWorker(): void
    {
        $file = is_file($this->root . '/sw.js') ? $this->root . '/sw.js' : $this->appDir() . '/sw.js';
        if (!is_file($file)) {
            $this->sendPlain(404, 'Nicht gefunden.');
            return;
        }
        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: no-cache');
        header('Service-Worker-Allowed: ' . $this->http->basePath);
        readfile($file);
    }

    private function sendPhoto(int $id, string $size): void
    {
        $app = $this->app();
        $user = $app->authContext->currentUser();
        $res = $app->gallery()->photoForDelivery($id, $size, (bool) $user?->isMember(), $user !== null);
        $this->securityHeaders();
        if ($res['status'] !== 200) {
            http_response_code($res['status']);
            return;
        }
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($res['file']));
        // Siehe WordPress-Adapter: Bilder können später nach „intern“ verschoben werden.
        header($res['intern'] ? 'Cache-Control: private, max-age=86400' : 'Cache-Control: public, max-age=3600');
        readfile($res['file']);
    }

    private function sendCron(): void
    {
        // Schlüssel im Header (Selbstaufruf) oder als Parameter (Cronjob des Hosters).
        $key = (string) ($this->http->header('X-Kompanie-Cron') ?? (is_string($_GET['key'] ?? null) ? $_GET['key'] : ''));
        header('Cache-Control: no-store');
        if (!hash_equals($this->cronKey(), $key)) {
            $this->sendPlain(403, 'Falscher Schlüssel.');
            return;
        }
        ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        $this->sendPlain(200, 'OK');
        $this->finishResponse();
        $this->runDueTasks();
    }

    private function sendApp(): void
    {
        header('Cache-Control: no-store');
        // Mit fest eingetragener Adresse: andere Schreibweisen (z. B. mit/ohne www) dorthin umleiten,
        // sonst lehnt die Herkunftsprüfung die Anmeldung als „fremde Seite“ ab.
        $base = $this->config()->baseUrl();
        if ($base !== '' && strcasecmp((string) parse_url($base, PHP_URL_HOST), (string) parse_url($this->http->baseUrl(), PHP_URL_HOST)) !== 0
            && $this->http->method() === 'GET') {
            header('Location: ' . $base, true, 301);
            return;
        }
        $file = $this->appDir() . '/index.html';
        if (!is_readable($file)) {
            $this->sendPlain(500, 'Die App wurde noch nicht gebaut (app/index.html fehlt).');
            return;
        }
        $app = $this->app();
        // Wer die App öffnet, bleibt angemeldet: dauerhafte Sitzung bei Bedarf verlängern.
        $this->sessions()->maybeRenew();
        $nonce = base64_encode(random_bytes(16));
        $config = json_encode($app->api()->config(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $head = sprintf(
            '<link rel="manifest" href="%s"><script nonce="%s">window.KOMPANIE_CONFIG=%s;</script>',
            htmlspecialchars($this->env->route('manifest.webmanifest'), ENT_QUOTES),
            $nonce,
            $config,
        );
        $html = (string) file_get_contents($file);
        // Relative Pfade aus dem Vite-Build (base: './') auf das App-Verzeichnis umbiegen.
        $base = htmlspecialchars($app->env->appUrl() . 'app/', ENT_QUOTES);
        $html = str_replace(['"./', "'./"], ['"' . $base, "'" . $base], $html);
        $pos = stripos($html, '<head>');
        $html = $pos === false ? $head . $html : substr_replace($html, $head, $pos + 6, 0);

        header('Content-Type: text/html; charset=utf-8');
        $this->securityHeaders();
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'unsafe-inline'; "
            . "img-src 'self' data: blob: https:; font-src 'self' data:; connect-src 'self'; frame-src https:; worker-src 'self'; "
            . "manifest-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
        echo $html;
    }

    private function sendNotInstalled(string $path): void
    {
        $msg = 'Die Kompanie-App ist hier noch nicht eingerichtet. Bitte install.php hochladen und im Browser öffnen.';
        if (str_starts_with($path, '/api/')) {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['code' => 'not_installed', 'message' => $msg], JSON_UNESCAPED_UNICODE);
            return;
        }
        $this->sendPlain(503, $msg);
    }

    /** Fehler als JSON (API) oder als Text (alles andere). */
    private function sendError(string $path, int $status, string $code, string $message): void
    {
        if (str_starts_with($path, '/api/')) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            $this->securityHeaders();
            echo json_encode(['code' => $code, 'message' => $message], JSON_UNESCAPED_UNICODE);
            return;
        }
        $this->sendPlain($status, $message);
    }

    private function sendPlain(int $status, string $text): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        $this->securityHeaders();
        echo $text;
    }

    private function securityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Frame-Options: SAMEORIGIN');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    }

    private function appDir(): string
    {
        return $this->root . '/app';
    }

    /**
     * Nach der Antwort: fällige Aufgaben (Pseudo-Cron). Wo der Server die Antwort vorab
     * abschließen kann (PHP-FPM, LiteSpeed), direkt hier; sonst über einen Aufruf der
     * Cron-Adresse im Hintergrund, damit Besucher nicht warten.
     */
    private function afterResponse(): void
    {
        try {
            $this->app()->scheduleTasks();
            if (!$this->scheduler()->anyDue()) {
                return;
            }
            if ($this->finishResponse()) {
                ignore_user_abort(true);
                $this->runDueTasks();
            } else {
                $this->spawnCron();
            }
        } catch (\Throwable $e) {
            error_log('Kompanie-App: Aufgaben: ' . $e->getMessage());
        }
    }

    private function finishResponse(): bool
    {
        if (function_exists('fastcgi_finish_request')) {
            return fastcgi_finish_request();
        }
        if (function_exists('litespeed_finish_request')) {
            return litespeed_finish_request();
        }
        return false;
    }

    /**
     * Ruft die eigene Cron-Adresse im Hintergrund auf. Nur mit fest eingetragener Adresse
     * (base_url aus config.php), nie mit der Adresse aus der Anfrage (Host-Header), und höchstens
     * einmal pro Minute. Der Schlüssel geht im Header, nicht in der Adresse.
     */
    private function spawnCron(): void
    {
        $base = $this->config()->baseUrl();
        if ($base === '' || !function_exists('curl_init')) {
            return;
        }
        $cache = $this->app()->cache;
        if ($cache->get('cron_spawned') !== null) {
            return;
        }
        $cache->set('cron_spawned', 1, 60);
        $url = $this->config()->urlMode() === 'rewrite' ? $base . 'cron' : $base . 'index.php?r=/cron';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => 300,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['X-Kompanie-Cron: ' . $this->cronKey()],
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    private static function tempDir(string $data): string
    {
        $dir = $data . '/tmp';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return is_dir($dir) && is_writable($dir) ? $dir : '';
    }
}
