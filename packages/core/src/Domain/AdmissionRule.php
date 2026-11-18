<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\Http\ApiError;

/**
 * Voraussetzung einer Anwärter-Rolle als Baum (Domain\AdmissionService): Gruppen verknüpfen Bedingungen und weitere
 * Gruppen mit UND (alle) oder ODER (eine reicht), beliebig tief.
 *
 * - Gruppe: {type: group, op: and|or, items: [...]}
 * - Teilnahmen: {type: attendance, kind, count} = mindestens count (1–100) Mal „anwesend“ bei Listen der Terminart kind
 * - Dauer: {type: duration, months} = mindestens months (1–120) Monate seit „Anwärter seit“
 *
 * Die oberste Ebene ist immer eine Gruppe; ohne Bedingungen gibt es keine Voraussetzung. Leere Gruppen fallen beim
 * Speichern weg. Höchstens CONDITIONS_MAX Bedingungen und GROUPS_MAX Gruppen (ohne die oberste).
 *
 * Auswertung: Jede Bedingung ist erfüllt, offen oder unbekannt (Dauer ohne „Anwärter seit“). UND ist erfüllt, wenn alle
 * Teile erfüllt sind (Datum: das späteste), ODER, wenn einer erfüllt ist (Datum: das früheste der erfüllten). Sonst ist
 * die Gruppe offen, oder unbekannt, wenn nur Unbekanntes fehlt.
 */
final class AdmissionRule
{
    public const GROUP = 'group';
    public const ATTENDANCE = 'attendance';
    public const DURATION = 'duration';
    public const AND = 'and';
    public const OR = 'or';

    public const FULFILLED = 'fulfilled';
    public const OPEN = 'open';
    public const UNKNOWN = 'unknown';

    public const COUNT_MAX = 100;
    public const MONTHS_MAX = 120;
    public const CONDITIONS_MAX = 20;
    public const GROUPS_MAX = 20;

    /** @return array<string, mixed> leere Voraussetzung */
    public static function none(): array
    {
        return ['type' => self::GROUP, 'op' => self::AND, 'items' => []];
    }

    /**
     * Baum aus der früheren festen Voraussetzung (Teilnahmen einer Terminart UND Mindestdauer).
     *
     * @return array<string, mixed>
     */
    public static function fromLegacy(string $kind, int $count, int $months): array
    {
        $items = [];
        if ($kind !== '' && $count > 0) {
            $items[] = ['type' => self::ATTENDANCE, 'kind' => $kind, 'count' => min(self::COUNT_MAX, $count)];
        }
        if ($months > 0) {
            $items[] = ['type' => self::DURATION, 'months' => min(self::MONTHS_MAX, $months)];
        }
        return ['type' => self::GROUP, 'op' => self::AND, 'items' => $items];
    }

    /**
     * Die frühere Form (kind, count, months), wenn der Baum genau sie ausdrückt; sonst null. Ältere Versionen lesen nur
     * diese Felder.
     *
     * @param array<string, mixed> $tree
     * @return array{kind: string, count: int, months: int}|null
     */
    public static function legacy(array $tree): ?array
    {
        $items = self::items($tree);
        if (count($items) > 1 && ($tree['op'] ?? self::AND) !== self::AND) {
            return null;
        }
        $out = ['kind' => '', 'count' => 0, 'months' => 0];
        $seen = [];
        foreach ($items as $item) {
            $type = $item['type'] ?? '';
            if (!in_array($type, [self::ATTENDANCE, self::DURATION], true) || isset($seen[$type])) {
                return null;
            }
            $seen[$type] = true;
            if ($type === self::ATTENDANCE) {
                $out['kind'] = is_string($item['kind'] ?? null) ? $item['kind'] : '';
                $out['count'] = is_int($item['count'] ?? null) ? $item['count'] : 0;
            } else {
                $out['months'] = is_int($item['months'] ?? null) ? $item['months'] : 0;
            }
        }
        return $out;
    }

    /**
     * Gespeicherten Baum nachsichtig lesen (Ungültiges fällt weg); null, wenn keiner gespeichert ist.
     *
     * @return array<string, mixed>|null
     */
    public static function fromStored(mixed $raw): ?array
    {
        if (!is_array($raw) || ($raw['type'] ?? null) !== self::GROUP) {
            return null;
        }
        try {
            return self::parse($raw, static fn (string $kind): string => $kind);
        } catch (ApiError) {
            return self::lenient($raw, 0) ?? self::none();
        }
    }

