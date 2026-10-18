<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Port\Cache;
use KompanieApp\Core\Port\HttpClient;
use KompanieApp\Core\Port\Settings;

/**
 * Alle Termine für Mitglieder aus dem Google Kalender.
 *
 * Quellen:
 *  1. Geheime iCal-Adresse (Standard, ohne Google Cloud).
 *  2. Google-Kalender-IDs mit API-Schlüssel (nur öffentliche Kalender).
 *
 * Das Ergebnis wird zwischengespeichert; Schlüssel verlassen nie den Server.
 */
final class CalendarService
{
    public const CACHE_KEY = 'calendar_events';
    private const GOOGLE_EVENTS_URL = 'https://www.googleapis.com/calendar/v3/calendars/%s/events';

    public function __construct(
        private readonly Settings $settings,
        private readonly HttpClient $http,
        private readonly Cache $cache,
        private readonly Time $time,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->source() !== 'none';
    }

    /** 'ics', 'google' oder 'none' */
    public function source(): string
    {
        if ($this->calendarIds() !== [] && trim((string) $this->settings->get('google_api_key', '')) !== '') {
            return 'google';
        }
        return trim((string) $this->settings->get('calendar_ics_url', '')) !== '' ? 'ics' : 'none';
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
        $cached = $this->cache->get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }
        $events = match ($this->source()) {
            'google' => $this->fetchGoogle(),
            'ics' => $this->fetchIcs((string) $this->settings->get('calendar_ics_url')),
            default => throw new ApiError(503, 'not_configured', 'Der Kalender ist noch nicht eingerichtet.'),
        };
        usort($events, static fn (array $a, array $b): int => strcmp($a['start'], $b['start']));
        $minutes = max(1, (int) $this->settings->get('calendar_cache_minutes', 15));
        $this->cache->set(self::CACHE_KEY, $events, $minutes * 60);
        return $events;
    }

    public function clearCache(): void
    {
        $this->cache->delete(self::CACHE_KEY);
    }

    /** @return array{0: int, 1: int} Zeitraum: ab gestern bis ein Jahr in die Zukunft */
    private function range(): array
    {
        $now = $this->time->timestamp();
        return [$now - 86400, $now + 365 * 86400];
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchGoogle(): array
    {
        [$min, $max] = $this->range();
        $key = (string) $this->settings->get('google_api_key', '');
        $events = [];
        foreach ($this->calendarIds() as $calId) {
            $params = [
                'singleEvents' => 'true',
                'orderBy' => 'startTime',
                'maxResults' => '250',
                'timeMin' => gmdate('Y-m-d\TH:i:s\Z', $min),
                'timeMax' => gmdate('Y-m-d\TH:i:s\Z', $max),
                'timeZone' => $this->time->timezone()->getName(),
                'key' => $key,
            ];
            $url = sprintf(self::GOOGLE_EVENTS_URL, rawurlencode($calId)) . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
            $res = $this->get($url);
            $body = json_decode($res['body'], true);
            if ($res['status'] !== 200 || !is_array($body)) {
                $msg = $body['error']['message'] ?? ('HTTP ' . $res['status']);
                throw ApiError::upstream('Google Kalender: ' . $msg);
            }
            foreach ($body['items'] ?? [] as $item) {
                if (($item['visibility'] ?? '') === 'private' || ($item['status'] ?? '') === 'cancelled') {
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
        try {
            return $this->http->request('GET', $url, [], null, 15);
        } catch (\RuntimeException $e) {
            throw ApiError::upstream('Der Kalender ist gerade nicht erreichbar: ' . $e->getMessage());
        }
    }

    /**
     * Einfacher ICS-Parser für VEVENTs. Wiederkehrende Termine (RRULE) erscheinen
     * nur mit ihrem ersten Vorkommen.
     *
     * @return array<int, array<string, mixed>>
     */
    public function parseIcs(string $ics): array
    {
        $ics = (string) preg_replace("/\r\n[ \t]|\n[ \t]/", '', str_replace("\r\n", "\n", $ics));
        [$min, $max] = $this->range();
        $events = [];
        $cur = null;
        foreach (explode("\n", $ics) as $line) {
            $trimmed = trim($line);
            if ($trimmed === 'BEGIN:VEVENT') {
                $cur = [];
                continue;
            }
            if ($trimmed === 'END:VEVENT') {
                if ($cur && !empty($cur['DTSTART']) && !self::icsHidden($cur)) {
                    $event = $this->icsEvent($cur);
                    if ($event && strtotime($event['end']) >= $min && strtotime($event['start']) <= $max) {
                        $events[] = $event;
                    }
                }
                $cur = null;
                continue;
            }
            if ($cur === null || !str_contains($line, ':')) {
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
            $cur[$name] = ['value' => $value, 'params' => $params];
        }
        return $events;
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

    /** @return array{0: string|null, 1: bool} */
    private function icsTime(array $prop): array
    {
        $v = trim($prop['value']);
        $allDay = ($prop['params']['VALUE'] ?? '') === 'DATE' || strlen($v) === 8;
        if ($allDay) {
            return [substr($v, 0, 4) . '-' . substr($v, 4, 2) . '-' . substr($v, 6, 2), true];
        }
        try {
            if (str_ends_with($v, 'Z')) {
                $dt = new \DateTimeImmutable(rtrim($v, 'Z'), new \DateTimeZone('UTC'));
            } elseif (!empty($prop['params']['TZID'])) {
                $dt = new \DateTimeImmutable($v, new \DateTimeZone($prop['params']['TZID']));
            } else {
                $dt = new \DateTimeImmutable($v, $this->time->timezone());
            }
            return [$dt->setTimezone($this->time->timezone())->format('c'), false];
        } catch (\Exception) {
            return [null, false];
        }
    }

    /** @return array<string, mixed>|null */
    private function icsEvent(array $e): ?array
    {
        [$start, $allDay] = $this->icsTime($e['DTSTART']);
        if (!$start) {
            return null;
        }
        $end = $start;
        if (!empty($e['DTEND'])) {
            $end = $this->icsTime($e['DTEND'])[0] ?? $start;
        }
        return [
            'id' => md5(isset($e['UID']) ? $e['UID']['value'] . $start : $start),
            'title' => isset($e['SUMMARY']) ? self::icsText($e['SUMMARY']['value']) : '(ohne Titel)',
            'start' => $start,
            'end' => $end,
            'all_day' => $allDay,
            'location' => isset($e['LOCATION']) ? self::icsText($e['LOCATION']['value']) : '',
            'description' => isset($e['DESCRIPTION']) ? Text::fromHtml(self::icsText($e['DESCRIPTION']['value'])) : '',
        ];
    }
}
