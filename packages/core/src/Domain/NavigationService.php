<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Port\Settings;

/**
 * Einrichtbare Navigation (Issue #277): Ein Admin legt fest, welche Bereiche unten in der Tab-Leiste stehen, in welcher
 * Reihenfolge, und fasst Bereiche zu Untermenüs zusammen (eine Ebene, als Kacheln wie „Intern“). „Verwaltung“ und
 * „Meins“ stehen immer am Ende und sind hier nicht einstellbar, damit sich niemand aussperrt.
 *
 * Nur Darstellung, keine Rechte: Die App zeigt jedem nur die Punkte, die er sehen darf; die Routen prüfen wie bisher.
 * Ohne gespeicherte Einstellung (null) zeigt die App die Standardnavigation wie bisher.
 *
 * Gespeichert wird {items, hidden}: items sind Kennungen von Bereichen (z. B. "termine") oder Untermenüs
 * {label, icon, items}; hidden sind Bereiche, die nicht in der Navigation stehen. Bereiche, die in keiner der beiden
 * Listen vorkommen (z. B. mit einer neuen Version dazugekommen), hängt die App hinten an die Tab-Leiste an.
 */
final class NavigationService
{
    public const SETTING = 'navigation';

    /** Bereiche, die in der Navigation stehen können, in der Reihenfolge der Standardnavigation (Ansichten der App). */
    public const TARGETS = ['termine', 'news', 'galerie', 'kompanien', 'sponsoren', 'mitglieder', 'dokumente', 'brett', 'dienstgrade', 'punktekatalog'];

    /** Symbole für Untermenüs (in der App gezeichnet). */
    public const ICONS = ['ordner', 'termine', 'news', 'galerie', 'kompanien', 'sponsoren', 'mitglieder', 'dokumente', 'brett', 'dienstgrade', 'punktekatalog', 'stern'];

    /** Hauptpunkte neben „Verwaltung“ und „Meins“: Mehr als sieben Tabs passen auf schmalen Handys nicht in die Leiste. */
    public const MAX_MAIN = 5;
    public const MAX_MENU_ITEMS = 12;
    public const LABEL_MAX = 24;

    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * Gespeicherte Navigation; null = Standardnavigation. Unbekannte Bereiche (z. B. nach einem Zurückrollen) fallen weg.
     *
     * @return array{items: list<string|array{label: string, icon: string, items: list<string>}>, hidden: list<string>}|null
     */
    public function get(): ?array
    {
        $raw = $this->settings->get(self::SETTING);
        if (!is_array($raw) || !is_array($raw['items'] ?? null)) {
            return null;
        }
        $known = static fn (mixed $id): bool => is_string($id) && in_array($id, self::TARGETS, true);
        $items = [];
        foreach ($raw['items'] as $entry) {
            if (is_string($entry)) {
                if ($known($entry)) {
                    $items[] = $entry;
                }
            } elseif (is_array($entry) && is_array($entry['items'] ?? null)) {
                $items[] = [
                    'label' => (string) ($entry['label'] ?? ''),
                    'icon' => in_array($entry['icon'] ?? null, self::ICONS, true) ? (string) $entry['icon'] : self::ICONS[0],
                    'items' => array_values(array_filter($entry['items'], $known)),
                ];
            }
        }
        $hidden = is_array($raw['hidden'] ?? null) ? array_values(array_filter($raw['hidden'], $known)) : [];
        return ['items' => $items, 'hidden' => $hidden];
    }

    /** @return array{navigation: array<string, mixed>|null, targets: list<string>, icons: list<string>, max_main: int, max_menu_items: int, label_max: int} */
    public function describe(): array
    {
        return [
            'navigation' => $this->get(),
            'targets' => self::TARGETS,
            'icons' => self::ICONS,
            'max_main' => self::MAX_MAIN,
            'max_menu_items' => self::MAX_MENU_ITEMS,
            'label_max' => self::LABEL_MAX,
        ];
    }

    /**
     * Speichert die Navigation; null stellt die Standardnavigation wieder her.
     *
     * @return array<string, mixed> wie describe()
     */
    public function set(mixed $navigation): array
    {
        if ($navigation === null) {
            $this->settings->delete(self::SETTING);
            return $this->describe();
        }
        if (!is_array($navigation) || !is_array($navigation['items'] ?? null) || !array_is_list($navigation['items'])) {
            throw ApiError::invalid('Die Navigation braucht eine Liste der Hauptpunkte.');
        }
        if (count($navigation['items']) > self::MAX_MAIN) {
            throw ApiError::invalid(sprintf('Unten passen höchstens %d Hauptpunkte neben Verwaltung und Meins.', self::MAX_MAIN));
        }
        $seen = [];
        $target = function (mixed $id) use (&$seen): string {
            if (!is_string($id) || !in_array($id, self::TARGETS, true)) {
                throw ApiError::invalid('Unbekannter Bereich in der Navigation.');
            }
            if (isset($seen[$id])) {
                throw ApiError::invalid('Jeder Bereich darf nur einmal in der Navigation stehen.');
            }
            $seen[$id] = true;
            return $id;
        };
        $items = [];
        foreach ($navigation['items'] as $entry) {
            if (!is_array($entry)) {
                $items[] = $target($entry);
                continue;
            }
            $label = Text::line($entry['label'] ?? '');
            if ($label === '') {
                throw ApiError::invalid('Jedes Untermenü braucht einen Namen.');
            }
            if (mb_strlen($label) > self::LABEL_MAX) {
                throw ApiError::invalid(sprintf('Der Name eines Untermenüs darf höchstens %d Zeichen lang sein.', self::LABEL_MAX));
            }
            $icon = $entry['icon'] ?? self::ICONS[0];
            if (!is_string($icon) || !in_array($icon, self::ICONS, true)) {
                throw ApiError::invalid('Unbekanntes Symbol für ein Untermenü.');
            }
            $sub = $entry['items'] ?? null;
            if (!is_array($sub) || !array_is_list($sub) || $sub === []) {
                throw ApiError::invalid(sprintf('Das Untermenü „%s“ braucht mindestens einen Bereich.', $label));
            }
            if (count($sub) > self::MAX_MENU_ITEMS) {
                throw ApiError::invalid(sprintf('Ein Untermenü hat höchstens %d Bereiche.', self::MAX_MENU_ITEMS));
            }
            // Eine Ebene: In einem Untermenü stehen nur Bereiche.
            $items[] = ['label' => $label, 'icon' => $icon, 'items' => array_map($target, $sub)];
        }
        $hidden = $navigation['hidden'] ?? [];
        if (!is_array($hidden) || !array_is_list($hidden)) {
            throw ApiError::invalid('Die ausgeblendeten Bereiche müssen eine Liste sein.');
        }
        $this->settings->set(self::SETTING, ['items' => $items, 'hidden' => array_map($target, $hidden)]);
        return $this->describe();
    }
}
