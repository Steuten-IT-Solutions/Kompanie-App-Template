<?php

declare(strict_types=1);

namespace KompanieApp\Core;

use KompanieApp\Core\Config\KompanieConfig;
use KompanieApp\Core\Domain\CalendarService;
use KompanieApp\Core\Domain\DriveClient;
use KompanieApp\Core\Domain\EventService;
use KompanieApp\Core\Domain\GalleryService;
use KompanieApp\Core\Domain\MemberService;
use KompanieApp\Core\Domain\ScoreService;
use KompanieApp\Core\Domain\Time;
use KompanieApp\Core\Domain\UserNames;
use KompanieApp\Core\Http\Api;
use KompanieApp\Core\Port\AuthContext;
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
use KompanieApp\Core\Storage\CatalogRepository;
use KompanieApp\Core\Storage\EventRepository;
use KompanieApp\Core\Storage\GalleryRepository;
use KompanieApp\Core\Storage\PenaltyRepository;
use KompanieApp\Core\Storage\PointRepository;
use KompanieApp\Core\Storage\Sql\Connection;
use KompanieApp\Core\Storage\Sql\Migrator;

/**
 * Setzt den Kern aus den Umsetzungen der Ports zusammen, die ein Adapter liefert.
 * Dienste werden erst bei Bedarf erzeugt.
 */
final class App
{
    /** Wiederkehrende Aufgaben: Name => Abstand in Sekunden */
    public const TASKS = [GalleryService::TASK => 3600];

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
    ) {
    }

    public function migrator(): Migrator
    {
        return new Migrator($this->db, $this->settings);
    }

    /** Ausstehende Migrationen ausführen und Aufgaben einplanen. */
    public function upgrade(): void
    {
        $migrator = $this->migrator();
        if ($migrator->pending()) {
            $migrator->migrate();
        }
    }

    /** Plant die wiederkehrenden Aufgaben ein bzw. aus, je nach Einrichtung. */
    public function scheduleTasks(): void
    {
        if ($this->config->featureEnabled('gallery') && $this->gallery()->isConfigured()) {
            $this->scheduler->schedule(GalleryService::TASK, self::TASKS[GalleryService::TASK]);
        } else {
            $this->scheduler->unschedule(GalleryService::TASK);
        }
    }

    /** Führt eine geplante Aufgabe aus. Fehler werden protokolliert, nicht weitergeworfen. */
    public function runTask(string $task): void
    {
        try {
            if ($task === GalleryService::TASK && $this->config->featureEnabled('gallery')) {
                $this->gallery()->runTask(25);
            }
        } catch (\Throwable $e) {
            error_log('Kompanie-App: Aufgabe ' . $task . ' fehlgeschlagen: ' . $e->getMessage());
        }
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
        ));
    }

    public function members(): MemberService
    {
        return $this->service(MemberService::class, fn () => new MemberService($this->users, $this->scores(), $this->authenticator));
    }

    public function calendar(): CalendarService
    {
        return $this->service(CalendarService::class, fn () => new CalendarService($this->settings, $this->http, $this->cache, $this->time()));
    }

    public function drive(): DriveClient
    {
        return $this->service(DriveClient::class, fn () => new DriveClient($this->settings, $this->http, $this->cache, $this->files, $this->time()));
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
