<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Port\Cache;
use KompanieApp\Core\Port\ExtendedHttpClient;
use KompanieApp\Core\Port\HttpClient;
use KompanieApp\Core\Port\Settings;

/**
 * Alle Termine für Mitglieder aus dem Google Kalender.
 *
 * Quellen, in dieser Reihenfolge:
 *  1. Google-Kalender-IDs mit eigenem API-Schlüssel (nur öffentliche Kalender), 'google'.
 *  2. Google-Kalender-IDs über den Google-Zugang, gemeinsam mit der Galerie (DriveClient: OAuth-Verbindung mit
 *     calendar.readonly oder Dienstkonto), auch für private Kalender, 'google_connection'.
 *  3. Geheime iCal-Adresse (Standard in der App, ohne Google Cloud), 'ics'.
 * Bei 1. und 2. löst Google Serientermine selbst auf (singleEvents=true).
 *
 * Schlüssel verlassen nie den Server.
 *
 * Zwischenspeicher: Die letzte gute Fassung bleibt lange erhalten (KEEP_SECONDS), mit einem
 * „frisch bis“-Zeitpunkt (Einstellung calendar_cache_minutes). Ist sie veraltet, erneuert sie
 * höchstens eine Anfrage je LOCK_SECONDS (Sperre); alle anderen bekommen sofort die alte Fassung,
 * bei einer Störung auch diese. Ein Fehler wird ERROR_SECONDS gemerkt, damit bei einer Störung nicht jede Anfrage
 * bis zum Timeout wartet. Im Hintergrund erneuert die Aufgabe TASK den Kalender stündlich.
 */
final class CalendarService
{
    public const CACHE_KEY = 'calendar_events';
    public const ERROR_KEY = 'calendar_error';
    public const TASK = 'calendar_refresh';
    /** So lange bleibt die letzte gute Fassung als Rückfall erhalten. */
    public const KEEP_SECONDS = 30 * 86400;
    /** Wie weit zurück der Kalender Termine lädt (Anwesenheit nachtragen). */
    public const PAST_DAYS = 90;
    /** So lange wird ein Fehler gemerkt (kein neuer Abruf). */
    public const ERROR_SECONDS = 60;
    /** Sperre gegen gleichzeitige Abrufe. */
    private const LOCK_SECONDS = 30;
    private const GOOGLE_EVENTS_URL = 'https://www.googleapis.com/calendar/v3/calendars/%s/events';

    public function __construct(
        private readonly Settings $settings,
        private readonly HttpClient $http,
        private readonly Cache $cache,
        private readonly Time $time,
        private readonly ?DriveClient $drive = null,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->source() !== 'none';
    }

    /** 'google' (eigener API-Schlüssel), 'google_connection' (Google-Zugang), 'ics' oder 'none' */
    public function source(): string
    {
        if ($this->calendarIds() !== []) {
            if (trim((string) $this->settings->get('google_api_key', '')) !== '') {
                return 'google';
            }
            if ($this->drive?->calendarAccess()) {
                return 'google_connection';
            }
        }
        return trim((string) $this->settings->get('calendar_ics_url', '')) !== '' ? 'ics' : 'none';
    }

    /**
     * Kalender-IDs ohne API-Schlüssel, aber die OAuth-Verbindung darf den Kalender nicht lesen
     * (verbunden vor der Kalender-Erweiterung oder ohne Häkchen dafür): Hinweis „neu verbinden“.
     */
    public function connectionNeedsCalendarAccess(): bool
    {
        $drive = $this->drive;
        return $drive !== null
            && $drive->oauth() !== null
            && !$drive->calendarAccess()
            && $this->calendarIds() !== []
            && trim((string) $this->settings->get('google_api_key', '')) === '';
    }

