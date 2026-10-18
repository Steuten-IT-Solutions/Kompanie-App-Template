<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Infra\RemoteNewsSource;

/**
 * Einstellungen, die Admins in der App pflegen (bei WordPress zusätzlich im WP-Admin):
 * Kalender, Galerie und – wo die App keine eigenen Beiträge hat – die Quelle der Neuigkeiten.
 *
 * Geheimnisse (geheime iCal-Adresse, API-Schlüssel, Dienstkonto) werden nie zurückgegeben,
 * nur ob sie gesetzt sind. Zum Ändern schickt man den neuen Wert, zum Löschen einen leeren Text.
 */
final class SettingsService
{
    public function __construct(private readonly App $app)
    {
    }

    /** @return array<string, mixed> */
    public function get(): array
    {
        $a = $this->app;
        $s = $a->settings;
        $out = [
            'calendar' => [
                'source' => $a->calendar()->source(),
                'ics_url_set' => trim((string) $s->get('calendar_ics_url', '')) !== '',
                'google_api_key_set' => trim((string) $s->get('google_api_key', '')) !== '',
                'calendar_ids' => (string) $s->get('calendar_ids', ''),
                'cache_minutes' => max(1, (int) $s->get('calendar_cache_minutes', 15)),
            ],
            'gallery' => [
                'configured' => $a->gallery()->isConfigured(),
                'root' => $a->gallery()->root(),
                'service_account_email' => $a->drive()->clientEmail(),
                'status' => $a->gallery()->status() + ['pending' => $a->gallery()->countPending()],
            ],
            'news' => null,
        ];
        if ($a->news instanceof RemoteNewsSource) {
            $out['news'] = ['source' => $a->news->type(), 'url' => $a->news->url()];
        }
        return $out;
    }

    /**
     * Übernimmt nur die mitgeschickten Felder.
     *
     * @param array<string, mixed> $in
     * @return array<string, mixed> neuer Stand wie get()
     */
    public function update(array $in): array
    {
        $a = $this->app;
        $s = $a->settings;
        $calendarChanged = false;

        if (array_key_exists('calendar_ics_url', $in)) {
            $url = trim(is_string($in['calendar_ics_url']) ? $in['calendar_ics_url'] : '');
            $url = (string) preg_replace('#^webcal://#i', 'https://', $url);
            $s->set('calendar_ics_url', Input::url($url, 'Bitte die geheime iCal-Adresse aus Google Kalender einfügen (beginnt mit https://).'));
            $calendarChanged = true;
        }
        if (array_key_exists('google_api_key', $in)) {
            $key = Text::line($in['google_api_key'], 200);
            if ($key !== '' && !preg_match('/^[A-Za-z0-9_-]+$/', $key)) {
                throw ApiError::invalid('Der API-Schlüssel enthält ungültige Zeichen.');
            }
            $s->set('google_api_key', $key);
            $calendarChanged = true;
        }
        if (array_key_exists('calendar_ids', $in)) {
            $ids = array_filter(array_map('trim', explode(',', Text::line($in['calendar_ids'], 1000))));
            foreach ($ids as $id) {
                if (!preg_match('/^[A-Za-z0-9._@%+-]+$/', $id)) {
                    throw ApiError::invalid('Ungültige Kalender-ID: ' . $id);
                }
            }
            $s->set('calendar_ids', implode(',', $ids));
            $calendarChanged = true;
        }
        if (array_key_exists('calendar_cache_minutes', $in)) {
            $min = Input::integer($in['calendar_cache_minutes']);
            if ($min === null || $min < 1 || $min > 1440) {
                throw ApiError::invalid('Der Zwischenspeicher muss zwischen 1 und 1440 Minuten liegen.');
            }
            $s->set('calendar_cache_minutes', $min);
        }
        if ($calendarChanged) {
            $a->calendar()->clearCache();
        }

        try {
            if (array_key_exists('drive_service_account', $in)) {
                $json = is_string($in['drive_service_account']) ? trim($in['drive_service_account']) : '';
                if ($json === '') {
                    $s->delete(DriveClient::SETTING);
                } else {
                    $a->drive()->saveServiceAccount($json);
                }
            }
            if (array_key_exists('gallery_root', $in)) {
                $before = $a->gallery()->root();
                $a->gallery()->setRoot(is_string($in['gallery_root']) ? $in['gallery_root'] : '');
                if ($before !== '' && $before !== $a->gallery()->root()) {
                    $a->gallery()->reset();
                }
            }
        } catch (\RuntimeException $e) {
            throw $e instanceof ApiError ? $e : ApiError::invalid($e->getMessage());
        }

        if ($a->news instanceof RemoteNewsSource) {
            if (array_key_exists('news_source', $in)) {
                $src = is_string($in['news_source']) ? $in['news_source'] : '';
                if (!in_array($src, RemoteNewsSource::SOURCES, true)) {
                    throw ApiError::invalid('Unbekannte Quelle für Neuigkeiten.');
                }
                $s->set('news_source', $src);
            }
            if (array_key_exists('news_url', $in)) {
                $s->set('news_url', Input::url($in['news_url'], 'Bitte die Adresse der Webseite bzw. des Feeds angeben (beginnt mit https://).'));
            }
        }

        $a->scheduleTasks();
        return $this->get();
    }

    /** @return array{count: int, sample: array<int, array{start: string, title: string}>} */
    public function testCalendar(): array
    {
        $cal = $this->app->calendar();
        $cal->clearCache();
        $events = $cal->events();
        return [
            'count' => count($events),
            'sample' => array_map(static fn (array $e): array => ['start' => $e['start'], 'title' => $e['title']], array_slice($events, 0, 5)),
        ];
    }

    /** @return array{count: int} */
    public function testNews(): array
    {
        if (!$this->app->news instanceof RemoteNewsSource) {
            throw ApiError::notFound('Die Neuigkeiten kommen hier aus den Beiträgen der Webseite.');
        }
        if (!$this->app->news->available()) {
            throw new ApiError(503, 'not_configured', 'Es ist noch keine Quelle für Neuigkeiten eingestellt.');
        }
        return ['count' => $this->app->news->test()];
    }

    /**
     * Abgleich von Hand: erst mit Drive abgleichen, dann Bilder aufbereiten, solange das
     * Zeitbudget reicht. Mit $onlyProcess nur weiter aufbereiten (für wiederholte Aufrufe).
     *
     * @return array<string, mixed>
     */
    public function syncGallery(bool $onlyProcess): array
    {
        $g = $this->app->gallery();
        if (!$g->isConfigured()) {
            throw new ApiError(503, 'not_configured', 'Die Galerie ist noch nicht eingerichtet.');
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }
        try {
            $stats = $onlyProcess ? $g->status() : $g->sync();
        } catch (\RuntimeException $e) {
            throw ApiError::upstream($e->getMessage());
        }
        $done = $g->processPending(20);
        return [
            'albums' => (int) ($stats['albums'] ?? 0),
            'photos' => (int) ($stats['photos'] ?? 0),
            'processed' => $done,
            'pending' => $g->countPending(),
        ];
    }
}
