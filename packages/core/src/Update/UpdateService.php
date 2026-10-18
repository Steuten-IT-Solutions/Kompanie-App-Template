<?php

declare(strict_types=1);

namespace KompanieApp\Core\Update;

use KompanieApp\Core\Domain\Time;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Port\BackupProvider;
use KompanieApp\Core\Port\FileStorage;
use KompanieApp\Core\Port\HttpClient;
use KompanieApp\Core\Port\Settings;
use KompanieApp\Core\Port\UpdateTarget;

/**
 * Updates der Installation: Update-Liste prüfen, passende Version wählen, Paket laden und
 * prüfen (Signatur der Liste, SHA-256 und Größe des Pakets), vorher sichern, einspielen.
 *
 * - Automatisch (täglich): neue Versionen derselben Hauptversion im eingestellten Kanal.
 * - Zurückgezogene Version: automatisch zurück auf die Version des Kanals.
 * - Von Hand: „Jetzt aktualisieren“, eine ältere Version wählen (Zurückrollen; diese Version
 *   wird dann beim automatischen Update übersprungen, bis eine neuere erscheint), neue Hauptversion.
 */
final class UpdateService
{
    public const TASK = 'update_check';
    public const INTERVAL = 86400;
    private const STATE = 'update_state';
    private const LAST = 'update_last';
    private const AUTO = 'update_auto';
    private const SKIP = 'update_skip';
    private const CHANNEL = 'update_channel';
    private const MAX_BACKUPS = 5;

    /**
     * @param array{updatesUrl?: string, trustedKeys?: array<string, string>} $config aus update.json des Pakets
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly HttpClient $http,
        private readonly FileStorage $files,
        private readonly Time $time,
        private readonly string $currentVersion,
        private readonly ?UpdateTarget $target,
        private readonly ?BackupProvider $backup,
        private readonly array $config,
    ) {
    }

    /** Ob Updates hier möglich sind (Paket mit Update-Adresse, Adapter kann einspielen). */
    public function configured(): bool
    {
        return $this->target !== null && $this->updatesUrl() !== '' && Version::valid($this->currentVersion);
    }

    public function channel(): string
    {
        $c = (string) $this->settings->get(self::CHANNEL, 'stable');
        return in_array($c, UpdateList::CHANNELS, true) ? $c : 'stable';
    }

    public function auto(): bool
    {
        return (bool) $this->settings->get(self::AUTO, true);
    }

    public function setAuto(bool $auto): void
    {
        $this->settings->set(self::AUTO, $auto);
    }

    /**
     * Lädt und prüft die Update-Liste.
     *
     * @throws ApiError 502 mit verständlicher Meldung
     */
    public function check(): UpdateList
    {
        $this->requireConfigured();
        $state = ['checked_at' => $this->time->timestamp(), 'error' => '', 'list' => null];
        try {
            $res = $this->http->request('GET', $this->updatesUrl(), ['Accept' => 'application/json', 'Cache-Control' => 'no-cache'], null, 20);
            if ($res['status'] !== 200) {
                throw new \RuntimeException('Die Update-Liste ist nicht erreichbar (HTTP ' . $res['status'] . ').');
            }
            $list = UpdateList::fromPayload(SignedEnvelope::open($res['body'], $this->trustedKeys()));
            $kompanie = (string) ($this->config['kompanie'] ?? '');
            if ($kompanie !== '' && $list->kompanie() !== $kompanie) {
                throw new \RuntimeException('Die Update-Liste gehört zu einer anderen Kompanie und wird nicht verwendet.');
            }
            // Nie eine ältere Liste als die zuletzt gesehene annehmen (Schutz vor dem Unterschieben
            // einer alten, ebenfalls signierten Liste, z. B. mit einer zurückgezogenen Version).
            $previous = (array) ($this->settings->get(self::STATE, [])['list'] ?? []);
            if ($list->serial() < (int) ($previous['serial'] ?? 0)) {
                throw new \RuntimeException('Die Update-Liste ist älter als die zuletzt geladene und wird nicht verwendet.');
            }
            $state['list'] = $list->toArray();
        } catch (\RuntimeException $e) {
            $state['error'] = $e->getMessage();
            $state['list'] = $this->settings->get(self::STATE, [])['list'] ?? null;
            $this->settings->set(self::STATE, $state);
            throw ApiError::upstream($e->getMessage());
        }
        $this->settings->set(self::STATE, $state);
        return UpdateList::fromPayload($state['list']);
    }

