<?php

declare(strict_types=1);

namespace KompanieApp\Core\Update;

/**
 * Die Update-Liste einer Kompanie (updates.json, signiert, auf GitHub Pages).
 *
 * - type/kompanie: wofür die Liste gilt; Installationen nehmen nur die Liste ihrer Kompanie an
 * - serial: steigt mit jeder Änderung; Installationen nehmen nie eine ältere Liste an
 *   (Schutz vor dem Wiedereinspielen einer alten, ebenfalls signierten Liste)
 * - versions: alle veröffentlichten Versionen, neueste zuerst, mit Adresse und SHA-256 der Pakete
 * - channels: early (nur unsere Demo-Installation) und stable (alle Kompanien)
 * - stableHistory: Versionen, die schon einmal für alle freigegeben waren (Rückfallziele)
 * - withdrawn: zurückgezogene Versionen; Installationen darauf rollen automatisch zurück
 * - blockedCores: Kern-Versionen mit zurückgezogenem Release; sie gehen nie mehr direkt nach stable
 */
final class UpdateList
{
    public const FORMAT = 1;
    public const TYPE = 'updates';
    public const CHANNELS = ['early', 'stable'];
    private const MAX_VERSIONS = 60;

    /** @param array<string, mixed> $data */
    private function __construct(private array $data)
    {
    }

    public static function create(string $kompanie, string $name): self
    {
        return new self([
            'format' => self::FORMAT,
            'type' => self::TYPE,
            'kompanie' => $kompanie,
            'name' => $name,
            'serial' => 0,
            'generated' => gmdate('c'),
            'channels' => ['early' => null, 'stable' => null],
            'stableHistory' => [],
            'withdrawn' => [],
            'blockedCores' => [],
            'versions' => [],
        ]);
    }

    /** @param array<string, mixed> $payload geprüfter Inhalt (SignedEnvelope::open) */
    public static function fromPayload(array $payload): self
    {
        if ((int) ($payload['format'] ?? 0) !== self::FORMAT || ($payload['type'] ?? self::TYPE) !== self::TYPE
            || !is_array($payload['versions'] ?? null) || !is_array($payload['channels'] ?? null)) {
            throw new \RuntimeException('Die Update-Liste hat ein unbekanntes Format.');
        }
        foreach (['withdrawn', 'stableHistory', 'blockedCores'] as $k) {
            $payload[$k] = array_values(array_filter((array) ($payload[$k] ?? []), 'is_string'));
        }
        $payload['type'] = self::TYPE;
        $payload['serial'] = (int) ($payload['serial'] ?? 0);
        return new self($payload);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    public function kompanie(): string
    {
        return (string) ($this->data['kompanie'] ?? '');
    }

    public function serial(): int
    {
        return (int) $this->data['serial'];
    }

    /**
     * Nimmt ein Release auf (Inhalt von release.json) und setzt es in den Kanal $channel.
     * Neue Versionen müssen neuer sein als alle bisherigen; vorhandene werden nie überschrieben.
     *
     * @param array<string, mixed> $release
     * @param string $baseUrl Adresse, unter der die Paketdateien liegen (mit abschließendem /)
     */
    public function addRelease(array $release, string $baseUrl, string $channel = 'early'): void
    {
        $v = (string) ($release['version'] ?? '');
        if (!Version::valid($v)) {
            throw new \RuntimeException('release.json enthält keine gültige Version.');
        }
        if (($release['type'] ?? 'release') !== 'release') {
            throw new \RuntimeException('Das ist keine release.json.');
        }
        if ($this->kompanie() !== '' && (string) ($release['kompanie'] ?? '') !== $this->kompanie()) {
            throw new \RuntimeException('Das Release gehört zu einer anderen Kompanie (' . ($release['kompanie'] ?? '?') . ').');
        }
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new \RuntimeException('Unbekannter Kanal: ' . $channel);
        }
        if ($this->entry($v) !== null || in_array($v, $this->data['withdrawn'], true)) {
            throw new \RuntimeException("Version $v gibt es schon; veröffentlichte Versionen werden nie ersetzt.");
        }
        foreach ($this->data['versions'] as $e) {
            if (Version::compare($e['version'], $v) > 0) {
                throw new \RuntimeException("Version $v ist älter als die schon veröffentlichte {$e['version']}.");
            }
        }
        $files = [];
        foreach ((array) ($release['files'] ?? []) as $variant => $f) {
            if (!is_array($f) || !preg_match('/^[a-f0-9]{64}$/', (string) ($f['sha256'] ?? '')) || !is_string($f['name'] ?? null)) {
                throw new \RuntimeException("release.json: Angaben zum Paket $variant fehlen.");
            }
            $files[$variant] = ['url' => $baseUrl . rawurlencode($f['name']), 'sha256' => $f['sha256'], 'size' => (int) ($f['size'] ?? 0)];
        }
        if (!isset($files['wordpress'], $files['standalone'])) {
            throw new \RuntimeException('release.json muss beide Pakete enthalten.');
        }
        array_unshift($this->data['versions'], [
            'version' => $v,
            'major' => Version::major($v),
            'coreVersion' => (string) ($release['coreVersion'] ?? ''),
            'coreCommit' => (string) ($release['coreCommit'] ?? ''),
            'minPhp' => (string) ($release['minPhp'] ?? '8.1'),
            'minWordPress' => (string) ($release['minWordPress'] ?? '6.2'),
            'extensions' => array_values((array) ($release['extensions'] ?? [])),
            'schema' => (array) ($release['schema'] ?? []),
            'published' => (string) ($release['published'] ?? gmdate('Y-m-d')),
            'files' => $files,
        ]);
        $this->data['channels']['early'] = $v;
        if ($channel === 'stable') {
            $this->markStable($v);
        }
        $this->trim();
        $this->touch();
    }

