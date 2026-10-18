<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

/**
 * Berechnet die Vorkommen einer Terminserie (RRULE nach RFC 5545) in einem Zeitraum.
 *
 * Unterstützt: FREQ=DAILY/WEEKLY/MONTHLY/YEARLY mit INTERVAL, COUNT, UNTIL, BYDAY
 * (bei MONTHLY/YEARLY auch mit Ordnungszahl, z. B. 1MO oder -1FR), BYMONTHDAY, BYMONTH.
 * Gerechnet wird in der Zeitzone des Termins, damit die Uhrzeit über die Zeitumstellung gleich bleibt.
 * Bei nicht unterstützten Regeln liefert supports() false; dann zählt nur der erste Termin.
 */
final class IcsRecurrence
{
    private const DAYS = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];
    private const KNOWN = ['FREQ', 'INTERVAL', 'COUNT', 'UNTIL', 'BYDAY', 'BYMONTHDAY', 'BYMONTH', 'WKST'];
    /** Obergrenze der durchlaufenen Zeitabschnitte, gegen Endlosschleifen bei kaputten Regeln. */
    private const MAX_PERIODS = 50000;

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
        $freq = $this->rule['FREQ'] ?? '';
        if (!in_array($freq, ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'], true) || array_diff(array_keys($this->rule), self::KNOWN) !== []) {
            return false;
        }
        // BYDAY ohne BYMONTH bei YEARLY bezieht sich auf das ganze Jahr – nicht unterstützt.
        if ($freq === 'YEARLY' && isset($this->rule['BYDAY']) && !isset($this->rule['BYMONTH'])) {
            return false;
        }
        foreach ($this->byDay() as [$n]) {
            if ($n !== 0 && !in_array($freq, ['MONTHLY', 'YEARLY'], true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Beginn aller Vorkommen ab $start bis höchstens $until (einschließlich).
     *
     * @return \DateTimeImmutable[]
     */
    public function occurrences(\DateTimeImmutable $start, \DateTimeImmutable $until, bool $allDay): array
    {
        $interval = max(1, (int) ($this->rule['INTERVAL'] ?? 1));
        $count = isset($this->rule['COUNT']) ? max(0, (int) $this->rule['COUNT']) : null;
        $ruleUntil = $this->until($start, $allDay);
        if ($ruleUntil !== null && $ruleUntil < $until) {
            $until = $ruleUntil;
        }
        $out = [];
        $seen = 0;
        for ($k = 0; $k < self::MAX_PERIODS; $k++) {
            $candidates = $this->period($start, $k * $interval);
            if ($candidates === null) {
                break;
            }
            foreach ($candidates as $c) {
                if ($c < $start) {
                    continue;
                }
                if ($c > $until || ($count !== null && $seen >= $count)) {
                    return $out;
                }
                $seen++;
                $out[] = $c;
            }
            // Abbruch, wenn schon der Anfang des Abschnitts hinter dem Ende liegt.
            if ($this->periodStart($start, $k * $interval) > $until) {
                break;
            }
        }
        return $out;
    }

    /** @return \DateTimeImmutable[]|null Kandidaten eines Abschnitts, sortiert */
    private function period(\DateTimeImmutable $start, int $offset): ?array
    {
        $y = (int) $start->format('Y');
        $m = (int) $start->format('n');
        $d = (int) $start->format('j');
        $byMonth = $this->intList('BYMONTH');
        $list = [];
        switch ($this->rule['FREQ']) {
            case 'DAILY':
                $c = $start->modify('+' . $offset . ' days');
                $list = [$c];
                break;
            case 'WEEKLY':
                $weekStart = $start->modify('-' . ((int) $start->format('N') - 1) . ' days')->modify('+' . $offset . ' weeks');
                $days = $this->byDay() ?: [[0, (int) $start->format('N')]];
                foreach ($days as [, $wd]) {
                    $list[] = $weekStart->modify('+' . ($wd - 1) . ' days');
                }
                break;
            case 'MONTHLY':
                $total = $m - 1 + $offset;
                $list = $this->inMonth($start, $y + intdiv($total, 12), $total % 12 + 1, $d);
                break;
            case 'YEARLY':
                foreach ($byMonth ?: [$m] as $month) {
                    array_push($list, ...$this->inMonth($start, $y + $offset, $month, $d));
                }
                break;
        }
        if ($byMonth && $this->rule['FREQ'] !== 'YEARLY') {
            $list = array_filter($list, static fn (\DateTimeImmutable $c): bool => in_array((int) $c->format('n'), $byMonth, true));
        }
        if ($this->rule['FREQ'] === 'DAILY' && $this->byDay()) {
            $days = array_column($this->byDay(), 1);
            $list = array_filter($list, static fn (\DateTimeImmutable $c): bool => in_array((int) $c->format('N'), $days, true));
        }
        usort($list, static fn ($a, $b) => $a <=> $b);
        return array_values($list);
    }

    private function periodStart(\DateTimeImmutable $start, int $offset): \DateTimeImmutable
    {
        return match ($this->rule['FREQ']) {
            'DAILY' => $start->modify('+' . $offset . ' days'),
            'WEEKLY' => $start->modify('+' . $offset . ' weeks')->modify('-7 days'),
            'MONTHLY' => $start->setDate((int) $start->format('Y'), (int) $start->format('n'), 1)->modify('+' . $offset . ' months'),
            default => $start->setDate((int) $start->format('Y') + $offset, 1, 1),
        };
    }

    /** @return \DateTimeImmutable[] */
    private function inMonth(\DateTimeImmutable $start, int $y, int $m, int $d): array
    {
        $len = (int) $start->setDate($y, $m, 1)->format('t');
        $days = [];
        foreach ($this->intList('BYMONTHDAY') as $md) {
            $days[] = $md < 0 ? $len + $md + 1 : $md;
        }
        foreach ($this->byDay() as [$n, $wd]) {
            $first = (int) $start->setDate($y, $m, 1)->format('N');
            $firstMatch = 1 + (($wd - $first + 7) % 7);
            $all = range($firstMatch, $len, 7);
            if ($n === 0) {
                array_push($days, ...$all);
            } elseif ($n > 0 && isset($all[$n - 1])) {
                $days[] = $all[$n - 1];
            } elseif ($n < 0 && isset($all[count($all) + $n])) {
                $days[] = $all[count($all) + $n];
            }
        }
        if (!isset($this->rule['BYMONTHDAY']) && !isset($this->rule['BYDAY'])) {
            $days = [$d];
        }
        $out = [];
        foreach (array_unique($days) as $day) {
            if ($day >= 1 && $day <= $len) {
                $out[] = $start->setDate($y, $m, $day);
            }
        }
        return $out;
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
