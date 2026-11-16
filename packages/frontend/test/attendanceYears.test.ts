// Anwesenheit einer Person in der Verwaltung (src/attendanceYears.ts): Jahre zum Umschalten und Summen je Zeitraum.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { attendanceYears, inPeriod, periodCounts, type AttendanceEntryLike } from '../src/attendanceYears.ts';

const entries: AttendanceEntryLike[] = [
  { date: '2026-11-01', status: 'excused' },
  { date: '2026-10-19', status: 'present' },
  { date: '2026-03-02', status: 'absent' },
  { date: '2024-09-01', status: 'present' },
];

test('Jahre: laufendes Jahr immer, dazu Jahre mit Einträgen, die spätesten zuerst', () => {
  assert.deepEqual(attendanceYears(entries, '2026-10-20'), ['2026', '2024']);
  assert.deepEqual(attendanceYears([], '2027-01-05'), ['2027']);
  assert.deepEqual(attendanceYears(entries, '2027-01-05'), ['2027', '2026', '2024']);
});

test('Summen im Jahr und gesamt, kommende gespeicherte Entschuldigungen gesondert genannt', () => {
  assert.deepEqual(periodCounts(entries, '2026', '2026-10-20'), { present: 1, excused: 1, absent: 1, upcoming: 1 });
  assert.deepEqual(periodCounts(entries, 'all', '2026-10-20'), { present: 2, excused: 1, absent: 1, upcoming: 1 });
  assert.deepEqual(periodCounts(entries, '2025', '2026-10-20'), { present: 0, excused: 0, absent: 0, upcoming: 0 });
  assert.equal(periodCounts(entries, '2026', '2026-11-01').upcoming, 0, 'am Tag des Termins nicht mehr kommend');
});

test('Einträge im Zeitraum', () => {
  assert.equal(inPeriod(entries, 'all').length, 4);
  assert.deepEqual(
    inPeriod(entries, '2024').map((e) => e.date),
    ['2024-09-01'],
  );
});
