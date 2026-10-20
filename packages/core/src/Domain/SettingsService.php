<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Infra\RemoteNewsSource;

/**
 * Einstellungen, die Admins in der App pflegen (bei WordPress zusätzlich im WP-Admin):
 * Google-Zugang, Kalender, Galerie und – wo die App keine eigenen Beiträge hat – die Quelle der Neuigkeiten.
 *
 * Geheimnisse (geheime iCal-Adresse, API-Schlüssel, Dienstkonto, Client-Secret, Refresh-Token) werden nie zurückgegeben,
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
        $drive = $a->drive();
        $client = $drive->oauthClient();
        $out = [
            // Gemeinsamer Zugang für Galerie und Kalender: „Mit Google verbinden“ oder Dienstkonto
            'google' => [
                'redirect_uri' => $a->env->googleRedirectUri(),
                'client_id' => $client['client_id'],
                'client_secret_set' => $client['client_secret_set'],
                'connected' => $drive->oauth() !== null,
                'email' => $drive->oauthEmail(),
                'calendar_access' => $drive->calendarAccess(),
                'service_account_email' => $drive->clientEmail(),
                'last_result' => $a->googleConnect()->lastResult(),
            ],
            'calendar' => [
                'source' => $a->calendar()->source(),
                // Kalender-IDs ohne API-Schlüssel, aber die OAuth-Verbindung darf den Kalender nicht lesen: neu verbinden.
                'connection_needs_calendar_access' => $a->calendar()->connectionNeedsCalendarAccess(),
                'ics_url_set' => trim((string) $s->get('calendar_ics_url', '')) !== '',
                'google_api_key_set' => trim((string) $s->get('google_api_key', '')) !== '',
                'calendar_ids' => (string) $s->get('calendar_ids', ''),
                'cache_minutes' => max(1, (int) $s->get('calendar_cache_minutes', 15)),
            ],
            'gallery' => [
                'configured' => $a->gallery()->isConfigured(),
                'root' => $a->gallery()->root(),
                // Wie google.* (für ältere Fassungen der App)
                'service_account_email' => $drive->clientEmail(),
                'oauth_connected' => $drive->oauth() !== null,
                'oauth_email' => $drive->oauthEmail(),
                'calendar_access' => $drive->calendarAccess(),
                'status' => array_merge($a->gallery()->status(), ['pending' => $a->gallery()->countPending()]),
            ],
            'news' => null,
        ];
        if ($a->news instanceof RemoteNewsSource) {
            $out['news'] = ['source' => $a->news->type(), 'url' => $a->news->url()];
        }
        return $out;
    }

    /**
     * Übernimmt nur die mitgeschickten Felder. Erst wird alles geprüft, dann gespeichert:
     * Ein Fehler in einem Feld lässt die anderen unverändert.
     *
     * @param array<string, mixed> $in
     * @return array<string, mixed> neuer Stand wie get()
     */
    public function update(array $in): array
    {
        $a = $this->app;
        $news = $a->news instanceof RemoteNewsSource;
        foreach ($in as $key => $value) {
            if (!is_string($value) && !($key === 'calendar_cache_minutes' && is_int($value))) {
                throw ApiError::invalid('Ungültiger Wert für ' . $key . '.');
            }
        }

        $set = [];
        if (array_key_exists('calendar_ics_url', $in)) {
            $url = (string) preg_replace('#^webcal://#i', 'https://', trim($in['calendar_ics_url']));
            $set['calendar_ics_url'] = Input::url($url, 'Bitte die geheime iCal-Adresse aus Google Kalender einfügen (beginnt mit https://).');
            if ($set['calendar_ics_url'] !== '' && !array_key_exists('calendar_ids', $in)) {
                // Die iCal-Adresse soll gelten; eine eingerichtete Google-API hätte sonst Vorrang.
                $set['calendar_ids'] = '';
            }
        }
        if (array_key_exists('google_api_key', $in)) {
            $key = Text::line($in['google_api_key'], 200);
            if ($key !== '' && !preg_match('/^[A-Za-z0-9_-]+$/', $key)) {
                throw ApiError::invalid('Der API-Schlüssel enthält ungültige Zeichen.');
            }
            $set['google_api_key'] = $key;
        }
        if (array_key_exists('calendar_ids', $in)) {
            $ids = array_filter(array_map('trim', explode(',', Text::line($in['calendar_ids'], 1000))));
            foreach ($ids as $id) {
                if (!preg_match('/^[A-Za-z0-9._@%+-]+$/', $id)) {
                    throw ApiError::invalid('Ungültige Kalender-ID: ' . $id);
                }
            }
            $set['calendar_ids'] = implode(',', $ids);
        }
        if (array_key_exists('calendar_cache_minutes', $in)) {
            $min = Input::integer($in['calendar_cache_minutes']);
            if ($min === null || $min < 1 || $min > 1440) {
                throw ApiError::invalid('Der Zwischenspeicher muss zwischen 1 und 1440 Minuten liegen.');
            }
            $set['calendar_cache_minutes'] = $min;
        }
        $serviceAccount = null;
        if (array_key_exists('drive_service_account', $in) && trim($in['drive_service_account']) !== '') {
            try {
                $serviceAccount = DriveClient::parseServiceAccount(trim($in['drive_service_account']));
            } catch (\RuntimeException $e) {
                throw ApiError::invalid($e->getMessage());
            }
        }
        if (array_key_exists('drive_oauth', $in) && $in['drive_oauth'] !== '') {
            throw ApiError::invalid('Eine Verbindung zu Google lässt sich hier nur trennen (leerer Wert). Verbinden: POST /admin/google/connect.');
        }
        $root = null;
        if (array_key_exists('gallery_root', $in)) {
            $root = DriveClient::parseFolderId($in['gallery_root']);
            if (trim($in['gallery_root']) !== '' && $root === '') {
                throw ApiError::invalid('Der Galerie-Ordner wurde nicht erkannt. Bitte den Link zum Ordner aus Google Drive einfügen.');
            }
        }
        if ($news && array_key_exists('news_source', $in)) {
            if (!in_array($in['news_source'], RemoteNewsSource::SOURCES, true)) {
                throw ApiError::invalid('Unbekannte Quelle für Neuigkeiten.');
            }
            $set['news_source'] = $in['news_source'];
        }
        if ($news && array_key_exists('news_url', $in)) {
            $set['news_url'] = Input::url($in['news_url'], 'Bitte die Adresse der Webseite bzw. des Feeds angeben (beginnt mit https://).');
        }
        if (($set['news_source'] ?? 'none') !== 'none' && ($set['news_url'] ?? ($news ? $a->news->url() : '')) === '') {
            throw ApiError::invalid('Bitte die Adresse der Webseite bzw. des Feeds angeben.');
        }

        // Speichern
        foreach ($set as $k => $v) {
            $a->settings->set($k, $v);
        }
        // Auch der Google-Zugang kann die Quelle des Kalenders sein (Kalender-IDs ohne API-Schlüssel).
        $driveChanged = array_key_exists('drive_service_account', $in) || array_key_exists('drive_oauth', $in);
        if (array_intersect(array_keys($set), ['calendar_ics_url', 'google_api_key', 'calendar_ids', 'calendar_cache_minutes'])
            || ($driveChanged && $a->calendar()->calendarIds() !== [])) {
            $a->calendar()->clearCache();
        }
        if (array_key_exists('drive_service_account', $in)) {
            $serviceAccount === null ? $a->drive()->removeServiceAccount() : $a->drive()->saveServiceAccount((string) json_encode($serviceAccount));
        }
        if (array_key_exists('drive_oauth', $in)) {
            // Trennen wie POST /admin/google/disconnect: bei Google widerrufen, der Client bleibt gespeichert.
            $a->drive()->disconnect();
            $a->settings->delete(GoogleConnect::RESULT_SETTING);
        }
        if ($root !== null) {
            $before = $a->gallery()->root();
            $a->gallery()->setRoot($root);
            if ($before !== '' && $before !== $root) {
                $a->gallery()->reset();
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
        $events = $cal->upcoming();
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
            'failed' => $g->countFailed(),
            'error' => (string) ($g->status()['error'] ?? ''),
        ];
    }
}
