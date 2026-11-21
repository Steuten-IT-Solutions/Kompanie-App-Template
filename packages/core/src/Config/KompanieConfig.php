<?php

declare(strict_types=1);

namespace KompanieApp\Core\Config;

use KompanieApp\Core\I18n\I18n;

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
        $label = is_array($labels) && is_string($labels[$level] ?? null) && $labels[$level] !== ''
            ? $labels[$level]
            : (self::ROLE_LABELS[$level] ?? $level);
        return $this->localized('roles', $level, $label);
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
            return I18n::t('{points}katalog', ['points' => $this->configuredSectionLabel('points')]);
        }
        if ($section === 'intern') {
            return $this->localized('sections', 'intern', 'Intern');
        }
        $labels = $this->data['labels']['sections'] ?? [];
        $label = is_array($labels) && is_string($labels[$section] ?? null) && $labels[$section] !== ''
            ? $labels[$section]
            : (self::SECTION_LABELS[$section] ?? $default ?? $section);
        return $this->localized('sections', $section, $label);
    }

    /** Ein einzelner Dienstgrad (Einzahl zum Bereich ranks), z. B. „Dienstgrad“ oder „Rang“. */
    public function rankLabel(): string
    {
        $label = $this->data['labels']['rank'] ?? null;
        return $this->localized('rank', 'rank', is_string($label) && trim($label) !== '' ? $label : 'Dienstgrad');
    }

    /**
     * Name in der Sprache der Anfrage (Plan „Mehrsprachigkeit“): Eintrag unter translations.<sprache> in kompanie.json,
     * sonst die übersetzte Vorgabe, wenn der Name noch die Vorgabe ist; eigene Namen ohne Eintrag bleiben, wie sie sind.
     * Wie packages/frontend/src/brandLocale.ts.
     *
     * @param 'roles'|'sections'|'rank' $kind
     */
    public function localized(string $kind, string $key, string $label): string
    {
        $locale = I18n::locale();
        if ($locale === I18n::SOURCE_LOCALE) {
            return $label;
        }
        $labels = $this->data['translations'][$locale]['labels'] ?? null;
        $own = is_array($labels) ? ($kind === 'rank' ? ($labels['rank'] ?? null) : ($labels[$kind][$key] ?? null)) : null;
        if (is_string($own) && trim($own) !== '') {
            return $own;
        }
        $preset = self::preset($kind, $key);
        return $preset !== null && $preset[0] === $label ? $preset[1] : $label;
    }

    /**
     * Vorgabe eines Namens: deutsch und in der Sprache der Anfrage (die Texte stehen einzeln, damit
     * scripts/check-i18n.php sie findet).
     *
     * @return array{0: string, 1: string}|null
     */
    private static function preset(string $kind, string $key): ?array
    {
        if ($kind === 'rank') {
            return ['Dienstgrad', I18n::t('Dienstgrad')];
        }
        if ($kind === 'roles') {
            return match ($key) {
                'guest' => ['Gast', I18n::t('Gast')],
                'candidate' => ['Anwärter', I18n::t('Anwärter')],
                'member' => ['Mitglied', I18n::t('Mitglied')],
                'organizer' => ['Verwalter', I18n::t('Verwalter')],
                'manager' => ['Leitung', I18n::t('Leitung')],
                'admin' => ['Admin', I18n::t('Admin')],
                default => null,
            };
        }
        return match ($key) {
            'events' => ['Termine', I18n::t('Termine')],
            'news' => ['News', I18n::t('News')],
            'gallery' => ['Galerie', I18n::t('Galerie')],
            'points' => ['Punkte', I18n::t('Punkte')],
            'penalties' => ['Strafen', I18n::t('Strafen')],
            'ranks' => ['Dienstgrade', I18n::t('Dienstgrade')],
            'titles' => ['Titel', I18n::tc('Bereich', 'Titel')],
            'attendance' => ['Anwesenheit', I18n::t('Anwesenheit')],
            'documents' => ['Dokumente', I18n::t('Dokumente')],
            'board' => ['Schwarzes Brett', I18n::t('Schwarzes Brett')],
            'polls' => ['Umfragen', I18n::t('Umfragen')],
            'birthdays' => ['Geburtstage', I18n::t('Geburtstage')],
            'inventory' => ['Inventar', I18n::t('Inventar')],
            'directory' => ['Mitglieder', I18n::t('Mitglieder')],
            'offices' => ['Ämter', I18n::t('Ämter')],
            'lists' => ['Listen', I18n::t('Listen')],
            'mail' => ['E-Mails', I18n::t('E-Mails')],
            'sponsors' => ['Sponsoren', I18n::t('Sponsoren')],
            'companies' => ['Kompanien', I18n::t('Kompanien')],
            'pages' => ['Seiten', I18n::t('Seiten')],
            'intern' => ['Intern', I18n::t('Intern')],
            default => null,
        };
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
