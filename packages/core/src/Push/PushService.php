<?php

declare(strict_types=1);

namespace KompanieApp\Core\Push;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Port\ExtendedHttpClient;
use KompanieApp\Core\Storage\PushRepository;

/**
 * Web Push für Mitglieder und Gäste (Issue #19).
 *
 * - Ereignistypen je Bereich (#7): Typen eines ausgeschalteten Bereichs werden weder angeboten noch verschickt.
 * - Abos je Gerät; Gäste (Konto 0) für öffentliche Termine, Erinnerungen und Neuigkeiten. Ein Gast ändert oder
 *   löscht sein Abo nur mit dem auth-Geheimnis des Geräts; neue Gast-Abos sind begrenzt.
 * - Versand im Hintergrund über die Warteschlange (Aufgabe push_send, nach dem Einreihen vorgezogen), parallel,
 *   wenn der HTTP-Client das kann; 404/410 löscht das Abo, 429/5xx wird begrenzt wiederholt.
 * - Abgleich alle 15 Minuten (push_watch): interner Kalender und Neuigkeiten gegen den letzten Stand (beim ersten
 *   Lauf nur merken), Erinnerung an die Termine von morgen ab der eingestellten Uhrzeit, einmal am Tag.
 *
 * Die Nachricht enthält Titel, Text, Ziel in der App (route) und tag; das Symbol setzt der Service Worker.
 *
 * @phpstan-import-type Subscription from PushRepository
 * @phpstan-import-type QueueItem from PushRepository
 * @phpstan-type Message array{title: string, body: string, route: string, tag: string}
 */
final class PushService
{
    public const TASK_SEND = 'push_send';
    public const TASK_WATCH = 'push_watch';
    public const WATCH_INTERVAL = 900;

    /** Diese Einstellungen gehören zur Installation (Backup::LOCAL_SETTINGS). */
    public const VAPID_SETTING = 'push_vapid';
    public const CALENDAR_SETTING = 'push_calendar_seen';
    public const NEWS_SETTING = 'push_news_seen';
    public const REMINDER_DATE_SETTING = 'push_reminder_date';
    /** Uhrzeit der Erinnerung (Stunde 0–23, Zeitzone der Kompanie); Einstellung der Admins. */
    public const REMINDER_HOUR_SETTING = 'push_reminder_hour';
    public const REMINDER_HOUR_DEFAULT = 18;
    /** Bis zu dieser Zahl von Stunden nach der Uhrzeit wird die Erinnerung noch verschickt (später nicht mehr). */
    private const REMINDER_WINDOW = 4;

    public const TYPE_PENALTIES = 'penalties';
    public const TYPE_POINTS = 'points';
    public const TYPE_EVENTS = 'events';
    public const TYPE_REMINDER = 'reminder';
    public const TYPE_NEWS = 'news';

    /** Push-Dienste der Browser (wie im Projekt-Cockpit). */
    private const PUSH_HOSTS = ['/^fcm\.googleapis\.com$/', '/(^|\.)push\.services\.mozilla\.com$/', '/(^|\.)push\.apple\.com$/', '/(^|\.)notify\.windows\.com$/'];
    public const MAX_PER_USER = 10;
    public const MAX_GUESTS = 2000;
    /** Neue Gast-Abos je Gerät/Adresse und Stunde */
    public const GUEST_RATE = 20;
    private const BATCH = 50;
    private const MAX_ATTEMPTS = 3;
    public const TTL = 86400;
    /** Ab so vielen neuen Kalenderterminen auf einmal eine Sammelnachricht */
    private const SUMMARY_FROM = 4;
    /** Neue Termine weiter in der Zukunft lösen nichts aus (z. B. ein ganzer Jahreskalender). */
    private const NEW_EVENT_HORIZON = 300 * 86400;

    private ?PushRepository $repo = null;

    public function __construct(private readonly App $app)
    {
    }

    public function repository(): PushRepository
    {
        return $this->repo ??= new PushRepository($this->app->db);
    }

    /** @return string[] fehlende Voraussetzungen des Servers */
    public function requirements(): array
    {
        return WebPushCrypto::requirements();
    }

