<?php

declare(strict_types=1);

namespace KompanieApp\Core\Config;

/**
 * Die Kompanie-Konfiguration (kompanie.json), wie sie beim Build in das Paket
 * gelegt wird. Fehlende Angaben bekommen die Standardwerte aus dem Schema.
 */
final class KompanieConfig
{
    public const FEATURES = ['events', 'calendar', 'news', 'gallery', 'points', 'penalties'];

    private const ROLE_LABELS = ['guest' => 'Gast', 'member' => 'Mitglied', 'manager' => 'Verwalter', 'admin' => 'Admin'];
    private const SECTION_LABELS = ['events' => 'Termine', 'news' => 'News', 'gallery' => 'Galerie', 'points' => 'Punkte', 'penalties' => 'Strafen'];

    /** @param array<string, mixed> $data */
    public function __construct(private readonly array $data)
    {
    }

    public static function fromFile(string $file): self
    {
        if (!is_readable($file)) {
            return new self([]);
        }
        $data = json_decode((string) file_get_contents($file), true);
        return new self(is_array($data) ? $data : []);
    }

    public function name(): string
    {
        return $this->string('name', 'Kompanie-App');
    }

    public function shortName(): string
    {
        return $this->string('shortName', $this->name());
    }

    public function description(): string
    {
        return $this->string('description', 'Die App der ' . $this->name());
    }

    public function featureEnabled(string $feature): bool
    {
        $features = is_array($this->data['features'] ?? null) ? $this->data['features'] : [];
        return (bool) ($features[$feature] ?? true);
    }

    /** @return array<string, bool> */
    public function features(): array
    {
        $out = [];
        foreach (self::FEATURES as $f) {
            $out[$f] = $this->featureEnabled($f);
        }
        return $out;
    }

    public function roleLabel(string $level): string
    {
        $labels = $this->data['labels']['roles'] ?? [];
        return is_array($labels) && is_string($labels[$level] ?? null) && $labels[$level] !== ''
            ? $labels[$level]
            : (self::ROLE_LABELS[$level] ?? $level);
    }

    public function sectionLabel(string $section): string
    {
        $labels = $this->data['labels']['sections'] ?? [];
        return is_array($labels) && is_string($labels[$section] ?? null) && $labels[$section] !== ''
            ? $labels[$section]
            : (self::SECTION_LABELS[$section] ?? $section);
    }

    public function primaryColor(): string
    {
        $c = $this->data['colors']['primary'] ?? '';
        return is_string($c) && preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : '#2f5d8a';
    }

    public function accentColor(): string
    {
        $c = $this->data['colors']['accent'] ?? '';
        return is_string($c) && preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : $this->primaryColor();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    private function string(string $key, string $default): string
    {
        $v = $this->data[$key] ?? null;
        return is_string($v) && trim($v) !== '' ? trim($v) : $default;
    }
}
