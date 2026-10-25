// Jahresübersicht der Ämter (Issue #112, R14): eine Spalte je Amt, eine Zeile je Jahr, neueste oben. Reine Rechnung ohne
// React, damit sie sich ohne Browser prüfen lässt.

/** Amtszeit, wie sie GET /offices liefert (nur die Felder, die das Raster braucht) */
export interface GridTerm {
  id: number;
  kind: 'period' | 'point';
  start_year: number;
  end_year: number | null;
  current: boolean;
}

/** Ein Balken im Raster: Spalte (Spur) innerhalb des Amts und die belegten Jahre von oben (last) nach unten (first). */
export interface GridBar<T extends GridTerm> {
  term: T;
  lane: number;
  first: number;
  last: number;
}

/**
 * Belegte Jahre einer Amtszeit. Bei einem Zeitraum gehört das Endjahr schon dem Nachfolger („2022–2024“, dann
 * „2024–2026“), sonst stünden fast alle Ämter in zwei Spuren; ein Zeitraum innerhalb eines Jahres belegt dieses Jahr.
 * Laufende reichen bis $thisYear.
 */
export function occupied(t: GridTerm, thisYear: number): { first: number; last: number } {
  if (t.kind === 'point') return { first: t.start_year, last: t.start_year };
  const end = t.end_year ?? thisYear;
  const last = t.end_year === null ? Math.max(end, t.start_year) : Math.max(t.start_year, end - 1);
  return { first: t.start_year, last };
}

/**
 * Verteilt die Amtszeiten eines Amts auf Spuren, sodass sich in einer Spur nichts überschneidet (z. B. mehrere
 * Fahnenträger gleichzeitig). Ältere zuerst, damit lange Linien links bleiben.
 */
export function lanes<T extends GridTerm>(terms: T[], thisYear: number): { bars: GridBar<T>[]; count: number } {
  const sorted = [...terms].sort((a, b) => a.start_year - b.start_year || a.id - b.id);
  const laneEnds: number[] = [];
  const bars: GridBar<T>[] = [];
  for (const term of sorted) {
    const { first, last } = occupied(term, thisYear);
    let lane = laneEnds.findIndex((end) => end < first);
    if (lane === -1) {
      lane = laneEnds.length;
      laneEnds.push(last);
    } else {
      laneEnds[lane] = last;
    }
    bars.push({ term, lane, first, last });
  }
  return { bars, count: Math.max(1, laneEnds.length) };
}

/** Jahre des Rasters: vom frühesten Beginn bis zum spätesten belegten Jahr (mindestens bis zu laufenden), neueste oben. */
export function yearRange(terms: GridTerm[], thisYear: number): number[] {
  if (terms.length === 0) return [];
  let from = Infinity;
  let to = -Infinity;
  for (const t of terms) {
    const { first, last } = occupied(t, thisYear);
    from = Math.min(from, first);
    to = Math.max(to, last);
  }
  const years: number[] = [];
  for (let y = to; y >= from; y--) years.push(y);
  return years;
}