    /** @return array<string, mixed> Stand für die Statusseite und die Einstellungen */
    public function status(): array
    {
        $state = (array) $this->settings->get(self::STATE, []);
        $out = [
            'configured' => $this->configured(),
            'current' => $this->currentVersion,
            'channel' => $this->channel(),
            'auto' => $this->auto(),
            'checked_at' => $state['checked_at'] ?? null,
            'error' => (string) ($state['error'] ?? ''),
            'latest' => null,
            'available' => false,
            'major' => false,
            'withdrawn' => false,
            'fallback' => null,
            'skipped' => false,
            'autoAllowed' => $this->target?->autoUpdateAllowed() ?? false,
            'problems' => [],
            'versions' => [],
            'last' => $this->settings->get(self::LAST),
        ];
        if (!is_array($state['list'] ?? null) || !$this->configured()) {
            return $out;
        }
        $list = UpdateList::fromPayload($state['list']);
        $out['withdrawn'] = $list->isWithdrawn($this->currentVersion);
        // Updates innerhalb der eigenen Hauptversion, auch wenn der Kanal schon auf eine neuere zeigt.
        $latest = $list->latestFor(Version::major($this->currentVersion), $this->channel());
        if ($out['withdrawn'] && $latest !== null && $latest !== $this->currentVersion) {
            // Ziel des automatischen Wechsels (kann älter sein); null = keine geprüfte Version da.
            $out['fallback'] = $latest;
        }
        if ($latest !== null && Version::compare($latest, $this->currentVersion) > 0) {
            $out['latest'] = $latest;
            $out['available'] = true;
            $out['problems'] = $this->problems((array) $list->entry($latest));
            // Nach dem Zurückrollen wird diese Version beim automatischen Update übersprungen.
            $out['skipped'] = $this->skipped($latest);
        }
        $head = $list->channel($this->channel());
        if ($head !== null && Version::major($head) > Version::major($this->currentVersion)) {
            $out['major'] = true;
            $out['latest'] ??= $head;
            $out['majorVersion'] = $head;
        }
        foreach ($list->available() as $e) {
            if (Version::major($e['version']) === Version::major($this->currentVersion) && $e['version'] !== $this->currentVersion
                && ($this->channel() === 'early' || $list->wasStable($e['version']))) {
                $out['versions'][] = ['version' => $e['version'], 'published' => $e['published'] ?? ''];
            }
        }
        return $out;
    }

    /** Tägliche Aufgabe: Liste prüfen, bei Bedarf automatisch aktualisieren oder zurückrollen. */
    public function runTask(): void
    {
        if (!$this->configured()) {
            return;
        }
        $list = $this->check();
        $target = $list->latestFor(Version::major($this->currentVersion), $this->channel());
        if ($target === null || $target === $this->currentVersion) {
            return;
        }
        if (!$this->target->autoUpdateAllowed()) {
            return; // z. B. in WordPress gesperrt; die Statusseite zeigt verfügbare Versionen trotzdem an
        }
        if ($list->isWithdrawn($this->currentVersion)) {
            // Zurückgezogen: auf die neueste geprüfte Version derselben Hauptversion, auch wenn sie älter ist.
            $this->install($target, 'withdrawn');
            return;
        }
        if (!$this->auto() || Version::compare($target, $this->currentVersion) <= 0 || $this->skipped($target)
            || $this->problems((array) $list->entry($target)) !== []) {
            return;
        }
        $this->install($target, 'auto');
    }

