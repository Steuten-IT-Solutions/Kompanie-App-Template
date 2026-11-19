<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Port\Settings;
use KompanieApp\Core\Storage\UserSettings;

/**
 * Persönliche Anordnung der Kacheln einer Seite (Verwaltung, Einrichtungen, Untermenüs, Gruppen der Einstellungen): je
 * Konto, Seite und Spaltenzahl des Rasters (2, 3 oder 4) eine Liste {id, col, row, span}. Gespeichert in user_settings
 * unter „tiles:<Seite>“ als JSON {"2": [...], "4": [...]}; beim Löschen des Kontos entfernt (UserData::purge).
 *
 * Der Server prüft nur die Form (bekannte Art von Seite, Ganzzahlen im Raster, keine Überlappung). Welche Kacheln eine
 * Person sieht, entscheiden weiter ihre Rechte; Kennungen gespeicherter Kacheln, die sie gerade nicht sieht, bleiben
 * erhalten, damit die Kachel nach Rückkehr des Rechts wieder an ihrem Platz steht. Das Zusammenführen mit der Vorgabe
 * des Admins macht die App (tileGrid.ts).
 *
 * Dazu eine Vorgabe für alle (Einstellung „tile_defaults“, gleiche Form je Seite): legt fest, wer die Einstellungen
 * ändern darf, direkt beim Anordnen einer Seite. Sie gilt für jede Person ohne eigene Anordnung der Seite und in der
 * Vorschau; wer eine Kachel nicht sieht, bei dem rücken die Kacheln derselben Zeile nach links.
 *
 * @phpstan-type Tile array{id: string, col: int, row: int, span: int}
 */
final class TileLayoutService
{
    /** Präfix der Einträge in user_settings (name höchstens 64 Zeichen) */
    public const PREFIX = 'tiles:';

    /** Einstellung mit der Vorgabe für alle: Seite => Spaltenzahl => Kacheln */
    public const DEFAULTS = 'tile_defaults';

    /** Spaltenzahlen des Rasters: Handy, Handy quer bzw. kleines Tablet, ab 900 px */
    public const COLUMNS = [2, 3, 4];

    public const MAX_TILES = 100;

    public const MAX_ROW = 200;

    /** Seiten mit Kachelraster: Verwaltung, Einrichtungen, Intern, Untermenü n, Gruppe der Einstellungen */
    private const PAGE = '/^(verwaltung|einrichtungen|intern|menue\/[0-9]{1,3}|einstellungen\/[a-z0-9-]{1,40})$/';

    /** Kennung einer Kachel: Bereich bzw. Ziel der Navigation (z. B. mitglieder, seite/3, einstellungen) */
    private const TILE_ID = '/^[a-z0-9][a-z0-9_\/-]{0,63}$/';

    public function __construct(private readonly UserSettings $settings, private readonly Settings $installation)
    {
    }

