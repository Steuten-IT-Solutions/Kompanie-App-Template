<?php

declare(strict_types=1);

namespace KompanieApp\Core\Update;

/**
 * Die Update-Liste einer Kompanie (updates.json, signiert, auf GitHub Pages).
 *
 * - versions: alle veröffentlichten Versionen, neueste zuerst, mit Adresse und SHA-256 der Pakete
 * - channels: early (nur unsere Demo-Installation) und stable (alle Kompanien)
 * - withdrawn: zurückgezogene Versionen; Installationen darauf rollen automatisch zurück
 */
final class UpdateList
{
    public const FORMAT = 1;
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
            'kompanie' => $kompanie,
            'name' => $name,
            'generated' => gmdate('c'),
            'channels' => ['early' => null, 'stable' => null],
            'withdrawn' => [],
            'versions' => [],
        ]);
    }

    /** @param array<string, mixed> $payload geprüfter Inhalt (SignedEnvelope::open) */
    public static function fromPayload(array $payload): self
    {
        if ((int) ($payload['format'] ?? 0) !== self::FORMAT || !is_array($payload['versions'] ?? null) || !is_array($payload['channels'] ?? null)) {
            throw new \RuntimeException('Die Update-Liste hat ein unbekanntes Format.');
        }
        $payload['withdrawn'] = array_values(array_filter((array) ($payload['withdrawn'] ?? []), 'is_string'));
        return new self($payload);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * Nimmt ein Release auf (Inhalt von release.json) und setzt es in den Kanal $channel.
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
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new \RuntimeException('Unbekannter Kanal: ' . $channel);
        }
        if (in_array($v, $this->data['withdrawn'], true)) {
            throw new \RuntimeException("Version $v wurde zurückgezogen und kann nicht erneut veröffentlicht werden.");
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
        $entry = [
            'version' => $v,
            'major' => Version::major($v),
            'coreVersion' => (string) ($release['coreVersion'] ?? ''),
            'minPhp' => (string) ($release['minPhp'] ?? '8.1'),
            'minWordPress' => (string) ($release['minWordPress'] ?? '6.2'),
            'extensions' => array_values((array) ($release['extensions'] ?? [])),
            'schema' => (array) ($release['schema'] ?? []),
            'published' => (string) ($release['published'] ?? gmdate('Y-m-d')),
            'files' => $files,
        ];
        $versions = array_values(array_filter($this->data['versions'], static fn (array $e): bool => $e['version'] !== $v));
        $versions[] = $entry;
        usort($versions, static fn (array $a, array $b): int => Version::compare($b['version'], $a['version']));
        $this->data['versions'] = array_slice($versions, 0, self::MAX_VERSIONS);
        $this->data['channels'][$channel] = $v;
        if ($channel === 'stable' && $this->channel('early') !== null && Version::compare($this->channel('early'), $v) < 0) {
            $this->data['channels']['early'] = $v;
        }
        $this->touch();
    }

    /**
     * Kanal für ein neues Release. Direkt stable, wenn der Programmcode schon geprüft ist:
     * - die Kern-Version hat den Test der Demo-Installation bestanden ($stableCore, Datei STABLE_CORE), oder
     * - es ändert sich nur das Aussehen (gleiche Kern-Version wie die Version in stable).
     * Sonst early: Neue Kern-Versionen laufen erst auf der Demo-Installation.
     *
     * @param array<string, mixed> $release
     */
    public function channelFor(array $release, string $stableCore = ''): string
    {
        $core = (string) ($release['coreVersion'] ?? '');
        if ($core === '') {
            return 'early';
        }
        if ($stableCore !== '' && $core === $stableCore) {
            return 'stable';
        }
        $stable = $this->channel('stable');
        return $stable !== null && ($this->entry($stable)['coreVersion'] ?? null) === $core ? 'stable' : 'early';
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
        $this->data['channels']['stable'] = $version;
        $this->touch();
    }

    /**
     * Zieht eine Version zurück. Zeigt ein Kanal auf sie, zeigt er danach auf die nächstältere
     * nicht zurückgezogene Version derselben Hauptversion.
     */
    public function withdraw(string $version): void
    {
        if ($this->entry($version) === null) {
            throw new \RuntimeException("Version $version ist nicht in der Liste.");
        }
        if (!in_array($version, $this->data['withdrawn'], true)) {
            $this->data['withdrawn'][] = $version;
        }
        foreach (self::CHANNELS as $ch) {
            if ($this->channel($ch) === $version) {
                $this->data['channels'][$ch] = $this->previous($version);
            }
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

    private function previous(string $version): ?string
    {
        foreach ($this->available() as $e) {
            if (Version::compare($e['version'], $version) < 0 && Version::major($e['version']) === Version::major($version)) {
                return $e['version'];
            }
        }
        return null;
    }

    private function touch(): void
    {
        $this->data['generated'] = gmdate('c');
    }
}
