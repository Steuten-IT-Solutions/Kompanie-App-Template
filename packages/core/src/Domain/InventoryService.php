<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Storage\InventoryRepository;

/**
 * Inventar (Issue #6): was die Kompanie besitzt, wie viel und wo. Nur für alle mit manage_inventory (siehe Api).
 *
 * - Ein Ort je Eintrag; dasselbe Material an zwei Orten sind zwei Einträge. Orte sind freier Text; ein Ort, der sich nur in
 *   Groß- und Kleinschreibung von einem vorhandenen unterscheidet, bekommt dessen Schreibweise (ebenso Kategorie und
 *   Einheit). Vorschläge: die Orte der vorhandenen Einträge.
 * - Mengen ≥ 0 mit höchstens drei Nachkommastellen, intern in Tausendsteln (exakt auf beiden Datenbanken).
 * - Löschen ist weich (deleted_at): Der Verlauf bleibt lesbar, auch für gelöschte Einträge.
 * - Jede Änderung schreibt genau einen Verlaufseintrag je betroffenem Eintrag (create, update, move, delete), mit den
 *   geänderten Feldern ({old, new}), optionalem Grund und Bezeichnung, Einheit und Ort als Momentaufnahme. Ändert ein
 *   PATCH nichts, entsteht kein Eintrag.
 * - Umlagern (move) in einer Transaktion; Einzelheiten bei move().
 *
 * @phpstan-import-type InventoryItem from InventoryRepository
 * @phpstan-import-type InventoryLogRow from InventoryRepository
 */
final class InventoryService
{
    public const TITLE_MAX = 100;
    public const LOCATION_MAX = 100;
    public const CATEGORY_MAX = 100;
    public const UNIT_MAX = 20;
    public const NOTE_MAX = 1000;
    public const REASON_MAX = 255;
    /** Größte Menge in Tausendsteln (999 999 999,999). */
    public const MAX_MILLI = 999_999_999_999;
    public const LOG_LIMIT = 100;
    public const LOG_LIMIT_MAX = 500;

    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_MOVE = 'move';
    public const ACTION_DELETE = 'delete';

    /** Felder, die ein PATCH ändern kann (ohne quantity). */
    private const TEXT_FIELDS = ['title', 'category', 'unit', 'location', 'note'];

    private ?InventoryRepository $repo = null;

    public function __construct(private readonly App $app)
    {
    }

    public function repository(): InventoryRepository
    {
        return $this->repo ??= new InventoryRepository($this->app->db);
    }

    /** @return list<array<string, mixed>> alle Einträge, nach Bezeichnung und Ort (gruppiert wird in der App) */
    public function list(): array
    {
        $names = new UserNames($this->app->users);
        return array_map(fn (array $i): array => self::format($i, $names), $this->repository()->active());
    }

    /** @return list<string> bekannte Orte (der vorhandenen Einträge), alphabetisch, ohne Dubletten in anderer Schreibweise */
    public function locations(): array
    {
        $out = [];
        foreach ($this->repository()->active() as $item) {
            $out[mb_strtolower($item['location'])] ??= $item['location'];
        }
        $list = array_values($out);
        usort($list, static fn (string $a, string $b): int => strcmp(mb_strtolower($a), mb_strtolower($b)));
        return $list;
    }

    /**
     * Verlauf aller Einträge (auch gelöschter), neueste zuerst.
     *
     * @return list<array<string, mixed>>
     */
    public function log(mixed $limit = null, mixed $before = null): array
    {
        return $this->formatLog($this->repository()->history(null, self::limit($limit), self::before($before)));
    }

    /**
     * Verlauf eines Eintrags, auch nach dem Löschen.
     *
     * @return list<array<string, mixed>>
     */
    public function itemLog(int $id, mixed $limit = null, mixed $before = null): array
    {
        if ($this->repository()->find($id) === null) {
            throw ApiError::notFound('Diesen Eintrag gibt es nicht.');
        }
        return $this->formatLog($this->repository()->history($id, self::limit($limit), self::before($before)));
    }

