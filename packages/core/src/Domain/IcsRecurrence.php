<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

/**
 * Berechnet die Vorkommen einer Terminserie (RRULE nach RFC 5545) in einem Zeitraum.
 *
 * Unterstützt: FREQ=DAILY/WEEKLY/MONTHLY/YEARLY mit INTERVAL, COUNT, UNTIL, WKST, BYDAY
 * (bei MONTHLY/YEARLY auch mit Ordnungszahl, z. B. 1MO oder -1FR), BYMONTHDAY, BYMONTH.
 * Gerechnet wird in der Zeitzone des Termins, damit die Uhrzeit über die Zeitumstellung gleich bleibt.
 * DTSTART ist immer das erste Vorkommen (zählt bei COUNT mit), auch wenn es nicht zur Regel passt.
 * Bei nicht unterstützten oder unsinnigen Regeln liefert supports() false; dann zählt nur der erste Termin.
 */
final class IcsRecurrence
{
    private const DAYS = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];
    private const KNOWN = ['FREQ', 'INTERVAL', 'COUNT', 'UNTIL', 'BYDAY', 'BYMONTHDAY', 'BYMONTH', 'WKST'];
    private const MAX_INTERVAL = 1000;
    /**
     * Obergrenzen gegen lange Laufzeiten bei kaputten oder sehr alten Serien. Mit COUNT muss ab
     * DTSTART gezählt werden; Serien, deren Abschnitte bis heute darüber hinausgehen (z. B. täglich
     * seit über 50 Jahren mit COUNT), erscheinen deshalb nicht.
     */
    private const MAX_PERIODS = 20000;
    private const MAX_RESULTS = 1000;

    /** @var array<string, string> */
    private readonly array $rule;

    public function __construct(string $rrule)
    {
        $rule = [];
        foreach (explode(';', trim($rrule)) as $part) {
            if (str_contains($part, '=')) {
                [$k, $v] = explode('=', $part, 2);
                $rule[strtoupper(trim($k))] = strtoupper(trim($v));
            }
        }
        $this->rule = $rule;
    }

    public function supports(): bool
    {
        $r = $this->rule;
        $freq = $r['FREQ'] ?? '';
        if (!in_array($freq, ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'], true) || array_diff(array_keys($r), self::KNOWN) !== []) {
            return false;
        }
        if (isset($r['INTERVAL']) && (!ctype_digit($r['INTERVAL']) || (int) $r['INTERVAL'] < 1 || (int) $r['INTERVAL'] > self::MAX_INTERVAL)) {
            return false;
        }
        if (isset($r['COUNT']) && !ctype_digit($r['COUNT'])) {
            return false;
        }
        if (isset($r['WKST']) && !isset(self::DAYS[$r['WKST']])) {
            return false;
        }
        foreach (['BYMONTHDAY' => 31, 'BYMONTH' => 12] as $key => $max) {
            if (isset($r[$key]) && !$this->validInts($r[$key], $max, $key === 'BYMONTHDAY')) {
                return false;
            }
        }
        if (isset($r['BYDAY'])) {
            foreach (explode(',', $r['BYDAY']) as $v) {
                if (!preg_match('/^([+-]?([1-9]|[1-4]\d|5[0-3]))?(MO|TU|WE|TH|FR|SA|SU)$/', $v, $m)) {
                    return false;
                }
                // Ordnungszahl (z. B. 2MO) nur bei MONTHLY/YEARLY.
                if (($m[1] ?? '') !== '' && !in_array($freq, ['MONTHLY', 'YEARLY'], true)) {
                    return false;
                }
            }
        }
        // BYDAY oder BYMONTHDAY ohne BYMONTH beziehen sich bei YEARLY auf das ganze Jahr (nicht
        // unterstützt); BYMONTHDAY gibt es bei WEEKLY nicht.
        if ($freq === 'YEARLY' && (isset($r['BYDAY']) || isset($r['BYMONTHDAY'])) && !isset($r['BYMONTH'])) {
            return false;
        }
        return !($freq === 'WEEKLY' && isset($r['BYMONTHDAY']));
    }

    /**
     * Beginn aller Vorkommen, die nicht vor $from beginnen, bis höchstens $until (einschließlich).
     * $from dient nur zum Überspringen alter Abschnitte; DTSTART wird immer mitgezählt.
     *
     * @return \DateTimeImmutable[]
     */
    public function occurrences(\DateTimeImmutable $start, \DateTimeImmutable $from, \DateTimeImmutable $until, bool $allDay): array
    {
        $interval = max(1, (int) ($this->rule['INTERVAL'] ?? 1));
        $count = isset($this->rule['COUNT']) ? (int) $this->rule['COUNT'] : null;
        $ruleUntil = $this->until($start, $allDay);
        if ($ruleUntil !== null && $ruleUntil < $until) {
            $until = $ruleUntil;
        }
        $out = [];
        $seen = 1; // DTSTART
        if ($start >= $from && $start <= $until) {
            $out[] = $start;
        }
        // Ohne COUNT muss nicht gezählt werden: direkt kurz vor $from beginnen.
        $first = $count === null ? $this->skipPeriods($start, $from, $interval) : 0;
        for ($k = $first; $k < $first + self::MAX_PERIODS; $k++) {
            if ($this->periodStart($start, $k * $interval) > $until) {
                break;
            }
            foreach ($this->period($start, $k * $interval) as $c) {
                if ($c <= $start) {
                    continue; // vor DTSTART bzw. DTSTART selbst (schon gezählt)
                }
                if ($c > $until || ($count !== null && $seen >= $count) || count($out) >= self::MAX_RESULTS) {
                    return $out;
                }
                $seen++;
                if ($c >= $from) {
                    $out[] = $c;
                }
            }
        }
        return $out;
    }

    /** Anzahl ganzer Abschnitte, die sicher vor $from enden. */
    private function skipPeriods(\DateTimeImmutable $start, \DateTimeImmutable $from, int $interval): int
    {
        if ($from <= $start) {
            return 0;
        }
        $diff = $start->diff($from);
        $units = match ($this->rule['FREQ']) {
            'DAILY' => (int) $diff->days,
            'WEEKLY' => intdiv((int) $diff->days, 7),
            'MONTHLY' => $diff->y * 12 + $diff->m,
            default => $diff->y,
        };
        return max(0, intdiv($units, $interval) - 1);
    }

    /** @return \DateTimeImmutable[] Kandidaten eines Abschnitts, sortiert */
    private function period(\DateTimeImmutable $start, int $offset): array
    {
        $y = (int) $start->format('Y');
        $m = (int) $start->format('n');
        $byMonth = $this->intList('BYMONTH');
        $list = [];
        switch ($this->rule['FREQ']) {
            case 'DAILY':
                $list = [$start->modify('+' . $offset . ' days')];
                break;
            case 'WEEKLY':
                $weekStart = $this->weekStart($start)->modify('+' . $offset . ' weeks');
                $wkst = self::DAYS[$this->rule['WKST'] ?? 'MO'];
                $days = $this->byDay() ?: [[0, (int) $start->format('N')]];
                foreach ($days as [, $wd]) {
                    $list[] = $weekStart->modify('+' . (($wd - $wkst + 7) % 7) . ' days');
                }
                break;
            case 'MONTHLY':
                $total = $m - 1 + $offset;
                $list = $this->inMonth($start, $y + intdiv($total, 12), $total % 12 + 1);
                break;
            case 'YEARLY':
                foreach ($byMonth ?: [$m] as $month) {
                    array_push($list, ...$this->inMonth($start, $y + $offset, $month));
                }
                break;
        }
        // Einschränkende Regeln (RFC 5545, Tabelle in 3.3.10).
        if ($byMonth && $this->rule['FREQ'] !== 'YEARLY') {
            $list = array_filter($list, static fn (\DateTimeImmutable $c): bool => in_array((int) $c->format('n'), $byMonth, true));
        }
        if ($this->rule['FREQ'] === 'DAILY') {
            if ($this->byDay()) {
                $days = array_column($this->byDay(), 1);
                $list = array_filter($list, static fn (\DateTimeImmutable $c): bool => in_array((int) $c->format('N'), $days, true));
            }
            if (isset($this->rule['BYMONTHDAY'])) {
                $list = array_filter($list, fn (\DateTimeImmutable $c): bool => in_array((int) $c->format('j'), $this->monthDays($c), true));
            }
        }
        // Ein Vorkommen zählt einmal, auch bei doppelten Angaben (z. B. BYDAY=TU,TU).
        $unique = [];
        foreach ($list as $c) {
            $unique[$c->getTimestamp()] = $c;
        }
        ksort($unique);
        return array_values($unique);
    }

    /** Beginn der Woche von $start nach WKST (Standard Montag), mit Uhrzeit von $start. */
    private function weekStart(\DateTimeImmutable $start): \DateTimeImmutable
    {
        $wkst = self::DAYS[$this->rule['WKST'] ?? 'MO'];
        $back = ((int) $start->format('N') - $wkst + 7) % 7;
        return $start->modify('-' . $back . ' days');
    }

    private function periodStart(\DateTimeImmutable $start, int $offset): \DateTimeImmutable
    {
        return match ($this->rule['FREQ']) {
            'DAILY' => $start->modify('+' . $offset . ' days'),
            'WEEKLY' => $this->weekStart($start)->modify('+' . $offset . ' weeks'),
            'MONTHLY' => $start->setDate((int) $start->format('Y'), (int) $start->format('n'), 1)->modify('+' . $offset . ' months'),
            default => $start->setDate((int) $start->format('Y') + $offset, 1, 1),
        };
    }

    /**
     * Tage eines Monats nach BYMONTHDAY und BYDAY. Sind beide angegeben, zählen nur Tage,
     * die beide erfüllen (z. B. „Freitag, der 13.“).
     *
     * @return \DateTimeImmutable[]
     */
    private function inMonth(\DateTimeImmutable $start, int $y, int $m): array
    {
        $first = $start->setDate($y, $m, 1);
        $len = (int) $first->format('t');
        $byMonthDay = isset($this->rule['BYMONTHDAY']) ? $this->monthDays($first) : null;
        $byDay = null;
        if ($this->byDay()) {
            $byDay = [];
            $firstWd = (int) $first->format('N');
            foreach ($this->byDay() as [$n, $wd]) {
                $all = range(1 + (($wd - $firstWd + 7) % 7), $len, 7);
                if ($n === 0) {
                    array_push($byDay, ...$all);
                } elseif ($n > 0 && isset($all[$n - 1])) {
                    $byDay[] = $all[$n - 1];
                } elseif ($n < 0 && isset($all[count($all) + $n])) {
                    $byDay[] = $all[count($all) + $n];
                }
            }
        }
        $days = match (true) {
            $byMonthDay !== null && $byDay !== null => array_intersect($byMonthDay, $byDay),
            $byMonthDay !== null => $byMonthDay,
            $byDay !== null => $byDay,
            default => [(int) $start->format('j')],
        };
        $out = [];
        foreach (array_unique($days) as $day) {
            if ($day >= 1 && $day <= $len) {
                $out[] = $start->setDate($y, $m, $day);
            }
        }
        return $out;
    }

    /** @return int[] BYMONTHDAY für den Monat von $date (negative Werte vom Monatsende) */
    private function monthDays(\DateTimeImmutable $date): array
    {
        $len = (int) $date->format('t');
        return array_map(static fn (int $d): int => $d < 0 ? $len + $d + 1 : $d, $this->intList('BYMONTHDAY'));
    }

    /** @return array<int, array{0: int, 1: int}> [Ordnungszahl (0 = alle), Wochentag 1–7] */
    private function byDay(): array
    {
        $out = [];
        foreach (array_filter(explode(',', $this->rule['BYDAY'] ?? '')) as $v) {
            if (preg_match('/^([+-]?\d{1,2})?(MO|TU|WE|TH|FR|SA|SU)$/', $v, $mm)) {
                $out[] = [(int) ($mm[1] ?? 0), self::DAYS[$mm[2]]];
            }
        }
        return $out;
    }

    /** @return int[] */
    private function intList(string $key): array
    {
        return array_values(array_filter(array_map('intval', explode(',', $this->rule[$key] ?? '')), static fn (int $v): bool => $v !== 0));
    }

    private function validInts(string $list, int $max, bool $negative): bool
    {
        foreach (explode(',', $list) as $v) {
            if (!preg_match($negative ? '/^[+-]?\d{1,2}$/' : '/^\+?\d{1,2}$/', $v) || (int) $v === 0 || abs((int) $v) > $max) {
                return false;
            }
        }
        return true;
    }

    private function until(\DateTimeImmutable $start, bool $allDay): ?\DateTimeImmutable
    {
        $v = $this->rule['UNTIL'] ?? '';
        if ($v === '') {
            return null;
        }
        try {
            if (strlen($v) === 8) {
                // Nur Datum: Der ganze Tag zählt noch dazu.
                return new \DateTimeImmutable($v . 'T235959', $start->getTimezone());
            }
            $u = str_ends_with($v, 'Z')
                ? new \DateTimeImmutable(rtrim($v, 'Z'), new \DateTimeZone('UTC'))
                : new \DateTimeImmutable($v, $start->getTimezone());
            return $allDay ? $u->setTimezone($start->getTimezone())->setTime(23, 59, 59) : $u;
        } catch (\Exception) {
            return null;
        }
    }
}
