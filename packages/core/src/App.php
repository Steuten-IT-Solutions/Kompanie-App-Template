<?php

declare(strict_types=1);

namespace KompanieApp\Core;

use KompanieApp\Core\Config\KompanieConfig;
use KompanieApp\Core\Domain\CalendarService;
use KompanieApp\Core\Domain\DriveClient;
use KompanieApp\Core\Domain\EventService;
use KompanieApp\Core\Domain\Features;
use KompanieApp\Core\Domain\GalleryService;
use KompanieApp\Core\Domain\MemberService;
use KompanieApp\Core\Domain\RoleService;
use KompanieApp\Core\Domain\ScoreService;
use KompanieApp\Core\Domain\SettingsService;
use KompanieApp\Core\Domain\StatusService;
use KompanieApp\Core\Domain\Time;
use KompanieApp\Core\Domain\UserNames;
use KompanieApp\Core\Http\Api;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Port\AuthContext;
use KompanieApp\Core\Port\BackupProvider;
use KompanieApp\Core\Port\HealthChecks;
use KompanieApp\Core\Port\UpdateTarget;
use KompanieApp\Core\Port\Authenticator;
use KompanieApp\Core\Port\Cache;
use KompanieApp\Core\Port\Clock;
use KompanieApp\Core\Port\Environment;
use KompanieApp\Core\Port\FileStorage;
use KompanieApp\Core\Port\HttpClient;
use KompanieApp\Core\Port\ImageResizer;
use KompanieApp\Core\Port\NewsSource;
use KompanieApp\Core\Port\Scheduler;
use KompanieApp\Core\Port\Settings;
use KompanieApp\Core\Port\UserDirectory;
use KompanieApp\Core\Push\PushService;
use KompanieApp\Core\Push\PushTriggers;
use KompanieApp\Core\Storage\CatalogRepository;
use KompanieApp\Core\Storage\EventRepository;
use KompanieApp\Core\Storage\GalleryRepository;
use KompanieApp\Core\Storage\PenaltyRepository;
use KompanieApp\Core\Storage\PointRepository;
use KompanieApp\Core\Storage\Sql\Connection;
use KompanieApp\Core\Storage\Sql\Migrator;
use KompanieApp\Core\Telemetry\Code;
use KompanieApp\Core\Telemetry\Telemetry;
use KompanieApp\Core\Update\StatusReporter;
use KompanieApp\Core\Update\UpdateService;

/**
 * Setzt den Kern aus den Umsetzungen der Ports zusammen, die ein Adapter liefert.
 * Dienste werden erst bei Bedarf erzeugt.
 */
final class App
{
    /** Wiederkehrende Aufgaben: Name => Abstand in Sekunden */
    public const TASKS = [
        GalleryService::TASK => 3600,
        GalleryService::PROCESS_TASK => 3600,
        CalendarService::TASK => 3600,
        UpdateService::TASK => UpdateService::INTERVAL,
        StatusReporter::TASK => StatusReporter::INTERVAL,
        PushService::TASK_SEND => 3600,
        PushService::TASK_WATCH => PushService::WATCH_INTERVAL,
    ];

    /**
     * Einstellung: Version, für die die Aufgaben zuletzt eingeplant wurden. Eingeplant wird nicht bei
     * jeder Anfrage, sondern beim Speichern von Einstellungen (Features, SettingsService, Updates),
     * im Cron und nach einem Update bzw. einer Migration (dann passt diese Einstellung nicht mehr).
     */
    public const PLAN_SETTING = 'tasks_planned';

    /** Einstellung: letzter Lauf einer Hintergrundaufgabe (Unix-Zeit, für die Statusmeldungen) */
    public const TASK_RUN_SETTING = 'tasks_last_run';

    /** @var array<string, object> */
    private array $services = [];

    public function __construct(
        public readonly Connection $db,
        public readonly Settings $settings,
        public readonly Cache $cache,
        public readonly HttpClient $http,
        public readonly FileStorage $files,
        public readonly ImageResizer $images,
        public readonly Scheduler $scheduler,
        public readonly NewsSource $news,
        public readonly Clock $clock,
        public readonly Environment $env,
        public readonly AuthContext $authContext,
        public readonly Authenticator $authenticator,
        public readonly UserDirectory $users,
        public readonly KompanieConfig $config,
        public readonly ?UpdateTarget $updateTarget = null,
        public readonly ?BackupProvider $backup = null,
        public readonly ?HealthChecks $healthChecks = null,
        /** @var array<string, mixed> update.json des Pakets: updatesUrl, trustedKeys, statusUrl, kompanie, support */
        public readonly array $packageConfig = [],
    ) {
    }

    public function migrator(): Migrator
    {
        return new Migrator($this->db, $this->settings);
    }

    /** Einstellung mit der tatsächlich laufenden Version (liest der Installer im Reparaturmodus). */
    public const VERSION_SETTING = 'running_version';