    /**
     * Kanal für ein neues Release. Direkt stable, wenn der Programmcode schon geprüft ist:
     * - die Kern-Version hat den Test der Demo-Installation bestanden ($stableCore, Datei STABLE_CORE), oder
     * - es ändert sich nur das Aussehen (gleicher Kern-Commit bzw. gleiche Kern-Version wie die Version in stable).
     * Nie für Kern-Versionen, von denen ein Release zurückgezogen wurde. Sonst early.
     *
     * @param array<string, mixed> $release
     */
    public function channelFor(array $release, string $stableCore = ''): string
    {
        $core = (string) ($release['coreVersion'] ?? '');
        if ($core === '' || in_array($core, $this->data['blockedCores'], true)) {
            return 'early';
        }
        if ($stableCore !== '' && $core === $stableCore) {
            return 'stable';
        }
        $stable = $this->channel('stable');
        $entry = $stable !== null ? $this->entry($stable) : null;
        if ($entry === null || ($entry['coreVersion'] ?? null) !== $core) {
            return 'early';
        }
        // Gleiche Kern-Version: Ist der Commit bekannt, muss auch er gleich sein (kein still verschobenes v1).
        $commit = (string) ($release['coreCommit'] ?? '');
        return $commit === '' || ($entry['coreCommit'] ?? '') === '' || $entry['coreCommit'] === $commit ? 'stable' : 'early';
    }

    /** Befördert eine Version nach stable (nach erfolgreichem Test der Demo-Installation). */
    public function promote(string $version): void
    {
        if ($this->entry($version) === null) {
            throw new \RuntimeException("Version $version ist nicht in der Liste.");
        }
        if (in_array($version, $this->data['withdrawn'], true)) {
            throw new \RuntimeException("Version $version wurde zurückgezogen.");
        }
        $current = $this->channel('stable');
        if ($current !== null && Version::compare($version, $current) < 0) {
            throw new \RuntimeException("Version $version ist älter als die freigegebene $current. Zum Zurückgehen bitte die neuere Version zurückziehen.");
        }
        $this->markStable($version);
        $this->touch();
    }

