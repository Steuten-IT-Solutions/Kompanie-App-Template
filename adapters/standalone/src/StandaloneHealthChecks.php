<?php

declare(strict_types=1);

namespace KompanieApp\Standalone;

use KompanieApp\Core\I18n\I18n;
use KompanieApp\Core\Port\Cache;
use KompanieApp\Core\Port\HealthChecks;
use KompanieApp\Core\Port\HttpClient;
use KompanieApp\Core\Port\Settings;

/**
 * Prüfungen der eigenständigen Version für die Statusseite:
 * - Sind config.php und die Datenbank von außen abrufbar? (Webserver ohne .htaccess, z. B. nginx)
 * - Läuft ein Cronjob oder nur der Pseudo-Cron, und klappt der Selbstaufruf?
 * - Läuft die App über HTTPS (verschlüsselte Verbindung)?
 */
final class StandaloneHealthChecks implements HealthChecks
{
    public function __construct(
        private readonly Config $config,
        private readonly HttpClient $http,
        private readonly Cache $cache,
        private readonly Settings $settings,
        private readonly Kernel $kernel,
    ) {
    }

    public function checks(): array
    {
        return array_values(array_filter([$this->dataProtection(), $this->https(), $this->cron()]));
    }

    /**
     * Über HTTP gehen Passwörter, Sitzungen und alle Daten der Mitglieder unverschlüsselt durchs Netz. Geprüft wird die
     * fest eingetragene Adresse (ohne sie die der Anfrage); localhost zählt nicht (Test auf dem eigenen Rechner).
     *
     * @return array{key: string, label: string, level: string, text: string}|null
     */
    public function https(): ?array
    {
        // Ohne fest eingetragene Adresse die der Anfrage (über die die Statusseite gerade geöffnet ist).
        $base = $this->config->baseUrl();
        if ($base === '' && $this->kernel->requestPath() === '/cron') {
            return null; // Cronjob des Hosters: sagt nichts darüber, wie Mitglieder die App öffnen
        }
        $https = $base !== '' ? strtolower((string) parse_url($base, PHP_URL_SCHEME)) === 'https' : $this->kernel->http()->isHttps();
        $host = strtolower((string) ($base !== '' ? parse_url($base, PHP_URL_HOST) : parse_url($this->kernel->http()->baseUrl(), PHP_URL_HOST)));
        if (in_array($host, ['', 'localhost', '127.0.0.1', '::1', '[::1]'], true)) {
            return null;
        }
        $item = static fn (string $level, string $text): array => ['key' => 'https', 'label' => I18n::t('Verschlüsselte Verbindung'), 'level' => $level, 'text' => $text];
        if ($https) {
            return $item('ok', I18n::t('Die App läuft über HTTPS; Passwörter und Daten gehen verschlüsselt durchs Netz.'));
        }
        return $item('warn', I18n::t('Die App läuft über HTTP: Passwörter und Daten der Mitglieder gehen unverschlüsselt durchs Netz. Bitte beim Hoster ein Zertifikat einrichten lassen (oft kostenlos über Let’s Encrypt) und die Adresse der App auf https:// umstellen (Eintrag base_url in config.php).'));
    }

