<?php

declare(strict_types=1);

namespace KompanieApp\Core\Http;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability as Cap;
use KompanieApp\Core\Auth\Preview;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Domain\EventService;
use KompanieApp\Core\Domain\Input;
use KompanieApp\Core\Domain\PersonName;
use KompanieApp\Core\Domain\StatusService;
use KompanieApp\Core\Domain\Text;
use KompanieApp\Core\Push\PushService;
use KompanieApp\Core\Storage\Sql\DatabaseException;
use KompanieApp\Core\Telemetry\Code;
use KompanieApp\Core\Telemetry\Detail;
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
    /** Bleiben im Wartungsmodus für alle erreichbar: Konfiguration (zeigt die Wartung an) und Anmeldung für Admins. */
    private const MAINTENANCE_OPEN = ['/config', '/auth/login', '/auth/logout', '/auth/csrf'];
    /** In der Vorschau (Issue #63) trotz „nur ansehen“ erlaubt: Abmelden (die App beendet dabei auch die Vorschau). */
    public const PREVIEW_OPEN = ['/auth/logout'];

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

    /**
     * Prüft Bereich und Rechte und führt eine Route aus. Fehler werden zu Fehlerantworten.
     * Die Vorschau (Issue #63) gilt für genau diese Anfrage: aus dem Header bzw. ?vorschau=… gesetzt, danach wie vorher.
     */
    public function dispatch(Route $route, Request $request): Response
    {
        $preview = $this->app->preview();
        $before = $preview->begin(Preview::valueFrom($request->preview, $request->query[Preview::QUERY] ?? null));
        try {
            return $this->run($route, $request);
        } finally {
            $preview->begin($before);
        }
    }

    private function run(Route $route, Request $request): Response
    {
        try {
            // Vorschau: nur ansehen, auf dem Server erzwungen – auch bei ungültiger Vorschau oder ohne Admin.
            if ($this->app->preview()->requested() && !in_array($request->method, ['GET', 'HEAD'], true) && !in_array($route->path, self::PREVIEW_OPEN, true)) {
                throw Preview::readOnlyError();
            }
            if ($route->feature !== null && !$this->app->features()->enabled($route->feature)) {
                throw new ApiError(403, 'feature_disabled', 'Dieser Bereich ist ausgeschaltet.');
            }
            // Wartungsmodus (Issue #42): vor allen Routen; Laufzeit-Konfiguration und Anmeldung bleiben erreichbar.
            if (!in_array($route->path, self::MAINTENANCE_OPEN, true) && $this->app->maintenance()->blocks($this->app->authContext->currentUser())) {
                throw $this->app->maintenance()->error();
            }
            $this->authorize($route);
            $result = ($route->handler)($request);
            return $result instanceof Response ? $result : new Response($result);
        } catch (ApiError $e) {
            return Response::error($e);
        } catch (\Throwable $e) {
            error_log('Kompanie-App: ' . $e::class . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            // In der Statusmeldung nur Code und Ursache (Art des Fehlers und Route), der Text nur im Fehlerprotokoll.
            $db = $e instanceof \PDOException || $e instanceof DatabaseException;
            $kind = $e instanceof \PDOException ? 'sqlstate_' . (string) ($e->errorInfo[0] ?? $e->getCode()) : ($db ? 'wpdb' : Detail::className($e));
            $this->app->telemetry()->record($db ? Code::DbError : Code::ServerError, Detail::of($kind, $route->method, $route->path), Detail::describe($e));
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
                $prepared = $this->app->members()->prepare(['level' => $level] + $p, $taken, $this->user());
                $initial = $this->app->ranks()->prepareInitial($this->user(), $p);
                if (($initial['points'] > 0 || $initial['rank'] !== null) && $level !== Roles::MEMBER) {
                    throw ApiError::invalid('Anfangspunkte und Dienstgrad gibt es nur für Mitglieder.');
                }
                $birthday = $this->birthdayInput($p['birthday'] ?? null);
                $photo = $this->photoInput($p['photo'] ?? null);
            } catch (ApiError $e) {
                $label = PersonName::join(Text::line($p['first_name'] ?? '', PersonName::MAX), Text::line($p['last_name'] ?? '', PersonName::MAX));
                $label = $label !== '' ? $label : Text::line($p['name'] ?? '', PersonName::MAX);
                $who = $label !== '' ? ' (' . $label . ')' : '';
                throw new ApiError($e->status, $e->errorCode, sprintf('Zeile %d%s: %s', $i + 1, $who, $e->getMessage()));
            }
            $plans[] = [$prepared, $initial, $birthday, $photo];
        }
        $out = [];
        foreach ($plans as [$prepared, $initial, $birthday, $photo]) {
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
            $this->storeBirthday((int) $created['id'], $birthday, $created);
            $this->storePhoto((int) $created['id'], $photo, $created);
            $out[] = $created;
        }
        return $out;
    }

    /**
     * Geburtstag beim Anlegen (Issue #1): „JJJJ-MM-TT“ oder „MM-TT“, vorab geprüft; nur mit eingeschaltetem Bereich.
     *
     * @return array{month: int, day: int, year: int|null}|null
     */
    private function birthdayInput(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!$this->app->features()->enabled('birthdays')) {
            throw ApiError::invalid('Der Bereich Geburtstage ist ausgeschaltet.');
        }
        return $this->app->birthdays()->parse($value);
    }

    /**
     * @param array{month: int, day: int, year: int|null}|null $birthday
     * @param array<string, mixed> $created
     */
    private function storeBirthday(int $userId, ?array $birthday, array &$created): void
    {
        if ($birthday === null) {
            return;
        }
        try {
            $this->app->birthdays()->store($this->user(), $userId, $birthday);
        } catch (\Throwable $e) {
            error_log('Kompanie-App: Geburtstag nicht übernommen: ' . $e->getMessage());
            $created['warning'] = trim(($created['warning'] ?? '') . ' Der Geburtstag fehlt noch. Bitte in den Mitgliedsdetails nachtragen.');
        }
    }

    /**
     * Foto beim Anlegen: {name, data (Base64)}, vorab geprüft (MemberPhotoService::parse).
     *
     * @return array{bytes: string, ext: string}|null
     */
    private function photoInput(mixed $value): ?array
    {
        return $value === null || $value === '' ? null : $this->app->memberPhotos()->parse($value);
    }

    /**
     * @param array{bytes: string, ext: string}|null $photo
     * @param array<string, mixed> $created
     */
    private function storePhoto(int $userId, ?array $photo, array &$created): void
    {
        $created['photo_url'] = null;
        if ($photo === null) {
            return;
        }
        try {
            $this->app->memberPhotos()->store($this->user(), $userId, $photo);
            $created['photo_url'] = $this->app->memberPhotos()->url($userId);
        } catch (\Throwable $e) {
            error_log('Kompanie-App: Foto nicht übernommen: ' . $e->getMessage());
            $created['warning'] = trim(($created['warning'] ?? '') . ' Das Foto fehlt noch. Bitte in den Mitgliedsdetails nachtragen.');
        }
    }

    /**
     * Dienstgrad in den Mitgliedsdetails: Bezeichnung und Abzeichen wie in der Mitgliederliste für alle mit Zugang;
     * Stand und Verlauf nur, wer Dienstgrade verwaltet.
     *
     * @return array<string, mixed>
     */
    private function memberRank(int $userId): array
    {
        if (!$this->app->features()->enabled('ranks')) {
            return [];
        }
        $status = $this->showRanks() ? $this->app->ranks()->status($userId) : null;
        $rank = $status !== null ? $status['rank'] : $this->app->ranks()->current($userId);
        return ['rank_title' => $rank['title'] ?? null, 'rank_badge_url' => $rank['badge_url'] ?? null]
            + ($status !== null ? ['rank' => $status, 'rank_history' => $this->app->ranks()->history($userId)] : []);
    }

    /**
     * Mitgliederliste für alle (auch ohne Anmeldung): Namen und Fotos der Mitglieder und Anwärter, mit den Bereichen auch
     * Dienstgrad (mit Abzeichen) und Titel. Keine Rollen, Punkte, Strafen oder Kontodaten. Geburtstag (mit dem Bereich
     * Geburtstage) und Eintrittsjahr nur, soweit die Person sie dem Betrachter zeigt (ProfileVisibility), sonst null. Mit
     * $entries dieselben Angaben zu anderen Personen (Ehemalige, ohne Geburtstag und Eintrittsjahr: Sie haben ihre Jahre).
     *
     * @param list<array<string, mixed>>|null $entries
     * @return list<array<string, mixed>>
     */
    private function directory(?array $entries = null): array
    {
        $a = $this->app;
        $photos = $a->memberPhotos()->urls();
        $ranks = $a->features()->enabled('ranks') ? $a->ranks()->assigned() : null;
        $titles = $a->features()->enabled('titles') ? $a->titles()->byUser() : null;
        $viewer = $a->authContext->currentUser();
        $birthdays = $entries === null && $a->features()->enabled('birthdays') ? $a->birthdays()->shownTo($viewer) : null;
        $joined = $entries === null ? $a->joinYears()->shownTo($viewer) : null;
        return array_map(static fn (array $m): array => $m
            + ['photo_url' => $photos[$m['id']] ?? null]
            + ($ranks !== null ? ['rank_title' => $ranks[$m['id']]['title'] ?? null, 'rank_badge_url' => $ranks[$m['id']]['badge_url'] ?? null] : [])
            + ($titles !== null ? ['titles' => $titles[$m['id']] ?? []] : [])
            + ($birthdays !== null ? ['birthday' => $birthdays[$m['id']] ?? null] : [])
            + ($joined !== null ? ['join_year' => $joined[$m['id']] ?? null] : []), $entries ?? $a->members()->directory());
    }

    /** Stand, Verlauf und frühere Mitglieder mit Dienstgrad in Mitgliederliste und -details: Bereich an und Recht, sie zu verwalten. */
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
        $user = $a->authContext->currentUser();
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
            // Fehlermeldungen an die Betreuer der App (Issue #64): ohne Empfänger kein Knopf „Fehler melden“
            'reports' => $a->bugReports()->available(),
            // Handbuch auf GitHub Pages der Kompanie: neben updates.json (Issue #12)
            'handbookUrl' => self::handbookUrl((string) ($a->packageConfig['updatesUrl'] ?? '')),
            // Bezeichnungen und Punktefaktor der Rollen (vom Admin in der App festgelegt)
            'roles' => $a->roles()->publicList(),
            // level ist die Grundrolle; extra_roles die Zusatzrollen (Issue #66), capabilities alle Rechte zusammen
            // photo_url: eigenes Foto (Konto), null ohne
            'user' => $user !== null ? $user->toArray() + ['extra_roles' => $a->roles()->extraRolesOf($user), 'photo_url' => $a->memberPhotos()->url($user->id)] : null,
            // Nur für Admins: Einrichtungsassistent beim ersten Öffnen anbieten.
            'maintenance' => self::maintenanceInfo($a->maintenance()->state()),
            'setupPending' => $this->can(Cap::ManageSettings) && !(bool) $a->settings->get(self::SETUP_SETTING, false),
            // Vorschau „App als Rolle ansehen“ (Issue #63): {role, label}, null ohne (gültige) Vorschau
            'preview' => $a->preview()->info(),
        ];
    }

    /**
     * @param array{on: bool, reason: string, since: int, by: int} $state
     * @return array{on: bool, reason: string, since: int}
     */
    private static function maintenanceInfo(array $state): array
    {
        return ['on' => $state['on'], 'reason' => $state['reason'], 'since' => $state['since']];
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
        $denyCalendar = 'Der interne Kalender ist nur für Mitglieder und Rollen mit dem internen Kalender (z. B. Anwärter).';
        $denyManage = 'Dafür fehlen dir die Rechte.';
        $denyEvents = 'Nur Administratoren dürfen öffentliche Termine pflegen.';
        $denyUsers = 'Nur Administratoren dürfen Mitglieder anlegen.';
        $denyRoles = 'Nur Administratoren dürfen Rollen ändern.';
        $denyRights = 'Dafür fehlen dir die Rechte.';
        $denyBoard = 'Das Schwarze Brett ist nur für Mitglieder und Rollen mit dem internen Kalender (z. B. Anwärter).';
        $denyPolls = 'Umfragen sind nur für Mitglieder.';
        $denyBirthdays = 'Geburtstage sind nur für Mitglieder.';
        // Mitgliederliste: wer Punkte oder Strafen vergibt, Mitglieder anlegt oder Rollen vergibt
        // (Punkte bzw. offene Beträge nur mit dem jeweiligen Recht, siehe MemberService)
        $manage = Cap::memberList();
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
            // Punkte und offene Strafen einmal (me()); vom Dienstgrad nur die Bezeichnung, ohne die Buchungen für den
            // Fortschritt erneut zu lesen (der steht unter /me/rank).
            new Route('GET', '/me', $L, fn () => $a->members()->me($this->user())
                + ($a->features()->enabled('ranks') && $this->user()->isMember() ? ['rank' => $a->ranks()->current($this->user()->id)] : [])
                + ($a->features()->enabled('titles') && $this->user()->isMember() ? ['titles' => $a->titles()->of($this->user()->id)] : [])),
            // Dienstgrade (Issue #9): eigener Stand und Verlauf, die Liste mit Schwellen (mit Punkten)
            new Route('GET', '/me/rank', Cap::Member, fn () => $a->ranks()->status($this->user()->id)
                + ['ranks' => $a->ranks()->list(false), 'history' => $a->ranks()->history($this->user()->id)], 'ranks', $denyMember),
            new Route('GET', '/me/attendance', Cap::Member, fn () => $a->attendance()->mine($this->user()->id), 'attendance', $denyMember),
            new Route('GET', '/me/points', Cap::Member, fn () => $a->scores()->pointsFor($this->user()->id), 'points', $denyMember),
            new Route('GET', '/me/penalties', Cap::Member, fn () => $a->scores()->penaltiesFor($this->user()->id), 'penalties', $denyMember),
            // Geburtstage (Issue #1): eigener mit Jahr und Schaltern
            new Route('GET', '/me/birthday', Cap::Member, fn () => ['birthday' => $a->birthdays()->of($this->user()->id)], 'birthdays', $denyBirthdays),
            new Route('PUT', '/me/birthday', Cap::Member, fn (Request $r) => ['birthday' => $a->birthdays()->saveOwn($this->user(), $r->body)], 'birthdays', $denyBirthdays),
            new Route('DELETE', '/me/birthday', Cap::Member, function () use ($a) {
                $a->birthdays()->remove($this->user()->id);
                return ['birthday' => null];
            }, 'birthdays', $denyBirthdays),
            // Eintrittsjahr mit der Anzeige auf der eigenen Seite in der Mitgliederliste
            new Route('GET', '/me/join-year', Cap::Member, fn () => ['join_year' => $a->joinYears()->of($this->user()->id)], null, $denyMember),
            new Route('PUT', '/me/join-year', Cap::Member, fn (Request $r) => ['join_year' => $a->joinYears()->saveOwn($this->user(), $r->body)], null, $denyMember),
            new Route('DELETE', '/me/join-year', Cap::Member, function () use ($a) {
                $a->joinYears()->remove($this->user()->id);
                return ['join_year' => null];
            }, null, $denyMember),
            new Route('POST', '/me/password', $L, function (Request $r) use ($a) {
                $a->members()->changeOwnPassword($this->user(), $r->input('current_password'), $r->input('new_password'));
                return ['changed' => true];
            }),
            // Web Push (Issue #19): Konten über /me/push, Gäste über /push (Ändern und Löschen nur mit dem auth-Geheimnis).
            // Fehlermeldungen (Issue #64): auch ohne Anmeldung; Zeitfalle, Honeypot und Begrenzung im Dienst
            new Route('GET', '/reports/form', $P, fn () => $a->bugReports()->form($a->authContext->currentUser())),
            new Route('POST', '/reports', $P, fn (Request $r) => Response::created($a->bugReports()->submit($a->authContext->currentUser(), $r->body, $r->client))),
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
            // Abzeichen (Issue #17): Datei Base64 im JSON, bei SVG optional eine PNG-Fassung (png) für Benachrichtigungen
            new Route('POST', '/ranks/{id}/badge', Cap::ManageRanks, fn (Request $r) => $a->ranks()->uploadBadge($r->id(), $r->input('file'), $r->input('png')), 'ranks', $denyRights),
            new Route('DELETE', '/ranks/{id}/badge', Cap::ManageRanks, fn (Request $r) => $a->ranks()->removeBadge($r->id()), 'ranks', $denyRights),
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

            // Ämter (Issue #112): Zeitstrahl öffentlich, Pflege mit manage_offices
            new Route('GET', '/offices', $P, fn () => $a->offices()->overview($a->authContext->currentUser()), 'offices'),
            new Route('POST', '/office-groups', Cap::ManageOffices, fn (Request $r) => Response::created($a->offices()->createGroup($r->input('title'))), 'offices', $denyRights),
            new Route('PUT', '/office-groups/order', Cap::ManageOffices, fn (Request $r) => $a->offices()->reorderGroups($r->input('ids')), 'offices', $denyRights),
            new Route('PATCH', '/office-groups/{id}', Cap::ManageOffices, fn (Request $r) => $a->offices()->updateGroup($r->id(), $r->input('title')), 'offices', $denyRights),
            new Route('DELETE', '/office-groups/{id}', Cap::ManageOffices, function (Request $r) use ($a) {
                $a->offices()->deleteGroup($r->id());
                return ['deleted' => true];
            }, 'offices', $denyRights),
            new Route('POST', '/offices', Cap::ManageOffices, fn (Request $r) => Response::created($a->offices()->createOffice($r->body)), 'offices', $denyRights),
            new Route('PUT', '/offices/order', Cap::ManageOffices, fn (Request $r) => $a->offices()->reorderOffices($r->input('ids')), 'offices', $denyRights),
            // Import (Ersterfassung): ohne apply nur die Vorschau
            new Route('POST', '/offices/import', Cap::ManageOffices, fn (Request $r) => $a->offices()->import(
                $r->input('csv'),
                $r->input('link') === null ? true : Input::bool($r->input('link')),
                Input::bool($r->input('apply')),
            ), 'offices', $denyRights),
            new Route('PATCH', '/offices/{id}', Cap::ManageOffices, fn (Request $r) => $a->offices()->updateOffice($r->id(), $r->body), 'offices', $denyRights),
            new Route('DELETE', '/offices/{id}', Cap::ManageOffices, function (Request $r) use ($a) {
                $a->offices()->deleteOffice($r->id());
                return ['deleted' => true];
            }, 'offices', $denyRights),
            new Route('GET', '/offices/{id}/title-preview', Cap::ManageOffices, fn (Request $r) => $a->offices()->linkPreview($r->id(), $r->query['title_id'] ?? null), 'offices', $denyRights),
            new Route('POST', '/offices/{id}/terms', Cap::ManageOffices, fn (Request $r) => Response::created($a->offices()->createTerm($r->id(), $r->body)), 'offices', $denyRights),
            new Route('PATCH', '/office-terms/{id}', Cap::ManageOffices, fn (Request $r) => $a->offices()->updateTerm($r->id(), $r->body), 'offices', $denyRights),
            new Route('DELETE', '/office-terms/{id}', Cap::ManageOffices, function (Request $r) use ($a) {
                $a->offices()->deleteTerm($r->id());
                return ['deleted' => true];
            }, 'offices', $denyRights),

            // Listen (Issue #121): nur mit manage_lists; Werte der Mitglieder nur, soweit die Person sie ohnehin sieht
            new Route('GET', '/lists', Cap::ManageLists, fn () => $a->lists()->overview(), 'lists', $denyRights),
            new Route('POST', '/lists', Cap::ManageLists, fn (Request $r) => Response::created($a->lists()->create($this->user(), $r->body)), 'lists', $denyRights),
            new Route('GET', '/lists/{id}', Cap::ManageLists, fn (Request $r) => $a->lists()->detail($this->user(), $r->id()), 'lists', $denyRights),
            new Route('PUT', '/lists/{id}', Cap::ManageLists, fn (Request $r) => $a->lists()->save($this->user(), $r->id(), $r->body), 'lists', $denyRights),
            new Route('DELETE', '/lists/{id}', Cap::ManageLists, function (Request $r) use ($a) {
                $a->lists()->delete($r->id());
                return ['deleted' => true];
            }, 'lists', $denyRights),
            new Route('POST', '/lists/{id}/copy', Cap::ManageLists, fn (Request $r) => Response::created($a->lists()->copy($this->user(), $r->id())), 'lists', $denyRights),
            // „Mitglieder aktualisieren“: ohne apply nur die Übersicht, was sich ändert
            new Route('POST', '/lists/{id}/sync-members', Cap::ManageLists, fn (Request $r) => $a->lists()->syncMembers($this->user(), $r->id(), Input::bool($r->input('apply'))), 'lists', $denyRights),

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

            // Schwarzes Brett (Issue #5): Mitglieder und Anwärter lesen (BoardService::isRecipient), post_board schreibt,
            // ändert und löscht (und liest daher auch); nur beim Schreiben eine Benachrichtigung
            new Route('GET', '/board', [Cap::Member, Cap::Calendar, Cap::PostBoard], fn () => $a->board()->list($this->user()), 'board', $denyBoard),
            new Route('POST', '/board', Cap::PostBoard, function (Request $r) use ($a) {
                // push: ob Mitglieder und Anwärter benachrichtigt werden (Standard ja, Haken beim Schreiben)
                $push = $r->input('push') ?? true;
                if (!is_bool($push)) {
                    throw ApiError::invalid('push muss true oder false sein.');
                }
                $item = $a->board()->create($this->user(), $r->input('text'));
                $author = $this->user()->id;
                if ($push) {
                    $this->notify(static fn () => $a->pushTriggers()->boardPosted($item, $author));
                }
                return Response::created($item);
            }, 'board', $denyRights),
            new Route('PATCH', '/board/{id}', Cap::PostBoard, fn (Request $r) => $a->board()->update($r->id(), $r->input('text')), 'board', $denyRights),
            new Route('DELETE', '/board/{id}', Cap::PostBoard, function (Request $r) use ($a) {
                $a->board()->delete($r->id());
                return ['deleted' => true];
            }, 'board', $denyRights),

            // Umfragen (Issue #4): Mitglieder lesen und antworten (nur solange die Umfrage läuft), manage_polls verwaltet;
            // nur beim Anlegen eine Benachrichtigung. Antworten anonymer Umfragen nie mit Namen oder IDs (PollService).
            new Route('GET', '/polls', [Cap::Member, Cap::ManagePolls], fn () => $a->polls()->list($this->user()), 'polls', $denyPolls),
            new Route('POST', '/polls', Cap::ManagePolls, function (Request $r) use ($a) {
                $poll = $a->polls()->create($this->user(), $r->body);
                $creator = $this->user()->id;
                $this->notify(static fn () => $a->pushTriggers()->pollCreated(['id' => (int) $poll['id'], 'title' => (string) $poll['title'], 'ends_at' => is_string($poll['ends_at']) ? $poll['ends_at'] : null], $creator));
                return Response::created($poll);
            }, 'polls', $denyRights),
            new Route('GET', '/polls/{id}', [Cap::Member, Cap::ManagePolls], fn (Request $r) => $a->polls()->get($this->user(), $r->id()), 'polls', $denyPolls),
            new Route('PATCH', '/polls/{id}', Cap::ManagePolls, fn (Request $r) => $a->polls()->update($this->user(), $r->id(), $r->body), 'polls', $denyRights),
            new Route('POST', '/polls/{id}/close', Cap::ManagePolls, fn (Request $r) => $a->polls()->close($this->user(), $r->id()), 'polls', $denyRights),
            new Route('DELETE', '/polls/{id}', Cap::ManagePolls, function (Request $r) use ($a) {
                $a->polls()->delete($r->id());
                return ['deleted' => true];
            }, 'polls', $denyRights),
            new Route('PUT', '/polls/{id}/vote', Cap::Member, fn (Request $r) => $a->polls()->vote($this->user(), $r->id(), $r->body), 'polls', $denyPolls),
            new Route('DELETE', '/polls/{id}/vote', Cap::Member, fn (Request $r) => $a->polls()->withdraw($this->user(), $r->id()), 'polls', $denyPolls),

            // Geburtstage (Issue #1): sichtbare der Mitglieder ab heute, nie das Geburtsjahr; ?days=7 nur die anstehenden.
            // Das Datum anderer pflegt, wer Mitglieder anlegt (die Schalter der Person bleiben).
            new Route('GET', '/birthdays', Cap::Member, function (Request $r) use ($a) {
                $days = null;
                if (($r->query['days'] ?? null) !== null) {
                    $days = Input::integer($r->query['days']);
                    if ($days === null || $days < 0 || $days > 366) {
                        throw ApiError::invalid('days: Zahl der Tage von 0 bis 366.');
                    }
                }
                return $a->birthdays()->list($days);
            }, 'birthdays', $denyBirthdays),
            new Route('PUT', '/members/{id}/birthday', Cap::CreateUsers, fn (Request $r) => ['birthday' => $a->birthdays()->saveFor($this->user(), $r->id(), $r->body)], 'birthdays', $denyRights),
            new Route('DELETE', '/members/{id}/birthday', Cap::CreateUsers, function (Request $r) use ($a) {
                $a->birthdays()->removeFor($r->id());
                return ['birthday' => null];
            }, 'birthdays', $denyRights),
            new Route('PUT', '/members/{id}/join-year', Cap::CreateUsers, fn (Request $r) => ['join_year' => $a->joinYears()->saveFor($this->user(), $r->id(), $r->body)], null, $denyRights),
            new Route('DELETE', '/members/{id}/join-year', Cap::CreateUsers, function (Request $r) use ($a) {
                $a->joinYears()->removeFor($r->id());
                return ['join_year' => null];
            }, null, $denyRights),

            // Inventar (Issue #6): nur mit manage_inventory (Mitglieder ohne das Recht sehen es nicht); optional reason bei
            // allen Änderungen. Genau ein Verlaufseintrag je Änderung und betroffenem Eintrag (InventoryService).
            new Route('GET', '/inventory', Cap::ManageInventory, fn () => $a->inventory()->list(), 'inventory', $denyRights),
            new Route('GET', '/inventory/locations', Cap::ManageInventory, fn () => $a->inventory()->locations(), 'inventory', $denyRights),
            new Route('GET', '/inventory/log', Cap::ManageInventory, fn (Request $r) => $a->inventory()->log($r->query['limit'] ?? null, $r->query['before'] ?? null), 'inventory', $denyRights),
            new Route('POST', '/inventory', Cap::ManageInventory, fn (Request $r) => Response::created($a->inventory()->create($this->user(), $r->body)), 'inventory', $denyRights),
            new Route('PATCH', '/inventory/{id}', Cap::ManageInventory, fn (Request $r) => $a->inventory()->update($this->user(), $r->id(), $r->body), 'inventory', $denyRights),
            new Route('DELETE', '/inventory/{id}', Cap::ManageInventory, function (Request $r) use ($a) {
                $a->inventory()->delete($this->user(), $r->id(), $r->body['reason'] ?? $r->query['reason'] ?? null);
                return ['deleted' => true];
            }, 'inventory', $denyRights),
            new Route('GET', '/inventory/{id}/log', Cap::ManageInventory, fn (Request $r) => $a->inventory()->itemLog($r->id(), $r->query['limit'] ?? null, $r->query['before'] ?? null), 'inventory', $denyRights),
            new Route('POST', '/inventory/{id}/move', Cap::ManageInventory, fn (Request $r) => $a->inventory()->move($this->user(), $r->id(), $r->body), 'inventory', $denyRights),

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

            // Mitgliederliste (Bereich directory, Issue #112: öffentlich, auch ohne Anmeldung): nur Namen, Fotos, Dienstgrade und
            // Titel, dazu Geburtstag und Eintrittsjahr, soweit die Person sie dem Betrachter zeigt
            new Route('GET', '/directory', $P, fn () => $this->directory(), 'directory'),
            new Route('GET', '/directory/counts', $P, fn () => $a->members()->directoryCounts($a->attendance()->groupMap()), 'directory'),
            // Ehemalige (Tab neben den Ämtern): wie die Liste, dazu nur Gründungsmitglied und die Jahre, kein Grund
            new Route('GET', '/directory/former', $P, fn () => $this->directory($a->formerMembers()->directory()), 'directory'),

            // Mitglieder
            new Route('GET', '/members', $manage, function (Request $r) use ($a) {
                // Wer Dienstgrade bzw. Titel verwaltet, sieht auch frühere Mitglieder, die noch welche haben.
                $holders = array_merge(
                    $this->showRanks() ? array_keys($a->ranks()->repository()->assignments()) : [],
                    $this->showTitles() ? array_keys($a->titles()->repository()->assignments()) : [],
                );
                // Ehemalige stehen in ihrer eigenen Liste (GET /members/former), auch wenn sie noch Dienstgrad oder Titel haben.
                $holders = array_values(array_diff($holders, $a->formerMembers()->ids()));
                $list = $a->members()->list($r->queryFlag('all') && $this->can(Cap::PromoteUsers), $this->user(), $r->queryFlag('candidates'), $holders);
                // Gruppe für den Zähler über der Liste, wie in der Anwesenheit (Einstellungen → Rollen); Gäste ohne.
                $groups = $a->attendance()->groupMap();
                $list = array_map(static fn (array $m): array => $m + ['group' => $groups[$m['level']] ?? null], $list);
                $photos = $a->memberPhotos()->urls();
                $list = array_map(static fn (array $m): array => $m + ['photo_url' => $photos[$m['id']] ?? null], $list);
                // Dienstgrad mit Abzeichen sieht jede Person mit Zugang zur Liste, nicht nur wer Dienstgrade verwaltet.
                if ($a->features()->enabled('ranks')) {
                    $ranks = $a->ranks()->assigned();
                    $list = array_map(static fn (array $m): array => $m + [
                        'rank_title' => $ranks[$m['id']]['title'] ?? null,
                        'rank_badge_url' => $ranks[$m['id']]['badge_url'] ?? null,
                    ], $list);
                }
                if ($this->showTitles()) {
                    $titles = $a->titles()->byUser();
                    $list = array_map(static fn (array $m): array => $m + ['titles' => $titles[$m['id']] ?? []], $list);
                }
                // Eintritt (zum Sortieren), wie in den Mitgliedsdetails nur für create_users
                if ($this->user()->can(Cap::CreateUsers)) {
                    $joined = $a->joinYears()->repository()->all();
                    $list = array_map(static fn (array $m): array => $m + ['joined_on' => isset($joined[$m['id']]) ? (string) $joined[$m['id']] : ''], $list);
                }
                return $list;
            }, null, $denyManage),
            new Route('GET', '/members/{id}', $manage, fn (Request $r) => $a->members()->detail($r->id(), $this->user())
                // Foto: sehen alle hier; ändern nur, wer das Konto verwalten darf
                + ['photo_url' => $a->memberPhotos()->url($r->id()), 'photo_editable' => $a->memberPhotos()->canEdit($this->user(), $r->id())]
                + $this->memberRank($r->id())
                + ($this->showTitles() ? ['titles' => $a->titles()->of($r->id())] : [])
                // Geburtstag mit Jahr und Schaltern (Issue #1), nur für alle, die ihn pflegen dürfen (create_users): Wer etwa
                // nur Punkte verwaltet, sieht kein Geburtsdatum, auch kein verborgenes
                + ($a->features()->enabled('birthdays') && $this->user()->can(Cap::CreateUsers) ? ['birthday' => $a->birthdays()->of($r->id())] : [])
                // Eintrittsjahr mit Anzeige, ebenso nur für create_users
                + ($this->user()->can(Cap::CreateUsers) ? ['join_year' => $a->joinYears()->of($r->id())] : [])
                // Ehemalig: Grund, Eintritt, Ausscheiden (sonst null)
                + ['former' => $a->formerMembers()->of($r->id())], null, $denyManage),
            // Ehemalige Mitglieder: Liste, eintragen (Konto stilllegen, Daten bleiben), ändern, wieder aufnehmen, Import
            new Route('GET', '/members/former', Cap::CreateUsers, function () use ($a) {
                $photos = $a->memberPhotos()->urls();
                return array_map(static fn (array $m): array => $m + ['photo_url' => $photos[$m['id']] ?? null], $a->formerMembers()->list($this->user()));
            }, null, $denyUsers),
            // Import (Ersterfassung, z. B. aus einer Chronik): ohne apply nur die Vorschau
            new Route('POST', '/members/former/import', Cap::CreateUsers, fn (Request $r) => $a->formerMembers()->import($this->user(), $r->input('csv'), Input::bool($r->input('apply'))), null, $denyUsers),
            new Route('PUT', '/members/{id}/former', Cap::CreateUsers, fn (Request $r) => $a->formerMembers()->mark($this->user(), $r->id(), $r->body), null, $denyUsers),
            new Route('PATCH', '/members/{id}/former', Cap::CreateUsers, fn (Request $r) => $a->formerMembers()->update($this->user(), $r->id(), $r->body), null, $denyUsers),
            new Route('DELETE', '/members/{id}/former', Cap::CreateUsers, fn (Request $r) => $a->formerMembers()->reactivate($this->user(), $r->id()), null, $denyUsers),
            // Grundrolle (level) und/oder Zusatzrollen (extra_roles, Issue #66)
            new Route('PATCH', '/members/{id}/role', Cap::PromoteUsers, fn (Request $r) => $a->members()->setLevel($this->user(), $r->id(), $r->input('level'), $r->input('extra_roles')), null, $denyRoles),
            // Rechte einzelner Personen zusätzlich zu ihren Rollen geben oder entziehen
            new Route('PUT', '/members/{id}/rights', Cap::PromoteUsers, fn (Request $r) => $a->roles()->setOverrides($this->user(), $r->id(), $r->input('grant'), $r->input('revoke')), null, $denyRoles),
            new Route('POST', '/members/{id}/promote', Cap::CreateUsers, fn (Request $r) => $a->members()->promote($this->user(), $r->id()), null, $denyUsers),
            // Konten verwalten (Issue #24): Angaben ändern, löschen mit allen eigenen Daten
            new Route('GET', '/members/{id}/account', Cap::CreateUsers, fn (Request $r) => $a->members()->profile($r->id(), $this->user()), null, $denyUsers),
            new Route('PATCH', '/members/{id}/account', Cap::CreateUsers, fn (Request $r) => $a->members()->updateProfile($this->user(), $r->id(), $r->body), null, $denyUsers),
            new Route('DELETE', '/members/{id}', Cap::CreateUsers, function (Request $r) use ($a) {
                $a->members()->removeAccount($this->user(), $r->id());
                $a->userDeleted($r->id());
                return ['deleted' => true];
            }, null, $denyUsers),
            // Foto des Mitglieds: hochladen bzw. ersetzen und entfernen
            new Route('PUT', '/members/{id}/photo', Cap::CreateUsers, fn (Request $r) => $a->memberPhotos()->upload($this->user(), $r->id(), $r->input('file')), null, $denyUsers),
            new Route('DELETE', '/members/{id}/photo', Cap::CreateUsers, fn (Request $r) => $a->memberPhotos()->remove($this->user(), $r->id()), null, $denyUsers),
            new Route('POST', '/members/{id}/password', Cap::CreateUsers, fn (Request $r) => $a->members()->resetPassword($this->user(), $r->id()), null, $denyUsers),
            new Route('POST', '/users', Cap::CreateUsers, function (Request $r) use ($a) {
                // Anfangspunkte und Dienstgrad: alles prüfen, bevor das Konto entsteht.
                $initial = $a->ranks()->prepareInitial($this->user(), $r->body);
                if (($initial['points'] > 0 || $initial['rank'] !== null) && ($r->input('level') ?? 'member') !== 'member') {
                    throw ApiError::invalid('Anfangspunkte und Dienstgrad gibt es nur für Mitglieder.');
                }
                $birthday = $this->birthdayInput($r->body['birthday'] ?? null);
                $photo = $this->photoInput($r->body['photo'] ?? null);
                $created = $a->members()->create($r->body, $this->user());
                try {
                    $a->ranks()->applyInitial($this->user(), (int) $created['id'], $initial);
                } catch (\Throwable $e) {
                    error_log('Kompanie-App: Anfangsstand nicht übernommen: ' . $e->getMessage());
                    $created['warning'] = 'Das Konto ist angelegt, aber Anfangspunkte bzw. Dienstgrad konnten nicht übernommen werden. Bitte in den Mitgliedsdetails nachtragen.';
                }
                $this->storeBirthday((int) $created['id'], $birthday, $created);
                $this->storePhoto((int) $created['id'], $photo, $created);
                return Response::created($created);
            }, null, $denyUsers),
            // Mehrere auf einmal (Issue #24): erst alle prüfen; bei einem Fehler wird keiner angelegt.
            new Route('POST', '/users/bulk', Cap::CreateUsers, fn (Request $r) => Response::created($this->createUsers($r->input('level'), $r->input('people'))), null, $denyUsers),

            // Einstellungen (Admins)
            new Route('GET', '/admin/maintenance', Cap::ManageSettings, fn () => $a->maintenance()->state() + ['log' => $a->maintenance()->log()], null, $denySettings),
            new Route('POST', '/admin/maintenance', Cap::ManageSettings, fn (Request $r) => $a->maintenance()->set($r->input('on'), $r->body['reason'] ?? null, $this->user()) + ['log' => $a->maintenance()->log()], null, $denySettings),
            new Route('GET', '/admin/settings', Cap::ManageSettings, fn () => $a->settingsService()->get(), null, $denySettings),
            // Push (Issue #19): Voraussetzungen, Zahl der Abos, Uhrzeit der Erinnerung; Benachrichtigung am Geburtstag
            new Route('GET', '/admin/push', Cap::ManageSettings, fn () => $a->push()->adminStatus(), null, $denySettings),
            new Route('PATCH', '/admin/push', Cap::ManageSettings, function (Request $r) use ($a) {
                if ($r->has('reminder_hour')) {
                    $a->push()->setReminderHour($r->input('reminder_hour'));
                }
                if ($r->has('birthday_notify') || $r->has('birthday_hour')) {
                    $a->birthdays()->setNotify($r->input('birthday_notify'), $r->input('birthday_hour'));
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
            // Reihenfolge der eigenen Rollen (Einstellungen → Rollen, per Ziehen)
            new Route('PUT', '/admin/roles/order', Cap::ManageSettings, function (Request $r) use ($a) {
                $a->roles()->reorder($r->input('keys'));
                return $a->roles()->describe();
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
            // „Mit Google verbinden“: Client speichern, Adresse der Anmeldung bei Google; Rückkehr über die Weiterleitungs-URI des Adapters
            new Route('POST', '/admin/google/connect', Cap::ManageSettings, fn (Request $r) => ['url' => $a->googleConnect()->start($r->input('client_id'), $r->input('client_secret'))], null, $denySettings),
            new Route('POST', '/admin/google/disconnect', Cap::ManageSettings, function () use ($a) {
                $a->googleConnect()->disconnect();
                return $a->settingsService()->get();
            }, null, $denySettings),
            // Bilder, die sich nicht laden ließen: Liste mit Fehlermeldung, alle erneut versuchen
            new Route('GET', '/admin/gallery/failed', Cap::ManageSettings, fn () => ['photos' => $a->gallery()->failedPhotos(), 'total' => $a->gallery()->countFailed()], null, $denySettings),
            new Route('POST', '/admin/gallery/retry', Cap::ManageSettings, fn () => ['retried' => $a->gallery()->retryFailedNow(), 'pending' => $a->gallery()->countPending()], null, $denySettings),
            new Route('POST', '/admin/gallery/sync', Cap::ManageSettings, fn (Request $r) => $a->settingsService()->syncGallery(Input::bool($r->input('only_process'))), null, $denySettings),

            // Status, Updates, Datensicherung, Einrichtung (Admins)
            new Route('GET', '/admin/status', Cap::ManageSettings, fn () => $a->status()->report() + [
                'updates' => $a->updates()->status(),
                'statusReports' => ['enabled' => (bool) $a->settings->get(StatusReporter::SETTING, false), 'available' => (string) ($a->packageConfig['statusUrl'] ?? '') !== '', 'payload' => $a->statusReporter()->payload(), 'last' => $a->statusReporter()->last()],
                'setupDone' => (bool) $a->settings->get(self::SETUP_SETTING, false),
                // Fehlerprotokoll: bleibt auf dem Server, auch ohne Zustimmung zu den Statusmeldungen
                'errorLog' => $a->telemetry()->log(),
            ], null, $denySettings),
            new Route('DELETE', '/admin/error-log', Cap::ManageSettings, function () use ($a) {
                $a->telemetry()->clearLog();
                return ['errorLog' => []];
            }, null, $denySettings),
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
                if ($r->has('channel')) {
                    $a->updates()->setChannel(is_string($r->input('channel')) ? $r->input('channel') : '');
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