    /** @return string[] */
    public function calendarIds(): array
    {
        $raw = (string) $this->settings->get('calendar_ids', '');
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /** @return array<int, array<string, mixed>> nach Beginn sortiert */
    public function events(): array
    {
        $copy = $this->copy();
        if ($copy !== null && $copy['fresh_until'] > $this->time->timestamp()) {
            return $copy['events'];
        }
        if ($copy !== null) {
            if ($this->cache->get(self::ERROR_KEY) === null && $this->lock()) {
                try {
                    return $this->refresh();
                } catch (ApiError $e) {
                    error_log('Kompanie-App: Kalender nicht erneuert, alte Fassung ausgeliefert: ' . $e->getMessage());
                }
            }
            return $copy['events'];
        }
        $error = $this->cache->get(self::ERROR_KEY);
        if (is_string($error) && $this->isConfigured()) {
            throw ApiError::upstream($error);
        }
        return $this->refresh();
    }

    /**
     * Für die geplante Aufgabe: den Kalender im Hintergrund erneuern, damit Anfragen ihn selten
     * selbst abrufen müssen. Fehler werden protokolliert; die alte Fassung bleibt.
     */
    /** @return bool false, wenn der Abruf gescheitert ist (für die Fehlercodes der Statusmeldungen) */
    public function refreshIfStale(): bool
    {
        if (!$this->isConfigured()) {
            return true;
        }
        $copy = $this->copy();
        // Gerade erst abgerufen (z. B. von einer Anfrage): nichts zu tun. Ohne Sperre: Die Aufgabe
        // läuft ohnehin nur einmal, und die Sperre einer Anfrage soll sie nicht eine Stunde aufschieben.
        if ($copy !== null && $copy['fetched_at'] > $this->time->timestamp() - 300) {
            return true;
        }
        try {
            $this->refresh();
        } catch (ApiError $e) {
            error_log('Kompanie-App: Kalender nicht erneuert: ' . $e->getMessage());
            return false;
        }
        return true;
    }

    public function clearCache(): void
    {
        $this->cache->delete(self::CACHE_KEY);
        $this->cache->delete(self::ERROR_KEY);
    }

    /** @return array{events: array<int, array<string, mixed>>, fetched_at: int, fresh_until: int}|null letzte gute Fassung */
    private function copy(): ?array
    {
        $c = $this->cache->get(self::CACHE_KEY);
        if (!is_array($c) || !is_array($c['events'] ?? null) || !isset($c['fetched_at'], $c['fresh_until'])) {
            return null; // auch der Zwischenspeicher älterer Versionen (nur die Liste)
        }
        return ['events' => $c['events'], 'fetched_at' => (int) $c['fetched_at'], 'fresh_until' => (int) $c['fresh_until']];
    }

    /** Nur eine Anfrage je LOCK_SECONDS ruft den Kalender ab (atomar über Cache::increment). */
    private function lock(): bool
    {
        return $this->cache->increment(self::CACHE_KEY . '_lock', self::LOCK_SECONDS) === 1;
    }

    /**
     * Ruft den Kalender ab und speichert ihn als neue gute Fassung.
     * Fehler der Gegenseite werden ERROR_SECONDS gemerkt.
     *
     * @return array<int, array<string, mixed>>
     */
    private function refresh(): array
    {
        try {
            $events = match ($this->source()) {
                'google' => $this->fetchGoogle(['key' => (string) $this->settings->get('google_api_key', '')], []),
                'google_connection' => $this->fetchWithConnection(),
                'ics' => $this->fetchIcs((string) $this->settings->get('calendar_ics_url')),
                default => throw new ApiError(503, 'not_configured', 'Der Kalender ist noch nicht eingerichtet.'),
            };
        } catch (ApiError $e) {
            if ($e->status === 502) {
                $this->cache->set(self::ERROR_KEY, $e->getMessage(), self::ERROR_SECONDS);
            }
            throw $e;
        }
        usort($events, static fn (array $a, array $b): int => strcmp($a['start'], $b['start']));
        $now = $this->time->timestamp();
        $minutes = max(1, (int) $this->settings->get('calendar_cache_minutes', 15));
        $this->cache->set(self::CACHE_KEY, ['events' => $events, 'fetched_at' => $now, 'fresh_until' => $now + $minutes * 60], self::KEEP_SECONDS);
        $this->cache->delete(self::ERROR_KEY);
        return $events;
    }

    /**
     * Termine ab gestern (für die Terminansicht).
     *
     * @return array<int, array<string, mixed>>
     */
    public function upcoming(): array
    {
        $from = $this->time->timestamp() - 86400;
        return array_values(array_filter($this->events(), static fn (array $e): bool => (int) strtotime((string) $e['end']) >= $from));
    }

    /**
     * Zeitraum: die letzten Wochen (für Anwesenheitslisten, Issue #21) bis ein Jahr in die Zukunft.
     *
     * @return array{0: int, 1: int}
     */
    private function range(): array
    {
        $now = $this->time->timestamp();
        return [$now - self::PAST_DAYS * 86400, $now + 365 * 86400];
    }

    /**
     * Über den Google-Zugang: Zugriffstoken als Bearer, ohne API-Schlüssel.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchWithConnection(): array
    {
        $drive = $this->drive;
        if ($drive === null) {
            throw new ApiError(503, 'not_configured', 'Der Kalender ist noch nicht eingerichtet.');
        }
        try {
            $token = $drive->accessToken();
        } catch (\RuntimeException $e) {
            throw ApiError::upstream('Google Kalender: ' . $e->getMessage());
        }
        $hint = $drive->oauth() !== null
            ? 'Kann das verbundene Google-Konto' . ($drive->oauthEmail() !== '' ? ' (' . $drive->oauthEmail() . ')' : '')
                . ' den Kalender sehen, und ist die Google Calendar API im Google-Cloud-Projekt aktiviert?'
            : 'Ist der Kalender für ' . $drive->clientEmail() . ' freigegeben („Alle Termindetails sehen“), und ist die Google Calendar API im Google-Cloud-Projekt aktiviert?';
        return $this->fetchGoogle([], ['Authorization' => 'Bearer ' . $token], $hint);
    }

    /**
     * @param array<string, string> $auth Parameter für die Anmeldung (API-Schlüssel) oder leer
     * @param array<string, string> $headers z. B. Authorization mit Zugriffstoken
     * @param string $hint Zusatz zur Fehlermeldung, wenn Google den Abruf ablehnt (401, 403, 404)
     * @return array<int, array<string, mixed>>
     */
    private function fetchGoogle(array $auth, array $headers, string $hint = ''): array
    {
        [$min, $max] = $this->range();
        $requests = [];
        foreach ($this->calendarIds() as $calId) {
            $params = [
                'singleEvents' => 'true',
                'orderBy' => 'startTime',
                'maxResults' => '250',
                'timeMin' => gmdate('Y-m-d\TH:i:s\Z', $min),
                'timeMax' => gmdate('Y-m-d\TH:i:s\Z', $max),
                'timeZone' => $this->time->timezone()->getName(),
            ] + $auth;
            $requests[$calId] = ['method' => 'GET', 'url' => sprintf(self::GOOGLE_EVENTS_URL, rawurlencode($calId)) . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986), 'headers' => $headers];
        }
        // Mehrere Kalender gleichzeitig abrufen, wenn der HttpClient das kann; sonst nacheinander.
        $responses = $this->http instanceof ExtendedHttpClient && count($requests) > 1 ? $this->http->requestAll($requests, 15) : [];
        $events = [];
        foreach ($requests as $calId => $request) {
            $calId = (string) $calId;
            $res = $responses[$calId] ?? $this->tryGet($request['url'], $headers);
            if ($res instanceof \RuntimeException) {
                throw ApiError::upstream('Der Kalender ist gerade nicht erreichbar: ' . $res->getMessage());
            }
            $items = [];
            // Mehr als 250 Termine im Jahr: weiterblättern (höchstens 4 Seiten). Sonst fehlten die späteren, und der
            // Abgleich für Benachrichtigungen hielte sie für neu, sobald vorne alte Termine wegfallen.
            for ($page = 0; $page < 4; $page++) {
                $body = json_decode($res['body'], true);
                if ($res['status'] !== 200 || !is_array($body)) {
                    $msg = $body['error']['message'] ?? ('HTTP ' . $res['status']);
                    $withHint = $hint !== '' && in_array($res['status'], [401, 403, 404], true);
                    throw ApiError::upstream('Google Kalender: ' . $msg . ($withHint ? ' – ' . $hint : ''));
                }
                array_push($items, ...array_values((array) ($body['items'] ?? [])));
                $token = $body['nextPageToken'] ?? null;
                if (!is_string($token) || $token === '' || $page === 3) {
                    break;
                }
                $res = $this->tryGet($request['url'] . '&pageToken=' . rawurlencode($token), $headers);
                if ($res instanceof \RuntimeException) {
                    throw ApiError::upstream('Der Kalender ist gerade nicht erreichbar: ' . $res->getMessage());
                }
            }
            foreach ($items as $item) {
                if (in_array($item['visibility'] ?? '', ['private', 'confidential'], true) || ($item['status'] ?? '') === 'cancelled') {
                    continue;
                }
                $allDay = !empty($item['start']['date']);
                $events[] = [
                    'id' => md5($calId . '|' . ($item['id'] ?? json_encode($item['start']))),
                    'title' => (string) ($item['summary'] ?? '(ohne Titel)'),
                    'start' => $this->normalize($allDay ? $item['start']['date'] : $item['start']['dateTime'], $allDay),
                    'end' => $this->normalize($allDay ? $item['end']['date'] : $item['end']['dateTime'], $allDay),
                    'all_day' => $allDay,
                    'location' => (string) ($item['location'] ?? ''),
                    'description' => Text::fromHtml((string) ($item['description'] ?? '')),
                ];
            }
        }
        return $events;
    }

