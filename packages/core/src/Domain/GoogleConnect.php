<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;

/**
 * „Mit Google verbinden“ für beide Adapter: Beginn (Client speichern, Adresse der Anmeldung bei Google), Rückkehr von
 * Google (state prüfen, Code eintauschen) und Trennen. Die Bausteine liegen in DriveClient; hier kommen Rechte,
 * Sitzung, Meldungen und die Folgen für Galerie und Kalender dazu.
 *
 * Ablauf: Die App (POST /admin/google/connect) bzw. der WP-Admin schickt den Admin zu Google. Google leitet ihn an die
 * feste Weiterleitungs-URI der Installation zurück (Environment::googleRedirectUri). Der Adapter ruft dort callback()
 * auf und leitet weiter in die App (appReturnUrl) bzw. auf die Einstellungsseite im WP-Admin. Das Ergebnis steht als
 * deutsche Meldung in der Einstellung RESULT_SETTING; GET /admin/settings liefert es als google.last_result.
 */
final class GoogleConnect
{
    public const RESULT_SETTING = 'drive_oauth_result';
    /** Ziele nach der Rückkehr von Google */
    public const RETURN_APP = 'app';
    public const RETURN_ADMIN = 'admin';
    /** Parameter der Rückkehr in die App: Die Einstellungen zeigen dann google.last_result an. */
    public const APP_PARAM = 'google';

    public function __construct(private readonly App $app)
    {
    }

    /** Stammt das state von dieser App (und nicht z. B. von einem anderen Plugin auf derselben Weiterleitungs-URI)? */
    public static function isOwnState(mixed $state): bool
    {
        return is_string($state) && str_starts_with($state, DriveClient::STATE_PREFIX);
    }

    /**
     * Speichert den Client und gibt die Adresse der Anmeldung bei Google zurück.
     *
     * @throws ApiError 401/403 ohne Admin, 400 bei ungültigem Client
     */
    public function start(mixed $clientId, mixed $clientSecret, string $return = self::RETURN_APP): string
    {
        $user = $this->admin();
        try {
            $this->app->drive()->saveClient(is_string($clientId) ? $clientId : '', is_string($clientSecret) ? $clientSecret : '');
        } catch (\RuntimeException $e) {
            throw ApiError::invalid($e->getMessage());
        }
        $return = $return === self::RETURN_ADMIN ? self::RETURN_ADMIN : self::RETURN_APP;
        $state = $this->app->drive()->createState($user->id, $this->app->authenticator->sessionKey(), $return);
        return $this->app->drive()->authorizeUrl($this->app->env->googleRedirectUri(), $state);
    }

    /**
     * Rückkehr von Google. Prüft Admin, state (Benutzer, Sitzung, Ablauf) und tauscht den Code ein. Das Ergebnis wird
     * als Meldung gespeichert (lastResult) und zurückgegeben.
     *
     * @param array<string, mixed> $query Parameter der Rückkehr (state, code oder error)
     * @return array{ok: bool, message: string, return: string}
     * @throws ApiError 401/403, wenn kein Admin angemeldet ist (dann wird nichts gespeichert)
     */
    public function callback(array $query): array
    {
        $state = is_string($query['state'] ?? null) ? $query['state'] : '';
        $return = DriveClient::stateReturn($state) === self::RETURN_ADMIN ? self::RETURN_ADMIN : self::RETURN_APP;
        $user = $this->admin();
        $drive = $this->app->drive();
        if (!$drive->checkState($state, $user->id, $this->app->authenticator->sessionKey())) {
            return $this->result(false, 'Die Anmeldung bei Google ist abgelaufen oder ungültig. Bitte noch einmal „Speichern und mit Google verbinden“ wählen.', $return);
        }
        $error = is_string($query['error'] ?? null) ? Text::line($query['error'], 100) : '';
        if ($error !== '') {
            return $this->result(false, $error === 'access_denied'
                ? 'Die Anmeldung bei Google wurde abgebrochen. Es ist nichts verbunden.'
                : 'Google meldet einen Fehler: ' . $error, $return);
        }
        $hadCalendarIds = $this->app->calendar()->calendarIds() !== [];
        try {
            $drive->exchangeCode(is_string($query['code'] ?? null) ? $query['code'] : '', $this->app->env->googleRedirectUri());
        } catch (\RuntimeException $e) {
            return $this->result(false, 'Die Verbindung mit Google ist fehlgeschlagen: ' . $e->getMessage(), $return);
        }
        $this->changed($hadCalendarIds);
        $email = $drive->oauthEmail();
        $message = 'Mit Google verbunden' . ($email !== '' ? ' (' . $email . ')' : '') . '.';
        $message .= $drive->calendarAccess()
            ? ' Die Verbindung darf Google Drive und Google Kalender lesen.'
            : ' Den Kalender darf die Verbindung nicht lesen (Häkchen nicht gesetzt). Für einen privaten Kalender trenne sie und verbinde neu, dabei beide Häkchen setzen.';
        return $this->result(true, $message, $return);
    }

    /** Trennt die Verbindung und widerruft sie bei Google; Client und Dienstkonto bleiben. */
    public function disconnect(): void
    {
        $this->admin();
        $hadCalendarIds = $this->app->calendar()->calendarIds() !== [];
        $this->app->drive()->disconnect();
        $this->app->settings->delete(self::RESULT_SETTING);
        $this->changed($hadCalendarIds);
    }

    /** @return array{ok: bool, message: string, at: int}|null Ergebnis der letzten Rückkehr von Google */
    public function lastResult(): ?array
    {
        $r = $this->app->settings->get(self::RESULT_SETTING);
        if (!is_array($r) || !is_string($r['message'] ?? null)) {
            return null;
        }
        return ['ok' => (bool) ($r['ok'] ?? false), 'message' => $r['message'], 'at' => (int) ($r['at'] ?? 0)];
    }

    /** Adresse der App nach der Rückkehr: Einstellungen, mit Hinweis auf das Ergebnis. */
    public function appReturnUrl(): string
    {
        $url = $this->app->env->appUrl();
        return $url . (str_contains($url, '?') ? '&' : '?') . self::APP_PARAM . '=1#/einstellungen';
    }

    /** Galerie und Kalender nach einem Wechsel des Zugangs: Aufgaben neu planen, Kalender ggf. neu laden. */
    private function changed(bool $hadCalendarIds): void
    {
        if ($hadCalendarIds) {
            $this->app->calendar()->clearCache();
        }
        $this->app->scheduleTasks();
    }

    /** @return array{ok: bool, message: string, return: string} */
    private function result(bool $ok, string $message, string $return): array
    {
        $this->app->settings->set(self::RESULT_SETTING, ['ok' => $ok, 'message' => $message, 'at' => $this->app->time()->timestamp()]);
        return ['ok' => $ok, 'message' => $message, 'return' => $return];
    }

    private function admin(): User
    {
        $user = $this->app->authContext->currentUser();
        if ($user === null) {
            throw new ApiError(401, 'not_logged_in', 'Bitte zuerst als Admin anmelden und die Verbindung mit Google dann erneut starten.');
        }
        if (!$user->can(Capability::ManageSettings)) {
            throw ApiError::forbidden('Nur Admins können die App mit Google verbinden.');
        }
        return $user;
    }
}
