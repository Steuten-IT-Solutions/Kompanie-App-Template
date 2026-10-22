<?php

declare(strict_types=1);

namespace KompanieApp\Core;

use KompanieApp\Core\Auth\Preview;
use KompanieApp\Core\Auth\PreviewEnvironment;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Config\KompanieConfig;
use KompanieApp\Core\Domain\AttendanceService;
use KompanieApp\Core\Domain\BirthdayService;
use KompanieApp\Core\Domain\BoardService;
use KompanieApp\Core\Domain\BugReportService;
use KompanieApp\Core\Domain\CalendarService;
use KompanieApp\Core\Domain\DocumentService;
use KompanieApp\Core\Domain\DriveClient;
use KompanieApp\Core\Domain\EventService;
use KompanieApp\Core\Domain\Features;
use KompanieApp\Core\Domain\FormerMemberService;
use KompanieApp\Core\Domain\GalleryService;
use KompanieApp\Core\Domain\GoogleConnect;
use KompanieApp\Core\Domain\JoinYearService;
use KompanieApp\Core\Domain\MemberService;
use KompanieApp\Core\Domain\PollService;
use KompanieApp\Core\Domain\RankService;
use KompanieApp\Core\Domain\RoleService;
use KompanieApp\Core\Domain\ScoreService;
use KompanieApp\Core\Domain\SettingsService;
use KompanieApp\Core\Domain\TitleService;
use KompanieApp\Core\Domain\StatusService;
use KompanieApp\Core\Domain\Time;
use KompanieApp\Core\Domain\MaintenanceService;
use KompanieApp\Core\Domain\MemberPhotoService;
use KompanieApp\Core\Domain\InventoryService;
use KompanieApp\Core\Domain\OfficeService;
use KompanieApp\Core\Domain\ListService;
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
use KompanieApp\Core\Storage\MemberRoleRepository;
use KompanieApp\Core\Storage\PenaltyRepository;
use KompanieApp\Core\Storage\PointRepository;
use KompanieApp\Core\Storage\Sql\Connection;
use KompanieApp\Core\Storage\Sql\Migrator;
use KompanieApp\Core\Storage\UserData;
use KompanieApp\Core\Telemetry\Code;
use KompanieApp\Core\Telemetry\Detail;
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
        BoardService::TASK => 3600,
        PollService::TASK => 3600,
        BirthdayService::TASK => 3600,
        BugReportService::TASK => BugReportService::INTERVAL,
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

    /** Adressen und Eckdaten; Adressen geschützter Dateien tragen in der Vorschau deren Parameter (PreviewEnvironment). */
    public readonly Environment $env;

    /** Wer die Anfrage stellt – in der Vorschau die gewählte Rolle (Auth\Preview); ohne Vorschau der Adapter selbst. */
    public readonly AuthContext $authContext;

    private readonly Preview $preview;

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
        Environment $env,
        AuthContext $authContext,
        public readonly Authenticator $authenticator,
        public readonly UserDirectory $users,
        public readonly KompanieConfig $config,
        public readonly ?UpdateTarget $updateTarget = null,
        public readonly ?BackupProvider $backup = null,
        public readonly ?HealthChecks $healthChecks = null,
        /** @var array<string, mixed> update.json des Pakets: updatesUrl, trustedKeys, statusUrl, kompanie, support */
        public readonly array $packageConfig = [],
    ) {
        // Vorschau „App als Rolle ansehen“ (Issue #63): Wer die Anfrage stellt und die Adressen geschützter Dateien gehen
        // über die Vorschau – so gilt sie überall gleich, ohne dass Dienste oder Adapter sie kennen müssen.
        $this->preview = new Preview($authContext, fn (): RoleService => $this->roles());
        $this->authContext = $this->preview;
        $this->env = new PreviewEnvironment($env, $this->preview);
    }

    /** Vorschau „App als Rolle ansehen“ der laufenden Anfrage (Issue #63). */
    public function preview(): Preview
    {
        return $this->preview;
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
            // Schwarzes Brett: abgelaufene Nachrichten stündlich löschen (die API liefert sie ohnehin nicht mehr).
            BoardService::TASK => $this->features()->enabled('board'),
            // Umfragen: stündlich prüfen, ob eine in höchstens 24 Stunden endet (Erinnerung, einmal je Umfrage).
            PollService::TASK => $this->features()->enabled('polls'),
            // Geburtstage: stündlich, benachrichtigt aber erst ab der eingestellten Stunde (Standard 7 Uhr) Ortszeit und je Geburtstag einmal am Tag.
            BirthdayService::TASK => $this->features()->enabled('birthdays'),
            // Fehlermeldungen (Issue #64): stündlich als Rückfall, nach dem Einreihen vorgezogen; nur mit Empfänger.
            BugReportService::TASK => $this->bugReports()->available(),
        ];
        foreach ($wanted as $task => $on) {
            $on ? $this->scheduler->schedule($task, self::TASKS[$task]) : $this->scheduler->unschedule($task);
        }
        $version = $this->env->version();
        if ($this->settings->get(self::PLAN_SETTING) !== $version) {
            $this->settings->set(self::PLAN_SETTING, $version);
        }
        // Schlüssel für Web Push gleich anlegen, nicht erst bei der ersten (womöglich gleichzeitigen) Anfrage. Scheitert
        // das (z. B. OpenSSL ohne nutzbare Konfiguration), darf das Einplanen der übrigen Aufgaben nicht scheitern.
        try {
            $this->push()->publicKey();
        } catch (\Throwable $e) {
            error_log('Kompanie-App: Schlüssel für Benachrichtigungen nicht erzeugt: ' . $e->getMessage());
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
            // Wartungsmodus (Issue #42): alles außer Updates und Statusmeldungen wartet; die Push-Warteschlange
            // bleibt liegen und wird nach dem Ende verschickt.
            if (!MaintenanceService::runsDuringMaintenance($task) && $this->maintenance()->active()) {
                return;
            }
            if ($task === GalleryService::TASK && $this->features()->enabled('gallery')) {
                $this->gallery()->runTask(25);
                $error = (string) ($this->gallery()->status()['error'] ?? '');
                if ($error !== '') {
                    $this->telemetry()->record(Code::GallerySync, Detail::fromMessage($error), $error);
                }
            } elseif ($task === GalleryService::PROCESS_TASK && $this->features()->enabled('gallery')) {
                $this->gallery()->runProcessTask(25);
            } elseif ($task === CalendarService::TASK && $this->features()->enabled('calendar')) {
                $error = $this->calendar()->refreshIfStale();
                if ($error !== null) {
                    $this->telemetry()->record(Code::CalendarFetch, Detail::of($this->calendar()->source(), Detail::fromMessage($error)), $error);
                }
            } elseif ($task === UpdateService::TASK) {
                $this->updates()->runTask();
            } elseif ($task === StatusReporter::TASK) {
                $this->statusReporter()->runTask();
            } elseif ($task === PushService::TASK_SEND) {
                $this->push()->processQueue();
            } elseif ($task === PushService::TASK_WATCH) {
                $this->pushTriggers()->watch();
            } elseif ($task === BoardService::TASK) {
                $this->board()->cleanup();
            } elseif ($task === PollService::TASK && $this->features()->enabled('polls')) {
                $this->polls()->remind();
            } elseif ($task === BirthdayService::TASK && $this->features()->enabled('birthdays')) {
                $this->birthdays()->notifyToday();
            } elseif ($task === BugReportService::TASK) {
                $this->bugReports()->runTask();
            }
        } catch (\Throwable $e) {
            error_log('Kompanie-App: Aufgabe ' . $task . ' fehlgeschlagen: ' . $e->getMessage());
            // Die Statusmeldung selbst nicht zählen (sonst meldete sie beim nächsten Mal ihr eigenes Scheitern),
            // Fehler der Update-Liste zählt UpdateService schon.
            // Ebenso nicht zugestellte Fehlermeldungen (das Cockpit war nicht erreichbar, kein Fehler der App).
            if (!in_array($task, [StatusReporter::TASK, BugReportService::TASK], true) && !($task === UpdateService::TASK && $e instanceof ApiError)) {
                $this->telemetry()->record(Code::TaskFailed, Detail::of($task, Detail::className($e)), Detail::describe($e));
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
     * Schwere PHP-Fehler in der App als Fehlercode zählen (in der Statusmeldung nur Code und Ursache, der Text
     * nur im Fehlerprotokoll). Ruft der Adapter
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
     * @param array{type?: int, file?: string, line?: int, message?: string} $error wie error_get_last()
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
            $message = (string) ($error['message'] ?? '');
            $kind = match (true) {
                str_contains($message, 'Allowed memory size') => 'memory',
                str_contains($message, 'Maximum execution time') => 'time_limit',
                default => pathinfo((string) $error['file'], PATHINFO_FILENAME),
            };
            $type = match ($error['type']) {
                E_PARSE => 'parse',
                E_COMPILE_ERROR => 'compile',
                default => 'error',
            };
            $this->telemetry()->record(Code::PhpFatal, Detail::of($type, $kind), $message . ' (' . basename((string) $error['file']) . ':' . (int) ($error['line'] ?? 0) . ')');
        } catch (\Throwable) {
        }
    }

    public function push(): PushService
    {
        return $this->service(PushService::class, fn () => new PushService($this));
    }

    /**
     * Ein Konto wurde gelöscht (in der App oder bei WordPress im Backend): alle eigenen Daten entfernen (Issue #24,
     * Löschung nach DSGVO): Punkte, Strafen, Anwesenheit, Dienstgrad mit Verlauf, Titel, Zusatzrollen, Push-Abos. Was die Person für
     * andere eingetragen hat, bleibt („eingetragen von: gelöschtes Konto“), ebenso ihre Nachrichten am Schwarzen Brett
     * (höchstens 24 Stunden, Verfasser dann „gelöschtes Konto“). Bei Umfragen verschwinden Teilnahme und Antworten, auch
     * anonyme (über das Pseudonym); die Umfragen, die sie angelegt hat, bleiben. Ihr Geburtstag und ihre Ausnahmen bei
     * den Rechten werden gelöscht, ebenso noch nicht versandte Fehlermeldungen mit ihrem Namen. Inventar und sein Verlauf
     * bleiben (dort steht nur, wer etwas geändert hat; angezeigt dann als „gelöschtes Konto“).
     */
    public function userDeleted(int $userId): void
    {
        UserData::purge($this->db, $userId, PollService::existingSecret($this->settings));
        $this->roles()->forget($userId);
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

    public function ranks(): RankService
    {
        return $this->service(RankService::class, fn () => new RankService($this));
    }

    public function documents(): DocumentService
    {
        return $this->service(DocumentService::class, fn () => new DocumentService($this));
    }

    public function attendance(): AttendanceService
    {
        return $this->service(AttendanceService::class, fn () => new AttendanceService($this));
    }

    public function board(): BoardService
    {
        return $this->service(BoardService::class, fn () => new BoardService($this));
    }

    public function polls(): PollService
    {
        return $this->service(PollService::class, fn () => new PollService($this));
    }

    public function birthdays(): BirthdayService
    {
        return $this->service(BirthdayService::class, fn () => new BirthdayService($this));
    }

    public function joinYears(): JoinYearService
    {
        return $this->service(JoinYearService::class, fn () => new JoinYearService($this));
    }

    public function memberPhotos(): MemberPhotoService
    {
        return $this->service(MemberPhotoService::class, fn () => new MemberPhotoService($this));
    }

    public function inventory(): InventoryService
    {
        return $this->service(InventoryService::class, fn () => new InventoryService($this));
    }

    public function titles(): TitleService
    {
        return $this->service(TitleService::class, fn () => new TitleService($this));
    }

    public function offices(): OfficeService
    {
        return $this->service(OfficeService::class, fn () => new OfficeService($this));
    }

    public function lists(): ListService
    {
        return $this->service(ListService::class, fn () => new ListService($this));
    }

    public function members(): MemberService
    {
        return $this->service(MemberService::class, fn () => new MemberService(
            $this->users,
            $this->scores(),
            $this->authenticator,
            $this->cache,
            $this->roles(),
            fn (User $user): ?string => $this->formerMembers()->previousRole($user),
        ));
    }

    public function formerMembers(): FormerMemberService
    {
        return $this->service(FormerMemberService::class, fn () => new FormerMemberService($this));
    }

    public function roles(): RoleService
    {
        return $this->service(RoleService::class, fn () => new RoleService($this->settings, $this->config, $this->users, new MemberRoleRepository($this->db), $this->time()));
    }

    public function calendar(): CalendarService
    {
        return $this->service(CalendarService::class, fn () => new CalendarService($this->settings, $this->http, $this->cache, $this->time(), $this->drive()));
    }

    public function drive(): DriveClient
    {
        return $this->service(DriveClient::class, fn () => new DriveClient($this->settings, $this->http, $this->cache, $this->files, $this->time()));
    }

    /** „Mit Google verbinden“ (Beginn, Rückkehr von Google, Trennen) */
    public function googleConnect(): GoogleConnect
    {
        return $this->service(GoogleConnect::class, fn () => new GoogleConnect($this));
    }

    public function maintenance(): MaintenanceService
    {
        return $this->service(MaintenanceService::class, fn () => new MaintenanceService($this));
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

    /** Fehlermeldungen an die Betreuer der App (Issue #64) */
    public function bugReports(): BugReportService
    {
        return $this->service(BugReportService::class, fn () => new BugReportService($this));
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
