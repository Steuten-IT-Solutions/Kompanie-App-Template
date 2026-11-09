<?php

declare(strict_types=1);

namespace KompanieApp\Core\Config;

/**
 * Die Kompanie-Konfiguration (kompanie.json), wie sie beim Build in das Paket
 * gelegt wird. Fehlende Angaben bekommen die Standardwerte aus dem Schema.
 */
final class KompanieConfig
{
    private const ROLE_LABELS = ['guest' => 'Gast', 'candidate' => 'Anwärter', 'member' => 'Mitglied', 'organizer' => 'Verwalter', 'manager' => 'Leitung', 'admin' => 'Admin'];
    private const SECTION_LABELS = ['events' => 'Termine', 'news' => 'News', 'gallery' => 'Galerie', 'points' => 'Punkte', 'penalties' => 'Strafen', 'ranks' => 'Dienstgrade', 'titles' => 'Titel', 'attendance' => 'Anwesenheit', 'documents' => 'Dokumente', 'board' => 'Schwarzes Brett', 'polls' => 'Umfragen', 'birthdays' => 'Geburtstage', 'inventory' => 'Inventar', 'directory' => 'Mitglieder', 'offices' => 'Ämter', 'lists' => 'Listen', 'mail' => 'E-Mails', 'sponsors' => 'Sponsoren', 'companies' => 'Kompanien', 'pages' => 'Seiten'];

    /** @var (\Closure(): array<string, string>)|null eigene Bereichsnamen der Installation (Einstellungen → Navigation) */
    private ?\Closure $sectionOverrides = null;

    /** @param array<string, mixed> $data */
    public function __construct(private readonly array $data)
    {
    }

    /**
     * Eigene Bereichsnamen der Installation (Issue #362): gehen vor kompanie.json und den Standardnamen. Die App setzt das
     * beim Start (App::__construct); gelesen wird erst bei Bedarf, damit die Einstellungen nicht vorab geladen werden.
     *
     * @param \Closure(): array<string, string> $overrides Abschnitt (z. B. "gallery") → Name
     */
    public function overrideSections(\Closure $overrides): void
    {
        $this->sectionOverrides = $overrides;
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

    /**
     * Früherer Schalter `features` in kompanie.json (Standard: an). Gilt nicht mehr – Bereiche schaltet
     * der Admin in der App (Domain\Features). Wird nur einmalig gelesen, wenn eine bestehende
     * Installation auf diese Version wechselt, damit kein ausgeschalteter Bereich plötzlich erscheint.
     */
    public function legacyFeature(string $feature): bool
    {
        $features = is_array($this->data['features'] ?? null) ? $this->data['features'] : [];
        return (bool) ($features[$feature] ?? true);
    }

    public function roleLabel(string $level): string
    {
        $labels = $this->data['labels']['roles'] ?? [];
        return is_array($labels) && is_string($labels[$level] ?? null) && $labels[$level] !== ''
            ? $labels[$level]
            : (self::ROLE_LABELS[$level] ?? $level);
    }

    public function sectionLabel(string $section, ?string $default = null): string
    {
        $own = $this->sectionOverrides !== null ? ($this->sectionOverrides)() : [];
        if (($own[$section] ?? '') !== '') {
            return $own[$section];
        }
        return $this->configuredSectionLabel($section, $default);
    }

    /**
     * Name eines Bereichs aus kompanie.json bzw. der Standard, ohne eigene Namen der Installation. „catalog“ (Punktekatalog)
     * und „intern“ (Untermenü der Standardnavigation) stehen nicht in kompanie.json: der Katalog heißt nach den Punkten.
     */
    public function configuredSectionLabel(string $section, ?string $default = null): string
    {
        if ($section === 'catalog') {
            return $this->configuredSectionLabel('points') . 'katalog';
        }
        if ($section === 'intern') {
            return 'Intern';
        }
        $labels = $this->data['labels']['sections'] ?? [];
        return is_array($labels) && is_string($labels[$section] ?? null) && $labels[$section] !== ''
            ? $labels[$section]
            : (self::SECTION_LABELS[$section] ?? $default ?? $section);
    }

    /** Ein einzelner Dienstgrad (Einzahl zum Bereich ranks), z. B. „Dienstgrad“ oder „Rang“. */
    public function rankLabel(): string
    {
        $label = $this->data['labels']['rank'] ?? null;
        return is_string($label) && trim($label) !== '' ? $label : 'Dienstgrad';
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
