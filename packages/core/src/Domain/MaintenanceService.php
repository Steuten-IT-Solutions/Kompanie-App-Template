<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Push\PushService;

/**
 * Wartungsmodus (Issue #42): Ein Admin schaltet ihn von Hand ein und aus. Solange er an ist, ist die App für alle
 * außer Admins gesperrt (API und geschützte Dateien antworten mit 503 `maintenance`), Hintergrundaufgaben außer
 * Updates und Statusmeldungen pausieren. Die Begründung ist optionaler Klartext und während der Wartung änderbar.
 */
final class MaintenanceService
{
    public const SETTING = 'maintenance';
    public const LOG_SETTING = 'maintenance_log';
    public const REASON_MAX = 500;
    private const LOG_MAX = 20;

    public function __construct(private readonly App $app)
    {
    }

    /** @return array{on: bool, reason: string, since: int, by: int} */
    public function state(): array
    {
        $s = $this->app->settings->get(self::SETTING);
        $s = is_array($s) ? $s : [];
        return [
            'on' => (bool) ($s['on'] ?? false),
            'reason' => (string) ($s['reason'] ?? ''),
            'since' => (int) ($s['since'] ?? 0),
            'by' => (int) ($s['by'] ?? 0),
        ];
    }

    public function active(): bool
    {
        return $this->state()['on'];
    }

    /** Gesperrt für diese Person? Admins (Einstellungen verwalten) arbeiten normal weiter. */
    public function blocks(?User $user): bool
    {
        return $this->active() && !($user?->can(Capability::ManageSettings) ?? false);
    }

    /** Fehler für gesperrte Anfragen, mit der Begründung. */
    public function error(): ApiError
    {
        return new ApiError(503, 'maintenance', 'Die App wird gerade gewartet. Bitte versuche es später noch einmal.', ['reason' => $this->state()['reason']]);
    }

    /**
     * Ein- oder ausschalten bzw. die Begründung ändern. Jede Änderung kommt ins Protokoll (wer, wann).
     *
     * @return array{on: bool, reason: string, since: int, by: int}
     */
    public function set(mixed $on, mixed $reason, User $actor): array
    {
        if (!is_bool($on)) {
            throw ApiError::invalid('Bitte angeben, ob der Wartungsmodus an oder aus sein soll.');
        }
        $before = $this->state();
        $text = $reason === null ? $before['reason'] : Text::multiline($reason);
        if ($reason !== null && !is_string($reason)) {
            throw ApiError::invalid('Die Begründung muss Text sein.');
        }
        if (mb_strlen($text) > self::REASON_MAX) {
            throw ApiError::invalid(sprintf('Die Begründung darf höchstens %d Zeichen lang sein.', self::REASON_MAX));
        }
        $now = $this->app->time()->timestamp();
        $state = $on
            ? ['on' => true, 'reason' => $text, 'since' => $before['on'] ? $before['since'] : $now, 'by' => $before['on'] ? $before['by'] : $actor->id]
            : ['on' => false, 'reason' => '', 'since' => 0, 'by' => 0];
        $this->app->settings->set(self::SETTING, $state);

        $log = $this->app->settings->get(self::LOG_SETTING);
        $log = is_array($log) ? $log : [];
        $log[] = ['at' => $now, 'by' => $actor->id, 'on' => $on, 'reason' => $state['reason']];
        $this->app->settings->set(self::LOG_SETTING, array_slice($log, -self::LOG_MAX));

        // Nach der Wartung liegengebliebene Benachrichtigungen gleich verschicken.
        if ($before['on'] && !$on) {
            $this->app->scheduler->runSoon(PushService::TASK_SEND);
        }
        return $state;
    }

    /** @return list<array{at: int, by: int, by_name: string, on: bool, reason: string}> neueste zuerst */
    public function log(): array
    {
        $log = $this->app->settings->get(self::LOG_SETTING);
        $log = is_array($log) ? $log : [];
        $names = (new UserNames($this->app->users))->prime(array_column($log, 'by'));
        $out = [];
        foreach (array_reverse($log) as $e) {
            $out[] = ['at' => (int) $e['at'], 'by' => (int) $e['by'], 'by_name' => (string) $names->name((int) $e['by']), 'on' => (bool) $e['on'], 'reason' => (string) $e['reason']];
        }
        return $out;
    }

    /** Aufgaben, die auch während der Wartung laufen: Updates (sollen möglich bleiben) und Statusmeldungen. */
    public static function runsDuringMaintenance(string $task): bool
    {
        return in_array($task, [\KompanieApp\Core\Update\UpdateService::TASK, \KompanieApp\Core\Update\StatusReporter::TASK], true);
    }
}
