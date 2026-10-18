<?php

declare(strict_types=1);

namespace KompanieApp\Core\Http;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability as Cap;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Domain\EventService;

/**
 * Die Routentabelle der API (siehe api/openapi.yaml) und ihre Ausführung.
 * Beide Adapter lesen dieselbe Tabelle, damit sie sich nicht auseinanderentwickeln.
 *
 * Grundsätze:
 * - Die /me-Routen lesen die Benutzer-ID immer aus der Sitzung, nie aus der Anfrage.
 * - Ist ein Bereich in kompanie.json ausgeschaltet, gibt es seine Routen nicht.
 */
final class Api
{
    public const NEWS_PER_PAGE = 10;

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
            if ($route->feature !== null && !$this->app->config->featureEnabled($route->feature)) {
                throw new ApiError(404, 'feature_disabled', 'Dieser Bereich ist ausgeschaltet.');
            }
            $this->authorize($route);
            $result = ($route->handler)($request);
            return $result instanceof Response ? $result : new Response($result);
        } catch (ApiError $e) {
            return Response::error($e);
        } catch (\Throwable $e) {
            error_log('Kompanie-App: ' . $e::class . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return Response::error(new ApiError(500, 'internal_error', 'Es ist ein Fehler aufgetreten. Bitte versuche es später erneut.'));
        }
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
        if ($route->access instanceof Cap && !$user->can($route->access)) {
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
        $features = $a->config->features();
        $features['calendar'] = $features['calendar'] && $a->calendar()->isConfigured();
        $features['gallery'] = $features['gallery'] && $a->gallery()->isConfigured();
        $features['news'] = $features['news'] && $a->news->available();
        return [
            'apiBase' => $a->env->apiBase(),
            'appUrl' => $a->env->appUrl(),
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
            'user' => $a->authContext->currentUser()?->toArray(),
        ];
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

        return [
            // App und Anmeldung
            new Route('GET', '/config', $P, fn () => $this->config()),
            new Route('GET', '/auth/csrf', $L, fn () => ['token' => $a->authenticator->csrfToken()]),
            new Route('POST', '/auth/login', $P, function (Request $r) use ($a) {
                $a->authenticator->login(
                    is_string($r->input('username')) ? trim($r->input('username')) : '',
                    is_string($r->input('password')) ? $r->input('password') : '',
                    $r->input('remember') === null ? true : (bool) $r->input('remember'),
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
            new Route('GET', '/calendar', Cap::Member, fn () => $a->calendar()->events(), 'calendar', $denyMember),

            // Öffentliche Termine
            new Route('GET', '/events', $P, fn () => $a->events()->upcoming(), 'events'),
            new Route('POST', '/events', Cap::ManageEvents, fn (Request $r) => Response::created(
                $a->events()->create(EventService::input($r->body), $this->user()->id),
            ), 'events', $denyEvents),
            new Route('PATCH', '/events/{id}', Cap::ManageEvents, fn (Request $r) => $a->events()->update($r->id(), EventService::input($r->body)), 'events', $denyEvents),
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
            new Route('GET', '/catalog', $L, fn (Request $r) => $a->scores()->catalog($r->queryFlag('all') && $this->can(Cap::Manage)), 'points'),
            new Route('POST', '/catalog', Cap::Manage, fn (Request $r) => Response::created($a->scores()->createCatalogItem($r->body)), 'points', $denyManage),
            new Route('PATCH', '/catalog/{id}', Cap::Manage, fn (Request $r) => $a->scores()->updateCatalogItem($r->id(), $r->body), 'points', $denyManage),

            // Mitglieder
            new Route('GET', '/members', Cap::Manage, fn (Request $r) => $a->members()->list($r->queryFlag('all') && $this->can(Cap::PromoteUsers)), null, $denyManage),
            new Route('GET', '/members/{id}', Cap::Manage, fn (Request $r) => $a->members()->detail($r->id()), null, $denyManage),
            new Route('PATCH', '/members/{id}/role', Cap::PromoteUsers, fn (Request $r) => $a->members()->setLevel($this->user(), $r->id(), $r->input('level')), null, $denyRoles),
            new Route('POST', '/members/{id}/password', Cap::CreateUsers, fn (Request $r) => $a->members()->resetPassword($this->user(), $r->id()), null, $denyUsers),
            new Route('POST', '/users', Cap::CreateUsers, fn (Request $r) => Response::created($a->members()->create($r->body)), null, $denyUsers),

            // Punkte und Strafen
            new Route('POST', '/points', Cap::Manage, fn (Request $r) => Response::created($a->scores()->givePoints($r->body, $this->user()->id)), 'points', $denyManage),
            new Route('DELETE', '/points/{id}', Cap::Manage, function (Request $r) use ($a) {
                $a->scores()->cancelPoints($r->id(), $this->user()->id);
                return ['deleted' => true];
            }, 'points', $denyManage),
            new Route('POST', '/penalties', Cap::Manage, fn (Request $r) => Response::created($a->scores()->givePenalty($r->body, $this->user()->id)), 'penalties', $denyManage),
            new Route('PATCH', '/penalties/{id}', Cap::Manage, fn (Request $r) => $a->scores()->setPenaltyStatus($r->id(), $r->input('status'), $this->user()->id), 'penalties', $denyManage),
            new Route('DELETE', '/penalties/{id}', Cap::Manage, function (Request $r) use ($a) {
                $a->scores()->cancelPenalty($r->id(), $this->user()->id);
                return ['deleted' => true];
            }, 'penalties', $denyManage),
        ];
    }
}
