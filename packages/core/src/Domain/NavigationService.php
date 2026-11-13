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
 *
 * Dazu (Issue #362), je in einer eigenen Einstellung, damit sie auch in der Standardnavigation gelten:
 * - eigene Namen und Symbole je Bereich, eigene Seite und „Intern“ (NAMES): Der Name gilt überall, wo die App den Bereich
 *   nennt (sectionOverrides → KompanieConfig::sectionLabel), bei eigenen Seiten nur im Tab (ihr Titel bleibt);
 * - die Startseite beim Öffnen der App, getrennt für Gäste und Angemeldete (START): ein Punkt der Navigation;
 * - ob die Tab-Leiste unter den Symbolen Namen zeigt (LABELS).
 *
 * Und die Reihenfolge der Kacheln (ebenfalls nur Darstellung):
 * - in „Intern“ der Standardnavigation (INTERN_ORDER), ohne dafür eine eigene Navigation anzulegen;
 * - auf der Startseite der Verwaltung und unter „Einrichtungen“ (ADMIN_ORDER), je Gruppe; eine Kachel wechselt die Gruppe
 *   dabei nicht. Kacheln, die in der Liste fehlen (z. B. neu mit einem Update), stellt die App an ihren Standardplatz.
 */
final class NavigationService
{
    public const SETTING = 'navigation';
    public const NAMES = 'navigation_names';
    public const START = 'navigation_start';
    public const LABELS = 'navigation_labels';
    /** Ein String, kein false: WordPress speichert false als leeren String (get_option liefert dann ''). */
    private const LABELS_OFF = 'off';
    public const INTERN_ORDER = 'navigation_intern_order';
    public const ADMIN_ORDER = 'admin_order';

    /** Kacheln der Startseite der Verwaltung (wie adminTabs() in der App), „einrichtungen“ ist der Sammelknopf. */
    public const ADMIN_START = [
        'mitglieder', 'ehemalige', 'anfragen', 'meldungen', 'aenderungen', 'punkte', 'strafe', 'befoerderungen', 'anwesenheit', 'brett', 'umfragen',
        'inventar', 'listen', 'mail', 'einrichtungen', 'einstellungen',
    ];
    /** Kacheln unter „Einrichtungen“ in der Verwaltung. */
    public const ADMIN_SETUP = ['katalog', 'dienstgrade', 'titel', 'aemter', 'kompanien', 'sponsoren', 'seiten', 'webseite'];

    /** Abschnitt (KompanieConfig::sectionLabel) je Bereich der Navigation; „mitglieder“ wählt sectionOverrides. */
    private const SECTIONS = [
        'termine' => 'events', 'news' => 'news', 'galerie' => 'gallery', 'kompanien' => 'companies', 'sponsoren' => 'sponsors',
        'dokumente' => 'documents', 'brett' => 'board', 'dienstgrade' => 'ranks', 'punktekatalog' => 'catalog', 'intern' => 'intern',
    ];

    /** Untermenü „Intern“ der Standardnavigation: umbenennbar wie ein Bereich. */
    public const INTERN = 'intern';
    /** „Meins“ kann für Angemeldete Startseite sein. */
    public const MINE = 'meins';

    /** Bereiche, die in der Navigation stehen können, in der Reihenfolge der Standardnavigation (Ansichten der App). */
    public const TARGETS = ['termine', 'news', 'galerie', 'kompanien', 'sponsoren', 'mitglieder', 'dokumente', 'brett', 'dienstgrade', 'punktekatalog'];

    /** Eigene Seiten (Issue #287) stehen je Seite als „seiten/<id>“ in der Navigation (Adresse #/seiten/<id>). */
    public const PAGE_PREFIX = 'seiten/';

    /** Symbole für Untermenüs (in der App gezeichnet). */
    /** Symbole für Untermenüs und Bereiche (in der App gezeichnet); ab „info“ neutrale Symbole ohne festen Bereich (Issue #362). */
    public const ICONS = [
        'ordner', 'termine', 'news', 'galerie', 'kompanien', 'sponsoren', 'mitglieder', 'dokumente', 'brett', 'dienstgrade', 'punktekatalog', 'seiten', 'stern',
        'info', 'karte', 'musik', 'pokal', 'glocke', 'fahne', 'kamera', 'haus', 'buch', 'uhr',
    ];

    /** Die Zahl der Hauptpunkte ist frei (Issue #362): Die App verkleinert Schrift und Symbole, je mehr unten stehen. */
    public const MAX_MENU_ITEMS = 12;
    public const LABEL_MAX = 24;

    public function __construct(private readonly Settings $settings)
    {
    }

    /** Bereich der Navigation: einer der festen oder eine eigene Seite (ob es sie gibt, prüft die App beim Anzeigen). */
    public static function isTarget(mixed $id): bool
    {
        return is_string($id) && (in_array($id, self::TARGETS, true) || preg_match('#^seiten/[1-9][0-9]{0,9}$#D', $id) === 1);
    }

    /** Eine gelöschte Seite aus der gespeicherten Navigation nehmen (leere Untermenüs fallen mit weg), samt Name und Startseite. */
    public function removeTarget(string $id): void
    {
        $names = $this->names();
        if (isset($names[$id])) {
            unset($names[$id]);
            $this->saveNames($names);
        }
        $order = $this->internOrder();
        if (in_array($id, $order, true)) {
            $this->saveInternOrder(array_values(array_filter($order, static fn (string $t): bool => $t !== $id)));
        }
        $start = $this->start();
        if (in_array($id, $start, true)) {
            $this->saveStart(array_filter($start, static fn (string $t): bool => $t !== $id));
        }
        $nav = $this->get();
        if ($nav === null) {
            return;
        }
        $items = [];
        foreach ($nav['items'] as $entry) {
            if (is_string($entry)) {
                if ($entry !== $id) {
                    $items[] = $entry;
                }
                continue;
            }
            $entry['items'] = array_values(array_filter($entry['items'], static fn (string $t): bool => $t !== $id));
            if ($entry['items'] !== []) {
                $items[] = $entry;
            }
        }
        $hidden = array_values(array_filter($nav['hidden'], static fn (string $t): bool => $t !== $id));
        if ($items !== $nav['items'] || $hidden !== $nav['hidden']) {
            $this->settings->set(self::SETTING, ['items' => $items, 'hidden' => $hidden]);
        }
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
        $known = static fn (mixed $id): bool => self::isTarget($id);
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

    /**
     * @return array{navigation: array<string, mixed>|null, names: array<string, array{label?: string, icon?: string}>|\stdClass, start: array{guest?: string, member?: string}|\stdClass, show_labels: bool, intern_order: list<string>, admin_order: array{start?: list<string>, setup?: list<string>}|\stdClass, targets: list<string>, icons: list<string>, max_menu_items: int, label_max: int}
     */
    public function describe(): array
    {
        return [
            'navigation' => $this->get(),
            // Leer als Objekt ({}), wie in api/openapi.yaml
            'names' => $this->names() === [] ? new \stdClass() : $this->names(),
            'start' => $this->start() === [] ? new \stdClass() : $this->start(),
            'show_labels' => $this->showLabels(),
            'intern_order' => $this->internOrder(),
            'admin_order' => $this->adminOrder() === [] ? new \stdClass() : $this->adminOrder(),
            'targets' => self::TARGETS,
            'icons' => self::ICONS,
            'max_menu_items' => self::MAX_MENU_ITEMS,
            'label_max' => self::LABEL_MAX,
        ];
    }

    /**
     * Eigene Namen und Symbole (Issue #362), je Bereich, eigene Seite oder „intern“; leer = keine.
     *
     * @return array<string, array{label?: string, icon?: string}>
     */
    public function names(): array
    {
        $raw = $this->settings->get(self::NAMES);
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $key => $entry) {
            if (!is_string($key) || !self::isNamed($key) || !is_array($entry)) {
                continue;
            }
            $one = [];
            if (is_string($entry['label'] ?? null) && $entry['label'] !== '') {
                $one['label'] = $entry['label'];
            }
            if (in_array($entry['icon'] ?? null, self::ICONS, true)) {
                $one['icon'] = (string) $entry['icon'];
            }
            if ($one !== []) {
                $out[$key] = $one;
            }
        }
        return $out;
    }

    /**
     * Eigene Namen als Abschnitte für KompanieConfig::sectionLabel (z. B. "gallery" => "Fotos"). „mitglieder“ heißt wie
     * der Abschnitt, den der Tab gerade zeigt: Mitgliederliste, oder nur die Ämter, wenn es keine Liste gibt (Issue #112).
     *
     * @return array<string, string>
     */
    public function sectionOverrides(bool $directory, bool $offices): array
    {
        $out = [];
        foreach ($this->names() as $key => $entry) {
            if (!isset($entry['label'])) {
                continue;
            }
            if ($key === 'mitglieder') {
                $out[$directory || !$offices ? 'directory' : 'offices'] = $entry['label'];
            } elseif (isset(self::SECTIONS[$key])) {
                $out[self::SECTIONS[$key]] = $entry['label'];
            }
        }
        return $out;
    }

    /** @return array{guest?: string, member?: string} Startseite je Gruppe; fehlt = erster Punkt der Leiste */
    public function start(): array
    {
        $raw = $this->settings->get(self::START);
        $out = [];
        foreach (['guest', 'member'] as $who) {
            $t = is_array($raw) ? ($raw[$who] ?? null) : null;
            if (is_string($t) && self::isStart($t, $who === 'member')) {
                $out[$who] = $t;
            }
        }
        return $out;
    }

    /** Zeigt die Tab-Leiste Namen unter den Symbolen? Standard: ja. */
    public function showLabels(): bool
    {
        return $this->settings->get(self::LABELS) !== self::LABELS_OFF;
    }

    /**
     * Reihenfolge der Kacheln in „Intern“ der Standardnavigation; leer = Standard. Unbekanntes fällt weg.
     *
     * @return list<string>
     */
    public function internOrder(): array
    {
        $raw = $this->settings->get(self::INTERN_ORDER);
        return is_array($raw) ? array_values(array_unique(array_filter($raw, static fn (mixed $t): bool => self::isTarget($t)))) : [];
    }

    /**
     * Reihenfolge der Kacheln der Verwaltung je Gruppe (start: Startseite, setup: „Einrichtungen“); leer = Standard.
     *
     * @return array{start?: list<string>, setup?: list<string>}
     */
    public function adminOrder(): array
    {
        $raw = $this->settings->get(self::ADMIN_ORDER);
        $out = [];
        foreach (['start' => self::ADMIN_START, 'setup' => self::ADMIN_SETUP] as $group => $known) {
            $list = is_array($raw) && is_array($raw[$group] ?? null) ? $raw[$group] : [];
            $list = array_values(array_unique(array_filter($list, static fn (mixed $t): bool => is_string($t) && in_array($t, $known, true))));
            if ($list !== []) {
                $out[$group] = $list;
            }
        }
        return $out;
    }

    /** Kann einen eigenen Namen bekommen: ein Bereich, eine eigene Seite oder „Intern“ der Standardnavigation. */
    private static function isNamed(string $key): bool
    {
        return self::isTarget($key) || $key === self::INTERN;
    }

    /** Möglicher Wert einer Startseite (ob er in der Navigation steht, prüft set bzw. beim Öffnen die App). */
    private static function isStart(string $t, bool $member): bool
    {
        return self::isTarget($t) || $t === self::INTERN || preg_match('#^menue/[1-9][0-9]?$#D', $t) === 1 || ($member && $t === self::MINE);
    }

    /**
     * Speichert Einstellungen → Navigation (Issue #362): die Navigation (null = Standard) und, falls angegeben, eigene
     * Namen und Symbole, Startseiten und die Namen in der Leiste. Startseiten prüft das gegen die neue Navigation.
     *
     * @param array<mixed> $body navigation (Pflicht), names?, start?, show_labels?, intern_order?, admin_order?
     * @return array<string, mixed> wie describe()
     */
    public function save(array $body): array
    {
        if (!array_key_exists('navigation', $body)) {
            throw ApiError::invalid('Bitte die Navigation angeben (null für die Standardnavigation).');
        }
        $names = array_key_exists('names', $body) ? $this->validNames($body['names']) : null;
        $internOrder = array_key_exists('intern_order', $body) ? self::validInternOrder($body['intern_order']) : null;
        $adminOrder = array_key_exists('admin_order', $body) ? self::validAdminOrder($body['admin_order']) : null;
        $this->set($body['navigation']);
        if ($internOrder !== null) {
            $this->saveInternOrder($internOrder);
        }
        if ($adminOrder !== null) {
            $adminOrder === [] ? $this->settings->delete(self::ADMIN_ORDER) : $this->settings->set(self::ADMIN_ORDER, $adminOrder);
        }
        if ($names !== null) {
            $this->saveNames($names);
        }
        if (array_key_exists('start', $body)) {
            $this->saveStart($this->validStart($body['start']));
        }
        if (array_key_exists('show_labels', $body)) {
            if (!is_bool($body['show_labels'])) {
                throw ApiError::invalid('show_labels muss true oder false sein.');
            }
            $body['show_labels'] ? $this->settings->delete(self::LABELS) : $this->settings->set(self::LABELS, self::LABELS_OFF);
        }
        return $this->describe();
    }

    /** @return list<string> */
    private static function validInternOrder(mixed $order): array
    {
        if ($order === null) {
            return [];
        }
        if (!is_array($order) || !array_is_list($order)) {
            throw ApiError::invalid('Die Reihenfolge von „Intern“ muss eine Liste sein.');
        }
        foreach ($order as $t) {
            if (!self::isTarget($t)) {
                throw ApiError::invalid('Unbekannter Bereich in der Reihenfolge von „Intern“.');
            }
        }
        if (count(array_unique($order)) !== count($order)) {
            throw ApiError::invalid('Jeder Bereich darf in der Reihenfolge von „Intern“ nur einmal stehen.');
        }
        return $order;
    }

    /** @return array{start?: list<string>, setup?: list<string>} */
    private static function validAdminOrder(mixed $order): array
    {
        if ($order === null) {
            return [];
        }
        if (!is_array($order) || ($order !== [] && array_is_list($order))) {
            throw ApiError::invalid('Die Reihenfolge der Verwaltung muss ein Objekt sein (start, setup).');
        }
        $out = [];
        foreach ($order as $group => $list) {
            $known = match ($group) {
                'start' => self::ADMIN_START,
                'setup' => self::ADMIN_SETUP,
                default => throw ApiError::invalid('Die Reihenfolge der Verwaltung kennt nur start und setup.'),
            };
            if ($list === null || $list === []) {
                continue;
            }
            if (!is_array($list) || !array_is_list($list)) {
                throw ApiError::invalid('Die Reihenfolge einer Gruppe der Verwaltung muss eine Liste sein.');
            }
            foreach ($list as $id) {
                if (!is_string($id) || !in_array($id, $known, true)) {
                    throw ApiError::invalid('Unbekannte Kachel in der Reihenfolge der Verwaltung.');
                }
            }
            if (count(array_unique($list)) !== count($list)) {
                throw ApiError::invalid('Jede Kachel darf in der Reihenfolge der Verwaltung nur einmal stehen.');
            }
            $out[$group] = $list;
        }
        return $out;
    }

    /** @param list<string> $order */
    private function saveInternOrder(array $order): void
    {
        $order === [] ? $this->settings->delete(self::INTERN_ORDER) : $this->settings->set(self::INTERN_ORDER, $order);
    }

    /** @return array<string, array{label?: string, icon?: string}> */
    private function validNames(mixed $names): array
    {
        if ($names === null) {
            return [];
        }
        if (!is_array($names) || ($names !== [] && array_is_list($names))) {
            throw ApiError::invalid('Die Namen der Bereiche müssen ein Objekt sein (Bereich → Name und Symbol).');
        }
        $out = [];
        foreach ($names as $key => $entry) {
            if (!is_string($key) || !self::isNamed($key)) {
                throw ApiError::invalid('Unbekannter Bereich bei den Namen.');
            }
            if (!is_array($entry)) {
                throw ApiError::invalid('Name und Symbol eines Bereichs müssen ein Objekt sein.');
            }
            $one = [];
            $label = Text::line(is_string($entry['label'] ?? null) ? $entry['label'] : '');
            if (mb_strlen($label) > self::LABEL_MAX) {
                throw ApiError::invalid(sprintf('Ein Name darf höchstens %d Zeichen lang sein („%s“).', self::LABEL_MAX, $label));
            }
            if ($label !== '') {
                $one['label'] = $label;
            }
            $icon = $entry['icon'] ?? null;
            if ($icon !== null && $icon !== '') {
                if (!is_string($icon) || !in_array($icon, self::ICONS, true)) {
                    throw ApiError::invalid('Unbekanntes Symbol.');
                }
                $one['icon'] = $icon;
            }
            if ($one !== []) {
                $out[$key] = $one;
            }
        }
        return $out;
    }

    /**
     * Startseiten müssen in der (eben gespeicherten) Navigation stehen: nicht ausgeblendet, ein vorhandenes Untermenü,
     * „Intern“ nur in der Standardnavigation, „Meins“ nur für Angemeldete.
     *
     * @return array{guest?: string, member?: string}
     */
    private function validStart(mixed $start): array
    {
        if ($start === null) {
            return [];
        }
        if (!is_array($start)) {
            throw ApiError::invalid('Die Startseite muss ein Objekt sein (guest, member).');
        }
        $nav = $this->get();
        $menus = $nav === null ? 0 : count(array_filter($nav['items'], 'is_array'));
        $out = [];
        foreach (['guest', 'member'] as $who) {
            $t = $start[$who] ?? null;
            if ($t === null || $t === '') {
                continue;
            }
            $ok = is_string($t) && self::isStart($t, $who === 'member');
            if ($ok && $t === self::INTERN) {
                $ok = $nav === null;
            } elseif ($ok && str_starts_with($t, 'menue/')) {
                $ok = (int) substr($t, 6) <= $menus;
            } elseif ($ok && $nav !== null) {
                $ok = !in_array($t, $nav['hidden'], true);
            }
            if (!$ok) {
                throw ApiError::invalid('Als Startseite geht nur ein Punkt, der in der Navigation steht.');
            }
            $out[$who] = $t;
        }
        return $out;
    }

    /** @param array<string, array{label?: string, icon?: string}> $names */
    private function saveNames(array $names): void
    {
        $names === [] ? $this->settings->delete(self::NAMES) : $this->settings->set(self::NAMES, $names);
    }

    /** @param array{guest?: string, member?: string} $start */
    private function saveStart(array $start): void
    {
        $start === [] ? $this->settings->delete(self::START) : $this->settings->set(self::START, $start);
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
        $seen = [];
        $target = function (mixed $id) use (&$seen): string {
            if (!self::isTarget($id)) {
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