    /**
     * Eingabe prüfen (PATCH /admin/roles/{key}, Feld admission_rule): null oder leer = keine Voraussetzung.
     *
     * @param \Closure(string): string $kind prüft eine Terminart (wirft ApiError) und gibt sie zurück
     * @return array<string, mixed>
     */
    public static function parse(mixed $raw, \Closure $kind): array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return self::none();
        }
        if (!is_array($raw) || ($raw['type'] ?? self::GROUP) !== self::GROUP) {
            throw ApiError::invalid('Die Voraussetzung muss eine Gruppe sein.');
        }
        $counter = ['conditions' => 0, 'groups' => 0];
        $tree = self::parseGroup($raw, $kind, $counter) ?? self::none();
        if ($counter['conditions'] > self::CONDITIONS_MAX) {
            throw ApiError::invalid(sprintf('Höchstens %d Bedingungen je Rolle.', self::CONDITIONS_MAX));
        }
        if ($counter['groups'] - 1 > self::GROUPS_MAX) {
            throw ApiError::invalid(sprintf('Höchstens %d Gruppen je Rolle.', self::GROUPS_MAX));
        }
        return $tree;
    }

    /**
     * Alle Terminarten im Baum.
     *
     * @param array<string, mixed> $node
     * @return list<string>
     */
    public static function kinds(array $node): array
    {
        if (($node['type'] ?? '') === self::ATTENDANCE) {
            return is_string($node['kind'] ?? null) && $node['kind'] !== '' ? [$node['kind']] : [];
        }
        $out = [];
        foreach (self::items($node) as $item) {
            $out = array_merge($out, self::kinds($item));
        }
        return array_values(array_unique($out));
    }

    /**
     * Erste Bedingung einer Art (Tiefensuche), null ohne.
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>|null
     */
    public static function first(array $node, string $type): ?array
    {
        if (($node['type'] ?? '') === $type) {
            return $node;
        }
        foreach (self::items($node) as $item) {
            $found = self::first($item, $type);
            if ($found !== null) {
                return $found;
            }
        }
        return null;
    }

    /**
     * Voraussetzung in einer Zeile, z. B. „6 Teilnahmen an Versammlung und (12 Monate als Anwärter oder 10 Teilnahmen an
     * Arbeitsdienst)“; leer ohne Bedingung.
     *
     * @param array<string, mixed> $node
     */
    public static function describe(array $node, bool $inner = false): string
    {
        $type = $node['type'] ?? '';
        if ($type === self::ATTENDANCE) {
            $n = is_int($node['count'] ?? null) ? $node['count'] : 0;
            return sprintf('%d %s an %s', $n, $n === 1 ? 'Teilnahme' : 'Teilnahmen', is_string($node['kind'] ?? null) ? $node['kind'] : '');
        }
        if ($type === self::DURATION) {
            $n = is_int($node['months'] ?? null) ? $node['months'] : 0;
            return sprintf('%d %s als Anwärter', $n, $n === 1 ? 'Monat' : 'Monate');
        }
        $parts = array_values(array_filter(array_map(static fn (array $i): string => self::describe($i, true), self::items($node)), static fn (string $p): bool => $p !== ''));
        $text = implode(($node['op'] ?? self::AND) === self::OR ? ' oder ' : ' und ', $parts);
        return $inner && count($parts) > 1 ? '(' . $text . ')' : $text;
    }

    /** @param array<string, mixed> $tree */
    public static function isEmpty(array $tree): bool
    {
        return self::items($tree) === [];
    }

    /**
     * Baum auswerten. $leaf wertet eine Bedingung aus und gibt sie mit status (FULFILLED, OPEN, UNKNOWN) und on (Tag,
     * an dem sie erfüllt wurde, sonst null) zurück; Gruppen bekommen dieselben Felder und ihre ausgewerteten items.
     *
     * @param array<string, mixed> $node
     * @param \Closure(array<string, mixed>): array<string, mixed> $leaf
     * @return array<string, mixed>
     */
    public static function evaluate(array $node, \Closure $leaf): array
    {
        if (($node['type'] ?? '') !== self::GROUP) {
            return $leaf($node);
        }
        $items = array_map(static fn (array $item): array => self::evaluate($item, $leaf), self::items($node));
        $or = ($node['op'] ?? self::AND) === self::OR;
        $statuses = array_column($items, 'status');
        $dates = array_values(array_filter(array_map(
            static fn (array $i): ?string => ($i['status'] ?? '') === self::FULFILLED && is_string($i['on'] ?? null) ? $i['on'] : null,
            $items,
        )));
        if ($items === []) {
            $status = self::FULFILLED;
        } elseif ($or) {
            $status = in_array(self::FULFILLED, $statuses, true) ? self::FULFILLED : (in_array(self::UNKNOWN, $statuses, true) ? self::UNKNOWN : self::OPEN);
        } else {
            $status = in_array(self::OPEN, $statuses, true) ? self::OPEN : (in_array(self::UNKNOWN, $statuses, true) ? self::UNKNOWN : self::FULFILLED);
        }
        $on = null;
        if ($status === self::FULFILLED && $dates !== []) {
            $on = $or ? min($dates) : max($dates);
        }
        return ['type' => self::GROUP, 'op' => $or ? self::OR : self::AND, 'status' => $status, 'on' => $on, 'items' => $items];
    }

    /**
     * @param array<string, mixed> $node
     * @return list<array<string, mixed>>
     */
    private static function items(array $node): array
    {
        $out = [];
        foreach (is_array($node['items'] ?? null) ? $node['items'] : [] as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $out[] = $item;
            }
        }
        return $out;
    }

    /**
     * @param array<mixed> $raw
     * @param \Closure(string): string $kind
     * @param array{conditions: int, groups: int} $counter
     * @return array<string, mixed>|null null für eine leere Gruppe
     */
    private static function parseGroup(array $raw, \Closure $kind, array &$counter): ?array
    {
        $counter['groups']++;
        if ($counter['groups'] - 1 > self::GROUPS_MAX) {
            throw ApiError::invalid(sprintf('Höchstens %d Gruppen je Rolle.', self::GROUPS_MAX));
        }
        $op = $raw['op'] ?? self::AND;
        if (!in_array($op, [self::AND, self::OR], true)) {
            throw ApiError::invalid('Eine Gruppe ist entweder UND oder ODER.');
        }
        $itemsRaw = $raw['items'] ?? [];
        if (!is_array($itemsRaw) || !array_is_list($itemsRaw)) {
            throw ApiError::invalid('Die Bedingungen einer Gruppe müssen eine Liste sein.');
        }
        $items = [];
        foreach ($itemsRaw as $item) {
            if (!is_array($item)) {
                throw ApiError::invalid('Unbekannte Bedingung.');
            }
            $type = $item['type'] ?? null;
            if ($type === self::GROUP) {
                $group = self::parseGroup($item, $kind, $counter);
                if ($group !== null) {
                    $items[] = $group;
                }
                continue;
            }
            $counter['conditions']++;
            if ($counter['conditions'] > self::CONDITIONS_MAX) {
                throw ApiError::invalid(sprintf('Höchstens %d Bedingungen je Rolle.', self::CONDITIONS_MAX));
            }
            if ($type === self::ATTENDANCE) {
                $name = is_string($item['kind'] ?? null) ? $item['kind'] : '';
                if ($name === '') {
                    throw ApiError::invalid('Für die Teilnahmen bitte eine Terminart wählen.');
                }
                $items[] = ['type' => self::ATTENDANCE, 'kind' => $kind($name), 'count' => self::number($item['count'] ?? null, self::COUNT_MAX, 'Teilnahmen')];
            } elseif ($type === self::DURATION) {
                $items[] = ['type' => self::DURATION, 'months' => self::number($item['months'] ?? null, self::MONTHS_MAX, 'Monate als Anwärter')];
            } else {
                throw ApiError::invalid('Unbekannte Bedingung.');
            }
        }
        return $items === [] && $counter['groups'] > 1 ? null : ['type' => self::GROUP, 'op' => $op, 'items' => $items];
    }

    private static function number(mixed $raw, int $max, string $label): int
    {
        $n = Input::integer($raw);
        if ($n === null || $n < 1 || $n > $max) {
            throw ApiError::invalid(sprintf('%s: bitte eine Zahl von 1 bis %d.', $label, $max));
        }
        return $n;
    }

    /**
     * Notfall beim Lesen eines beschädigten Baums: gültige Teile behalten.
     *
     * @param array<mixed> $raw
     * @return array<string, mixed>|null
     */
    private static function lenient(array $raw, int $depth): ?array
    {
        if ($depth > self::GROUPS_MAX) {
            return null;
        }
        $items = [];
        foreach (is_array($raw['items'] ?? null) ? $raw['items'] : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = $item['type'] ?? null;
            if ($type === self::GROUP) {
                $group = self::lenient($item, $depth + 1);
                if ($group !== null && $group['items'] !== []) {
                    $items[] = $group;
                }
            } elseif ($type === self::ATTENDANCE && is_string($item['kind'] ?? null) && $item['kind'] !== '' && is_int($item['count'] ?? null) && $item['count'] >= 1) {
                $items[] = ['type' => self::ATTENDANCE, 'kind' => $item['kind'], 'count' => min(self::COUNT_MAX, $item['count'])];
            } elseif ($type === self::DURATION && is_int($item['months'] ?? null) && $item['months'] >= 1) {
                $items[] = ['type' => self::DURATION, 'months' => min(self::MONTHS_MAX, $item['months'])];
            }
        }
        return ['type' => self::GROUP, 'op' => ($raw['op'] ?? null) === self::OR ? self::OR : self::AND, 'items' => $items];
    }
}
