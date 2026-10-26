<?php

declare(strict_types=1);

namespace KompanieApp\Core\Push;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Domain\BoardService;
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
 * - Abgleich alle 15 Minuten (push_watch): interner Kalender (jedes Mal frisch abgerufen) und Neuigkeiten gegen den
 *   letzten Stand (beim ersten Lauf nur merken), Erinnerung an die Termine von morgen ab der eingestellten Uhrzeit,
 *   einmal am Tag. Was er einreiht, geht im selben Lauf raus (App::runTask).
 *
 * - Schwarzes Brett (Issue #5): an alle Empfänger außer dem Verfasser, mit Urgency high und einer TTL höchstens bis zum
 *   Ablauf der Nachricht.
 * - Umfragen (Issue #4): neue Umfrage an alle Mitglieder außer dem, der sie angelegt hat; Erinnerung 24 Stunden vor dem
 *   Ende an alle Mitglieder ohne Antwort; TTL höchstens bis zum Ende.
 * - Geburtstage (Issue #1): am Tag selbst an alle Mitglieder außer dem Geburtstagskind; TTL höchstens bis Mitternacht.
 *
 * Die Nachricht enthält Titel, Text, Ziel in der App (route) und tag; das Symbol setzt der Service Worker. Optional
 * image: vollständige Adresse eines Bildes (Abzeichen bei Beförderungen, Issue #17), das der Service Worker als Bild
 * und Symbol der Benachrichtigung zeigt.
 *
 * @phpstan-import-type Subscription from PushRepository
 * @phpstan-import-type QueueItem from PushRepository
 * @phpstan-type Message array{title: string, body: string, route: string, tag: string, image?: string}
 */
final class PushService
{
    public const TASK_SEND = 'push_send';
    public const TASK_WATCH = 'push_watch';
    public const WATCH_INTERVAL = 900;

    /** Schlüssel für Web Push; mit den Abos in der Sicherung, damit Benachrichtigungen nach einem Umzug weiter ankommen. */
    public const VAPID_SETTING = 'push_vapid';
    /** Diese Einstellungen gehören zur Installation (Backup::LOCAL_SETTINGS). */
    public const CALENDAR_SETTING = 'push_calendar_seen';
    public const NEWS_SETTING = 'push_news_seen';
    /** Neue Art „Schwarzes Brett“ den bestehenden Abos schon einmal hinzugefügt (beim ersten Einschalten des Bereichs). */
    public const BOARD_OFFERED_SETTING = 'push_board_offered';
    /** Ebenso für die Art „Umfragen“ (Issue #4). */
    public const POLLS_OFFERED_SETTING = 'push_polls_offered';
    /** Ebenso für die Art „Geburtstage“ (Issue #1). */
    public const BIRTHDAYS_OFFERED_SETTING = 'push_birthdays_offered';
    public const REMINDER_DATE_SETTING = 'push_reminder_date';
    /** Uhrzeit der Erinnerung (Stunde 0–23, Zeitzone der Kompanie); Einstellung der Admins. */
    public const REMINDER_HOUR_SETTING = 'push_reminder_hour';
    public const REMINDER_HOUR_DEFAULT = 18;
    /** Bis zu dieser Zahl von Stunden nach der Uhrzeit wird die Erinnerung noch verschickt (später nicht mehr). */
    private const REMINDER_WINDOW = 4;

    public const TYPE_PENALTIES = 'penalties';
    public const TYPE_POINTS = 'points';
    public const TYPE_RANKS = 'ranks';
    public const TYPE_TITLES = 'titles';
    public const TYPE_EVENTS = 'events';
    public const TYPE_REMINDER = 'reminder';
    public const TYPE_NEWS = 'news';
    public const TYPE_BOARD = 'board';
    public const TYPE_POLLS = 'polls';
    public const TYPE_BIRTHDAYS = 'birthdays';

    /** Push-Dienste der Browser (wie im Projekt-Cockpit). */
    private const PUSH_HOSTS = ['/^fcm\.googleapis\.com$/', '/(^|\.)push\.services\.mozilla\.com$/', '/(^|\.)push\.apple\.com$/', '/(^|\.)notify\.windows\.com$/'];
    public const MAX_PER_USER = 10;
    private const GUEST_EXPIRY_DAYS = 180;
    public const MAX_GUESTS = 2000;
    /** Neue Gast-Abos je Gerät/Adresse und Stunde */
    public const GUEST_RATE = 20;
    private const BATCH = 50;
    private const BATCH_SEQUENTIAL = 20;
    /** Sekunden, die beanspruchte Nachrichten einem Lauf gehören */
    private const LEASE = 600;
    private const MAX_ATTEMPTS = 3;
    public const TTL = 86400;
    /**
     * Obergrenze der Zustelldauer bei den Push-Diensten (Issue #172): Firebase Cloud Messaging (Chrome, Android) nimmt
     * 0 bis 2 419 200 Sekunden (28 Tage) an; das ist die kleinste dokumentierte Grenze der verbreiteten Dienste.
     * Gilt für das Schwarze Brett, dessen Nachrichten beliebig lange sichtbar sein können.
     */
    public const MAX_TTL = 2419200;
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
     * VAPID-Schlüsselpaar, erzeugt beim Einplanen der Aufgaben (App::scheduleTasks, nach Einrichtung und Update),
     * sonst beim ersten Bedarf; der private Schlüssel wird nie ausgegeben.
     *
     * @return array{public: string, private: string}|null
     */
    private function vapid(): ?array
    {
        $stored = static fn (mixed $k): ?array => is_array($k) && is_string($k['public'] ?? null) && is_string($k['private'] ?? null)
            ? ['public' => $k['public'], 'private' => $k['private']] : null;
        $keys = $stored($this->app->settings->get(self::VAPID_SETTING));
        if ($keys !== null || !$this->supported()) {
            return $keys;
        }
        // Hat eine gleichzeitige Anfrage gerade eins erzeugt, dieses nehmen (sonst passten Abos nicht zum Schlüssel).
        $keys = $stored($this->app->settings->fresh(self::VAPID_SETTING)) ?? WebPushCrypto::newKeyPair();
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
     * Typen mit Bezeichnung, Bereichen (einer davon muss eingeschaltet sein) und wer sie bekommt: members = nur
     * Mitglieder, board = Empfänger des Schwarzen Bretts (Mitglieder und Anwärter, BoardService::isRecipient),
     * all = alle einschließlich Gästen.
     *
     * @return array<string, array{label: string, features: list<string>, audience: 'members'|'board'|'all'}>
     */
    public function types(): array
    {
        $c = $this->app->config;
        return [
            self::TYPE_BOARD => ['label' => $c->sectionLabel('board', 'Schwarzes Brett') . ': dringende Nachrichten', 'features' => ['board'], 'audience' => 'board'],
            self::TYPE_POLLS => ['label' => $c->sectionLabel('polls', 'Umfragen') . ': neue Umfrage und Erinnerung', 'features' => ['polls'], 'audience' => 'members'],
            self::TYPE_BIRTHDAYS => ['label' => $c->sectionLabel('birthdays', 'Geburtstage') . ': am Tag selbst', 'features' => ['birthdays'], 'audience' => 'members'],
            self::TYPE_PENALTIES => ['label' => $c->sectionLabel('penalties', 'Strafen') . ': neue Einträge', 'features' => ['penalties'], 'audience' => 'members'],
            self::TYPE_POINTS => ['label' => $c->sectionLabel('points', 'Punkte') . ': neue Einträge', 'features' => ['points'], 'audience' => 'members'],
            self::TYPE_RANKS => ['label' => $c->rankLabel() . ': Beförderung oder Änderung', 'features' => ['ranks'], 'audience' => 'members'],
            self::TYPE_TITLES => ['label' => $c->sectionLabel('titles', 'Titel') . ': neu verliehen', 'features' => ['titles'], 'audience' => 'members'],
            self::TYPE_EVENTS => ['label' => $c->sectionLabel('events', 'Termine') . ': neue und geänderte', 'features' => ['events', 'calendar'], 'audience' => 'all'],
            self::TYPE_REMINDER => ['label' => 'Erinnerung am Vortag', 'features' => ['events', 'calendar'], 'audience' => 'all'],
            self::TYPE_NEWS => ['label' => $c->sectionLabel('news', 'News') . ': neue Beiträge', 'features' => ['news'], 'audience' => 'all'],
        ];
    }

    /** @return list<string> Typen, die dieses Konto (null = Gast) abonnieren kann */
    public function availableTypes(?User $user): array
    {
        $allowed = ['all' => true, 'members' => $user !== null && $user->isMember(), 'board' => BoardService::isRecipient($user)];
        $out = [];
        foreach ($this->types() as $key => $type) {
            if (!$allowed[$type['audience']] || !$this->anyEnabled($type['features'])) {
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
        // Wie die App (Bereich an und eingerichtet): Sonst sehen Mitglieder die öffentliche Terminliste.
        return $user !== null && $user->can(Capability::Calendar) && $this->app->features()->enabled('calendar') && $this->app->calendar()->isConfigured();
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
        self::requireTypes($types);
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
            $values['types'] = self::requireTypes(self::cleanTypes($types, $this->availableTypes($user)));
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
        // Nur einfache Hostnamen und keine Sonderzeichen in der Adresse (Unterschiede zwischen parse_url und cURL).
        $known = false;
        if (preg_match('/^[a-z0-9.-]+$/', $host) !== 1 || !is_string($endpoint) || preg_match('{^https://[A-Za-z0-9.-]+/[A-Za-z0-9._~:/?#\[\]@!$&\'()*+,;=%-]*$}', $endpoint) !== 1) {
            $host = '';
        }
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

    /**
     * Ein Abo ohne Arten bekäme nie eine Nachricht, würde also auch nie als abgemeldet erkannt und belegte nur
     * einen der begrenzten Plätze für Gäste.
     *
     * @param list<string> $types
     * @return list<string>
     */
    private static function requireTypes(array $types): array
    {
        if ($types === []) {
            throw ApiError::invalid('Bitte mindestens eine Art von Benachrichtigungen auswählen.');
        }
        return $types;
    }

    private function guestLimit(string $client): void
    {
        if ($this->repository()->countGuests() >= self::MAX_GUESTS) {
            throw new ApiError(503, 'push_full', 'Zurzeit sind keine weiteren Abos möglich.');
        }
        if ($this->app->cache->increment('push_guest_' . hash('sha256', self::clientKey($client)), 3600) > self::GUEST_RATE) {
            throw new ApiError(429, 'too_many_attempts', 'Zu viele Anfragen. Bitte später erneut versuchen.');
        }
    }

    /**
     * IPv6: das ganze /64-Netz zählt als ein Anfragender (so viele Adressen hat jeder Anschluss). IPv4 in IPv6-Form
     * (::ffff:a.b.c.d, manche Server melden IPv4 so) zählt als die IPv4-Adresse.
     */
    public static function clientKey(string $client): string
    {
        $bin = filter_var($client, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? inet_pton($client) : false;
        if ($bin === false) {
            return $client;
        }
        if (str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
            return (string) inet_ntop(substr($bin, 12));
        }
        return bin2hex(substr($bin, 0, 8)) . '::/64';
    }

    /**
     * Vermerkt, dass die App auf dem Gerät noch genutzt wird (höchstens einmal am Tag ein Schreibzugriff).
     *
     * @param Subscription $sub
     */
    public function seen(array $sub): void
    {
        $now = $this->app->time()->timestamp();
        if ($sub['updated_at'] === '' || (int) strtotime($sub['updated_at'] . ' UTC') < $now - 86400) {
            $this->repository()->update($sub['id'], [], gmdate('Y-m-d H:i:s', $now));
        }
    }

    /**
     * Gast-Abos, die seit GUEST_EXPIRY_DAYS weder geändert noch in der App aufgerufen wurden und keine Nachricht
     * bekommen haben, entfernen
     * (z. B. Geräte, die es nicht mehr gibt, oder Abos, deren Push-Dienst Fehler statt „abgemeldet“ meldet).
     */
    public function expireGuests(): int
    {
        $limit = gmdate('Y-m-d H:i:s', $this->app->time()->timestamp() - self::GUEST_EXPIRY_DAYS * 86400);
        return $this->repository()->deleteStaleGuests($limit);
    }

    /**
     * Gehört das Abo dieses Geräts (nachgewiesen durch sein auth-Geheimnis) einem Konto, das hier nicht mehr
     * angemeldet ist (abgemeldet ohne die App, Sitzung abgelaufen, jemand anderes angemeldet), wird es entfernt:
     * Sonst bekäme der nächste Mensch am Gerät die Nachrichten des Kontos.
     */
    public function releaseForeign(?User $user, mixed $endpoint, mixed $auth): bool
    {
        $raw = WebPushCrypto::b64uDecode(is_string($auth) ? $auth : '');
        try {
            $sub = $raw === null ? null : $this->repository()->byEndpoint(self::validEndpoint($endpoint));
        } catch (ApiError) {
            return false;
        }
        if ($sub === null || $sub['user_id'] === 0 || $sub['user_id'] === ($user?->id ?? 0) || !hash_equals($sub['auth'], WebPushCrypto::b64uEncode((string) $raw))) {
            return false;
        }
        $this->repository()->delete($sub['id']);
        return true;
    }

    /* ---------------------------------------------------------------- */
    /* Nachrichten                                                      */
    /* ---------------------------------------------------------------- */

    /** @return Message */
    public static function message(string $title, string $body, string $route, string $tag = '', ?string $image = null): array
    {
        return ['title' => mb_substr($title, 0, 100), 'body' => mb_substr($body, 0, 300), 'route' => $route, 'tag' => $tag]
            + ($image !== null && $image !== '' ? ['image' => $image] : []);
    }

    /**
     * An alle Geräte eines Kontos, die den Typ abonniert haben.
     *
     * @param Message $message
     * @param Message|null $detail Variante mit Einzelheiten für Abos mit „Details anzeigen“
     */
    public function notifyUser(int $userId, string $type, array $message, ?array $detail = null): int
    {
        return $this->notifyUsers($type, [[$userId, $message, $detail]]);
    }

    /**
     * Je Konto eine eigene Nachricht (z. B. Punkte für viele Personen auf einmal): die Abos aller Konten mit einer
     * Abfrage, die Warteschlange mit einem gesammelten INSERT (Issue #18).
     *
     * @param list<array{0: int, 1: Message, 2?: Message|null}> $messages [Benutzer-ID, Nachricht, Variante mit Einzelheiten]
     */
    public function notifyUsers(string $type, array $messages): int
    {
        if ($messages === [] || !$this->typeEnabled($type)) {
            return 0;
        }
        $subs = $this->repository()->forUsers(array_map(static fn (array $m): int => $m[0], $messages));
        $items = [];
        foreach ($messages as $m) {
            [$userId, $message] = $m;
            $detail = $m[2] ?? null;
            foreach ($subs[$userId] ?? [] as $sub) {
                if (in_array($type, $sub['types'], true)) {
                    $items[] = ['subscription_id' => $sub['id'], 'payload' => $this->json($detail !== null && $sub['details'] ? $detail : $message)];
                }
            }
        }
        return $this->enqueue($items);
    }

    /**
     * Konten der Abos vorab mit einer Abfrage laden (für die Prüfung des Empfängerkreises).
     *
     * @param list<Subscription> $subs
     * @return array<int, User> Benutzer-ID => Konto
     */
    private function accountsOf(array $subs): array
    {
        return $this->app->users->findMany(array_values(array_unique(array_filter(array_column($subs, 'user_id'), static fn (int $id): bool => $id > 0))));
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
        $json = $this->json($message);
        $items = [];
        $calendarUsers = [];
        $subs = array_values(array_filter($this->repository()->all(), static fn (array $sub): bool => in_array($type, $sub['types'], true)));
        $accounts = $calendar !== null ? $this->accountsOf($subs) : [];
        foreach ($subs as $sub) {
            if ($calendar !== null) {
                $uid = $sub['user_id'];
                $calendarUsers[$uid] ??= $uid > 0 && $this->usesCalendar($accounts[$uid] ?? null);
                if ($calendarUsers[$uid] !== $calendar) {
                    continue;
                }
            }
            $items[] = ['subscription_id' => $sub['id'], 'payload' => $json];
        }
        return $this->enqueue($items);
    }

    /**
     * Schwarzes Brett (Issue #5): an alle Geräte der Empfänger mit dem Typ, außer denen des Verfassers. Dringend
     * (Urgency high) und nur so lange zustellbar, wie die Nachricht gilt, höchstens MAX_TTL (Issue #172).
     *
     * @param Message $message
     * @param int $ttl Sekunden bis zum Ablauf der Nachricht (höchstens MAX_TTL)
     */
    public function notifyBoard(array $message, int $authorId, int $ttl): int
    {
        return $this->notifyAudience(self::TYPE_BOARD, $message, static fn (int $uid): bool => $uid !== $authorId, 'high', $ttl, self::MAX_TTL);
    }

    /**
     * An alle Geräte von Konten aus dem Empfängerkreis des Typs (types(): audience), die den Typ abonniert haben und
     * die $include zulässt (z. B. nicht der Verfasser, nur wer noch nicht abgestimmt hat).
     *
     * @param Message $message
     * @param \Closure(int): bool $include Benutzer-ID → bekommt die Nachricht
     * @param int $ttl Sekunden, höchstens $maxTtl; 0 oder weniger: nichts
     * @param int $maxTtl Obergrenze (Standard TTL, höchstens MAX_TTL)
     */
    public function notifyAudience(string $type, array $message, \Closure $include, string $urgency = 'normal', int $ttl = self::TTL, int $maxTtl = self::TTL): int
    {
        if (!$this->typeEnabled($type) || $ttl <= 0) {
            return 0;
        }
        $audience = $this->types()[$type]['audience'];
        $json = $this->json($message);
        $items = [];
        $recipients = [];
        $subs = array_values(array_filter($this->repository()->all(), static fn (array $sub): bool => $sub['user_id'] > 0 && in_array($type, $sub['types'], true)));
        $accounts = $this->accountsOf($subs);
        foreach ($subs as $sub) {
            $uid = $sub['user_id'];
            $recipients[$uid] ??= $include($uid) && $this->inAudience($audience, $accounts[$uid] ?? null);
            if ($recipients[$uid]) {
                $items[] = ['subscription_id' => $sub['id'], 'payload' => $json];
            }
        }
        return $this->enqueue($items, $urgency, min($maxTtl, self::MAX_TTL, $ttl));
    }

    /** @param 'members'|'board'|'all' $audience */
    private function inAudience(string $audience, ?User $user): bool
    {
        return match ($audience) {
            'members' => $user !== null && $user->isMember(),
            'board' => BoardService::isRecipient($user),
            'all' => true,
        };
    }

    /**
     * Beim ersten Einschalten eines Bereichs mit eigener Art (Schwarzes Brett, Umfragen): die neue Art bei allen
     * bestehenden Abos der Konten aus ihrem Empfängerkreis einschalten (wie bei neuen Abos, die alle angebotenen Arten
     * bekommen). Einmalig je Art (Einstellung push_<art>_offered); danach entscheidet jedes Gerät selbst, erneutes
     * Ein- und Ausschalten des Bereichs ändert die Abos nicht mehr.
     */
    public function offerTypeToExisting(string $type): int
    {
        $definition = $this->types()[$type] ?? null;
        $setting = 'push_' . $type . '_offered';
        if ($definition === null || $this->app->settings->get($setting, false)) {
            return 0;
        }
        $this->app->settings->set($setting, true);
        $now = $this->app->time()->nowUtc();
        $order = array_flip(array_keys($this->types()));
        $recipients = [];
        $count = 0;
        $subs = array_values(array_filter($this->repository()->all(), static fn (array $sub): bool => $sub['user_id'] > 0 && !in_array($type, $sub['types'], true)));
        $accounts = $this->accountsOf($subs);
        foreach ($subs as $sub) {
            $uid = $sub['user_id'];
            $recipients[$uid] ??= $this->inAudience($definition['audience'], $accounts[$uid] ?? null);
            if ($recipients[$uid]) {
                // In der üblichen Reihenfolge der Arten (wie bei neuen Abos).
                $types = [...$sub['types'], $type];
                usort($types, static fn (string $a, string $b): int => ($order[$a] ?? 999) <=> ($order[$b] ?? 999));
                $this->repository()->update($sub['id'], ['types' => $types], $now);
                $count++;
            }
        }
        return $count;
    }

    private function typeEnabled(string $type): bool
    {
        $t = $this->types()[$type] ?? null;
        return $t !== null && $this->supported() && $this->anyEnabled($t['features']);
    }

    /** @param list<array{subscription_id: int, payload: string}> $items */
    private function enqueue(array $items, string $urgency = 'normal', int $ttl = self::TTL): int
    {
        if ($items === []) {
            return 0;
        }
        $this->repository()->enqueue($items, $urgency, $ttl, $this->app->time()->nowUtc());
        $this->app->scheduler->runSoon(self::TASK_SEND);
        return count($items);
    }

    /**
     * Nutzlast als JSON. „url“ (vollständige Adresse) zusätzlich zu „route“: für Service Worker, die noch von einer
     * früheren App stammen und bis zu ihrem Austausch Nachrichten anzeigen (etwa nach einer Übernahme).
     *
     * @param Message $message
     */
    private function json(array $message): string
    {
        $message['url'] = $this->app->env->appUrl() . '#/' . ltrim($message['route'], '#/');
        return (string) json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
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
        $ts = $this->app->time()->timestamp();
        $now = gmdate('Y-m-d H:i:s', $ts);
        // Nacheinander (ohne cURL, z. B. WordPress) dauert jede Anfrage bis zu ihrem Zeitlimit: kleinere Durchgänge.
        $parallel = $this->app->http instanceof ExtendedHttpClient;
        $rows = $repo->claim($now, gmdate('Y-m-d H:i:s', $ts + self::LEASE), $parallel ? $limit : min($limit, self::BATCH_SEQUENTIAL));
        $keys = $this->vapid();
        $sent = 0;
        $tokens = []; // VAPID-Token je Push-Dienst, einmal je Lauf signiert
        // In Teilen senden und jeden Teil gleich abschließen: Bricht der Lauf ab, kommt höchstens ein Teil doppelt.
        foreach (array_chunk($rows, $parallel ? 25 : 5) as $chunk) {
            $done = [];
            $requests = [];
            foreach ($chunk as $row) {
                $age = max(0, $ts - (int) strtotime($row['created_at'] . ' UTC'));
                if ($row['endpoint'] === null || $row['p256dh'] === null || $row['auth'] === null || $keys === null || $age >= $row['ttl']) {
                    $done[] = $row['id']; // Abo weg, kein Push möglich oder zu alt
                    continue;
                }
                try {
                    $requests[$row['id']] = $this->request(['ttl' => $row['ttl'] - $age] + $row, $keys, $tokens);
                } catch (\RuntimeException $e) {
                    error_log('Kompanie-App: Push nicht vorbereitet: ' . $e->getMessage());
                    $done[] = $row['id'];
                }
            }
            $responses = $this->sendAll($requests);
            // FCM-403 heißt nur dann „Abo gehört zu einem anderen Schlüssel“, wenn FCM andere Nachrichten dieses Teils
            // angenommen hat; sonst liegt es eher an uns (z. B. falsche Uhrzeit des Servers) und alle Abos blieben weg.
            $fcmAccepts = false;
            foreach ($responses as $id => $res) {
                $fcmAccepts = $fcmAccepts || (is_array($res) && $res['status'] >= 200 && $res['status'] < 300
                    && parse_url($requests[$id]['url'], PHP_URL_HOST) === 'fcm.googleapis.com');
            }
            $delivered = [];
            foreach ($chunk as $row) {
                if (!isset($requests[$row['id']])) {
                    continue;
                }
                $status = self::classify($responses[$row['id']] ?? new \RuntimeException('keine Antwort'), (string) $row['endpoint'], $fcmAccepts);
                if ($status === 'ok') {
                    $sent++;
                    $done[] = $row['id'];
                    $delivered[] = $row['subscription_id'];
                } elseif ($status === 'gone') {
                    $repo->delete($row['subscription_id']);
                } elseif ($status === 'retry' && $row['attempts'] < self::MAX_ATTEMPTS) {
                    $repo->retry($row['id'], gmdate('Y-m-d H:i:s', $ts + 300 * $row['attempts']));
                } else {
                    $done[] = $row['id'];
                }
            }
            $repo->markSentMany($delivered, $now);
            $repo->dequeue($done);
        }
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
            'urgency' => 'high', 'ttl' => 300, 'attempts' => 0, 'created_at' => '', 'endpoint' => $sub['endpoint'], 'p256dh' => $sub['p256dh'], 'auth' => $sub['auth']];
        $tokens = [];
        $res = $this->sendAll([0 => $this->request($row, $keys, $tokens)])[0] ?? new \RuntimeException('keine Antwort');
        $status = self::classify($res, $sub['endpoint']);
        if ($status === 'gone') {
            $this->repository()->delete($sub['id']);
            throw new ApiError(410, 'push_gone', 'Der Push-Dienst kennt dieses Gerät nicht mehr. Bitte Benachrichtigungen neu aktivieren.');
        }
        if (is_array($res) && $res['status'] === 403 && parse_url($sub['endpoint'], PHP_URL_HOST) === 'fcm.googleapis.com') {
            // Meist gehört das Abo zu einem früheren Schlüssel des Servers; das Gerät muss sich neu anmelden.
            throw new ApiError(409, 'push_key_mismatch', 'Der Push-Dienst lehnt die Nachricht ab. Bitte Benachrichtigungen aus- und wieder einschalten.');
        }
        if ($status !== 'ok') {
            $code = $res instanceof \RuntimeException ? 'keine Verbindung' : 'HTTP ' . $res['status'];
            throw ApiError::upstream('Der Push-Dienst hat die Nachricht nicht angenommen (' . $code . ').');
        }
    }

    /**
     * @param QueueItem $row
     * @param array{public: string, private: string} $keys
     * @param array<string, string> $tokens VAPID-Token je Push-Dienst (Ursprung des Endpoints), für den ganzen Lauf: Das
     *        Token gilt 12 Stunden und hängt nur vom Dienst ab; eine Signatur je Dienst statt je Nachricht.
     * @return array{method: string, url: string, headers: array<string, string>, body: string}
     */
    private function request(array $row, array $keys, array &$tokens): array
    {
        $endpoint = (string) $row['endpoint'];
        $body = WebPushCrypto::encrypt($row['payload'], (string) WebPushCrypto::b64uDecode((string) $row['p256dh']), (string) WebPushCrypto::b64uDecode((string) $row['auth']));
        $audience = WebPushCrypto::audience($endpoint);
        $jwt = $tokens[$audience] ??= WebPushCrypto::vapidToken($endpoint, $keys['private'], $this->subject(), $this->app->time()->timestamp());
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

    /**
     * ok | gone | retry | error. FCM antwortet mit 403, wenn das Abo zu einem anderen Serverschlüssel gehört (z. B.
     * nach einer Neuinstallation): Das Abo taugt dann nie mehr und wird wie ein abgemeldetes entfernt – aber nur,
     * wenn FCM gleichzeitig andere Nachrichten angenommen hat ($fcmAccepts), unsere Anmeldung also stimmt.
     *
     * @param array{status: int, headers?: array<string, string>, body?: string}|\RuntimeException $res
     */
    private static function classify(array|\RuntimeException $res, string $endpoint, bool $fcmAccepts = false): string
    {
        if ($res instanceof \RuntimeException) {
            return 'retry';
        }
        $code = (int) $res['status'];
        $fcm = parse_url($endpoint, PHP_URL_HOST) === 'fcm.googleapis.com';
        return match (true) {
            $code >= 200 && $code < 300 => 'ok',
            $code === 404 || $code === 410 || ($code === 403 && $fcm && $fcmAccepts) => 'gone',
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

    /**
     * birthdays: Benachrichtigung am Geburtstag (an/aus, ab welcher Stunde); null ohne den Bereich Geburtstage.
     *
     * @return array{supported: bool, missing: string[], devices: int, accounts: int, guests: int, queued: int, reminderHour: int, birthdays: array{enabled: bool, hour: int}|null}
     */
    public function adminStatus(): array
    {
        return ['supported' => $this->supported(), 'missing' => $this->requirements()] + $this->repository()->counts() + [
            'reminderHour' => $this->reminderHour(),
            'birthdays' => $this->app->features()->enabled('birthdays') ? $this->app->birthdays()->notifySettings() : null,
        ];
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
