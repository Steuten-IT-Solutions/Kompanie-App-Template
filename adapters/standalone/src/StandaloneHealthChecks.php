<?php

declare(strict_types=1);

namespace KompanieApp\Standalone;

use KompanieApp\Core\Port\Cache;
use KompanieApp\Core\Port\HealthChecks;
use KompanieApp\Core\Port\HttpClient;

/**
 * Prüfungen der eigenständigen Version für die Statusseite:
 * - Sind config.php und die Datenbank von außen abrufbar? (Webserver ohne .htaccess, z. B. nginx)
 * - Läuft ein Cronjob oder nur der Pseudo-Cron?
 */
final class StandaloneHealthChecks implements HealthChecks
{
    public function __construct(
        private readonly Config $config,
        private readonly HttpClient $http,
        private readonly Cache $cache,
    ) {
    }

    public function checks(): array
    {
        return [$this->dataProtection()];
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
