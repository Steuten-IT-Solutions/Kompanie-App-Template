<?php

declare(strict_types=1);

namespace KompanieApp\Standalone;

use KompanieApp\Core\Port\Cache;
use KompanieApp\Core\Port\HealthChecks;
use KompanieApp\Core\Port\HttpClient;
use KompanieApp\Core\Port\Settings;

/**
 * Prüfungen der eigenständigen Version für die Statusseite:
 * - Sind config.php und die Datenbank von außen abrufbar? (Webserver ohne .htaccess, z. B. nginx)
 * - Läuft ein Cronjob oder nur der Pseudo-Cron, und klappt der Selbstaufruf?
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
        return [$this->dataProtection(), $this->cron()];
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
        $item = static fn (string $level, string $text): array => ['key' => 'cron', 'label' => 'Hintergrundaufgaben', 'level' => $level, 'text' => $text];
        $now = time();
        $date = fn (int $ts): string => (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone($this->config->timezone()))->format('d.m.Y H:i');
        $runs = $this->settings->get(Kernel::CRON_RUNS_SETTING);
        $runs = is_array($runs) ? array_map('intval', $runs) : [];
        $hosted = max($runs['cron'] ?? 0, $runs['cli'] ?? 0);
        if ($hosted > $now - 2 * 86400) {
            return $item('ok', 'Ein Cronjob beim Hoster erledigt die Hintergrundaufgaben (zuletzt ' . $date($hosted) . ').');
        }
        if ($this->kernel->scheduler()->nextRun() === null) {
            return $item('off', 'Zurzeit sind keine Hintergrundaufgaben eingeplant.');
        }
        $advice = ' Bitte beim Hoster einen Cronjob einrichten, der alle 15 Minuten diese Adresse aufruft: '
            . $this->kernel->cronUrl() . ' (oder auf dem Server „php bin/kompanie.php cron“ ausführt).';
        if (Kernel::canFinishResponse()) {
            return $item('ok', 'Die Hintergrundaufgaben laufen nach Seitenaufrufen'
                . (isset($runs['request']) ? ' (zuletzt ' . $date($runs['request']) . ')' : '') . '. Wird die App selten aufgerufen, bleiben sie liegen.'
                . ' Zuverlässiger ist ein Cronjob beim Hoster, der alle 15 Minuten diese Adresse aufruft: ' . $this->kernel->cronUrl());
        }
        if ($this->config->baseUrl() === '') {
            return $item('warn', 'Die Adresse der App ist nicht eingetragen; deshalb kann der Server die Hintergrundaufgaben (Galerie, Kalender, Updates) nicht selbst anstoßen.' . $advice);
        }
        $spawn = $this->settings->get(Kernel::CRON_SPAWN_SETTING);
        $spawn = is_array($spawn) ? $spawn : [];
        $arrived = $runs['spawn'] ?? 0;
        if ((int) ($spawn['failures'] ?? 0) >= 3 && $arrived < (int) ($spawn['at'] ?? 0)) {
            return $item('error', 'Der Server kann sich nicht selbst aufrufen (' . rtrim((string) ($spawn['error'] ?? ''), '.')
                . '). Deshalb laufen die Hintergrundaufgaben (Galerie, Kalender, Updates) nicht zuverlässig.' . $advice);
        }
        return $item('ok', 'Die Hintergrundaufgaben laufen nach Seitenaufrufen über einen Aufruf der eigenen Adresse'
            . ($arrived > 0 ? ' (zuletzt ' . $date($arrived) . ')' : '') . '. Wird die App selten aufgerufen, bleiben sie liegen.'
            . ' Zuverlässiger ist ein Cronjob beim Hoster, der alle 15 Minuten diese Adresse aufruft: ' . $this->kernel->cronUrl());
    }

    /** @return array{key: string, label: string, level: string, text: string} */
    public function dataProtection(bool $fresh = false): array
    {
        $item = static fn (string $level, string $text): array => ['key' => 'data', 'label' => 'Schutz der Daten', 'level' => $level, 'text' => $text];
        $base = $this->config->baseUrl();
        $dataDir = realpath($this->config->dataDir) ?: $this->config->dataDir;
        $root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
        if (!str_starts_with($dataDir . '/', $root . '/')) {
            return $item('ok', 'Die Daten liegen außerhalb des Web-Verzeichnisses.');
        }
        $cached = $fresh ? null : $this->cache->get('health_data');
        if (is_array($cached)) {
            return $cached;
        }
        $db = $this->config->toArray()['db'] ?? [];
        $files = ['config.php'];
        if (($db['driver'] ?? 'sqlite') === 'sqlite') {
            $files[] = (string) ($db['path'] ?? '');
        }
        $result = $item('ok', 'Datenbank und Zugangsdaten sind von außen nicht abrufbar (geprüft).');
        if ($base === '') {
            $result = $item('warn', 'Die Adresse der App ist nicht eingetragen; der Schutz der Daten ließ sich nicht prüfen.');
        } else {
            foreach ($files as $f) {
                $rel = substr($dataDir, strlen($root) + 1) . '/' . $f;
                try {
                    $res = $this->http->request('GET', $base . str_replace('%2F', '/', rawurlencode($rel)), [], null, 5);
                } catch (\RuntimeException) {
                    continue; // nicht erreichbar ist hier gut
                }
                if ($res['status'] === 200 && $res['body'] !== '') {
                    $result = $item('error', 'Achtung: Dateien mit Daten sind aus dem Internet abrufbar (' . $f . '). Der Webserver wertet die Sperre (.htaccess) nicht aus. Bitte sofort beim Hoster das Verzeichnis „data“ sperren lassen oder die Daten außerhalb des Web-Verzeichnisses ablegen (siehe Anleitung).');
                    break;
                }
            }
        }
        $this->cache->set('health_data', $result, 86400);
        return $result;
    }
}