    /** Ganztägig: 'Y-m-d' (Ende exklusiv). Mit Uhrzeit: ISO 8601 in der Zeitzone der Kompanie. */
    private function normalize(string $value, bool $allDay): string
    {
        if ($allDay) {
            return substr($value, 0, 10);
        }
        try {
            return (new \DateTimeImmutable($value))->setTimezone($this->time->timezone())->format('c');
        } catch (\Exception) {
            return $value;
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchIcs(string $url): array
    {
        // Google zeigt webcal:// an; abgerufen wird über https.
        $url = (string) preg_replace('#^webcal://#i', 'https://', trim($url));
        $res = $this->get($url);
        if ($res['status'] !== 200) {
            throw ApiError::upstream('Der Kalender-Link lieferte HTTP ' . $res['status'] . '.');
        }
        return $this->parseIcs($res['body']);
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private function get(string $url): array
    {
        $res = $this->tryGet($url);
        if ($res instanceof \RuntimeException) {
            throw ApiError::upstream('Der Kalender ist gerade nicht erreichbar: ' . $res->getMessage());
        }
        return $res;
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}|\RuntimeException
     */
    private function tryGet(string $url, array $headers = []): array|\RuntimeException
    {
        try {
            return $this->http->request('GET', $url, $headers, null, 15);
        } catch (\RuntimeException $e) {
            return $e;
        }
    }

    /**
     * ICS-Parser für VEVENTs. Terminserien (RRULE) werden im Zeitraum aufgefächert,
     * mit Ausnahmen (EXDATE) und einzeln geänderten Terminen (RECURRENCE-ID).
     * Eingebettete Blöcke wie VALARM werden übersprungen.
     *
     * @return array<int, array<string, mixed>>
     */
    public function parseIcs(string $ics): array
    {
        $ics = (string) preg_replace("/\r\n[ \t]|\n[ \t]/", '', str_replace("\r\n", "\n", $ics));
        $raw = [];
        $cur = null;
        $nested = 0;
        foreach (explode("\n", $ics) as $line) {
            $trimmed = strtoupper(trim($line));
            if ($trimmed === 'BEGIN:VEVENT' && $cur === null) {
                $cur = [];
                $nested = 0;
                continue;
            }
            if ($cur !== null && str_starts_with($trimmed, 'BEGIN:') && $trimmed !== 'BEGIN:VEVENT') {
                $nested++;
                continue;
            }
            // END:VEVENT schließt den Termin immer, auch wenn ein eingebetteter Block
            // (z. B. VALARM) nicht geschlossen wurde.
            if ($trimmed === 'END:VEVENT' && $cur !== null) {
                $nested = 0;
                if ($cur && !empty($cur['DTSTART'])) {
                    $raw[] = $cur;
                }
                $cur = null;
                continue;
            }
            if ($cur !== null && str_starts_with($trimmed, 'END:') && $nested > 0) {
                $nested--;
                continue;
            }
            if ($cur === null || $nested > 0 || !str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = explode(':', $line, 2);
            $parts = explode(';', $key);
            $name = strtoupper((string) array_shift($parts));
            $params = [];
            foreach ($parts as $p) {
                if (str_contains($p, '=')) {
                    [$pk, $pv] = explode('=', $p, 2);
                    $params[strtoupper($pk)] = trim($pv, '"');
                }
            }
            if ($name === 'EXDATE') {
                foreach (explode(',', $value) as $v) {
                    $cur['EXDATE'][] = ['value' => $v, 'params' => $params];
                }
            } else {
                $cur[$name] = ['value' => $value, 'params' => $params];
            }
        }

        // Einzeln geänderte oder abgesagte Termine einer Serie: UID + ursprünglicher Beginn.
        $overridden = [];
        foreach ($raw as $e) {
            if (isset($e['RECURRENCE-ID'], $e['UID']) && ($rid = $this->icsDate($e['RECURRENCE-ID']))) {
                $overridden[$e['UID']['value'] . '|' . $rid[0]->getTimestamp()] = true;
            }
        }

        [$min, $max] = $this->range();
        $events = [];
        foreach ($raw as $e) {
            if (self::icsHidden($e)) {
                continue;
            }
            try {
                $occurrences = $this->icsOccurrences($e, $overridden, $min, $max);
            } catch (\Throwable $ex) {
                // Ein kaputter Termin soll nicht den ganzen Kalender lahmlegen.
                error_log('Kompanie-App: Termin im Kalender übersprungen: ' . $ex->getMessage());
                continue;
            }
            foreach ($occurrences as $event) {
                if (strtotime($event['end']) >= $min && strtotime($event['start']) <= $max) {
                    $events[] = $event;
                }
            }
        }
        return $events;
    }

    /**
     * @param array<string, true> $overridden
     * @return array<int, array<string, mixed>>
     */
    private function icsOccurrences(array $e, array $overridden, int $min, int $max): array
    {
        $start = $this->icsDate($e['DTSTART']);
        if (!$start) {
            return [];
        }
        [$dt, $allDay] = $start;
        $end = !empty($e['DTEND']) ? $this->icsDate($e['DTEND']) : null;
        $duration = $end ? $end[0]->getTimestamp() - $dt->getTimestamp() : ($allDay ? 86400 : 0);
        $duration = max(0, $duration);

        $starts = [$dt];
        if (!empty($e['RRULE']) && !isset($e['RECURRENCE-ID'])) {
            $rule = new IcsRecurrence($e['RRULE']['value']);
            if ($rule->supports()) {
                $tz = $dt->getTimezone();
                $from = (new \DateTimeImmutable('@' . ($min - $duration - 86400)))->setTimezone($tz);
                $limit = (new \DateTimeImmutable('@' . $max))->setTimezone($tz);
                $starts = $rule->occurrences($dt, $from, $limit, $allDay);
                $uid = $e['UID']['value'] ?? '';
                // Ausnahmen mit Uhrzeit über den Zeitpunkt, ganztägige über das Datum
                // (manche Programme schreiben EXDATE;VALUE=DATE auch bei Serien mit Uhrzeit).
                $excluded = [];
                foreach ($e['EXDATE'] ?? [] as $x) {
                    if ($xd = $this->icsDate($x)) {
                        $excluded[$xd[1] ? 'd' . $xd[0]->format('Y-m-d') : 't' . $xd[0]->getTimestamp()] = true;
                    }
                }
                $starts = array_filter($starts, static fn (\DateTimeImmutable $s): bool => !isset($excluded['t' . $s->getTimestamp()])
                    && !isset($excluded['d' . $s->format('Y-m-d')])
                    && !isset($overridden[$uid . '|' . $s->getTimestamp()]));
            }
        }

        $out = [];
        foreach ($starts as $s) {
            $out[] = $this->icsEvent($e, $s, $duration, $allDay);
        }
        return $out;
    }

    /**
     * Private und vertrauliche Termine (CLASS) sowie abgesagte (STATUS) nicht zeigen –
     * wie bei der Google-API. Die geheime iCal-Adresse von Google enthält auch private Termine.
     */
    private static function icsHidden(array $e): bool
    {
        $class = strtoupper(trim($e['CLASS']['value'] ?? 'PUBLIC'));
        $status = strtoupper(trim($e['STATUS']['value'] ?? ''));
        return in_array($class, ['PRIVATE', 'CONFIDENTIAL'], true) || $status === 'CANCELLED';
    }

    private static function icsText(string $v): string
    {
        return str_replace(['\\n', '\\N', '\\,', '\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $v);
    }

    /**
     * Zeitpunkt in der Zeitzone des Termins (für Serien), ganztägig um 0 Uhr in der Zeitzone der Kompanie.
     *
     * @return array{0: \DateTimeImmutable, 1: bool}|null
     */
    private function icsDate(array $prop): ?array
    {
        $v = trim($prop['value']);
        $allDay = ($prop['params']['VALUE'] ?? '') === 'DATE' || strlen($v) === 8;
        try {
            if ($allDay) {
                return [new \DateTimeImmutable(substr($v, 0, 8) . 'T000000', $this->time->timezone()), true];
            }
            if (str_ends_with($v, 'Z')) {
                return [new \DateTimeImmutable(rtrim($v, 'Z'), new \DateTimeZone('UTC')), false];
            }
            if (!empty($prop['params']['TZID'])) {
                try {
                    $tz = new \DateTimeZone($prop['params']['TZID']);
                } catch (\Exception) {
                    $tz = $this->time->timezone(); // z. B. Windows-Zonennamen aus Outlook
                }
                return [new \DateTimeImmutable($v, $tz), false];
            }
            return [new \DateTimeImmutable($v, $this->time->timezone()), false];
        } catch (\Exception) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function icsEvent(array $e, \DateTimeImmutable $start, int $duration, bool $allDay): array
    {
        $end = $start->modify('+' . $duration . ' seconds');
        if ($allDay) {
            // Ganztägig: Datum, Ende exklusiv; Dauer in Tagen, damit die Zeitumstellung nichts verschiebt.
            $days = max(1, (int) round($duration / 86400));
            $startStr = $start->format('Y-m-d');
            $endStr = $start->modify('+' . $days . ' days')->format('Y-m-d');
        } else {
            $tz = $this->time->timezone();
            $startStr = $start->setTimezone($tz)->format('c');
            $endStr = $end->setTimezone($tz)->format('c');
        }
        return [
            'id' => md5(($e['UID']['value'] ?? '') . $startStr),
            'title' => isset($e['SUMMARY']) ? self::icsText($e['SUMMARY']['value']) : '(ohne Titel)',
            'start' => $startStr,
            'end' => $endStr,
            'all_day' => $allDay,
            'location' => isset($e['LOCATION']) ? self::icsText($e['LOCATION']['value']) : '',
            'description' => isset($e['DESCRIPTION']) ? Text::fromHtml(self::icsText($e['DESCRIPTION']['value'])) : '',
        ];
    }
}
