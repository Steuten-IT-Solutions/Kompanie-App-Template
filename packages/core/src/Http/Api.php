<?php

declare(strict_types=1);

namespace KompanieApp\Core\Http;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability as Cap;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Domain\EventService;
use KompanieApp\Core\Domain\Input;
use KompanieApp\Core\Domain\StatusService;
use KompanieApp\Core\Push\PushService;
use KompanieApp\Core\Storage\Sql\DatabaseException;
use KompanieApp\Core\Telemetry\Code;
use KompanieApp\Core\Update\StatusReporter;

/**
 * Die Routentabelle der API (siehe api/openapi.yaml) und ihre Ausführung.
 * Beide Adapter lesen dieselbe Tabelle, damit sie sich nicht auseinanderentwickeln.
 *
 * Grundsätze:
 * - Die /me-Routen lesen die Benutzer-ID immer aus der Sitzung, nie aus der Anfrage.
 * - Ist ein Bereich ausgeschaltet (Domain\Features), antworten seine Routen mit 403 feature_disabled.
 */
final class Api
{
    public const NEWS_PER_PAGE = 10;
    /** Der Einrichtungsassistent wurde abgeschlossen oder übersprungen. */
    public const SETUP_SETTING = 'setup_done';

    /** @var Route[]|null */
    private ?array $routes = null;

    public function __construct(private readonly App $app)
    {
    }

    /** @return Route[] */
    public function routes(): array
    {
        return $this->routes ??= $this->build();
    }

    /** Sucht die passende Route und führt sie aus. */
    public function handle(Request $request): Response
    {
        $path = '/' . trim($request->path, '/');
        $pathMatched = false;
        foreach ($this->routes() as $route) {
            $params = $route->match($request->method, $path);
            if ($params !== null) {
                $request->params = $params;
                return $this->dispatch($route, $request);
            }
            $pathMatched = $pathMatched || preg_match($route->regex(), $path) === 1;
        }
        return $pathMatched
            ? Response::error(new ApiError(405, 'method_not_allowed', 'Diese Aktion gibt es hier nicht.'))
            : Response::error(ApiError::notFound('Unbekannte Adresse.'));
    }

    /** Prüft Bereich und Rechte und führt eine Route aus. Fehler werden zu Fehlerantworten. */
    public function dispatch(Route $route, Request $request): Response
    {
        try {
            if ($route->feature !== null && !$this->app->features()->enabled($route->feature)) {
                throw new ApiError(403, 'feature_disabled', 'Dieser Bereich ist ausgeschaltet.');
            }
            $this->authorize($route);
            $result = ($route->handler)($request);
            return $result instanceof Response ? $result : new Response($result);
        } catch (ApiError $e) {
            return Response::error($e);
        } catch (\Throwable $e) {
            error_log('Kompanie-App: ' . $e::class . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            // Nur der Code, nie der Text (Statusmeldungen, nur mit Zustimmung).
            $this->app->telemetry()->record($e instanceof \PDOException || $e instanceof DatabaseException ? Code::DbError : Code::ServerError);
            return Response::error(new ApiError(500, 'internal_error', 'Es ist ein Fehler aufgetreten. Bitte versuche es später erneut.'));
        }
    }

    /**
     * Folgen einer Aktion (z. B. Benachrichtigungen) dürfen sie nie scheitern lassen.
     *
     * @param \Closure(): mixed $fn
     */
    private function notify(\Closure $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            error_log('Kompanie-App: Benachrichtigung nicht eingereiht: ' . $e->getMessage());
        }
    }

