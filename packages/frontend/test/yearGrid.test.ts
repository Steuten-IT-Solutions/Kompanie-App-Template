// Jahresübersicht der Ämter (src/yearGrid.ts): belegte Jahre, Spuren für gleichzeitige Amtszeiten, Jahre des Rasters.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { lanes, occupied, yearRange, type GridTerm } from '../src/yearGrid.ts';

let next = 1;
const term = (start: number, end: number | null, kind: 'period' | 'point' = 'period'): GridTerm => ({
  id: next++,
  kind,
  start_year: start,
  end_year: end,
  current: kind === 'period' && end === null,
});

test('belegte Jahre: Endjahr gehört dem Nachfolger, laufende bis heute, einzelnes Jahr', () => {
  assert.deepEqual(occupied(term(2022, 2024), 2026), { first: 2022, last: 2023 });
  assert.deepEqual(occupied(term(2006, 2006), 2026), { first: 2006, last: 2006 });
  assert.deepEqual(occupied(term(2020, null), 2026), { first: 2020, last: 2026 });
  assert.deepEqual(occupied(term(2028, null), 2026), { first: 2028, last: 2028 }, 'Beginn in der Zukunft');
  assert.deepEqual(occupied(term(1973, null, 'point'), 2026), { first: 1973, last: 1973 });
});

test('aufeinanderfolgende Amtszeiten bleiben in einer Spur, gleichzeitige bekommen eigene', () => {
  const kings = [term(2022, 2024), term(2024, 2026), term(2020, 2022)];
  assert.equal(lanes(kings, 2026).count, 1);
  // Fahne: fünf laufend, davon vier seit 2006, dazu frühere
  const flag = [
    term(2025, null),
    term(2006, null),
    term(2006, null),
    term(2006, null),
    term(2006, null),
    term(2023, 2025),
    term(2009, 2023),
  ];
  const { bars, count } = lanes(flag, 2026);
  assert.equal(count, 5);
  for (const a of bars) {
    for (const b of bars) {
      if (a !== b && a.lane === b.lane) assert.ok(a.last < b.first || b.last < a.first, 'keine Überschneidung in einer Spur');
    }
  }
  assert.equal(lanes([], 2026).count, 1, 'leeres Amt: eine Spalte');
});

test('Jahre des Rasters: neueste oben, vom frühesten Beginn bis heute', () => {
  assert.deepEqual(yearRange([term(2020, null), term(2018, 2019)], 2022), [2022, 2021, 2020, 2019, 2018]);
  assert.deepEqual(yearRange([], 2022), []);
});