    /**
     * @param array<string, mixed> $in title, quantity, location (Pflicht), category, unit, note, reason
     * @return array<string, mixed>
     */
    public function create(User $user, array $in): array
    {
        $title = self::requiredText($in['title'] ?? null, self::TITLE_MAX, 'Bitte eine Bezeichnung eingeben.', 'Die Bezeichnung');
        $location = self::requiredText($in['location'] ?? null, self::LOCATION_MAX, 'Bitte einen Ort eingeben.', 'Der Ort');
        $quantity = self::quantity($in['quantity'] ?? 0, 'Die Menge');
        $category = self::optionalText($in['category'] ?? null, self::CATEGORY_MAX, 'Die Kategorie');
        $unit = self::optionalText($in['unit'] ?? null, self::UNIT_MAX, 'Die Einheit');
        $note = self::note($in['note'] ?? null);
        $reason = self::reason($in['reason'] ?? null);
        $repo = $this->repository();
        $id = $repo->db()->transaction(function () use ($repo, $user, $title, $location, $quantity, $category, $unit, $note, $reason): int {
            $known = $repo->active();
            $location = self::canonical($location, array_column($known, 'location'));
            $category = self::canonical($category, array_column($known, 'category'));
            $unit = self::canonical($unit, array_column($known, 'unit'));
            $now = $this->app->time()->nowUtc();
            $id = $repo->insert([
                'title' => $title, 'category' => $category, 'quantity' => $quantity, 'unit' => $unit, 'location' => $location, 'note' => $note,
                'created_by' => $user->id, 'created_at' => $now, 'updated_by' => $user->id, 'updated_at' => $now,
            ]);
            $changes = ['title' => self::change(null, $title), 'quantity' => self::change(null, self::number($quantity)), 'location' => self::change(null, $location)];
            foreach (['category' => $category, 'unit' => $unit, 'note' => $note] as $field => $value) {
                if ($value !== '') {
                    $changes[$field] = self::change(null, $value);
                }
            }
            $repo->log($id, self::ACTION_CREATE, $changes, $reason, $title, $unit, $location, $user->id, $now);
            return $id;
        });
        return $this->one($id);
    }