    /**
     * Wie laufen die Hintergrundaufgaben (Galerie, Kalender, Updates)? Über einen Cronjob des Hosters,
     * nach Seitenaufrufen im selben Prozess (PHP-FPM, LiteSpeed) oder über einen Selbstaufruf – und
     * klappt der Selbstaufruf?
     *
     * @return array{key: string, label: string, level: string, text: string}
     */
    public function cron(): array
    {
        $item = static fn (string $level, string $text): array => ['key' => 'cron', 'label' => I18n::t('Hintergrundaufgaben'), 'level' => $level, 'text' => $text];
        $now = time();
        $date = fn (int $ts): string => (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone($this->config->timezone()))->format('d.m.Y H:i');
        $runs = $this->settings->get(Kernel::CRON_RUNS_SETTING);
        $runs = is_array($runs) ? array_map('intval', $runs) : [];
        $hosted = max($runs['cron'] ?? 0, $runs['cli'] ?? 0);
        if ($hosted > $now - 2 * 86400) {
            return $item('ok', I18n::t('Ein Cronjob beim Hoster erledigt die Hintergrundaufgaben (zuletzt {date}).', ['date' => $date($hosted)]));
        }
        if ($this->kernel->scheduler()->nextRun() === null) {
            return $item('off', I18n::t('Zurzeit sind keine Hintergrundaufgaben eingeplant.'));
        }
        $advice = ' ' . I18n::t('Bitte beim Hoster einen Cronjob einrichten, der alle 15 Minuten diese Adresse aufruft: {url} (oder auf dem Server „php bin/kompanie.php cron“ ausführt; mit curl besser den Schlüssel im Header X-Kompanie-Cron-Key statt in der Adresse senden, dann steht er nicht im Protokoll des Webservers).', ['url' => $this->kernel->cronUrl()]);
        $reliable = ' ' . I18n::t('Wird die App selten aufgerufen, bleiben sie liegen. Zuverlässiger ist ein Cronjob beim Hoster, der alle 15 Minuten diese Adresse aufruft: {url}', ['url' => $this->kernel->cronUrl()]);
        if (Kernel::canFinishResponse()) {
            return $item('ok', (isset($runs['request'])
                ? I18n::t('Die Hintergrundaufgaben laufen nach Seitenaufrufen (zuletzt {date}).', ['date' => $date($runs['request'])])
                : I18n::t('Die Hintergrundaufgaben laufen nach Seitenaufrufen.')) . $reliable);
        }
        if ($this->config->baseUrl() === '') {
            return $item('warn', I18n::t('Die Adresse der App ist nicht eingetragen; deshalb kann der Server die Hintergrundaufgaben (Galerie, Kalender, Updates) nicht selbst anstoßen.') . $advice);
        }
        $spawn = $this->settings->get(Kernel::CRON_SPAWN_SETTING);
        $spawn = is_array($spawn) ? $spawn : [];
        $arrived = $runs['spawn'] ?? 0;
        if ((int) ($spawn['failures'] ?? 0) >= 3 && $arrived < (int) ($spawn['at'] ?? 0)) {
            return $item('error', I18n::t('Der Server kann sich nicht selbst aufrufen ({error}). Deshalb laufen die Hintergrundaufgaben (Galerie, Kalender, Updates) nicht zuverlässig.', ['error' => rtrim((string) ($spawn['error'] ?? ''), '.')]) . $advice);
        }
        return $item('ok', ($arrived > 0
            ? I18n::t('Die Hintergrundaufgaben laufen nach Seitenaufrufen über einen Aufruf der eigenen Adresse (zuletzt {date}).', ['date' => $date($arrived)])
            : I18n::t('Die Hintergrundaufgaben laufen nach Seitenaufrufen über einen Aufruf der eigenen Adresse.')) . $reliable);
    }

    /** @return array{key: string, label: string, level: string, text: string} */
    public function dataProtection(bool $fresh = false): array
    {
        $item = static fn (string $level, string $text): array => ['key' => 'data', 'label' => I18n::t('Schutz der Daten'), 'level' => $level, 'text' => $text];
        $base = $this->config->baseUrl();
        $dataDir = realpath($this->config->dataDir) ?: $this->config->dataDir;
        $root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
        if (!str_starts_with($dataDir . '/', $root . '/')) {
            return $item('ok', I18n::t('Die Daten liegen außerhalb des Web-Verzeichnisses.'));
        }
        if ($base === '') {
            return $item('warn', I18n::t('Die Adresse der App ist nicht eingetragen; der Schutz der Daten ließ sich nicht prüfen.'));
        }
        // Zwischengespeichert wird das Ergebnis der Prüfung, nicht der Text: Der folgt der Sprache der Anfrage.
        $cached = $fresh ? null : $this->cache->get('health_data');
        if (is_array($cached) && array_key_exists('exposed', $cached)) {
            $exposed = is_string($cached['exposed']) ? $cached['exposed'] : null;
        } else {
            $exposed = $this->exposedFile($base, $dataDir, $root);
            $this->cache->set('health_data', ['exposed' => $exposed], 86400);
        }
        return $exposed === null
            ? $item('ok', I18n::t('Datenbank und Zugangsdaten sind von außen nicht abrufbar (geprüft).'))
            : $item('error', I18n::t('Achtung: Dateien mit Daten sind aus dem Internet abrufbar ({file}). Der Webserver wertet die Sperre (.htaccess) nicht aus. Bitte sofort beim Hoster das Verzeichnis „data“ sperren lassen oder die Daten außerhalb des Web-Verzeichnisses ablegen (siehe Anleitung).', ['file' => $exposed]));
    }

    /** Erste Datei mit Daten, die aus dem Internet abrufbar ist, sonst null. */
    private function exposedFile(string $base, string $dataDir, string $root): ?string
    {
        $db = $this->config->toArray()['db'] ?? [];
        $files = ['config.php'];
        if (($db['driver'] ?? 'sqlite') === 'sqlite') {
            $files[] = (string) ($db['path'] ?? '');
        }
        foreach ($files as $f) {
            $rel = substr($dataDir, strlen($root) + 1) . '/' . $f;
            try {
                $res = $this->http->request('GET', $base . str_replace('%2F', '/', rawurlencode($rel)), [], null, 5);
            } catch (\RuntimeException) {
                continue; // nicht erreichbar ist hier gut
            }
            if ($res['status'] === 200 && $res['body'] !== '') {
                return $f;
            }
        }
        return null;
    }
}