    public function supported(): bool
    {
        return $this->requirements() === [];
    }

    /**
     * VAPID-Schlüsselpaar, beim ersten Bedarf erzeugt; der private Schlüssel wird nie ausgegeben.
     *
     * @return array{public: string, private: string}|null
     */
    private function vapid(): ?array
    {
        $keys = $this->app->settings->get(self::VAPID_SETTING);
        if (is_array($keys) && is_string($keys['public'] ?? null) && is_string($keys['private'] ?? null)) {
            return ['public' => $keys['public'], 'private' => $keys['private']];
        }
        if (!$this->supported()) {
            return null;
        }
        $keys = WebPushCrypto::newKeyPair();
        $this->app->settings->set(self::VAPID_SETTING, $keys);
        return $keys;
    }

    public function publicKey(): ?string
    {
        return $this->vapid()['public'] ?? null;
    }

    /* ---------------------------------------------------------------- */
    /* Ereignistypen                                                    */
    /* ---------------------------------------------------------------- */

    /**
     * Typen mit Bezeichnung, Bereichen (einer davon muss eingeschaltet sein) und ob nur Mitglieder sie bekommen.
     *
     * @return array<string, array{label: string, features: list<string>, members: bool}>
     */
    public function types(): array
    {
        $c = $this->app->config;
        return [
            self::TYPE_PENALTIES => ['label' => $c->sectionLabel('penalties', 'Strafen') . ': neue Einträge', 'features' => ['penalties'], 'members' => true],
            self::TYPE_POINTS => ['label' => $c->sectionLabel('points', 'Punkte') . ': neue Einträge', 'features' => ['points'], 'members' => true],
            self::TYPE_EVENTS => ['label' => $c->sectionLabel('events', 'Termine') . ': neue und geänderte', 'features' => ['events', 'calendar'], 'members' => false],
            self::TYPE_REMINDER => ['label' => 'Erinnerung am Vortag', 'features' => ['events', 'calendar'], 'members' => false],
            self::TYPE_NEWS => ['label' => $c->sectionLabel('news', 'News') . ': neue Beiträge', 'features' => ['news'], 'members' => false],
        ];
    }

    /** @return list<string> Typen, die dieses Konto (null = Gast) abonnieren kann */
    public function availableTypes(?User $user): array
    {
        $member = $user !== null && $user->isMember();
        $out = [];
        foreach ($this->types() as $key => $type) {
            if (($type['members'] && !$member) || !$this->anyEnabled($type['features'])) {
                continue;
            }
            // Termine und Erinnerungen: Mitglieder mit Kalender bekommen sie aus dem Kalender, alle anderen aus
            // den öffentlichen Terminen; ohne passenden Bereich gibt es sie für dieses Konto nicht.
            if (in_array($key, [self::TYPE_EVENTS, self::TYPE_REMINDER], true)
                && !($this->usesCalendar($user) || $this->app->features()->enabled('events'))) {
                continue;
            }
            $out[] = $key;
        }
        return $out;
    }

    /** Termine aus dem internen Kalender (Mitglieder, wenn der Bereich Kalender an ist) oder aus der öffentlichen Liste. */
    public function usesCalendar(?User $user): bool
    {
        return $user !== null && $user->isMember() && $this->app->features()->enabled('calendar');
    }

    /** @param list<string> $features */
    private function anyEnabled(array $features): bool
    {
        foreach ($features as $f) {
            if ($this->app->features()->enabled($f)) {
                return true;
            }
        }
        return false;
    }

    /* ---------------------------------------------------------------- */
    /* Abos                                                             */
    /* ---------------------------------------------------------------- */