    /**
     * Spielt eine Version aus der geprüften Liste ein.
     *
     * @param string $kind auto, manual, rollback, withdrawn
     * @param bool $allowMajor neue Hauptversion nur ausdrücklich
     */
    public function install(string $version, string $kind = 'manual', bool $allowMajor = false): void
    {
        $this->requireConfigured();
        $state = (array) $this->settings->get(self::STATE, []);
        if (!is_array($state['list'] ?? null)) {
            $this->check();
            $state = (array) $this->settings->get(self::STATE, []);
        }
        $list = UpdateList::fromPayload($state['list']);
        $entry = $list->entry($version);
        if ($entry === null || $list->isWithdrawn($version)) {
            throw ApiError::invalid("Version $version ist nicht verfügbar.");
        }
        if ($this->channel() === 'stable' && !$list->wasStable($version)) {
            throw ApiError::invalid("Version $version ist noch nicht für alle freigegeben.");
        }
        if (Version::major($version) > Version::major($this->currentVersion) && !$allowMajor) {
            throw ApiError::invalid('Eine neue Hauptversion wird nur auf ausdrückliche Bestätigung eingespielt.');
        }
        if (Version::major($version) < Version::major($this->currentVersion)) {
            throw ApiError::invalid('Über eine Hauptversion zurück geht es nur mit der Sicherung vor dem Update.');
        }
        if ($problems = $this->problems($entry)) {
            throw ApiError::invalid(implode(' ', $problems));
        }
        $file = $entry['files'][$this->target->variant()] ?? null;
        // Nur https (für Tests auch http auf dem eigenen Rechner).
        if (!is_array($file) || !preg_match('#^(https://|http://(127\.0\.0\.1|localhost)[:/])#', (string) ($file['url'] ?? ''))) {
            throw ApiError::invalid('Für diese Installation gibt es in dieser Version kein Paket.');
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        // Nur ein Update zur selben Zeit (täglicher Abgleich, zwei Admins, erneuter Klick nach einer
        // Zeitüberschreitung). flock gibt die Sperre auch frei, wenn der Prozess abbricht.
        // Kann das Dateisystem keine Sperren (manche Netzlaufwerke), geht es ohne weiter.
        $lock = @fopen($this->files->path('sicherungen/update.lock', false), 'c');
        if ($lock === false) {
            throw new ApiError(500, 'update_lock', 'Die Sperrdatei für Updates lässt sich nicht anlegen (Schreibrechte im Datenverzeichnis?).');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB, $wouldBlock)) {
            if ($wouldBlock) {
                throw new ApiError(409, 'update_running', 'Gerade läuft schon ein Update. Bitte in ein paar Minuten den Zustand neu laden.');
            }
            $lock = null;
        }
        // Klassen, die nach dem Austausch der Dateien noch gebraucht werden, vorher laden.
        class_exists(ApiError::class);
        class_exists(Version::class);
        $record = ['from' => $this->currentVersion, 'to' => $version, 'kind' => $kind, 'at' => $this->time->timestamp(), 'ok' => false, 'error' => ''];
        try {
            $zip = $this->download($file);
            try {
                $this->backupBefore($entry, Version::major($version) > Version::major($this->currentVersion));
                $this->target->install($zip, $version);
            } finally {
                @unlink($zip);
            }
        } catch (\RuntimeException $e) {
            $lock && flock($lock, LOCK_UN);
            $record['error'] = $e->getMessage();
            $this->settings->set(self::LAST, $record);
            throw $e instanceof ApiError ? $e : ApiError::upstream('Das Update hat nicht geklappt; die bisherige Version läuft weiter. ' . $e->getMessage());
        }
        $lock && flock($lock, LOCK_UN);
        $record['ok'] = true;
        $this->settings->set(self::LAST, $record);
        if ($kind === 'rollback') {
            $this->settings->set(self::SKIP, $this->currentVersion);
        } elseif (Version::compare($version, $this->currentVersion) > 0) {
            $this->settings->delete(self::SKIP);
        }
    }

    /** Zurück auf eine ältere Version derselben Hauptversion; die jetzige wird danach übersprungen. */
    public function rollback(string $version): void
    {
        if (!Version::valid($version) || Version::compare($version, $this->currentVersion) >= 0) {
            throw ApiError::invalid('Bitte eine ältere Version wählen.');
        }
        $this->install($version, 'rollback');
    }

    /** @return string[] Gründe, warum eine Version hier nicht läuft (in Alltagssprache) */
    public function problems(array $entry): array
    {
        $problems = [];
        $minPhp = (string) ($entry['minPhp'] ?? '8.1');
        if (version_compare(PHP_VERSION, $minPhp, '<')) {
            $problems[] = "Die neue Version braucht PHP $minPhp; auf dem Server läuft PHP " . PHP_VERSION . '. Die PHP-Version lässt sich meist im Kundenmenü des Hosters umstellen.';
        }
        $missing = array_values(array_filter((array) ($entry['extensions'] ?? []), static fn ($ext): bool => is_string($ext) && !extension_loaded($ext)));
        if ($missing) {
            $problems[] = 'Auf dem Server fehlen PHP-Erweiterungen: ' . implode(', ', $missing) . '. Sie lassen sich meist im Kundenmenü des Hosters einschalten.';
        }
        return [...$problems, ...($this->target?->problems($entry) ?? [])];
    }

