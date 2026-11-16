// Bericht als PDF (src/report.ts): Schnellwahl der Zeiträume, Beschriftung und Dateiname.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { dayLabel, rangeLabel, reportFileName, reportPresets } from '../src/report.ts';

test('Schnellwahl: dieses Jahr bis heute, letztes Jahr, letzte 12 Monate', () => {
  const p = Object.fromEntries(reportPresets('2026-11-16').map((x) => [x.id, x.range]));
  assert.deepEqual(p.year, { from: '2026-01-01', to: '2026-11-16' });
  assert.deepEqual(p['last-year'], { from: '2025-01-01', to: '2025-12-31' });
  assert.deepEqual(p['twelve-months'], { from: '2025-11-17', to: '2026-11-16' });
});

test('Letzte 12 Monate über Monats- und Schaltjahresgrenzen', () => {
  const twelve = (today: string) => reportPresets(today).find((x) => x.id === 'twelve-months')!.range.from;
  assert.equal(twelve('2026-01-31'), '2025-02-01');
  assert.equal(twelve('2028-02-29'), '2027-03-01');
  assert.equal(twelve('2026-12-31'), '2026-01-01');
});

test('Beschriftung und Dateiname', () => {
  assert.equal(dayLabel('2026-03-01'), '01.03.2026');
  assert.equal(rangeLabel({ from: '2026-01-01', to: '2026-12-31' }), '01.01.2026 bis 31.12.2026');
  assert.equal(rangeLabel({ from: '2026-05-01', to: '2026-05-01' }), '01.05.2026');
  assert.equal(reportFileName({ from: '2026-01-01', to: '2026-12-31' }), 'bericht-2026-01-01-bis-2026-12-31.pdf');
  assert.equal(reportFileName({ from: '2026-05-01', to: '2026-05-01' }), 'bericht-2026-05-01.pdf');
});
