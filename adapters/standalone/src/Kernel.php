<?php

declare(strict_types=1);

namespace KompanieApp\Standalone;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Preview;
use KompanieApp\Core\Config\KompanieConfig;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Http\FileCache;
use KompanieApp\Core\Http\ManifestIcons;
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
 *   /foto/<id>/video         Galerievideo in Ausschnitten aus Drive (Range)
 *   /dokument/<id>?v=…       Dokument         (index.php?r=/dokument/…&v=…)
 *   /abzeichen/<id>?v=…&s=…  Abzeichen        (index.php?r=/abzeichen/…&v=…&s=…)
 *   /mitgliedsfoto/<id>?v=…&s=… Foto eines Mitglieds (index.php?r=/mitgliedsfoto/…&v=…&s=…)
 *   /bild/<kennung>          Bild in formatiertem Inhalt (index.php?r=/bild/…)
 *   /anhang/<kennung>        Dateianhang (index.php?r=/anhang/…), immer als Download
 *   /sicherung               Datensicherung herunterladen, nur Admins (index.php?r=/sicherung)
 *   /cron?key=…              Aufgaben         (index.php?r=/cron&key=…)
 *   /google/callback         Rückkehr von Google („Mit Google verbinden“, index.php?r=/google/callback)
 */
final class Kernel
{
    public const DEV_VERSION = '0.0.0-dev';

    /** Einstellung: letzter Lauf je Art (siehe runDueTasks), Unix-Zeit. */
    public const CRON_RUNS_SETTING = 'cron_runs';
    /** Einstellung: Stand des Selbstaufrufs (letzter Versuch, Fehlschläge in Folge, letzter Fehler). */
    public const CRON_SPAWN_SETTING = 'cron_spawn';

    /** Zeitgrenze (Sekunden) beim Selbstaufruf für den Verbindungsaufbau, bei https auch für TLS. */
    private const SPAWN_TIMEOUT = 0.25;
    /** Längste Pause zwischen zwei Versuchen, wenn der Selbstaufruf fehlschlägt. */
    private const SPAWN_MAX_BACKOFF = 3600;

    private ?App $app = null;
    private ?Config $config = null;
    private ?PdoConnection $db = null;
    private ?Sessions $sessions = null;
    private ?SessionAuth $auth = null;
    private ?PseudoScheduler $scheduler = null;
    private ?SqlSettings $settings = null;
    private ?StandaloneEnvironment $env = null;
    private HttpContext $http;