    /** Ausstehende Migrationen ausführen und die laufende Version festhalten. */
    public function upgrade(): void
    {
        $migrator = $this->migrator();
        // Vor den Migrationen merken, ob die Installation schon bestand (für die Bereiche, s. u.).
        $existing = $migrator->current() > 0;
        $migrated = false;
        if ($migrator->pending()) {
            $migrator->migrate();
            $migrated = true;
        }
        $this->features()->initialize($existing);
        $this->roles()->initialize($existing);
        // Die laufende Version festhalten: Fehlt später die Datei VERSION, weiß der Installer trotzdem,
        // welche Hauptversion installiert ist (auch nach einem abgebrochenen oder händischen Wechsel).
        // Reine Hilfsinformation: Ein Fehler beim Schreiben darf keinen Aufruf scheitern lassen.
        $version = $this->env->version();
        try {
            if (\KompanieApp\Core\Update\Version::valid($version) && $this->settings->get(self::VERSION_SETTING) !== $version) {
                $this->settings->set(self::VERSION_SETTING, $version);
            }
        } catch (\Throwable $e) {
            error_log('Kompanie-App: laufende Version nicht gespeichert: ' . $e->getMessage());
        }
        // Nach einem Update oder einer Migration neu einplanen (neue Aufgaben, geänderte Abstände).
        // Sonst nicht: Eine normale Anfrage soll die Aufgaben weder lesen noch schreiben.
        if ($migrated || $this->settings->get(self::PLAN_SETTING) !== $version) {
            try {
                $this->scheduleTasks();
            } catch (\Throwable $e) {
                error_log('Kompanie-App: Aufgaben nicht eingeplant: ' . $e->getMessage());
            }
        }
    }

    /**
     * Plant die wiederkehrenden Aufgaben ein bzw. aus, je nach Einrichtung. Aufrufen, wenn sich
     * die Einrichtung geändert hat (nicht bei jeder Anfrage, siehe PLAN_SETTING).
     */
    public function scheduleTasks(): void
    {
        $gallery = $this->features()->enabled('gallery') && $this->gallery()->isConfigured();
        $wanted = [
            GalleryService::TASK => $gallery,
            GalleryService::PROCESS_TASK => $gallery,
            CalendarService::TASK => $this->features()->enabled('calendar') && $this->calendar()->isConfigured(),
            UpdateService::TASK => $this->updates()->configured(),
            StatusReporter::TASK => $this->statusReporter()->enabled(),
            // Versand: stündlich als Rückfall, sonst nach dem Einreihen vorgezogen (Scheduler::runSoon).
            PushService::TASK_SEND => $this->push()->supported(),
            PushService::TASK_WATCH => $this->push()->supported()
                && ($this->features()->enabled('calendar') || $this->features()->enabled('news') || $this->features()->enabled('events')),
        ];
        foreach ($wanted as $task => $on) {
            $on ? $this->scheduler->schedule($task, self::TASKS[$task]) : $this->scheduler->unschedule($task);
        }
        $version = $this->env->version();
        if ($this->settings->get(self::PLAN_SETTING) !== $version) {
            $this->settings->set(self::PLAN_SETTING, $version);
        }
    }

    /**
     * Führt eine geplante Aufgabe aus. Fehler werden protokolliert und als Fehlercode gezählt
     * (Statusmeldungen, nur mit Zustimmung), nicht weitergeworfen.
     */
    public function runTask(string $task): void
    {
        $this->noteTaskRun();
        try {
            if ($task === GalleryService::TASK && $this->features()->enabled('gallery')) {
                $this->gallery()->runTask(25);
                if ((string) ($this->gallery()->status()['error'] ?? '') !== '') {
                    $this->telemetry()->record(Code::GallerySync);
                }
            } elseif ($task === GalleryService::PROCESS_TASK && $this->features()->enabled('gallery')) {
                $this->gallery()->runProcessTask(25);
            } elseif ($task === CalendarService::TASK && $this->features()->enabled('calendar')) {
                if (!$this->calendar()->refreshIfStale()) {
                    $this->telemetry()->record(Code::CalendarFetch);
                }
            } elseif ($task === UpdateService::TASK) {
                $this->updates()->runTask();
            } elseif ($task === StatusReporter::TASK) {
                $this->statusReporter()->runTask();
            } elseif ($task === PushService::TASK_SEND) {
                $this->push()->processQueue();
            } elseif ($task === PushService::TASK_WATCH) {
                $this->pushTriggers()->watch();
            }
        } catch (\Throwable $e) {
            error_log('Kompanie-App: Aufgabe ' . $task . ' fehlgeschlagen: ' . $e->getMessage());
            // Die Statusmeldung selbst nicht zählen (sonst meldete sie beim nächsten Mal ihr eigenes Scheitern),
            // Fehler der Update-Liste zählt UpdateService schon.
            if ($task !== StatusReporter::TASK && !($task === UpdateService::TASK && $e instanceof ApiError)) {
                $this->telemetry()->record(Code::TaskFailed);
            }
        }
    }

