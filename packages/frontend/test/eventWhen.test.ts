// Datum von Terminen (src/format.ts): Termine außerhalb des laufenden Jahres zeigen die Jahreszahl.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { formatEventWhen, formatWeekdayDateYear } from '../src/format.ts';

const now = new Date(2026, 10, 6, 12, 0);

test('Termine im laufenden Jahr bleiben ohne Jahreszahl', () => {
  assert.equal(formatEventWhen({ start: '2026-11-21', end: '', all_day: true }, now), 'Sa., 21. Nov.');
  assert.equal(
    formatEventWhen({ start: '2026-11-21T19:00:00', end: '2026-11-21T23:00:00', all_day: false }, now),
    'Sa., 21. Nov. · 19:00–23:00 Uhr',
  );
});

test('ganztägige Termine in einem anderen Jahr zeigen die Jahreszahl', () => {
  assert.equal(formatEventWhen({ start: '2027-01-08', end: '', all_day: true }, now), 'Fr., 8. Jan. 2027');
  // Mehrtägig im selben Jahr: Jahreszahl nur am Ende (Ende exklusiv wie bei iCal).
  assert.equal(formatEventWhen({ start: '2027-01-08', end: '2027-01-11', all_day: true }, now), 'Fr., 8. Jan. – So., 10. Jan. 2027');
  // Über den Jahreswechsel: Der Anfang liegt im laufenden Jahr.
  assert.equal(formatEventWhen({ start: '2026-12-31', end: '2027-01-02', all_day: true }, now), 'Do., 31. Dez. – Fr., 1. Jan. 2027');
});

test('Termine mit Uhrzeit in einem anderen Jahr zeigen die Jahreszahl', () => {
  assert.equal(
    formatEventWhen({ start: '2027-06-26T19:00:00', end: '2027-06-26T23:00:00', all_day: false }, now),
    'Sa., 26. Juni 2027 · 19:00–23:00 Uhr',
  );
  assert.equal(formatEventWhen({ start: '2027-06-26T19:00:00', end: '', all_day: false }, now), 'Sa., 26. Juni 2027 · 19:00 Uhr');
  assert.equal(
    formatEventWhen({ start: '2027-06-26T19:00:00', end: '2027-06-27T02:00:00', all_day: false }, now),
    'Sa., 26. Juni, 19:00 – So., 27. Juni 2027, 02:00 Uhr',
  );
  assert.equal(
    formatEventWhen({ start: '2026-12-31T20:00:00', end: '2027-01-01T02:00:00', all_day: false }, now),
    'Do., 31. Dez., 20:00 – Fr., 1. Jan. 2027, 02:00 Uhr',
  );
});

test('Datum mit Wochentag nennt das Jahr nur außerhalb des laufenden', () => {
  assert.equal(formatWeekdayDateYear(new Date(2026, 11, 24), now), 'Do., 24. Dez.');
  assert.equal(formatWeekdayDateYear(new Date(2027, 0, 3), now), 'So., 3. Jan. 2027');
});