    /**
     * Felder ändern; nur genannte ändern sich. Menge absolut (quantity) oder als Änderung (adjust, z. B. +1/−1 von den
     * Knöpfen der App, ohne dass sich gleichzeitige Änderungen überschreiben). Der Ort lässt sich hier nur berichtigen
     * (ohne Zusammenführen, Eintrag „update“); Bestand an einen anderen Ort bringt move().
     *
     * @param array<string, mixed> $in
     * @return array<string, mixed>
     */
    public function update(User $user, int $id, array $in): array
    {
        if (array_key_exists('quantity', $in) && array_key_exists('adjust', $in)) {
            throw ApiError::invalid('Bitte entweder die Menge oder eine Änderung der Menge angeben, nicht beides.');
        }
        $next = [];
        if (array_key_exists('title', $in)) {
            $next['title'] = self::requiredText($in['title'], self::TITLE_MAX, 'Bitte eine Bezeichnung eingeben.', 'Die Bezeichnung');
        }
        if (array_key_exists('location', $in)) {
            $next['location'] = self::requiredText($in['location'], self::LOCATION_MAX, 'Bitte einen Ort eingeben.', 'Der Ort');
        }
        if (array_key_exists('category', $in)) {
            $next['category'] = self::optionalText($in['category'], self::CATEGORY_MAX, 'Die Kategorie');
        }
        if (array_key_exists('unit', $in)) {
            $next['unit'] = self::optionalText($in['unit'], self::UNIT_MAX, 'Die Einheit');
        }
        if (array_key_exists('note', $in)) {
            $next['note'] = self::note($in['note']);
        }
        $quantity = array_key_exists('quantity', $in) ? self::quantity($in['quantity'], 'Die Menge') : null;
        $adjust = array_key_exists('adjust', $in) ? self::adjust($in['adjust']) : null;
        $reason = self::reason($in['reason'] ?? null);
        $repo = $this->repository();
        $repo->db()->transaction(function () use ($repo, $user, $id, $next, $quantity, $adjust, $reason): void {
            $item = $this->activeItem($id, true);
            $known = array_filter($repo->active(), static fn (array $i): bool => $i['id'] !== $id);
            foreach (['location', 'category', 'unit'] as $field) {
                if (isset($next[$field])) {
                    $next[$field] = self::canonical($next[$field], array_column($known, $field));
                }
            }
            if ($adjust !== null) {
                $quantity = $item['quantity'] + $adjust;
                if ($quantity < 0) {
                    throw ApiError::invalid(sprintf('Die Menge darf nicht unter 0 fallen (vorhanden: %s).', self::display($item['quantity'])));
                }
                if ($quantity > self::MAX_MILLI) {
                    throw ApiError::invalid('Die Menge ist zu groß.');
                }
            }
            $data = [];
            $changes = [];
            foreach (self::TEXT_FIELDS as $field) {
                if (isset($next[$field]) && $next[$field] !== $item[$field]) {
                    $data[$field] = $next[$field];
                    $changes[$field] = self::change($item[$field], $next[$field]);
                }
            }
            if ($quantity !== null && $quantity !== $item['quantity']) {
                $data['quantity'] = $quantity;
                $changes['quantity'] = self::change(self::number($item['quantity']), self::number($quantity));
            }
            if ($data === []) {
                return; // nichts geändert: kein Verlaufseintrag
            }
            $now = $this->app->time()->nowUtc();
            $repo->update($id, $data + ['updated_by' => $user->id, 'updated_at' => $now]);
            $after = $data + $item;
            $repo->log($id, self::ACTION_UPDATE, $changes, $reason, (string) $after['title'], (string) $after['unit'], (string) $after['location'], $user->id, $now);
        });
        return $this->one($id);
    }

    public function delete(User $user, int $id, mixed $reason): void
    {
        $reason = self::reason($reason);
        $repo = $this->repository();
        $repo->db()->transaction(function () use ($repo, $user, $id, $reason): void {
            $item = $this->activeItem($id, true);
            $now = $this->app->time()->nowUtc();
            $repo->update($id, ['deleted_at' => $now, 'deleted_by' => $user->id, 'updated_by' => $user->id, 'updated_at' => $now]);
            $repo->log($id, self::ACTION_DELETE, ['quantity' => self::change(self::number($item['quantity']), null)], $reason, $item['title'], $item['unit'], $item['location'], $user->id, $now);
        });
    }

