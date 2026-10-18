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
        try {
            return $this->http->request('GET', $url, [], null, 15);
        } catch (\RuntimeException $e) {
            throw ApiError::upstream('Der Kalender ist gerade nicht erreichbar: ' . $e->getMessage());
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
