<?php

declare(strict_types=1);

namespace KompanieApp\Core\Http;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability as Cap;
use KompanieApp\Core\Auth\Roles;
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

    public const BULK_MAX = 50;

    /**
     * Mehrere Konten anlegen: alle Einträge vorab prüfen (Namen, Benutzernamen und E-Mail auch untereinander,
     * Anfangspunkte, Dienstgrad); bei einem Fehler wird keiner angelegt und die Meldung nennt die Zeile. Scheitert danach
     * doch eines (z. B. Datenbank), steht es mit Grund im Ergebnis.
     *
     * @return list<array<string, mixed>>
     */
    private function createUsers(mixed $level, mixed $people): array
    {
        $level = $level ?? Roles::MEMBER;
        if (!is_array($people) || $people === []) {
            throw ApiError::invalid('Bitte mindestens eine Person eintragen.');
        }
        if (count($people) > self::BULK_MAX) {
            throw ApiError::invalid(sprintf('Höchstens %d Personen auf einmal.', self::BULK_MAX));
        }
        $taken = [];
        $plans = [];
        foreach (array_values($people) as $i => $p) {
            $p = is_array($p) ? $p : [];
            try {
                $prepared = $this->app->members()->prepare(['level' => $level] + $p, $taken);
                $initial = $this->app->ranks()->prepareInitial($this->user(), $p);
                if (($initial['points'] > 0 || $initial['rank'] !== null) && $level !== Roles::MEMBER) {
                    throw ApiError::invalid('Anfangspunkte und Dienstgrad gibt es nur für Mitglieder.');
                }
            } catch (ApiError $e) {
                $who = is_string($p['name'] ?? null) && trim($p['name']) !== '' ? ' (' . trim($p['name']) . ')' : '';
                throw new ApiError($e->status, $e->errorCode, sprintf('Zeile %d%s: %s', $i + 1, $who, $e->getMessage()));
            }
            $plans[] = [$prepared, $initial];
        }
        $out = [];
        foreach ($plans as [$prepared, $initial]) {
            try {
                $created = $this->app->members()->insert($prepared);
            } catch (\Throwable $e) {
                $out[] = ['name' => $prepared['name'], 'username' => $prepared['login'], 'error' => $e instanceof ApiError ? $e->getMessage() : 'Konnte nicht angelegt werden.'];
                continue;
            }
            try {
                $this->app->ranks()->applyInitial($this->user(), (int) $created['id'], $initial);
            } catch (\Throwable $e) {
                error_log('Kompanie-App: Anfangsstand nicht übernommen: ' . $e->getMessage());
                $created['warning'] = 'Angelegt, aber Anfangspunkte bzw. Dienstgrad fehlen noch. Bitte in den Mitgliedsdetails nachtragen.';
            }
            $out[] = $created;
        }
        return $out;
    }

    /** Dienstgrade in Mitgliederliste und -details: Bereich an und Recht, sie zu verwalten. */
    private function showRanks(): bool
    {
        return $this->app->features()->enabled('ranks') && $this->can(Cap::ManageRanks);
    }

    /** Titel in Mitgliederliste und -details: Bereich an und Recht, sie zu verwalten. */
    private function showTitles(): bool
    {
        return $this->app->features()->enabled('titles') && $this->can(Cap::ManageTitles);
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
        $denyCalendar = 'Der interne Kalender ist nur für Mitglieder und Anwärter.';
        $denyManage = 'Dafür fehlen dir die Rechte.';
        $denyEvents = 'Nur Administratoren dürfen öffentliche Termine pflegen.';
        $denyUsers = 'Nur Administratoren dürfen Mitglieder anlegen.';
        $denyRoles = 'Nur Administratoren dürfen Rollen ändern.';
        $denyRights = 'Dafür fehlen dir die Rechte.';
        // Mitgliederliste: wer Punkte oder Strafen vergibt, Mitglieder anlegt oder Rollen vergibt
        // (Punkte bzw. offene Beträge nur mit dem jeweiligen Recht, siehe MemberService)
        $manage = [Cap::ManagePoints, Cap::ManagePenalties, Cap::ManageRanks, Cap::ManageTitles, Cap::CreateUsers, Cap::PromoteUsers];
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
            new Route('GET', '/me', $L, fn () => $a->members()->me($this->user())
                + ($a->features()->enabled('ranks') && $this->user()->isMember() ? ['rank' => $a->ranks()->status($this->user()->id)['rank']] : [])
                + ($a->features()->enabled('titles') && $this->user()->isMember() ? ['titles' => $a->titles()->of($this->user()->id)] : [])),
            // Dienstgrade (Issue #9): eigener Stand und Verlauf, die Liste mit Schwellen (mit Punkten)
            new Route('GET', '/me/rank', Cap::Member, fn () => $a->ranks()->status($this->user()->id)
                + ['ranks' => $a->ranks()->list(false), 'history' => $a->ranks()->history($this->user()->id)], 'ranks', $denyMember),
            new Route('GET', '/me/attendance', Cap::Member, fn () => $a->attendance()->mine($this->user()->id), 'attendance', $denyMember),
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
            new Route('GET', '/calendar', Cap::Calendar, fn () => $a->calendar()->upcoming(), 'calendar', $denyCalendar),

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

            // Dienstgrade: Liste für Mitglieder, Pflege, Befördern und Degradieren, Beförderungsübersicht
            new Route('GET', '/ranks', Cap::Member, fn (Request $r) => $a->ranks()->list($r->queryFlag('all') && $this->can(Cap::ManageRanks)), 'ranks', $denyMember),
            new Route('POST', '/ranks', Cap::ManageRanks, fn (Request $r) => Response::created($a->ranks()->create($r->body)), 'ranks', $denyRights),
            new Route('PUT', '/ranks/order', Cap::ManageRanks, fn (Request $r) => $a->ranks()->reorder($r->input('ids')), 'ranks', $denyRights),
            new Route('PATCH', '/ranks/{id}', Cap::ManageRanks, fn (Request $r) => $a->ranks()->update($r->id(), $r->body), 'ranks', $denyRights),
            new Route('DELETE', '/ranks/{id}', Cap::ManageRanks, function (Request $r) use ($a) {
                $a->ranks()->delete($r->id());
                return ['deleted' => true];
            }, 'ranks', $denyRights),
            new Route('PUT', '/members/{id}/rank', Cap::ManageRanks, fn (Request $r) => $a->ranks()->change($this->user(), $r->id(), $r->input('rank_id'), $r->input('reason')), 'ranks', $denyRights),
            new Route('GET', '/promotions', Cap::ManageRanks, fn () => $a->ranks()->promotions(), 'ranks', $denyRights),

            // Titel (Issue #22): Pflege der Liste und Zuweisung
            new Route('GET', '/titles', Cap::ManageTitles, fn () => $a->titles()->list(), 'titles', $denyRights),
            new Route('POST', '/titles', Cap::ManageTitles, fn (Request $r) => Response::created($a->titles()->create($r->body)), 'titles', $denyRights),
            new Route('PUT', '/titles/order', Cap::ManageTitles, fn (Request $r) => $a->titles()->reorder($r->input('ids')), 'titles', $denyRights),
            new Route('PATCH', '/titles/{id}', Cap::ManageTitles, fn (Request $r) => $a->titles()->update($r->id(), $r->body), 'titles', $denyRights),
            new Route('DELETE', '/titles/{id}', Cap::ManageTitles, function (Request $r) use ($a) {
                $a->titles()->delete($r->id());
                return ['deleted' => true];
            }, 'titles', $denyRights),
            new Route('PUT', '/members/{id}/titles', Cap::ManageTitles, fn (Request $r) => ['titles' => $a->titles()->assign($r->id(), $r->input('title_ids'))], 'titles', $denyRights),

            // Dokumente (Issue #2): sichtbare für Mitglieder, Pflege mit manage_documents; Dateien Base64 im JSON
            new Route('GET', '/documents', Cap::Member, fn () => $a->documents()->list($this->user()), 'documents', $denyMember),
            new Route('POST', '/documents', Cap::ManageDocuments, fn (Request $r) => Response::created($a->documents()->create($r->body, $this->user()->id)), 'documents', $denyRights),
            new Route('PATCH', '/documents/{id}', Cap::ManageDocuments, fn (Request $r) => $a->documents()->update($r->id(), $r->body), 'documents', $denyRights),
            new Route('POST', '/documents/{id}/file', Cap::ManageDocuments, fn (Request $r) => $a->documents()->replaceFile($r->id(), $r->input('file'), $this->user()->id), 'documents', $denyRights),
            new Route('DELETE', '/documents/{id}', Cap::ManageDocuments, function (Request $r) use ($a) {
                $a->documents()->delete($r->id());
                return ['deleted' => true];
            }, 'documents', $denyRights),
            new Route('POST', '/document-categories', Cap::ManageDocuments, fn (Request $r) => Response::created($a->documents()->createCategory($r->input('title'))), 'documents', $denyRights),
            new Route('PUT', '/document-categories/order', Cap::ManageDocuments, fn (Request $r) => $a->documents()->reorderCategories($r->input('ids')), 'documents', $denyRights),
            new Route('PATCH', '/document-categories/{id}', Cap::ManageDocuments, fn (Request $r) => $a->documents()->renameCategory($r->id(), $r->input('title')), 'documents', $denyRights),
            new Route('DELETE', '/document-categories/{id}', Cap::ManageDocuments, function (Request $r) use ($a) {
                $a->documents()->deleteCategory($r->id());
                return ['deleted' => true];
            }, 'documents', $denyRights),

            // Anwesenheit (Issue #21): Listen je Termin; Gruppen für den Zähler stellen Admins bei den Rollen ein
            new Route('GET', '/attendance', Cap::ManageAttendance, fn () => $a->attendance()->lists(), 'attendance', $denyRights),
            new Route('PUT', '/attendance', Cap::ManageAttendance, function (Request $r) use ($a) {
                [$list, $created] = $a->attendance()->save($this->user(), $r->body);
                return $created ? Response::created($list) : $list;
            }, 'attendance', $denyRights),
            new Route('GET', '/attendance/events', Cap::ManageAttendance, fn () => $a->attendance()->events(), 'attendance', $denyRights),
            new Route('GET', '/attendance/groups', Cap::ManageSettings, fn () => $a->attendance()->groups(), 'attendance', $denySettings),
            new Route('PUT', '/attendance/groups', Cap::ManageSettings, fn (Request $r) => $a->attendance()->setGroups($r->input('groups')), 'attendance', $denySettings),
            new Route('GET', '/attendance/{id}', Cap::ManageAttendance, fn (Request $r) => $a->attendance()->detail($r->id()), 'attendance', $denyRights),
            new Route('DELETE', '/attendance/{id}', Cap::ManageAttendance, function (Request $r) use ($a) {
                $a->attendance()->delete($r->id());
                return ['deleted' => true];
            }, 'attendance', $denyRights),

            // Mitglieder
            new Route('GET', '/members', $manage, function (Request $r) use ($a) {
                // Wer Dienstgrade bzw. Titel verwaltet, sieht auch frühere Mitglieder, die noch welche haben.
                $holders = array_merge(
                    $this->showRanks() ? array_keys($a->ranks()->repository()->assignments()) : [],
                    $this->showTitles() ? array_keys($a->titles()->repository()->assignments()) : [],
                );
                $list = $a->members()->list($r->queryFlag('all') && $this->can(Cap::PromoteUsers), $this->user(), $r->queryFlag('candidates'), $holders);
                if ($this->showRanks()) {
                    $rankTitles = $a->ranks()->titles();
                    $list = array_map(static fn (array $m): array => $m + ['rank_title' => $rankTitles[$m['id']] ?? null], $list);
                }
                if ($this->showTitles()) {
                    $titles = $a->titles()->byUser();
                    $list = array_map(static fn (array $m): array => $m + ['titles' => $titles[$m['id']] ?? []], $list);
                }
                return $list;
            }, null, $denyManage),
            new Route('GET', '/members/{id}', $manage, fn (Request $r) => $a->members()->detail($r->id(), $this->user())
                + ($this->showRanks() ? ['rank' => $a->ranks()->status($r->id()), 'rank_history' => $a->ranks()->history($r->id())] : [])
                + ($this->showTitles() ? ['titles' => $a->titles()->of($r->id())] : []), null, $denyManage),
            new Route('PATCH', '/members/{id}/role', Cap::PromoteUsers, fn (Request $r) => $a->members()->setLevel($this->user(), $r->id(), $r->input('level')), null, $denyRoles),
            new Route('POST', '/members/{id}/promote', Cap::CreateUsers, fn (Request $r) => $a->members()->promote($this->user(), $r->id()), null, $denyUsers),
            // Konten verwalten (Issue #24): Angaben ändern, löschen mit allen eigenen Daten
            new Route('GET', '/members/{id}/account', Cap::CreateUsers, fn (Request $r) => $a->members()->profile($r->id(), $this->user()), null, $denyUsers),
            new Route('PATCH', '/members/{id}/account', Cap::CreateUsers, fn (Request $r) => $a->members()->updateProfile($this->user(), $r->id(), $r->body), null, $denyUsers),
            new Route('DELETE', '/members/{id}', Cap::CreateUsers, function (Request $r) use ($a) {
                $a->members()->removeAccount($this->user(), $r->id());
                $a->userDeleted($r->id());
                return ['deleted' => true];
            }, null, $denyUsers),
            new Route('POST', '/members/{id}/password', Cap::CreateUsers, fn (Request $r) => $a->members()->resetPassword($this->user(), $r->id()), null, $denyUsers),
            new Route('POST', '/users', Cap::CreateUsers, function (Request $r) use ($a) {
                // Anfangspunkte und Dienstgrad: alles prüfen, bevor das Konto entsteht.
                $initial = $a->ranks()->prepareInitial($this->user(), $r->body);
                if (($initial['points'] > 0 || $initial['rank'] !== null) && ($r->input('level') ?? 'member') !== 'member') {
                    throw ApiError::invalid('Anfangspunkte und Dienstgrad gibt es nur für Mitglieder.');
                }
                $created = $a->members()->create($r->body);
                try {
                    $a->ranks()->applyInitial($this->user(), (int) $created['id'], $initial);
                } catch (\Throwable $e) {
                    error_log('Kompanie-App: Anfangsstand nicht übernommen: ' . $e->getMessage());
                    $created['warning'] = 'Das Konto ist angelegt, aber Anfangspunkte bzw. Dienstgrad konnten nicht übernommen werden. Bitte in den Mitgliedsdetails nachtragen.';
                }
                return Response::created($created);
            }, null, $denyUsers),
            // Mehrere auf einmal (Issue #24): erst alle prüfen; bei einem Fehler wird keiner angelegt.
            new Route('POST', '/users/bulk', Cap::CreateUsers, fn (Request $r) => Response::created($this->createUsers($r->input('level'), $r->input('people'))), null, $denyUsers),

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
                $body = $r->body;
                // Aus dem Vorschlag einer Anwesenheitsliste: nur, wer für diese Liste noch keine Punkte hat.
                if (($body['attendance_id'] ?? null) !== null && $body['attendance_id'] !== '') {
                    [$body['attendance_id'], $body['user_ids']] = $a->attendance()->forBooking($body['attendance_id'], $body['user_ids'] ?? null);
                }
                $rows = $a->scores()->givePoints($body, $this->user()->id);
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