    /**
     * Einen Teil oder alles an einen anderen Ort bringen, in einer Transaktion. Mehr als vorhanden → 400 ohne Änderung.
     *
     * Ziel: Gibt es am Zielort schon einen Eintrag mit derselben Bezeichnung und Einheit (ohne Unterschied in Groß- und
     * Kleinschreibung), kommt die Menge dazu; sonst entsteht ein neuer Eintrag (Kategorie und Notiz übernommen). Wird
     * alles umgelagert, verschwindet die Quelle (beim Zusammenführen weich gelöscht); ohne passendes Ziel ändert sich
     * dann nur ihr Ort.
     *
     * Verlauf: Jeder betroffene Eintrag bekommt genau einen Eintrag „move“ mit demselben Objekt
     * changes.move = {from, to, quantity, source_id, target_id} und seinen eigenen Änderungen:
     * - Teilmenge: Quelle quantity {alt, neu}; Ziel quantity {alt, neu} bzw. beim neuen Eintrag {null, Menge}.
     * - Alles, mit Ziel: Quelle quantity {alt, 0} und move.source_removed = true; Ziel quantity {alt, neu}.
     * - Alles, ohne Ziel: nur ein Eintrag (source_id = target_id) mit location {alt, neu}.
     *
     * @param array<string, mixed> $in quantity (> 0), location (Ziel), reason
     * @return array{source: array<string, mixed>|null, target: array<string, mixed>} source: was am alten Ort bleibt
     *     (null, wenn dort nichts mehr steht); target: der Eintrag am Zielort
     */
    public function move(User $user, int $id, array $in): array
    {
        $amount = self::quantity($in['quantity'] ?? null, 'Die Menge zum Umlagern');
        if ($amount === 0) {
            throw ApiError::invalid('Bitte eine Menge größer als 0 angeben.');
        }
        $to = self::requiredText($in['location'] ?? null, self::LOCATION_MAX, 'Bitte den Zielort eingeben.', 'Der Zielort');
        $reason = self::reason($in['reason'] ?? null);
        $repo = $this->repository();
        [$sourceId, $targetId] = $repo->db()->transaction(function () use ($repo, $user, $id, $amount, $to, $reason): array {
            $source = $this->activeItem($id, true);
            $others = array_values(array_filter($repo->active(), static fn (array $i): bool => $i['id'] !== $id));
            $to = self::canonical($to, array_column($others, 'location'));
            if (mb_strtolower($to) === mb_strtolower($source['location'])) {
                throw ApiError::invalid('Der Zielort ist derselbe wie der jetzige Ort.');
            }
            if ($amount > $source['quantity']) {
                throw ApiError::invalid(sprintf('Es sind nur %s vorhanden.', self::display($source['quantity'], $source['unit'])));
            }
            $target = null;
            foreach ($others as $other) {
                if (mb_strtolower($other['location']) === mb_strtolower($to)
                    && mb_strtolower($other['title']) === mb_strtolower($source['title'])
                    && mb_strtolower($other['unit']) === mb_strtolower($source['unit'])) {
                    $target = $repo->find($other['id'], true);
                    break;
                }
            }
            $now = $this->app->time()->nowUtc();
            $all = $amount === $source['quantity'];
            $stamp = ['updated_by' => $user->id, 'updated_at' => $now];
            $move = ['from' => $source['location'], 'to' => $target['location'] ?? $to, 'quantity' => self::number($amount), 'source_id' => $id];

            if ($target === null && $all) {
                // Alles, ohne Ziel: nur der Ort ändert sich.
                $repo->update($id, ['location' => $to] + $stamp);
                $repo->log($id, self::ACTION_MOVE, ['location' => self::change($source['location'], $to), 'move' => $move + ['target_id' => $id]], $reason, $source['title'], $source['unit'], $to, $user->id, $now);
                return [null, $id];
            }
            if ($target === null) {
                $targetId = $repo->insert([
                    'title' => $source['title'], 'category' => $source['category'], 'quantity' => $amount, 'unit' => $source['unit'], 'location' => $to,
                    'note' => $source['note'], 'created_by' => $user->id, 'created_at' => $now,
                ] + $stamp);
                $targetBefore = null;
                $targetLocation = $to;
                $targetTitle = $source['title'];
                $targetUnit = $source['unit'];
            } else {
                $targetId = $target['id'];
                $targetBefore = $target['quantity'];
                if ($targetBefore + $amount > self::MAX_MILLI) {
                    throw ApiError::invalid('Die Menge am Zielort wäre zu groß.');
                }
                $repo->update($targetId, ['quantity' => $targetBefore + $amount] + $stamp);
                $targetLocation = $target['location'];
                $targetTitle = $target['title'];
                $targetUnit = $target['unit'];
            }
            $move += ['target_id' => $targetId];
            $left = $source['quantity'] - $amount;
            $repo->update($id, ['quantity' => $left] + ($all ? ['deleted_at' => $now, 'deleted_by' => $user->id] : []) + $stamp);
            $repo->log($id, self::ACTION_MOVE, ['quantity' => self::change(self::number($source['quantity']), self::number($left)), 'move' => $move + ($all ? ['source_removed' => true] : [])], $reason, $source['title'], $source['unit'], $source['location'], $user->id, $now);
            $targetAfter = ($targetBefore ?? 0) + $amount;
            $repo->log($targetId, self::ACTION_MOVE, ['quantity' => self::change($targetBefore === null ? null : self::number($targetBefore), self::number($targetAfter)), 'move' => $move], $reason, $targetTitle, $targetUnit, $targetLocation, $user->id, $now);
            return [$all ? null : $id, $targetId];
        });
        return ['source' => $sourceId === null ? null : $this->one($sourceId), 'target' => $this->one($targetId)];
    }