    /** Letzten Lauf merken, höchstens alle fünf Minuten geschrieben (mehrere Aufgaben laufen oft zusammen). */
    private function noteTaskRun(): void
    {
        try {
            $now = $this->time()->timestamp();
            $last = $this->settings->get(self::TASK_RUN_SETTING);
            if (!is_numeric($last) || $now - (int) $last >= 300) {
                $this->settings->set(self::TASK_RUN_SETTING, $now);
            }
        } catch (\Throwable $e) {
            error_log('Kompanie-App: Lauf der Aufgaben nicht vermerkt: ' . $e->getMessage());
        }
    }

    /**
     * Schwere PHP-Fehler in der App als Fehlercode zählen (nur der Code, kein Text). Ruft der Adapter
     * einmal je Anfrage auf; Fehler anderer Plugins oder Programme zählen nicht. Bei WordPress läuft vorher
     * dessen eigener Fehler-Handler (Fehlerseite ohne exit), danach diese Funktion.
     */
    public function watchFatals(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;
        register_shutdown_function(function (): void {
            $e = error_get_last();
            if ($e !== null) {
                $this->recordFatal($e);
            }
        });
    }

    /**
     * Zählt einen schweren PHP-Fehler, wenn er in der App passiert ist (höchstens einmal je Anfrage).
     *
     * @param array{type?: int, file?: string} $error wie error_get_last()
     */
    public function recordFatal(array $error): void
    {
        static $recorded = false;
        // Im Paket liegt der Kern unter <Plugin bzw. App>/core: dann zählt der ganze Ordner der App,
        // in der Entwicklung (packages/core/src) nur der Kern.
        $root = (basename(__DIR__) === 'core' ? dirname(__DIR__) : __DIR__) . DIRECTORY_SEPARATOR;
        if ($recorded || !in_array($error['type'] ?? 0, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)
            || !str_starts_with((string) ($error['file'] ?? ''), $root)) {
            return;
        }
        $recorded = true;
        // Bei zu wenig Speicher scheitert auch das Zählen; dann eben nicht.
        try {
            $this->telemetry()->record(Code::PhpFatal);
        } catch (\Throwable) {
        }
    }

    public function push(): PushService
    {
        return $this->service(PushService::class, fn () => new PushService($this));
    }

    public function pushTriggers(): PushTriggers
    {
        return $this->service(PushTriggers::class, fn () => new PushTriggers($this, $this->push()));
    }

    public function telemetry(): Telemetry
    {
        return $this->service(Telemetry::class, fn () => new Telemetry(
            $this->settings,
            $this->scheduler,
            $this->time(),
            fn (): bool => $this->statusReporter()->enabled(),
            StatusReporter::TASK,
        ));
    }

    public function features(): Features
    {
        return $this->service(Features::class, fn () => new Features($this));
    }

    public function api(): Api
    {
        return $this->service(Api::class, fn () => new Api($this));
    }

    public function time(): Time
    {
        return $this->service(Time::class, fn () => new Time($this->clock));
    }

    public function events(): EventService
    {
        return $this->service(EventService::class, fn () => new EventService(new EventRepository($this->db), $this->time()));
    }

    public function scores(): ScoreService
    {
        return $this->service(ScoreService::class, fn () => new ScoreService(
            new PointRepository($this->db),
            new PenaltyRepository($this->db),
            new CatalogRepository($this->db),
            $this->users,
            new UserNames($this->users),
            $this->time(),
            $this->roles(),
        ));
    }

    public function members(): MemberService
    {
        return $this->service(MemberService::class, fn () => new MemberService($this->users, $this->scores(), $this->authenticator, $this->cache, $this->roles()));
    }

    public function roles(): RoleService
    {
        return $this->service(RoleService::class, fn () => new RoleService($this->settings, $this->config, $this->users));
    }

    public function calendar(): CalendarService
    {
        return $this->service(CalendarService::class, fn () => new CalendarService($this->settings, $this->http, $this->cache, $this->time()));
    }

    public function drive(): DriveClient
    {
        return $this->service(DriveClient::class, fn () => new DriveClient($this->settings, $this->http, $this->cache, $this->files, $this->time()));
    }

    public function settingsService(): SettingsService
    {
        return $this->service(SettingsService::class, fn () => new SettingsService($this));
    }

    public function updates(): UpdateService
    {
        return $this->service(UpdateService::class, fn () => new UpdateService(
            $this->settings,
            $this->http,
            $this->files,
            $this->time(),
            $this->env->version(),
            $this->updateTarget,
            $this->backup,
            $this->packageConfig,
            $this->telemetry(),
        ));
    }

    public function status(): StatusService
    {
        return $this->service(StatusService::class, fn () => new StatusService($this));
    }

    public function statusReporter(): StatusReporter
    {
        return $this->service(StatusReporter::class, fn () => new StatusReporter($this));
    }

    public function gallery(): GalleryService
    {
        return $this->service(GalleryService::class, fn () => new GalleryService(
            new GalleryRepository($this->db),
            $this->drive(),
            $this->files,
            $this->images,
            $this->settings,
            $this->env,
            $this->time(),
            $this->scheduler,
            $this->cache,
        ));
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @param callable(): T $factory
     * @return T
     */
    private function service(string $id, callable $factory): object
    {
        return $this->services[$id] ??= $factory();
    }
}