    /**
     * @param HttpClient|null $httpClient für Tests (z. B. simuliertes Google Drive)
     * @param array<string, mixed> $packageOverride für Tests: Werte, die update.json ergänzen (z. B. reportUrl)
     */
    public function __construct(
        public readonly string $root,
        ?HttpContext $http = null,
        private readonly ?HttpClient $httpClient = null,
        private readonly array $packageOverride = [],
    ) {
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

    /**
     * update.json des Pakets: Adresse der Update-Liste, vertrauenswürdige Schlüssel, Statusmeldungen.
     * Fehlt sie (Entwicklung), gibt es keine Updates.
     *
     * @return array<string, mixed>
     */
    public function packageConfig(): array
    {
        $data = json_decode((string) @file_get_contents($this->root . '/update.json'), true);
        return $this->packageOverride + (is_array($data) ? $data : []);
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
        return $this->scheduler ??= new PseudoScheduler($this->db(), $this->settings());
    }

    /** Einstellungen; eine Instanz für App und Pseudo-Cron (alle Einstellungen in einer Abfrage). */
    public function settings(): SqlSettings
    {
        return $this->settings ??= new SqlSettings($this->db());
    }

    public function users(): PdoUsers
    {
        // Rechte je Rolle aus der App (Einstellungen → Rollen), mit den Zusatzrollen und Ausnahmen der Person; erst beim ersten
        // Benutzer abgefragt.
        return new PdoUsers($this->db(), $this->sessions(), fn (string $role, int $id): array => $this->app()->roles()->capabilitiesFor($id, $role));
    }

    /**
     * kompanie.json der Installation. Während der Entwicklung (kein Paket): die Datei aus
     * KOMPANIE_CONFIG wie beim Bauen (z. B. für Bildschirmfotos einer Kompanie), sonst das Beispiel.
     */
    public function kompanieConfig(): KompanieConfig
    {
        $file = $this->root . '/kompanie.json';
        $env = getenv('KOMPANIE_CONFIG');
        if (!is_file($file)) {
            $file = is_string($env) && is_file($env) ? $env : dirname($this->root, 2) . '/config/kompanie.example.json';
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
        $settings = $this->settings();
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
            $settings,
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
            updateTarget: new StandaloneUpdateTarget($this->root, $data),
            backup: new StandaloneBackup($this),
            healthChecks: new StandaloneHealthChecks($config, $httpClient, $cache, $settings, $this),
            packageConfig: $this->packageConfig(),
        );
        $this->app->watchFatals();
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

    /**
     * Führt die fälligen Aufgaben aus (jede mit eigenem Zeitbudget, siehe App::runTask).
     * $via: cron (Cron-Adresse, vom Hoster aufgerufen), cli (bin/kompanie.php cron),
     * spawn (Selbstaufruf), request (nach einer Anfrage im selben Prozess); für die Statusseite.
     */
    public function runDueTasks(string $via = 'cli'): void
    {
        $app = $this->app();
        $this->recordCronRun($via);
        $app->scheduleTasks();
        foreach ($this->scheduler()->claimDue() as $task) {
            $app->runTask($task);
        }
    }

    private function recordCronRun(string $via): void
    {
        $runs = $this->settings()->get(self::CRON_RUNS_SETTING);
        $runs = is_array($runs) ? $runs : [];
        $now = time();
        // Ein Cronjob läuft oft minütlich: dann nur alle fünf Minuten schreiben. Selbstaufrufe
        // immer, daran erkennt spawnCron(), ob der letzte Aufruf angekommen ist.
        if ($via === 'spawn' || $now - (int) ($runs[$via] ?? 0) >= 300) {
            $runs[$via] = $now;
            $this->settings()->set(self::CRON_RUNS_SETTING, $runs);
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
        } elseif (preg_match('#^/foto/(\d+)/(thumb|large|original)$#', $path, $m)) {
            $this->beginPreview();
            $this->sendPhoto((int) $m[1], $m[2]);
        } elseif (preg_match('#^/foto/(\d+)/video$#', $path, $m)) {
            $this->beginPreview();
            $this->sendVideo((int) $m[1]);
        } elseif (preg_match('#^/dokument/(\d+)$#', $path, $m)) {
            $this->beginPreview();
            $this->sendDocument((int) $m[1]);
        } elseif (preg_match('#^/abzeichen/(\d+)$#', $path, $m)) {
            $this->beginPreview();
            $this->sendBadge((int) $m[1]);
        } elseif (preg_match('#^/mitgliedsfoto/(\d+)$#', $path, $m)) {
            $this->beginPreview();
            $this->sendMemberPhoto((int) $m[1]);
        } elseif (preg_match('#^/bild/([a-f0-9]{32})$#', $path, $m)) {
            $this->beginPreview();
            $app = $this->app();
            $this->sendBody($app->richImages()->forDelivery($app->authContext->currentUser(), $m[1]));
        } elseif ($path === '/sicherung') {
            $app = $this->app();
            $this->sendBody($app->backupDownload()->forDelivery($app->authContext->currentUser()));
        } elseif (preg_match('#^/anhang/([a-f0-9]{32})$#', $path, $m)) {
            $this->beginPreview();
            $app = $this->app();
            $this->sendBody($app->attachments()->forDelivery($app->authContext->currentUser(), $m[1]));
        } elseif ($path === '/google/callback') {
            $this->sendGoogleCallback();
        } elseif ($path === '/cron') {
            $this->sendCron();
            return;
        } else {
            $this->sendApp();
        }
        $this->afterResponse();
    }

    /**
     * Vorschau „App als Rolle ansehen“ (Issue #63) für Bilder, Dokumente und Abzeichen: Der Browser lädt sie ohne den
     * Header der App, ihre Adressen tragen deshalb ?vorschau=… (der Kern prüft, ob die Person Admin ist).
     */
    private function beginPreview(): void
    {
        $this->app()->preview()->begin(Preview::valueFrom($this->http->header(Preview::HEADER), $_GET[Preview::QUERY] ?? null));
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
        // Daten der App (auch öffentliche wie Mitgliederliste und Ämter) nicht in Suchmaschinen (Issue #112).
        header('X-Robots-Tag: noindex');
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
            $res = $this->app()->api()->handle(new Request($method, $path, $query, $body, [], $this->http->clientIp(), $this->http->header(Preview::HEADER)));
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
        header('Cache-Control: private, max-age=86400');
        $this->securityHeaders();
        echo json_encode([
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
            'icons' => ManifestIcons::entries($icons, $this->appDir() . '/icons'),
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
        // Der Browser prüft sw.js bei jedem Start der App: mit ETag meist nur 304 ohne Inhalt (Issue #18).
        if ($this->notModified(FileCache::etag('sw.js', $file))) {
            return;
        }
        header('Content-Length: ' . filesize($file));
        readfile($file);
    }

    private function sendPhoto(int $id, string $size): void
    {
        $app = $this->app();
        $this->securityHeaders();
        // Sitzung und Konto nur, wenn nötig (Wartungsmodus, interne Bilder): öffentliche Bilder ohne diese Abfragen.
        if ($app->maintenance()->active() && $app->maintenance()->blocks($app->authContext->currentUser())) {
            http_response_code(503);
            header('Cache-Control: no-store');
            return;
        }
        $res = $app->gallery()->deliverPhoto($id, $size, static function () use ($app): array {
            $user = $app->authContext->currentUser();
            return [(bool) $user?->isMember(), $user !== null];
        });
        if (isset($res['placeholder'])) {
            // Noch nicht aufbereitet: Platzhalter, nicht speichern, später erneut versuchen.
            http_response_code($res['status']);
            header('Content-Type: image/svg+xml');
            header('Cache-Control: no-store');
            header('Retry-After: ' . (int) ($res['retryAfter'] ?? 30));
            echo $res['placeholder'];
            return;
        }
        if ($res['status'] !== 200) {
            http_response_code($res['status']);
            return;
        }
        // Siehe WordPress-Adapter: Bilder können später nach „intern“ verschoben werden.
        // In der Vorschau (Issue #63) nie speichern.
        header('Cache-Control: ' . $app->preview()->cacheControl($res['intern'] ? 'private, max-age=86400' : 'public, max-age=3600'));
        if ($this->notModified($res['etag'] ?? null)) {
            return;
        }
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($res['file']));
        readfile($res['file']);
    }

    /** Galerievideo (Issue #182): Ausschnitt aus Drive, Status und Bereich wie im Kern festgelegt. */
    private function sendVideo(int $id): void
    {
        $app = $this->app();
        $this->securityHeaders();
        if ($app->maintenance()->active() && $app->maintenance()->blocks($app->authContext->currentUser())) {
            http_response_code(503);
            header('Cache-Control: no-store');
            return;
        }
        $res = $app->gallery()->deliverVideo($id, $this->http->header('Range'), static function () use ($app): array {
            $user = $app->authContext->currentUser();
            return [(bool) $user?->isMember(), $user !== null];
        });
        http_response_code($res['status']);
        header('Accept-Ranges: bytes');
        if (isset($res['contentRange'])) {
            header('Content-Range: ' . $res['contentRange']);
        }
        if ($res['status'] !== 206 || !isset($res['body'])) {
            header('Cache-Control: no-store');
            if (isset($res['retryAfter'])) {
                header('Retry-After: ' . (int) $res['retryAfter']);
            }
            return;
        }
        header('Cache-Control: ' . $app->preview()->cacheControl($res['intern'] ? 'private, max-age=86400' : 'public, max-age=3600'));
        header('ETag: ' . $res['etag']);
        header('Content-Type: ' . $res['mime']);
        header('Content-Length: ' . strlen($res['body']));
        echo $res['body'];
    }

    /**
     * Bedingter Abruf: ETag setzen und bei passendem If-None-Match mit 304 ohne Inhalt antworten.
     * Erst aufrufen, wenn die Rechte geprüft sind und Cache-Control gesetzt ist.
     */
    private function notModified(?string $etag): bool
    {
        if ($etag === null) {
            return false;
        }
        header('ETag: ' . $etag);
        if (!FileCache::matches($etag, $this->http->header('If-None-Match'))) {
            return false;
        }
        http_response_code(304);
        return true;
    }

    /** Dokument (Issue #2): PDFs und Bilder inline, Office-Dateien als Download. */
    private function sendDocument(int $id): void
    {
        $app = $this->app();
        $res = $app->documents()->forDelivery($app->authContext->currentUser(), $id, is_string($_GET['v'] ?? null) ? $_GET['v'] : null);
        $this->securityHeaders();
        header('Cache-Control: private, no-cache');
        if ($res['status'] !== 200 || !isset($res['path'], $res['mime'], $res['disposition'])) {
            http_response_code($res['status']);
            return;
        }
        // Aktuelle Fassung: ein Jahr (die Adresse wechselt mit jeder neuen Fassung), sonst jedes Mal prüfen.
        header('Cache-Control: ' . ($res['cache_control'] ?? 'private, no-cache'));
        header('X-Robots-Tag: noindex');
        if ($this->notModified($res['etag'] ?? null)) {
            return;
        }
        header('Content-Type: ' . $res['mime']);
        header('Content-Length: ' . filesize($res['path']));
        header('Content-Disposition: ' . $res['disposition']);
        readfile($res['path']);
    }

    /** Abzeichen (Issue #17): Status und Header wie im Kern festgelegt (SVG mit strenger CSP). */
    private function sendBadge(int $id): void
    {
        $app = $this->app();
        $v = is_string($_GET['v'] ?? null) ? $_GET['v'] : null;
        $s = is_string($_GET['s'] ?? null) ? $_GET['s'] : null;
        $res = $app->ranks()->badgeForDelivery($app->authContext->currentUser(), $id, $v, $s);
        $this->securityHeaders();
        http_response_code($res['status']);
        foreach ($res['headers'] as $name => $value) {
            header($name . ': ' . $value);
        }
        if ($res['status'] === 200 && FileCache::matches($res['headers']['ETag'] ?? null, $this->http->header('If-None-Match'))) {
            http_response_code(304);
            return;
        }
        if ($res['status'] === 200 && isset($res['path'])) {
            header('Content-Length: ' . filesize($res['path']));
            readfile($res['path']);
        }
    }

    /** Foto eines Mitglieds: Status, Header und Inhalt wie im Kern festgelegt. */
    private function sendMemberPhoto(int $id): void
    {
        $app = $this->app();
        $v = is_string($_GET['v'] ?? null) ? $_GET['v'] : null;
        $s = is_string($_GET['s'] ?? null) ? $_GET['s'] : null;
        $this->sendBody($app->memberPhotos()->forDelivery($app->authContext->currentUser(), $id, $v, $s));
    }

    /**
     * Antwort mit Inhalt aus dem Kern (Fotos der Mitglieder, Bilder in formatierten Inhalten): Status, Header, Inhalt.
     *
     * @param array{status: int, headers: array<string, string>, body?: string} $res
     */
    private function sendBody(array $res): void
    {
        $this->securityHeaders();
        http_response_code($res['status']);
        foreach ($res['headers'] as $name => $value) {
            header($name . ': ' . $value);
        }
        if ($res['status'] === 200 && FileCache::matches($res['headers']['ETag'] ?? null, $this->http->header('If-None-Match'))) {
            http_response_code(304);
            return;
        }
        if ($res['status'] === 200 && isset($res['body'])) {
            header('Content-Length: ' . strlen($res['body']));
            echo $res['body'];
        } elseif ($res['status'] === 200 && isset($res['path'])) {
            header('Content-Length: ' . filesize($res['path']));
            readfile($res['path']);
        } elseif ($res['status'] === 200 && isset($res['write'])) {
            // Stück für Stück an den Browser (Datensicherung): ohne Puffer, mit Zeit für große Sicherungen.
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
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

    private function sendCron(): void
    {
        // Schlüssel im Header (Selbstaufruf) oder als Parameter (Cronjob des Hosters).
        $header = $this->http->header('X-Kompanie-Cron');
        $key = (string) ($header ?? (is_string($_GET['key'] ?? null) ? $_GET['key'] : ''));
        header('Cache-Control: no-store');
        if (!hash_equals($this->cronKey(), $key)) {
            $this->sendPlain(403, 'Falscher Schlüssel.');
            return;
        }
        ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        ob_start(); // für flushResponse(): Länge der Antwort
        $this->sendPlain(200, 'OK');
        // Der Aufrufer (Selbstaufruf, wget/curl des Hosters) soll nicht auf die Aufgaben warten.
        if (!$this->finishResponse()) {
            $this->flushResponse();
        }
        $this->runDueTasks($header !== null ? 'spawn' : 'cron');
    }

    private function sendApp(): void
    {
        header('Cache-Control: no-store');
        // Mit fest eingetragener Adresse: andere Schreibweisen (z. B. mit/ohne www) dorthin umleiten,
        // sonst lehnt die Herkunftsprüfung die Anmeldung als „fremde Seite“ ab. Hinter einem Proxy
        // zählt X-Forwarded-Host; 302 statt 301, damit Browser eine falsche Umleitung nicht dauerhaft merken.
        $base = $this->config()->baseUrl();
        $baseHost = strtolower((string) parse_url($base, PHP_URL_HOST));
        $hosts = array_map(static fn ($h): string => strtolower((string) preg_replace('/:\d+$/', '', trim((string) $h))), array_filter([
            $this->http->host(), ...explode(',', (string) $this->http->header('X-Forwarded-Host')),
        ]));
        if ($base !== '' && $this->http->method() === 'GET' && !in_array($baseHost, $hosts, true)) {
            header('Location: ' . $base, true, 302);
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
    /**
     * Rückkehr von Google nach „Mit Google verbinden“ (Weiterleitungs-URI, siehe StandaloneEnvironment). Der Kern prüft
     * Admin, Sitzung und state und tauscht den Code ein; danach geht es in die App zu den Einstellungen, die das
     * Ergebnis anzeigen. Ohne angemeldeten Admin nur eine Meldung.
     */
    private function sendGoogleCallback(): void
    {
        $app = $this->app();
        header('Cache-Control: no-store');
        // Code und state nicht als Referrer weitergeben.
        header('Referrer-Policy: no-referrer');
        $query = $_GET;
        unset($query['r']);
        try {
            $app->googleConnect()->callback($query);
        } catch (ApiError $e) {
            $this->sendPlain($e->status, $e->getMessage());
            return;
        }
        header('Location: ' . $app->googleConnect()->appReturnUrl(), true, 303);
    }

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
     * Nach der Antwort: fällige Aufgaben (Pseudo-Cron). Ob etwas fällig ist, steht in den
     * Einstellungen (keine eigene Abfrage, siehe PseudoScheduler). Wo der Server die Antwort vorab
     * abschließen kann (PHP-FPM, LiteSpeed), direkt hier; sonst über einen Aufruf der
     * Cron-Adresse im Hintergrund, auf den der Besucher nicht wartet.
     */
    private function afterResponse(): void
    {
        try {
            if (!$this->scheduler()->anyDue()) {
                return;
            }
            if ($this->finishResponse()) {
                ignore_user_abort(true);
                $this->runDueTasks('request');
            } else {
                $this->spawnCron();
            }
        } catch (\Throwable $e) {
            error_log('Kompanie-App: Aufgaben: ' . $e->getMessage());
        }
    }

    /** Kann der Server die Antwort abschließen und danach weiterarbeiten (PHP-FPM, LiteSpeed)? */
    public static function canFinishResponse(): bool
    {
        return function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
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
     * Ohne PHP-FPM: die Antwort mit Länge und „Connection: close“ vollständig schicken, damit der
     * Aufrufer nicht auf das Ende des Skripts wartet. Wirkt nicht, wenn der Webserver die Ausgabe
     * selbst puffert (z. B. zum Komprimieren); dann wartet der Aufrufer wie bisher.
     */
    private function flushResponse(): void
    {
        if (headers_sent()) {
            return;
        }
        // Innere Puffer in den äußersten übernehmen; dessen Inhalt ist die ganze Antwort.
        while (ob_get_level() > 1) {
            ob_end_flush();
        }
        if (ob_get_level() === 0) {
            return; // ohne Ausgabepuffer ist die Länge nicht bekannt
        }
        header('Content-Length: ' . (int) ob_get_length());
        header('Connection: close');
        ob_end_flush();
        flush();
    }

    /**
     * Ruft die eigene Cron-Adresse im Hintergrund auf, ohne auf die Antwort zu warten: Verbindung
     * aufbauen, Anfrage schreiben, schließen. Nur mit fest eingetragener Adresse (base_url aus
     * config.php), nie mit der Adresse aus der Anfrage (Host-Header). Der Schlüssel geht im Header,
     * nicht in der Adresse.
     *
     * Höchstens einmal pro Minute. Kommt ein Aufruf nicht an (keine Verbindung, vom Hoster gesperrt,
     * die Cron-Adresse meldet sich nicht), zählt das als Fehlschlag; dann wird es seltener versucht
     * (bis einmal pro Stunde), und die Statusseite empfiehlt einen Cronjob.
     */
    private function spawnCron(): void
    {
        $base = $this->config()->baseUrl();
        if ($base === '') {
            return;
        }
        $settings = $this->settings();
        $state = $settings->get(self::CRON_SPAWN_SETTING);
        $state = is_array($state) ? $state : [];
        $at = (int) ($state['at'] ?? 0);
        $failures = (int) ($state['failures'] ?? 0);
        $error = (string) ($state['error'] ?? '');
        $checked = !empty($state['checked']);
        $runs = $settings->get(self::CRON_RUNS_SETTING);
        $arrived = is_array($runs) ? (int) ($runs['spawn'] ?? 0) : 0;
        $now = time();
        if ($at > 0 && !$checked) {
            // Ist der letzte Selbstaufruf angekommen? Er vermerkt sich in CRON_RUNS_SETTING.
            if ($arrived >= $at) {
                $failures = 0;
                $error = '';
            } elseif ($now - $at >= 60) {
                $failures++;
                $error = 'Der Aufruf der eigenen Cron-Adresse kam nicht an.';
            } else {
                return; // vielleicht läuft er noch
            }
            $checked = true;
        }
        if ($now - $at < min(self::SPAWN_MAX_BACKOFF, 60 * 2 ** min($failures, 6))) {
            if ($checked !== !empty($state['checked'])) {
                $settings->set(self::CRON_SPAWN_SETTING, ['at' => $at, 'failures' => $failures, 'error' => $error, 'checked' => true]);
            }
            return;
        }
        $url = $this->config()->urlMode() === 'rewrite' ? $base . 'cron' : $base . 'index.php?r=/cron';
        try {
            $sent = self::fireAndForget($url, ['X-Kompanie-Cron' => $this->cronKey()], self::SPAWN_TIMEOUT);
        } catch (\Throwable $e) {
            // z. B. stream_socket_client vom Hoster abgeschaltet: wie ein Fehlschlag behandeln (seltener versuchen, Statusseite)
            $sent = 'Der Selbstaufruf ist auf diesem Server nicht möglich (' . $e->getMessage() . ').';
        }
        if ($sent !== null) {
            $failures++;
            $error = $sent;
        }
        $settings->set(self::CRON_SPAWN_SETTING, ['at' => $now, 'failures' => $failures, 'error' => $error, 'checked' => $sent !== null]);
    }

    /**
     * Schickt eine GET-Anfrage und schließt die Verbindung, ohne auf die Antwort zu warten.
     *
     * @param array<string, string> $headers
     * @return string|null null, wenn gesendet, sonst die Fehlermeldung
     */
    public static function fireAndForget(string $url, array $headers, float $timeout): ?string
    {
        $u = parse_url($url);
        $scheme = strtolower((string) ($u['scheme'] ?? ''));
        $host = (string) ($u['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return 'Ungültige Adresse.';
        }
        $port = (int) ($u['port'] ?? ($scheme === 'https' ? 443 : 80));
        // Bei https gilt die Zeit für den Verbindungsaufbau und für TLS. Das Zertifikat wird wie
        // üblich geprüft: Der Cron-Schlüssel geht im Header mit.
        $target = ($scheme === 'https' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $context = stream_context_create(['ssl' => ['peer_name' => $host, 'SNI_enabled' => true]]);
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client($target, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($fp === false) {
            return 'Keine Verbindung zur eigenen Adresse' . ($errstr !== '' ? ' (' . $errstr . ')' : '') . '.';
        }
        $path = ($u['path'] ?? '/') . (isset($u['query']) ? '?' . $u['query'] : '');
        $request = 'GET ' . $path . " HTTP/1.1\r\nHost: " . $host . (isset($u['port']) ? ':' . $port : '')
            . "\r\nUser-Agent: Kompanie-App\r\nConnection: close\r\n";
        foreach ($headers as $k => $v) {
            $request .= $k . ': ' . $v . "\r\n";
        }
        $request .= "\r\n";
        stream_set_timeout($fp, 0, (int) ($timeout * 1000000));
        $written = @fwrite($fp, $request);
        fclose($fp);
        return $written === strlen($request) ? null : 'Die Anfrage an die eigene Adresse ließ sich nicht senden.';
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