    /** @param array<string, mixed> $file url, sha256, size */
    private function download(array $file): string
    {
        try {
            $res = $this->http->request('GET', (string) $file['url'], ['Accept' => 'application/octet-stream'], null, 120);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('Das Paket ließ sich nicht laden: ' . $e->getMessage());
        }
        if ($res['status'] !== 200) {
            throw new \RuntimeException('Das Paket ließ sich nicht laden (HTTP ' . $res['status'] . ').');
        }
        if (!hash_equals((string) $file['sha256'], hash('sha256', $res['body'])) || (isset($file['size']) && (int) $file['size'] !== strlen($res['body']))) {
            throw new \RuntimeException('Das geladene Paket stimmt nicht mit der signierten Liste überein und wird nicht verwendet.');
        }
        $path = $this->files->tempFile();
        if (file_put_contents($path, $res['body']) === false) {
            throw new \RuntimeException('Das Paket kann nicht zwischengespeichert werden (Speicherplatz?).');
        }
        return $path;
    }

    /**
     * Vor Updates mit Datenbank-Änderungen und vor einer neuen Hauptversion automatisch sichern
     * (geschützter Bereich, die letzten 5).
     */
    private function backupBefore(array $entry, bool $newMajor): void
    {
        if ($this->backup === null) {
            return;
        }
        $schema = (array) ($entry['schema'] ?? []);
        $changes = $newMajor || (int) ($schema['core'] ?? 0) > (int) $this->settings->get('schema_version', 0)
            || ($this->target->variant() === 'standalone' && (int) ($schema['standalone'] ?? 0) > (int) $this->settings->get('standalone_schema_version', 0));
        if (!$changes) {
            return;
        }
        // Fortlaufende Nummer vorn (sortiert richtig, auch bei Versionen wie 1.4.0.10 und 1.4.0.9 und
        // mehreren Updates in derselben Sekunde); zufälliger Teil, weil unter WordPress auf nginx der
        // .htaccess-Schutz des Ordners nicht greift.
        $dir = dirname($this->files->path('sicherungen/x', false));
        $seq = 0;
        // Nur Dateien im Format mit Nummer zählen (ältere Namen ohne Nummer gelten als älter).
        $numbered = glob($dir . '/vor-update-[0-9][0-9][0-9][0-9][0-9][0-9]-*.json') ?: [];
        foreach ($numbered as $f) {
            $seq = max($seq, (int) substr(basename($f), strlen('vor-update-'), 6));
        }
        $name = sprintf('vor-update-%06d-%s-%s-%s.json', $seq + 1, gmdate('Ymd-His', $this->time->timestamp()), $this->currentVersion, bin2hex(random_bytes(16)));
        $path = $dir . '/' . $name;
        if (!is_file(dirname($path) . '/index.html')) {
            @file_put_contents(dirname($path) . '/index.html', '');
        }
        try {
            $json = json_encode($this->backup->export(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Die Sicherung vor dem Update ließ sich nicht erstellen (' . $e->getMessage() . '). Das Update wurde nicht eingespielt.');
        }
        $written = file_put_contents($path, $json);
        if ($written === false || $written < strlen($json)) {
            @unlink($path);
            throw new \RuntimeException('Die Sicherung vor dem Update ließ sich nicht schreiben (Speicherplatz?). Das Update wurde nicht eingespielt.');
        }
        @chmod($path, 0600);
        $numbered = glob($dir . '/vor-update-[0-9][0-9][0-9][0-9][0-9][0-9]-*.json') ?: [];
        sort($numbered);
        $all = [...array_values(array_diff(glob($dir . '/vor-update-*.json') ?: [], $numbered)), ...$numbered];
        foreach (array_slice($all, 0, max(0, count($all) - self::MAX_BACKUPS)) as $old) {
            @unlink($old);
        }
    }

    private function skipped(string $version): bool
    {
        $skip = (string) $this->settings->get(self::SKIP, '');
        return $skip !== '' && Version::valid($skip) && Version::compare($version, $skip) <= 0;
    }

    private function updatesUrl(): string
    {
        return trim((string) ($this->config['updatesUrl'] ?? ''));
    }

    /** @return array<string, string> */
    private function trustedKeys(): array
    {
        return TrustedKeys::all() + array_filter((array) ($this->config['trustedKeys'] ?? []), 'is_string');
    }

    private function requireConfigured(): void
    {
        if (!$this->configured()) {
            throw new ApiError(503, 'not_configured', 'Updates sind für diese Installation nicht eingerichtet.');
        }
    }
}