    /**
     * Verfügbare Typen und das Abo dieses Geräts (Endpoint im Inhalt, nicht in der Adresse).
     *
     * @return array<string, mixed>
     */
    private function pushStatus(Request $r, ?User $user): array
    {
        $push = $this->app->push();
        $sub = null;
        if ($r->input('endpoint') !== null) {
            // Die App fragt beim Start nach: Abos eines Kontos, das hier nicht mehr angemeldet ist, fallen dann weg.
            $push->releaseForeign($user, $r->input('endpoint'), $r->input('auth'));
            try {
                $sub = $push->own($user, $r->input('endpoint'), $user === null ? $r->input('auth') : null);
                $push->seen($sub); // Die App wird auf dem Gerät noch genutzt: Gast-Abo verfällt nicht.
            } catch (ApiError) {
                $sub = null;
            }
        }
        $labels = $push->types();
        return [
            'supported' => $push->supported(),
            'publicKey' => $push->publicKey(),
            'types' => array_map(static fn (string $k): array => ['key' => $k, 'label' => $labels[$k]['label']], $push->availableTypes($user)),
            'subscription' => PushService::formatSubscription($sub),
        ];
    }

    private function authorize(Route $route): void
    {
        if ($route->access === Route::PUBLIC) {
            return;
        }
        $user = $this->app->authContext->currentUser();
        if ($user === null) {
            throw ApiError::notLoggedIn();
        }
        $needed = $route->access instanceof Cap ? [$route->access] : (is_array($route->access) ? $route->access : []);
        if ($needed !== [] && !array_filter($needed, static fn (Cap $c): bool => $user->can($c))) {
            throw ApiError::forbidden($route->denyMessage);
        }
    }

    private function user(): User
    {
        return $this->app->authContext->currentUser() ?? throw ApiError::notLoggedIn();
    }

    private function can(Cap $cap): bool
    {
        return $this->app->authContext->currentUser()?->can($cap) ?? false;
    }

    /** Laufzeit-Konfiguration für die App. */
    public function config(): array
    {
        $a = $this->app;
        // Für die App: an und eingerichtet (ein Kalender ohne Adresse hätte nichts zu zeigen).
        $features = [];
        foreach ($a->features()->all() as $key => $on) {
            $features[$key] = $on && $a->features()->configured($key);
        }
        return [
            'apiBase' => $a->env->apiBase(),
            'appUrl' => $a->env->appUrl(),
            'swUrl' => $a->env->serviceWorkerUrl(),
            'siteUrl' => $a->env->siteUrl(),
            'variant' => $a->env->variant(),
            'version' => $a->env->version(),
            'csrf' => [
                'header' => $a->authenticator->csrfHeader(),
                'token' => $a->authenticator->csrfToken(),
                'refreshUrl' => $a->authenticator->csrfRefreshUrl(),
            ],
            'auth' => [
                'mode' => $a->authenticator->mode(),
                'loginUrl' => $a->authenticator->loginUrl(),
                'logoutUrl' => $a->authenticator->logoutUrl(),
            ],
            'features' => $features,
            // Handbuch auf GitHub Pages der Kompanie: neben updates.json (Issue #12)
            'handbookUrl' => self::handbookUrl((string) ($a->packageConfig['updatesUrl'] ?? '')),
            // Bezeichnungen und Punktefaktor der Rollen (vom Admin in der App festgelegt)
            'roles' => $a->roles()->publicList(),
            'user' => $a->authContext->currentUser()?->toArray(),
            // Nur für Admins: Einrichtungsassistent beim ersten Öffnen anbieten.
            'setupPending' => $this->can(Cap::ManageSettings) && !(bool) $a->settings->get(self::SETUP_SETTING, false),
        ];
    }

    /** https://…/updates.json → https://…/handbuch/ (dort liegt die Seite der Kompanie); sonst null. */
    public static function handbookUrl(string $updatesUrl): ?string
    {
        return preg_match('#^(https://[^?\#]+/)updates\.json$#', trim($updatesUrl), $m) ? $m[1] . 'handbuch/' : null;
    }