    /** @return InventoryItem */
    private function activeItem(int $id, bool $lock = false): array
    {
        $item = $this->repository()->find($id, $lock);
        if ($item === null || $item['deleted_at'] !== null) {
            throw ApiError::notFound('Diesen Eintrag gibt es nicht (mehr).');
        }
        return $item;
    }

    /** @return array<string, mixed> */
    private function one(int $id): array
    {
        return self::format($this->activeItem($id), new UserNames($this->app->users));
    }

    /**
     * @param InventoryItem $i
     * @return array<string, mixed>
     */
    private static function format(array $i, UserNames $names): array
    {
        return [
            'id' => $i['id'],
            'title' => $i['title'],
            'quantity' => self::number($i['quantity']),
            'unit' => $i['unit'] === '' ? null : $i['unit'],
            'location' => $i['location'],
            'category' => $i['category'] === '' ? null : $i['category'],
            'note' => $i['note'] === '' ? null : $i['note'],
            'created_by_name' => $names->name($i['created_by']),
            'created_at' => Time::utcToIso($i['created_at']),
            'updated_by_name' => $names->name($i['updated_by']),
            'updated_at' => Time::utcToIso($i['updated_at']),
        ];
    }

    /**
     * @param list<InventoryLogRow> $rows
     * @return list<array<string, mixed>>
     */
    private function formatLog(array $rows): array
    {
        $names = new UserNames($this->app->users);
        return array_map(static fn (array $r): array => [
            'id' => $r['id'],
            'item_id' => $r['item_id'],
            'action' => $r['action'],
            'changes' => (object) $r['changes'],
            'reason' => $r['reason'] === '' ? null : $r['reason'],
            'title' => $r['title'],
            'unit' => $r['unit'] === '' ? null : $r['unit'],
            'location' => $r['location'],
            'user_id' => $r['user_id'],
            'user_name' => $names->name($r['user_id']),
            'created_at' => Time::utcToIso($r['created_at']),
        ], $rows);
    }

    /** @return array{old: mixed, new: mixed} */
    private static function change(mixed $old, mixed $new): array
    {
        return ['old' => $old, 'new' => $new];
    }

    /** Tausendstel → Zahl für JSON: ganze Zahl, wenn möglich, sonst mit Nachkommastellen. */
    public static function number(int $milli): int|float
    {
        return $milli % 1000 === 0 ? intdiv($milli, 1000) : round($milli / 1000, 3);
    }

    /** Für Meldungen: „12,5 Stück“. */
    private static function display(int $milli, string $unit = ''): string
    {
        $text = str_replace('.', ',', rtrim(rtrim(InventoryRepository::toDecimal($milli), '0'), '.'));
        return $unit === '' ? $text : $text . ' ' . $unit;
    }