    /**
     * Vorgabe für alle.
     *
     * @return array<string, array<string, list<Tile>>> Seite => Spaltenzahl (als Text) => Kacheln
     */
    public function defaults(): array
    {
        $raw = $this->installation->get(self::DEFAULTS);
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $page => $value) {
            $layouts = is_string($page) && preg_match(self::PAGE, $page) === 1 ? self::decode((string) json_encode($value)) : [];
            if ($layouts !== []) {
                $out[$page] = $layouts;
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * Vorgabe als Text je Seite (Änderungsprotokoll), z. B. „2 Spalten: mitglieder Zeile 1 Spalte 1 Breite 2, …“.
     *
     * @return array<string, string>
     */
    public function describeDefaults(): array
    {
        $out = [];
        foreach ($this->defaults() as $page => $layouts) {
            $parts = [];
            foreach ($layouts as $cols => $tiles) {
                $list = array_map(static fn (array $t): string => sprintf('%s Zeile %d Spalte %d Breite %d', $t['id'], $t['row'], $t['col'], $t['span']), $tiles);
                $parts[] = $cols . ' Spalten: ' . ($list === [] ? 'leer' : implode(', ', $list));
            }
            $out[$page] = implode('; ', $parts);
        }
        return $out;
    }

    /**
     * Vorgabe einer Seite für eine Spaltenzahl festlegen; die anderen Spaltenzahlen der Seite bleiben.
     *
     * @param array<mixed> $body page, cols, tiles
     * @return array<string, list<Tile>> alle Spaltenzahlen der Seite
     */
    public function saveDefault(array $body): array
    {
        [$page, $cols, $tiles] = self::input($body);
        $all = $this->defaults();
        $all[$page] = [...($all[$page] ?? []), (string) $cols => $tiles];
        ksort($all[$page]);
        $this->installation->set(self::DEFAULTS, $all);
        return $all[$page];
    }

    /** Vorgabe einer Seite löschen (alle Spaltenzahlen); danach gilt wieder die Reihenfolge aus Einstellungen → Navigation. */
    public function resetDefault(mixed $page): void
    {
        $page = self::page($page);
        $all = $this->defaults();
        unset($all[$page]);
        if ($all === []) {
            $this->installation->delete(self::DEFAULTS);
        } else {
            $this->installation->set(self::DEFAULTS, $all);
        }
    }

    /**
     * Alle Anordnungen eines Kontos.
     *
     * @return array<string, array<string, list<Tile>>> Seite => Spaltenzahl (als Text) => Kacheln
     */
    public function all(int $userId): array
    {
        $out = [];
        foreach ($this->settings->withPrefix($userId, self::PREFIX) as $name => $value) {
            $page = substr($name, strlen(self::PREFIX));
            $layouts = self::decode($value);
            if (preg_match(self::PAGE, $page) === 1 && $layouts !== []) {
                $out[$page] = $layouts;
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * Anordnung einer Seite für eine Spaltenzahl speichern; die anderen Spaltenzahlen der Seite bleiben.
     *
     * @param array<mixed> $body page, cols, tiles
     * @return array<string, list<Tile>> alle Spaltenzahlen der Seite
     */
    public function save(int $userId, array $body): array
    {
        [$page, $cols, $tiles] = self::input($body);
        $layouts = self::decode($this->settings->get($userId, self::PREFIX . $page) ?? '');
        $layouts[(string) $cols] = $tiles;
        ksort($layouts);
        $this->settings->set($userId, self::PREFIX . $page, (string) json_encode($layouts, JSON_UNESCAPED_SLASHES));
        return $layouts;
    }

    /** Zurücksetzen: alle Spaltenzahlen der Seite; danach gilt wieder die Vorgabe. */
    public function reset(int $userId, mixed $page): void
    {
        $this->settings->delete($userId, self::PREFIX . self::page($page));
    }

    /**
     * @param array<mixed> $body page, cols, tiles
     * @return array{string, int, list<Tile>}
     */
    private static function input(array $body): array
    {
        $page = self::page($body['page'] ?? null);
        $cols = $body['cols'] ?? null;
        if (!is_int($cols) || !in_array($cols, self::COLUMNS, true)) {
            throw ApiError::invalid('Die Spaltenzahl muss 2, 3 oder 4 sein.');
        }
        return [$page, $cols, self::tiles($body['tiles'] ?? null, $cols)];
    }

    private static function page(mixed $page): string
    {
        if (!is_string($page) || preg_match(self::PAGE, $page) !== 1) {
            throw ApiError::invalid('Unbekannte Seite.');
        }
        return $page;
    }

    /** @return list<Tile> nach Zeile und Spalte geordnet */
    private static function tiles(mixed $tiles, int $cols): array
    {
        if (!is_array($tiles) || !array_is_list($tiles)) {
            throw ApiError::invalid('Die Kacheln fehlen.');
        }
        if (count($tiles) > self::MAX_TILES) {
            throw ApiError::invalid(sprintf('Höchstens %d Kacheln je Seite.', self::MAX_TILES));
        }
        $out = [];
        $ids = [];
        $taken = [];
        foreach ($tiles as $t) {
            $tile = is_array($t) ? self::tile($t, $cols) : null;
            if ($tile === null) {
                throw ApiError::invalid('Ungültige Kachel.');
            }
            if (isset($ids[$tile['id']])) {
                throw ApiError::invalid('Eine Kachel steht doppelt.');
            }
            $ids[$tile['id']] = true;
            for ($c = $tile['col']; $c < $tile['col'] + $tile['span']; $c++) {
                $cell = $tile['row'] . ':' . $c;
                if (isset($taken[$cell])) {
                    throw ApiError::invalid('Zwei Kacheln liegen übereinander.');
                }
                $taken[$cell] = true;
            }
            $out[] = $tile;
        }
        usort($out, static fn (array $a, array $b): int => [$a['row'], $a['col']] <=> [$b['row'], $b['col']]);
        return $out;
    }

    /**
     * @param array<mixed> $t
     * @return Tile|null
     */
    private static function tile(array $t, int $cols): ?array
    {
        $id = $t['id'] ?? null;
        $col = $t['col'] ?? null;
        $row = $t['row'] ?? null;
        $span = $t['span'] ?? null;
        if (!is_string($id) || preg_match(self::TILE_ID, $id) !== 1 || !is_int($col) || !is_int($row) || !is_int($span)) {
            return null;
        }
        if ($col < 1 || $span < 1 || $col + $span - 1 > $cols || $row < 1 || $row > self::MAX_ROW) {
            return null;
        }
        return ['id' => $id, 'col' => $col, 'row' => $row, 'span' => $span];
    }

    /**
     * Gespeicherter Wert; was nicht (mehr) passt, fällt weg.
     *
     * @return array<string, list<Tile>>
     */
    private static function decode(string $value): array
    {
        $data = $value === '' ? null : json_decode($value, true);
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach (self::COLUMNS as $cols) {
            $tiles = $data[(string) $cols] ?? null;
            if (!is_array($tiles)) {
                continue;
            }
            try {
                $out[(string) $cols] = self::tiles($tiles, $cols);
            } catch (ApiError) {
                // Beschädigter Eintrag: wie nicht gespeichert
            }
        }
        return $out;
    }
}