    /**
     * Legt ein Abo an oder übernimmt es (z. B. wenn sich auf dem Gerät jemand anderes angemeldet hat). Übernehmen
     * darf nur dasselbe Gerät (gleiches auth-Geheimnis). Ohne neue Angaben behält ein Abo desselben Kontos seine
     * Einstellungen.
     *
     * @param mixed $types Liste von Typen oder null
     * @param string $client Kennung des Anfragenden (für die Begrenzung neuer Gast-Abos)
     * @return Subscription
     */
    public function subscribe(?User $user, mixed $endpoint, mixed $keys, mixed $types, mixed $details, string $client): array
    {
        if (!$this->supported()) {
            throw new ApiError(501, 'push_unsupported', 'Push-Benachrichtigungen werden auf diesem Server nicht unterstützt.');
        }
        $endpoint = self::validEndpoint($endpoint);
        $keys = is_array($keys) ? $keys : [];
        $p256dh = WebPushCrypto::b64uDecode(is_string($keys['p256dh'] ?? null) ? $keys['p256dh'] : '');
        $auth = WebPushCrypto::b64uDecode(is_string($keys['auth'] ?? null) ? $keys['auth'] : '');
        if ($p256dh === null || WebPushCrypto::publicKeyFromRaw($p256dh) === null || $auth === null || strlen($auth) !== 16) {
            throw ApiError::invalid('Ungültige Schlüssel des Geräts.');
        }
        $userId = $user?->id ?? 0;
        $repo = $this->repository();
        $existing = $repo->byEndpoint($endpoint);
        $p256dhText = WebPushCrypto::b64uEncode($p256dh);
        $authText = WebPushCrypto::b64uEncode($auth);
        if ($existing !== null && !hash_equals($existing['auth'], $authText)) {
            throw ApiError::forbidden('Dieses Gerät ist bereits anders angemeldet.');
        }
        $allowed = $this->availableTypes($user);
        $same = $existing !== null && $existing['user_id'] === $userId;
        $types = $types !== null ? self::cleanTypes($types, $allowed) : ($same ? self::cleanTypes($existing['types'], $allowed) : $allowed);
        $details = $details !== null ? (bool) $details : ($same && $existing['details']);
        $now = $this->app->time()->nowUtc();
        if ($existing !== null) {
            $repo->update($existing['id'], ['user_id' => $userId, 'p256dh' => $p256dhText, 'auth' => $authText, 'types' => $types, 'details' => $details], $now);
            if (!$same) {
                $repo->clearQueueFor($existing['id']); // Nachrichten für das vorige Konto nicht mehr zustellen
            }
        } else {
            if ($userId === 0) {
                $this->guestLimit($client);
            }
            $repo->insert($userId, $endpoint, $p256dhText, $authText, $types, $details, $now);
            if ($userId > 0) {
                $subs = $repo->forUser($userId);
                foreach (array_slice($subs, 0, max(0, count($subs) - self::MAX_PER_USER)) as $old) {
                    $repo->delete($old['id']);
                }
            }
        }
        return $repo->byEndpoint($endpoint) ?? throw ApiError::invalid('Das Abo konnte nicht gespeichert werden.');
    }

    /**
     * Abo zum Endpoint, das dem Anfragenden gehört; Konten über die Anmeldung, Gäste über das auth-Geheimnis.
     *
     * @return Subscription
     */
    public function own(?User $user, mixed $endpoint, mixed $auth): array
    {
        $sub = $this->repository()->byEndpoint(self::validEndpoint($endpoint));
        $ok = $sub !== null && $sub['user_id'] === ($user?->id ?? 0);
        if ($ok && $user === null) {
            $raw = WebPushCrypto::b64uDecode(is_string($auth) ? $auth : '');
            $ok = $raw !== null && hash_equals((string) $sub['auth'], WebPushCrypto::b64uEncode($raw));
        }
        if (!$ok || $sub === null) {
            throw ApiError::notFound('Für dieses Gerät sind keine Benachrichtigungen aktiviert.');
        }
        return $sub;
    }

    /** @return Subscription */
    public function update(?User $user, mixed $endpoint, mixed $auth, mixed $types, mixed $details): array
    {
        $sub = $this->own($user, $endpoint, $auth);
        $values = [];
        if ($types !== null) {
            $values['types'] = self::cleanTypes($types, $this->availableTypes($user));
        }
        if ($details !== null) {
            $values['details'] = (bool) $details;
        }
        $this->repository()->update($sub['id'], $values, $this->app->time()->nowUtc());
        return $this->repository()->byEndpoint($sub['endpoint']) ?? $sub;
    }