    /** @return Route[] */
    private function build(): array
    {
        $a = $this->app;
        $P = Route::PUBLIC;
        $L = Route::LOGIN;
        $denyMember = 'Dieser Bereich ist nur für Mitglieder.';
        $denyManage = 'Dafür fehlen dir die Rechte.';
        $denyEvents = 'Nur Administratoren dürfen öffentliche Termine pflegen.';
        $denyUsers = 'Nur Administratoren dürfen Mitglieder anlegen.';
        $denyRoles = 'Nur Administratoren dürfen Rollen ändern.';
        // Mitgliederliste: wer Punkte oder Strafen vergibt, Mitglieder anlegt oder Rollen vergibt
        // (Punkte bzw. offene Beträge nur mit dem jeweiligen Recht, siehe MemberService)
        $manage = [Cap::ManagePoints, Cap::ManagePenalties, Cap::CreateUsers, Cap::PromoteUsers];
        $denySettings = 'Nur Administratoren dürfen Einstellungen ändern.';

        return [
            // App und Anmeldung
            new Route('GET', '/config', $P, fn () => $this->config()),
            new Route('GET', '/auth/csrf', $L, fn () => ['token' => $a->authenticator->csrfToken()]),
            new Route('POST', '/auth/login', $P, function (Request $r) use ($a) {
                $a->authenticator->login(
                    is_string($r->input('username')) ? trim($r->input('username')) : '',
                    is_string($r->input('password')) ? $r->input('password') : '',
                    $r->input('remember') === null ? true : Input::bool($r->input('remember')),
                );
                return $this->config();
            }),
            new Route('POST', '/auth/logout', $L, function () use ($a) {
                $a->authenticator->logout();
                return ['logged_out' => true];
            }),

            // Eigene Daten
            new Route('GET', '/me', $L, fn () => $a->members()->me($this->user())),
            new Route('GET', '/me/points', Cap::Member, fn () => $a->scores()->pointsFor($this->user()->id), 'points', $denyMember),
            new Route('GET', '/me/penalties', Cap::Member, fn () => $a->scores()->penaltiesFor($this->user()->id), 'penalties', $denyMember),
            new Route('POST', '/me/password', $L, function (Request $r) use ($a) {
                $a->members()->changeOwnPassword($this->user(), $r->input('current_password'), $r->input('new_password'));
                return ['changed' => true];
            }),
            // Web Push (Issue #19): Konten über /me/push, Gäste über /push (Ändern und Löschen nur mit dem auth-Geheimnis).
            new Route('GET', '/push/key', $P, fn () => ['supported' => $a->push()->supported(), 'publicKey' => $a->push()->publicKey()]),
            new Route('POST', '/me/push/status', $L, fn (Request $r) => $this->pushStatus($r, $this->user())),
            new Route('POST', '/push/status', $P, fn (Request $r) => $this->pushStatus($r, null)),
            new Route('POST', '/me/push', $L, fn (Request $r) => PushService::formatSubscription($a->push()->subscribe(
                $this->user(), $r->input('endpoint'), $r->input('keys'), $r->input('types'), $r->input('details'), $r->client,
            ))),
            new Route('POST', '/push', $P, fn (Request $r) => PushService::formatSubscription($a->push()->subscribe(
                null, $r->input('endpoint'), $r->input('keys'), $r->input('types'), $r->input('details'), $r->client,
            ))),
            new Route('PUT', '/me/push', $L, fn (Request $r) => PushService::formatSubscription($a->push()->update($this->user(), $r->input('endpoint'), null, $r->input('types'), $r->input('details')))),
            new Route('PUT', '/push', $P, fn (Request $r) => PushService::formatSubscription($a->push()->update(null, $r->input('endpoint'), $r->input('auth'), $r->input('types'), null))),
            new Route('DELETE', '/me/push', $L, fn (Request $r) => ['deleted' => $a->push()->unsubscribe($this->user(), $r->input('endpoint'), null)]),
            new Route('DELETE', '/push', $P, fn (Request $r) => ['deleted' => $a->push()->unsubscribe(null, $r->input('endpoint'), $r->input('auth'))]),
            new Route('POST', '/me/push/test', $L, function (Request $r) use ($a) {
                $a->push()->sendTest($a->push()->own($this->user(), $r->input('endpoint'), null));
                return ['sent' => true];
            }),
            new Route('POST', '/push/test', $P, function (Request $r) use ($a) {
                $a->push()->sendTest($a->push()->own(null, $r->input('endpoint'), $r->input('auth')));
                return ['sent' => true];
            }),
            new Route('GET', '/calendar', Cap::Member, fn () => $a->calendar()->events(), 'calendar', $denyMember),

            // Öffentliche Termine
            new Route('GET', '/events', $P, fn () => $a->events()->upcoming(), 'events'),
            new Route('POST', '/events', Cap::ManageEvents, function (Request $r) use ($a) {
                $event = $a->events()->create(EventService::input($r->body), $this->user()->id);
                $this->notify(static fn () => $a->pushTriggers()->publicEventSaved($event));
                return Response::created($event);
            }, 'events', $denyEvents),
            new Route('PATCH', '/events/{id}', Cap::ManageEvents, function (Request $r) use ($a) {
                $before = $a->events()->get($r->id());
                $event = $a->events()->update($r->id(), EventService::input($r->body));
                $this->notify(static fn () => $a->pushTriggers()->publicEventSaved($event, $before));
                return $event;
            }, 'events', $denyEvents),
            new Route('DELETE', '/events/{id}', Cap::ManageEvents, function (Request $r) use ($a) {
                $a->events()->delete($r->id());
                return ['deleted' => true];
            }, 'events', $denyEvents),

            // Galerie
            new Route('GET', '/gallery', $P, fn () => ['years' => $a->gallery()->overview($this->can(Cap::Member))], 'gallery'),
            new Route('GET', '/gallery/{id}', $P, fn (Request $r) => $a->gallery()->album($r->id(), $this->can(Cap::Member))
                ?? throw ApiError::notFound('Album nicht gefunden.'), 'gallery'),

            // Neuigkeiten
            new Route('GET', '/news', $P, function (Request $r) use ($a) {
                $page = max(1, (int) ($r->query['page'] ?? 1));
                $result = $a->news->available() ? $a->news->page($page, self::NEWS_PER_PAGE) : ['items' => [], 'totalPages' => 0];
                return new Response($result['items'], 200, ['X-Total-Pages' => (string) $result['totalPages']]);
            }, 'news'),
            new Route('GET', '/news/{key}', $P, fn (Request $r) => ($a->news->available() ? $a->news->item((string) $r->params['key']) : null)
                ?? throw ApiError::notFound('Beitrag nicht gefunden.'), 'news'),

            // Punktekatalog
            new Route('GET', '/catalog', $L, fn (Request $r) => $a->scores()->catalog($r->queryFlag('all') && $this->can(Cap::ManagePoints)), 'points'),
            new Route('POST', '/catalog', Cap::ManagePoints, fn (Request $r) => Response::created($a->scores()->createCatalogItem($r->body)), 'points', $denyManage),
            new Route('PATCH', '/catalog/{id}', Cap::ManagePoints, fn (Request $r) => $a->scores()->updateCatalogItem($r->id(), $r->body), 'points', $denyManage),

            // Mitglieder
            new Route('GET', '/members', $manage, fn (Request $r) => $a->members()->list($r->queryFlag('all') && $this->can(Cap::PromoteUsers), $this->user()), null, $denyManage),
            new Route('GET', '/members/{id}', $manage, fn (Request $r) => $a->members()->detail($r->id(), $this->user()), null, $denyManage),
            new Route('PATCH', '/members/{id}/role', Cap::PromoteUsers, fn (Request $r) => $a->members()->setLevel($this->user(), $r->id(), $r->input('level')), null, $denyRoles),
            new Route('POST', '/members/{id}/password', Cap::CreateUsers, fn (Request $r) => $a->members()->resetPassword($this->user(), $r->id()), null, $denyUsers),
            new Route('POST', '/users', Cap::CreateUsers, fn (Request $r) => Response::created($a->members()->create($r->body)), null, $denyUsers),

            // Einstellungen (Admins)
            new Route('GET', '/admin/settings', Cap::ManageSettings, fn () => $a->settingsService()->get(), null, $denySettings),
            // Push (Issue #19): Voraussetzungen, Zahl der Abos, Uhrzeit der Erinnerung
            new Route('GET', '/admin/push', Cap::ManageSettings, fn () => $a->push()->adminStatus(), null, $denySettings),
            new Route('PATCH', '/admin/push', Cap::ManageSettings, function (Request $r) use ($a) {
                if ($r->has('reminder_hour')) {
                    $a->push()->setReminderHour($r->input('reminder_hour'));
                }
                return $a->push()->adminStatus();
            }, null, $denySettings),
            new Route('PATCH', '/admin/settings', Cap::ManageSettings, fn (Request $r) => $a->settingsService()->update($r->body), null, $denySettings),
            // Rollen (Issue #28): nur Admins (manage_settings lässt sich keiner anderen Rolle geben)
            new Route('GET', '/admin/roles', Cap::ManageSettings, fn () => $a->roles()->describe(), null, $denySettings),
            new Route('POST', '/admin/roles', Cap::ManageSettings, function (Request $r) use ($a) {
                $a->roles()->create($r->body);
                return Response::created($a->roles()->describe());
            }, null, $denySettings),
            new Route('PATCH', '/admin/roles/{key}', Cap::ManageSettings, function (Request $r) use ($a) {
                $a->roles()->update((string) $r->params['key'], $r->body);
                return $a->roles()->describe();
            }, null, $denySettings),
            new Route('DELETE', '/admin/roles/{key}', Cap::ManageSettings, function (Request $r) use ($a) {
                $replacement = $r->query['replacement'] ?? null;
                $a->roles()->delete((string) $r->params['key'], is_string($replacement) ? $replacement : null);
                return $a->roles()->describe();
            }, null, $denySettings),
            new Route('GET', '/admin/features', Cap::ManageSettings, fn () => $a->features()->describe(), null, $denySettings),
            new Route('PUT', '/admin/features', Cap::ManageSettings, function (Request $r) use ($a) {
                $a->features()->update($r->body);
                return $a->features()->describe();
            }, null, $denySettings),
            new Route('POST', '/admin/calendar/test', Cap::ManageSettings, fn () => $a->settingsService()->testCalendar(), null, $denySettings),
            new Route('POST', '/admin/news/test', Cap::ManageSettings, fn () => $a->settingsService()->testNews(), null, $denySettings),
            new Route('POST', '/admin/gallery/sync', Cap::ManageSettings, fn (Request $r) => $a->settingsService()->syncGallery(Input::bool($r->input('only_process'))), null, $denySettings),

            // Status, Updates, Datensicherung, Einrichtung (Admins)
            new Route('GET', '/admin/status', Cap::ManageSettings, fn () => $a->status()->report() + [
                'updates' => $a->updates()->status(),
                'statusReports' => ['enabled' => (bool) $a->settings->get(StatusReporter::SETTING, false), 'available' => (string) ($a->packageConfig['statusUrl'] ?? '') !== '', 'payload' => $a->statusReporter()->payload(), 'last' => $a->statusReporter()->last()],
                'setupDone' => (bool) $a->settings->get(self::SETUP_SETTING, false),
            ], null, $denySettings),
            new Route('POST', '/admin/update/check', Cap::ManageSettings, function () use ($a) {
                $a->updates()->check();
                return $a->updates()->status();
            }, null, $denySettings),
            new Route('POST', '/admin/update/install', Cap::ManageSettings, function (Request $r) use ($a) {
                $a->updates()->install(is_string($r->input('version')) ? $r->input('version') : '', 'manual', Input::bool($r->input('major')));
                return ['installed' => $r->input('version')];
            }, null, $denySettings),
            new Route('POST', '/admin/update/rollback', Cap::ManageSettings, function (Request $r) use ($a) {
                $a->updates()->rollback(is_string($r->input('version')) ? $r->input('version') : '');
                return ['installed' => $r->input('version')];
            }, null, $denySettings),
            new Route('PATCH', '/admin/update', Cap::ManageSettings, function (Request $r) use ($a) {
                if ($r->has('auto')) {
                    $a->updates()->setAuto(Input::bool($r->input('auto')));
                }
                if ($r->has('status_reports')) {
                    $on = Input::bool($r->input('status_reports'));
                    $a->settings->set(StatusReporter::SETTING, $on);
                    if (!$on) {
                        // Ohne Zustimmung auch keine Fehlerzähler mehr aufheben.
                        $a->telemetry()->forget();
                    }
                }
                $a->scheduleTasks();
                return $a->updates()->status();
            }, null, $denySettings),
            new Route('GET', '/admin/backup', Cap::ManageSettings, function () use ($a) {
                $backup = $a->backup ?? throw new ApiError(404, 'not_available', 'Die Datensicherung gibt es in dieser Installation nicht.');
                $data = $backup->export();
                $a->settings->set(StatusService::BACKUP_SETTING, $a->time()->timestamp());
                $name = 'sicherung-' . $a->time()->format($a->time()->timestamp(), 'Y-m-d') . '.json';
                return new Response($data, 200, ['Content-Disposition' => 'attachment; filename="' . $name . '"', 'Cache-Control' => 'no-store']);
            }, null, $denySettings),
            new Route('POST', '/admin/backup', Cap::ManageSettings, function (Request $r) use ($a) {
                $backup = $a->backup ?? throw new ApiError(404, 'not_available', 'Die Datensicherung gibt es in dieser Installation nicht.');
                if (!$backup->canImport()) {
                    throw new ApiError(404, 'not_available', 'Eine Sicherung lässt sich hier nicht wiederherstellen.');
                }
                if (!Input::bool($r->input('confirm')) || !is_string($r->input('backup'))) {
                    throw ApiError::invalid('Bitte die Sicherung auswählen und das Ersetzen aller Daten bestätigen.');
                }
                try {
                    $backup->import($r->input('backup'));
                } catch (\RuntimeException $e) {
                    throw $e instanceof ApiError ? $e : ApiError::invalid($e->getMessage());
                }
                return ['restored' => true];
            }, null, $denySettings),
            new Route('POST', '/admin/setup/done', Cap::ManageSettings, function () use ($a) {
                $a->settings->set(self::SETUP_SETTING, true);
                return ['done' => true];
            }, null, $denySettings),

            // Punkte und Strafen
            new Route('POST', '/points', Cap::ManagePoints, function (Request $r) use ($a) {
                $rows = $a->scores()->givePoints($r->body, $this->user()->id);
                $this->notify(static fn () => $a->pushTriggers()->pointsAdded($rows));
                return Response::created($rows);
            }, 'points', $denyManage),
            new Route('DELETE', '/points/{id}', Cap::ManagePoints, function (Request $r) use ($a) {
                $a->scores()->cancelPoints($r->id(), $this->user()->id);
                return ['deleted' => true];
            }, 'points', $denyManage),
            new Route('POST', '/penalties', Cap::ManagePenalties, function (Request $r) use ($a) {
                $rows = $a->scores()->givePenalty($r->body, $this->user()->id);
                $this->notify(static fn () => $a->pushTriggers()->penaltiesAdded($rows));
                return Response::created($rows);
            }, 'penalties', $denyManage),
            new Route('PATCH', '/penalties/{id}', Cap::ManagePenalties, fn (Request $r) => $a->scores()->setPenaltyStatus($r->id(), $r->input('status'), $this->user()->id), 'penalties', $denyManage),
            new Route('DELETE', '/penalties/{id}', Cap::ManagePenalties, function (Request $r) use ($a) {
                $a->scores()->cancelPenalty($r->id(), $this->user()->id);
                return ['deleted' => true];
            }, 'penalties', $denyManage),
        ];
    }
}