    /**
     * Menge ≥ 0 mit höchstens drei Nachkommastellen; Zahl oder Text mit Punkt oder Komma („2,5“). Ergebnis in Tausendsteln.
     */
    public static function quantity(mixed $value, string $what): int
    {
        if (is_int($value)) {
            $value = (string) $value;
        } elseif (is_float($value)) {
            if (!is_finite($value)) {
                throw ApiError::invalid($what . ' ist keine gültige Zahl.');
            }
            $milli = (int) round($value * 1000);
            if (abs($value * 1000 - $milli) > 1e-6 * max(1.0, abs($value))) {
                throw ApiError::invalid($what . ' darf höchstens drei Nachkommastellen haben.');
            }
            $value = InventoryRepository::toDecimal($milli);
        }
        if (!is_string($value) || trim($value) === '') {
            throw ApiError::invalid('Bitte eine Menge angeben.');
        }
        $s = str_replace(',', '.', trim($value));
        if (preg_match('/^-/', $s)) {
            throw ApiError::invalid($what . ' darf nicht negativ sein.');
        }
        if (!preg_match('/^(\d+)(?:\.(\d*))?$/', $s, $m)) {
            throw ApiError::invalid($what . ' ist keine gültige Zahl.');
        }
        $fraction = rtrim($m[2] ?? '', '0');
        if (strlen($fraction) > 3) {
            throw ApiError::invalid($what . ' darf höchstens drei Nachkommastellen haben.');
        }
        $whole = ltrim($m[1], '0');
        if (strlen($whole) > 9) {
            throw ApiError::invalid($what . ' ist zu groß.');
        }
        return (int) $whole * 1000 + (int) str_pad($fraction, 3, '0');
    }

    /** Änderung der Menge (z. B. +1 oder −1), in Tausendsteln; 0 ist erlaubt (ändert nichts). */
    private static function adjust(mixed $value): int
    {
        $negative = (is_int($value) || is_float($value)) ? $value < 0 : (is_string($value) && str_starts_with(trim($value), '-'));
        if (is_int($value) || is_float($value)) {
            $value = abs($value);
        } elseif (is_string($value)) {
            $value = ltrim(trim($value), '+-');
        }
        $milli = self::quantity($value, 'Die Änderung der Menge');
        return $negative ? -$milli : $milli;
    }

    private static function requiredText(mixed $value, int $max, string $missing, string $what): string
    {
        $text = is_string($value) ? Text::line($value) : '';
        if ($text === '') {
            throw ApiError::invalid($missing);
        }
        if (mb_strlen($text) > $max) {
            throw ApiError::invalid(sprintf('%s darf höchstens %d Zeichen lang sein.', $what, $max));
        }
        return $text;
    }

    private static function optionalText(mixed $value, int $max, string $what): string
    {
        if ($value === null) {
            return '';
        }
        if (!is_string($value)) {
            throw ApiError::invalid($what . ' muss ein Text sein.');
        }
        $text = Text::line($value);
        if (mb_strlen($text) > $max) {
            throw ApiError::invalid(sprintf('%s darf höchstens %d Zeichen lang sein.', $what, $max));
        }
        return $text;
    }

    private static function note(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (!is_string($value)) {
            throw ApiError::invalid('Die Notiz muss ein Text sein.');
        }
        $text = Text::multiline($value);
        if (mb_strlen($text) > self::NOTE_MAX) {
            throw ApiError::invalid(sprintf('Die Notiz darf höchstens %d Zeichen lang sein.', self::NOTE_MAX));
        }
        return $text;
    }

    private static function reason(mixed $value): string
    {
        return self::optionalText($value, self::REASON_MAX, 'Der Grund');
    }

    /**
     * Schreibweise eines bekannten Werts übernehmen, wenn er sich nur in Groß- und Kleinschreibung unterscheidet.
     *
     * @param array<int, string> $known
     */
    private static function canonical(string $value, array $known): string
    {
        if ($value === '') {
            return '';
        }
        $lower = mb_strtolower($value);
        foreach ($known as $k) {
            if ($k !== '' && mb_strtolower($k) === $lower) {
                return $k;
            }
        }
        return $value;
    }

    private static function limit(mixed $value): int
    {
        if ($value === null || $value === '') {
            return self::LOG_LIMIT;
        }
        $n = Input::integer($value);
        if ($n === null || $n < 1 || $n > self::LOG_LIMIT_MAX) {
            throw ApiError::invalid(sprintf('limit: Zahl von 1 bis %d.', self::LOG_LIMIT_MAX));
        }
        return $n;
    }

    private static function before(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $n = Input::integer($value);
        if ($n === null || $n < 1) {
            throw ApiError::invalid('before: ID eines Verlaufseintrags.');
        }
        return $n;
    }
}