    public function unsubscribe(?User $user, mixed $endpoint, mixed $auth): bool
    {
        try {
            $sub = $this->own($user, $endpoint, $auth);
        } catch (ApiError $e) {
            if ($e->status === 404) {
                return false;
            }
            throw $e;
        }
        $this->repository()->delete($sub['id']);
        return true;
    }

    /** Beim Abmelden auf dem Gerät und beim Löschen eines Kontos. */
    public function deleteForUser(int $userId): void
    {
        foreach ($this->repository()->forUser($userId) as $sub) {
            $this->repository()->delete($sub['id']);
        }
    }

    /** @param Subscription|null $sub */
    public static function formatSubscription(?array $sub): ?array
    {
        return $sub === null ? null : ['types' => $sub['types'], 'details' => $sub['details']];
    }

    /**
     * Nur https-Adressen der Push-Dienste der Browser (Chrome/Edge/Android, Firefox, Safari, Windows), ohne
     * Zugangsdaten und eigenen Port: sonst könnte jeder den Server Anfragen an beliebige Adressen schicken lassen.
     */
    private static function validEndpoint(mixed $endpoint): string
    {
        $parts = is_string($endpoint) && strlen($endpoint) <= 2048 ? parse_url($endpoint) : false;
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $known = false;
        foreach (self::PUSH_HOSTS as $pattern) {
            $known = $known || preg_match($pattern, $host) === 1;
        }
        if (!is_string($endpoint) || !is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !$known
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            throw ApiError::invalid('Ungültige Push-Adresse.');
        }
        return $endpoint;
    }

