<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Http\ApiError;

/**
 * Die Bereiche (Funktionen) der App und ob sie eingeschaltet sind – die einzige Stelle, die das sagt.
 *
 * Welche Bereiche eine Kompanie nutzt, entscheidet ihr Admin in der App (Ersteinrichtung bzw.
 * Einstellungen → Bereiche), nicht mehr kompanie.json. Ein ausgeschalteter Bereich verschwindet aus
 * der App und ist auf dem Server gesperrt; seine Daten bleiben erhalten.
 *
 * Neue Bereiche tragen sich in DEFINITIONS ein (Bezeichnung, Beschreibung, Abhängigkeiten).
 */
final class Features
{
    public const SETTING = 'features';

    /**
     * Alle Bereiche in der Reihenfolge der Anzeige.
     * - label: Standardbezeichnung (Bereiche mit eigener Bezeichnung in kompanie.json nehmen diese)
     * - description: ein Satz, wofür der Bereich da ist
     * - requires: Bereiche, ohne die dieser nicht eingeschaltet werden kann
     * - setup: Abschnitt in den Einstellungen, falls der Bereich eingerichtet werden muss
     *
     * @var array<string, array{label: string, description: string, requires: string[], setup: string|null}>
     */
    public const DEFINITIONS = [
        'events' => ['label' => 'Termine', 'description' => 'Öffentliche Termine, die auch Gäste ohne Anmeldung sehen.', 'requires' => [], 'setup' => null],
        'calendar' => ['label' => 'Kalender', 'description' => 'Alle Termine aus Google Kalender für angemeldete Mitglieder.', 'requires' => [], 'setup' => 'kalender'],
        'news' => ['label' => 'News', 'description' => 'Neuigkeiten der Kompanie, z. B. aus den Beiträgen der Webseite.', 'requires' => [], 'setup' => 'news'],
        'gallery' => ['label' => 'Galerie', 'description' => 'Fotoalben aus Google Drive, mit internen Bildern nur für Mitglieder.', 'requires' => [], 'setup' => 'galerie'],
        'points' => ['label' => 'Punkte', 'description' => 'Punkte für Mitglieder, mit Katalog und Verlauf.', 'requires' => [], 'setup' => null],
        'penalties' => ['label' => 'Strafen', 'description' => 'Strafen der Mitglieder, offen oder bezahlt.', 'requires' => [], 'setup' => null],
    ];

    /** @var array<string, bool>|null */
    private ?array $state = null;

    public function __construct(private readonly App $app)
    {
    }

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public function enabled(string $feature): bool
    {
        return $this->all()[$feature] ?? false;
    }

    /**
     * Ein- bzw. ausgeschaltet je Bereich. Ohne gespeicherte Einstellung ist alles aus:
     * Der Admin wählt bei der Ersteinrichtung, was die Kompanie nutzt.
     *
     * @return array<string, bool>
     */
    public function all(): array
    {
        if ($this->state === null) {
            $saved = $this->app->settings->get(self::SETTING);
            $saved = is_array($saved) ? $saved : [];
            $this->state = [];
            foreach (self::keys() as $key) {
                $this->state[$key] = (bool) ($saved[$key] ?? false);
            }
        }
        return $this->state;
    }

    public function isStored(): bool
    {
        return is_array($this->app->settings->get(self::SETTING));
    }

    /**
     * Liste für die Ersteinrichtung und die Einstellungen der Admins.
     *
     * @return array<int, array{key: string, label: string, description: string, enabled: bool, requires: string[], configured: bool|null, setup: string|null}>
     */
    public function describe(): array
    {
        $out = [];
        foreach (self::DEFINITIONS as $key => $def) {
            $out[] = [
                'key' => $key,
                'label' => $this->app->config->sectionLabel($key, $def['label']),
                'description' => $def['description'],
                'enabled' => $this->enabled($key),
                'requires' => $def['requires'],
                // null: muss nicht eingerichtet werden
                'configured' => $def['setup'] === null ? null : $this->configured($key),
                'setup' => $def['setup'],
            ];
        }
        return $out;
    }

    /** Ist ein Bereich eingerichtet (z. B. Kalender mit Adresse)? Unabhängig davon, ob er an ist. */
    public function configured(string $feature): bool
    {
        return match ($feature) {
            'calendar' => $this->app->calendar()->isConfigured(),
            'gallery' => $this->app->gallery()->isConfigured(),
            'news' => $this->app->news->available(),
            default => true,
        };
    }

    /**
     * Übernimmt die mitgeschickten Schalter; nicht genannte Bereiche bleiben, wie sie sind.
     * Unbekannte Bereiche, Werte, die kein Wahrheitswert sind, und fehlende Abhängigkeiten → 400.
     *
     * @param array<string, mixed> $in
     * @return array<string, bool>
     */
    public function update(array $in): array
    {
        $next = $this->all();
        foreach ($in as $key => $value) {
            if (!array_key_exists((string) $key, self::DEFINITIONS)) {
                throw ApiError::invalid('Unbekannter Bereich: ' . $key . '.');
            }
            if (!is_bool($value)) {
                throw ApiError::invalid('Ungültiger Wert für ' . $key . ' (an oder aus erwartet).');
            }
            $next[(string) $key] = $value;
        }
        foreach ($next as $key => $on) {
            foreach (self::DEFINITIONS[$key]['requires'] as $required) {
                if ($on && !$next[$required]) {
                    throw ApiError::invalid(sprintf(
                        '„%s“ braucht „%s“. Bitte beide einschalten.',
                        $this->app->config->sectionLabel($key, self::DEFINITIONS[$key]['label']),
                        $this->app->config->sectionLabel($required, self::DEFINITIONS[$required]['label']),
                    ));
                }
            }
        }
        $this->save($next);
        return $next;
    }

    /**
     * Einmalig beim ersten Start dieser Version: Bestehende Installationen übernehmen die Bereiche
     * aus der alten kompanie.json (Standard dort: an), damit nichts plötzlich verschwindet.
     * Neue Installationen starten ohne Bereiche.
     */
    public function initialize(bool $existingInstallation): void
    {
        if ($this->isStored()) {
            return;
        }
        $state = [];
        foreach (self::keys() as $key) {
            $state[$key] = $existingInstallation && $this->app->config->legacyFeature($key);
        }
        $this->save($state);
    }

    /** @param array<string, bool> $state */
    private function save(array $state): void
    {
        $this->app->settings->set(self::SETTING, $state);
        $this->state = $state;
        $this->app->scheduleTasks();
    }
}