    /**
     * Zieht eine Version zurück. stable fällt auf die letzte Version derselben Hauptversion zurück,
     * die schon einmal freigegeben war; early auf die nächstältere nicht zurückgezogene. Die
     * Kern-Version ist danach gesperrt (kein direkter Weg mehr nach stable).
     */
    public function withdraw(string $version): void
    {
        $entry = $this->entry($version);
        if ($entry === null) {
            throw new \RuntimeException("Version $version ist nicht in der Liste.");
        }
        if (!in_array($version, $this->data['withdrawn'], true)) {
            $this->data['withdrawn'][] = $version;
        }
        $core = (string) ($entry['coreVersion'] ?? '');
        if ($core !== '' && !in_array($core, $this->data['blockedCores'], true)) {
            $this->data['blockedCores'][] = $core;
        }
        if ($this->channel('stable') === $version) {
            $this->data['channels']['stable'] = $this->previous($version, true);
        }
        if ($this->channel('early') === $version) {
            $this->data['channels']['early'] = $this->previous($version, false) ?? $this->channel('stable');
        }
        $this->touch();
    }

    public function channel(string $channel): ?string
    {
        $v = $this->data['channels'][$channel] ?? null;
        return is_string($v) && Version::valid($v) ? $v : null;
    }

    public function isWithdrawn(string $version): bool
    {
        return in_array($version, $this->data['withdrawn'], true);
    }

    public function wasStable(string $version): bool
    {
        return in_array($version, $this->data['stableHistory'], true);
    }

    /** @return array<string, mixed>|null */
    public function entry(string $version): ?array
    {
        foreach ($this->data['versions'] as $e) {
            if (($e['version'] ?? null) === $version) {
                return $e;
            }
        }
        return null;
    }

    /** @return array<int, array<string, mixed>> neueste zuerst, ohne zurückgezogene */
    public function available(): array
    {
        return array_values(array_filter($this->data['versions'], fn (array $e): bool => !$this->isWithdrawn($e['version'])));
    }

    /**
     * Neueste Version einer Hauptversion für einen Kanal (stable: nur freigegebene).
     * So bekommen Installationen einer älteren Hauptversion weiter Updates, auch wenn stable
     * schon auf die nächste Hauptversion zeigt.
     */
    public function latestFor(int $major, string $channel): ?string
    {
        foreach ($this->available() as $e) {
            if (Version::major($e['version']) === $major && ($channel === 'early' || $this->wasStable($e['version']))) {
                return $e['version'];
            }
        }
        return null;
    }

    private function markStable(string $version): void
    {
        $this->data['channels']['stable'] = $version;
        if (!in_array($version, $this->data['stableHistory'], true)) {
            $this->data['stableHistory'][] = $version;
        }
        if ($this->channel('early') === null || Version::compare($this->channel('early'), $version) < 0) {
            $this->data['channels']['early'] = $version;
        }
    }

    private function previous(string $version, bool $stableOnly): ?string
    {
        foreach ($this->available() as $e) {
            if (Version::compare($e['version'], $version) < 0 && Version::major($e['version']) === Version::major($version)
                && (!$stableOnly || $this->wasStable($e['version']))) {
                return $e['version'];
            }
        }
        return null;
    }

    /** Ältere Einträge kürzen; Kanalziele und die letzten freigegebenen Versionen bleiben immer. */
    private function trim(): void
    {
        $keep = array_filter([$this->channel('early'), $this->channel('stable')]);
        $keep = array_merge($keep, array_slice($this->data['stableHistory'], -10));
        $out = [];
        foreach ($this->data['versions'] as $i => $e) {
            if ($i < self::MAX_VERSIONS || in_array($e['version'], $keep, true)) {
                $out[] = $e;
            }
        }
        $this->data['versions'] = $out;
    }

    private function touch(): void
    {
        $this->data['serial'] = $this->serial() + 1;
        $this->data['generated'] = gmdate('c');
    }
}