    /**
     * @param list<string> $allowed
     * @return list<string>
     */
    private static function cleanTypes(mixed $raw, array $allowed): array
    {
        if (!is_array($raw)) {
            return $allowed;
        }
        $given = array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $raw);
        return array_values(array_intersect($allowed, $given));
    }

    private function guestLimit(string $client): void
    {
        if ($this->repository()->countGuests() >= self::MAX_GUESTS) {
            throw new ApiError(503, 'push_full', 'Zurzeit sind keine weiteren Abos möglich.');
        }
        if ($this->app->cache->increment('push_guest_' . hash('sha256', $client), 3600) > self::GUEST_RATE) {
            throw new ApiError(429, 'too_many_attempts', 'Zu viele Anfragen. Bitte später erneut versuchen.');
        }
    }

    /* ---------------------------------------------------------------- */
    /* Nachrichten                                                      */
    /* ---------------------------------------------------------------- */

    /** @return Message */
    public static function message(string $title, string $body, string $route, string $tag = ''): array
    {
        return ['title' => mb_substr($title, 0, 100), 'body' => mb_substr($body, 0, 300), 'route' => $route, 'tag' => $tag];
    }

    /**
     * An alle Geräte eines Kontos, die den Typ abonniert haben.
     *
     * @param Message $message
     * @param Message|null $detail Variante mit Einzelheiten für Abos mit „Details anzeigen“
     */
    public function notifyUser(int $userId, string $type, array $message, ?array $detail = null): int
    {
        if (!$this->typeEnabled($type)) {
            return 0;
        }
        $items = [];
        foreach ($this->repository()->forUser($userId) as $sub) {
            if (in_array($type, $sub['types'], true)) {
                $items[] = ['subscription_id' => $sub['id'], 'payload' => self::json($detail !== null && $sub['details'] ? $detail : $message)];
            }
        }
        return $this->enqueue($items);
    }

    /**
     * An alle Abos mit dem Typ.
     *
     * @param Message $message
     * @param bool|null $calendar true = nur Mitglieder mit Kalender, false = alle anderen (Gäste, Konten ohne Kalender)
     */
    public function notifyAll(string $type, array $message, ?bool $calendar = null): int
    {
        if (!$this->typeEnabled($type)) {
            return 0;
        }
        $json = self::json($message);
        $items = [];
        $calendarUsers = [];
        foreach ($this->repository()->all() as $sub) {
            if (!in_array($type, $sub['types'], true)) {
                continue;
            }
            if ($calendar !== null) {
                $uid = $sub['user_id'];
                $calendarUsers[$uid] ??= $uid > 0 && $this->usesCalendar($this->app->users->find($uid));
                if ($calendarUsers[$uid] !== $calendar) {
                    continue;
                }
            }
            $items[] = ['subscription_id' => $sub['id'], 'payload' => $json];
        }
        return $this->enqueue($items);
    }

    private function typeEnabled(string $type): bool
    {
        $t = $this->types()[$type] ?? null;
        return $t !== null && $this->supported() && $this->anyEnabled($t['features']);
    }

    /** @param list<array{subscription_id: int, payload: string}> $items */
    private function enqueue(array $items, string $urgency = 'normal'): int
    {
        if ($items === []) {
            return 0;
        }
        $this->repository()->enqueue($items, $urgency, self::TTL, $this->app->time()->nowUtc());
        $this->app->scheduler->runSoon(self::TASK_SEND);
        return count($items);
    }

    /** @param Message $message */
    private static function json(array $message): string
    {
        return (string) json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /* ---------------------------------------------------------------- */
    /* Versand                                                          */
    /* ---------------------------------------------------------------- */

    /**
     * Verschickt fällige Nachrichten aus der Warteschlange (Aufgabe push_send).
     *
     * @return int erfolgreich zugestellt
     */
    public function processQueue(int $limit = self::BATCH): int
    {
        $repo = $this->repository();
        $now = $this->app->time()->nowUtc();
        $rows = $repo->due($now, $limit);
        $keys = $this->vapid();
        $sent = 0;
        $done = [];
        $requests = [];
        foreach ($rows as $row) {
            if ($row['endpoint'] === null || $row['p256dh'] === null || $row['auth'] === null || $keys === null) {
                $done[] = $row['id'];
                continue;
            }
            try {
                $requests[$row['id']] = $this->request($row, $keys);
            } catch (\RuntimeException $e) {
                error_log('Kompanie-App: Push nicht vorbereitet: ' . $e->getMessage());
                $done[] = $row['id'];
            }
        }
        $responses = $this->sendAll($requests);
        foreach ($rows as $row) {
            if (!isset($requests[$row['id']])) {
                continue;
            }
            $status = self::classify($responses[$row['id']] ?? new \RuntimeException('keine Antwort'));
            if ($status === 'ok') {
                $sent++;
                $done[] = $row['id'];
                $repo->markSent($row['subscription_id'], $now);
            } elseif ($status === 'gone') {
                $repo->delete($row['subscription_id']);
            } elseif ($status === 'retry' && $row['attempts'] + 1 < self::MAX_ATTEMPTS) {
                $repo->retry($row['id'], $row['attempts'] + 1, gmdate('Y-m-d H:i:s', $this->app->time()->timestamp() + 300 * ($row['attempts'] + 1)));
            } else {
                $done[] = $row['id'];
            }
        }
        $repo->dequeue($done);
        // Noch mehr fällig (größer als ein Durchgang): gleich weitermachen.
        $next = $repo->nextDue();
        if ($next !== null && $next <= $now) {
            $this->app->scheduler->runSoon(self::TASK_SEND);
        }
        return $sent;
    }

    /**
     * Testbenachrichtigung sofort an ein Abo.
     *
     * @param Subscription $sub
     */
    public function sendTest(array $sub): void
    {
        $keys = $this->vapid() ?? throw new ApiError(501, 'push_unsupported', 'Push-Benachrichtigungen werden auf diesem Server nicht unterstützt.');
        $row = ['id' => 0, 'subscription_id' => $sub['id'], 'payload' => self::json(self::message($this->app->config->name(), 'Benachrichtigungen funktionieren.', 'benachrichtigungen', 'test')),
            'urgency' => 'high', 'ttl' => 300, 'attempts' => 0, 'endpoint' => $sub['endpoint'], 'p256dh' => $sub['p256dh'], 'auth' => $sub['auth']];
        $res = $this->sendAll([0 => $this->request($row, $keys)])[0] ?? new \RuntimeException('keine Antwort');
        $status = self::classify($res);
        if ($status === 'gone') {
            $this->repository()->delete($sub['id']);
            throw new ApiError(410, 'push_gone', 'Der Push-Dienst kennt dieses Gerät nicht mehr. Bitte Benachrichtigungen neu aktivieren.');
        }
        if ($status !== 'ok') {
            $code = $res instanceof \RuntimeException ? 'keine Verbindung' : 'HTTP ' . $res['status'];
            throw ApiError::upstream('Der Push-Dienst hat die Nachricht nicht angenommen (' . $code . ').');
        }
    }

    /**
     * @param QueueItem $row
     * @param array{public: string, private: string} $keys
     * @return array{method: string, url: string, headers: array<string, string>, body: string}
     */
    private function request(array $row, array $keys): array
    {
        $endpoint = (string) $row['endpoint'];
        $body = WebPushCrypto::encrypt($row['payload'], (string) WebPushCrypto::b64uDecode((string) $row['p256dh']), (string) WebPushCrypto::b64uDecode((string) $row['auth']));
        $jwt = WebPushCrypto::vapidToken($endpoint, $keys['private'], $this->subject(), $this->app->time()->timestamp());
        return ['method' => 'POST', 'url' => $endpoint, 'body' => $body, 'headers' => [
            'Content-Type' => 'application/octet-stream',
            'Content-Encoding' => 'aes128gcm',
            'TTL' => (string) $row['ttl'],
            'Urgency' => $row['urgency'],
            'Authorization' => 'vapid t=' . $jwt . ', k=' . $keys['public'],
        ]];
    }

    /**
     * Parallel, wenn der HTTP-Client das kann (cURL), sonst nacheinander.
     *
     * @param array<int, array{method: string, url: string, headers: array<string, string>, body: string}> $requests
     * @return array<int, array{status: int, headers: array<string, string>, body: string}|\RuntimeException>
     */
    private function sendAll(array $requests): array
    {
        if ($requests === []) {
            return [];
        }
        $http = $this->app->http;
        if ($http instanceof ExtendedHttpClient) {
            return $http->requestAll($requests, 10);
        }
        $out = [];
        foreach ($requests as $key => $r) {
            try {
                $out[$key] = $http->request($r['method'], $r['url'], $r['headers'], $r['body'], 10);
            } catch (\RuntimeException $e) {
                $out[$key] = $e;
            }
        }
        return $out;
    }

    /** @param array{status: int, headers?: array<string, string>, body?: string}|\RuntimeException $res ok | gone | retry | error */
    private static function classify(array|\RuntimeException $res): string
    {
        if ($res instanceof \RuntimeException) {
            return 'retry';
        }
        $code = (int) $res['status'];
        return match (true) {
            $code >= 200 && $code < 300 => 'ok',
            $code === 404 || $code === 410 => 'gone',
            $code === 429 || $code >= 500 => 'retry',
            default => 'error',
        };
    }

    /** Kontakt für die Push-Dienste: die Support-Adresse, sonst die Adresse der App. */
    private function subject(): string
    {
        $support = trim((string) ($this->app->packageConfig['support'] ?? ''));
        if (filter_var($support, FILTER_VALIDATE_EMAIL)) {
            return 'mailto:' . $support;
        }
        return str_starts_with($support, 'https://') ? $support : $this->app->env->appUrl();
    }

    /** @return array{supported: bool, missing: string[], devices: int, accounts: int, guests: int, queued: int, reminderHour: int} */
    public function adminStatus(): array
    {
        return ['supported' => $this->supported(), 'missing' => $this->requirements()] + $this->repository()->counts() + ['reminderHour' => $this->reminderHour()];
    }

    public function reminderHour(): int
    {
        $h = $this->app->settings->get(self::REMINDER_HOUR_SETTING, self::REMINDER_HOUR_DEFAULT);
        return is_numeric($h) && (int) $h >= 0 && (int) $h <= 23 ? (int) $h : self::REMINDER_HOUR_DEFAULT;
    }

    public function setReminderHour(mixed $hour): void
    {
        if (!is_numeric($hour) || (int) $hour < 0 || (int) $hour > 23 || (string) (int) $hour !== (string) $hour) {
            throw ApiError::invalid('Bitte eine volle Stunde von 0 bis 23 angeben.');
        }
        $this->app->settings->set(self::REMINDER_HOUR_SETTING, (int) $hour);
    }
}
