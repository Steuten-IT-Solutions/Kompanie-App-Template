<?php

declare(strict_types=1);

namespace KompanieApp\Core\Update;

use KompanieApp\Core\App;

/**
 * Statusmeldungen an uns – nur mit Zustimmung der Kompanie (Einstellung status_reports).
 * Inhalt abschließend: Kompanie, Version, Variante, PHP-Version, Datenbank, Ergebnis des
 * letzten Updates und Galerie-Abgleichs (nur ja/nein und Zeitpunkt), keine Mitgliederdaten,
 * keine Adressen, keine Fehlertexte. Siehe docs/OFFENE-PUNKTE.md, Abschnitt 4.
 */
final class StatusReporter
{
    public const TASK = 'status_report';
    public const SETTING = 'status_reports';

    public function __construct(private readonly App $app)
    {
    }

    public function enabled(): bool
    {
        return (bool) $this->app->settings->get(self::SETTING, false) && $this->url() !== '';
    }

    /** @return array<string, mixed> genau das, was gesendet wird (auch zum Anzeigen in der App) */
    public function payload(): array
    {
        $a = $this->app;
        $last = $a->settings->get('update_last');
        $gallery = $a->gallery()->status();
        return [
            'format' => 1,
            'kompanie' => (string) ($a->packageConfig['kompanie'] ?? ''),
            'version' => $a->env->version(),
            'variant' => $a->env->variant(),
            'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'database' => $a->db->driver(),
            'update' => is_array($last) ? ['ok' => (bool) $last['ok'], 'at' => (int) $last['at'], 'to' => (string) $last['to']] : null,
            'updateCheckOk' => (string) (((array) $a->settings->get('update_state', []))['error'] ?? '') === '',
            'gallery' => $a->gallery()->isConfigured() ? ['ok' => ($gallery['error'] ?? '') === '', 'at' => (int) ($gallery['last_sync'] ?? 0)] : null,
            'sent' => $a->time()->timestamp(),
        ];
    }

    public function runTask(): void
    {
        if (!$this->enabled()) {
            return;
        }
        $res = $this->app->http->request('POST', $this->url(), ['Content-Type' => 'application/json'], (string) json_encode($this->payload()), 10);
        if ($res['status'] >= 300) {
            throw new \RuntimeException('Statusmeldung nicht angenommen (HTTP ' . $res['status'] . ').');
        }
    }

    private function url(): string
    {
        $url = trim((string) ($this->app->packageConfig['statusUrl'] ?? ''));
        return str_starts_with($url, 'https://') ? $url : '';
    }
}
